<?php

declare(strict_types=1);

namespace Tests\Mock;

use RuntimeException;

/**
 * Mix into test classes to spin up a fresh MockServerProcess per test and
 * expose helpers for configuring fixtures.
 *
 * Each test gets a unique port so parallel tests do not collide.
 */
trait MockServerTrait
{
    private ?MockServerProcess $mockServer = null;
    private string $mockBaseUrl = '';

    protected function setUpMockServer(): void
    {
        if (null !== $this->mockServer) {
            return;
        }
        $this->mockServer = new MockServerProcess();
        $this->mockBaseUrl = $this->mockServer->start();
        $this->mockServer->reset();
    }

    protected function tearDownMockServer(): void
    {
        if (null === $this->mockServer) {
            return;
        }
        $this->mockServer->stop();
        $this->mockServer = null;
        $this->mockBaseUrl = '';
    }

    protected function mockBaseUrl(): string
    {
        if ('' === $this->mockBaseUrl) {
            throw new RuntimeException('Mock server is not running. Did you call setUpMockServer()?');
        }

        return $this->mockBaseUrl;
    }

    protected function mockServer(): MockServerProcess
    {
        if (null === $this->mockServer) {
            throw new RuntimeException('Mock server is not running. Did you call setUpMockServer()?');
        }

        return $this->mockServer;
    }
}
