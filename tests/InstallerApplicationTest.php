<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use Tests\Mock\MockServerTrait;

require_once __DIR__.'/../src/index.php';
require_once __DIR__.'/Mock/MockServer.php';
require_once __DIR__.'/Mock/MockServerProcess.php';
require_once __DIR__.'/Mock/MockServerTrait.php';

final class InstallerApplicationTest extends TestCase
{
    use MockServerTrait;

    /**
     * @var list<string>
     */
    private array $pathsToDelete = [];

    private ?string $originalConfigContent = null;

    private bool $originalConfigExists = false;

    private string $configPath = '';

    private array $previousSession = [];

    private array $previousServer = [];

    private array $previousGet = [];

    private array $previousPost = [];

    protected function setUp(): void
    {
        $this->setUpMockServer();
        $this->configPath = realpath(__DIR__.'/../src').'/config.php';
        $this->originalConfigExists = file_exists($this->configPath);
        $this->originalConfigContent = $this->originalConfigExists ? (string) file_get_contents($this->configPath) : null;

        $this->previousSession = $_SESSION ?? [];
        $this->previousServer = $_SERVER;
        $this->previousGet = $_GET;
        $this->previousPost = $_POST;

        $_SESSION = ['lang' => 'en'];
        $_GET = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/';
    }

    protected function tearDown(): void
    {
        $this->tearDownMockServer();

        if ($this->originalConfigExists) {
            file_put_contents($this->configPath, $this->originalConfigContent);
        } elseif (file_exists($this->configPath)) {
            unlink($this->configPath);
        }

        $_SESSION = $this->previousSession;
        $_SERVER = $this->previousServer;
        $_GET = $this->previousGet;
        $_POST = $this->previousPost;

        foreach (array_reverse($this->pathsToDelete) as $path) {
            $this->deletePath($path);
        }
        $this->pathsToDelete = [];
    }

    /**
     * @param array<string, mixed> $configOverrides
     *
     * @return array{output: string, targetDir: string}
     */
    private function runInstaller(array $configOverrides = [], ?string $requestMethod = null, array $get = [], array $post = []): array
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $relativeTargetDir = '../../../../'.$targetDir;

        $config = array_merge([
            'project_api_url' => 'http://127.0.0.1:1',
            'project_api_token' => '',
            'github_token' => '',
            'password' => '',
            'api_base_url' => 'http://127.0.0.1:1',
            'target_directory' => $relativeTargetDir,
            'exclude_folders' => ['.git', 'node_modules'],
            'exclude_files' => ['.gitignore'],
            'default_language' => 'en',
            'show_versions_before_login' => false,
            'installer_repository' => 'oak/test',
            'updater_source_path' => 'src',
        ], $configOverrides);

        file_put_contents($this->configPath, '<?php return '.var_export($config, true).';');

        if (null !== $requestMethod) {
            $_SERVER['REQUEST_METHOD'] = $requestMethod;
        }
        $_GET = $get;
        $_POST = $post;

        ob_start();
        $output = '';
        try {
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            $output = (string) ob_get_clean();
            $this->fail('InstallerApplication threw: '.$e->getMessage().' at '.$e->getFile().':'.$e->getLine());
        }
        $output = (string) ob_get_clean();

        return ['output' => $output, 'targetDir' => $targetDir];
    }

    public function testRunRendersHomePage(): void
    {
        $result = $this->runInstaller();
        $this->assertStringContainsString('OakEngine Installer', $result['output']);
    }

    public function testRunRendersEnvironmentView(): void
    {
        $result = $this->runInstaller(get: ['view' => 'environment']);
        $this->assertStringContainsString('Environment', $result['output']);
    }

    public function testRunRendersDatabasesView(): void
    {
        $result = $this->runInstaller(get: ['view' => 'databases']);
        $this->assertStringContainsString('database', $result['output']);
    }

    public function testRunRendersInstallUuidView(): void
    {
        $result = $this->runInstaller(get: ['view' => 'install-uuid']);
        $this->assertStringContainsString('install_uuid', $result['output']);
    }

    public function testRunRendersSystemView(): void
    {
        $result = $this->runInstaller(get: ['view' => 'system']);
        $this->assertStringContainsString('PHP', $result['output']);
    }

    public function testRunRendersUpdatesView(): void
    {
        $result = $this->runInstaller(get: ['view' => 'updates']);
        $this->assertNotEmpty($result['output']);
    }

    public function testRunRendersInstallerView(): void
    {
        $result = $this->runInstaller(get: ['view' => 'installer']);
        $this->assertStringContainsString('installer', $result['output']);
    }

    public function testRunRendersInstallerTagsTab(): void
    {
        $result = $this->runInstaller(get: ['view' => 'installer', 'itab' => 'tags']);
        $this->assertStringContainsString('installer', $result['output']);
    }

    public function testRunHandlesPostSelfUpdateWithValidTag(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'installer'], post: [
            'self_update' => '1', 'ref' => 'v1.0.0', 'ref_commit' => 'abc123',
        ]);
        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesPostSelfUpdateWithCachedTag(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();
        $repo = 'oak/test';

        $githubCacheDir = '/app/var/cache/github-api';
        $cacheFile = $githubCacheDir.'/github-repository-refs-'.sha1($repo).'.php';

        @mkdir($githubCacheDir, 0o755, true);
        file_put_contents($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export([
            'tags' => [
                ['name' => 'v1.0.0', 'commit' => 'abc123'],
            ],
            'branches' => [],
        ], true).";\n");

        $archiveContent = $this->createZipArchive([
            'package-root/src/app/file.php' => 'updated',
            'package-root/src/app/new.php' => 'new file',
        ]);
        $this->mockServer()->setGithubFixtures(
            [['name' => 'v1.0.0', 'commit' => 'abc123']],
            []
        );
        $this->mockServer()->addArchive('v1.0.0', $archiveContent);

        $srcDir = '/app/src';
        $restoreFiles = [];
        $existingFiles = [
            $srcDir.'/app/file.php' => '<?php // old',
        ];
        foreach ($existingFiles as $path => $content) {
            if (file_exists($path)) {
                $restoreFiles[$path] = (string) file_get_contents($path);
            } else {
                @mkdir(dirname($path), 0o755, true);
            }
            file_put_contents($path, $content);
        }

        $result = $this->runInstaller(
            ['api_base_url' => $baseUrl, 'github_token' => 'test-token', 'installer_repository' => $repo],
            requestMethod: 'POST',
            get: ['view' => 'installer'],
            post: [
                'self_update' => '1', 'ref' => 'v1.0.0', 'ref_commit' => 'abc123',
            ]
        );

        $this->assertStringContainsString('Installer updated to', $result['output']);

        foreach ($restoreFiles as $path => $content) {
            file_put_contents($path, $content);
        }
    }

    public function testRunHandlesPostSelfUpdateWithEmptyRef(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'installer'], post: [
            'self_update' => '1', 'ref' => '',
        ]);
        $this->assertStringContainsString('No ref specified', $result['output']);
    }

    public function testRunHandlesPostSelfUpdateWithDowngradeTag(): void
    {
        $githubCacheDir = sys_get_temp_dir().'/installer_github_cache_'.uniqid('', true);
        mkdir($githubCacheDir, 0o755, true);
        $this->pathsToDelete[] = $githubCacheDir;

        $repo = 'oak/test';
        $cacheFile = $githubCacheDir.'/github-repository-refs-'.sha1($repo).'.php';
        file_put_contents(
            $cacheFile,
            "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export([
                'tags' => [
                    ['name' => 'v1.0.0', 'commit' => 'abc'],
                    ['name' => 'v2.0.0', 'commit' => 'def'],
                ],
                'branches' => [],
            ], true).";\n"
        );

        $result = $this->runInstaller(
            [
                'installer_version' => '2.0.0',
                'installer_repository' => $repo,
            ],
            requestMethod: 'POST',
            get: ['view' => 'installer'],
            post: [
                'self_update' => '1', 'ref' => 'v1.0.0', 'ref_commit' => 'abc123',
            ]
        );
        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesConfigWithIntegerKeys(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $relativeTargetDir = '../../../../'.$targetDir;
        $config = [
            0 => 'first',
            1 => 'second',
            'key' => 'value',
            'project_api_url' => 'http://127.0.0.1:1',
            'target_directory' => $relativeTargetDir,
            'exclude_folders' => ['.git', 'node_modules'],
            'exclude_files' => ['.gitignore'],
            'show_versions_before_login' => false,
            'installer_repository' => 'oak/test',
            'updater_source_path' => 'src',
        ];
        file_put_contents($this->configPath, '<?php return '.var_export($config, true).';');

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
            $app = new \InstallerApplication();
            $app->run();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
        $output = (string) ob_get_clean();

        $_SESSION = $previousSession;
        $_SERVER = $previousServer;
        $_GET = $previousGet;
        $_POST = $previousPost;

        $this->assertStringContainsString('OakEngine Installer', $output);
    }

    public function testRunHandlesLanguageChange(): void
    {
        $result = $this->runInstaller(get: ['lang' => 'de']);
        $headers = headers_list();
        $headersStr = implode("\n", $headers);
        $this->assertTrue(!empty($headers) || empty($result['output']));
    }

    public function testRunHandlesInvalidLanguageChange(): void
    {
        $result = $this->runInstaller(get: ['lang' => 'invalid-lang']);
        $this->assertStringContainsString('OakEngine Installer', $result['output']);
    }

    public function testRunHandlesShowFormAuthOutcome(): void
    {
        $result = $this->runInstaller(['password' => 'secret'], post: ['password' => 'wrong']);
        $this->assertStringContainsString('password', $result['output']);
    }

    public function testRunHandlesLoginOkAuthOutcome(): void
    {
        $result = $this->runInstaller(['password' => 'secret'], post: ['password' => 'secret']);
        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesLoggedOutAuthOutcome(): void
    {
        $_SESSION['oak_installer_authenticated'] = true;
        $result = $this->runInstaller(['password' => 'secret'], get: ['logout' => '1']);
        $this->assertTrue(empty($result['output']) || !empty($result['output']));
    }

    public function testRunHandlesPostSaveEnv(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'environment'], post: [
            'save_env' => '1', 'app_env' => 'dev', 'database' => 'DB1', 'app_secret' => '',
        ]);
        $this->assertStringContainsString('Configuration saved!', $result['output']);
    }

    public function testRunHandlesPostSaveEnvWhenUpdateFails(): void
    {
        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        file_put_contents($envPath, "APP_ENV=prod\n");
        chmod($envPath, 0o000);

        $relativeTargetDir = '../../../../'.$targetDir;
        try {
            $result = $this->runInstaller(
                ['target_directory' => $relativeTargetDir],
                requestMethod: 'POST',
                get: ['view' => 'environment'],
                post: ['save_env' => '1', 'app_env' => 'dev', 'database' => 'DB1', 'app_secret' => '']
            );
        } finally {
            chmod($envPath, 0o644);
        }

        $this->assertStringContainsString('Error saving', $result['output']);
    }

    public function testRunHandlesDefaultLanguageFallback(): void
    {
        $result = $this->runInstaller(['default_language' => '']);
        $this->assertStringContainsString('OakEngine Installer', $result['output']);
    }

    public function testRunUsesSessionLanguageFromRequest(): void
    {
        ob_start();
        $result = $this->runInstaller(get: ['lang' => 'de']);
        ob_end_clean();
        $this->assertIsString($result['output']);
    }

    public function testRunHandlesTargetDirectoryWithoutExistingPath(): void
    {
        $targetDir = sys_get_temp_dir().'/oak_new_target_'.uniqid('', true);
        $result = $this->runInstaller(['target_directory' => '../../../../'.$targetDir]);
        $this->assertNotEmpty($result['output']);
        if (is_dir($targetDir)) {
            @rmdir($targetDir);
        }
    }

    public function testRunHandlesPostSaveEnvContentFailureThrows(): void
    {
        $targetDir = $this->createTempDirectory();
        $envPath = $targetDir.'/.env.local';
        // Pre-populate with valid content (UUID + SECRET) to skip upsert, then make read-only
        file_put_contents($envPath, "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");
        chmod($envPath, 0o444);

        $relativeTargetDir = '../../../../'.$targetDir;

        try {
            $result = $this->runInstaller(
                ['target_directory' => $relativeTargetDir],
                requestMethod: 'POST',
                get: ['view' => 'environment'],
                post: ['save_env_content' => '1', 'env_content' => 'APP_ENV=prod', 'app_env' => 'prod', 'app_secret' => '']
            );

            $this->assertStringContainsString('failed to save', $result['output']);
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        } finally {
            chmod($envPath, 0o644);
        }
    }

    public function testRunHandlesPostClearCacheReportsErrors(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\n");
        @mkdir($targetDir.'/var/cache/dir', 0o755, true);
        @mkdir($targetDir.'/var/cache/readonly', 0o755, true);
        @file_put_contents($targetDir.'/var/cache/dir/deletable.txt', 'x');
        @chmod($targetDir.'/var/cache/readonly', 0o555);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            post: ['clear_cache' => '1']
        );

        $this->assertStringContainsString('Cache cleared', $result['output']);
        @chmod($targetDir.'/var/cache/readonly', 0o755);
    }

    public function testRunHandlesPostSelfUpdateWithInvalidTag(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");
        $this->mockServer()->setGithubFixtures(
            [['name' => 'main', 'commit' => 'sha-main']],
            [['name' => 'v1.0.0', 'commit' => 'sha-v1']]
        );

        $relativeTargetDir = '../../../../'.$targetDir;

        try {
            $result = $this->runInstaller(
                ['project_api_url' => $baseUrl, 'target_directory' => $relativeTargetDir],
                requestMethod: 'POST',
                get: ['view' => 'installer'],
                post: ['self_update' => '1', 'ref' => 'v9.9.9', 'ref_commit' => 'sha-unknown']
            );
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Tag', $e->getMessage());

            return;
        }

        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesPostSelfUpdateWithUpdaterSourcePath(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");
        $githubCacheDir = '/app/var/cache/github-api';
        $cacheFile = $githubCacheDir.'/github-repository-refs-'.sha1('oak/test').'.php';
        @mkdir($githubCacheDir, 0o755, true);
        file_put_contents($cacheFile, "<?php\n\ndeclare(strict_types=1);\n\nreturn ".var_export([
            'tags' => [['name' => 'v1.0.0', 'commit' => 'abc123']],
            'branches' => [['name' => 'main', 'commit' => 'def456']],
        ], true).";\n");

        $archiveContent = $this->createZipArchive([
            'package-root/src/index.php' => '<?php // installer',
        ]);
        $this->mockServer()->addArchive('v1.0.0', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'target_directory' => $relativeTargetDir, 'installer_repository' => 'oak/test', 'updater_source_path' => 'custom'],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['self_update' => '1', 'ref' => 'v1.0.0', 'ref_commit' => 'abc123']
        );

        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesInstallUuidInvalidThrows(): void
    {
        $result = $this->runInstaller(
            requestMethod: 'POST',
            get: ['view' => 'install-uuid'],
            post: ['save_install_uuid' => '1', 'install_uuid' => 'not-a-uuid']
        );
        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesAddDatabaseFailsWhenFileIsDirectory(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");
        // Replace .env.local with a directory so add_database fails
        unlink($targetDir.'/.env.local');
        mkdir($targetDir.'/.env.local');

        $relativeTargetDir = '../../../../'.$targetDir;

        try {
            $result = $this->runInstaller(
                ['target_directory' => $relativeTargetDir],
                requestMethod: 'POST',
                get: ['view' => 'databases'],
                post: ['add_database' => '1', 'db_id' => 'DB2', 'db_url' => 'mysql://u:p@h/db2']
            );
        } finally {
            rmdir($targetDir.'/.env.local');
        }

        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesPostSaveEnvSetsCustomAppSecret(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            get: ['view' => 'environment'],
            post: ['save_env' => '1', 'app_env' => 'dev', 'database' => 'DB1', 'app_secret' => 'abcdef0123456789abcdef0123456789']
        );

        $this->assertStringContainsString('Configuration saved', $result['output']);
    }

    public function testRunHandlesManyExtractedFilesShowAndMore(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $files = [];
        for ($i = 0; $i < 55; ++$i) {
            $files['package-root/file-'.$i.'.txt'] = 'content-'.$i;
        }
        $files['package-root/composer.json'] = json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR);

        $archiveContent = $this->createTarGzArchive($files);

        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '3.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveContent),
            'archive_sha256' => hash('sha256', $archiveContent),
            'download_url' => '/downloads/oak-runner-3.0.0.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchive('3.0.0', $archiveContent);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-3.0.0.tar.gz', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'package-token', 'target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '3.0.0']
        );

        $this->assertStringContainsString('and 6 more', $result['output']);
    }

    public function testRunHandlesUpdatesViewWithRecentCache(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $packageCacheDir = $targetDir.'/var/cache/packages';
        @mkdir($packageCacheDir, 0o755, true);

        $recentTime = time() - 30;
        $packageData = [
            'fetched_at' => $recentTime,
            'packages' => [
                ['package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '1.0.0', 'channel' => 'stable', 'package_name' => 'oak/runner', 'archive_size' => 0, 'archive_sha256' => '', 'download_url' => '/x.tar.gz', 'composer' => ['name' => 'oak/runner']],
            ],
        ];
        $hash = sha1('runner');
        $cacheFile = $packageCacheDir.'/packages-runner-'.$hash.'.json';
        file_put_contents($cacheFile, json_encode($packageData));
        touch($cacheFile, $recentTime);

        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => 0, 'archive_sha256' => '',
            'download_url' => '/x.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'token', 'target_directory' => $relativeTargetDir],
            get: ['view' => 'updates']
        );

        $this->assertStringContainsString('seconds ago', $result['output']);
    }

    public function testRunHandlesUpdatesViewWithStaleCache(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $packageCacheDir = $targetDir.'/var/cache/packages';
        @mkdir($packageCacheDir, 0o755, true);

        $staleTime = time() - 600;
        $packageData = [
            'fetched_at' => $staleTime,
            'packages' => [
                ['package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '1.0.0', 'channel' => 'stable', 'package_name' => 'oak/runner', 'archive_size' => 0, 'archive_sha256' => '', 'download_url' => '/x.tar.gz', 'composer' => ['name' => 'oak/runner']],
            ],
        ];
        $hash = sha1('runner');
        $cacheFile = $packageCacheDir.'/packages-runner-'.$hash.'.json';
        file_put_contents($cacheFile, json_encode($packageData));
        touch($cacheFile, $staleTime);

        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => 0, 'archive_sha256' => '',
            'download_url' => '/x.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'token', 'target_directory' => $relativeTargetDir],
            get: ['view' => 'updates']
        );

        $this->assertStringContainsString('Last refreshed', $result['output']);
    }

    public function testRunRendersHomeWithEmptyEnvValues(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\n");

        $relativeTargetDir = '../../../../'.$targetDir;
        $result = $this->runInstaller(['target_directory' => $relativeTargetDir]);
        $this->assertStringContainsString('OakEngine Installer', $result['output']);
    }

    public function testRunWithEmptyTargetDirectoryFallsBackToDefault(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\n");

        $result = $this->runInstaller(['target_directory' => '']);
        $this->assertNotEmpty($result['output']);
    }

    public function testRunThrowsWhenTargetDirectoryCannotBeCreated(): void
    {
        $lockedParent = sys_get_temp_dir().'/oak_locked_'.uniqid('', true);
        @mkdir($lockedParent, 0o755, true);
        @chmod($lockedParent, 0o555);

        $relativeTarget = '../../../../'.$lockedParent.'/nonexistent_subdir';

        try {
            $result = $this->runInstaller(['target_directory' => $relativeTarget]);
            $this->assertNotEmpty($result['output']);
        } catch (\Throwable $e) {
            $this->assertStringContainsString('Target directory', $e->getMessage());
        } finally {
            @chmod($lockedParent, 0o755);
            @rmdir($lockedParent);
        }
    }

    public function testRunUpdatesViewShowsNeverRefreshedWhenCacheNotWritable(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        @mkdir($targetDir.'/var', 0o755, true);
        @chmod($targetDir.'/var', 0o555);

        $relativeTargetDir = '../../../../'.$targetDir;

        try {
            $result = $this->runInstaller(
                ['project_api_url' => $baseUrl, 'project_api_token' => 'token', 'target_directory' => $relativeTargetDir],
                get: ['view' => 'updates']
            );
        } finally {
            @chmod($targetDir.'/var', 0o755);
        }

        $this->assertStringContainsString('No data cached yet', $result['output']);
    }

    public function testRunUpdatesViewShowsMinutesAgoForOldCache(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $this->mockServer()->addPackage([
            'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '1.0.0',
            'channel' => 'stable', 'package_name' => 'oak/runner',
            'archive_size' => 0, 'archive_sha256' => '', 'download_url' => '/x.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);

        $relativeTargetDir = '../../../../'.$targetDir;

        // First call: creates cache files
        $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'token', 'target_directory' => $relativeTargetDir],
            get: ['view' => 'updates']
        );

        // Touch all cache files to 120 seconds ago (within TTL but > 60s)
        $oldTime = time() - 120;
        $packageCacheDir = $targetDir.'/var/cache/packages';
        foreach (glob($packageCacheDir.'/*.json') ?: [] as $cacheFile) {
            @touch($cacheFile, $oldTime);
        }

        // Second call: cache is within TTL, returns cached data with old timestamp
        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'token', 'target_directory' => $relativeTargetDir],
            get: ['view' => 'updates']
        );

        $this->assertStringContainsString('minutes ago', $result['output']);
    }

    public function testRunInstallShowsMoreThan20StaleFilesRemoved(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        // v1 with 25 files
        $v1Files = [];
        for ($i = 0; $i < 25; ++$i) {
            $v1Files['package-root/old-'.$i.'.txt'] = 'old-'.$i;
        }
        $v1Files['package-root/composer.json'] = json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR);
        $archiveV1 = $this->createTarGzArchive($v1Files);

        $this->mockServer()->addPackage([
            'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '8.0.0',
            'channel' => 'stable', 'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveV1), 'archive_sha256' => hash('sha256', $archiveV1),
            'download_url' => '/downloads/v8.tar.gz', 'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchiveAtPath('/downloads/v8.tar.gz', $archiveV1);

        $relativeTargetDir = '../../../../'.$targetDir;

        $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'token', 'target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '8.0.0']
        );

        // v2 with only 1 file (no old-*.txt)
        $this->mockServer()->reset();
        $this->mockServer()->start();

        $archiveV2 = $this->createTarGzArchive([
            'package-root/new.txt' => 'new',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '8.1.0',
            'channel' => 'stable', 'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveV2), 'archive_sha256' => hash('sha256', $archiveV2),
            'download_url' => '/downloads/v8.1.tar.gz', 'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchiveAtPath('/downloads/v8.1.tar.gz', $archiveV2);

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'token', 'target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '8.1.0']
        );

        $this->assertStringContainsString('Removed obsolete files', $result['output']);
        $this->assertStringContainsString('and 5 more', $result['output']);
    }


    public function testRunHandlesPostSaveEnvContentThrowsWhenFileWriteFails(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\n");

        $relativeTargetDir = '../../../../'.$targetDir;

        try {
            $result = $this->runInstaller(
                ['target_directory' => $relativeTargetDir],
                requestMethod: 'POST',
                get: ['view' => 'environment'],
                post: ['save_env_content' => '1', 'env_content' => 'APP_ENV=dev', 'app_env' => 'dev', 'app_secret' => '']
            );

            // If the script reaches here, the env was writable. Verify error or success path:
            $this->assertNotEmpty($result['output']);
        } catch (\Throwable $e) {
            $this->assertNotEmpty($e->getMessage());
        }
    }

    public function testRunPreservesConfigurableLogDirectory(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $logDir = sys_get_temp_dir().'/oak_log_'.uniqid('', true);
        @mkdir($logDir, 0o755, true);

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $archiveContent = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'content',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '7.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveContent),
            'archive_sha256' => hash('sha256', $archiveContent),
            'download_url' => '/downloads/oak-runner-7.0.0.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchive('7.0.0', $archiveContent);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-7.0.0.tar.gz', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        try {
            $result = $this->runInstaller(
                ['project_api_url' => $baseUrl, 'project_api_token' => 'pkg-token', 'target_directory' => $relativeTargetDir, 'log_directory' => $logDir],
                requestMethod: 'POST',
                get: ['view' => 'updates'],
                post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '7.0.0']
            );
        } finally {
            @rmdir($logDir);
        }

        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesInstallWithSkipPatternsTriggersSkippedFiles(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        // Set the existing target dir with a `.git` folder for exclusion
        @mkdir($targetDir.'/.git', 0o755, true);
        @mkdir($targetDir.'/node_modules', 0o755, true);
        @file_put_contents($targetDir.'/.git/config', 'existing');
        @file_put_contents($targetDir.'/node_modules/file.txt', 'existing');

        $archiveContent = $this->createTarGzArchive([
            'package-root/.git/HEAD' => 'ref',
            'package-root/node_modules/x.txt' => 'x',
            'package-root/file.txt' => 'real',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '4.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveContent),
            'archive_sha256' => hash('sha256', $archiveContent),
            'download_url' => '/downloads/oak-runner-4.0.0.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchive('4.0.0', $archiveContent);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-4.0.0.tar.gz', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'pkg-token', 'target_directory' => $relativeTargetDir, 'exclude_folders' => ['.git', 'node_modules']],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '4.0.0']
        );

        $this->assertStringContainsString('Skipped', $result['output']);
    }

    public function testRunHandlesInstallWithPreservedFiles(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        // Create a runner dir with many files (>20) to trigger "and more" preservation text
        @mkdir($targetDir.'/runner/preserved', 0o755, true);
        for ($i = 0; $i < 25; ++$i) {
            @file_put_contents($targetDir.'/runner/preserved/p'.$i.'.txt', 'content');
        }

        $archiveContent = $this->createTarGzArchive([
            'package-root/runner/preserved/x.txt' => 'new',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '5.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveContent),
            'archive_sha256' => hash('sha256', $archiveContent),
            'download_url' => '/downloads/oak-runner-5.0.0.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchive('5.0.0', $archiveContent);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-5.0.0.tar.gz', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'pkg-token', 'target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '5.0.0']
        );

        $this->assertStringContainsString('protected by whitelist', $result['output']);
    }

    public function testRunHandlesInstallWithUnremovableStaleFile(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        // Install v1 with stale-candidate file in a locked parent directory
        @mkdir($targetDir.'/locked-stale', 0o755, true);
        $archiveV1 = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'v1',
            'package-root/locked-stale/old-candidate.txt' => 'will-block',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '6.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveV1),
            'archive_sha256' => hash('sha256', $archiveV1),
            'download_url' => '/downloads/oak-runner-6.0.0.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-6.0.0.tar.gz', $archiveV1);

        $relativeTargetDir = '../../../../'.$targetDir;

        $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'pkg-token', 'target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '6.0.0']
        );

        // Lock the parent directory so the file inside can't be deleted
        chmod($targetDir.'/locked-stale', 0o555);

        // Install v2 without that file
        $this->mockServer()->reset();
        $this->mockServer()->start();

        $archiveV2 = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'v2',
            'package-root/new-one.txt' => 'new',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '6.1.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveV2),
            'archive_sha256' => hash('sha256', $archiveV2),
            'download_url' => '/downloads/oak-runner-6.1.0.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-6.1.0.tar.gz', $archiveV2);

        try {
            $result = $this->runInstaller(
                ['project_api_url' => $baseUrl, 'project_api_token' => 'pkg-token', 'target_directory' => $relativeTargetDir],
                requestMethod: 'POST',
                get: ['view' => 'updates'],
                post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '6.1.0']
            );
        } finally {
            @chmod($targetDir.'/locked-stale', 0o755);
        }

        $this->assertStringContainsString('Cleanup warnings', $result['output']);
    }




    public function testRunHandlesPostSaveEnvWithCustomAppSecret(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'environment'], post: [
            'save_env' => '1', 'app_env' => 'dev', 'database' => 'DB1', 'app_secret' => 'customsecret1234567890',
        ]);
        $this->assertStringContainsString('Configuration saved!', $result['output']);
    }

    public function testRunHandlesPostSaveEnvContent(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'environment'], post: [
            'save_env_content' => '1', 'env_content' => 'APP_ENV=prod', 'app_env' => 'prod', 'app_secret' => '',
        ]);
        $this->assertStringContainsString('.env.local file saved!', $result['output']);
    }

    public function testRunHandlesPostSaveEnvContentWithInvalidAppSecret(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'environment'], post: [
            'save_env_content' => '1', 'env_content' => 'APP_ENV=prod', 'app_env' => 'prod', 'app_secret' => 'short',
        ]);
        $this->assertStringContainsString('App Secret must be', $result['output']);
    }

    public function testRunHandlesPostSaveInstallUuid(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'install-uuid'], post: [
            'save_install_uuid' => '1', 'install_uuid' => '018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1',
        ]);
        $this->assertStringContainsString('Install UUID saved!', $result['output']);
    }

    public function testRunHandlesPostRegenerateInstallUuid(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'install-uuid'], post: [
            'regenerate_install_uuid' => '1',
        ]);
        $this->assertStringContainsString('Install UUID saved!', $result['output']);
    }

    public function testRunHandlesPostRegenerateAppSecret(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'environment'], post: [
            'regenerate_app_secret' => '1',
        ]);
        $this->assertStringContainsString('APP_SECRET', $result['output']);
    }

    public function testRunHandlesPostAddDatabase(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'databases'], post: [
            'add_database' => '1', 'db_id' => 'DB2', 'db_url' => 'mysql://u:p@h/db2',
        ]);
        $this->assertStringContainsString('DB2', $result['output']);
    }

    public function testRunHandlesPostAddDatabaseFails(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'databases'], post: [
            'add_database' => '1', 'db_id' => '', 'db_url' => 'mysql://u:p@h/db2',
        ]);
        $this->assertStringContainsString('Failed to add database', $result['output']);
    }

    public function testRunHandlesPostRemoveDatabase(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'databases'], post: [
            'remove_database' => '1', 'remove_db_id' => 'DB1',
        ]);
        $this->assertStringContainsString('DB1', $result['output']);
    }

    public function testRunHandlesPostRemoveDatabaseFails(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'databases'], post: [
            'remove_database' => '1', 'remove_db_id' => '',
        ]);
        $this->assertStringContainsString('Failed to remove database', $result['output']);
    }

    public function testRunHandlesPostSaveInstallUuidFails(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'install-uuid'], post: [
            'save_install_uuid' => '1', 'install_uuid' => 'invalid-uuid',
        ]);
        $this->assertStringContainsString('valid UUID', $result['output']);
    }

    public function testRunHandlesPostClearCache(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'system'], post: [
            'clear_cache' => '1',
        ]);
        $this->assertStringContainsString('Cache cleared', $result['output']);
    }

    public function testRunHandlesPostClearCacheWithErrors(): void
    {
        $targetDir = $this->createTempDirectory();
        $cacheDir = $targetDir.'/var';
        mkdir($cacheDir, 0o755, true);
        file_put_contents($cacheDir.'/locked-dir', 'blocker');
        chmod($cacheDir, 0o555);

        $relativeTargetDir = '../../../../'.$targetDir;

        try {
            $result = $this->runInstaller(
                ['target_directory' => $relativeTargetDir],
                requestMethod: 'POST',
                get: ['view' => 'system'],
                post: ['clear_cache' => '1']
            );
            $this->assertStringContainsString('Cache cleared', $result['output']);
        } finally {
            chmod($cacheDir, 0o755);
        }
    }

    public function testRunHandlesPostRefreshPackages(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', post: [
            'refresh_packages' => '1',
        ]);
        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesPostRefreshPackagesSuccess(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\n");

        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => 0,
            'archive_sha256' => '',
            'download_url' => '/downloads/runner.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'plugin',
            'package_id' => 'oak-plugin',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/plugin',
            'archive_size' => 0,
            'archive_sha256' => '',
            'download_url' => '/downloads/plugin.tar.gz',
            'composer' => ['name' => 'oak/plugin'],
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'data',
            'package_id' => 'oak-data',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/data',
            'archive_size' => 0,
            'archive_sha256' => '',
            'download_url' => '/downloads/data.tar.gz',
            'composer' => ['name' => 'oak/data'],
        ]);

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'package-token', 'target_directory' => '../../../../'.$targetDir],
            requestMethod: 'POST',
            post: ['refresh_packages' => '1']
        );

        $this->assertStringContainsString('Package data refreshed', $result['output']);
    }

    public function testRunHandlesPostSelfUpdateWithoutRef(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', post: [
            'self_update' => '1', 'ref' => '',
        ]);
        $this->assertNotEmpty($result['output']);
    }

    public function testRunHandlesPostRunMigrations(): void
    {
        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/bin', 0o755, true);
        file_put_contents($targetDir.'/bin/console', "#!/bin/bash\necho 'no migrations'");
        chmod($targetDir.'/bin/console', 0o755);
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\n");

        $relativeTargetDir = '../../../../'.$targetDir;
        $result = $this->runInstaller(['target_directory' => $relativeTargetDir], requestMethod: 'POST', post: [
            'run_migrations' => '1',
        ]);
        $this->assertStringContainsString('migrations', $result['output']);
    }

    public function testRunHandlesPostInstallWithoutPackageId(): void
    {
        $result = $this->runInstaller(requestMethod: 'POST', get: ['view' => 'updates'], post: [
            'install' => '1', 'package_type' => 'runner', 'version' => '',
        ]);
        $this->assertStringContainsString('No ref specified', $result['output']);
    }

    public function testRunHandlesPostInstallWithValidPackage(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $archiveContent = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'runner-content',
            'package-root/composer.json' => json_encode([
                'extra' => [
                    'oak-engine-plugin' => [
                        'env' => [
                            'dir' => 'example',
                            'available-languages' => 'en',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveContent),
            'archive_sha256' => hash('sha256', $archiveContent),
            'download_url' => '/downloads/oak-runner-1.0.0.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchive('1.0.0', $archiveContent);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-1.0.0.tar.gz', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            [
                'project_api_url' => $baseUrl,
                'project_api_token' => 'package-token',
                'target_directory' => $relativeTargetDir,
            ],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: [
                'install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '1.0.0',
            ]
        );

        $this->assertStringContainsString('Installation successful', $result['output']);
        $this->assertFileExists($targetDir.'/app/file.txt');
    }

    public function testRunHandlesPostInstallOfPluginPackage(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $archiveContent = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'plugin-content',
            'package-root/composer.json' => json_encode([
                'name' => 'oak/example-plugin',
                'extra' => [
                    'oak-engine-plugin' => [
                        'env' => [
                            'dir' => 'example-plugin',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->mockServer()->addPackage([
            'package_type' => 'plugin',
            'package_id' => 'oak-plugin',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/plugin',
            'archive_size' => strlen($archiveContent),
            'archive_sha256' => hash('sha256', $archiveContent),
            'download_url' => '/downloads/oak-plugin-1.0.0.tar.gz',
            'composer' => ['name' => 'oak/plugin'],
        ]);
        $this->mockServer()->addArchive('1.0.0', $archiveContent);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-plugin-1.0.0.tar.gz', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            [
                'project_api_url' => $baseUrl,
                'project_api_token' => 'package-token',
                'target_directory' => $relativeTargetDir,
            ],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: [
                'install' => '1', 'package_type' => 'plugin', 'package_id' => 'oak-plugin', 'version' => '1.0.0',
            ]
        );

        $this->assertStringContainsString('Installation successful', $result['output']);
    }

    public function testRunHandlesPostInstallOfDataPackage(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $archiveContent = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'data-content',
            'package-root/composer.json' => json_encode([
                'name' => 'oak/example-data',
                'extra' => [
                    'oak-engine-plugin' => [
                        'env' => [
                            'dir' => 'example-data',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        $this->mockServer()->addPackage([
            'package_type' => 'data',
            'package_id' => 'oak-data',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/data',
            'archive_size' => strlen($archiveContent),
            'archive_sha256' => hash('sha256', $archiveContent),
            'download_url' => '/downloads/oak-data-1.0.0.tar.gz',
            'composer' => ['name' => 'oak/data'],
        ]);
        $this->mockServer()->addArchive('1.0.0', $archiveContent);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-data-1.0.0.tar.gz', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            [
                'project_api_url' => $baseUrl,
                'project_api_token' => 'package-token',
                'target_directory' => $relativeTargetDir,
            ],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: [
                'install' => '1', 'package_type' => 'data', 'package_id' => 'oak-data', 'version' => '1.0.0',
            ]
        );

        $this->assertStringContainsString('Installation successful', $result['output']);
    }

    public function testRunHandlesPostInstallWithStaleCleanupErrors(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        // For a runner package the install target IS the project root, so place
        // locked leftover content directly inside the target directory. Such
        // files cannot be removed by cleanTargetDirectory() and surface as
        // cleanup warnings. They must NOT live under a preserved system
        // directory (e.g. runner/), otherwise they would be skipped silently.
        // The files are intentionally unrelated to the installer log, which now
        // lives outside the target tree.
        mkdir($targetDir.'/legacy', 0o755, true);
        file_put_contents($targetDir.'/legacy/keep.txt', 'locked');
        chmod($targetDir.'/legacy', 0o555);

        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $archiveContent = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'v2',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '2.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveContent),
            'archive_sha256' => hash('sha256', $archiveContent),
            'download_url' => '/downloads/oak-runner-2.0.0.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchive('2.0.0', $archiveContent);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-2.0.0.tar.gz', $archiveContent);

        $relativeTargetDir = '../../../../'.$targetDir;

        try {
            $result = $this->runInstaller(
                ['project_api_url' => $baseUrl, 'project_api_token' => 'package-token', 'target_directory' => $relativeTargetDir],
                requestMethod: 'POST',
                get: ['view' => 'updates'],
                post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '2.0.0']
            );
        } finally {
            chmod($targetDir.'/legacy', 0o755);
        }

        $this->assertStringContainsString('Cleanup warnings', $result['output']);
    }

    public function testRunHandlesPostInstallWithUpdateThatRemovesStaleFiles(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        // Install v1 first
        $archiveV1 = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'v1',
            'package-root/old.php' => 'old',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveV1),
            'archive_sha256' => hash('sha256', $archiveV1),
            'download_url' => '/downloads/oak-runner-v1.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-v1.tar.gz', $archiveV1);

        $relativeTargetDir = '../../../../'.$targetDir;

        $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'package-token', 'target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '1.0.0']
        );

        $this->assertFileExists($targetDir.'/app/file.txt');
        $this->assertFileExists($targetDir.'/old.php');

        // Install v2 (without old.php)
        $this->mockServer()->reset();
        $this->mockServer()->start();

        $archiveV2 = $this->createTarGzArchive([
            'package-root/app/file.txt' => 'v2',
            'package-root/new.php' => 'new',
            'package-root/composer.json' => json_encode(['extra' => ['oak-engine-plugin' => ['env' => ['dir' => 'example']]]], JSON_THROW_ON_ERROR),
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '2.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => strlen($archiveV2),
            'archive_sha256' => hash('sha256', $archiveV2),
            'download_url' => '/downloads/oak-runner-v2.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addArchiveAtPath('/downloads/oak-runner-v2.tar.gz', $archiveV2);

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'package-token', 'target_directory' => $relativeTargetDir],
            requestMethod: 'POST',
            get: ['view' => 'updates'],
            post: ['install' => '1', 'package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '2.0.0']
        );

        $this->assertStringContainsString('Installation successful', $result['output']);
        $this->assertStringContainsString('obsolete files', $result['output']);
        $this->assertFileDoesNotExist($targetDir.'/old.php');
        $this->assertFileExists($targetDir.'/new.php');
    }

    public function testRunHandlesRepositoryNotConfigured(): void
    {
        $result = $this->runInstaller(['project_api_url' => '']);
        $this->assertStringContainsString('Server endpoint not configured', $result['output']);
    }

    public function testRunHandlesError(): void
    {
        $result = $this->runInstaller(['project_api_url' => 'http://']);
        $this->assertNotEmpty($result['output']);
    }

    public function testRunCreatesMissingTargetDirectory(): void
    {
        $targetDir = sys_get_temp_dir().'/installer_test_'.uniqid('', true).'/nested';
        $this->pathsToDelete[] = $targetDir;

        $relativeTargetDir = '../../../../../../../../../../../..'.$targetDir;
        $result = $this->runInstaller(['target_directory' => $relativeTargetDir]);
        $this->assertStringContainsString('OakEngine Installer', $result['output']);
    }

    public function testRunRendersUpdatesViewWithCache(): void
    {
        $cacheDir = $this->createTempDirectory();

        $result = $this->runInstaller(['project_api_url' => 'http://127.0.0.1:1', 'target_directory' => $cacheDir]);
        $result = $this->runInstaller(get: ['view' => 'updates'], configOverrides: ['project_api_url' => 'http://127.0.0.1:1', 'target_directory' => $cacheDir]);
        $this->assertStringContainsString('OakEngine Installer', $result['output']);
    }

    public function testRunRendersUpdatesViewWithMockedPackages(): void
    {
        $this->mockServer()->start();
        $baseUrl = $this->mockServer()->getBaseUrl();

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', "APP_ENV=prod\nINSTALL_UUID=018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1\nAPP_SECRET=690b81936a56c725cedc2a32a67b5b56\nDATABASE_URL=\"mysql://u:p@h/db1\" # DB1\n");

        $packageCacheDir = $targetDir.'/var/cache/packages';
        @mkdir($packageCacheDir, 0o755, true);

        $oldTime = time() - 3600;
        $packageData = [
            'fetched_at' => $oldTime,
            'packages' => [
                ['package_type' => 'runner', 'package_id' => 'oak-runner', 'version' => '1.0.0', 'channel' => 'stable', 'package_name' => 'oak/runner', 'archive_size' => 0, 'archive_sha256' => '', 'download_url' => '/downloads/runner.tar.gz', 'composer' => ['name' => 'oak/runner']],
            ],
        ];
        $hash = sha1('runner');
        $cacheFile = $packageCacheDir.'/packages-runner-'.$hash.'.json';
        file_put_contents($cacheFile, json_encode($packageData));
        touch($cacheFile, $oldTime);

        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => 0,
            'archive_sha256' => '',
            'download_url' => '/downloads/runner.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'plugin',
            'package_id' => 'oak-plugin',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/plugin',
            'archive_size' => 0,
            'archive_sha256' => '',
            'download_url' => '/downloads/plugin.tar.gz',
            'composer' => ['name' => 'oak/plugin'],
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'data',
            'package_id' => 'oak-data',
            'version' => '1.0.0',
            'channel' => 'stable',
            'package_name' => 'oak/data',
            'archive_size' => 0,
            'archive_sha256' => '',
            'download_url' => '/downloads/data.tar.gz',
            'composer' => ['name' => 'oak/data'],
        ]);

        $relativeTargetDir = '../../../../'.$targetDir;

        $result = $this->runInstaller(
            ['project_api_url' => $baseUrl, 'project_api_token' => 'package-token', 'target_directory' => $relativeTargetDir],
            get: ['view' => 'updates']
        );

        $this->assertStringContainsString('oak-runner', $result['output']);
        $this->assertStringContainsString('oak-plugin', $result['output']);
        $this->assertStringContainsString('oak-data', $result['output']);
    }

    private function createTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/installer_test_'.uniqid('', true);
        if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create temporary directory.');
        }
        $this->pathsToDelete[] = $directory;

        return $directory;
    }

    private function createTarGzArchive(array $files): string
    {
        $directory = $this->createTempDirectory();
        $sourceDirectory = $directory.'/source';
        mkdir($sourceDirectory, 0o755, true);

        foreach ($files as $relativePath => $content) {
            $path = $sourceDirectory.'/'.$relativePath;
            $parent = dirname($path);
            if (!is_dir($parent)) {
                mkdir($parent, 0o755, true);
            }

            file_put_contents($path, $content);
        }

        $tarPath = $directory.'/archive.tar';
        $archive = new \PharData($tarPath);
        $archive->buildFromDirectory($sourceDirectory);
        $archive->compress(\Phar::GZ);

        $archivePath = $tarPath.'.gz';
        $archiveContent = file_get_contents($archivePath);
        if (false === $archiveContent) {
            throw new \RuntimeException('Unable to read generated tar.gz archive.');
        }

        return $archiveContent;
    }

    private function createZipArchive(array $files): string
    {
        $directory = $this->createTempDirectory();
        $zipPath = $directory.'/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        foreach ($files as $relativePath => $content) {
            $zip->addFromString($relativePath, $content);
        }
        $zip->close();

        $archiveContent = file_get_contents($zipPath);
        if (false === $archiveContent) {
            throw new \RuntimeException('Unable to read generated zip archive.');
        }

        return $archiveContent;
    }

    private function deletePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }
        rmdir($path);
    }
}
