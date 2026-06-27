# Install Manifest

Every install of a runner, plugin or data package writes a manifest file with one entry per extracted file and its SHA1 hash. On the next install of the same package, the installer compares the old manifest against the new one and removes only the files that disappeared. Empty directories that result from the cleanup are removed as well.

This document is the canonical spec for the manifest. For the user-facing overview see [../README.md](../README.md). For how it fits into the bigger picture see [architecture.md](architecture.md).

## Related documents

- [architecture.md](architecture.md) – module overview, install pipeline, request lifecycle.
- [security.md](security.md) – why the manifest file is excluded from the manifest, path-traversal protection.
- [development.md](development.md) – how to run the manifest tests.

## Where the manifest lives

The manifest is stored alongside the installed package, one per install:

| Package type | Manifest path |
| --- | --- |
| `runner` | `<target_directory>/.oak-install-manifest.json` |
| `plugin` | `<target_directory>/runner/<packageDir>/.oak-install-manifest.json` |
| `data` | `<target_directory>/data/<packageDir>/.oak-install-manifest.json` |

`<packageDir>` is the directory the package was extracted into (see [architecture.md](architecture.md)). The constant that defines the filename lives in [`src/app/InstallManifestManager.php`](../src/app/InstallManifestManager.php) as `InstallManifestManager::MANIFEST_FILENAME = '.oak-install-manifest.json'`.

The file is intentionally dot-prefixed so it stays out of the way and is easy to recognize as installer metadata.

## File format

A JSON object with three metadata fields and a `files` map:

```json
{
  "package_type": "plugin",
  "package_id": "oak-contact-panel",
  "version": "1.4.2",
  "files": {
    "composer.json": "7d38c12b4d09a0c2c8e7f1c9a1d3b8e0c5e7f9a1",
    "src/Controller/PanelController.php": "b1e02a3c4f5d6789abcdef0123456789abcdef01",
    "public/panel.js": "1234567890abcdef1234567890abcdef12345678"
  }
}
```

- `package_type` – one of `runner`, `plugin`, `data`.
- `package_id` – the package identifier as returned by the endpoint.
- `version` – the version that was installed.
- `files` – map of relative path (forward slashes) to SHA1 hash of the file content.

Only files that were actually extracted (after applying `exclude_folders` and `exclude_files`) are tracked. The manifest file itself is never tracked, so it can never be deleted by its own diff. See `buildManifest()` in [`src/app/InstallManifestManager.php`](../src/app/InstallManifestManager.php).

## Lifecycle

1. **Before extraction** – `loadManifest($packageTargetDir)` reads the previous manifest. It returns `null` on first install or if the file is corrupt.
2. **Extraction** – `ProjectPackageArchiveExtractor::extractTarGzFile()` writes the new files into the target directory and returns the list of relative paths it actually wrote.
3. **Build new manifest** – `buildManifest($packageTargetDir, $type, $id, $version, $extractedFiles)` walks the list, computes `sha1_file` for each existing file, and returns a new manifest array.
4. **Save** – `saveManifest($packageTargetDir, $manifest)` writes the JSON file pretty-printed.
5. **Diff** – `diffStaleFiles($oldManifest, $newManifest)` returns the relative paths that were tracked before but are no longer in the new manifest, sorted alphabetically.
6. **Cleanup** – `deleteStaleFilesAndEmptyDirs($packageTargetDir, $staleFiles)` deletes each stale file (guarded against `..` traversal) and then walks the target directory from the inside out, removing every directory that became empty.

The full sequence lives in the `install` POST handler at [`src/app/InstallerApplication.php:512`](../src/app/InstallerApplication.php).

## First-install vs update

The install handler has a single migration step built in:

```php
$oldManifest = $manifestManager->loadManifest($packageTargetDir);
$cleanResult = ['deleted_count' => 0, 'preserved' => []];
if (null === $oldManifest) {
    $cleanResult = cleanTargetDirectory($packageTargetDir);
}
```

If no manifest exists yet, the installer falls back to the legacy `cleanTargetDirectory()` wipe (which preserves `runner/`, `data/`, `public/update`, `.env.local` for the runner type). This means existing installs get exactly one wipe on the next update, after which every subsequent install goes through the diff path.

Once a manifest exists, the installer skips the wipe entirely. Instead:

- Existing files are overwritten in place by the extractor.
- Files that no longer exist in the new package are removed by the diff.
- Empty directories are removed.
- Anything that was *not* tracked in the manifest (user files, `.env.local`, custom dotfiles) stays untouched.

This means **plugin and data folders are automatically preserved across runner updates**, because they have their own manifests and are not in the runner manifest.

## Diff semantics

The diff is **path-based**. Two files with the same path are considered the same file even if their SHA1 changed – they are simply overwritten by the extractor. The SHA1 is recorded for visibility (and could later be used to detect tampered files), but deletion is triggered by path only.

Concretely:

- File in old manifest, not in new manifest → **deleted**.
- File in old manifest AND in new manifest → overwritten in place, hash updated.
- File in new manifest but not in old manifest → newly extracted, hash recorded.
- File neither in old nor in new manifest → left alone (user file).

## Empty directory cleanup

`deleteStaleFilesAndEmptyDirs()` iterates the target directory from deepest to shallowest using `RecursiveIteratorIterator(..., CHILD_FIRST, CATCH_GET_CHILD)` and calls `rmdir()` on every directory that contains no more entries. The flag `CATCH_GET_CHILD` makes the iterator silently skip directories it cannot enter instead of throwing.

A directory is considered empty when `scandir()` returns exactly two entries (`.` and `..`). A directory that exists but cannot be read is conservatively treated as non-empty and is **not** removed.

## Path traversal protection

`deleteStaleFilesAndEmptyDirs()` rejects any stale path that contains a `..` segment:

```php
if ($this->containsTraversal($normalizedPath)) {
    continue;
}
```

`containsTraversal()` splits the path on `/` and checks for an exact `..` entry. This means legitimate filenames like `foo..bar` or `..hidden` are unaffected, but `../etc/passwd` and `foo/../bar` are silently skipped.

See [security.md](security.md) for the broader threat model.

## Public API of `InstallManifestManager`

| Method | Purpose |
| --- | --- |
| `loadManifest(string $packageTargetDir): ?array` | Read the previous manifest, or `null` when none exists. |
| `saveManifest(string $packageTargetDir, array $manifest): bool` | Write the manifest JSON. |
| `manifestExists(string $packageTargetDir): bool` | Quick existence check. |
| `manifestPath(string $packageTargetDir): string` | Compute the absolute manifest path. |
| `buildManifest(string $packageTargetDir, string $type, string $id, string $version, array $extractedFiles): array` | Build a manifest array with SHA1 hashes. |
| `diffStaleFiles(?array $oldManifest, array $newManifest): array` | Return sorted list of stale relative paths. |
| `deleteStaleFilesAndEmptyDirs(string $packageTargetDir, array $staleFiles): array` | Delete the given files, then recursively remove every directory that became empty. Returns `{deleted_files, deleted_dirs, errors}`. |

Full signatures and types are documented in the source: [`src/app/InstallManifestManager.php`](../src/app/InstallManifestManager.php).
