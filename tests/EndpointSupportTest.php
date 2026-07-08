<?php

declare(strict_types=1);

namespace Tests;

use Oak\Engine\Installer\InstallUuidManager;
use Oak\Engine\Installer\ProjectPackageApiClient;
use Oak\Engine\Installer\ProjectPackageArchiveExtractor;
use Phar;
use PharData;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class EndpointSupportTest extends TestCase
{
    private static mixed $serverProcess = null;
    private static ?string $serverDirectory = null;
    private static ?string $baseUrl = null;

    /**
     * @var list<string>
     */
    private array $pathsToDelete = [];

    public static function setUpBeforeClass(): void
    {
        $directory = sys_get_temp_dir().'/installer_api_'.uniqid('', true);
        if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create temporary API directory.');
        }

        $router = <<<'PHP'
<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$query = [];
parse_str(parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY) ?? '', $query);
$packageType = (string) ($query['package_type'] ?? $query['type'] ?? 'runner');
$version = (string) ($query['version'] ?? '1.0.0');
$installUuid = (string) ($_POST['install_uuid'] ?? '');
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$hasAccess = '018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1' === $installUuid || 'Bearer package-token' === $authorization;

$packages = [
    [
        'package_type' => 'runner',
        'package_id' => 'oak-runner',
        'version' => '1.0.0',
        'channel' => 'stable',
        'package_name' => 'oak/runner',
        'archive_size' => 1234,
        'archive_sha256' => 'sha-runner',
        'download_url' => '/downloads/oak-runner-1.0.0.tar.gz',
        'composer' => ['name' => 'oak/runner'],
    ],
    [
        'package_type' => 'plugin',
        'package_id' => 'oak-plugin',
        'version' => '2.0.0',
        'channel' => 'beta',
        'package_name' => 'oak/plugin',
        'archive_size' => 5678,
        'archive_sha256' => 'sha-plugin',
        'download_url' => '/downloads/oak-plugin-2.0.0.tar.gz',
        'composer' => ['name' => 'oak/plugin'],
    ],
    [
        'package_id' => 'oak-untyped',
        'version' => '9.9.9',
        'channel' => 'stable',
        'package_name' => 'oak/untyped',
        'archive_size' => 42,
        'archive_sha256' => 'sha-untyped',
        'download_url' => '/downloads/oak-untyped-9.9.9.tar.gz',
        'composer' => ['name' => 'oak/untyped'],
    ],
];

if ('/' === $path || '/packages' === $path) {
    if (!$hasAccess) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'packages' => array_values(array_filter($packages, static fn (array $package): bool => ($package['package_type'] ?? null) === $packageType)),
    ]);
    return;
}

if ('/mixed' === $path) {
    if (!$hasAccess) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode([
        'packages' => $packages,
    ]);
    return;
}

if ('/packages/runner/oak-runner' === $path) {
    if (!$hasAccess) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        return;
    }

    header('Content-Type: application/json');
    echo json_encode($packages[0]);
    return;
}

if ('/downloads/oak-runner-1.0.0.tar.gz' === $path) {
    if ('018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1' !== ($_SERVER['HTTP_X_INSTALL_UUID'] ?? '') && 'Bearer package-token' !== $authorization) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'forbidden']);
        return;
    }

    header('Content-Type: application/octet-stream');
    echo 'runner-package-'.$version.'-'.($_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['HTTP_X_INSTALL_UUID'] ?? 'missing'));
    return;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'not found']);
PHP;

        file_put_contents($directory.'/router.php', $router);
        $port = self::findFreePort();
        $command = sprintf('php -S 127.0.0.1:%d %s', $port, escapeshellarg($directory.'/router.php'));
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $directory.'/server.log', 'a'],
            2 => ['file', $directory.'/server-error.log', 'a'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $directory);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start test API server.');
        }

        foreach ($pipes as $pipe) {
            fclose($pipe);
        }

        self::$serverDirectory = $directory;
        self::$serverProcess = $process;
        self::$baseUrl = sprintf('http://127.0.0.1:%d', $port);

        $attempt = 0;
        while ($attempt < 100) {
            $response = @file_get_contents(
                (string) self::$baseUrl,
                false,
                stream_context_create([
                    'http' => [
                        'method' => 'POST',
                        'timeout' => 2,
                        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                        'content' => http_build_query([
                            'type' => 'runner',
                            'install_uuid' => '018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1',
                        ]),
                    ],
                ]),
            );
            if (false !== $response) {
                return;
            }

            usleep(100000);
            ++$attempt;
        }

        throw new RuntimeException('Test API server did not become ready in time.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$serverProcess)) {
            proc_terminate(self::$serverProcess);
            proc_close(self::$serverProcess);
        }

        if (null !== self::$serverDirectory) {
            self::deleteDirectory(self::$serverDirectory);
        }
    }

    public function tearDown(): void
    {
        foreach (array_reverse($this->pathsToDelete) as $path) {
            self::deleteDirectory($path);
        }

        $this->pathsToDelete = [];
    }

    public function testInstallUuidManagerEnsuresAndRegeneratesUuid(): void
    {
        $manager = new InstallUuidManager();
        $envPath = $this->createTempDirectory().'/.env.local';

        $first = $manager->ensureEnvLocalInstallUuid($envPath);
        $second = $manager->ensureEnvLocalInstallUuid($envPath);
        $third = $manager->ensureEnvLocalInstallUuid($envPath, true);

        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $first);
        $this->assertSame($first, $second);
        $this->assertNotSame($second, $third);
        $this->assertStringContainsString('INSTALL_UUID='.$third, (string) file_get_contents($envPath));
    }

    public function testProjectPackageApiClientListsGetsAndDownloadsPackages(): void
    {
        $client = new ProjectPackageApiClient((string) self::$baseUrl, 'runner', '018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1');

        $packages = $client->listPackages();
        $package = $client->getPackage('oak-runner', '1.0.0');
        $downloadPath = $client->downloadPackage('oak-runner', '1.0.0');
        $this->pathsToDelete[] = $downloadPath;

        $download = (string) file_get_contents($downloadPath);

        $this->assertCount(1, $packages);
        $this->assertSame('oak-runner', $packages[0]['package_id']);
        $this->assertSame('stable', $packages[0]['channel']);
        $this->assertSame('oak/runner', $package['package_name']);
        $this->assertSame((string) self::$baseUrl.'/downloads/oak-runner-1.0.0.tar.gz', $package['download_url']);
        $this->assertStringContainsString('runner-package-1.0.0', $download);
        $this->assertStringContainsString('018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1', $download);
    }

    public function testProjectPackageApiClientSupportsBearerTokenWithoutInstallUuid(): void
    {
        $client = new ProjectPackageApiClient((string) self::$baseUrl, 'runner', '', 'package-token');

        $packages = $client->listPackages();
        $downloadPath = $client->downloadPackage('oak-runner', '1.0.0');
        $this->pathsToDelete[] = $downloadPath;

        $download = (string) file_get_contents($downloadPath);

        $this->assertCount(1, $packages);
        $this->assertStringContainsString('runner-package-1.0.0', $download);
        $this->assertStringContainsString('Bearer package-token', $download);
    }

    public function testProjectPackageApiClientFiltersMixedResponsesByRequestedPackageType(): void
    {
        $client = new ProjectPackageApiClient((string) self::$baseUrl.'/mixed', 'plugin', '018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1');

        $packages = $client->listPackages();

        $this->assertCount(1, $packages);
        $this->assertSame('plugin', $packages[0]['package_type']);
        $this->assertSame('oak-plugin', $packages[0]['package_id']);
    }

    public function testProjectPackageArchiveExtractorExtractsTarGzPackages(): void
    {
        $targetDir = $this->createTempDirectory();

        $extractor = new ProjectPackageArchiveExtractor();
        $result = $extractor->extractTarGz(
            $this->createTarGzArchive([
                'package-root/app/file.txt' => 'runner',
                'package-root/composer.json' => json_encode([
                    'extra' => [
                        'oak-engine-plugin' => [
                            'env' => [
                                'dir' => 'example',
                            ],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                'package-root/docs/readme.md' => 'skip-folder',
                'package-root/.env.local' => 'from-archive',
                'package-root/README.md' => 'skip-name',
            ]),
            $targetDir,
            ['docs'],
            ['README.md']
        );

        $this->assertSame(['app/file.txt', 'composer.json', '.env.local'], $result['extracted']);
        $this->assertContains('docs', $result['skipped_folders']);
        $this->assertContains('README.md', $result['skipped_files']);
        $this->assertSame('runner', file_get_contents($targetDir.'/app/file.txt'));
        $this->assertSame('from-archive', file_get_contents($targetDir.'/.env.local'));
    }

    public function testProjectPackageArchiveExtractorExtractsTarGzFromFile(): void
    {
        $targetDir = $this->createTempDirectory();
        $archiveDir = $this->createTempDirectory();
        $archivePath = $archiveDir.'/package.tar.gz';
        file_put_contents($archivePath, $this->createTarGzArchive([
            'package-root/app/file.txt' => 'from-file',
            'package-root/composer.json' => json_encode([
                'extra' => [
                    'oak-engine-plugin' => [
                        'env' => [
                            'dir' => 'example',
                        ],
                    ],
                ],
            ], JSON_THROW_ON_ERROR),
        ]));

        $extractor = new ProjectPackageArchiveExtractor();
        $result = $extractor->extractTarGzFile(
            $archivePath,
            $targetDir,
            [],
            []
        );

        $this->assertSame(['app/file.txt', 'composer.json'], $result['extracted']);
        $this->assertSame('from-file', file_get_contents($targetDir.'/app/file.txt'));
    }

    public function testProjectPackageArchiveExtractorRejectsMissingArchiveFile(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $extractor->extractTarGzFile(
            sys_get_temp_dir().'/definitely-missing-'.uniqid('', true).'.tar.gz',
            $this->createTempDirectory(),
            [],
            []
        );
    }

    public function testProjectPackageArchiveExtractorExtractsFromFlatArchiveLayout(): void
    {
        $targetDir = $this->createTempDirectory();

        $extractor = new ProjectPackageArchiveExtractor();
        $result = $extractor->extractTarGz(
            $this->createTarGzArchive([
                'flat.txt' => 'flat-content',
                'nested/inner.txt' => 'nested-content',
            ]),
            $targetDir,
            [],
            []
        );

        $this->assertContains('flat.txt', $result['extracted']);
        $this->assertContains('nested/inner.txt', $result['extracted']);
        $this->assertSame('flat-content', file_get_contents($targetDir.'/flat.txt'));
        $this->assertSame('nested-content', file_get_contents($targetDir.'/nested/inner.txt'));
    }

    public function testProjectPackageArchiveExtractorExtractsFromNonDirectoryEntry(): void
    {
        $targetDir = $this->createTempDirectory();

        $directory = $this->createTempDirectory();
        $sourceDirectory = $directory.'/source';
        mkdir($sourceDirectory, 0o755, true);
        file_put_contents($sourceDirectory.'/single-file.txt', 'single-content');

        $tarPath = $directory.'/archive.tar';
        $archive = new PharData($tarPath);
        $archive->buildFromDirectory($sourceDirectory);
        $archive->compress(Phar::GZ);

        $archivePath = $tarPath.'.gz';
        $archiveContent = file_get_contents($archivePath);
        $this->assertNotFalse($archiveContent);

        $extractor = new ProjectPackageArchiveExtractor();
        $result = $extractor->extractTarGz($archiveContent, $targetDir, [], []);

        $this->assertContains('single-file.txt', $result['extracted']);
    }

    public function testProjectPackageArchiveExtractorSkipsFileInExcludedSubfolder(): void
    {
        $targetDir = $this->createTempDirectory();

        $extractor = new ProjectPackageArchiveExtractor();
        $result = $extractor->extractTarGz(
            $this->createTarGzArchive([
                'package-root/keep/file.txt' => 'kept',
                'package-root/skip/sub/file.txt' => 'should-be-skipped',
            ]),
            $targetDir,
            ['skip'],
            []
        );

        $this->assertContains('keep/file.txt', $result['extracted']);
        $this->assertNotContains('skip/sub/file.txt', $result['extracted']);
    }

    public function testProjectPackageArchiveExtractorUsesPharWhenTarUnavailable(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $directory = $this->createTempDirectory();
        $gzFile = $directory.'/archive.tar.gz';
        $extractionDirectory = $directory.'/extract';
        mkdir($extractionDirectory, 0o755, true);

        file_put_contents(
            $gzFile,
            $this->createTarGzArchive([
                'package-root/file.txt' => 'phar-content',
            ])
        );

        $streamMethod = new \ReflectionMethod($extractor, 'streamExtractGzTarWithPhar');
        $streamMethod->setAccessible(true);
        $streamMethod->invoke($extractor, $gzFile, $extractionDirectory);

        $this->assertFileExists($extractionDirectory.'/package-root/file.txt');
        $this->assertSame('phar-content', (string) file_get_contents($extractionDirectory.'/package-root/file.txt'));
    }

    public function testProjectPackageArchiveExtractorBinaryThrowsOnMissingFile(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $streamMethod = new \ReflectionMethod($extractor, 'streamExtractGzTarWithBinary');
        $streamMethod->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $streamMethod->invoke(
            $extractor,
            '/bin/tar',
            '/nonexistent-'.uniqid('', true).'.tar.gz',
            sys_get_temp_dir()
        );
    }

    public function testProjectPackageArchiveExtractorBinaryThrowsOnFailure(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $directory = $this->createTempDirectory();
        $gzFile = $directory.'/archive.tar.gz';
        $extractionDirectory = $directory.'/extract';
        mkdir($extractionDirectory, 0o755, true);
        file_put_contents($gzFile, 'not a valid gz file');

        $streamMethod = new \ReflectionMethod($extractor, 'streamExtractGzTarWithBinary');
        $streamMethod->setAccessible(true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to extract package archive');

        $streamMethod->invoke($extractor, '/bin/tar', $gzFile, $extractionDirectory);
    }

    public function testProjectPackageArchiveExtractorRecursiveDeleteHandlesNonExistingPath(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();
        $method = new \ReflectionMethod($extractor, 'recursiveDelete');
        $method->setAccessible(true);

        $method->invoke($extractor, '/nonexistent-'.uniqid('', true));

        $this->assertTrue(true);
    }

    public function testProjectPackageArchiveExtractorRecursiveDeleteHandlesFilePath(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();
        $method = new \ReflectionMethod($extractor, 'recursiveDelete');
        $method->setAccessible(true);

        $file = $this->createTempDirectory().'/file-to-delete.txt';
        file_put_contents($file, 'data');
        $this->assertFileExists($file);

        $method->invoke($extractor, $file);

        $this->assertFileDoesNotExist($file);
    }

    public function testProjectPackageArchiveExtractorResolveSourceDirectoryReturnsExtractionDirectoryForEmpty(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();
        $method = new \ReflectionMethod($extractor, 'resolveSourceDirectory');
        $method->setAccessible(true);

        $directory = $this->createTempDirectory();
        $this->assertSame($directory, $method->invoke($extractor, $directory));
    }

    public function testProjectPackageArchiveExtractorPharThrowsOnInvalidGzFile(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $directory = $this->createTempDirectory();
        $gzFile = $directory.'/invalid.tar.gz';
        file_put_contents($gzFile, 'not a valid gz file - no phar header');

        $streamMethod = new \ReflectionMethod($extractor, 'streamExtractGzTarWithPhar');
        $streamMethod->setAccessible(true);

        $this->expectException(\Throwable::class);

        $streamMethod->invoke($extractor, $gzFile, $directory.'/extract');
    }

    public function testProjectPackageArchiveExtractorCopyThrowsWhenTargetDirectoryCannotBeCreated(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $sourceDir = $this->createTempDirectory();
        mkdir($sourceDir.'/sub', 0o755, true);
        file_put_contents($sourceDir.'/sub/file.txt', 'content');

        $targetDir = $this->createTempDirectory();
        $blocker = $targetDir.'/blocker';
        file_put_contents($blocker, 'blocker');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create directory');

        $extractor->extractTarGz(
            $this->createTarGzArchive([
                'sub/file.txt' => 'content',
            ]),
            $blocker.'/subdir',
            [],
            []
        );
    }

    public function testProjectPackageArchiveExtractorCopyThrowsWhenCopyFails(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $directory = $this->createTempDirectory();
        mkdir($directory.'/source/sub', 0o755, true);
        file_put_contents($directory.'/source/file.txt', 'flat');
        file_put_contents($directory.'/source/sub/file.txt', 'nested');

        $archivePath = $directory.'/archive.tar';
        $archive = new PharData($archivePath);
        $archive->buildFromDirectory($directory.'/source');
        $archive->compress(Phar::GZ);

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/sub', 'blocker');

        $exceptionMessage = null;
        try {
            $extractor->extractTarGzFile(
                $archivePath.'.gz',
                $targetDir,
                [],
                []
            );
        } catch (RuntimeException $e) {
            $exceptionMessage = $e->getMessage();
        }

        $this->assertNotNull($exceptionMessage, 'Expected RuntimeException');
        $this->assertStringContainsString('Unable to create directory', $exceptionMessage);
    }

    public function testProjectPackageArchiveExtractorCopyThrowsWhenCopyFailsForFile(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $directory = $this->createTempDirectory();
        mkdir($directory.'/source', 0o755, true);
        file_put_contents($directory.'/source/file.txt', 'content');

        $archivePath = $directory.'/archive.tar';
        $archive = new PharData($archivePath);
        $archive->buildFromDirectory($directory.'/source');
        $archive->compress(Phar::GZ);

        $targetDir = $this->createTempDirectory();
        $blocker = $targetDir.'/file.txt';
        mkdir($blocker, 0o755, true);

        $exceptionMessage = null;
        try {
            $extractor->extractTarGzFile(
                $archivePath.'.gz',
                $targetDir,
                [],
                []
            );
        } catch (RuntimeException $e) {
            $exceptionMessage = $e->getMessage();
        }

        $this->assertNotNull($exceptionMessage, 'Expected RuntimeException');
        $this->assertStringContainsString('Unable to copy', $exceptionMessage);
    }

    public function testProjectPackageArchiveExtractorChmodsReadOnlyTarget(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $directory = $this->createTempDirectory();
        mkdir($directory.'/source', 0o755, true);
        file_put_contents($directory.'/source/file.txt', 'content');

        $archivePath = $directory.'/archive.tar';
        $archive = new PharData($archivePath);
        $archive->buildFromDirectory($directory.'/source');
        $archive->compress(Phar::GZ);

        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/file.txt', 'old');
        chmod($targetDir.'/file.txt', 0o444);

        $extractor->extractTarGzFile(
            $archivePath.'.gz',
            $targetDir,
            [],
            []
        );

        $this->assertSame('content', (string) file_get_contents($targetDir.'/file.txt'));
    }

    public function testProjectPackageArchiveExtractorChmodsReadOnlySubdirectory(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();

        $directory = $this->createTempDirectory();
        mkdir($directory.'/source/sub', 0o755, true);
        file_put_contents($directory.'/source/file.txt', 'flat');
        file_put_contents($directory.'/source/sub/file.txt', 'nested');

        $archivePath = $directory.'/archive.tar';
        $archive = new PharData($archivePath);
        $archive->buildFromDirectory($directory.'/source');
        $archive->compress(Phar::GZ);

        $targetDir = $this->createTempDirectory();
        mkdir($targetDir.'/sub', 0o755, true);
        chmod($targetDir.'/sub', 0o555);

        try {
            $extractor->extractTarGzFile(
                $archivePath.'.gz',
                $targetDir,
                [],
                []
            );
        } finally {
            chmod($targetDir.'/sub', 0o755);
        }

        $this->assertTrue(true);
    }

    public function testProjectPackageArchiveExtractorThrowsWhenGzFileMissingForBinary(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();
        $targetDir = $this->createTempDirectory();

        try {
            $extractor->extractTarGzFile(
                $targetDir.'/definitely-missing-'.uniqid('', true).'.tar.gz',
                $this->createTempDirectory(),
                [],
                []
            );
            $this->fail('Expected RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('does not exist', $e->getMessage());
        }
    }

    public function testProjectPackageArchiveExtractorThrowsWhenTempDirectoryCannotBeCreatedForContent(): void
    {
        $extractor = new ProjectPackageArchiveExtractor();
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not exist');

        $extractor->extractTarGzFile(
            $blocker.'/file/missing-archive.tar.gz',
            $this->createTempDirectory(),
            [],
            []
        );
    }

    public function testProjectPackageArchiveExtractorUsesPharWhenNoTarCandidateConfigured(): void
    {
        $extractor = new ProjectPackageArchiveExtractor(
            $this->createTempDirectory(),
            [],
            '/nonexistent-path-'.uniqid('', true),
        );

        $archiveContent = $this->createTarGzArchive(['package-root/file.txt' => 'phar-content']);
        $targetDir = $this->createTempDirectory();

        $result = $extractor->extractTarGz($archiveContent, $targetDir, [], []);

        $this->assertFileExists($targetDir.'/file.txt');
        $this->assertSame('phar-content', (string) file_get_contents($targetDir.'/file.txt'));
        $this->assertNotEmpty($result['extracted']);
    }
    public function testProjectPackageArchiveExtractorUsesPharWhenNoTarCandidateConfiguredForFile(): void
    {
        $extractor = new ProjectPackageArchiveExtractor(
            $this->createTempDirectory(),
            [],
            '/nonexistent-path-'.uniqid('', true),
        );

        $archiveContent = $this->createTarGzArchive(['package-root/nested/file.txt' => 'phar-file']);
        $archiveDirectory = $this->createTempDirectory();
        $archivePath = $archiveDirectory.'/archive.tar.gz';
        file_put_contents($archivePath, $archiveContent);
        $targetDir = $this->createTempDirectory();

        $result = $extractor->extractTarGzFile($archivePath, $targetDir, [], []);

        $this->assertFileExists($targetDir.'/nested/file.txt');
        $this->assertSame('phar-file', (string) file_get_contents($targetDir.'/nested/file.txt'));
        $this->assertNotEmpty($result['extracted']);
    }

    public function testProjectPackageArchiveExtractorResolvesTarBinaryViaPathEnvironment(): void
    {
        $tarDirs = ['/bin', '/usr/bin', '/usr/local/bin'];
        $pathWithTar = implode(':', array_filter(
            $tarDirs,
            static fn (string $directory): bool => is_file($directory.'/tar') && is_executable($directory.'/tar'),
        ));
        if ('' === $pathWithTar) {
            self::markTestSkipped('No tar binary available to test PATH-based resolution.');
        }

        $extractor = new ProjectPackageArchiveExtractor(
            $this->createTempDirectory(),
            [],
            $pathWithTar,
        );

        $archiveContent = $this->createTarGzArchive(['package-root/file.txt' => 'path-resolved']);
        $targetDir = $this->createTempDirectory();

        $result = $extractor->extractTarGz($archiveContent, $targetDir, [], []);

        $this->assertFileExists($targetDir.'/file.txt');
        $this->assertSame('path-resolved', (string) file_get_contents($targetDir.'/file.txt'));
        $this->assertNotEmpty($result['extracted']);
    }

    public function testProjectPackageArchiveExtractorThrowsWhenInjectedTempBaseCannotBeCreatedForContent(): void
    {
        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $extractor = new ProjectPackageArchiveExtractor($blocker.'/file');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create temp directory');

        $extractor->extractTarGz('archive', $this->createTempDirectory(), [], []);
    }

    public function testProjectPackageArchiveExtractorThrowsWhenInjectedTempBaseCannotBeCreatedForFile(): void
    {
        $archiveDirectory = $this->createTempDirectory();
        $archivePath = $archiveDirectory.'/archive.tar.gz';
        file_put_contents($archivePath, $this->createTarGzArchive(['package-root/file.txt' => 'x']));

        $blocker = $this->createTempDirectory();
        file_put_contents($blocker.'/file', 'blocker');

        $extractor = new ProjectPackageArchiveExtractor($blocker.'/file');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to create temp directory');

        $extractor->extractTarGzFile($archivePath, $this->createTempDirectory(), [], []);
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if (false === $socket) {
            throw new RuntimeException(sprintf('Unable to allocate port: %s (%d)', $errorMessage, $errorCode));
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (false === $name) {
            throw new RuntimeException('Unable to read allocated port.');
        }

        $parts = explode(':', $name);

        return (int) end($parts);
    }

    private function createTempDirectory(): string
    {
        $directory = sys_get_temp_dir().'/installer_endpoint_'.uniqid('', true);
        if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create temporary directory.');
        }

        $this->pathsToDelete[] = $directory;

        return $directory;
    }

    /**
     * @param array<string, string> $files
     */
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
        $archive = new PharData($tarPath);
        $archive->buildFromDirectory($sourceDirectory);
        $archive->compress(Phar::GZ);

        $archivePath = $tarPath.'.gz';
        $archiveContent = file_get_contents($archivePath);
        if (false === $archiveContent) {
            throw new RuntimeException('Unable to read generated tar.gz archive.');
        }

        return $archiveContent;
    }

    private static function deleteDirectory(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
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
