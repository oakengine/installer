<?php

declare(strict_types=1);

namespace Tests\Mock;

/**
 * Starts PHP's built-in HTTP server in a background process and exposes
 * helpers for tests to configure the MockServer fixtures through a control
 * endpoint.
 */
final class MockServerProcess
{
    private string $baseUrl;
    private string $stateFile;
    /** @var resource|null */
    private $process = null;
    private string $host;
    private int $port;
    private string $logFile;

    public function __construct(string $host = '127.0.0.1', int $port = 0)
    {
        $this->host = $host;
        $this->port = $port;
        $this->baseUrl = '';
        $this->stateFile = '';
        $this->logFile = '';
    }

    public function start(): string
    {
        if (null !== $this->process) {
            return $this->baseUrl;
        }

        $port = 0 === $this->port ? $this->findFreePort() : $this->port;
        $this->stateFile = tempnam(sys_get_temp_dir(), 'oak_mock_state_');
        $this->logFile = $this->stateFile.'.log';
        $this->baseUrl = sprintf('http://%s:%d', $this->host, $port);

        $command = [
            'php',
            '-d', 'display_errors=1',
            '-d', 'log_errors=1',
            '-d', 'error_log='.sys_get_temp_dir().'/php_mock_err.log',
            '-S', $this->host.':'.$port,
            __DIR__.'/router.php',
        ];

        $env = array_merge(
            getenv(),
            [
                'OAK_MOCK_STATE_FILE' => $this->stateFile,
                // The mock server is a helper process, not code under test. Disable
                // Xdebug for it so coverage runs do not hang/slow down on the child.
                'XDEBUG_MODE' => 'off',
            ],
        );

        $descriptors = [
            1 => ['file', $this->logFile, 'a'],
            2 => ['file', $this->logFile, 'a'],
        ];

        $this->process = proc_open($command, $descriptors, $pipes, dirname(__DIR__, 2), $env);
        if (!is_resource($this->process)) {
            throw new RuntimeException('Unable to start mock HTTP server.');
        }

        $this->waitUntilReady($port);

        return $this->baseUrl;
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }
        if ('' !== $this->stateFile && is_file($this->stateFile)) {
            @unlink($this->stateFile);
        }
        if ('' !== $this->logFile && is_file($this->logFile)) {
            @unlink($this->logFile);
        }
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function postControl(string $path, array $payload = []): array
    {
        return $this->jsonRequest('POST', $path, $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getRequests(): array
    {
        $response = $this->jsonRequest('GET', '/__mock__/requests');

        return is_array($response['body'] ?? null) ? array_values($response['body']) : [];
    }

    public function reset(): void
    {
        $this->postControl('/__mock__/reset');
        $this->postControl('/__mock__/clear-requests');
    }

    public function clearRequests(): void
    {
        $this->postControl('/__mock__/clear-requests');
    }

    /**
     * @param array<string, mixed> $package
     */
    public function addPackage(array $package): void
    {
        $this->postControl('/__mock__/set-package', $package);
    }

    /**
     * @param list<array{name: string, commit: string}> $branches
     * @param list<array{name: string, commit: string}> $tags
     */
    public function setGithubFixtures(array $branches, array $tags): void
    {
        $this->postControl('/__mock__/set-github', [
            'branches' => $branches,
            'tags' => $tags,
        ]);
    }

    /**
     * Stores an archive (e.g. a tar.gz or zip) for the given ref name. The
     * next request to /repos/{owner}/{repo}/zipball/{ref} will return it.
     */
    public function addArchive(string $ref, string $binaryContent): void
    {
        $this->postControl('/__mock__/set-package', [
            'archive' => true,
            'ref' => $ref,
            'content_b64' => base64_encode($binaryContent),
        ]);
    }

    /**
     * Stores an archive that will be served at the given path (e.g. the
     * download_url returned by the package endpoint).
     */
    public function addArchiveAtPath(string $path, string $binaryContent): void
    {
        $this->postControl('/__mock__/set-package', [
            'archive' => true,
            'path' => $path,
            'content_b64' => base64_encode($binaryContent),
        ]);
    }

    public function pushStatus(int $status): void
    {
        $this->postControl('/__mock__/set-status', ['status' => $status]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{status: int, body: array<string, mixed>|null}
     */
    private function jsonRequest(string $method, string $path, array $payload = []): array
    {
        $ch = curl_init();
        $url = $this->baseUrl.$path;
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 5,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = is_string($body) && '' !== $body ? json_decode($body, true) : null;

        return ['status' => $status, 'body' => is_array($decoded) ? $decoded : null];
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://'.$this->host.':0', $errno, $errstr);
        if (false === $socket) {
            throw new RuntimeException("Unable to find a free port: {$errstr}");
        }
        $name = stream_socket_get_name($socket, false);
        fclose($socket);
        if (false === $name) {
            throw new RuntimeException('Unable to read socket name.');
        }
        $parts = explode(':', (string) $name);

        return (int) ($parts[1] ?? 0);
    }

    private function waitUntilReady(int $port): void
    {
        $deadline = microtime(true) + 5.0;
        $url = sprintf('http://%s:%d/', $this->host, $port);
        while (microtime(true) < $deadline) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 1,
                CURLOPT_CONNECTTIMEOUT => 1,
            ]);
            curl_exec($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            if (0 === $errno) {
                return;
            }
            usleep(50_000);
        }
    }
}
