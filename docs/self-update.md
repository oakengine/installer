# Self-Update from GitHub

The installer can update itself in place from a GitHub repository. Only the files that belong to the installer (`src/` plus an optional `updater_source_path`) are touched. The user configuration, `var/`, and the target directory are never modified by the self-update.

For the broader architecture see [architecture.md](architecture.md). For security considerations see [security.md](security.md).

## Related documents

- [architecture.md](architecture.md) – where the self-update handler sits.
- [security.md](security.md) – HTTPS, GitHub tokens, downgrade policy.

## When to use it

Open the installer, go to **Installer management** in the dashboard. From there you can list branches and tags of the configured repository and install any of them.

The installer version that is currently running is shown at the top of the page. After a successful self-update, the installer reloads (the next page request already runs the new version).

## Configuration

| Config key | Default | Notes |
| --- | --- | --- |
| `installer_repository` | `oakengine/installer` | The GitHub repo to pull from. |
| `installer_version` | empty | Optional. When set, the installer remembers a specific version after a successful self-update. |
| `installer_commit` | empty | Companion to `installer_version`. Stores the commit hash for branches. |
| `github_token` | empty | Optional. Increases the rate limit for unauthenticated requests. |
| `updater_source_path` | `src` | Path inside the archive that contains the installer files to copy. |
| `api_base_url` | `https://api.github.com` | Override for GitHub Enterprise. |

## Source path

The archive that GitHub returns has a top-level directory named after the repo (`<repo>-<sha>`). The installer looks inside that directory for `updater_source_path` (default `src/`) and copies that subtree into the installer's own root.

So for `oakengine/installer` with the default `updater_source_path=src`, the self-update copies `oakengine-installer-<sha>/src/*` into the installer's own `src/`.

## Allowed files

Not every file under `src/` is copied. `updateUpdaterFromTag()` filters the archive entries through [`isAllowedUpdaterFile`](../src/app/InstallerUpdater.php):

- `.htaccess` at the root.
- `config.php` is **never** copied (user-specific).
- Any single PHP file at the root (e.g. `index.php`).
- Any single PHP file directly under `app/` (e.g. `app/InstallerApplication.php`).
- Any `.php` file under `lang/`.
- Any `.svg`, `.png`, `.js`, or `.ai` file under `logo/`.

Everything else is skipped and reported back to the user as `skipped_files`.

## Version handling

The currently installed version comes from one of these sources, in order:

1. `config.php` key `installer_version` (or `installer_commit`).
2. The `version` field in the installer's own `composer.json`.
3. The literal string `unknown`.

Branch refs are stored as `installer_version=<branch>` and `installer_commit=<full sha>`. Semver tags are stored as the tag name itself (e.g. `1.4.0`).

`resolveInstallerVersion()` appends the first 7 characters of `installer_commit` to the branch name when the version is not a semver tag, so that the displayed version uniquely identifies the installed commit. Semver tags are returned unchanged.

## Downgrade policy

`canUpdateInstallerToTag($current, $target)` blocks updates that go backwards in semver:

- If either side is not a semver tag (e.g. a branch name), the update is always allowed.
- If both are semver tags, the target must be `>=` the current version.

The check is used for informational purposes; the dashboard button itself does not enforce it. The user can still type any tag or branch into the form.

## Request flow

```
POST /index.php
  self_update = 1
  ref = <branch or tag>
  ref_commit = <sha>
  ref_type = branch | tag  (optional)

  1. getCachedGitHubRepositoryRefs(...) → list of tags and branches.
  2. Validate that $ref exists in the cached list.
  3. Call canUpdateInstallerToTag(...) (advisory).
  4. downloadArchive(repo, ref, ref_type) via GitHub.
  5. Extract into a temp directory.
  6. Walk the archive under <updater_source_path>.
  7. Copy every allowed file over the installer's own file.
  8. Remove the temp directory.
  9. Update config.php with the new version + commit.
 10. Render the result page with updated_files and skipped_files.
```

Implementation: [`updateUpdaterFromTag()`](../src/app/InstallerUpdater.php).

## After a successful self-update

`config.php` is rewritten via [`writeConfigValues()`](../src/app/InstallerUpdater.php) with the two new values:

```php
[
    'installer_version' => $tag,
    'installer_commit'  => $refCommit,
]
```

The function merges the new values into the existing array (other keys are preserved) and pretty-prints the file with `var_export`.

## Cache

GitHub tag and branch listings are cached under `<installer>/var/cache/github-api/...` by [`GitHubRefsCache`](../src/app/GitHubRefsCache.php). The cache TTL is **15 minutes** by default. Refresh it by reloading the **Installer management** page after the TTL has elapsed, or by re-running the self-update action.

## What never gets touched

The self-update pipeline explicitly does not touch:

- `config.php` (except for the version keys, and only via the merge above).
- Anything outside `src/` in the installer directory.
- Anything in the target directory.
- The manifest files inside the target directory (runner / plugin / data).

So a self-update followed by a project install is fully independent.
