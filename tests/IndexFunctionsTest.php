<?php

declare(strict_types=1);

namespace Tests;

use Oak\Engine\Installer\InstallUuidManager;
use Oak\Engine\Installer\AppSecretManager;
use Oak\Engine\Installer\ProjectPackageApiClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

require_once __DIR__.'/../src/index.php';

final class IndexFunctionsTest extends TestCase
{
    /**
     * @var list<string>
     */
    private array $pathsToDelete = [];

    public function tearDown(): void
    {
        foreach (array_reverse($this->pathsToDelete) as $path) {
            $this->deletePath($path);
        }

        $this->pathsToDelete = [];
        $_SESSION['lang'] = 'en';
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    public function testExtractSemverFromTag(): void
    {
        $this->assertSame('1.0.0', extractSemverFromTag('1.0.0'));
        $this->assertSame('1.2.3', extractSemverFromTag('v1.2.3'));
        $this->assertSame('1.2.3', extractSemverFromTag('V1.2.3'));
        $this->assertNull(extractSemverFromTag('latest'));
        $this->assertNull(extractSemverFromTag('1.0'));
        $this->assertNull(extractSemverFromTag('1.0.0-alpha'));
    }

    public function testFormatPackageSize(): void
    {
        $this->assertSame('512 B', formatPackageSize(512));
        $this->assertSame('1.0 KB', formatPackageSize(1024));
        $this->assertSame('1.0 MB', formatPackageSize(1024 * 1024));
    }

    public function testResolveInstalledProjectVersionUsesRunnerMetadata(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents(
            $targetDir.'/composer.json',
            json_encode([
                'extra' => [
                    'oak-engine-runner' => [
                        'version' => '1.2.3',
                        'channel' => 'stable',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertSame('1.2.3 (stable)', resolveInstalledProjectVersion($targetDir));
        $this->assertSame('unknown', resolveInstalledProjectVersion($targetDir.'/missing'));
    }

    public function testResolveComposerPackageVersion(): void
    {
        $targetDir = $this->createTempDirectory();
        $composerPath = $targetDir.'/composer.json';
        file_put_contents(
            $composerPath,
            json_encode([
                'name' => 'oak/installer',
                'version' => 'dev-main',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertSame('dev-main', resolveComposerPackageVersion($composerPath));
        $this->assertSame('', resolveComposerPackageVersion($targetDir.'/missing.json'));
    }

    public function testReadComposerJsonMetadata(): void
    {
        $targetDir = $this->createTempDirectory();
        $composerPath = $targetDir.'/composer.json';
        file_put_contents($composerPath, json_encode([
            'name' => 'oak/installer',
            'extra' => [
                'oak-engine-runner' => [
                    'version' => '1.0.1',
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            'name' => 'oak/installer',
            'extra' => [
                'oak-engine-runner' => [
                    'version' => '1.0.1',
                ],
            ],
        ], readComposerJsonMetadata($composerPath));
        $this->assertSame([], readComposerJsonMetadata($targetDir.'/missing.json'));
    }

    public function testResolveInstalledPackagesFindsMultiplePluginAndDataEntries(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/example/core/index-bundle', 0o755, true);
        mkdir($targetDir.'/runner/example/plugin/contact-panel', 0o755, true);
        mkdir($targetDir.'/runner/homanit/core/index-bundle', 0o755, true);
        mkdir($targetDir.'/data/example', 0o755, true);
        mkdir($targetDir.'/data/homanit', 0o755, true);
        mkdir($targetDir.'/vendor/vendor/package', 0o755, true);

        $pluginComposer = static fn (string $name, string $dir, string $version, string $channel): string => json_encode([
            'name' => $name,
            'extra' => [
                'oak-engine-plugin' => [
                    'version' => $version,
                    'channel' => $channel,
                    'env' => [
                        'dir' => $dir,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($targetDir.'/runner/example/core/index-bundle/composer.json', $pluginComposer('oakengine/oak-example', 'example', '1.0.0', 'stable'));
        file_put_contents($targetDir.'/runner/example/plugin/contact-panel/composer.json', $pluginComposer('oakengine/oak-example', 'example', '1.0.0', 'stable'));
        file_put_contents($targetDir.'/runner/homanit/core/index-bundle/composer.json', $pluginComposer('oakengine/plugin-homanit', 'homanit', '2.0.0', 'beta'));

        $dataComposer = static fn (string $name, string $version, string $channel): string => json_encode([
            'name' => $name,
            'extra' => [
                'oak-engine-data' => [
                    'version' => $version,
                    'channel' => $channel,
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        file_put_contents($targetDir.'/data/example/composer.json', $dataComposer('oakengine/example', '1.0.0', 'stable'));
        file_put_contents($targetDir.'/data/homanit/composer.json', $dataComposer('oakengine/homanit', '2.0.0', 'beta'));

        file_put_contents(
            $targetDir.'/vendor/vendor/package/composer.json',
            json_encode(['name' => 'vendor/package'], JSON_THROW_ON_ERROR)
        );

        $this->assertSame([
            ['name' => 'example', 'version' => '1.0.0', 'channel' => 'stable'],
            ['name' => 'homanit', 'version' => '2.0.0', 'channel' => 'beta'],
        ], resolveInstalledPackages($targetDir, 'plugin'));
        $this->assertSame([
            ['name' => 'example', 'version' => '1.0.0', 'channel' => 'stable'],
            ['name' => 'homanit', 'version' => '2.0.0', 'channel' => 'beta'],
        ], resolveInstalledPackages($targetDir, 'data'));
    }

    public function testResolvePackageInstallTargetDir(): void
    {
        $this->assertSame('/var/project', resolvePackageInstallTargetDir('/var/project', 'runner'));
        $this->assertSame('/var/project/runner/example', resolvePackageInstallTargetDir('/var/project', 'plugin', 'example'));
        $this->assertSame('/var/project/data/example', resolvePackageInstallTargetDir('/var/project/', 'data', 'example'));
    }

    public function testResolvePackageInstallTargetDirThrowsForMissingPluginDataDir(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        resolvePackageInstallTargetDir('/var/project', 'plugin');
    }

    public function testResolvePackageInstallDirFromMetadataPrefersEnvDir(): void
    {
        $metadata = [
            'name' => 'oakengine/oak-example',
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'example',
                    ],
                ],
            ],
        ];

        $this->assertSame('example', resolvePackageInstallDirFromMetadata($metadata, 'plugin'));
    }

    public function testResolvePackageInstallDirFromMetadataFallsBackToComposerName(): void
    {
        $metadata = [
            'name' => 'oakengine/example',
            'extra' => [
                'oak-engine-data' => [
                    'version' => '1.0.0',
                ],
            ],
        ];

        $this->assertSame('example', resolvePackageInstallDirFromMetadata($metadata, 'data'));
    }

    public function testResolvePackageInstallDirFromMetadataThrowsWhenUnresolved(): void
    {
        $this->expectException(\RuntimeException::class);
        resolvePackageInstallDirFromMetadata([], 'plugin');
    }

    public function testNormalizePackageType(): void
    {
        $this->assertSame('runner', normalizePackageType('runner'));
        $this->assertSame('plugin', normalizePackageType('plugin'));
        $this->assertSame('data', normalizePackageType('data'));
        $this->assertSame('runner', normalizePackageType('unsupported'));
        $this->assertSame('runner', normalizePackageType([]));
    }

    public function testRenderPackageListHtml(): void
    {
        $packages = [
            [
                'package_type' => 'runner',
                'package_id' => 'oak-runner',
                'version' => '1.2.3',
                'channel' => 'stable',
                'package_name' => 'oak/runner',
                'archive_size' => 2048,
                'archive_sha256' => 'hash',
                'download_url' => 'https://example.com/download',
                'composer' => ['name' => 'oak/runner'],
            ],
            [
                'package_type' => 'runner',
                'package_id' => 'oak-runner',
                'version' => '1.10.0',
                'channel' => 'stable',
                'package_name' => 'oak/runner',
                'archive_size' => 4096,
                'archive_sha256' => 'hash2',
                'download_url' => 'https://example.com/download2',
                'composer' => ['name' => 'oak/runner'],
            ],
        ];

        $html = renderPackageListHtml($packages, 'runner', ['install' => 'Install', 'no_tags_found' => 'None']);

        $this->assertStringContainsString('1.2.3', $html);
        $this->assertStringContainsString('1.10.0', $html);
        $this->assertStringContainsString('stable', $html);
        $this->assertStringContainsString('2.0 KB', $html);
        $this->assertStringContainsString('name="package_type" value="runner"', $html);
        $this->assertStringContainsString('class="dropdown dropdown-version"', $html);
        $this->assertStringContainsString('<input type="hidden" name="version" value="1.10.0">', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertSame(1, substr_count($html, 'name="install"'));
        $this->assertSame('<li><em>None</em></li>', renderPackageListHtml([], 'runner', ['no_tags_found' => 'None']));
    }

    public function testRenderModal(): void
    {
        $html = renderModal('modal-x', 'Details', '<p>Body</p>', 'Close');

        $this->assertStringContainsString('<div class="modal" id="modal-x" role="dialog" aria-modal="true" aria-hidden="true">', $html);
        $this->assertStringContainsString('data-modal-close="modal-x"', $html);
        $this->assertStringContainsString('<h3 class="modal-title">Details</h3>', $html);
        $this->assertStringContainsString('aria-label="Close"', $html);
        $this->assertStringContainsString('<div class="modal-body"><p>Body</p></div>', $html);
    }

    public function testRenderWhitelistValueSingleEntryShowsChip(): void
    {
        $html = renderWhitelistValue(['public/update'], '1 entries', 'Whitelist', 'Close');

        $this->assertStringContainsString('<div class="status-chips"><span class="status-chip">public/update</span></div>', $html);
        $this->assertStringNotContainsString('status-count', $html);
        $this->assertStringNotContainsString('class="modal"', $html);
    }

    public function testRenderWhitelistValueMultipleEntriesShowsCountAndModal(): void
    {
        $html = renderWhitelistValue(['public/update', '.env.local', 'config/app.php'], '3 entries', 'Whitelist active', 'Close');

        $this->assertStringContainsString('data-modal-open="modal-whitelist"', $html);
        $this->assertStringContainsString('<span class="status-count-num">3</span>', $html);
        $this->assertStringContainsString('<span class="status-count-text">entries</span>', $html);
        $this->assertStringNotContainsString('<span class="status-count-text">3 entries</span>', $html);
        $this->assertStringContainsString('id="modal-whitelist"', $html);
        $this->assertStringContainsString('<li><code>config/app.php</code></li>', $html);
        $this->assertStringContainsString('<ul class="modal-list">', $html);
    }

    public function testRenderWhitelistValueEmptyReturnsEmptyString(): void
    {
        $this->assertSame('', renderWhitelistValue([], '0 entries', 'Whitelist', 'Close'));
    }

    public function testFormatVersionBadge(): void
    {
        $this->assertSame('<code>1.0.0</code> <span class="status-badge">stable</span>', formatVersionBadge('1.0.0 (stable)'));
        $this->assertSame('<code>unknown</code>', formatVersionBadge('unknown'));
        $this->assertSame('<code>2.3.4</code>', formatVersionBadge('  2.3.4  '));
    }

    public function testRenderHomeSections(): void
    {
        $html = renderHomeSections('System', [
            ['icon' => 'endpoint', 'label' => 'Server Endpoint', 'value' => '<code>http://x</code>'],
            ['icon' => 'folder', 'label' => 'Target directory', 'value' => '<code>/app</code>'],
            ['icon' => 'code', 'label' => 'PHP version', 'value' => '<code>8.4.0</code>'],
            [
                'icon' => 'puzzle',
                'label' => 'PHP modules',
                'value' => '<span class="info-list-meta">42 modules</span>',
                'action_html' => '<button type="button" class="info-list-action" data-modal-open="modal-php-modules" title="PHP modules" aria-label="PHP modules">'.lucideIcon('info', 16).'</button>',
            ],
            ['icon' => 'upload-cloud', 'label' => 'Upload limit', 'value' => '<code>128M</code>'],
            ['icon' => 'clock', 'label' => 'Max execution time', 'value' => '<code>300</code>'],
            ['icon' => 'memory-stick', 'label' => 'Memory limit', 'value' => '<code>512M</code>'],
            ['icon' => 'hard-drive', 'label' => 'Cache size', 'value' => '<code>/var</code>'],
            ['icon' => 'folder-tree', 'label' => 'Installed Data', 'value' => '<ul></ul>'],
            ['icon' => 'unknown-icon', 'label' => 'No icon here', 'value' => '<code>x</code>'],
        ], [
            [
                'title' => 'Configuration',
                'icon' => 'settings',
                'href' => '?view=environment',
                'items' => [
                    ['icon' => 'settings', 'label' => 'Mode', 'value' => '<code>dev</code>', 'action' => '?view=environment', 'action_title' => 'Configure'],
                ],
            ],
            [
                'title' => 'Installations',
                'icon' => 'download',
                'href' => '?view=updates',
                'items' => [
                    ['icon' => 'runner', 'label' => 'Runner Version', 'value' => '<code>1.0.0</code>', 'action' => '?view=updates', 'action_title' => 'Configure'],
                ],
            ],
        ]);

        $this->assertStringContainsString('class="home-stack"', $html);
        $this->assertStringContainsString('home-card-header--static', $html);
        $this->assertStringContainsString('Server Endpoint', $html);
        $this->assertStringContainsString('Target directory', $html);
        $this->assertStringContainsString('PHP version', $html);
        $this->assertStringContainsString('PHP modules', $html);
        $this->assertStringContainsString('Upload limit', $html);
        $this->assertStringContainsString('Max execution time', $html);
        $this->assertStringContainsString('Memory limit', $html);
        $this->assertStringContainsString('Cache size', $html);
        $this->assertStringContainsString('Installed Data', $html);
        $this->assertStringContainsString('No icon here', $html);
        $this->assertStringContainsString('class="home-card"', $html);
        $this->assertStringContainsString('class="home-card-header" href="?view=environment"', $html);
        $this->assertStringContainsString('Configuration', $html);
        $this->assertStringContainsString('class="home-card-header" href="?view=updates"', $html);
        $this->assertStringContainsString('Installations', $html);
        $this->assertStringContainsString('class="home-card-icon"', $html);
        $this->assertStringContainsString('class="home-card-cta"', $html);
        $this->assertStringContainsString('class="info-list-action" href="?view=environment" title="Configure" aria-label="Configure"', $html);
        $this->assertStringContainsString('class="info-list-action" href="?view=updates" title="Configure" aria-label="Configure"', $html);
        $this->assertStringContainsString('data-modal-open="modal-php-modules"', $html);
        $this->assertStringContainsString('<circle cx="12" cy="12" r="10"/>', $html);
        $this->assertStringNotContainsString('class="home-info"', $html);
        $this->assertStringNotContainsString('class="home-grid"', $html);
        $this->assertStringNotContainsString('<select', $html);
    }

    public function testFormatVersionBadgeEscapesValue(): void
    {
        $this->assertSame('<code>1.0.0</code> <span class="status-badge">a&lt;b</span>', formatVersionBadge('1.0.0 (a<b)'));
    }

    public function testProjectPackageApiClientCachesListAndExposesRefresh(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';

        $client = new ProjectPackageApiClient('https://invalid.invalid/', 'runner', '', '', $cacheDir);

        $this->assertNull($client->getCacheAge());

        $cacheFile = (function () use ($client) {
            $reflection = new \ReflectionClass($client);
            $method = $reflection->getMethod('getCacheFile');
            $method->setAccessible(true);

            return $method->invoke($client);
        })();
        $this->assertNotNull($cacheFile);

        $payload = [[
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'Demo/Runner',
            'archive_size' => 1024,
            'archive_sha256' => 'abc',
            'download_url' => 'https://example.test/demo.tar.gz',
            'composer' => ['oak-engine-runner' => ['version' => '1.0.0']],
        ]];
        mkdir(dirname((string) $cacheFile), 0o755, true);
        file_put_contents((string) $cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));
        touch((string) $cacheFile, time());

        $cached = $client->listPackages();
        $this->assertSame('1.0.0', $cached[0]['version']);
        $this->assertSame(300, $client->getCacheTtl());
        $this->assertNotNull($client->getCacheAge());
        $this->assertLessThan(60, $client->getCacheAge());

        touch((string) $cacheFile, time() - 600);
        clearstatcache(true, (string) $cacheFile);
        $this->expectException(\RuntimeException::class);
        $client->listPackages();
    }

    public function testProjectPackageApiClientRefreshBypassesCache(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';
        $client = new ProjectPackageApiClient('https://invalid.invalid/', 'runner', '', '', $cacheDir);

        $cacheFile = (function () use ($client) {
            $reflection = new \ReflectionClass($client);
            $method = $reflection->getMethod('getCacheFile');
            $method->setAccessible(true);

            return $method->invoke($client);
        })();
        $this->assertNotNull($cacheFile);
        mkdir(dirname((string) $cacheFile), 0o755, true);

        $payload = [['package_type' => 'runner', 'package_id' => 'demo', 'version' => '1.0.0', 'channel' => 'stable', 'package_name' => 'Demo/Runner', 'archive_size' => 1024, 'archive_sha256' => 'abc', 'download_url' => 'https://x/y', 'composer' => []]];
        file_put_contents((string) $cacheFile, json_encode($payload, JSON_THROW_ON_ERROR));
        touch((string) $cacheFile, time());

        $this->expectException(\RuntimeException::class);
        $client->refreshPackages();
        $this->assertFileDoesNotExist((string) $cacheFile);
    }

    public function testProjectPackageApiClientInvalidateCacheRemovesFile(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';
        $client = new ProjectPackageApiClient('https://invalid.invalid/', 'runner', '', '', $cacheDir);

        $cacheFile = (function () use ($client) {
            $reflection = new \ReflectionClass($client);
            $method = $reflection->getMethod('getCacheFile');
            $method->setAccessible(true);

            return $method->invoke($client);
        })();
        $this->assertNotNull($cacheFile);
        mkdir(dirname((string) $cacheFile), 0o755, true);
        file_put_contents((string) $cacheFile, '[]');
        $this->assertFileExists((string) $cacheFile);

        $client->invalidateCache();
        $this->assertFileDoesNotExist((string) $cacheFile);
        $this->assertNull($client->getCacheAge());
    }

    public function testProjectPackageApiClientWithoutCacheDirectoryReturnsNullAge(): void
    {
        $client = new ProjectPackageApiClient('https://invalid.invalid/', 'runner', '', '', null);
        $this->assertNull($client->getCacheAge());
    }

    public function testRenderDropdownPreselectsValueAndReplacesSelect(): void
    {
        $html = renderDropdown('app_env', [
            ['value' => 'dev', 'label' => 'Dev'],
            ['value' => 'prod', 'label' => 'Prod'],
        ], 'prod', false, 'dropdown-env');

        $this->assertStringContainsString('class="dropdown dropdown-env"', $html);
        $this->assertStringContainsString('<input type="hidden" name="app_env" value="prod">', $html);
        $this->assertStringContainsString('data-value="prod" aria-selected="true"', $html);
        $this->assertStringContainsString('<span class="dropdown-label">Prod</span>', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('data-autosubmit', $html);
    }

    public function testRenderDropdownFallsBackToFirstOptionAndSupportsAutoSubmit(): void
    {
        $html = renderDropdown('lang', [
            ['value' => 'en', 'label' => 'EN'],
            ['value' => 'de', 'label' => 'DE'],
        ], 'xx', true);

        $this->assertStringContainsString('data-autosubmit="1"', $html);
        $this->assertStringContainsString('<input type="hidden" name="lang" value="en">', $html);
        $this->assertStringContainsString('<span class="dropdown-label">EN</span>', $html);
    }

    public function testRenderDropdownShowsDisabledPlaceholderWhenEmpty(): void
    {
        $html = renderDropdown('database', [], '', false, 'dropdown-db');

        $this->assertStringContainsString('class="dropdown dropdown-db is-disabled"', $html);
        $this->assertStringContainsString('<input type="hidden" name="database" value="">', $html);
        $this->assertStringContainsString('<button type="button" class="dropdown-toggle" aria-haspopup="listbox" aria-expanded="false" disabled aria-disabled="true">', $html);
        $this->assertStringContainsString('<span class="dropdown-label">-</span>', $html);
        $this->assertStringNotContainsString('data-autosubmit="1"', $html);
    }

    public function testAllLanguageFilesContainEnglishKeys(): void
    {
        /** @var array<string, string> $english */
        $english = require __DIR__.'/../src/lang/en.php';

        foreach (glob(__DIR__.'/../src/lang/*.php') as $file) {
            /** @var array<string, string> $translations */
            $translations = require $file;
            $missingKeys = array_keys(array_diff_key($english, $translations));

            $this->assertSame([], $missingKeys, basename($file).' is missing translation keys.');
        }
    }

    public function testAllLanguageFilesUseOakEngineInstallerTitle(): void
    {
        foreach (glob(__DIR__.'/../src/lang/*.php') as $file) {
            /** @var array<string, string> $translations */
            $translations = require $file;

            $this->assertSame('OakEngine Installer', $translations['title'] ?? null, basename($file).' should use the unified installer title.');
        }
    }

    public function testComparePackageVersionsDesc(): void
    {
        $versions = ['1.2.0', '1.10.0', '1.9.0'];
        usort($versions, 'comparePackageVersionsDesc');
        $this->assertSame(['1.10.0', '1.9.0', '1.2.0'], $versions);

        $this->assertLessThan(0, comparePackageVersionsDesc('1.0.0', 'main'));
        $this->assertGreaterThan(0, comparePackageVersionsDesc('main', '1.0.0'));
    }

    public function testRenderInstalledPackageListHtml(): void
    {
        $html = renderInstalledPackageListHtml([
            ['name' => 'example-plugin', 'version' => '1.2.3', 'channel' => 'stable'],
            ['name' => 'second-plugin', 'version' => '2.0.0', 'channel' => 'beta'],
        ], ['none_installed' => 'None installed']);

        $this->assertStringContainsString('example-plugin', $html);
        $this->assertStringContainsString('1.2.3 (stable)', $html);
        $this->assertStringContainsString('second-plugin', $html);
        $this->assertStringContainsString('2.0.0 (beta)', $html);
        $this->assertSame('<em>None installed</em>', renderInstalledPackageListHtml([], ['none_installed' => 'None installed']));
    }

    public function testRenderComposerMetadataSourceListHtml(): void
    {
        $html = renderComposerMetadataSourceListHtml([
            [
                'path' => 'runner/acme/core/admin/composer.json',
                'package_type' => 'runner',
                'metadata' => [],
            ],
            [
                'path' => 'runner/acme/plugin/blog/composer.json',
                'package_type' => 'plugin',
                'metadata' => [],
            ],
        ], [
            'processed_composer_files' => 'Processed composer.json files',
            'and_more' => '... and :count more',
        ]);

        $this->assertStringContainsString('Processed composer.json files', $html);
        $this->assertStringContainsString('runner/acme/core/admin/composer.json', $html);
        $this->assertStringContainsString('runner/acme/plugin/blog/composer.json', $html);
        $this->assertSame('', renderComposerMetadataSourceListHtml([], ['processed_composer_files' => 'x', 'and_more' => 'y']));
    }

    public function testNormalizeRelativePath(): void
    {
        $this->assertSame('index.php', normalizeRelativePath('index.php'));
        $this->assertSame('index.php', normalizeRelativePath('/index.php'));
        $this->assertSame('index.php', normalizeRelativePath('\\index.php'));
        $this->assertSame('lang/de.php', normalizeRelativePath('lang/de.php'));
        $this->assertSame('lang/de.php', normalizeRelativePath('lang\\de.php'));
        $this->assertSame('lang/de.php', normalizeRelativePath('/lang/de.php/'));
    }

    public function testIsAllowedUpdaterFile(): void
    {
        $this->assertTrue(isAllowedUpdaterFile('index.php'));
        $this->assertTrue(isAllowedUpdaterFile('/index.php'));
        $this->assertTrue(isAllowedUpdaterFile('.htaccess'));
        $this->assertTrue(isAllowedUpdaterFile('config.example.php'));
        $this->assertTrue(isAllowedUpdaterFile('lang/de.php'));
        $this->assertTrue(isAllowedUpdaterFile('lang/en.php'));
        $this->assertTrue(isAllowedUpdaterFile('logo/svg/oakengine.svg'));
        $this->assertTrue(isAllowedUpdaterFile('app/GitHubClient.php'));
        $this->assertTrue(isAllowedUpdaterFile('app/HtmlRenderer.php'));
        $this->assertTrue(isAllowedUpdaterFile('app/EnvLocalManager.php'));

        $this->assertFalse(isAllowedUpdaterFile('config.php'));
        $this->assertFalse(isAllowedUpdaterFile('src/index.php'));
        $this->assertFalse(isAllowedUpdaterFile('lang/de.txt'));
        $this->assertFalse(isAllowedUpdaterFile('logo/svg/oakengine.txt'));
        $this->assertFalse(isAllowedUpdaterFile('other/file.php'));
        $this->assertFalse(isAllowedUpdaterFile('app/subdir/DeepFile.php'));
    }

    public function testCanUpdateInstallerToTag(): void
    {
        $this->assertTrue(canUpdateInstallerToTag('unknown', 'v1.0.0'));
        $this->assertTrue(canUpdateInstallerToTag('v1.0.0', 'latest'));
        $this->assertTrue(canUpdateInstallerToTag('v1.0.0', 'v1.1.0'));
        $this->assertTrue(canUpdateInstallerToTag('v1.1.0', 'v1.1.0'));
        $this->assertFalse(canUpdateInstallerToTag('v1.1.0', 'v1.0.0'));
        $this->assertFalse(canUpdateInstallerToTag('v1.2.0', 'v1.1.0'));
    }

    public function testResolveLangKey(): void
    {
        $lang = ['test' => 'Das ist ein Test', 'greet' => 'Hallo :name'];

        $this->assertSame('Das ist ein Test', resolveLangKey('test', $lang));
        $this->assertSame('Hallo Welt', resolveLangKey('greet', $lang, ['name' => 'Welt']));
        $this->assertSame('unknown', resolveLangKey('unknown', $lang));
    }

    public function testResolveInstallerVersion(): void
    {
        $tags = [
            ['name' => 'v1.0.0', 'commit' => 'sha1'],
            ['name' => 'v1.2.0', 'commit' => 'sha2'],
            ['name' => 'v1.1.0', 'commit' => 'sha3'],
        ];

        $this->assertSame('v1.5.0', resolveInstallerVersion(['installer_version' => 'v1.5.0'], $tags));
        $this->assertSame('dev-main', resolveInstallerVersion([], $tags));
        $this->assertSame('dev-main', resolveInstallerVersion([], []));
    }

    public function testGetCachedGitHubRepositoryRefsWritesAndReadsFreshCache(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';
        $expected = [
            'tags' => [['name' => 'v1.2.3', 'commit' => 'tag-sha']],
            'branches' => [['name' => 'main', 'commit' => 'branch-sha']],
        ];

        $firstClient = new FakeGitHubClient('', $expected['tags'], $expected['branches']);
        $this->assertSame($expected, getCachedGitHubRepositoryRefs($firstClient, 'oakengine/installer', $cacheDir, 600));
        $this->assertSame(1, $firstClient->tagRequests);
        $this->assertSame(1, $firstClient->branchRequests);

        $secondClient = new FakeGitHubClient('', [['name' => 'v9.9.9', 'commit' => 'new-tag']], [['name' => 'develop', 'commit' => 'new-branch']]);
        $this->assertSame($expected, getCachedGitHubRepositoryRefs($secondClient, 'oakengine/installer', $cacheDir, 600));
        $this->assertSame(0, $secondClient->tagRequests);
        $this->assertSame(0, $secondClient->branchRequests);
    }

    public function testGetCachedGitHubRepositoryRefsRefreshesExpiredOrInvalidCache(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';
        $repo = 'oakengine/installer';
        $cacheFile = buildGitHubRepositoryRefsCacheFilePath($cacheDir, $repo);
        mkdir($cacheDir, 0o755, true);
        file_put_contents($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn 'invalid';\n");

        $firstExpected = [
            'tags' => [['name' => 'v1.0.0', 'commit' => 'tag-sha-1']],
            'branches' => [['name' => 'main', 'commit' => 'branch-sha-1']],
        ];
        $firstClient = new FakeGitHubClient('', $firstExpected['tags'], $firstExpected['branches']);
        $this->assertSame($firstExpected, getCachedGitHubRepositoryRefs($firstClient, $repo, $cacheDir, 600));
        $this->assertSame(1, $firstClient->tagRequests);
        $this->assertSame(1, $firstClient->branchRequests);

        touch($cacheFile, time() - 601);

        $secondExpected = [
            'tags' => [['name' => 'v2.0.0', 'commit' => 'tag-sha-2']],
            'branches' => [['name' => 'develop', 'commit' => 'branch-sha-2']],
        ];
        $secondClient = new FakeGitHubClient('', $secondExpected['tags'], $secondExpected['branches']);
        $this->assertSame($secondExpected, getCachedGitHubRepositoryRefs($secondClient, $repo, $cacheDir, 600));
        $this->assertSame(1, $secondClient->tagRequests);
        $this->assertSame(1, $secondClient->branchRequests);
    }

    public function testWriteGitHubRepositoryRefsCacheThrowsWhenDirectoryCannotBeCreated(): void
    {
        $path = $this->createTempDirectory().'/blocked';
        file_put_contents($path, 'not-a-directory');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub cache directory cannot be created');

        writeGitHubRepositoryRefsCache($path.'/refs.php', [
            'tags' => [['name' => 'v1.0.0', 'commit' => 'tag-sha']],
            'branches' => [['name' => 'main', 'commit' => 'branch-sha']],
        ]);
    }

    public function testGetCachedGitHubRepositoryRefsReturnsEmptyListsWhenGithubFails(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';
        $client = new FakeGitHubClient('', [], [], true);

        $this->assertSame([
            'tags' => [],
            'branches' => [],
        ], getCachedGitHubRepositoryRefs($client, 'oakengine/installer', $cacheDir, 600));
        $this->assertSame(1, $client->tagRequests);
        $this->assertSame(0, $client->branchRequests);
    }

    public function testGetCachedGitHubRepositoryRefsContinuesWhenCacheWriteFails(): void
    {
        $cacheDir = $this->createTempDirectory().'/blocked';
        file_put_contents($cacheDir, 'not-a-directory');
        $expected = [
            'tags' => [['name' => 'v1.2.3', 'commit' => 'tag-sha']],
            'branches' => [['name' => 'main', 'commit' => 'branch-sha']],
        ];
        $client = new FakeGitHubClient('', $expected['tags'], $expected['branches']);

        $this->assertSame($expected, getCachedGitHubRepositoryRefs($client, 'oakengine/installer', $cacheDir, 600));
        $this->assertSame(1, $client->tagRequests);
        $this->assertSame(1, $client->branchRequests);
    }

    public function testWriteConfigValues(): void
    {
        $configPath = $this->createTempDirectory().'/config.php';
        file_put_contents($configPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['installer_version' => 'v1.0.0'];\n");

        $this->assertTrue(writeConfigValues($configPath, ['installer_version' => 'v1.1.0', 'project_api_url' => 'https://example.com/api']));

        /** @var array<string, mixed> $updatedConfig */
        $updatedConfig = require $configPath;
        $this->assertSame('v1.1.0', $updatedConfig['installer_version']);
        $this->assertSame('https://example.com/api', $updatedConfig['project_api_url']);
        $this->assertFalse(writeConfigValues($configPath.'.missing', ['foo' => 'bar']));
    }

    public function testUpdateUpdaterFromTagCopiesOnlyAllowedFiles(): void
    {
        $destinationDir = $this->createTempDirectory();
        $archiveContent = $this->createZipArchive([
            'src/index.php' => '<?php echo "updated";',
            'src/config.example.php' => '<?php return [];',
            'src/app/GitHubClient.php' => '<?php final class GitHubClient {}',
            'src/lang/en.php' => '<?php return ["title" => "Test"];',
            'src/logo/svg/oakengine.svg' => '<svg></svg>',
            'src/config.php' => '<?php return ["do_not_copy" => true];',
            'src/app/deep/Nested.php' => '<?php echo "skip";',
        ]);

        $result = updateUpdaterFromTag(new FakeGitHubClient($archiveContent), 'oakengine/installer', 'v1.0.0', 'src', $destinationDir);

        $this->assertEqualsCanonicalizing(['index.php', 'config.example.php', 'app/GitHubClient.php', 'lang/en.php', 'logo/svg/oakengine.svg'], $result['updated_files']);
        $this->assertEqualsCanonicalizing(['config.php', 'app/deep/Nested.php'], $result['skipped_files']);
        $this->assertFileExists($destinationDir.'/index.php');
        $this->assertFileExists($destinationDir.'/app/GitHubClient.php');
        $this->assertFileExists($destinationDir.'/logo/svg/oakengine.svg');
        $this->assertFileDoesNotExist($destinationDir.'/config.php');
        $this->assertFileDoesNotExist($destinationDir.'/app/deep/Nested.php');
    }

    public function testClearCacheDirectory(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';
        mkdir($cacheDir, 0o755, true);
        file_put_contents($cacheDir.'/test.txt', 'test');
        mkdir($cacheDir.'/subdir');
        file_put_contents($cacheDir.'/subdir/test2.txt', 'test2');

        $result = clearCacheDirectory($cacheDir);

        $this->assertSame(2, $result['deleted_count']);
        $this->assertEmpty($result['errors']);
        $this->assertDirectoryDoesNotExist($cacheDir);
    }

    public function testClearCacheDirectoryReturnsEmptyResultForMissingDirectory(): void
    {
        $result = clearCacheDirectory($this->createTempDirectory().'/missing');

        $this->assertSame(0, $result['deleted_count']);
        $this->assertSame([], $result['errors']);
    }

    public function testGetDirectorySizeSumsFiles(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir.'/a.txt', 'hello');
        file_put_contents($dir.'/b.txt', 'world!!');
        mkdir($dir.'/sub');
        file_put_contents($dir.'/sub/c.txt', '12345');

        $this->assertSame(17, getDirectorySize($dir));
    }

    public function testGetDirectorySizeReturnsZeroForMissingDirectory(): void
    {
        $this->assertSame(0, getDirectorySize($this->createTempDirectory().'/nope'));
    }

    public function testGetDirectorySizeReturnsAccumulatedSizeWhenIteratorFails(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir.'/a.txt', 'hello');
        chmod($dir, 0o000);
        clearstatcache(true, $dir);
        try {
            $this->assertSame(0, getDirectorySize($dir));
        } finally {
            chmod($dir, 0o755);
        }
    }

    public function testFormatFileSizeFormatsUnits(): void
    {
        $this->assertSame('0 B', formatFileSize(0));
        $this->assertSame('500B', formatFileSize(500));
        $this->assertSame('1KB', formatFileSize(1024));
        $this->assertSame('1.5KB', formatFileSize(1536));
        $this->assertSame('12MB', formatFileSize(12 * 1024 * 1024));
        $this->assertSame('1.2GB', formatFileSize((int) (1.2 * 1024 * 1024 * 1024)));
    }

    public function testCleanTargetDirectoryPreservesCriticalPaths(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/public/update', 0o755, true);
        file_put_contents($targetDir.'/public/update/keep.txt', 'keep');
        file_put_contents($targetDir.'/.env.local', 'APP_ENV=prod');
        file_put_contents($targetDir.'/remove.txt', 'remove');
        mkdir($targetDir.'/runner', 0o755, true);
        file_put_contents($targetDir.'/runner/keep.txt', 'plugins-keep');

        $result = cleanTargetDirectory($targetDir);

        $this->assertSame(1, $result['deleted_count']);
        $this->assertContains('public/update/keep.txt', $result['preserved']);
        $this->assertContains('.env.local', $result['preserved']);
        $this->assertContains('runner/keep.txt', $result['preserved']);
        $this->assertFileExists($targetDir.'/public/update/keep.txt');
        $this->assertFileExists($targetDir.'/.env.local');
        $this->assertFileExists($targetDir.'/runner/keep.txt');
        $this->assertFileDoesNotExist($targetDir.'/remove.txt');
    }

    public function testExtractZipHonorsExcludeRulesOnly(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', 'keep-me');

        $result = extractZip(
            $this->createZipArchive([
                'app/file.txt' => 'copied',
                'docs/readme.md' => 'skip-folder',
                '.env.local' => 'overwrite-attempt',
                'README.md' => 'skip-file',
            ]),
            $targetDir,
            ['docs'],
            ['README.md']
        );

        $this->assertEqualsCanonicalizing(['app/file.txt', '.env.local'], $result['extracted']);
        $this->assertContains('docs', $result['skipped_folders']);
        $this->assertContains('README.md', $result['skipped_files']);
        $this->assertSame('overwrite-attempt', file_get_contents($targetDir.'/.env.local'));
        $this->assertSame('copied', file_get_contents($targetDir.'/app/file.txt'));
    }

    public function testExtractZipCreatesDirectoriesWithExpectedPermissions(): void
    {
        $targetDir = $this->createTempDirectory();

        extractZip(
            $this->createZipArchive([
                'app/file.txt' => 'copied',
            ]),
            $targetDir,
            [],
            []
        );

        $this->assertSame(0o755, fileperms($targetDir.'/app') & 0o777);
    }

    public function testRecursiveDeleteRemovesDirectoryTree(): void
    {
        $directory = $this->createTempDirectory();
        mkdir($directory.'/nested', 0o755, true);
        file_put_contents($directory.'/nested/file.txt', 'content');

        recursiveDelete($directory);

        $this->assertDirectoryDoesNotExist($directory);
        $this->pathsToDelete = array_values(array_filter($this->pathsToDelete, static fn (string $path): bool => $path !== $directory));
    }

    public function testParseEnvLocal(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        $content = <<<ENV
APP_ENV=dev
DATABASE_URL="mysql://user:pass@127.0.0.1/db1" # Database 1
# DATABASE_URL="mysql://user:pass@127.0.0.1/db2" # Database 2
INSTALL_UUID="018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1"
APP_SECRET="690b81936a56c725cedc2a32a67b5b56"
ENV;
        file_put_contents($envPath, $content);

        $result = parseEnvLocal($envPath);

        $this->assertSame('dev', $result['app_env']);
        $this->assertSame('Database 1', $result['current_db']);
        $this->assertCount(2, $result['databases']);
        $this->assertSame('Database 1', $result['databases'][0]['id']);
        $this->assertTrue($result['databases'][0]['active']);
        $this->assertSame('Database 2', $result['databases'][1]['id']);
        $this->assertFalse($result['databases'][1]['active']);
        $this->assertSame('018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1', $result['install_uuid']);
        $this->assertSame('690b81936a56c725cedc2a32a67b5b56', $result['app_secret']);
    }

    public function testUpdateEnvLocalAndSaveEnvLocalContent(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents(
            $envPath,
            "APP_ENV=dev\nDATABASE_URL=\"mysql://user:pass@127.0.0.1/db1\" # DB1\n# DATABASE_URL=\"mysql://user:pass@127.0.0.1/db2\" # DB2\n"
        );

        $this->assertTrue(updateEnvLocal($envPath, 'prod', 'DB2'));
        $updatedContent = (string) file_get_contents($envPath);
        $normalizedContent = str_replace(['# DB1', '# DB2'], ['#DB1', '#DB2'], $updatedContent);
        $this->assertStringContainsString("APP_ENV=prod", $updatedContent);
        $this->assertStringContainsString("#DATABASE_URL=\"mysql://user:pass@127.0.0.1/db1\" #DB1", $normalizedContent);
        $this->assertStringContainsString("DATABASE_URL=\"mysql://user:pass@127.0.0.1/db2\" #DB2", $normalizedContent);

        $this->assertTrue(saveEnvLocalContent($envPath, "LINE1\r\nLINE2\rLINE3"));
        $this->assertSame("LINE1\nLINE2\nLINE3", file_get_contents($envPath));
    }

    public function testSetEnvLocalValueReplacesExistingLine(): void
    {
        $content = "APP_ENV=dev\nAPP_SECRET=old-secret-1234567890\nOTHER=1\n";

        $result = setEnvLocalValue($content, 'APP_ENV', 'prod');

        $this->assertSame("APP_ENV=prod\nAPP_SECRET=old-secret-1234567890\nOTHER=1\n", $result);
    }

    public function testSetEnvLocalValueReplacesCommentedLine(): void
    {
        $content = "#APP_SECRET=old-secret-1234567890\nOTHER=1\n";

        $result = setEnvLocalValue($content, 'APP_SECRET', 'new-secret-1234567890');

        $this->assertSame("APP_SECRET=new-secret-1234567890\nOTHER=1\n", $result);
    }

    public function testSetEnvLocalValueAppendsMissingKey(): void
    {
        $content = "OTHER=1\n";

        $result = setEnvLocalValue($content, 'APP_ENV', 'prod');

        $this->assertSame("OTHER=1\nAPP_ENV=prod\n", $result);
    }

    public function testSetEnvLocalValueAppendsToEmptyContent(): void
    {
        $result = setEnvLocalValue('', 'APP_ENV', 'prod');

        $this->assertSame("APP_ENV=prod\n", $result);
    }

    public function testSetEnvLocalValueNormalizesLineEndings(): void
    {
        $content = "OTHER=1\r\nAPP_ENV=dev\r\n";

        $result = setEnvLocalValue($content, 'APP_ENV', 'prod');

        $this->assertSame("OTHER=1\nAPP_ENV=prod\n", $result);
    }

    public function testUpdateEnvLocalAppendsAppEnvWhenMissing(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "DATABASE_URL=\"mysql://user:pass@127.0.0.1/db1\" # DB1\n");

        $this->assertTrue(updateEnvLocal($envPath, 'prod', 'DB1'));
        $updatedContent = (string) file_get_contents($envPath);
        $this->assertStringContainsString('APP_ENV=prod', $updatedContent);
        $this->assertSame(1, substr_count($updatedContent, 'APP_ENV='));
    }

    public function testUpdateEnvLocalUpdatesCommentedAppEnvLine(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "#APP_ENV=dev\n");

        $this->assertTrue(updateEnvLocal($envPath, 'prod', 'DB1'));
        $updatedContent = (string) file_get_contents($envPath);
        $this->assertStringContainsString('APP_ENV=prod', $updatedContent);
        $this->assertStringNotContainsString('#APP_ENV=', $updatedContent);
    }

    public function testUpdateEnvLocalReturnsFalseForMissingFile(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        $this->assertFalse(updateEnvLocal($envPath, 'prod', 'DB1'));
    }

    public function testAddAndRemoveDatabaseFromEnvLocal(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\nDATABASE_URL=\"mysql://user:pass@127.0.0.1/db1\" # DB1\n");

        $this->assertTrue(addDatabaseToEnvLocal($envPath, 'DB2', 'mysql://user:pass@127.0.0.1/db2'));
        $this->assertFalse(addDatabaseToEnvLocal($envPath, 'DB2', 'mysql://user:pass@127.0.0.1/db2'));
        $this->assertFalse(addDatabaseToEnvLocal($envPath, '', 'mysql://user:pass@127.0.0.1/db3'));
        $this->assertTrue(removeDatabaseFromEnvLocal($envPath, 'DB2'));
        $this->assertFalse(removeDatabaseFromEnvLocal($envPath, 'DB2'));
    }

    public function testUpdateInstallUuidInEnvLocal(): void
    {
        $manager = new InstallUuidManager();
        $envPath = $this->createTempDirectory().'/.env.local';
        $generated = $manager->upsertInstallUuid('', true);
        $replacement = $manager->upsertInstallUuid('', true)['uuid'];

        file_put_contents($envPath, $generated['content']);

        $this->assertTrue(updateInstallUuidInEnvLocal($manager, $envPath, $replacement));
        $this->assertStringContainsString('INSTALL_UUID='.$replacement, (string) file_get_contents($envPath));
    }

    public function testEnsureEnvLocalInstallUuidCreatesDirectoryWithExpectedPermissions(): void
    {
        $manager = new InstallUuidManager();
        $targetDir = $this->createTempDirectory().'/nested/config';
        $envPath = $targetDir.'/.env.local';

        $manager->ensureEnvLocalInstallUuid($envPath);

        $this->assertSame(0o755, fileperms($targetDir) & 0o777);
        $this->assertFalse(updateInstallUuidInEnvLocal($manager, $envPath, 'invalid-uuid'));
    }

    public function testUpdateAppSecretInEnvLocal(): void
    {
        $manager = new AppSecretManager();
        $envPath = $this->createTempDirectory().'/.env.local';
        $generated = $manager->upsertAppSecret('', true);
        $replacement = '690b81936a56c725cedc2a32a67b5b56';

        file_put_contents($envPath, $generated['content']);

        $this->assertTrue(updateAppSecretInEnvLocal($manager, $envPath, $replacement));
        $this->assertStringContainsString('APP_SECRET='.$replacement, (string) file_get_contents($envPath));
    }

    public function testUpdateAppSecretInEnvLocalRejectsInvalidValue(): void
    {
        $manager = new AppSecretManager();
        $envPath = $this->createTempDirectory().'/.env.local';
        $manager->ensureEnvLocalAppSecret($envPath);

        $this->assertFalse(updateAppSecretInEnvLocal($manager, $envPath, 'short'));
        $this->assertFalse(updateAppSecretInEnvLocal($manager, $envPath, 'value with spaces'));
        $this->assertFalse(updateAppSecretInEnvLocal($manager, $envPath, ''));
    }

    public function testEnsureEnvLocalAppSecretCreatesDirectoryWithExpectedPermissions(): void
    {
        $manager = new AppSecretManager();
        $targetDir = $this->createTempDirectory().'/nested/config';
        $envPath = $targetDir.'/.env.local';

        $manager->ensureEnvLocalAppSecret($envPath);

        $this->assertSame(0o755, fileperms($targetDir) & 0o777);
    }

    public function testAppSecretManagerEnsuresPreservesAndRegeneratesSecret(): void
    {
        $manager = new AppSecretManager();
        $envPath = $this->createTempDirectory().'/.env.local';

        $first = $manager->ensureEnvLocalAppSecret($envPath);
        $second = $manager->ensureEnvLocalAppSecret($envPath);
        $third = $manager->ensureEnvLocalAppSecret($envPath, true);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{16,128}$/', $first);
        $this->assertSame($first, $second);
        $this->assertNotSame($second, $third);
        $this->assertStringContainsString('APP_SECRET='.$third, (string) file_get_contents($envPath));
    }

    public function testAppSecretManagerUpsertAppendsToExistingContent(): void
    {
        $manager = new AppSecretManager();
        $existing = "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\n";

        $result = $manager->upsertAppSecret($existing, false);

        $this->assertNotNull($result['secret']);
        $this->assertTrue($result['changed']);
        $this->assertStringContainsString('APP_ENV=prod', $result['content']);
        $this->assertStringContainsString('APP_SECRET='.$result['secret'], $result['content']);
    }

    public function testAppSecretManagerUpsertKeepsExistingSecret(): void
    {
        $manager = new AppSecretManager();
        $existing = "APP_SECRET=my-existing-secret-value\n";

        $result = $manager->upsertAppSecret($existing, false);

        $this->assertSame('my-existing-secret-value', $result['secret']);
        $this->assertFalse($result['changed']);
    }

    public function testAppSecretManagerUpsertRejectsInvalidExistingSecret(): void
    {
        $manager = new AppSecretManager();
        $existing = "APP_SECRET=short\n";

        $result = $manager->upsertAppSecret($existing, false);

        $this->assertNotSame('short', $result['secret']);
        $this->assertTrue($result['changed']);
        $this->assertStringNotContainsString('APP_SECRET=short', $result['content']);
    }

    public function testAppSecretManagerReplacesExistingSecretWhenRequested(): void
    {
        $manager = new AppSecretManager();
        $existing = "APP_SECRET=my-existing-secret-value-12345\n";

        $result = $manager->upsertAppSecret($existing, true);

        $this->assertNotSame('my-existing-secret-value-12345', $result['secret']);
        $this->assertTrue($result['changed']);
        $this->assertStringContainsString('APP_SECRET='.$result['secret'], $result['content']);
        $this->assertStringNotContainsString('APP_SECRET=my-existing-secret-value-12345', $result['content']);
    }

    public function testParseEnvLocalIgnoresInvalidAppSecret(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents(
            $envPath,
            "APP_ENV=prod\nAPP_SECRET=tooshort\nAPP_SECRET=\"value with space\"\n"
        );

        $result = parseEnvLocal($envPath);

        $this->assertNull($result['app_secret']);
    }

    public function testParseEnvLocalReadsAppSecretWithoutQuotes(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents(
            $envPath,
            "APP_SECRET=my-plain-app-secret-12345678\n"
        );

        $result = parseEnvLocal($envPath);

        $this->assertSame('my-plain-app-secret-12345678', $result['app_secret']);
    }

    public function testAppSecretManagerEnsuresAndWritesEnvLocalFile(): void
    {
        $manager = new AppSecretManager();
        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';

        $secret = $manager->ensureEnvLocalAppSecret($envPath);

        $this->assertMatchesRegularExpression('/^[A-Za-z0-9._-]{16,128}$/', $secret);
        $this->assertFileExists($envPath);
        $this->assertStringContainsString('APP_SECRET='.$secret, (string) file_get_contents($envPath));
    }

    public function testAppSecretManagerThrowsWhenDirectoryCannotBeCreated(): void
    {
        $manager = new AppSecretManager();
        $blocker = $this->createTempDirectory().'/blocker';
        file_put_contents($blocker, 'not-a-directory');
        $envPath = $blocker.'/nested/.env.local';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create directory');

        $manager->ensureEnvLocalAppSecret($envPath);
    }

    public function testAppSecretManagerThrowsWhenExistingFileCannotBeRead(): void
    {
        $manager = new AppSecretManager();
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "APP_SECRET=existing-valid-secret-12345\n");
        chmod($envPath, 0o000);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Unable to read');
            $manager->ensureEnvLocalAppSecret($envPath);
        } finally {
            chmod($envPath, 0o644);
        }
    }

    public function testAppSecretManagerThrowsWhenEnvFileCannotBeWritten(): void
    {
        $manager = new AppSecretManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/.env.local', 0o755, true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to write');

        $manager->ensureEnvLocalAppSecret($targetDir.'/.env.local');
    }

    public function testSyncPackageEnvToEnvLocalAppendsOnlyMissingVariables(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\nOAK_DIR=existing\n");

        $result = syncPackageEnvToEnvLocal($envPath, [
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'example',
                        'core-bundle-class' => 'Oak\\Core\\IndexBundle\\IndexBundle',
                        'language-version' => '1',
                        'default-language' => 'en',
                        'available-languages' => 'en|de',
                        'default-language-redirect' => '0',
                    ],
                ],
            ],
        ], 'runner');

        $this->assertTrue($result);
        $content = (string) file_get_contents($envPath);
        $this->assertStringContainsString("APP_ENV=prod\nOAK_DIR=existing\n", $content);
        $this->assertStringContainsString('OAK_CORE_BUNDLE_CLASS=Oak\\Core\\IndexBundle\\IndexBundle', $content);
        $this->assertStringContainsString('OAK_LANGUAGE_VERSION=1', $content);
        $this->assertStringContainsString('OAK_DEFAULT_LANGUAGE=en', $content);
        $this->assertStringContainsString('OAK_AVAILABLE_LANGUAGES=en|de', $content);
        $this->assertStringContainsString('OAK_DEFAULT_LANGUAGE_REDIRECT=0', $content);
        $this->assertSame(1, substr_count($content, 'OAK_DIR=existing'));
        $this->assertSame(0, substr_count($content, 'OAK_DIR=example'));
    }

    public function testSyncPackageEnvToEnvLocalCreatesFileFromRunnerMetadata(): void
    {
        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/nested/.env.local';

        $result = syncPackageEnvToEnvLocal($envPath, [
            'extra' => [
                'oak-engine-runner' => [
                    'env' => [
                        'dir' => 'example',
                        'default-language' => 'en',
                    ],
                ],
            ],
        ], 'runner');

        $this->assertTrue($result);
        $this->assertSame("OAK_DIR=example\nOAK_DEFAULT_LANGUAGE=en\n", file_get_contents($envPath));
    }

    public function testSyncPackageEnvToEnvLocalCreatesFileFromDataPluginMetadata(): void
    {
        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';

        $result = syncPackageEnvToEnvLocal($envPath, [
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'example-data',
                        'default-language' => 'de',
                    ],
                ],
            ],
        ], 'data');

        $this->assertTrue($result);
        $this->assertSame("OAK_DIR=example-data\nOAK_DEFAULT_LANGUAGE=de\n", file_get_contents($envPath));
    }

    public function testSyncPackageEnvToEnvLocalDetailedReportsWrittenAndSkippedVariables(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\nOAK_DIR=existing\n");

        $result = syncPackageEnvToEnvLocalDetailed($envPath, [
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'example',
                        'default-language' => 'en',
                        'available-languages' => 'en|de',
                    ],
                ],
            ],
        ], 'runner');

        $this->assertTrue($result['written']);
        $this->assertSame([
            'OAK_DEFAULT_LANGUAGE=en',
            'OAK_AVAILABLE_LANGUAGES=en|de',
        ], $result['written_lines']);
        $this->assertSame([
            'OAK_DIR=example',
        ], $result['skipped_existing_lines']);
    }

    public function testSyncPackageEnvToEnvLocalReturnsFalseWithoutNewVariables(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "OAK_DIR=example\n# OAK_DEFAULT_LANGUAGE=en\n");

        $this->assertFalse(syncPackageEnvToEnvLocal($envPath, [
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'example',
                        'default-language' => 'en',
                        'language-version' => '',
                        'available-languages' => [],
                    ],
                ],
            ],
        ], 'runner'));

        $this->assertFalse(syncPackageEnvToEnvLocal($envPath, ['extra' => []], 'runner'));
    }

    public function testResolveProjectEnvComposerMetadataSourcesFindsProjectComposerFilesInCoreThenPluginOrder(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/example/core/example/index-bundle', 0o755, true);
        mkdir($targetDir.'/runner/example/plugin/example/teaser-widget', 0o755, true);
        mkdir($targetDir.'/runner/example/plugin/example/no-env', 0o755, true);
        mkdir($targetDir.'/runner/example/invalid/example/index-bundle', 0o755, true);

        file_put_contents($targetDir.'/runner/example/core/example/index-bundle/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'example',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/example/plugin/example/teaser-widget/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'default-language' => 'en',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/example/plugin/example/no-env/composer.json', json_encode([
            'name' => 'oak/empty-plugin',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/example/invalid/example/index-bundle/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'ignored',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            [
                'path' => 'runner/example/core/example/index-bundle/composer.json',
                'package_type' => 'runner',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'dir' => 'example',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'path' => 'runner/example/plugin/example/teaser-widget/composer.json',
                'package_type' => 'plugin',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'default-language' => 'en',
                            ],
                        ],
                    ],
                ],
            ],
        ], resolveProjectEnvComposerMetadataSources($targetDir));
    }

    public function testResolveProjectEnvComposerMetadataSourcesFindsInstalledProjectLayout(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/example/plugin/teaser-widget', 0o755, true);

        file_put_contents($targetDir.'/composer.json', json_encode([
            'extra' => [
                'oak-engine-runner' => [
                    'env' => [
                        'dir' => 'example',
                        'core-bundle-class' => 'Oak\\Core\\IndexBundle\\IndexBundle',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/example/plugin/teaser-widget/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'default-language' => 'en',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            [
                'path' => 'composer.json',
                'package_type' => 'runner',
                'metadata' => [
                    'extra' => [
                        'oak-engine-runner' => [
                            'env' => [
                                'dir' => 'example',
                                'core-bundle-class' => 'Oak\\Core\\IndexBundle\\IndexBundle',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'path' => 'runner/example/plugin/teaser-widget/composer.json',
                'package_type' => 'plugin',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'default-language' => 'en',
                            ],
                        ],
                    ],
                ],
            ],
        ], resolveProjectEnvComposerMetadataSources($targetDir));
    }

    public function testResolveProjectEnvComposerMetadataSourcesFindsFlatPluginCoreLayout(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/core/index-bundle', 0o755, true);
        mkdir($targetDir.'/runner/plugin/teaser-widget', 0o755, true);
        mkdir($targetDir.'/runner/plugin/example/no-env', 0o755, true);
        mkdir($targetDir.'/runner/invalid/example/index-bundle', 0o755, true);

        file_put_contents($targetDir.'/runner/core/index-bundle/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'homanit',
                        'core-bundle-class' => 'Oak\\Core\\IndexBundle\\IndexBundle',
                        'language-version' => '1',
                        'default-language' => 'de',
                        'available-languages' => 'de',
                        'default-language-redirect' => '0',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/plugin/teaser-widget/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'default-language' => 'en',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/plugin/example/no-env/composer.json', json_encode([
            'name' => 'oak/empty-plugin',
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/invalid/example/index-bundle/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'ignored',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            [
                'path' => 'runner/core/index-bundle/composer.json',
                'package_type' => 'runner',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'dir' => 'homanit',
                                'core-bundle-class' => 'Oak\\Core\\IndexBundle\\IndexBundle',
                                'language-version' => '1',
                                'default-language' => 'de',
                                'available-languages' => 'de',
                                'default-language-redirect' => '0',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'path' => 'runner/plugin/teaser-widget/composer.json',
                'package_type' => 'plugin',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'default-language' => 'en',
                            ],
                        ],
                    ],
                ],
            ],
        ], resolveProjectEnvComposerMetadataSources($targetDir));
    }

    public function testSyncPackageEnvComposerMetadataSourcesToEnvLocalDetailedMergesVariables(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';

        $result = syncPackageEnvComposerMetadataSourcesToEnvLocalDetailed($envPath, [
            [
                'path' => 'runner/acme/core/site/composer.json',
                'package_type' => 'runner',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'dir' => 'example',
                                'core-bundle-class' => 'Oak\\Core\\IndexBundle\\IndexBundle',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'path' => 'runner/acme/plugin/blog/composer.json',
                'package_type' => 'plugin',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'default-language' => 'en',
                                'available-languages' => 'en|de',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['written']);
        $this->assertSame([
            'OAK_DIR=example',
            'OAK_CORE_BUNDLE_CLASS=Oak\\Core\\IndexBundle\\IndexBundle',
            'OAK_DEFAULT_LANGUAGE=en',
            'OAK_AVAILABLE_LANGUAGES=en|de',
        ], $result['written_lines']);
        $this->assertSame([], $result['skipped_existing_lines']);
        $this->assertSame(
            "OAK_DIR=example\nOAK_CORE_BUNDLE_CLASS=Oak\\Core\\IndexBundle\\IndexBundle\nOAK_DEFAULT_LANGUAGE=en\nOAK_AVAILABLE_LANGUAGES=en|de\n",
            file_get_contents($envPath),
        );
    }

    public function testSyncPackageEnvComposerMetadataSourcesToEnvLocalDetailedReportsExistingValues(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "OAK_DIR=existing\nOAK_DEFAULT_LANGUAGE=de\n");

        $result = syncPackageEnvComposerMetadataSourcesToEnvLocalDetailed($envPath, [
            [
                'path' => 'runner/acme/core/site/composer.json',
                'package_type' => 'runner',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'dir' => 'example',
                                'core-bundle-class' => 'Oak\\Core\\IndexBundle\\IndexBundle',
                            ],
                        ],
                    ],
                ],
            ],
            [
                'path' => 'runner/acme/plugin/blog/composer.json',
                'package_type' => 'plugin',
                'metadata' => [
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'default-language' => 'en',
                                'available-languages' => 'en|de',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['written']);
        $this->assertSame([
            'OAK_CORE_BUNDLE_CLASS=Oak\\Core\\IndexBundle\\IndexBundle',
            'OAK_AVAILABLE_LANGUAGES=en|de',
        ], $result['written_lines']);
        $this->assertSame([
            'OAK_DIR=example',
            'OAK_DEFAULT_LANGUAGE=en',
        ], $result['skipped_existing_lines']);
    }

    public function testResolvePackageEnvComposerMetadataPrefersNestedComposerWithEnv(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/composer.json', json_encode([
            'extra' => [
                'oak-engine-runner' => [
                    'version' => '1.0.0',
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        mkdir($targetDir.'/runner/acme/core/site', 0o755, true);
        mkdir($targetDir.'/runner/acme/plugin/blog', 0o755, true);
        file_put_contents($targetDir.'/runner/acme/core/site/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'example',
                        'default-language' => 'en',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/acme/plugin/blog/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'default-language' => 'de',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $metadata = resolvePackageEnvComposerMetadata($targetDir, 'runner');

        $this->assertSame([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'example',
                        'default-language' => 'en',
                    ],
                ],
            ],
        ], $metadata);
    }

    public function testResolvePackageEnvComposerMetadataReturnsEmptyArrayWithoutEnvMetadata(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/composer.json', json_encode([
            'name' => 'oak/example',
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([], resolvePackageEnvComposerMetadata($targetDir, 'runner'));
    }

    public function testGetMigrationsStatusReportsMissingConsole(): void
    {
        $status = getMigrationsStatus($this->createTempDirectory());

        $this->assertTrue($status['error']);
        $this->assertSame('bin/console not found', $status['html']);
    }

    public function testGetMigrationsStatusReportsNoMigrations(): void
    {
        $targetDir = $this->createTempDirectory();
        $this->createConsoleScript($targetDir, 'New Migrations: 0');
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';

        $status = getMigrationsStatus($targetDir);

        $this->assertFalse($status['error']);
        $this->assertSame(0, $status['count']);
        $this->assertArrayHasKey('no_migrations', $status);
    }

    public function testGetMigrationsStatusReportsPendingMigrationsAndDatabaseErrors(): void
    {
        $pendingDir = $this->createTempDirectory();
        mkdir($pendingDir.'/migrations', 0o755, true);
        file_put_contents($pendingDir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($pendingDir, 'New Migrations: 3');

        $pending = getMigrationsStatus($pendingDir);
        $this->assertFalse($pending['error']);
        $this->assertSame(3, $pending['count']);
        $this->assertStringContainsString('3 pending', $pending['html']);

        $noDbDir = $this->createTempDirectory();
        mkdir($noDbDir.'/migrations', 0o755, true);
        file_put_contents($noDbDir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($noDbDir, json_encode(['message' => 'could not find driver'], JSON_THROW_ON_ERROR));

        $noDb = getMigrationsStatus($noDbDir);
        $this->assertFalse($noDb['error']);
        $this->assertSame(0, $noDb['count']);
        $this->assertArrayHasKey('no_db', $noDb);
    }

    public function testRenderPageIncludesEnvironmentDashboardContent(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents(
            $envPath,
            "APP_ENV=prod\nDATABASE_URL=\"mysql://user:pass@127.0.0.1/db1\" # DB1\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\n"
        );
        mkdir($targetDir.'/migrations', 0o755, true);
        file_put_contents($targetDir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($targetDir, 'New Migrations: 0');

        $html = renderPage('Installer', '<p>Welcome</p>', 'Boom', $envPath, true, 'install-uuid', '<div class="success">Saved</div>');

        $this->assertStringContainsString('Boom', $html);
        $this->assertStringContainsString('<div class="success">Saved</div>', $html);
        $this->assertStringContainsString('018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1', $html);
        $this->assertStringContainsString('class="env-form env-form--stack"', $html);
        $this->assertStringContainsString('class="env-input env-input--uuid"', $html);
        $this->assertStringContainsString('class="input-group"', $html);
        $this->assertStringContainsString('class="input-group-append"', $html);
        $this->assertStringContainsString('name="regenerate_install_uuid"', $html);
        $this->assertStringContainsString('name="save_install_uuid"', $html);
        $this->assertStringContainsString('<title>Installer · OakEngine Installer</title>', $html);
        $this->assertStringContainsString('oakengine-logo-1', $html);
        $this->assertStringContainsString('<h1>OakEngine Installer</h1>', $html);
        $this->assertStringContainsString('<input type="hidden" name="view" value="install-uuid">', $html);
        $this->assertStringContainsString('dashboard-btn active', $html);
        $this->assertStringContainsString('view=install-uuid', $html);
        $this->assertStringContainsString('Logout', $html);
        $this->assertStringContainsString('id="modal-confirm-action"', $html);
        $this->assertStringNotContainsString('<p>Welcome</p>', $html);
        $this->assertStringNotContainsString('showDashboardSection', $html);
    }

    public function testRenderPageHighlightsHomeViewAndRendersContent(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $html = renderPage('Installer', '<ul class="info-list">HOME</ul>', null, $envPath, false, 'home');

        $this->assertStringContainsString('HOME', $html);
        $this->assertStringContainsString('class="dashboard-nav"', $html);
        $this->assertStringContainsString('dashboard-btn active', $html);
        $this->assertStringContainsString('view=installer', $html);
        $this->assertStringNotContainsString('id="btn-home"', $html);
        $this->assertStringNotContainsString('showDashboardSection', $html);
    }

    public function testRenderPageRendersDatabasesViewSection(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\nDATABASE_URL=\"mysql://user:pass@127.0.0.1/db1\" # DB1\n");

        $html = renderPage('Installer', '<p>ignored</p>', null, $envPath, false, 'databases');

        $this->assertStringNotContainsString('<p>ignored</p>', $html);
        $this->assertStringContainsString('dashboard-btn active', $html);
        $this->assertStringContainsString('view=databases', $html);
        $this->assertStringContainsString('class="env-section-header"', $html);
        $this->assertStringContainsString('class="env-section-title"', $html);
        $this->assertStringContainsString('class="env-divider"', $html);
        $this->assertStringContainsString('class="env-form env-form--stack"', $html);
        $this->assertStringContainsString('class="input-group"', $html);
        $this->assertStringContainsString('input-group-append', $html);
        $this->assertStringContainsString('input-group-append--danger', $html);
        $this->assertStringContainsString('class="env-row env-row--stack env-row--wide"', $html);
        $this->assertStringContainsString('class="env-input env-input--wide"', $html);
        $this->assertStringContainsString('Active Database:', $html);
        $this->assertStringContainsString('Add database', $html);
        $this->assertStringContainsString('Remove database:', $html);
        $this->assertStringContainsString('<input type="hidden" name="view" value="databases">', $html);
        $this->assertStringContainsString('data-confirm-title="Run migrations"', $html);
        $this->assertStringContainsString('data-confirm-message="Are you sure you want to run database migrations?"', $html);
        $this->assertStringContainsString('data-confirm-submit-label="Run migrations"', $html);
        $this->assertStringNotContainsString('confirm(', $html);
    }

    public function testRenderPageDisablesDatabaseActionsWhenNoDatabasesExist(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $html = renderPage('Installer', '<p>ignored</p>', null, $envPath, false, 'databases');

        $this->assertStringContainsString('<span class="dropdown-label">-</span>', $html);
        $this->assertStringContainsString('class="dropdown dropdown-db is-disabled"', $html);
        $this->assertStringContainsString('name="save_env" value="1" class="input-group-append"', $html);
        $this->assertStringContainsString('name="remove_database" value="1" class="input-group-append input-group-append--danger"', $html);
        $this->assertStringContainsString('name="save_env" value="1" class="input-group-append" title="Save" aria-label="Save"disabled', $html);
        $this->assertStringContainsString('name="remove_database" value="1" class="input-group-append input-group-append--danger" title="Remove database" aria-label="Remove database"disabled', $html);
    }

    public function testRenderPageWithoutEnvPathHasNoDashboardNav(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $html = renderPage('Installer', '<p>LoginContent</p>', null, null, false, '');

        $this->assertStringContainsString('LoginContent', $html);
        $this->assertStringContainsString('<h1>OakEngine Installer</h1>', $html);
        $this->assertStringNotContainsString('class="dashboard-nav"', $html);
    }

    public function testRenderPageLangFormPreservesActiveView(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';
        $_GET['view'] = 'environment';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $html = renderPage('Installer', '<p>x</p>', null, $envPath, false, 'environment');

        $this->assertStringContainsString('<input type="hidden" name="view" value="environment">', $html);

        unset($_GET['view']);
    }

    public function testRenderPageEnvironmentViewIncludesAppSecretSection(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents(
            $envPath,
            "APP_ENV=prod\nAPP_SECRET=my-existing-app-secret-12345678\n"
        );

        $html = renderPage('Installer', '<p>x</p>', null, $envPath, false, 'environment');

        $this->assertStringContainsString('env-input--secret', $html);
        $this->assertStringContainsString('name="app_secret" value="my-existing-app-secret-12345678"', $html);
        $this->assertStringContainsString('class="env-textarea"', $html);
        $this->assertStringContainsString('name="env_content"', $html);
        $this->assertStringContainsString('name="save_env_content"', $html);
        $this->assertStringContainsString('class="input-group"', $html);
        $this->assertStringContainsString('class="input-group-append"', $html);
        $this->assertStringContainsString('name="regenerate_app_secret"', $html);
        $this->assertStringNotContainsString('name="save_app_secret"', $html);
        $this->assertStringNotContainsString('view=app-secret', $html);
    }

    public function testRenderPageLangFormPreservesInstallerTab(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';
        $_GET['view'] = 'installer';
        $_GET['itab'] = 'tags';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $html = renderPage('Installer', '<p>x</p>', null, $envPath, false, 'installer');

        $this->assertStringContainsString('<input type="hidden" name="view" value="installer">', $html);
        $this->assertStringContainsString('<input type="hidden" name="itab" value="tags">', $html);

        unset($_GET['view'], $_GET['itab']);
    }

    public function testRenderPageInstallUuidViewMatchesEnvironmentStyle(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents(
            $envPath,
            "APP_ENV=prod\nINSTALL_UUID=019e96ae-46c7-7a9f-b9d4-250a021b5ce4\n"
        );

        $html = renderPage('Installer', '<p>x</p>', null, $envPath, false, 'install-uuid');

        $this->assertStringContainsString('class="env-form env-form--stack"', $html);
        $this->assertStringContainsString('class="input-group"', $html);
        $this->assertStringContainsString('class="input-group-append"', $html);
        $this->assertStringContainsString('name="install_uuid" value="019e96ae-46c7-7a9f-b9d4-250a021b5ce4"', $html);
        $this->assertStringContainsString('name="regenerate_install_uuid"', $html);
        $this->assertStringContainsString('name="save_install_uuid"', $html);
        $this->assertStringNotContainsString('class="env-form env-form--inline"', $html);
    }

    public function testRenderConfirmAttributesEscapesHtml(): void
    {
        $attributes = renderConfirmAttributes('Install', 'Use "dangerous" version?', 'Install');

        $this->assertStringContainsString('data-confirm-title="Install"', $attributes);
        $this->assertStringContainsString('data-confirm-message="Use &quot;dangerous&quot; version?"', $attributes);
        $this->assertStringContainsString('data-confirm-submit-label="Install"', $attributes);
    }

    public function testResolveDashboardViewWhitelistsValues(): void
    {
        $this->assertSame('home', resolveDashboardView(null));
        $this->assertSame('home', resolveDashboardView('bogus'));
        $this->assertSame('home', resolveDashboardView(['array']));
        $this->assertSame('updates', resolveDashboardView('updates'));
        $this->assertSame('environment', resolveDashboardView('environment'));
        $this->assertSame('databases', resolveDashboardView('databases'));
        $this->assertSame('install-uuid', resolveDashboardView('install-uuid'));
        $this->assertSame('installer', resolveDashboardView('installer'));
    }

    public function testResolveInstallerTabDefaultsToBranches(): void
    {
        $this->assertSame('branches', resolveInstallerTab(null));
        $this->assertSame('branches', resolveInstallerTab('branches'));
        $this->assertSame('branches', resolveInstallerTab('bogus'));
        $this->assertSame('tags', resolveInstallerTab('tags'));
    }

    public function testResolveDashboardStatePrefersPostedState(): void
    {
        $this->assertSame(
            ['view' => 'environment', 'itab' => null],
            resolveDashboardState('home', null, 'environment', null)
        );
        $this->assertSame(
            ['view' => 'installer', 'itab' => 'tags'],
            resolveDashboardState('home', 'branches', 'installer', 'tags')
        );
        $this->assertSame(
            ['view' => 'home', 'itab' => null],
            resolveDashboardState('bogus', 'tags', null, null)
        );
    }

    public function testBuildDashboardViewHrefBuildsStableLinks(): void
    {
        $href = buildDashboardViewHref('home');
        $this->assertMatchesRegularExpression('/^\?_t=\d+$/', $href);

        $href = buildDashboardViewHref('environment');
        $this->assertMatchesRegularExpression('/^\?_t=\d+&view=environment$/', $href);

        $href = buildDashboardViewHref('installer', 'tags');
        $this->assertMatchesRegularExpression('/^\?_t=\d+&view=installer&itab=tags$/', $href);

        $href = buildDashboardViewHref('installer');
        $this->assertMatchesRegularExpression('/^\?_t=\d+&view=installer&itab=branches$/', $href);
    }

    public function testRenderDashboardStateInputsOmitsHomeAndPreservesInstallerTab(): void
    {
        $this->assertSame('', renderDashboardStateInputs('home'));
        $this->assertSame(
            '<input type="hidden" name="view" value="environment">',
            renderDashboardStateInputs('environment')
        );
        $this->assertSame(
            '<input type="hidden" name="view" value="installer"><input type="hidden" name="itab" value="tags">',
            renderDashboardStateInputs('installer', 'tags')
        );
    }

    private function createTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/installer_test_'.uniqid('', true);
        if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create temporary test directory.');
        }

        $this->pathsToDelete[] = $directory;

        return $directory;
    }

    /**
     * @param array<string, string> $files
     */
    private function createZipArchive(array $files): string
    {
        $directory = $this->createTempDirectory();
        $zipPath = $directory.'/archive.zip';
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if (true !== $result) {
            throw new RuntimeException('Unable to create ZIP archive for test.');
        }

        foreach ($files as $path => $content) {
            $zip->addFromString('package-root/'.$path, $content);
        }

        $zip->close();

        $zipContent = file_get_contents($zipPath);
        if (false === $zipContent) {
            throw new RuntimeException('Unable to read ZIP archive for test.');
        }

        return $zipContent;
    }

    private function createConsoleScript(string $targetDir, string $output): void
    {
        mkdir($targetDir.'/bin', 0o755, true);
        $script = sprintf(
            "<?php\ndeclare(strict_types=1);\necho %s;\n",
            var_export($output, true)
        );
        file_put_contents($targetDir.'/bin/console', $script);
        chmod($targetDir.'/bin/console', 0o755);
    }

    private function deletePath(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path);
        if (false === $items) {
            return;
        }

        foreach ($items as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }

            $this->deletePath($path.'/'.$item);
        }

        rmdir($path);
    }
}

final class FakeGitHubClient extends \GitHubClient
{
    public int $tagRequests = 0;
    public int $branchRequests = 0;

    /**
     * @param array<int, array{name: string, commit: string}> $tags
     * @param array<int, array{name: string, commit: string}> $branches
     */
    public function __construct(
        private readonly string $archiveContent,
        private readonly array $tags = [],
        private readonly array $branches = [],
        private readonly bool $failTags = false,
    )
    {
        parent::__construct('https://api.github.com', '');
    }

    public function getBranches(string $repo): array
    {
        ++$this->branchRequests;

        return $this->branches;
    }

    public function getTags(string $repo): array
    {
        ++$this->tagRequests;

        if ($this->failTags) {
            throw new RuntimeException('GitHub API Error: HTTP 403');
        }

        return $this->tags;
    }

    public function downloadArchive(string $repo, string $ref, string $refType = 'branch'): string
    {
        return $this->archiveContent;
    }
}
