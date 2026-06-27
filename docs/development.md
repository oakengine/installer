# Development Workflow

How to run the test suite, PHPStan, ECS, Rector and the Docker setup. Every project command runs inside the `installer-web-1` container as the `application` user, with `/app` as the working directory.

For the broader architecture see [architecture.md](architecture.md).

## Related documents

- [architecture.md](architecture.md) – module overview.
- [install-manifest.md](install-manifest.md) – the manifest tests in detail.
- [../AGENTS.md](../AGENTS.md) – the runtime rules this project follows.

## Docker

The project ships with a `docker-compose.yml` that defines the development stack. The relevant service is `installer-web-1`, which runs the installer PHP process.

Start it (if it is not already running):

```bash
docker compose up -d
```

All project commands are expected to run inside that container. Use the application user and `/app` as the working directory:

```bash
docker exec --user=application -w /app installer-web-1 bash -lc "<command>"
```

## Installing dependencies

Dependencies live in the root `composer.json`. Tools that need their own composer.json (PHPStan, ECS, PHPUnit, Rector) are installed through `bamarni/composer-bin-plugin` under `vendor-bin/`.

```bash
COMPOSER_MEMORY_LIMIT=-1 composer install
```

For tool-specific installs use the helper scripts, e.g.:

```bash
composer bin-phpstan-install
composer bin-ecs-install
composer bin-rector-install
composer bin-phpunit-install
```

## Quality tools

The project enforces the following standards. None of them may be silenced or excluded.

### PHPStan

Runs at level `max` against `src/`:

```bash
composer bin-phpstan
```

No excludes, no stubs. Every new file must pass.

### PHPUnit with coverage

```bash
composer test-coverage
```

The project targets **100% line and method coverage** for every class in `src/`. Tests must:

- Generate their own data for each test run (no shared fixture databases).
- Not assume fixture data from a previous test is still available.
- Not use stubs.

Faster iteration without coverage:

```bash
composer test           # = composer bin-phpunit-no-coverage
composer test-full      # = composer bin-phpunit
composer test-watch     # --testdox watch mode
```

### ECS (PHP-CS-Fixer)

Check style:

```bash
composer bin-ecs
```

Apply fixes:

```bash
composer bin-ecs-fix
```

### Rector

Dry run:

```bash
composer bin-rector
```

Apply transformations:

```bash
composer bin-rector-process
```

### CI

A combined command that runs all checks:

```bash
composer ci         # ECS check + Rector dry + PHPStan + PHPUnit (no coverage)
composer ci-fix     # ECS fix   + Rector apply + PHPStan + PHPUnit (no coverage)
composer ci-coverage
```

## Adding tests

The test suite lives under `tests/`. The two test files today are:

- `tests/EndpointSupportTest.php` – the GitHub endpoint helper tests.
- `tests/IndexFunctionsTest.php` – every other function and class, including the install manifest tests.

When you add a new test, follow the conventions in `tests/IndexFunctionsTest.php`:

- One test method per behavior.
- Use `createTempDirectory()` to get an isolated temp dir; the helper registers it for automatic cleanup in `tearDown()`.
- Reset `$_SESSION` and `$_SERVER['REQUEST_METHOD']` in `tearDown()` if you mutate them.
- Use the `Tests\` namespace and `require_once __DIR__.'/../src/index.php';` at the top.

When you add a new public method to `InstallManifestManager`, add at least one test that exercises each branch. The current suite has 24 tests for the manifest and reaches 100% line coverage.

## Adding translations

The language dictionaries live in `src/lang/<lang>.php` as plain PHP arrays. The `testAllLanguageFilesContainEnglishKeys` test enforces that every key present in `en.php` exists in all other languages.

To add a new translation key:

1. Add it to `src/lang/en.php`.
2. Add it to every other file in `src/lang/` (there are 12 languages).
3. Use it from PHP via `resolveLangKey('your_key', $lang)`.
4. If the key has placeholders, pass them as `[$langForGlobal, ['name' => $value]]`.

## Working with the install manifest

When you change the manifest format, the diff logic, or the install pipeline, make sure to update [install-manifest.md](install-manifest.md) and the German README if the user-visible behavior changes.

Specifically, if you change:

- The manifest file name → update [`InstallManifestManager::MANIFEST_FILENAME`](../src/app/InstallManifestManager.php) and the docs.
- The manifest JSON shape → update the example in [install-manifest.md](install-manifest.md).
- The diff strategy (currently path-based) → update the "Diff semantics" section.
- The cleanup logic → update the "Empty directory cleanup" section.

## Working with the package endpoint

When you change the HTTP contract (headers, request payload, response shape), update [package-endpoint.md](package-endpoint.md). The endpoint is consumed by installations other than the local Docker setup, so backwards-incompatible changes must be coordinated with whoever runs the endpoint.
