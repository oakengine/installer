<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Mock\MockServer;
use Tests\Mock\MockServerProcess;
use Tests\Mock\MockServerTrait;

require_once __DIR__.'/../src/index.php';
require_once __DIR__.'/Mock/MockServer.php';
require_once __DIR__.'/Mock/MockServerProcess.php';
require_once __DIR__.'/Mock/MockServerTrait.php';

final class MockServerTest extends TestCase
{
    use MockServerTrait;

    protected function setUp(): void
    {
        $this->setUpMockServer();
    }

    protected function tearDown(): void
    {
        $this->tearDownMockServer();
    }

    public function testRespondsToPackageEndpoint(): void
    {
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'oak-runner',
            'version' => '1.2.3',
            'package_name' => 'oak/runner',
            'download_url' => 'https://example.test/runner.tar.gz',
        ]);

        $client = new \Oak\Engine\Installer\ProjectPackageApiClient(
            $this->mockBaseUrl(),
            'runner',
            '',
            '',
            null
        );

        $packages = $client->listPackages();

        $this->assertCount(1, $packages);
        $this->assertSame('oak-runner', $packages[0]['package_id']);
        $this->assertSame('1.2.3', $packages[0]['version']);
    }

    public function testRespondsToGitHubBranchesEndpoint(): void
    {
        $this->mockServer()->setGithubFixtures(
            [['name' => 'main', 'commit' => 'sha-main']],
            [['name' => 'v1.0.0', 'commit' => 'sha-tag']]
        );

        $client = new \GitHubClient($this->mockBaseUrl(), '');

        $this->assertSame(
            [['name' => 'main', 'commit' => 'sha-main']],
            $client->getBranches('demo/repo')
        );
        $this->assertSame(
            [['name' => 'v1.0.0', 'commit' => 'sha-tag']],
            $client->getTags('demo/repo')
        );
    }

    public function testReusesCachesStateAcrossHttpRequests(): void
    {
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'cached',
            'version' => '1.0.0',
            'package_name' => 'cached/package',
        ]);

        $client1 = new \Oak\Engine\Installer\ProjectPackageApiClient(
            $this->mockBaseUrl(),
            'runner',
            '',
            '',
            sys_get_temp_dir().'/mock-cache-'.uniqid()
        );
        $client2 = new \Oak\Engine\Installer\ProjectPackageApiClient(
            $this->mockBaseUrl(),
            'runner',
            '',
            '',
            sys_get_temp_dir().'/mock-cache-'.uniqid()
        );

        $first = $client1->listPackages();
        $second = $client2->listPackages();

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);
        $this->assertSame('cached', $first[0]['package_id']);
        $this->assertSame('cached', $second[0]['package_id']);
    }

    public function testPackageEndpointFiltersByType(): void
    {
        $this->mockServer()->addPackage([
            'package_type' => 'runner',
            'package_id' => 'r1',
            'version' => '1.0.0',
            'package_name' => 'r1/pkg',
        ]);
        $this->mockServer()->addPackage([
            'package_type' => 'plugin',
            'package_id' => 'p1',
            'version' => '1.0.0',
            'package_name' => 'p1/pkg',
        ]);

        $client = new \Oak\Engine\Installer\ProjectPackageApiClient(
            $this->mockBaseUrl(),
            'plugin',
            '',
            '',
            null
        );

        $packages = $client->listPackages();

        $this->assertCount(1, $packages);
        $this->assertSame('plugin', $packages[0]['package_type']);
        $this->assertSame('p1', $packages[0]['package_id']);
    }

    public function testMockServerUnitHandlesBranchesPagination(): void
    {
        $server = new MockServer();
        $server->setBranches([
            ['name' => 'main', 'commit' => 'sha-1'],
            ['name' => 'dev', 'commit' => 'sha-2'],
        ]);

        [$status, $contentType, $body] = $server->handle('GET', '/repos/owner/repo/branches', [], '', []);

        $this->assertSame(200, $status);
        $this->assertSame('application/json', $contentType);
        $decoded = json_decode($body, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('main', $decoded[0]['name']);
        $this->assertSame('sha-1', $decoded[0]['commit']['sha']);
    }

    public function testMockServerUnitHandlesNotFoundArchive(): void
    {
        $server = new MockServer();

        [$status, , $body] = $server->handle('GET', '/repos/owner/repo/zipball/missing', [], '', []);

        $this->assertSame(404, $status);
        $this->assertStringContainsString('Ref not found', $body);
    }

    public function testMockServerUnitHandlesControlEndpoints(): void
    {
        $server = new MockServer();
        $server->addPackage([
            'package_type' => 'runner',
            'package_id' => 'x',
            'version' => '1.0.0',
            'package_name' => 'x/y',
        ]);

        $request = [
            'method' => 'GET',
            'path' => '/__mock__/requests',
            'query' => [],
            'body' => '',
            'headers' => [],
        ];
        $server->handle($request['method'], $request['path'], $request['query'], $request['body'], $request['headers']);

        $requests = $server->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('/__mock__/requests', $requests[0]['path']);
    }

    public function testMockServerUnitStateRoundtripsThroughFile(): void
    {
        $stateFile = tempnam(sys_get_temp_dir(), 'oak_mock_state_');
        $server = new MockServer();
        $server->addPackage([
            'package_type' => 'runner',
            'package_id' => 'persisted',
            'version' => '1.0.0',
            'package_name' => 'persisted/pkg',
        ]);
        $server->save($stateFile);

        $loaded = MockServer::load($stateFile);
        [$status, , $body] = $loaded->handle('POST', '/index.php', ['package_type' => 'runner'], 'package_type=runner', []);

        $this->assertSame(200, $status);
        $this->assertStringContainsString('persisted', $body);

        @unlink($stateFile);
    }
}
