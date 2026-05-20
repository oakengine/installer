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
];

if ('/' === $path || '/packages' === $path) {
    header('Content-Type: application/json');
    echo json_encode([
        'packages' => array_values(array_filter($packages, static fn (array $package): bool => $package['package_type'] === $packageType)),
    ]);
    return;
}

if ('/packages/runner/oak-runner' === $path) {
    header('Content-Type: application/json');
    echo json_encode($packages[0]);
    return;
}

if ('/downloads/oak-runner-1.0.0.tar.gz' === $path) {
    header('Content-Type: application/octet-stream');
    echo 'runner-package-'.$version.'-'.($_SERVER['HTTP_X_INSTALL_UUID'] ?? 'missing');
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
        while ($attempt < 20) {
            $response = @file_get_contents(self::$baseUrl.'/?package_type=runner');
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
        $download = $client->downloadPackage('oak-runner', '1.0.0');

        $this->assertCount(1, $packages);
        $this->assertSame('oak-runner', $packages[0]['package_id']);
        $this->assertSame('stable', $packages[0]['channel']);
        $this->assertSame('oak/runner', $package['package_name']);
        $this->assertSame((string) self::$baseUrl.'/downloads/oak-runner-1.0.0.tar.gz', $package['download_url']);
        $this->assertStringContainsString('runner-package-1.0.0', $download);
        $this->assertStringContainsString('018f5e91-16a3-7f41-8d6a-8f4d5b4ec2f1', $download);
    }

    public function testProjectPackageArchiveExtractorExtractsTarGzPackages(): void
    {
        $targetDir = $this->createTempDirectory();
        file_put_contents($targetDir.'/.env.local', 'keep');

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
                'package-root/.env.local' => 'skip-file',
                'package-root/README.md' => 'skip-name',
            ]),
            $targetDir,
            ['docs'],
            ['README.md'],
            [],
            ['.env.local']
        );

        $this->assertSame(['app/file.txt', 'composer.json'], $result['extracted']);
        $this->assertContains('docs', $result['skipped_folders']);
        $this->assertContains('.env.local', $result['skipped_files']);
        $this->assertContains('README.md', $result['skipped_files']);
        $this->assertSame('runner', file_get_contents($targetDir.'/app/file.txt'));
        $this->assertSame('keep', file_get_contents($targetDir.'/.env.local'));
        $this->assertSame('example', $result['composer_metadata']['extra']['oak-engine-plugin']['env']['dir']);
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
