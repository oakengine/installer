# OakEngine Installer

A lightweight PHP installer for OakEngine deployments. The project installation flow uses a **server endpoint** that exposes runner, plugin, and data packages. Only the installer self-update still talks to GitHub.

## Features

- Install **runner**, **plugin**, and **data** packages from a package API endpoint.
- **Install manifest tracking** – every install keeps a SHA1-backed list of all files it wrote, so updates can remove obsolete files and empty directories via diff.
- Send a persistent **Install UUID** with every package request.
- Self-update the installer from the `oakengine/installer` GitHub repository.
- Edit `.env.local`, switch `APP_ENV`, manage database entries, and regenerate the Install UUID.
- Check migration status, run Doctrine migrations, and clear the application cache.
- Protect the installer with a password.
- Preserve plugin and data folders automatically during runner updates (separate manifests).

## Documentation

- English overview: this document.
- German overview: [README.de.md](README.de.md).
- Installer architecture and internals: [docs/architecture.md](docs/architecture.md).
- Install manifest format and diff workflow: [docs/install-manifest.md](docs/install-manifest.md).
- Package endpoint contract in detail: [docs/package-endpoint.md](docs/package-endpoint.md).
- Self-update workflow from GitHub: [docs/self-update.md](docs/self-update.md).
- Development, Docker, and test workflow: [docs/development.md](docs/development.md).
- Security notes for production use: [docs/security.md](docs/security.md).

## Requirements

- PHP 8.2 or higher
- PHP extensions: `curl`, `zip`, `openssl`
- Write permissions for the target directory
- `shell_exec` enabled if you want to use migration and cache actions from the UI

## Installation

1. Copy the complete contents of `src/` to the web-accessible installer directory on the target server.
2. Rename `config.example.php` to `config.php`.
3. Set at least `project_api_url` and `target_directory`.
4. For the local Docker setup, the default package API endpoint is `http://web-server/index.php` with the dev token `oak-local-dev-token`.
5. Open the installer in the browser.

## Configuration

`config.php` returns a plain PHP array. The most important options are:

| Key | Description |
| --- | --- |
| `project_api_url` | Base URL of the package server endpoint used for project packages. |
| `project_api_token` | Optional bearer token for the package API. |
| `target_directory` | Directory where the selected runner package is installed. |
| `installer_repository` | GitHub repository used for installer self-updates. |
| `installer_version` | Current installer version; updated automatically after self-update. |
| `github_token` | Optional token for GitHub self-update requests. |
| `password` | Optional UI password, plain text or password hash. |
| `log_directory` | Directory where the installer writes `oak-installer.log`. MUST live outside the installed project so cache clears never delete it. Absolute path or relative to the installer root. Empty = default `<installer-root>/logs`. |
| `updater_source_path` | Source path inside the installer repository used during self-update. |
| `exclude_folders` / `exclude_files` | Files and folders that must never be extracted into the target directory. |
| `default_language` | UI language fallback. |

## Package endpoint contract

The installer accepts list and detail responses that provide package metadata in this shape:

```json
{
  "package_type": "runner",
  "package_id": "oak-runner",
  "version": "1.2.3",
  "channel": "stable",
  "package_name": "oak/runner",
  "archive_size": 123456,
  "archive_sha256": "…",
  "download_url": "https://packages.example.test/downloads/oak-runner-1.2.3.tar.gz",
  "composer": {
    "name": "oak/runner"
  }
}
```

For package lists, the endpoint can return either a plain array or an object with a `packages` array. The installer sends the current Install UUID as `X-Install-UUID` and, when configured, the package API token as `Authorization: Bearer ...`. Full details: [docs/package-endpoint.md](docs/package-endpoint.md).

## How updates work

For each install (runner, plugin, data) the installer writes a manifest file with one entry per extracted file and its SHA1 hash. On the next install, it diffs the old manifest against the new one and removes only the files that disappeared. Empty directories that result from the cleanup are also removed.

- Format spec and example: [docs/install-manifest.md](docs/install-manifest.md)
- Implementation: [`src/app/InstallManifestManager.php`](src/app/InstallManifestManager.php)

## Logging

The installer keeps a structured, append-only audit log at `oak-installer.log`. The log directory is resolved once per request and lives **outside the installed project** (outside the `<target>/var/` cache tree), so clearing the application cache, a first-install cleanup, or any similar operation never deletes it:

1. `log_directory` in `config.php` if set (absolute path, or relative to the installer root).
2. Default: `<installer-root>/logs` (a `logs/` folder next to the installer's own `src/`).

As a safety net, `clearCacheDirectory()` also preserves any top-level `log`/`logs` directory inside the cache tree, so runtime logs (e.g. Symfony's `var/log`) survive a cache clear too. Logging is best-effort and never breaks an installation. Implementation: [`src/app/FilesystemSupport.php`](src/app/FilesystemSupport.php).

## Security notes

- Set a password in `config.php`.
- Restrict access to the installer directory with web server rules if possible.
- Remove the installer when it is no longer needed.
- More details: [docs/security.md](docs/security.md)

## License

MIT. See [LICENSE](LICENSE).
