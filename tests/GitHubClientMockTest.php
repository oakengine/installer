<?php

declare(strict_types=1);

namespace Tests;

use GitHubClient;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Mock\MockServerTrait;

require_once __DIR__.'/../src/index.php';
require_once __DIR__.'/Mock/MockServer.php';
require_once __DIR__.'/Mock/MockServerProcess.php';
require_once __DIR__.'/Mock/MockServerTrait.php';

final class GitHubClientMockTest extends TestCase
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

    public function testConstructorRejectsEmptyBaseUrl(): void
    {
        $this->expectException(RuntimeException::class);
        new GitHubClient('', '');
    }

    public function testGetBranchesReturnsAllBranches(): void
    {
        $this->mockServer()->setGithubFixtures(
            [['name' => 'main', 'commit' => 'sha-main'], ['name' => 'dev', 'commit' => 'sha-dev']],
            []
        );

        $client = new GitHubClient($this->mockBaseUrl(), 'token');
        $branches = $client->getBranches('demo/repo');

        $this->assertSame(
            [['name' => 'main', 'commit' => 'sha-main'], ['name' => 'dev', 'commit' => 'sha-dev']],
            $branches
        );
    }

    public function testGetTagsReturnsAllTags(): void
    {
        $this->mockServer()->setGithubFixtures(
            [],
            [['name' => 'v1.0.0', 'commit' => 'sha-v1'], ['name' => 'v2.0.0', 'commit' => 'sha-v2']]
        );

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $tags = $client->getTags('demo/repo');

        $this->assertSame(
            [['name' => 'v1.0.0', 'commit' => 'sha-v1'], ['name' => 'v2.0.0', 'commit' => 'sha-v2']],
            $tags
        );
    }

    public function testBranchesAndTagsSkipEntriesWithMissingFields(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->setGithubFixtures(
            [
                ['name' => 'main', 'commit' => 'sha-main'],
            ],
            []
        );

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $branches = $client->getBranches('demo/repo');
        $tags = $client->getTags('demo/repo');

        $this->assertSame([['name' => 'main', 'commit' => 'sha-main']], $branches);
        $this->assertSame([], $tags);
    }

    public function testBranchesAndTagsAcceptIntegerNames(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->setGithubFixtures(
            [
                ['name' => 42, 'commit' => 'sha-int'],
            ],
            [
                ['name' => 7, 'commit' => 'sha-int-tag'],
            ]
        );

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $branches = $client->getBranches('demo/repo');
        $tags = $client->getTags('demo/repo');

        $this->assertSame([['name' => '42', 'commit' => 'sha-int']], $branches);
        $this->assertSame([['name' => '7', 'commit' => 'sha-int-tag']], $tags);
    }

    public function testBranchesAndTagsAcceptIntegerCommitShas(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-github', [
            'branches' => [
                ['name' => 'main', 'commit' => 12345],
            ],
            'tags' => [
                ['name' => 'v1.0', 'commit' => 67890],
            ],
        ]);

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $branches = $client->getBranches('demo/repo');
        $tags = $client->getTags('demo/repo');

        $this->assertSame([['name' => 'main', 'commit' => '12345']], $branches);
        $this->assertSame([['name' => 'v1.0', 'commit' => '67890']], $tags);
    }

    public function testBranchesAndTagsSkipInvalidEntries(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-github', [
            'branches' => [
                ['commit' => ['sha' => 'sha-no-name']],
                ['name' => 'no-commit'],
                'not-an-object',
            ],
            'tags' => [
                ['name' => ''],
                'not-an-object',
            ],
        ]);

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $branches = $client->getBranches('demo/repo');
        $tags = $client->getTags('demo/repo');

        $this->assertSame([], $branches);
        $this->assertSame([], $tags);
    }

    public function testBranchesHandlePaginationAcrossMultiplePages(): void
    {
        $branches = [];
        for ($i = 0; $i < 150; ++$i) {
            $branches[] = ['name' => 'branch-'.$i, 'commit' => 'sha-'.$i];
        }
        $this->mockServer()->setGithubFixtures($branches, []);

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $result = $client->getBranches('demo/repo');

        $this->assertCount(150, $result);
        $this->assertSame('branch-0', $result[0]['name']);
        $this->assertSame('branch-149', $result[149]['name']);
    }

    public function testBranchesStopWhenEmptyResponse(): void
    {
        $this->mockServer()->setGithubFixtures([], []);

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $this->assertSame([], $client->getBranches('demo/repo'));
    }

    public function testRequestReturnsDecodedJsonArray(): void
    {
        $this->mockServer()->setGithubFixtures(
            [['name' => 'main', 'commit' => 'sha']],
            []
        );

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $branches = $client->getBranches('demo/repo');

        $this->assertCount(1, $branches);
    }

    public function testRequestReturnsEmptyArrayForEmptyResponse(): void
    {
        $this->mockServer()->setGithubFixtures([], []);

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $this->assertSame([], $client->getBranches('demo/repo'));
    }

    public function testRequestReturnsEmptyArrayForJsonObject(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-status', ['status' => 200]);
        $this->mockServer()->postControl('/__mock__/set-package', [
            '_force_response' => json_encode(['not' => 'an array']),
        ]);

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $this->assertSame([], $client->getBranches('demo/repo'));
    }

    public function testRequestReturnsEmptyArrayForInvalidJson(): void
    {
        $this->mockServer()->reset();
        $this->mockServer()->postControl('/__mock__/set-status', ['status' => 200]);
        $this->mockServer()->postControl('/__mock__/set-package', [
            '_force_response' => 'not-json{',
        ]);

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $this->assertSame([], $client->getBranches('demo/repo'));
    }

    public function testRequestThrowsOnHttpError(): void
    {
        $this->mockServer()->pushStatus(500);

        $client = new GitHubClient($this->mockBaseUrl(), '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $client->getBranches('demo/repo');
    }

    public function testRequestRetriesWithoutTokenOn403(): void
    {
        $this->mockServer()->setGithubFixtures(
            [['name' => 'main', 'commit' => 'sha-after-retry']],
            []
        );
        $this->mockServer()->pushStatus(403);

        $client = new GitHubClient($this->mockBaseUrl(), 'will-be-revoked');
        $branches = $client->getBranches('demo/repo');

        $this->assertSame([['name' => 'main', 'commit' => 'sha-after-retry']], $branches);
    }

    public function testRequestThrowsOnHttpErrorAfter403Retry(): void
    {
        $this->mockServer()->pushStatus(403);
        $this->mockServer()->pushStatus(500);

        $client = new GitHubClient($this->mockBaseUrl(), 'token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 500');
        $client->getBranches('demo/repo');
    }

    public function testDownloadArchiveReturnsBody(): void
    {
        $archiveContent = 'fake-archive-binary-data';
        $this->mockServer()->addArchive('main', $archiveContent);

        $client = new GitHubClient($this->mockBaseUrl(), '');
        $result = $client->downloadArchive('demo/repo', 'main');

        $this->assertSame($archiveContent, $result);
    }

    public function testDownloadArchiveThrowsOn404(): void
    {
        $client = new GitHubClient($this->mockBaseUrl(), '');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');
        $client->downloadArchive('demo/repo', 'missing');
    }

    public function testDownloadArchiveRetriesWithoutTokenOn403(): void
    {
        $archiveContent = 'public-archive';
        $this->mockServer()->addArchive('main', $archiveContent);
        $this->mockServer()->pushStatus(403);

        $client = new GitHubClient($this->mockBaseUrl(), 'will-be-revoked');
        $result = $client->downloadArchive('demo/repo', 'main');

        $this->assertSame($archiveContent, $result);
    }

    public function testDownloadArchiveThrowsAfterRetryStill403(): void
    {
        $this->mockServer()->addArchive('main', 'content');
        $this->mockServer()->pushStatus(403);
        $this->mockServer()->pushStatus(403);

        $client = new GitHubClient($this->mockBaseUrl(), 'token');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 403');
        $client->downloadArchive('demo/repo', 'main');
    }
}
