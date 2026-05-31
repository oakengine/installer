# Oak Engine Installer

A lightweight PHP installer for Oak Engine deployments. The project installation flow now uses a **server endpoint** that exposes runner, plugin, and data packages. Only the installer self-update still talks to GitHub.

## Features

- Install **runner**, **plugin**, and **data** packages from a package API endpoint.
- Send a persistent **Install UUID** with every package request.
- Self-update the installer from the `oakengine/installer` GitHub repository.
- Edit `.env.local`, switch `APP_ENV`, manage database entries, and regenerate the Install UUID.
- Check migration status, run Doctrine migrations, and clear the application cache.
- Protect the installer with a password.
- Preserve configured files and folders during runner updates.

## Requirements

- PHP 8.2 or higher
- PHP extensions: `curl`, `zip`, `openssl`
- Write permissions for the target directory
- `shell_exec` enabled if you want to use migration and cache actions from the UI

## Installation

1. Copy the complete contents of `src/` to the web-accessible installer directory on the target server.
2. Rename `config.example.php` to `config.php`.
3. Set at least `project_api_url` and `target_directory`.
4. Open the installer in the browser.

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
| `updater_source_path` | Source path inside the installer repository used during self-update. |
| `exclude_folders` / `exclude_files` | Files and folders that must never be extracted into the target directory. |
| `whitelist_folders` / `whitelist_files` | Files and folders that must be preserved during runner installation. |
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

For package lists, the endpoint can return either a plain array or an object with a `packages` array. The installer sends the current Install UUID as `X-Install-UUID` and, when configured, the package API token as `Authorization: Bearer ...`.

## Security notes

- Set a password in `config.php`.
- Restrict access to the installer directory with web server rules if possible.
- Remove the installer when it is no longer needed.

## License

MIT. See `LICENSE`.
