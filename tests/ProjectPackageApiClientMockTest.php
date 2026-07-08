<?php

declare(strict_types=1);

namespace Tests;

use Oak\Engine\Installer\ProjectPackageApiClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Mock\MockServerTrait;

require_once __DIR__.'/../src/index.php';
require_once __DIR__.'/Mock/MockServer.php';
require_once __DIR__.'/Mock/MockServerProcess.php';
require_once __DIR__.'/Mock/MockServerTrait.php';

final class ProjectPackageApiClientMockTest extends TestCase
{
    use MockServerTrait;

    /** @var list<string> */
    private array $pathsToDelete = [];

    protected function setUp(): void
    {
        $this->setUpMockServer();
    }

    protected function tearDown(): void
    {
        $this->tearDownMockServer();
        foreach (array_reverse($this->pathsToDelete) as $path) {
            $this->deletePath($path);
        }
        $this->pathsToDelete = [];
    }

    private function createTempDirectory(): string
    {
        $dir = sys_get_temp_dir().'/api_client_test_'.uniqid('', true);
        if (!mkdir($dir, 0o755, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create temp directory.');
        }
        $this->pathsToDelete[] = $dir;

        return $dir;
    }

    private function deletePath(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);

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
            $this->deletePath($path.DIRECTORY_SEPARATOR.$item);
        }
        @rmdir($path);
    }

    public function testFetchPackagesWithFullPayload(): void
    {
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.2.3',
            'channel' => 'stable',
            'package_name' => 'oak/runner',
            'archive_size' => 1024,
            'archive_sha256' => 'abc',
            'download_url' => '/archives/oak-runner.tar.gz',
            'composer' => ['name' => 'oak/runner'],
        ]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');
        $packages = $client->listPackages();

        $this->assertCount(1, $packages);
        $this->assertSame('oak-runner', $packages[0]['package_id']);
        $this->assertSame(1024, $packages[0]['archive_size']);
    }

    public function testFetchPackagesReturnsEmptyArrayForEmptyPackages(): void
    {
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->assertSame([], $client->listPackages());
    }

    public function testFetchPackagesSkipsNonArrayPackageEntries(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-package', ['_force_response' => json_encode([
            'packages' => ['not-an-object', 42, 'also-not-an-object'],
        ])]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->assertSame([], $client->listPackages());
    }

    public function testFetchPackagesThrowsWhenPackagesKeyIsMissing(): void
    {
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-package', ['_force_response' => json_encode(['not-packages' => []])]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no package list');
        $client->listPackages();
    }

    public function testFetchPackagesSkipsEntriesWithWrongType(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-package', ['_force_response' => json_encode([
            'packages' => [
                ['package_type' => 'plugin', 'package_id' => 'x', 'version' => '1.0.0', 'package_name' => 'n', 'download_url' => '/x'],
            ],
        ])]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->assertSame([], $client->listPackages());
    }

    public function testFetchPackagesSkipsEntriesMissingRequiredFields(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-package', ['_force_response' => json_encode([
            'packages' => [
                ['package_type' => 'runner', 'version' => '1.0.0', 'package_name' => 'n', 'download_url' => '/x'],
                ['package_type' => 'runner', 'package_id' => 'x', 'package_name' => 'n', 'download_url' => '/x'],
                ['package_type' => 'runner', 'package_id' => 'x', 'version' => '1.0.0', 'download_url' => '/x'],
                ['package_type' => 'runner', 'package_id' => 'x', 'version' => '1.0.0', 'package_name' => 'n'],
            ],
        ])]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->assertSame([], $client->listPackages());
    }

    public function testFetchPackagesResolvesRelativeDownloadUrl(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-package', ['_force_response' => json_encode([
            'packages' => [
                [
                    'package_type' => 'runner',
                    'package_id' => 'x',
                    'version' => '1.0.0',
                    'package_name' => 'n',
                    'download_url' => 'archives/x.tar.gz',
                ],
            ],
        ])]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');
        $packages = $client->listPackages();

        $this->assertSame($this->mockBaseUrl().'/archives/x.tar.gz', $packages[0]['download_url']);
    }

    public function testRequestThrowsOnHttpError(): void
    {
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');
        $this->mockServer()->pushStatus(500);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $client->listPackages();
    }

    public function testRequestThrowsOnInvalidJson(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-package', ['_force_response' => 'not-json{']);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');
        $client->listPackages();
    }

    public function testBuildPayloadThrowsForInvalidUuid(): void
    {
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner', 'not-a-uuid', '', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('install UUID');
        $client->listPackages();
    }

    public function testBuildPayloadAppendsLowercaseUuid(): void
    {
        $this->mockServer()->reset();
        $uuid = '0192F8E3-7C8E-7C2F-9D2A-8F4D5B4EC2F1';
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner', $uuid, '', null);
        $client->listPackages();

        $requests = $this->mockServer()->getRequests();
        $this->assertNotEmpty($requests);
        $first = $requests[0];
        $this->assertSame(strtolower($uuid), $first['body']['install_uuid']);
    }

    public function testBuildHeadersIncludesBearerTokenAndUuid(): void
    {
        $this->mockServer()->reset();
        $uuid = '0192f8e3-7c8e-7c2f-9d2a-8f4d5b4ec2f1';
        $client = new ProjectPackageApiClient(
            $this->mockBaseUrl(),
            'runner',
            $uuid,
            'secret-token',
            null
        );
        $client->listPackages();

        $requests = $this->mockServer()->getRequests();
        $this->assertNotEmpty($requests);
        $headers = $requests[0]['headers'];
        $this->assertSame('Bearer secret-token', $headers['authorization']);
        $this->assertSame($uuid, $headers['x-install-uuid']);
        $this->assertSame('application/json', $headers['accept']);
    }

    public function testResolveDownloadUrlSkipsEmptyAndAbsoluteUrls(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-package', ['_force_response' => json_encode([
            'packages' => [
                [
                    'package_type' => 'runner',
                    'package_id' => 'http',
                    'version' => '1.0.0',
                    'package_name' => 'n',
                    'download_url' => 'http://other.test/x',
                ],
                [
                    'package_type' => 'runner',
                    'package_id' => 'https',
                    'version' => '1.0.0',
                    'package_name' => 'n',
                    'download_url' => 'https://other.test/x',
                ],
                [
                    'package_type' => 'runner',
                    'package_id' => 'empty',
                    'version' => '1.0.0',
                    'package_name' => 'n',
                    'download_url' => '',
                ],
            ],
        ])]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');
        $packages = $client->listPackages();

        $urls = array_column($packages, 'download_url', 'package_id');
        $this->assertSame('http://other.test/x', $urls['http']);
        $this->assertSame('https://other.test/x', $urls['https']);
    }

    public function testReadCacheReturnsNullWhenFileUnreadable(): void
    {
        $cacheDir = $this->createTempDirectory();
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner', '', '', $cacheDir);

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getCacheFile');
        $method->setAccessible(true);
        $cacheFile = (string) $method->invoke($client);

        file_put_contents($cacheFile, '[]');
        @chmod(dirname($cacheFile), 0o000);
        @chmod($cacheFile, 0o000);

        $this->assertNull($client->getCacheAge());
        $this->assertSame([], $client->listPackages());

        @chmod(dirname($cacheFile), 0o755);
        @chmod($cacheFile, 0o644);
    }

    public function testReadCacheSkipsInvalidEntries(): void
    {
        $cacheDir = $this->createTempDirectory();
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner', '', '', $cacheDir);

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getCacheFile');
        $method->setAccessible(true);
        $cacheFile = (string) $method->invoke($client);

        file_put_contents($cacheFile, json_encode([
            'not-an-object',
            ['package_type' => 'runner', 'package_id' => 'good', 'version' => '1.0.0', 'package_name' => 'n', 'download_url' => '/x', 'channel' => 'stable', 'archive_size' => 100],
            ['package_id' => 'x', 'version' => '1.0.0', 'package_name' => 'n', 'download_url' => '/x'],
            ['package_type' => 1, 'package_id' => 'x', 'version' => '1.0.0', 'package_name' => 'n', 'download_url' => '/x'],
        ], JSON_THROW_ON_ERROR));
        touch($cacheFile, time());

        $packages = $client->listPackages();

        $this->assertCount(1, $packages);
        $this->assertSame('good', $packages[0]['package_id']);
    }

    public function testReadCacheReturnsNullForInvalidJson(): void
    {
        $cacheDir = $this->createTempDirectory();
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner', '', '', $cacheDir);

        $reflection = new \ReflectionClass($client);
        $method = $reflection->getMethod('getCacheFile');
        $method->setAccessible(true);
        $cacheFile = (string) $method->invoke($client);

        file_put_contents($cacheFile, 'not-json{');

        $this->assertSame([], $client->listPackages());
    }

    public function testWriteCacheCreatesDirectoryAndFile(): void
    {
        $cacheDir = $this->createTempDirectory().'/nested/cache';

        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'cached-write',
            'version' => '1.0.0',
            'package_name' => 'cw/pkg',
            'download_url' => '/x.tar.gz',
        ]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner', '', '', $cacheDir);
        $client->listPackages();

        $this->assertDirectoryExists($cacheDir);
        $files = glob($cacheDir.'/*.json');
        $this->assertNotEmpty($files);
        $this->assertSame('0644', substr(sprintf('%o', fileperms($files[0])), -4));
    }

    public function testWriteCacheSilentlyReturnsWhenDirectoryCannotBeCreated(): void
    {
        $cacheRoot = $this->createTempDirectory();
        file_put_contents($cacheRoot.'/blocker', 'not-a-dir');

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner', '', '', $cacheRoot.'/blocker/cache');

        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'silent',
            'version' => '1.0.0',
            'package_name' => 's/pkg',
        ]);

        $client->listPackages();

        $this->assertFileExists($cacheRoot.'/blocker');
        $this->assertDirectoryDoesNotExist($cacheRoot.'/blocker/cache');
    }

    public function testGetPackageReturnsMatchingEntry(): void
    {
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.2.3',
            'package_name' => 'oak/runner',
            'channel' => 'stable',
            'download_url' => '/x.tar.gz',
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '2.0.0',
            'package_name' => 'oak/runner',
            'channel' => 'beta',
            'download_url' => '/y.tar.gz',
        ]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->assertSame('1.2.3', $client->getPackage('oak-runner', '1.2.3')['version']);
        $this->assertSame('2.0.0', $client->getPackage('oak-runner', '2.0.0')['version']);
        $this->assertSame('1.2.3', $client->getPackage('oak-runner')['version']);
    }

    public function testGetPackageThrowsWhenNotFound(): void
    {
        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Runner package "missing" (latest) was not found');
        $client->getPackage('missing');
    }

    public function testDownloadPackageStoresArchiveToTempFile(): void
    {
        $archiveContent = "\x1f\x8b\x08\x00fake-gzip-content";
        $downloadPath = '/archives/oak-runner.tar.gz';
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.0.0',
            'package_name' => 'oak/runner',
            'download_url' => $downloadPath,
        ]);
        $this->mockServer()->addArchiveAtPath($downloadPath, $archiveContent);

        $directCheck = file_get_contents($this->mockBaseUrl().$downloadPath);
        $this->assertSame($archiveContent, $directCheck, 'Archive must be reachable from mock server');

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');
        $tempFile = $client->downloadPackage('oak-runner');
        $this->assertNotEmpty($tempFile);
        $this->assertSame($archiveContent, file_get_contents($tempFile));
        @unlink($tempFile);
    }

    public function testDownloadPackageThrowsOnHttpError(): void
    {
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'broken',
            'version' => '1.0.0',
            'package_name' => 'b/pkg',
            'download_url' => '/broken.tar.gz',
        ]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Package download failed');
        $client->downloadPackage('broken');
    }

    public function testDownloadPackageThrowsWhenCurlCannotConnect(): void
    {
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'unreachable',
            'version' => '1.0.0',
            'package_name' => 'unr/pkg',
            'download_url' => 'http://127.0.0.1:1/missing.tar.gz',
        ]);

        $client = new ProjectPackageApiClient($this->mockBaseUrl(), 'runner');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Package download failed');
        $client->downloadPackage('unreachable');
    }
}
