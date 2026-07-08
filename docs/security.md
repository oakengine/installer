# Security Notes

Threat model, defensive measures and production hardening for the OakEngine Installer.

For the broader architecture see [architecture.md](architecture.md). For the install manifest internals see [install-manifest.md](install-manifest.md).

## Related documents

- [architecture.md](architecture.md) – module overview.
- [install-manifest.md](install-manifest.md) – manifest file format and path traversal protection.
- [self-update.md](self-update.md) – self-update pipeline and how it is locked down.

## Threat model

The installer has **elevated filesystem and shell access** on the target server:

- It writes files into a configurable target directory (often the document root or even the parent project root).
- For runner installs that target directory *is* the application root, including PHP files that get executed by the web server.
- It can run `shell_exec('php bin/console doctrine:migrations:migrate …')` to touch the database.
- It can clear `var/` (Symfony cache).
- It edits `.env.local` in the target directory, which contains database credentials and the `APP_SECRET`.

This means **a successful attack on the installer is equivalent to remote code execution on the server** that hosts it. Treat it accordingly.

## Production hardening checklist

- [ ] **Set `password` in `config.php`** – use a long random string or a `password_hash()` value. Without it, anyone who can reach the installer URL has full access.
- [ ] **Restrict the URL with web server rules** – for Apache, see `src/.htaccess`; for Nginx, deny access by default and only allow from specific IPs.
- [ ] **HTTPS only** – the password and the bearer token for the package endpoint travel in HTTP headers. Never serve the installer over plain HTTP.
- [ ] **Rotate `APP_SECRET`** regularly – it is the Symfony secret used for CSRF tokens and signed URIs. Rotate via the dashboard after every privilege change.
- [ ] **Keep the install UUID stable** unless you mean to reset the endpoint’s tracking of the installation.
- [ ] **Drop the installer when you are done** – the install step is one-shot. Leaving the installer on a production server expands the attack surface for no benefit.
- [ ] **Review `exclude_folders` / `exclude_files`** in `config.php` to ensure the runner tarball cannot overwrite your custom secrets, CI configs, or `.env.local`.

## File-system safety

### Path traversal in the install manifest cleanup

`InstallManifestManager::deleteStaleFilesAndEmptyDirs()` rejects any relative path that contains a `..` segment:

```php
if ($this->containsTraversal($normalizedPath)) {
    continue;
}
```

`containsTraversal()` splits on `/` and checks for an exact `..` entry. So `../etc/passwd` and `foo/../bar` are silently skipped, while legitimate filenames like `foo..bar` or `..hidden` are accepted. The check is necessary because the path comes from a JSON manifest that an attacker could otherwise tamper with via `config.php`.

### No realpath race

The earlier implementation resolved paths via `realpath()` to check that files stayed inside the target directory. That approach was replaced by the simpler `..`-segment rejection because it does not depend on filesystem state and is fully testable.

### Safe extraction

`ProjectPackageArchiveExtractor` extracts with the `--no-same-owner` and `--no-same-permissions` flags when using the `tar` binary, and applies `extractTo(..., null, true)` (overwrite) when using the PHP `PharData` fallback. The third argument to `PharData::extractTo()` ensures existing files are overwritten.

### Self-update whitelist

`updateUpdaterFromTag()` does **not** copy every file from the GitHub archive. Only files that pass `isAllowedUpdaterFile()` are written into the installer directory. In particular, `config.php` is never overwritten – the installer preserves the user configuration across updates.

### Target directory preservation on first install

If a target directory contains no install manifest yet, the first install falls back to `cleanTargetDirectory()`, which preserves:

- `runner/` and `data/` subdirectories (they have their own manifests once installed).
- `public/update/` (the installer itself when co-located with the runner).
- `.env.local` (runtime configuration).

Anything outside this whitelist is removed. As soon as the first install has produced a manifest, every subsequent install uses the diff workflow instead and never touches anything that was not tracked by the manifest itself.

## Network safety

### Bearer tokens

The package endpoint can be protected with a bearer token via `project_api_token`. The installer sends it as `Authorization: Bearer …`. Treat this token as a credential: store it in `config.php`, never commit `config.php` to version control, and rotate it if it leaks.

### Install UUID

The install UUID is sent as both a query parameter (`install_uuid`) and a header (`X-Install-UUID`). It is not a secret – it is a correlation identifier. Do not rely on it for authentication.

### GitHub token

`github_token` is optional and only used for the installer self-update. An authenticated request has a much higher rate limit than an anonymous one (5000 vs 60 requests per hour).

## Cryptography

The installer does not implement its own crypto. It relies on:

- **HTTPS** for transport encryption.
- **`password_hash()` / `password_verify()`** for the optional UI password (any value in `config.php` that starts with `$2y$` or similar is treated as a hash, anything else as plain text).
- **`APP_SECRET`** generation via `random_bytes(16)` plus a hex encoding (see `AppSecretManager`).
- **`install_uuid`** generated as UUID v7 (`InstallUuidManager`).

If you need a stronger password hash, set `password` to `password_hash('your-strong-password', PASSWORD_BCRYPT)`.

## Session and CSRF

The installer uses PHP sessions for the password gate. Every state-changing POST handler is reachable only from an authenticated session. The Symfony `APP_SECRET` from `.env.local` is used for Symfony's own CSRF protection when the runner is installed, but the installer UI itself does not issue CSRF tokens – instead it relies on the password gate and the SameSite=Lax cookie defaults.

If you put the installer behind a reverse proxy, make sure the proxy does not strip the `Set-Cookie` header or downgrade the session cookie to a cross-site context.

## Logging

The installer writes a structured, append-only log file named `oak-installer.log`. Every install, cleanup, stale-file removal, failed deletion and package summary is recorded there with a UTC timestamp, level and `key=value` context so operators can audit exactly what changed on the server.

### Log location is deliberately outside the project

The log directory is resolved once at request time by `installerLogBaseDirectory()` and is intentionally kept **outside the installed project** (and therefore outside the `<target>/var/` tree that `clearCacheDirectory()` wipes):

1. If `log_directory` is set in `config.php`, that path is used (absolute paths are used as-is, relative paths are resolved against the installer root).
2. Otherwise the installer falls back to `<installer-root>/logs`, i.e. a `logs/` folder next to the installer's own `src/` directory.

Because clearing the application cache only ever deletes `<target>/var/`, the installer log is never deleted by a cache clear, a first-install cleanup, or any other in-project operation. As an additional safety net, `clearCacheDirectory()` explicitly preserves any top-level `log`/`logs` directory inside the cache tree, so runtime logs (for example Symfony's `var/log`) survive a cache clear too.

Logging is best-effort and never throws: if the log directory cannot be created or written, the failure is swallowed so that logging can never break an installation.

### Other audit sources

Errors are also shown on the rendered error page. For additional audit trails, rely on your web server's access log and the Symfony log inside `<target>/var/log/` (once the runner is installed).

## Vulnerability audit (2026-07)

Reviewed surface and findings. Items marked **fixed** are addressed in this revision; the rest are recommendations.

### Authentication & session

- **No CSRF tokens on state-changing POSTs** (`src/app/Authenticator.php`, `src/app/InstallerApplication.php`): the UI relies on the password gate plus the `SameSite=Lax` session cookie. A malicious site that can make an authenticated admin's browser submit a form could trigger installs, cache clears, migrations, `.env.local` edits or a self-update. `SameSite=Lax` blocks most cross-site POSTs, but an explicit CSRF token per form is the robust fix. *Recommendation.*
- **No session ID regeneration on login** (`src/app/Authenticator.php:50`): after successful authentication the existing session ID is reused, which is a classic session-fixation vector. *Recommendation:* call `session_regenerate_id(true)` when setting `oak_installer_authenticated`.
- **No brute-force protection** on the login form. *Recommendation:* add rate limiting (e.g. via the web server / fail2ban) or a lockout.
- **`show_versions_before_login`** leaks installer/runner versions on the login screen when enabled (off by default). Keep it off in production.

### Filesystem & extraction

- **Install-log location was wiped by cache clear** – *fixed.* The log now lives outside `<target>/var/` (see *Logging* above) and `clearCacheDirectory()` preserves `log`/`logs` directories.
- **Path traversal in stale-file cleanup** is rejected: `InstallManifestManager::containsTraversal()` drops any relative path containing a `..` segment.
- **Self-update whitelist** (`src/app/InstallerUpdater.php`): only files matching `isAllowedUpdaterFile()` are copied during a self-update; `config.php` is never overwritten.
- **Package archive extraction** (`src/app/ProjectPackageArchiveExtractor.php`): extraction trusts the configured package endpoint. A compromised endpoint could craft archive entries; consider adding an explicit `..`-segment rejection to the copy step as defence in depth (the trusted-endpoint model currently mitigates this).

### Shell & secrets

- **`shell_exec` for migrations** (`src/app/InstallerApplication.php`): the console path comes from trusted config and is passed through `escapeshellarg()`. The broad surface is the price of the feature; restrict installer access accordingly.
- **`APP_SECRET` is partially echoed** (≈8 chars) in the configuration dashboard. Acceptable behind authentication, but treat the dashboard as privileged.
- **`.htaccess`** grants direct access to every `.php` file. `config.php` only `return`s an array (executing it leaks nothing), but denying direct access to `config.php` / `config.example.php` is a cheap hardening.

### Hardening recap

Set `password`, serve over HTTPS only, restrict the installer URL by IP, keep `log_directory` outside the document root, and remove the installer once the installation is finished.
