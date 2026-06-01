<?php

declare(strict_types=1);

namespace Tests;

use Oak\Engine\Installer\InstallUuidManager;
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
        mkdir($targetDir.'/runner/vendor-one/example-plugin', 0o755, true);
        mkdir($targetDir.'/runner/vendor-two/second-plugin', 0o755, true);
        mkdir($targetDir.'/data/acme/example-data', 0o755, true);
        mkdir($targetDir.'/vendor/vendor/package', 0o755, true);

        file_put_contents(
            $targetDir.'/runner/vendor-one/example-plugin/composer.json',
            json_encode([
                'name' => 'acme/example-plugin',
                'extra' => [
                    'oak-engine-plugin' => [
                        'version' => '1.2.3',
                        'channel' => 'stable',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );
        file_put_contents(
            $targetDir.'/runner/vendor-two/second-plugin/composer.json',
            json_encode([
                'name' => 'acme/second-plugin',
                'extra' => [
                    'oak-engine-plugin' => [
                        'version' => '2.0.0',
                        'channel' => 'beta',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );
        file_put_contents(
            $targetDir.'/data/acme/example-data/composer.json',
            json_encode([
                'name' => 'acme/example-data',
                'extra' => [
                    'oak-engine-data' => [
                        'version' => '3.1.0',
                        'channel' => 'stable',
                    ],
                ],
            ], JSON_THROW_ON_ERROR)
        );
        file_put_contents(
            $targetDir.'/vendor/vendor/package/composer.json',
            json_encode([
                'name' => 'vendor/package',
            ], JSON_THROW_ON_ERROR)
        );

        $this->assertSame([
            ['name' => 'example-plugin', 'version' => '1.2.3', 'channel' => 'stable'],
            ['name' => 'second-plugin', 'version' => '2.0.0', 'channel' => 'beta'],
        ], resolveInstalledPackages($targetDir, 'plugin'));
        $this->assertSame([
            ['name' => 'example-data', 'version' => '3.1.0', 'channel' => 'stable'],
        ], resolveInstalledPackages($targetDir, 'data'));
    }

    public function testResolvePackageInstallTargetDir(): void
    {
        $this->assertSame('/var/project', resolvePackageInstallTargetDir('/var/project', 'runner'));
        $this->assertSame('/var/project/runner', resolvePackageInstallTargetDir('/var/project/', 'plugin'));
        $this->assertSame('/var/project/data', resolvePackageInstallTargetDir('/var/project', 'data'));
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

    public function testRenderStatusOverview(): void
    {
        $html = renderStatusOverview([
            ['icon' => 'installer', 'label' => 'Installer Version', 'value' => '<code>1.0.0</code>'],
            ['icon' => 'unknown-icon', 'label' => 'Custom', 'value' => '<em>none</em>'],
        ]);

        $this->assertStringStartsWith('<section class="status-overview">', $html);
        $this->assertStringContainsString('class="status-item"', $html);
        $this->assertStringContainsString('<span class="status-label">Installer Version</span>', $html);
        $this->assertStringContainsString('<div class="status-value"><code>1.0.0</code></div>', $html);
        $this->assertStringContainsString('<svg viewBox="0 0 24 24"', $html);
        $this->assertStringContainsString('<span class="status-icon" aria-hidden="true"></span>', $html);
        $this->assertStringNotContainsString('<select', $html);
    }

    public function testFormatVersionBadgeEscapesValue(): void
    {
        $this->assertSame('<code>1.0.0</code> <span class="status-badge">a&lt;b</span>', formatVersionBadge('1.0.0 (a<b)'));
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
        $this->assertTrue(isAllowedUpdaterFile('app/GitHubClient.php'));
        $this->assertTrue(isAllowedUpdaterFile('app/HtmlRenderer.php'));
        $this->assertTrue(isAllowedUpdaterFile('app/EnvLocalManager.php'));

        $this->assertFalse(isAllowedUpdaterFile('config.php'));
        $this->assertFalse(isAllowedUpdaterFile('src/index.php'));
        $this->assertFalse(isAllowedUpdaterFile('lang/de.txt'));
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
            'src/config.php' => '<?php return ["do_not_copy" => true];',
            'src/app/deep/Nested.php' => '<?php echo "skip";',
        ]);

        $result = updateUpdaterFromTag(new FakeGitHubClient($archiveContent), 'oakengine/installer', 'v1.0.0', 'src', $destinationDir);

        $this->assertEqualsCanonicalizing(['index.php', 'config.example.php', 'app/GitHubClient.php', 'lang/en.php'], $result['updated_files']);
        $this->assertEqualsCanonicalizing(['config.php', 'app/deep/Nested.php'], $result['skipped_files']);
        $this->assertFileExists($destinationDir.'/index.php');
        $this->assertFileExists($destinationDir.'/app/GitHubClient.php');
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

    public function testCleanTargetDirectoryPreservesWhitelistedEntries(): void
    {
        $targetDir = $this->createTempDirectory().'/public';
        mkdir($targetDir.'/update', 0o755, true);
        file_put_contents($targetDir.'/update/keep.txt', 'keep');
        file_put_contents($targetDir.'/.env.local', 'APP_ENV=prod');
        file_put_contents($targetDir.'/remove.txt', 'remove');

        $result = cleanTargetDirectory($targetDir, ['public/update'], ['.env.local']);

        $this->assertSame(1, $result['deleted_count']);
        $this->assertContains('update/keep.txt', $result['preserved']);
        $this->assertContains('.env.local', $result['preserved']);
        $this->assertFileExists($targetDir.'/update/keep.txt');
        $this->assertFileExists($targetDir.'/.env.local');
        $this->assertFileDoesNotExist($targetDir.'/remove.txt');
    }

    public function testExtractZipHonorsExcludeAndWhitelistRules(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', 'keep-me');

        $result = extractZip(
            $this->createZipArchive([
                'app/file.txt' => 'copied',
                'docs/readme.md' => 'skip-folder',
                '.env.local' => 'do-not-overwrite',
                'README.md' => 'skip-file',
            ]),
            $targetDir,
            ['docs'],
            ['README.md'],
            [],
            ['.env.local']
        );

        $this->assertSame(['app/file.txt'], $result['extracted']);
        $this->assertContains('.env.local', $result['skipped_files']);
        $this->assertContains('docs', $result['skipped_folders']);
        $this->assertContains('README.md', $result['skipped_files']);
        $this->assertSame('keep-me', file_get_contents($targetDir.'/.env.local'));
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
            [],
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
        mkdir($targetDir.'/runner/plugin/example/teaser-widget', 0o755, true);

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
        file_put_contents($targetDir.'/runner/plugin/example/teaser-widget/composer.json', json_encode([
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
                'path' => 'runner/plugin/example/teaser-widget/composer.json',
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
        $this->assertStringContainsString('class="env-form env-form--inline"', $html);
        $this->assertStringContainsString('class="env-row env-row--inline env-row--grow"', $html);
        $this->assertStringContainsString('class="env-input env-input--uuid"', $html);
        $this->assertStringContainsString('<input type="hidden" name="view" value="install-uuid">', $html);
        $this->assertStringContainsString('dashboard-btn active" href="?view=install-uuid"', $html);
        $this->assertStringContainsString('Logout', $html);
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

        $html = renderPage('Installer', '<section class="status-overview">HOME</section>', null, $envPath, false, 'home');

        $this->assertStringContainsString('HOME', $html);
        $this->assertStringContainsString('class="dashboard-nav"', $html);
        $this->assertStringContainsString('dashboard-btn active" href="?"', $html);
        $this->assertStringContainsString('href="?view=installer"', $html);
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
        $this->assertStringContainsString('dashboard-btn active" href="?view=databases"', $html);
        $this->assertStringContainsString('class="env-form env-form--stack"', $html);
        $this->assertStringContainsString('class="env-row env-row--stack env-row--wide"', $html);
        $this->assertStringContainsString('class="env-input env-input--wide"', $html);
        $this->assertStringContainsString('<input type="hidden" name="view" value="databases">', $html);
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
        $this->assertStringContainsString('name="save_env" class="btn btn-secondary btn-small" disabled', $html);
        $this->assertStringContainsString('name="remove_database" class="btn btn-small" disabled', $html);
    }

    public function testRenderPageWithoutEnvPathHasNoDashboardNav(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $html = renderPage('Installer', '<p>LoginContent</p>', null, null, false, '');

        $this->assertStringContainsString('LoginContent', $html);
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
        $this->assertSame('?', buildDashboardViewHref('home'));
        $this->assertSame('?view=environment', buildDashboardViewHref('environment'));
        $this->assertSame('?view=installer&itab=tags', buildDashboardViewHref('installer', 'tags'));
        $this->assertSame('?view=installer&itab=branches', buildDashboardViewHref('installer'));
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
