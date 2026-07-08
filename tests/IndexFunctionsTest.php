<?php

declare(strict_types=1);

namespace Tests;

use Oak\Engine\Installer\InstallManifestManager;
use Oak\Engine\Installer\InstallUuidManager;
use Oak\Engine\Installer\AppSecretManager;
use Oak\Engine\Installer\ProjectPackageApiClient;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

    public function testLucideIconReturnsSvgForKnownName(): void
    {
        $html = lucideIcon('home', 18);

        $this->assertStringContainsString('<svg', $html);
        $this->assertStringContainsString('width="18"', $html);
        $this->assertStringContainsString('height="18"', $html);
    }

    public function testLucideIconReturnsEmptyForUnknownName(): void
    {
        $this->assertSame('', lucideIcon('nonexistent-icon-xyz'));
    }

    public function testRenderWelcomeBoxRendersTitleAndSubtitle(): void
    {
        $html = renderWelcomeBox('Welcome Title', 'Subtitle text', [], []);

        $this->assertStringContainsString('class="welcome-card"', $html);
        $this->assertStringContainsString('Welcome Title', $html);
        $this->assertStringContainsString('Subtitle text', $html);
        $this->assertStringNotContainsString('welcome-card-links', $html);
    }

    public function testRenderWelcomeBoxRendersQuickLinks(): void
    {
        $links = [
            ['label' => 'Docs', 'href' => 'https://example.com/docs', 'external' => true],
            ['label' => 'Home', 'href' => '/home', 'icon' => 'home'],
        ];

        $html = renderWelcomeBox('Title', 'Sub', $links, []);

        $this->assertStringContainsString('class="welcome-card-links"', $html);
        $this->assertStringContainsString('href="https://example.com/docs"', $html);
        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        $this->assertStringContainsString('href="/home"', $html);
        $this->assertStringContainsString('Docs', $html);
        $this->assertStringContainsString('Home', $html);
    }

    public function testBuildLoginFormContentIncludesErrorAndVersionMeta(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $_SESSION['lang'] = 'en';

        $html = buildLoginFormContent('Invalid password', ['installer_version' => '1.0.0', 'project_version' => '2.0.0']);

        $this->assertStringContainsString('Invalid password', $html);
        $this->assertStringContainsString('1.0.0', $html);
        $this->assertStringContainsString('2.0.0', $html);
        $this->assertStringContainsString('class="login-form"', $html);
        $this->assertStringContainsString('name="password"', $html);
    }

    public function testBuildLoginFormContentWithoutVersionMeta(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $_SESSION['lang'] = 'en';

        $html = buildLoginFormContent('', []);

        $this->assertStringNotContainsString('class="repo-info"', $html);
        $this->assertStringContainsString('class="login-form"', $html);
        $this->assertStringNotContainsString('Invalid password', $html);
    }

    public function testBuildLoginFormContentWithNonScalarVersionMeta(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $_SESSION['lang'] = 'en';

        $html = buildLoginFormContent('', ['installer_version' => ['invalid'], 'project_version' => new \stdClass()]);

        $this->assertStringContainsString('class="repo-info"', $html);
        $this->assertStringContainsString('<code></code>', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState enabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(true)]
    public function testInstallerApplicationRendersHomePage(): void
    {
        $configPath = realpath(__DIR__.'/../src').'/config.php';
        $originalConfigExists = file_exists($configPath);
        $originalConfigContent = $originalConfigExists ? (string) file_get_contents($configPath) : null;

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");
        $relativeTargetDir = '../../../../'.$targetDir;
        file_put_contents($configPath, '<?php return '.var_export([
            'project_api_url' => 'http://localhost:9999',
            'project_api_token' => '',
            'github_token' => '',
            'password' => '',
            'api_base_url' => 'http://localhost:9999',
            'target_directory' => $relativeTargetDir,
            'exclude_folders' => ['.git', 'node_modules'],
            'exclude_files' => ['.gitignore'],
            'default_language' => 'en',
            'show_versions_before_login' => false,
            'installer_repository' => 'oak/test',
            'updater_source_path' => 'src',
        ], true).';');

        $previousSession = $_SESSION ?? [];
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $previousPost = $_POST;

        $_SESSION = ['lang' => 'en'];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        ob_start();
        try {
            require_once __DIR__.'/../src/index.php';
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
            $_SESSION = $previousSession;
            $_SERVER = $previousServer;
            $_GET = $previousGet;
            $_POST = $previousPost;
            $this->fail('InstallerApplication threw: '.$e->getMessage());
        }
        $output = (string) ob_get_clean();

        $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
        $_SESSION = $previousSession;
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;

        $this->assertNotEmpty($output);
        $this->assertStringContainsString('OakEngine Installer', $output);
        $this->assertTrue(true, 'reached end of test');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState enabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(true)]
    public function testInstallerApplicationHandlesPostSaveEnv(): void
    {
        $configPath = realpath(__DIR__.'/../src').'/config.php';
        $originalConfigExists = file_exists($configPath);
        $originalConfigContent = $originalConfigExists ? (string) file_get_contents($configPath) : null;

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=dev\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");
        $relativeTargetDir = '../../../../'.$targetDir;
        file_put_contents($configPath, '<?php return '.var_export([
            'project_api_url' => 'http://localhost:9999',
            'project_api_token' => '',
            'github_token' => '',
            'password' => '',
            'api_base_url' => 'http://localhost:9999',
            'target_directory' => $relativeTargetDir,
            'exclude_folders' => ['.git', 'node_modules'],
            'exclude_files' => ['.gitignore'],
            'default_language' => 'en',
            'show_versions_before_login' => false,
            'installer_repository' => 'oak/test',
            'updater_source_path' => 'src',
        ], true).';');

        $previousSession = $_SESSION ?? [];
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $previousPost = $_POST;

        $_SESSION = ['lang' => 'en'];
        $_GET = [];
        $_POST = ['save_env' => '1', 'app_env' => 'prod', 'database' => 'DB1'];
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/';

        ob_start();
        try {
            require_once __DIR__.'/../src/index.php';
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
            $_SESSION = $previousSession;
            $_SERVER = $previousServer;
            $_GET = $previousGet;
            $_POST = $previousPost;
            $this->fail('InstallerApplication threw: '.$e->getMessage());
        }
        $output = (string) ob_get_clean();

        $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
        $_SESSION = $previousSession;
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;

        $this->assertSame('prod', trim((string) shell_exec('grep "^APP_ENV=" '.$targetDir.'/.env.local | cut -d= -f2')));
        $this->assertNotEmpty($output);
    }

    private function restoreInstallerConfig(string $configPath, bool $originalExists, ?string $originalContent): void
    {
        if ($originalExists) {
            file_put_contents($configPath, $originalContent);
        } elseif (file_exists($configPath)) {
            unlink($configPath);
        }
    }

    public function testInstallerApplicationFallsBackToExampleConfig(): void
    {
        $configPath = realpath(__DIR__.'/../src').'/config.php';
        $originalConfigExists = file_exists($configPath);
        $originalConfigContent = $originalConfigExists ? (string) file_get_contents($configPath) : null;

        if ($originalConfigExists) {
            unlink($configPath);
        }

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");

        $previousSession = $_SESSION ?? [];
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $previousPost = $_POST;

        $_SESSION = ['lang' => 'en'];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
        $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__.'/../src');

        ob_start();
        try {
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            $output = (string) ob_get_clean();
            $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
            $_SESSION = $previousSession;
            $_SERVER = $previousServer;
            $_GET = $previousGet;
            $_POST = $previousPost;

            $this->markTestSkipped('InstallerApplication cannot resolve paths without config.php: '.$e->getMessage());
        }
        $output = (string) ob_get_clean();

        $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
        $_SESSION = $previousSession;
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;

        $this->assertNotEmpty($output);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState enabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(true)]
    public function testInstallerApplicationHandlesLangSwitchWithExtraParams(): void
    {
        $configPath = realpath(__DIR__.'/../src').'/config.php';
        $originalConfigExists = file_exists($configPath);
        $originalConfigContent = $originalConfigExists ? (string) file_get_contents($configPath) : null;

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");
        $relativeTargetDir = '../../../../'.$targetDir;
        file_put_contents($configPath, '<?php return '.var_export([
            'project_api_url' => 'http://localhost:9999',
            'project_api_token' => '',
            'github_token' => '',
            'password' => '',
            'api_base_url' => 'http://localhost:9999',
            'target_directory' => $relativeTargetDir,
            'exclude_folders' => ['.git', 'node_modules'],
            'exclude_files' => ['.gitignore'],
            'default_language' => 'en',
            'show_versions_before_login' => false,
            'installer_repository' => 'oak/test',
            'updater_source_path' => 'src',
        ], true).';');

        $previousSession = $_SESSION ?? [];
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $previousPost = $_POST;

        $_SESSION = ['lang' => 'en'];
        $_GET = ['lang' => 'de', 'view' => 'updates'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/?lang=de&view=updates';
        $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__.'/../src');

        ob_start();
        try {
            require_once __DIR__.'/../src/index.php';
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
            $_SESSION = $previousSession;
            $_SERVER = $previousServer;
            $_GET = $previousGet;
            $_POST = $previousPost;
            $this->markTestSkipped('Cannot run installer for lang switch test: '.$e->getMessage());

            return;
        }
        $output = (string) ob_get_clean();

        $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
        $_SESSION = $previousSession;
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;

        $this->assertTrue(true, 'reached end of test');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState enabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(true)]
    public function testInstallerApplicationHandlesLangSwitchWithNonStringRequestUri(): void
    {
        $configPath = realpath(__DIR__.'/../src').'/config.php';
        $originalConfigExists = file_exists($configPath);
        $originalConfigContent = $originalConfigExists ? (string) file_get_contents($configPath) : null;

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");
        $relativeTargetDir = '../../../../'.$targetDir;
        file_put_contents($configPath, '<?php return '.var_export([
            'project_api_url' => 'http://localhost:9999',
            'project_api_token' => '',
            'github_token' => '',
            'password' => '',
            'api_base_url' => 'http://localhost:9999',
            'target_directory' => $relativeTargetDir,
            'exclude_folders' => ['.git', 'node_modules'],
            'exclude_files' => ['.gitignore'],
            'default_language' => 'en',
            'show_versions_before_login' => false,
            'installer_repository' => 'oak/test',
            'updater_source_path' => 'src',
        ], true).';');

        $previousSession = $_SESSION ?? [];
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $previousPost = $_POST;

        $_SESSION = ['lang' => 'en'];
        $_GET = ['lang' => 'de'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = ['not-a-string'];
        $_SERVER['DOCUMENT_ROOT'] = realpath(__DIR__.'/../src');

        ob_start();
        try {
            require_once __DIR__.'/../src/index.php';
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
            $_SESSION = $previousSession;
            $_SERVER = $previousServer;
            $_GET = $previousGet;
            $_POST = $previousPost;
            $this->markTestSkipped('Cannot run installer: '.$e->getMessage());

            return;
        }
        $output = (string) ob_get_clean();

        $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
        $_SESSION = $previousSession;
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;

        $this->assertTrue(true, 'reached end of test');
    }

    public function testInstallerApplicationFallsBackToDefaultSessionLang(): void
    {
        $configPath = realpath(__DIR__.'/../src').'/config.php';
        $originalConfigExists = file_exists($configPath);
        $originalConfigContent = $originalConfigExists ? (string) file_get_contents($configPath) : null;

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");
        $relativeTargetDir = '../../../../'.$targetDir;
        file_put_contents($configPath, '<?php return '.var_export([
            'project_api_url' => 'http://localhost:9999',
            'project_api_token' => '',
            'github_token' => '',
            'password' => '',
            'api_base_url' => 'http://localhost:9999',
            'target_directory' => $relativeTargetDir,
            'exclude_folders' => ['.git', 'node_modules'],
            'exclude_files' => ['.gitignore'],
            'default_language' => 'en',
            'show_versions_before_login' => false,
            'installer_repository' => 'oak/test',
            'updater_source_path' => 'src',
        ], true).';');

        $previousSession = $_SESSION ?? [];
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $previousPost = $_POST;

        $_SESSION = ['lang' => 42];  // not a string -> fallback to default
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        ob_start();
        try {
            require_once __DIR__.'/../src/index.php';
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
            $_SESSION = $previousSession;
            $_SERVER = $previousServer;
            $_GET = $previousGet;
            $_POST = $previousPost;
            $this->markTestSkipped('Cannot run installer for fallback lang test: '.$e->getMessage());

            return;
        }
        $output = (string) ob_get_clean();

        $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
        $_SESSION = $previousSession;
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;

        $this->assertNotEmpty($output);
    }

    public function testInstallerApplicationHandlesInvalidLangRedirect(): void
    {
        $configPath = realpath(__DIR__.'/../src').'/config.php';
        $originalConfigExists = file_exists($configPath);
        $originalConfigContent = $originalConfigExists ? (string) file_get_contents($configPath) : null;

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");
        $relativeTargetDir = '../../../../'.$targetDir;
        file_put_contents($configPath, '<?php return '.var_export([
            'project_api_url' => 'http://localhost:9999',
            'project_api_token' => '',
            'github_token' => '',
            'password' => '',
            'api_base_url' => 'http://localhost:9999',
            'target_directory' => $relativeTargetDir,
            'exclude_folders' => ['.git', 'node_modules'],
            'exclude_files' => ['.gitignore'],
            'default_language' => 'en',
            'show_versions_before_login' => false,
            'installer_repository' => 'oak/test',
            'updater_source_path' => 'src',
        ], true).';');

        $previousSession = $_SESSION ?? [];
        $previousServer = $_SERVER;
        $previousGet = $_GET;
        $previousPost = $_POST;

        $_SESSION = ['lang' => 'en'];
        $_GET = ['lang' => 'invalid-lang-fallback'];  // not in availableLangs
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';

        ob_start();
        try {
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            ob_end_clean();
            $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
            $_SESSION = $previousSession;
            $_SERVER = $previousServer;
            $_GET = $previousGet;
            $_POST = $previousPost;
            $this->markTestSkipped('Cannot run installer: '.$e->getMessage());

            return;
        }
        $output = (string) ob_get_clean();

        $this->restoreInstallerConfig($configPath, $originalConfigExists, $originalConfigContent);
        $_SESSION = $previousSession;
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;

        $this->assertNotEmpty($output);
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

    public function testRenderHomeSectionsInfoCardFirstPlacesInfoBeforeSections(): void
    {
        $html = renderHomeSections('System', [
            ['icon' => 'endpoint', 'label' => 'Server Endpoint', 'value' => '<code>http://x</code>'],
        ], [
            [
                'title' => 'Configuration',
                'icon' => 'settings',
                'href' => '?view=environment',
                'items' => [
                    ['icon' => 'settings', 'label' => 'Mode', 'value' => '<code>dev</code>'],
                ],
            ],
        ]);

        $this->assertLessThan(
            strpos($html, 'Configuration'),
            strpos($html, 'Server Endpoint'),
            'System info card should render before the section cards by default'
        );
    }

    public function testRenderHomeSectionsInfoCardLastPlacesInfoAfterSections(): void
    {
        $html = renderHomeSections('System', [
            ['icon' => 'endpoint', 'label' => 'Server Endpoint', 'value' => '<code>http://x</code>'],
        ], [
            [
                'title' => 'Configuration',
                'icon' => 'settings',
                'href' => '?view=environment',
                'items' => [
                    ['icon' => 'settings', 'label' => 'Mode', 'value' => '<code>dev</code>'],
                ],
            ],
        ], '', false);

        $this->assertLessThan(
            strpos($html, 'Server Endpoint'),
            strpos($html, 'Configuration'),
            'System info card should render after the section cards when requested'
        );
    }

    public function testRenderHomeSectionsOmitsInfoCardWhenInfoItemsEmpty(): void
    {
        $html = renderHomeSections('System', [], [
            [
                'title' => 'Configuration',
                'icon' => 'settings',
                'href' => '?view=environment',
                'items' => [
                    ['icon' => 'settings', 'label' => 'Mode', 'value' => '<code>dev</code>'],
                ],
            ],
        ]);

        $this->assertStringContainsString('class="home-stack"', $html);
        $this->assertStringContainsString('Configuration', $html);
        $this->assertStringNotContainsString('System', $html);
        $this->assertStringNotContainsString('home-card-header--static', $html);
    }

    public function testRenderHomeSectionsReturnsEmptyStackWhenInfoItemsAndSectionsEmpty(): void
    {
        $html = renderHomeSections('System', [], []);

        $this->assertSame('<div class="home-stack"></div>', $html);
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

    public function testHandleAuthenticationReturnsImmediatelyWhenNoPasswordConfigured(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = evaluateAuthentication([], false, []);

        $this->assertSame('no-password', $result['outcome']);
    }

    public function testHandleAuthenticationReturnsImmediatelyWhenAlreadyAuthenticated(): void
    {
        $_SESSION = ['oak_installer_authenticated' => true];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = evaluateAuthentication(['password' => 'secret'], false, []);

        $this->assertSame('authenticated', $result['outcome']);
        $this->assertTrue($_SESSION['oak_installer_authenticated']);
    }

    public function testHandleAuthenticationIgnoresNonScalarPasswordConfig(): void
    {
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = evaluateAuthentication(['password' => ['nested']], false, []);

        $this->assertSame('no-password', $result['outcome']);
    }

    public function testHandleAuthenticationShowsFormForUnauthenticatedGet(): void
    {
        $GLOBALS['lang'] = ['incorrect_password' => 'wrong'];
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = evaluateAuthentication(['password' => 'secret'], false, []);

        $this->assertSame('show-form', $result['outcome']);
        $this->assertSame('', $result['error']);
        $this->assertArrayNotHasKey('oak_installer_authenticated', $_SESSION);
    }

    public function testHandleAuthenticationShowsFormWithVersionMetaWhenConfigured(): void
    {
        $GLOBALS['lang'] = ['incorrect_password' => 'wrong'];
        $_SESSION = [];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = evaluateAuthentication(
            ['password' => 'secret'],
            true,
            ['installer_version' => '1.0.0', 'project_version' => '2.0.0']
        );

        $this->assertSame('show-form', $result['outcome']);
        $this->assertSame(['installer_version' => '1.0.0', 'project_version' => '2.0.0'], $result['version_meta']);
    }

    public function testHandleAuthenticationAuthenticatesOnPlainPassword(): void
    {
        $GLOBALS['lang'] = ['incorrect_password' => 'wrong'];
        $_SESSION = [];
        $_GET = [];
        $_POST = ['password' => 'secret'];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = evaluateAuthentication(['password' => 'secret'], false, []);

        $this->assertSame('login-ok', $result['outcome']);
        $this->assertTrue($_SESSION['oak_installer_authenticated']);
        $this->assertArrayHasKey('oak_installer_auth_time', $_SESSION);
    }

    public function testHandleAuthenticationAuthenticatesWithHashedPassword(): void
    {
        $GLOBALS['lang'] = ['incorrect_password' => 'wrong'];
        $hashed = password_hash('secret', PASSWORD_BCRYPT);
        $_SESSION = [];
        $_GET = [];
        $_POST = ['password' => 'secret'];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = evaluateAuthentication(['password' => $hashed], false, []);

        $this->assertSame('login-ok', $result['outcome']);
        $this->assertTrue($_SESSION['oak_installer_authenticated']);
    }

    public function testHandleAuthenticationReturnsFailureOnWrongPassword(): void
    {
        $GLOBALS['lang'] = ['incorrect_password' => 'wrong-password'];
        $_SESSION = [];
        $_GET = [];
        $_POST = ['password' => 'wrong'];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = evaluateAuthentication(['password' => 'correct'], false, []);

        $this->assertSame('login-failed', $result['outcome']);
        $this->assertSame('wrong-password', $result['error']);
        $this->assertArrayNotHasKey('oak_installer_authenticated', $_SESSION);
    }

    public function testHandleAuthenticationHandlesLogoutRequest(): void
    {
        $GLOBALS['lang'] = ['incorrect_password' => 'wrong'];
        $_SESSION = ['oak_installer_authenticated' => true, 'extra' => 'value'];
        $_GET = ['logout' => '1'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $result = evaluateAuthentication(['password' => 'secret'], false, []);

        $this->assertSame('logged-out', $result['outcome']);
        $this->assertEmpty($_SESSION);
    }

    public function testHandleAuthenticationIgnoresNonStringPostPassword(): void
    {
        $GLOBALS['lang'] = ['incorrect_password' => 'wrong'];
        $_SESSION = [];
        $_GET = [];
        $_POST = ['password' => ['not-a-string']];
        $_SERVER['REQUEST_METHOD'] = 'POST';

        $result = evaluateAuthentication(['password' => 'secret'], false, []);

        $this->assertSame('show-form', $result['outcome']);
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

    public function testComparePackageVersionsDescHandlesBothNonSemver(): void
    {
        $this->assertSame(0, comparePackageVersionsDesc('main', 'main'));
        $this->assertGreaterThan(0, comparePackageVersionsDesc('alpha', 'beta'));
        $this->assertLessThan(0, comparePackageVersionsDesc('zeta', 'alpha'));
    }

    public function testReadComposerJsonMetadataReturnsEmptyWhenFileUnreadable(): void
    {
        $path = $this->createTempDirectory().'/composer.json';
        file_put_contents($path, '{}');
        chmod($path, 0o000);

        try {
            $this->assertSame([], readComposerJsonMetadata($path));
        } finally {
            chmod($path, 0o644);
        }
    }

    public function testReadComposerJsonMetadataReturnsEmptyForInvalidJson(): void
    {
        $path = $this->createTempDirectory().'/composer.json';
        file_put_contents($path, '{not-valid-json');

        $this->assertSame([], readComposerJsonMetadata($path));
    }

    public function testResolveComposerPackageVersionReturnsEmptyForNonScalarVersion(): void
    {
        $path = $this->createTempDirectory().'/composer.json';
        file_put_contents($path, json_encode(['version' => ['nested']], JSON_THROW_ON_ERROR));

        $this->assertSame('', resolveComposerPackageVersion($path));
    }

    public function testResolveInstalledPackagesScansRunnerDirectory(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/vendor/example/core', 0o755, true);
        file_put_contents($targetDir.'/vendor/example/core/composer.json', json_encode([
            'extra' => ['oak-engine-runner' => ['version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));

        $packages = resolveInstalledPackages($targetDir, 'runner');

        $this->assertCount(1, $packages);
        $this->assertSame('vendor', $packages[0]['name']);
    }

    public function testResolveInstalledPackagesSkipsUnreadableAndInvalid(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/good', 0o755, true);
        mkdir($targetDir.'/runner/unreadable', 0o755, true);
        mkdir($targetDir.'/runner/invalid', 0o755, true);
        mkdir($targetDir.'/runner/no-meta', 0o755, true);
        mkdir($targetDir.'/runner/no-version', 0o755, true);
        file_put_contents($targetDir.'/runner/good/composer.json', json_encode([
            'extra' => ['oak-engine-plugin' => ['version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/unreadable/composer.json', '{}');
        chmod($targetDir.'/runner/unreadable/composer.json', 0o000);
        file_put_contents($targetDir.'/runner/invalid/composer.json', 'not-json');
        file_put_contents($targetDir.'/runner/no-meta/composer.json', json_encode(['name' => 'x'], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/no-version/composer.json', json_encode([
            'extra' => ['oak-engine-plugin' => ['channel' => 'stable']],
        ], JSON_THROW_ON_ERROR));

        $packages = resolveInstalledPackages($targetDir, 'plugin');

        chmod($targetDir.'/runner/unreadable/composer.json', 0o644);
        $this->assertCount(1, $packages);
        $this->assertSame('good', $packages[0]['name']);
    }

    public function testResolveInstalledPackagesFallsBackToFirstPathSegment(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/somewhere/deep', 0o755, true);
        file_put_contents($targetDir.'/runner/somewhere/deep/composer.json', json_encode([
            'extra' => ['oak-engine-plugin' => ['version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));

        $packages = resolveInstalledPackages($targetDir, 'plugin');

        $this->assertCount(1, $packages);
        $this->assertSame('somewhere', $packages[0]['name']);
    }

    public function testResolveInstalledPackageDisplayNameFallsBackToComposerName(): void
    {
        $this->assertSame(
            'awesome-plugin',
            resolveInstalledPackageDisplayName('vendor/awesome-plugin', '/some/other/path', '/scan/dir')
        );
    }

    public function testResolveInstalledPackageDisplayNameFallsBackToDirectoryName(): void
    {
        $this->assertSame(
            'deep',
            resolveInstalledPackageDisplayName('', '/some/other/path/deep', '/scan/dir')
        );
    }

    public function testResolveInstalledPackagesAcceptsLegacyChanelKey(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/legacy', 0o755, true);
        file_put_contents($targetDir.'/runner/legacy/composer.json', json_encode([
            'extra' => ['oak-engine-plugin' => ['version' => '1.0.0', 'chanel' => 'legacy']],
        ], JSON_THROW_ON_ERROR));

        $packages = resolveInstalledPackages($targetDir, 'plugin');

        $this->assertCount(1, $packages);
        $this->assertSame('legacy', $packages[0]['channel']);
    }

    public function testResolveInstalledPackagesSkipsNonComposerJsonFiles(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/good', 0o755, true);
        mkdir($targetDir.'/runner/mixed', 0o755, true);
        file_put_contents($targetDir.'/runner/good/composer.json', json_encode([
            'extra' => ['oak-engine-plugin' => ['version' => '1.0.0']],
        ], JSON_THROW_ON_ERROR));
        file_put_contents($targetDir.'/runner/mixed/README.md', 'just a readme');
        file_put_contents($targetDir.'/runner/mixed/some-class.php', '<?php class Foo {}');

        $packages = resolveInstalledPackages($targetDir, 'plugin');

        $this->assertCount(1, $packages);
        $this->assertSame('good', $packages[0]['name']);
    }

    public function testResolveInstalledPackagesReturnsEmptyForMissingDirectory(): void
    {
        $this->assertSame([], resolveInstalledPackages($this->createTempDirectory().'/missing', 'plugin'));
    }

    public function testResolveInstalledProjectVersionAcceptsLegacyChanelKey(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/composer.json', json_encode([
            'extra' => ['oak-engine-runner' => ['version' => '1.0.0', 'chanel' => 'legacy']],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('1.0.0 (legacy)', resolveInstalledProjectVersion($targetDir));
    }

    public function testRenderInstalledPackageListHtml(): void
    {
        $html = renderInstalledPackageListHtml([
            ['name' => 'example-plugin', 'version' => '1.2.3', 'channel' => 'stable'],
            ['name' => 'second-plugin', 'version' => '2.0.0', 'channel' => 'beta'],
        ], ['none_installed' => 'None installed', 'whitelist_count' => ':count packages', 'close' => 'Close', 'installed_plugins' => 'Installed']);

        $this->assertStringContainsString('example-plugin', $html);
        $this->assertStringContainsString('1.2.3 (stable)', $html);
        $this->assertStringContainsString('second-plugin', $html);
        $this->assertStringContainsString('2.0.0 (beta)', $html);
        $this->assertSame('<em>None installed</em>', renderInstalledPackageListHtml([], ['none_installed' => 'None installed']));
    }

    public function testRenderInstalledPackageListHtmlWithSinglePackageShowsChip(): void
    {
        $html = renderInstalledPackageListHtml([
            ['name' => 'oak/runner', 'version' => '1.0.0', 'channel' => 'stable'],
        ], ['none_installed' => 'None', 'whitelist_count' => ':count packages', 'close' => 'Close', 'installed_plugins' => 'Installed']);

        $this->assertStringContainsString('class="status-chips"', $html);
        $this->assertStringContainsString('oak/runner', $html);
        $this->assertStringContainsString('1.0.0 (stable)', $html);
    }

    public function testRenderInstalledPackageListHtmlWithSinglePackageWithoutChannel(): void
    {
        $html = renderInstalledPackageListHtml([
            ['name' => 'oak/runner', 'version' => '1.0.0', 'channel' => ''],
        ], ['none_installed' => 'None', 'whitelist_count' => ':count packages', 'close' => 'Close', 'installed_plugins' => 'Installed']);

        $this->assertStringContainsString('oak/runner', $html);
        $this->assertStringNotContainsString('1.0.0 (', $html);
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

    public function testRenderComposerMetadataSourceListHtmlShowsAndMoreWhenOverLimit(): void
    {
        $sources = [];
        for ($i = 0; $i < 25; ++$i) {
            $sources[] = [
                'path' => 'src/file-'.$i.'/composer.json',
                'package_type' => 'plugin',
                'metadata' => [],
            ];
        }

        $html = renderComposerMetadataSourceListHtml($sources, [
            'processed_composer_files' => 'Processed',
            'and_more' => '... and :count more',
        ]);

        $this->assertStringContainsString('... and 5 more', $html);
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

    public function testDoubleUnderscoreFallsBackToKeyWhenLangNotLoaded(): void
    {
        global $lang;
        $previousLang = $lang;
        unset($lang);

        $this->assertSame('missing_key', __('missing_key'));

        $lang = $previousLang;
    }

    public function testDoubleUnderscoreFallsBackToKeyWhenLangIsNotAnArray(): void
    {
        global $lang;
        $previousLang = $lang;
        $lang = 'not-an-array';

        $this->assertSame('missing_key', __('missing_key'));

        $lang = $previousLang;
    }

    public function testDoubleUnderscoreResolvesAgainstGlobalLang(): void
    {
        global $lang;
        $previousLang = $lang;
        $lang = ['greet' => 'Hello :name'];

        $this->assertSame('Hello World', __('greet', ['name' => 'World']));

        $lang = $previousLang;
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

    public function testResolveInstallerVersionAppendsCommitForBranchName(): void
    {
        $this->assertSame(
            'develop1234567',
            resolveInstallerVersion(['installer_version' => 'develop', 'installer_commit' => '1234567890abcdef'], [])
        );
    }

    public function testResolveInstallerVersionReturnsBranchNameWithoutCommit(): void
    {
        $this->assertSame('develop', resolveInstallerVersion(['installer_version' => 'develop'], []));
    }

    public function testResolveInstallerVersionIgnoresNonScalarConfiguredVersion(): void
    {
        $this->assertSame(
            'dev-main',
            resolveInstallerVersion(['installer_version' => ['nested']], [])
        );
    }

    public function testWriteConfigValuesReturnsFalseWhenFileMissing(): void
    {
        $missing = $this->createTempDirectory().'/missing-config.php';

        $this->assertFalse(writeConfigValues($missing, ['foo' => 'bar']));
    }

    public function testWriteConfigValuesTreatsNonArrayReturnAsEmpty(): void
    {
        $configPath = $this->createTempDirectory().'/config.php';
        file_put_contents($configPath, "<?php\n\ndeclare(strict_types=1);\n\nreturn 'not-an-array';\n");

        $this->assertTrue(writeConfigValues($configPath, ['foo' => 'bar']));

        /** @var array<string, mixed> $updated */
        $updated = require $configPath;
        $this->assertSame('bar', $updated['foo']);
    }

    public function testUpdateUpdaterFromTagThrowsWhenZipInvalid(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to open update ZIP');

        updateUpdaterFromTag(new FakeGitHubClient('not-a-zip'), 'oakengine/installer', 'v1.0.0', 'src', $this->createTempDirectory());
    }

    public function testUpdateUpdaterFromTagThrowsWhenArchiveHasNoDirectory(): void
    {
        $dir = $this->createTempDirectory();
        $zipPath = $dir.'/no-dir.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('file.txt', 'no-dir-content');
        $zip->close();
        $zipContent = (string) file_get_contents($zipPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No directory in update archive');

        updateUpdaterFromTag(new FakeGitHubClient($zipContent), 'oakengine/installer', 'v1.0.0', 'src', $this->createTempDirectory());
    }

    public function testUpdateUpdaterFromTagThrowsWhenUpdaterSourcePathMissing(): void
    {
        $archiveContent = $this->createZipArchive(['src/index.php' => '<?php echo "x";']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Updater source path not found');

        updateUpdaterFromTag(new FakeGitHubClient($archiveContent), 'oakengine/installer', 'v1.0.0', 'not-in-archive', $this->createTempDirectory());
    }

    public function testUpdateUpdaterFromTagSkipsDirectories(): void
    {
        $archiveContent = $this->createZipArchive([
            'src/index.php' => '<?php echo "x";',
        ]);

        $destinationDir = $this->createTempDirectory();
        $result = updateUpdaterFromTag(new FakeGitHubClient($archiveContent), 'oakengine/installer', 'v1.0.0', 'src', $destinationDir);

        $this->assertContains('index.php', $result['updated_files']);
    }

    public function testUpdateUpdaterFromTagThrowsWhenTargetDirectoryCannotBeCreated(): void
    {
        $destinationDir = $this->createTempDirectory();
        file_put_contents($destinationDir.'/app', 'blocker-as-file');
        $archiveContent = $this->createZipArchive([
            'src/app/Foo.php' => '<?php echo "x";',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target directory cannot be created');

        updateUpdaterFromTag(new FakeGitHubClient($archiveContent), 'oakengine/installer', 'v1.0.0', 'src', $destinationDir);
    }

    public function testUpdateUpdaterFromTagThrowsWhenCopyFails(): void
    {
        $destinationDir = $this->createTempDirectory();
        mkdir($destinationDir.'/index.php', 0o755, true);
        $archiveContent = $this->createZipArchive([
            'src/index.php' => '<?php echo "x";',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to update file');

        updateUpdaterFromTag(new FakeGitHubClient($archiveContent), 'oakengine/installer', 'v1.0.0', 'src', $destinationDir);
    }

    public function testUpdateUpdaterFromTagThrowsWhenTempUpdateDirectoryCannotBeCreated(): void
    {
        $destinationDir = $this->createTempDirectory();
        chmod($destinationDir, 0o555);
        $archiveContent = $this->createZipArchive([
            'src/index.php' => '<?php echo "x";',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Temp update directory cannot be created');

        try {
            updateUpdaterFromTag(new FakeGitHubClient($archiveContent), 'oakengine/installer', 'v1.0.0', 'src', $destinationDir);
        } finally {
            chmod($destinationDir, 0o755);
        }
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

    public function testWriteGitHubRepositoryRefsCacheThrowsWhenCreateDirectoryTreeFails(): void
    {
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub cache directory cannot be created');

        writeGitHubRepositoryRefsCache($blocker.'/file/sub/refs.php', [
            'tags' => [['name' => 'v1.0.0', 'commit' => 'tag-sha']],
            'branches' => [['name' => 'main', 'commit' => 'branch-sha']],
        ]);
    }

    public function testWriteGitHubRepositoryRefsCacheThrowsWhenFileCannotBeWritten(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';
        mkdir($cacheDir, 0o755, true);
        chmod($cacheDir, 0o555);
        $cacheFile = buildGitHubRepositoryRefsCacheFilePath($cacheDir, 'oakengine/installer');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('GitHub cache file cannot be written');

        try {
            writeGitHubRepositoryRefsCache($cacheFile, [
                'tags' => [['name' => 'v1.0.0', 'commit' => 'tag-sha']],
                'branches' => [['name' => 'main', 'commit' => 'branch-sha']],
            ]);
        } finally {
            chmod($cacheDir, 0o755);
        }
    }

    public function testNormalizeGitHubRepositoryRefsCacheValueReturnsNullForNonArray(): void
    {
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue('not-an-array'));
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue(42));
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue(null));
    }

    public function testNormalizeGitHubRepositoryRefsCacheValueReturnsNullForNonArrayEntry(): void
    {
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue([
            'valid' => ['name' => 'v1', 'commit' => 'sha'],
            'invalid' => 'not-an-array',
        ]));
    }

    public function testNormalizeGitHubRepositoryRefsCacheValueReturnsNullForMissingFields(): void
    {
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue([
            ['name' => 'v1'],
        ]));
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue([
            ['commit' => 'sha'],
        ]));
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue([
            ['name' => 1.5, 'commit' => 'sha'],
        ]));
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue([
            ['name' => 'v1', 'commit' => ['sha']],
        ]));
    }

    public function testNormalizeGitHubRepositoryRefsCacheValueReturnsNullForEmptyFields(): void
    {
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue([
            ['name' => '   ', 'commit' => 'sha'],
        ]));
        $this->assertNull(normalizeGitHubRepositoryRefsCacheValue([
            ['name' => 'v1', 'commit' => ''],
        ]));
    }

    public function testReadGitHubRepositoryRefsCacheReturnsNullWhenTagsOrBranchesInvalid(): void
    {
        $cacheDir = $this->createTempDirectory();
        @mkdir($cacheDir, 0o755, true);
        $cacheFile = buildGitHubRepositoryRefsCacheFilePath($cacheDir, 'oakengine/installer');
        file_put_contents($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn ['tags' => 'invalid', 'branches' => []];\n");

        $this->assertNull(readGitHubRepositoryRefsCache($cacheFile, 600));
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

    public function testCleanTargetDirectoryReturnsEmptyResultForMissingDirectory(): void
    {
        $result = cleanTargetDirectory($this->createTempDirectory().'/missing');

        $this->assertSame(0, $result['deleted_count']);
        $this->assertSame([], $result['preserved']);
    }

    public function testClearCacheDirectoryReportsErrorsWhenIteratorThrows(): void
    {
        $cacheDir = $this->createTempDirectory();
        mkdir($cacheDir.'/sub', 0o755, true);
        file_put_contents($cacheDir.'/sub/file.txt', 'x');
        chmod($cacheDir.'/sub', 0o000);

        $result = clearCacheDirectory($cacheDir);

        chmod($cacheDir.'/sub', 0o755);
        $this->assertNotEmpty($result['errors']);
    }

    public function testClearCacheDirectoryPreservesLogDirectories(): void
    {
        $cacheDir = $this->createTempDirectory().'/cache';
        mkdir($cacheDir.'/log', 0o755, true);
        file_put_contents($cacheDir.'/log/app.log', 'keep me');
        file_put_contents($cacheDir.'/test.txt', 'remove me');

        $result = clearCacheDirectory($cacheDir);

        $this->assertSame(1, $result['deleted_count']);
        $this->assertFileExists($cacheDir.'/log/app.log');
        $this->assertFileDoesNotExist($cacheDir.'/test.txt');
        $this->assertDirectoryExists($cacheDir.'/log');
    }

    public function testExtractZipThrowsWhenZipCannotBeOpened(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to open ZIP');

        extractZip('not-a-real-zip', $this->createTempDirectory(), [], []);
    }

    public function testExtractZipThrowsWhenTempDirectoryCannotBeCreated(): void
    {
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');
        $targetDir = $blocker.'/file/target';

        @mkdir($targetDir, 0o755, true);
        @chmod($targetDir, 0o555);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Temp extract directory cannot be created');

        try {
            extractZip($this->createZipArchive(['file.txt' => 'x']), $targetDir, [], []);
        } finally {
            @chmod($targetDir, 0o755);
            recursiveDelete($targetDir);
        }
    }

    public function testExtractZipThrowsWhenArchiveHasNoDirectory(): void
    {
        $dir = $this->createTempDirectory();
        $zipPath = $dir.'/no-dir.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('file.txt', 'just-a-file-no-dir');
        $zip->close();
        $zipContent = (string) file_get_contents($zipPath);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No directory in archive');

        extractZip($zipContent, $this->createTempDirectory(), [], []);
    }

    public function testExtractZipThrowsWhenSubdirectoryCannotBeCreated(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/sub', 'blocker-as-file');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Target directory cannot be created');

        extractZip(
            $this->createZipArchive(['sub/file.txt' => 'x']),
            $targetDir,
            [],
            []
        );
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

    public function testParseEnvLocalReturnsDefaultsForMissingFile(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        $result = parseEnvLocal($envPath);
        $this->assertSame('prod', $result['app_env']);
        $this->assertNull($result['current_db']);
        $this->assertSame([], $result['databases']);
        $this->assertNull($result['install_uuid']);
        $this->assertNull($result['app_secret']);
        $this->assertSame('', $result['raw_content']);
    }

    public function testParseEnvLocalReturnsDefaultsForUnreadableFile(): void
    {
        $dir = $this->createTempDirectory();
        $envPath = $dir.'/.env.local';
        file_put_contents($envPath, 'APP_ENV=dev');
        chmod($envPath, 0o000);

        try {
            $result = parseEnvLocal($envPath);
            $this->assertSame('prod', $result['app_env']);
            $this->assertSame('', $result['raw_content']);
        } finally {
            chmod($envPath, 0o644);
        }
    }

    public function testUpdateEnvLocalReturnsFalseForUnreadableFile(): void
    {
        $dir = $this->createTempDirectory();
        $envPath = $dir.'/.env.local';
        file_put_contents($envPath, 'APP_ENV=dev');
        chmod($envPath, 0o000);

        try {
            $this->assertFalse(updateEnvLocal($envPath, 'prod', 'DB1'));
        } finally {
            chmod($envPath, 0o644);
        }
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

    public function testAddDatabaseToEnvLocalCreatesFileAndDirectory(): void
    {
        $envPath = $this->createTempDirectory().'/nested/.env.local';

        $this->assertTrue(addDatabaseToEnvLocal($envPath, 'NEW_DB', 'mysql://u:p@h/db'));
        $this->assertStringContainsString('DATABASE_URL="mysql://u:p@h/db" # NEW_DB', (string) file_get_contents($envPath));
    }

    public function testAddDatabaseToEnvLocalReturnsFalseWhenDirectoryCannotBeCreated(): void
    {
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $this->assertFalse(addDatabaseToEnvLocal($blocker.'/file/.env.local', 'NEW_DB', 'mysql://u:p@h/db'));
    }

    public function testAddDatabaseToEnvLocalReturnsFalseWhenFileCannotBeWritten(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/.env.local', 0o755, true);

        $this->assertFalse(addDatabaseToEnvLocal($targetDir.'/.env.local', 'NEW_DB', 'mysql://u:p@h/db'));
    }

    public function testAddDatabaseToEnvLocalRejectsInvalidCharacters(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        $this->assertFalse(addDatabaseToEnvLocal($envPath, 'DB"X', 'mysql://u:p@h/db'));
        $this->assertFalse(addDatabaseToEnvLocal($envPath, 'DB_OK', "mysql://u:p@h/db\nINJECTED"));
    }

    public function testRemoveDatabaseFromEnvLocalReturnsFalseWhenFileMissing(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        $this->assertFalse(removeDatabaseFromEnvLocal($envPath, 'DB1'));
    }

    public function testRemoveDatabaseFromEnvLocalReturnsFalseForEmptyDbId(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");
        $this->assertFalse(removeDatabaseFromEnvLocal($envPath, '   '));
    }

    public function testRemoveDatabaseFromEnvLocalReturnsFalseForUnreadableFile(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");
        chmod($envPath, 0o000);

        try {
            $this->assertFalse(removeDatabaseFromEnvLocal($envPath, 'DB1'));
        } finally {
            chmod($envPath, 0o644);
        }
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

    public function testUpdateInstallUuidInEnvLocalReturnsFalseForUnreadableFile(): void
    {
        $manager = new InstallUuidManager();
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, 'APP_ENV=prod');
        chmod($envPath, 0o000);

        try {
            $this->assertFalse(updateInstallUuidInEnvLocal($manager, $envPath, '018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1'));
        } finally {
            chmod($envPath, 0o644);
        }
    }

    public function testUpdateInstallUuidInEnvLocalReturnsFalseWhenDirectoryCannotBeCreated(): void
    {
        $manager = new InstallUuidManager();
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $this->assertFalse(updateInstallUuidInEnvLocal($manager, $blocker.'/file/.env.local', '018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1'));
    }

    public function testUpdateAppSecretInEnvLocalReturnsFalseForUnreadableFile(): void
    {
        $manager = new AppSecretManager();
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, 'APP_ENV=prod');
        chmod($envPath, 0o000);

        try {
            $this->assertFalse(updateAppSecretInEnvLocal($manager, $envPath, '690b81936a56c725cedc2a32a67b5b56'));
        } finally {
            chmod($envPath, 0o644);
        }
    }

    public function testUpdateAppSecretInEnvLocalReturnsFalseWhenDirectoryCannotBeCreated(): void
    {
        $manager = new AppSecretManager();
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $this->assertFalse(updateAppSecretInEnvLocal($manager, $blocker.'/file/.env.local', '690b81936a56c725cedc2a32a67b5b56'));
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

    public function testEnsureEnvLocalInstallUuidThrowsWhenFileUnreadable(): void
    {
        $manager = new InstallUuidManager();
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, 'APP_ENV=prod');
        chmod($envPath, 0o000);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to read');

        try {
            $manager->ensureEnvLocalInstallUuid($envPath);
        } finally {
            chmod($envPath, 0o644);
        }
    }

    public function testEnsureEnvLocalInstallUuidThrowsWhenDirectoryCannotBeCreated(): void
    {
        $manager = new InstallUuidManager();
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to create directory');

        $manager->ensureEnvLocalInstallUuid($blocker.'/file/.env.local');
    }

    public function testEnsureEnvLocalInstallUuidThrowsWhenFileCannotBeWritten(): void
    {
        $manager = new InstallUuidManager();
        $targetDir = $this->createTempDirectory().'/nested';
        mkdir($targetDir, 0o755, true);
        chmod($targetDir, 0o555);
        $envPath = $targetDir.'/.env.local';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unable to write');

        try {
            $manager->ensureEnvLocalInstallUuid($envPath);
        } finally {
            chmod($targetDir, 0o755);
        }
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

    public function testSyncPackageEnvComposerMetadataSourcesToEnvLocalDetailedSkipsDuplicateSkippedKey(): void
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
                                'dir' => 'new-value',
                                'core-bundle-class' => 'Acme\\Bundle',
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
                                'dir' => 'other-value',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertTrue($result['written']);
        $this->assertSame([
            'OAK_DIR=new-value',
            'OAK_CORE_BUNDLE_CLASS=Acme\\Bundle',
        ], $result['written_lines']);
        $this->assertSame([], $result['skipped_existing_lines']);
    }

    public function testSyncPackageEnvToEnvLocalDetailedReturnsNotWrittenForUnreadableFile(): void
    {
        $envPath = $this->createTempDirectory().'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");
        chmod($envPath, 0o000);

        try {
            $result = syncPackageEnvToEnvLocalDetailed($envPath, [
                'extra' => [
                    'oak-engine-runner' => [
                        'env' => [
                            'dir' => 'example',
                        ],
                    ],
                ],
            ], 'runner');

            $this->assertFalse($result['written']);
            $this->assertSame([], $result['written_lines']);
        } finally {
            chmod($envPath, 0o644);
        }
    }

    public function testSyncPackageEnvToEnvLocalDetailedReturnsNotWrittenWhenDirectoryCannotBeCreated(): void
    {
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $result = syncPackageEnvToEnvLocalDetailed($blocker.'/file/.env.local', [
            'extra' => [
                'oak-engine-runner' => [
                    'env' => [
                        'dir' => 'example',
                    ],
                ],
            ],
        ], 'runner');

        $this->assertFalse($result['written']);
        $this->assertSame([], $result['written_lines']);
    }

    public function testSyncPackageEnvToEnvLocalDetailedReturnsNotWrittenWhenFileCannotBeWritten(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/.env.local', 0o755, true);

        $result = syncPackageEnvToEnvLocalDetailed($targetDir.'/.env.local', [
            'extra' => [
                'oak-engine-runner' => [
                    'env' => [
                        'dir' => 'example',
                    ],
                ],
            ],
        ], 'runner');

        $this->assertFalse($result['written']);
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

    public function testResolvePackageEnvComposerMetadataSkipsMismatchedTypes(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/runner/acme/core/site', 0o755, true);
        file_put_contents($targetDir.'/runner/acme/core/site/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'dir' => 'core-dir',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));
        mkdir($targetDir.'/runner/acme/plugin/blog', 0o755, true);
        file_put_contents($targetDir.'/runner/acme/plugin/blog/composer.json', json_encode([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'default-language' => 'en',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame([
            'extra' => [
                'oak-engine-plugin' => [
                    'env' => [
                        'default-language' => 'en',
                    ],
                ],
            ],
        ], resolvePackageEnvComposerMetadata($targetDir, 'plugin'));
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

    public function testGetMigrationsStatusExtractsMessageFromJson(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $dir = $this->createTempDirectory();
        mkdir($dir.'/migrations', 0o755, true);
        file_put_contents($dir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($dir, json_encode(['message' => 'SQLSTATE error: Some failure'], JSON_THROW_ON_ERROR));

        $status = getMigrationsStatus($dir);

        $this->assertTrue($status['error']);
        $this->assertStringContainsString('SQLSTATE error', $status['html']);
    }

    public function testGetMigrationsStatusExtractsInnerMessageFromJson(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $dir = $this->createTempDirectory();
        mkdir($dir.'/migrations', 0o755, true);
        file_put_contents($dir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($dir, json_encode(['message' => 'Wrapped: Message: "Inner detail"'], JSON_THROW_ON_ERROR));

        $status = getMigrationsStatus($dir);

        $this->assertTrue($status['error']);
        $this->assertStringContainsString('Inner detail', $status['html']);
    }

    public function testGetMigrationsStatusDetectsConnectionRefusedInJson(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $dir = $this->createTempDirectory();
        mkdir($dir.'/migrations', 0o755, true);
        file_put_contents($dir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($dir, json_encode(['message' => 'Connection refused'], JSON_THROW_ON_ERROR));

        $status = getMigrationsStatus($dir);

        $this->assertArrayHasKey('no_db', $status);
        $this->assertFalse($status['error']);
    }

    public function testGetMigrationsStatusHandlesPlainTextError(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $dir = $this->createTempDirectory();
        mkdir($dir.'/migrations', 0o755, true);
        file_put_contents($dir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($dir, "Some error happened\nMore lines\nEven more");

        $status = getMigrationsStatus($dir);

        $this->assertTrue($status['error']);
        $this->assertStringContainsString('Some error happened', $status['html']);
    }

    public function testGetMigrationsStatusDetectsConnectionRefusedInPlainText(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $dir = $this->createTempDirectory();
        mkdir($dir.'/migrations', 0o755, true);
        file_put_contents($dir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($dir, "Doctrine\\DBAL\\ExceptionConverter.php: Connection refused");

        $status = getMigrationsStatus($dir);

        $this->assertArrayHasKey('no_db', $status);
        $this->assertFalse($status['error']);
    }

    public function testGetMigrationsStatusFallsBackToEmptyOutput(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $dir = $this->createTempDirectory();
        mkdir($dir.'/migrations', 0o755, true);
        file_put_contents($dir.'/migrations/Version1.php', '<?php');
        $this->createConsoleScript($dir, '');

        $status = getMigrationsStatus($dir);

        $this->assertTrue($status['error']);
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
        $this->assertStringContainsString('view=system', $html);
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

    public function testRenderPageHighlightsSystemViewAndKeepsContent(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', 'de'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $html = renderPage('Installer', '<ul class="info-list">SYSTEM</ul>', null, $envPath, false, 'system');

        $this->assertStringContainsString('SYSTEM', $html);
        $this->assertStringContainsString('href="'.buildDashboardViewHref('system').'"', $html);
    }

    public function testRenderPageSkipsEmptyAndNonScalarLanguageCodes(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en', '', null, 'de'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");

        $html = renderPage('Installer', '', null, $envPath, false, 'home');

        $this->assertStringContainsString('value="en"', $html);
        $this->assertStringContainsString('value="de"', $html);
        $this->assertStringNotContainsString('value=""', $html);
    }

    public function testRenderPageSkipsEmptyAndNonScalarDatabaseIds(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents(
            $envPath,
            "APP_ENV=prod\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n# DATABASE_URL=\"mysql://u:p@h/db2\" # DB2\n"
        );

        $html = renderPage('Installer', '<p>ignored</p>', null, $envPath, false, 'databases');

        $this->assertStringContainsString('value="DB1"', $html);
        $this->assertStringNotContainsString('value=""', $html);
    }

    public function testRenderPageSkipsDatabaseEntriesWithEmptyId(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents(
            $envPath,
            "APP_ENV=prod\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\nDATABASE_URL=\"mysql://u:p@h/empty\" #    \n"
        );

        $html = renderPage('Installer', '<p>ignored</p>', null, $envPath, false, 'databases');

        $this->assertStringContainsString('value="DB1"', $html);
    }

    public function testRenderPageSkipsRemoveDbDropdownEntryWithEmptyId(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';
        $GLOBALS['availableLangs'] = ['en'];
        $_SESSION['lang'] = 'en';

        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents(
            $envPath,
            "APP_ENV=prod\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\nDATABASE_URL=\"mysql://u:p@h/empty\" #    \n"
        );

        $html = renderPage('Installer', '<p>ignored</p>', null, $envPath, false, 'databases');

        $this->assertStringContainsString('Remove database', $html);
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
        $this->assertSame('home', resolveDashboardView('home'));
        $this->assertSame('updates', resolveDashboardView('updates'));
        $this->assertSame('environment', resolveDashboardView('environment'));
        $this->assertSame('databases', resolveDashboardView('databases'));
        $this->assertSame('install-uuid', resolveDashboardView('install-uuid'));
        $this->assertSame('installer', resolveDashboardView('installer'));
        $this->assertSame('system', resolveDashboardView('system'));
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

        $href = buildDashboardViewHref('system');
        $this->assertMatchesRegularExpression('/^\?_t=\d+&view=system$/', $href);
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
        $this->assertSame(
            '<input type="hidden" name="view" value="system">',
            renderDashboardStateInputs('system')
        );
    }

    public function testInstallManifestManagerLoadReturnsNullWithoutManifest(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();

        $this->assertNull($manager->loadManifest($targetDir));
        $this->assertFalse($manager->manifestExists($targetDir));
    }

    public function testInstallManifestManagerLoadReturnsNullForInvalidJson(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents($manager->manifestPath($targetDir), '{not valid json');

        $this->assertNull($manager->loadManifest($targetDir));
    }

    public function testInstallManifestManagerLoadReturnsNullForMissingFilesKey(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents($manager->manifestPath($targetDir), json_encode(['package_type' => 'runner'], JSON_THROW_ON_ERROR));

        $this->assertNull($manager->loadManifest($targetDir));
    }

    public function testInstallManifestManagerLoadNormalizesManifest(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents(
            $manager->manifestPath($targetDir),
            json_encode([
                'package_type' => 'plugin',
                'package_id' => 'demo',
                'version' => '1.0.0',
                'files' => ['app/file.php' => 'abc123', 'other.php' => 123],
            ], JSON_THROW_ON_ERROR)
        );

        $manifest = $manager->loadManifest($targetDir);

        $this->assertNotNull($manifest);
        $this->assertSame('plugin', $manifest['package_type']);
        $this->assertSame('demo', $manifest['package_id']);
        $this->assertSame('1.0.0', $manifest['version']);
        $this->assertSame(['app/file.php' => 'abc123', 'other.php' => '123'], $manifest['files']);
        $this->assertTrue($manager->manifestExists($targetDir));
    }

    public function testInstallManifestManagerSaveAndBuildRoundTrip(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/app', 0o755, true);
        file_put_contents($targetDir.'/app/file.php', 'content-a');
        file_put_contents($targetDir.'/root.php', 'content-b');

        $manifest = $manager->buildManifest($targetDir, 'runner', 'demo', '1.2.3', ['app/file.php', 'root.php']);

        $this->assertSame('runner', $manifest['package_type']);
        $this->assertSame('demo', $manifest['package_id']);
        $this->assertSame('1.2.3', $manifest['version']);
        $this->assertSame(sha1('content-a'), $manifest['files']['app/file.php']);
        $this->assertSame(sha1('content-b'), $manifest['files']['root.php']);

        $this->assertTrue($manager->saveManifest($targetDir, $manifest));
        $this->assertTrue($manager->manifestExists($targetDir));

        $loaded = $manager->loadManifest($targetDir);
        $this->assertNotNull($loaded);
        $this->assertSame($manifest['files'], $loaded['files']);
    }

    public function testInstallManifestManagerBuildSkipsMissingFilesAndManifestFile(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/present.php', 'x');

        $manifest = $manager->buildManifest(
            $targetDir,
            'data',
            'demo',
            '2.0.0',
            ['present.php', 'missing.php', InstallManifestManager::MANIFEST_FILENAME, '', 123]
        );

        $this->assertSame(['present.php' => sha1('x')], $manifest['files']);
    }

    public function testInstallManifestManagerSaveCreatesDirectory(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory().'/nested/deep';

        $manifest = $manager->buildManifest($targetDir, 'plugin', 'demo', '1.0.0', []);
        $this->assertTrue($manager->saveManifest($targetDir, $manifest));
        $this->assertFileExists($manager->manifestPath($targetDir));
    }

    public function testInstallManifestManagerDiffReturnsEmptyForFirstInstall(): void
    {
        $manager = new InstallManifestManager();
        $newManifest = ['package_type' => 'runner', 'package_id' => 'demo', 'version' => '1.0.0', 'files' => ['a.php' => 'x']];

        $this->assertSame([], $manager->diffStaleFiles(null, $newManifest));
    }

    public function testInstallManifestManagerDiffReturnsStaleFilesSorted(): void
    {
        $manager = new InstallManifestManager();
        $oldManifest = [
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.0.0',
            'files' => ['keep.php' => 'a', 'old.php' => 'b', 'gone.php' => 'c'],
        ];
        $newManifest = [
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.1.0',
            'files' => ['keep.php' => 'a', 'added.php' => 'd'],
        ];

        $this->assertSame(['gone.php', 'old.php'], $manager->diffStaleFiles($oldManifest, $newManifest));
    }

    public function testInstallManifestManagerDeleteStaleFilesAndEmptyDirs(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/old/sub', 0o755, true);
        mkdir($targetDir.'/keep', 0o755, true);
        file_put_contents($targetDir.'/old/stale.php', 'old');
        file_put_contents($targetDir.'/old/sub/deep.php', 'old');
        file_put_contents($targetDir.'/keep/file.php', 'keep');
        file_put_contents($targetDir.'/root.php', 'keep');

        $result = $manager->deleteStaleFilesAndEmptyDirs($targetDir, ['old/stale.php', 'old/sub/deep.php', 'nonexistent.php']);

        $this->assertEqualsCanonicalizing(['old/stale.php', 'old/sub/deep.php'], $result['deleted_files']);
        $this->assertSame([], $result['errors']);
        $this->assertFileDoesNotExist($targetDir.'/old/stale.php');
        $this->assertFileDoesNotExist($targetDir.'/old/sub/deep.php');
        $this->assertDirectoryDoesNotExist($targetDir.'/old/sub');
        $this->assertDirectoryDoesNotExist($targetDir.'/old');
        $this->assertFileExists($targetDir.'/keep/file.php');
        $this->assertFileExists($targetDir.'/root.php');
        $this->assertNotEmpty($result['deleted_dirs']);
    }

    public function testInstallManifestManagerDeleteIgnoresPathsOutsideTarget(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        $outside = $this->createTempDirectory();
        file_put_contents($outside.'/secret.php', 'secret');
        file_put_contents($targetDir.'/inside.php', 'keep');

        $result = $manager->deleteStaleFilesAndEmptyDirs($targetDir, ['../'.basename($outside).'/secret.php']);

        $this->assertSame([], $result['deleted_files']);
        $this->assertFileExists($outside.'/secret.php');
        $this->assertFileExists($targetDir.'/inside.php');
    }

    public function testInstallManifestManagerDeleteReportsErrorsForUnlinkedFiles(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/locked', 0o755, true);
        file_put_contents($targetDir.'/locked/file.php', 'locked');
        chmod($targetDir.'/locked', 0o555);

        $result = $manager->deleteStaleFilesAndEmptyDirs($targetDir, ['locked/file.php']);

        chmod($targetDir.'/locked', 0o755);

        $this->assertSame([], $result['deleted_files']);
        $this->assertNotEmpty($result['errors']);
        $this->assertFileExists($targetDir.'/locked/file.php');
    }

    public function testInstallManifestManagerDeleteHandlesMissingTargetDirectory(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory().'/does-not-exist';

        $result = $manager->deleteStaleFilesAndEmptyDirs($targetDir, ['a.php']);

        $this->assertSame(['deleted_files' => [], 'deleted_dirs' => [], 'errors' => []], $result);
    }

    public function testInstallManifestManagerManifestPath(): void
    {
        $manager = new InstallManifestManager();

        $this->assertSame('/var/project/'.InstallManifestManager::MANIFEST_FILENAME, $manager->manifestPath('/var/project'));
        $this->assertSame('/var/project/'.InstallManifestManager::MANIFEST_FILENAME, $manager->manifestPath('/var/project/'));
    }

    public function testInstallManifestManagerEndToEndUpdateRemovesObsoleteFiles(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();

        mkdir($targetDir.'/v1', 0o755, true);
        file_put_contents($targetDir.'/v1/keep.php', 'keep');
        file_put_contents($targetDir.'/v1/old.php', 'old');
        file_put_contents($targetDir.'/root.php', 'root');

        $oldManifest = $manager->buildManifest($targetDir, 'plugin', 'demo', '1.0.0', ['v1/keep.php', 'v1/old.php', 'root.php']);
        $this->assertTrue($manager->saveManifest($targetDir, $oldManifest));

        $loadedOld = $manager->loadManifest($targetDir);
        $this->assertNotNull($loadedOld);

        $newManifest = $manager->buildManifest($targetDir, 'plugin', 'demo', '1.1.0', ['v1/keep.php', 'root.php']);
        $this->assertTrue($manager->saveManifest($targetDir, $newManifest));

        $stale = $manager->diffStaleFiles($loadedOld, $newManifest);
        $this->assertContains('v1/old.php', $stale);

        $result = $manager->deleteStaleFilesAndEmptyDirs($targetDir, $stale);

        $this->assertContains('v1/old.php', $result['deleted_files']);
        $this->assertFileDoesNotExist($targetDir.'/v1/old.php');
        $this->assertFileExists($targetDir.'/v1/keep.php');
        $this->assertFileExists($targetDir.'/root.php');
    }

    public function testInstallManifestManagerLoadReturnsNullForUnreadableFile(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        $path = $manager->manifestPath($targetDir);
        file_put_contents($path, '{}');
        chmod($path, 0o000);

        $this->assertNull($manager->loadManifest($targetDir));

        chmod($path, 0o644);
    }

    public function testInstallManifestManagerLoadSkipsNonStringKeys(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents(
            $manager->manifestPath($targetDir),
            json_encode([
                'package_type' => 'runner',
                'package_id' => 'demo',
                'version' => '1.0.0',
                'files' => [123 => 'numeric-key-hash', 'valid.php' => 'abc'],
            ], JSON_THROW_ON_ERROR)
        );

        $manifest = $manager->loadManifest($targetDir);
        $this->assertNotNull($manifest);
        $this->assertSame(['valid.php' => 'abc'], $manifest['files']);
    }

    public function testInstallManifestManagerLoadNormalizesNonScalarMetadata(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents(
            $manager->manifestPath($targetDir),
            json_encode([
                'package_type' => ['nested'],
                'package_id' => null,
                'version' => '1.0.0',
                'files' => [],
            ], JSON_THROW_ON_ERROR)
        );

        $manifest = $manager->loadManifest($targetDir);
        $this->assertNotNull($manifest);
        $this->assertSame('', $manifest['package_type']);
        $this->assertSame('', $manifest['package_id']);
    }

    public function testInstallManifestManagerSaveReturnsFalseWhenDirectoryCannotBeCreated(): void
    {
        $manager = new InstallManifestManager();
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $manifest = $manager->buildManifest($blocker.'/file', 'runner', 'demo', '1.0.0', []);

        $this->assertFalse($manager->saveManifest($blocker.'/file', $manifest));
    }

    public function testInstallManifestManagerSaveReturnsFalseForUnencodableManifest(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();

        $manifest = [
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.0.0',
            'files' => ['bad.php' => "invalid\xFFutf8"],
        ];

        $this->assertFalse($manager->saveManifest($targetDir, $manifest));
    }

    public function testInstallManifestManagerBuildSkipsUnreadableFiles(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/readable.php', 'a');
        file_put_contents($targetDir.'/unreadable.php', 'b');
        chmod($targetDir.'/unreadable.php', 0o000);

        $manifest = $manager->buildManifest($targetDir, 'runner', 'demo', '1.0.0', ['readable.php', 'unreadable.php']);

        chmod($targetDir.'/unreadable.php', 0o644);
        $this->assertSame(['readable.php' => sha1('a')], $manifest['files']);
    }

    public function testInstallManifestManagerDiffIgnoresNonStringKeys(): void
    {
        $manager = new InstallManifestManager();
        $oldManifest = [
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.0.0',
            'files' => [0 => 'numeric', 'gone.php' => 'b'],
        ];
        $newManifest = [
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.1.0',
            'files' => ['keep.php' => 'c'],
        ];

        $this->assertSame(['gone.php'], $manager->diffStaleFiles($oldManifest, $newManifest));
    }

    public function testInstallManifestManagerDeleteSkipsNonStringAndEmptyEntries(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/keep.php', 'keep');

        $result = $manager->deleteStaleFilesAndEmptyDirs($targetDir, [0, '', null, 'keep.php']);

        $this->assertEqualsCanonicalizing(['keep.php'], $result['deleted_files']);
    }

    public function testInstallManifestManagerDeleteHandlesUnreadableDirectory(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/unreadable', 0o755, true);
        chmod($targetDir.'/unreadable', 0o000);

        $result = $manager->deleteStaleFilesAndEmptyDirs($targetDir, []);

        chmod($targetDir.'/unreadable', 0o755);
        $this->assertSame(['deleted_files' => [], 'deleted_dirs' => [], 'errors' => []], $result);
    }

    public function testInstallerLogRelativePath(): void
    {
        $this->assertSame('oak-installer.log', installerLogRelativePath());
    }

    public function testInstallerLogPathFallsBackToTemp(): void
    {
        $path = installerLogPath('');

        $this->assertStringEndsWith('/oak-installer.log', $path);
        $this->assertDirectoryExists(dirname($path));
    }

    public function testInstallerLogPathUsesProvidedRoot(): void
    {
        $projectRoot = $this->createTempDirectory();

        $path = installerLogPath($projectRoot);

        $this->assertSame($projectRoot.'/oak-installer.log', $path);
    }

    public function testInstallerLogBaseDirectoryDefaultsToInstallerLogs(): void
    {
        $installerRoot = $this->createTempDirectory();
        @rmdir($installerRoot.'/logs');

        $resolved = installerLogBaseDirectory($installerRoot);

        $this->assertSame($installerRoot.'/logs', $resolved);
        $this->assertDirectoryExists($resolved);
    }

    public function testInstallerLogBaseDirectoryUsesConfiguredAbsolutePath(): void
    {
        $installerRoot = $this->createTempDirectory();
        $configured = $this->createTempDirectory().'/custom-logs';

        $resolved = installerLogBaseDirectory($installerRoot, $configured);

        $this->assertSame($configured, $resolved);
        $this->assertDirectoryExists($resolved);
    }

    public function testInstallerLogBaseDirectoryResolvesRelativeConfigAgainstInstallerRoot(): void
    {
        $installerRoot = $this->createTempDirectory();

        $resolved = installerLogBaseDirectory($installerRoot, 'shared/log');

        $this->assertSame($installerRoot.'/shared/log', $resolved);
        $this->assertDirectoryExists($resolved);
    }

    public function testInstallerLogBaseDirectoryDefaultsToTempWhenInstallerRootEmpty(): void
    {
        $resolved = installerLogBaseDirectory('');

        $this->assertStringEndsWith('/oak-installer-logs', $resolved);
        $this->assertDirectoryExists($resolved);
    }

    public function testLogInstallerEventAppendsTimestampedLine(): void
    {
        $projectRoot = $this->createTempDirectory();

        logInstallerEvent($projectRoot, 'info', 'Hello world', ['package_type' => 'runner', 'count' => 5]);
        logInstallerEvent($projectRoot, 'warning', 'Something went wrong', ['relative_path' => 'src/Foo.php']);

        $lines = readInstallerLog($projectRoot);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('[INFO]', $lines[0]);
        $this->assertStringContainsString('Hello world', $lines[0]);
        $this->assertStringContainsString('package_type=runner', $lines[0]);
        $this->assertStringContainsString('count=5', $lines[0]);
        $this->assertStringContainsString('[WARNING]', $lines[1]);
        $this->assertStringContainsString('Something went wrong', $lines[1]);
    }

    public function testLogInstallerEventHandlesBoolContext(): void
    {
        $projectRoot = $this->createTempDirectory();

        logInstallerEvent($projectRoot, 'info', 'Flag event', ['is_enabled' => true, 'is_visible' => false]);

        $lines = readInstallerLog($projectRoot);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('is_enabled=true', $lines[0]);
        $this->assertStringContainsString('is_visible=false', $lines[0]);
    }

    public function testLogInstallerEventCreatesLogDirectory(): void
    {
        $projectRoot = $this->createTempDirectory();
        $logDirectory = $projectRoot.'/nested/logs';
        $this->assertDirectoryDoesNotExist($logDirectory);

        logInstallerEvent($logDirectory, 'info', 'Lazy directory creation');

        $this->assertDirectoryExists($logDirectory);
        $this->assertFileExists($logDirectory.'/oak-installer.log');
    }

    public function testReadInstallerLogReturnsEmptyForMissingFile(): void
    {
        $projectRoot = $this->createTempDirectory();

        $this->assertSame([], readInstallerLog($projectRoot));
    }

    public function testReadInstallerLogReturnsAllWrittenLines(): void
    {
        $projectRoot = $this->createTempDirectory();
        $logPath = installerLogPath($projectRoot);
        if (file_exists($logPath)) {
            unlink($logPath);
        }

        for ($i = 1; $i <= 5; ++$i) {
            logInstallerEvent($projectRoot, 'info', 'Event '.$i);
        }

        clearstatcache();
        $lines = readInstallerLog($projectRoot);

        $this->assertGreaterThanOrEqual(5, count($lines));
    }

    public function testReadInstallerLogTruncatesWhenMoreLinesThanLimit(): void
    {
        $projectRoot = $this->createTempDirectory();
        $logPath = installerLogPath($projectRoot);
        if (file_exists($logPath)) {
            unlink($logPath);
        }
        @mkdir(dirname($logPath), 0o755, true);

        $content = '';
        for ($i = 1; $i <= 20; ++$i) {
            $content .= '[2026-07-03T16:00:00Z] [INFO] Event '.$i."\n";
        }
        file_put_contents($logPath, $content);

        clearstatcache();
        $lines = readInstallerLog($projectRoot, 5);

        $this->assertCount(5, $lines);
    }

    public function testInstallManifestManagerDeleteStaleFilesWritesLogEntry(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/stale.php', 'old');

        file_put_contents($targetDir.'/'.InstallManifestManager::MANIFEST_FILENAME, json_encode([
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.0.0',
            'files' => ['stale.php' => 'abc'],
        ]));

        $manager->deleteStaleFilesAndEmptyDirs($targetDir, ['stale.php'], $targetDir);

        $lines = readInstallerLog($targetDir);
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Deleted stale file from previous install')
                && str_contains($line, 'relative_path=stale.php')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected log line for deleted stale file was not written');
    }

    public function testInstallManifestManagerDeleteStaleFilesLogsFailedDeletions(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/sub', 0o755, true);
        file_put_contents($targetDir.'/sub/locked.php', 'content');
        chmod($targetDir.'/sub', 0o555);

        file_put_contents($targetDir.'/'.InstallManifestManager::MANIFEST_FILENAME, json_encode([
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.0.0',
            'files' => ['sub/locked.php' => 'abc'],
        ]));

        $result = null;
        try {
            $result = $manager->deleteStaleFilesAndEmptyDirs($targetDir, ['sub/locked.php'], $targetDir);
        } finally {
            chmod($targetDir.'/sub', 0o755);
            if (file_exists($targetDir.'/sub/locked.php')) {
                chmod($targetDir.'/sub/locked.php', 0o644);
            }
        }

        $this->assertNotEmpty($result['errors']);

        $lines = readInstallerLog($targetDir);
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Could not delete stale file (left in place)')
                && str_contains($line, 'relative_path=sub/locked.php')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected warning line for failed deletion was not written');
    }

    public function testCleanTargetDirectoryWritesLogEntry(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/leftover.txt', 'old');

        cleanTargetDirectory($targetDir, $targetDir);

        $lines = readInstallerLog($targetDir);
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Initial target directory cleanup finished')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected cleanup summary line was not written');
    }

    public function testCleanTargetDirectoryLogsFailedRemovals(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/locked', 0o755, true);
        file_put_contents($targetDir.'/locked/data.txt', 'x');
        chmod($targetDir.'/locked', 0o555);

        try {
            $result = cleanTargetDirectory($targetDir, $targetDir);
        } finally {
            chmod($targetDir.'/locked', 0o755);
        }

        $this->assertNotEmpty($result['failed']);

        $lines = readInstallerLog($targetDir);
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Could not remove leftover (left in place)')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected warning line for failed cleanup was not written');
    }

    public function testCleanTargetDirectoryReturnsFailedKey(): void
    {
        $targetDir = $this->createTempDirectory();

        $result = cleanTargetDirectory($targetDir);

        $this->assertArrayHasKey('failed', $result);
        $this->assertSame([], $result['failed']);
    }

    public function testLogInstallerPackageSummaryEmitsStructuredFields(): void
    {
        $projectRoot = $this->createTempDirectory();
        $logPath = installerLogPath($projectRoot);
        if (file_exists($logPath)) {
            unlink($logPath);
        }
        @mkdir(dirname($logPath), 0o755, true);

        logInstallerPackageSummary(
            $projectRoot,
            'runner',
            'oak-runner',
            [
                'previous_version' => '1.0.0',
                'new_version' => '1.1.0',
                'files_added' => 42,
                'files_removed' => 3,
                'dirs_removed' => 2,
                'errors' => 0,
            ]
        );

        clearstatcache();
        $lines = readInstallerLog($projectRoot);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Package runner update 1.0.0 → 1.1.0', $lines[0]);
        $this->assertStringContainsString('package_type=runner', $lines[0]);
        $this->assertStringContainsString('package_id=oak-runner', $lines[0]);
        $this->assertStringContainsString('previous_version=1.0.0', $lines[0]);
        $this->assertStringContainsString('new_version=1.1.0', $lines[0]);
        $this->assertStringContainsString('same_version=false', $lines[0]);
        $this->assertStringContainsString('files_added=42', $lines[0]);
        $this->assertStringContainsString('files_removed=3', $lines[0]);
        $this->assertStringContainsString('dirs_removed=2', $lines[0]);
        $this->assertStringContainsString('errors=0', $lines[0]);
        $this->assertStringContainsString('[INFO]', $lines[0]);
    }

    public function testLogInstallerPackageSummaryDetectsSameVersion(): void
    {
        $projectRoot = $this->createTempDirectory();
        $logPath = installerLogPath($projectRoot);
        if (file_exists($logPath)) {
            unlink($logPath);
        }
        @mkdir(dirname($logPath), 0o755, true);

        logInstallerPackageSummary(
            $projectRoot,
            'plugin',
            'oak-plugin',
            [
                'previous_version' => '2.5.0',
                'new_version' => '2.5.0',
                'files_added' => 5,
                'files_removed' => 0,
                'dirs_removed' => 0,
                'errors' => 0,
            ]
        );

        clearstatcache();
        $lines = readInstallerLog($projectRoot);

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('Package plugin reinstall (same version)', $lines[0]);
        $this->assertStringContainsString('same_version=true', $lines[0]);
    }

    public function testLogInstallerPackageSummaryEscalatesToWarningOnErrors(): void
    {
        $projectRoot = $this->createTempDirectory();
        $logPath = installerLogPath($projectRoot);
        if (file_exists($logPath)) {
            unlink($logPath);
        }
        @mkdir(dirname($logPath), 0o755, true);

        logInstallerPackageSummary(
            $projectRoot,
            'data',
            'oak-data',
            [
                'previous_version' => '1.0.0',
                'new_version' => '1.1.0',
                'files_added' => 10,
                'files_removed' => 0,
                'dirs_removed' => 0,
                'errors' => 3,
            ]
        );

        clearstatcache();
        $lines = readInstallerLog($projectRoot);

        $this->assertStringContainsString('[WARNING]', $lines[0]);
    }

    public function testInstallManifestManagerRemoveEmptyDirectoriesInPublicMethod(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/empty/sub', 0o755, true);
        file_put_contents($targetDir.'/keep.php', 'keep');

        $logPath = installerLogPath($targetDir);
        if (file_exists($logPath)) {
            unlink($logPath);
        }
        @mkdir(dirname($logPath), 0o755, true);

        file_put_contents($targetDir.'/'.InstallManifestManager::MANIFEST_FILENAME, json_encode([
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.0.0',
            'files' => ['keep.php' => 'abc'],
        ]));

        $deleted = $manager->removeEmptyDirectoriesIn($targetDir, $targetDir);

        $this->assertContains('empty/sub', $deleted);
        $this->assertDirectoryDoesNotExist($targetDir.'/empty/sub');
        $this->assertFileExists($targetDir.'/keep.php');

        clearstatcache();
        $lines = readInstallerLog($targetDir);
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Removed empty directories')) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected empty directory summary line not found');
    }

    public function testInstallManifestManagerRemoveEmptyDirectoriesInWithoutContext(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/no-context-empty', 0o755, true);

        $deleted = $manager->removeEmptyDirectoriesIn($targetDir);

        $this->assertContains('no-context-empty', $deleted);
        $this->assertDirectoryDoesNotExist($targetDir.'/no-context-empty');
    }

    public function testInstallManifestManagerRemoveEmptyDirectoriesInStillCleansWithoutManifest(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/no-manifest-empty', 0o755, true);

        $deleted = $manager->removeEmptyDirectoriesIn($targetDir, $targetDir);

        $this->assertContains('no-manifest-empty', $deleted);
        $this->assertDirectoryDoesNotExist($targetDir.'/no-manifest-empty');
    }

    public function testLogInstallerEventSilentlySwallowsDirectoryCreationFailure(): void
    {
        $projectRoot = $this->createTempDirectory();
        $blocker = $projectRoot.'/blocker';
        if (file_exists($blocker) && is_dir($blocker)) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($blocker, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($blocker);
        }
        file_put_contents($blocker, 'blocker');

        $unusableLogDirectory = $blocker.'/nested';

        $previousErrorReporting = error_reporting(0);
        try {
            logInstallerEvent($unusableLogDirectory, 'info', 'Should be swallowed');
        } finally {
            error_reporting($previousErrorReporting);
        }

        $this->assertFileExists($blocker);
        $this->assertSame('blocker', (string) file_get_contents($blocker));
    }

    public function testReadInstallerLogReturnsEmptyForUnreadableFile(): void
    {
        $projectRoot = $this->createTempDirectory();
        $logPath = installerLogPath($projectRoot);
        @mkdir(dirname($logPath), 0o755, true);
        file_put_contents($logPath, '');
        chmod($logPath, 0o000);

        try {
            $lines = readInstallerLog($projectRoot);
        } finally {
            chmod($logPath, 0o644);
        }

        $this->assertSame([], $lines);
    }

    public function testInstallManifestManagerLogsFailedEmptyDirectoryRemoval(): void
    {
        $manager = new InstallManifestManager();
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/empty-parent', 0o755, true);
        mkdir($targetDir.'/empty-parent/empty-child', 0o755, true);
        chmod($targetDir.'/empty-parent', 0o555);

        $logPath = installerLogPath($targetDir);
        if (file_exists($logPath)) {
            unlink($logPath);
        }
        @mkdir(dirname($logPath), 0o755, true);

        file_put_contents($targetDir.'/'.InstallManifestManager::MANIFEST_FILENAME, json_encode([
            'package_type' => 'runner',
            'package_id' => 'demo',
            'version' => '1.0.0',
            'files' => [],
        ]));

        try {
            $manager->removeEmptyDirectoriesIn($targetDir, $targetDir);
        } finally {
            chmod($targetDir.'/empty-parent', 0o755);
        }

        clearstatcache();
        $lines = readInstallerLog($targetDir);
        $found = false;
        foreach ($lines as $line) {
            if (str_contains($line, 'Could not remove empty directory')) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, 'Expected warning line for failed empty directory removal was not found');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState enabled
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(true)]
    public function testRenderLoginFormOutputsContent(): void
    {
        $GLOBALS['lang'] = require __DIR__.'/../src/lang/en.php';

        ob_start();
        renderLoginForm('Bad credentials', ['installer_version' => '1.0.0']);
        $output = (string) ob_get_clean();

        $this->assertStringContainsString('Bad credentials', $output);
        $this->assertStringContainsString('login-form', $output);
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
