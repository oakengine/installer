# Architecture

Technical overview of the OakEngine Installer. This document describes the modules, request lifecycle, and data flow between the browser, the installer, the package endpoint, and the target directory.

If you are looking for a user-facing overview, see [../README.md](../README.md) or [../README.de.md](../README.de.md).

## Related documents

- [install-manifest.md](install-manifest.md) – how the install manifest is built, diffed, and used to clean up obsolete files.
- [package-endpoint.md](package-endpoint.md) – the HTTP contract between the installer and the package server.
- [self-update.md](self-update.md) – how the installer updates itself from GitHub.
- [security.md](security.md) – production hardening, path traversal protection, manifest safety.
- [development.md](development.md) – how to run the test suite, PHPStan, ECS, Rector inside Docker.

## Module overview

The installer is intentionally small: a single front controller (`src/index.php`) loads the application modules under `src/app/` and hands the request to `InstallerApplication`.

| File | Responsibility |
| --- | --- |
| [`src/index.php`](../src/index.php) | Bootstraps the autoloader, loads all `src/app/*.php` files, starts the session, runs the application. |
| [`src/app/InstallerApplication.php`](../src/app/InstallerApplication.php) | The HTTP entry point. Parses the request, dispatches POST actions, renders pages. |
| [`src/app/Authenticator.php`](../src/app/Authenticator.php) | Handles the optional password gate and the login session. |
| [`src/app/EnvLocalManager.php`](../src/app/EnvLocalManager.php) | Reads, writes, and edits `.env.local` (app env, databases, install uuid, app secret). |
| [`src/app/AppSecretManager.php`](../src/app/AppSecretManager.php) | Generates and validates the Symfony `APP_SECRET`. |
| [`src/app/InstallUuidManager.php`](../src/app/InstallUuidManager.php) | Generates and validates the `install_uuid` (UUID v7). |
| [`src/app/ProjectPackageApiClient.php`](../src/app/ProjectPackageApiClient.php) | Talks to the package endpoint. Caches the package list per package type. |
| [`src/app/ProjectPackageArchiveExtractor.php`](../src/app/ProjectPackageArchiveExtractor.php) | Streams `.tar.gz` archives into the target directory, honoring the exclude rules. |
| [`src/app/InstallManifestManager.php`](../src/app/InstallManifestManager.php) | Builds, saves, loads and diffs the install manifest (see [install-manifest.md](install-manifest.md)). |
| [`src/app/GitHubClient.php`](../src/app/GitHubClient.php) | GitHub REST client used for installer self-update. |
| [`src/app/GitHubRefsCache.php`](../src/app/GitHubRefsCache.php) | Caches the tag and branch list from GitHub. |
| [`src/app/InstallerUpdater.php`](../src/app/InstallerUpdater.php) | Implements the self-update pipeline (see [self-update.md](self-update.md)). |
| [`src/app/Filesystem.php`](../src/app/Filesystem.php) | `createDirectoryTree` helper. |
| [`src/app/FilesystemSupport.php`](../src/app/FilesystemSupport.php) | `cleanTargetDirectory`, `clearCacheDirectory`, `getDirectorySize`, `formatFileSize`. |
| [`src/app/PackageSupport.php`](../src/app/PackageSupport.php) | Package helpers: `resolvePackageInstallTargetDir`, `normalizePackageType`, `resolveInstalledPackages`, `resolvePackageInstallDirFromMetadata`. |
| [`src/app/MigrationStatus.php`](../src/app/MigrationStatus.php) | Doctrine migration introspection. |
| [`src/app/HtmlRenderer.php`](../src/app/HtmlRenderer.php) | HTML rendering helpers and partials. |
| [`src/app/Translation.php`](../src/app/Translation.php) | Language resolution and `resolveLangKey`. |
| [`src/lang/*.php`](../src/lang/) | Translation dictionaries (12 languages). |

## Request lifecycle

Every request hits `src/index.php`, which:

1. Starts a PHP session.
2. Loads the configuration array from `src/config.php` (or `src/config.example.php` as a fallback).
3. Resolves the active language and loads the matching translation dictionary.
4. Instantiates `InstallerApplication::run()`.

`InstallerApplication::run()` does the following (see [`src/app/InstallerApplication.php`](../src/app/InstallerApplication.php)):

1. Read the configuration array and normalize it.
2. Resolve the `target_directory` and create it if missing.
3. Resolve the current `installer_version` (from `config.php`, the local `composer.json`, or `unknown`).
4. Resolve the current `project_version` (from the runner's `composer.json` extra block, via `resolveInstalledProjectVersion`).
5. Run `handleAuthentication(...)` – if the password gate is configured, render the login form and exit early.
6. Initialize the `GitHubClient`, the `InstallUuidManager`, the `AppSecretManager`, and the `.env.local` path.
7. Build the three `ProjectPackageApiClient` instances (runner / plugin / data) and the `ProjectPackageArchiveExtractor`.
8. Build the `InstallManifestManager` used for install-time diffs.
9. Dispatch the POST handlers (env save, self-update, install, etc.).
10. Render the dashboard.

## POST handlers

`InstallerApplication::run()` checks the request method and `$_POST` keys. The interesting handlers are:

| `$_POST['...']` | Action |
| --- | --- |
| `save_env` | Save the environment / database from the form. |
| `save_env_content` | Save a full `.env.local` edited in the textarea. |
| `save_install_uuid` | Replace the install UUID. |
| `regenerate_install_uuid` | Generate a new install UUID. |
| `regenerate_app_secret` | Generate a new `APP_SECRET`. |
| `add_database` / `remove_database` | Manage the database map in `.env.local`. |
| `run_migrations` | Run `doctrine:migrations:migrate --no-interaction` via `shell_exec`. |
| `clear_cache` | Remove everything under `<target>/var`. |
| `refresh_packages` | Invalidate the package cache for runner / plugin / data. |
| `self_update` | Update the installer from GitHub (see [self-update.md](self-update.md)). |
| `install` | Install a runner / plugin / data package (see below). |

## The install pipeline

The `install` POST handler is the most complex one. The full sequence lives at [`src/app/InstallerApplication.php:484`](../src/app/InstallerApplication.php) and looks like this:

```
POST /install
  1. Determine the package type (runner / plugin / data) and the chosen version.
  2. Resolve the package via the matching ProjectPackageApiClient.
  3. Download the archive to a temporary file (downloadPackage).
  4. Resolve the install target directory:
       runner -> <target_directory>
       plugin -> <target_directory>/runner/<packageDir>
       data   -> <target_directory>/data/<packageDir>
  5. Load the old manifest from <targetDir>/.oak-install-manifest.json.
  6. If no old manifest exists (first install):
       call cleanTargetDirectory($packageTargetDir) to wipe the target.
     Otherwise (update):
       skip the wipe entirely.
  7. Extract the tar.gz into the target directory, honoring exclude_folders
     and exclude_files. The extractor returns:
       - extracted (relative paths actually written)
       - skipped_files, skipped_folders
  8. For 'runner' only: ensure install_uuid and APP_SECRET exist in .env.local.
  9. Sync composer.json env metadata into .env.local.
 10. Build the new manifest from the extracted paths (SHA1 per file).
 11. Save the new manifest at <targetDir>/.oak-install-manifest.json.
 12. diffStaleFiles(old, new) -> list of files that disappeared.
 13. deleteStaleFilesAndEmptyDirs(target, stale) ->
       deletes the stale files, then recursively removes any directory
       that became empty as a result.
 14. Render the result page with counts of extracted / skipped / removed
       files and directories.
```

The reason for the dual behavior (full wipe for the first install, diff for every subsequent one) is migration safety: existing installs without a manifest get exactly one wipe on the next update, which then writes the first manifest.

## Package types and their target directories

| Package type | Package ID | Install target directory |
| --- | --- | --- |
| `runner` | e.g. `oak-runner` | `<target_directory>` |
| `plugin` | e.g. `oak-contact-panel` | `<target_directory>/runner/<packageDir>` |
| `data` | e.g. `oak-content-demo` | `<target_directory>/data/<packageDir>` |

`<packageDir>` is derived from the package's `composer.json` `extra.env.dir` (preferred) or the `composer name`'s basename. See [`resolvePackageInstallDirFromMetadata`](../src/app/PackageSupport.php).

## Caching

Two layers of caching exist:

1. **Package list cache** (`<target>/var/cache/packages/<hash>.json`) – 5-minute TTL, written by [`ProjectPackageApiClient`](../src/app/ProjectPackageApiClient.php). It is invalidated manually via the `Refresh data` button (`refresh_packages` POST) or implicitly when the cache age exceeds the TTL.
2. **GitHub refs cache** (`<installer>/var/cache/github-api/...`) – written by [`GitHubRefsCache`](../src/app/GitHubRefsCache.php). Used by the installer management screen to list tags and branches.

## The `var/` directory

`<target>/var` is the cache and runtime directory. It contains:

- `var/cache/packages/` – package list JSON cache.
- `var/cache/` – Symfony cache (when the runner has been installed).
- `var/log/` – Symfony logs (when the runner has been installed).

The `Clear cache` UI action invokes [`clearCacheDirectory`](../src/app/FilesystemSupport.php), which removes everything under `var/`.

## PHP-CS-Fixer, PHPStan and Rector

The project enforces consistent style, type safety, and modern syntax via three tools (all wired into `composer.json`):

- **ECS** (PHP-CS-Fixer) – `composer bin-ecs` (check) and `composer bin-ecs-fix` (apply).
- **PHPStan** – `composer bin-phpstan` (level `max`, no excludes).
- **Rector** – `composer bin-rector` (dry-run) and `composer bin-rector-process` (apply).

All three run inside the `installer-web-1` Docker container. See [development.md](development.md).
