<?php

declare(strict_types=1);

namespace Tests\Mock;

/**
 * In-memory router for the test mock server. The same instance can be shared
 * between the test and the server process via serialization through a temp
 * file so individual tests can configure responses before each request.
 *
 * Endpoints supported:
 *  - GET  /repos/{owner}/{repo}/branches (paginated, 100 per page)
 *  - GET  /repos/{owner}/{repo}/tags      (paginated, 100 per page)
 *  - GET  /repos/{owner}/{repo}/zipball/{ref}  (returns binary archive)
 *  - POST /index.php   (package endpoint, body: package_type + install_uuid)
 *  - GET  /index.php   (package endpoint, query string: package_type + install_uuid)
 *
 * Plus a control endpoint used by the test trait:
 *  - POST /__mock__/reset       (clear all fixtures)
 *  - POST /__mock__/set-package (configure a package fixture)
 *  - POST /__mock__/set-github  (configure a GitHub fixture)
 *  - POST /__mock__/set-status  (configure next response status code)
 *  - GET  /__mock__/requests    (return list of recorded requests)
 */
final class MockServer
{
    /**
     * @var list<array{method: string, path: string, query: array<string, string>, body: array<string, string>, headers: array<string, string>}>
     */
    private array $requests = [];

    /**
     * @var list<array{package_type: string, package_id: string, version: string, channel: string, package_name: string, archive_size: int, archive_sha256: string, download_url: string, composer: array<string, mixed>}>
     */
    private array $packages = [];

    /**
     * @var list<array{name: string, commit: string}>
     */
    private array $branches = [];

    /**
     * @var list<array{name: string, commit: string}>
     */
    private array $tags = [];

    /** @var array<string, string> */
    private array $archives = [];

    /** @var list<int> */
    private array $nextStatuses = [];

    /** @var list<string> */
    private array $forcedResponses = [];

    public function reset(): void
    {
        $this->requests = [];
        $this->packages = [];
        $this->branches = [];
        $this->tags = [];
        $this->archives = [];
        $this->nextStatuses = [];
        $this->forcedResponses = [];
    }

    /**
     * @param array{package_type?: string, package_id?: string, version?: string, channel?: string, package_name?: string, archive_size?: int, archive_sha256?: string, download_url?: string, composer?: array<string, mixed>} $package
     */
    public function addPackage(array $package): void
    {
        $this->packages[] = [
            'package_type' => (string) ($package['package_type'] ?? 'runner'),
            'package_id' => (string) ($package['package_id'] ?? 'demo'),
            'version' => (string) ($package['version'] ?? '1.0.0'),
            'channel' => (string) ($package['channel'] ?? 'stable'),
            'package_name' => (string) ($package['package_name'] ?? 'demo/package'),
            'archive_size' => (int) ($package['archive_size'] ?? 0),
            'archive_sha256' => (string) ($package['archive_sha256'] ?? ''),
            'download_url' => (string) ($package['download_url'] ?? '/archives/demo.tar.gz'),
            'composer' => is_array($package['composer'] ?? null) ? $package['composer'] : [],
        ];
    }

    /**
     * @param list<array{name: string, commit: string}> $branches
     */
    public function setBranches(array $branches): void
    {
        $this->branches = $branches;
    }

    /**
     * @param list<array{name: string, commit: string}> $tags
     */
    public function setTags(array $tags): void
    {
        $this->tags = $tags;
    }

    /**
     * Stores an archive (binary content) keyed by ref name.
     */
    public function addArchive(string $ref, string $binaryContent): void
    {
        $this->archives[$ref] = $binaryContent;
    }

    /**
     * Stores an archive served at the given path (e.g. the package
     * download_url returned by the package endpoint).
     */
    public function addArchiveAtPath(string $path, string $binaryContent): void
    {
        $this->archives['__path__:'.$path] = $binaryContent;
    }

    public function pushStatus(int $status): void
    {
        $this->nextStatuses[] = $status;
    }

    public function pushForcedResponse(string $body): void
    {
        $this->forcedResponses[] = $body;
    }

    /**
     * @return list<array{method: string, path: string, query: array<string, string>, body: array<string, string>, headers: array<string, string>}>
     */
    public function getRequests(): array
    {
        return $this->requests;
    }

    public function clearRequests(): void
    {
        $this->requests = [];
    }

    /**
     * Routes an incoming request and returns the response as
     * [int $status, string $contentType, string $body].
     *
     * @param array<string, string> $query
     * @param array<string, string> $headers
     */
    public function handle(string $method, string $path, array $query, string $body, array $headers): array
    {
        $parsedHeaders = [];
        foreach ($headers as $name => $value) {
            $parsedHeaders[(string) $name] = (string) $value;
        }

        $parsedBody = $this->parseBody($method, $body, $parsedHeaders);

        $this->requests[] = [
            'method' => $method,
            'path' => $path,
            'query' => $query,
            'body' => $parsedBody,
            'headers' => $parsedHeaders,
        ];

        if (str_starts_with($path, '/__mock__/')) {
            return $this->handleControl($method, $path, $body);
        }

        $status = $this->consumeNextStatus() ?? 200;

        if (preg_match('#^/repos/([^/]+)/([^/]+)/branches(?:\?(.*))?$#', $path, $matches) === 1) {
            return $this->handleBranches($query, $status);
        }

        if (preg_match('#^/repos/([^/]+)/([^/]+)/tags(?:\?(.*))?$#', $path, $matches) === 1) {
            return $this->handleTags($query, $status);
        }

        if (preg_match('#^/repos/([^/]+)/([^/]+)/zipball/(.+)$#', $path, $matches) === 1) {
            return $this->handleZipball($matches[3], $status);
        }

        if (isset($this->archives['__path__:'.$path])) {
            return [$status, 'application/octet-stream', $this->archives['__path__:'.$path]];
        }

        if ('/index.php' === $path || '/' === $path) {
            return $this->handlePackageEndpoint($parsedBody, $query, $status);
        }

        return [404, 'application/json', json_encode(['error' => 'Not Found', 'path' => $path], JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private function parseBody(string $method, string $body, array $headers): array
    {
        if ('' === $body) {
            return [];
        }

        $contentType = strtolower($headers['content-type'] ?? '');
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $this->flatten($decoded) : [];
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded') || 'POST' === $method) {
            parse_str($body, $parsed);

            /** @var array<string, string> $parsed */
            return $parsed;
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $data
     *
     * @return array<string, string>
     */
    private function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $keyStr = (string) $key;
            $combinedKey = '' === $prefix ? $keyStr : $prefix.'['.$keyStr.']';
            if (is_array($value)) {
                /** @var array<int|string, mixed> $value */
                foreach ($this->flatten($value, $combinedKey) as $subKey => $subValue) {
                    $result[$subKey] = $subValue;
                }
            } else {
                $result[$combinedKey] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{int, string, string}
     */
    private function handleBranches(array $query, int $status): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($this->branches, $offset, $perPage);
        $payload = [];
        foreach ($slice as $branch) {
            if (!is_array($branch)) {
                $payload[] = $branch;
                continue;
            }
            $payload[] = [
                'name' => $branch['name'] ?? null,
                'commit' => array_key_exists('commit', $branch) ? ['sha' => $branch['commit']] : null,
            ];
        }

        return [$status, 'application/json', json_encode($payload, JSON_THROW_ON_ERROR)];
    }

    /**
     * @param array<string, string> $query
     *
     * @return array{int, string, string}
     */
    private function handleTags(array $query, int $status): array
    {
        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = max(1, (int) ($query['per_page'] ?? 100));
        $offset = ($page - 1) * $perPage;
        $slice = array_slice($this->tags, $offset, $perPage);
        $payload = [];
        foreach ($slice as $tag) {
            if (!is_array($tag)) {
                $payload[] = $tag;
                continue;
            }
            $payload[] = [
                'name' => $tag['name'] ?? null,
                'commit' => array_key_exists('commit', $tag) ? ['sha' => $tag['commit']] : null,
            ];
        }

        return [$status, 'application/json', json_encode($payload, JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array{int, string, string}
     */
    private function handleZipball(string $ref, int $status): array
    {
        $ref = urldecode($ref);
        if (!isset($this->archives[$ref])) {
            return [404, 'application/json', json_encode(['error' => 'Ref not found', 'ref' => $ref], JSON_THROW_ON_ERROR)];
        }

        return [$status, 'application/zip', $this->archives[$ref]];
    }

    /**
     * @param array<string, string> $body
     * @param array<string, string> $query
     *
     * @return array{int, string, string}
     */
    private function handlePackageEndpoint(array $body, array $query, int $status): array
    {
        if ([] !== $this->forcedResponses) {
            $forced = array_shift($this->forcedResponses);

            return [$status, 'application/json', $forced];
        }

        $packageType = (string) ($body['package_type'] ?? $query['package_type'] ?? '');
        $typeAlias = (string) ($body['type'] ?? $query['type'] ?? '');
        $resolvedType = '' !== $packageType ? $packageType : $typeAlias;

        $filtered = array_values(array_filter(
            $this->packages,
            static fn (array $package): bool => $package['package_type'] === $resolvedType
        ));

        $payload = ['packages' => $filtered];

        return [$status, 'application/json', json_encode($payload, JSON_THROW_ON_ERROR)];
    }

    private function consumeNextStatus(): ?int
    {
        if ([] === $this->nextStatuses) {
            return null;
        }

        return array_shift($this->nextStatuses);
    }

    /**
     * @return array{int, string, string}
     */
    private function handleControl(string $method, string $path, string $body): array
    {
        $data = $this->parseControlBody($body);

        if ('/__mock__/reset' === $path) {
            $this->reset();

            return [200, 'application/json', json_encode(['status' => 'reset'], JSON_THROW_ON_ERROR)];
        }

        if ('/__mock__/set-package' === $path && 'POST' === $method) {
            if (isset($data['_force_response']) && is_string($data['_force_response'])) {
                $this->pushForcedResponse($data['_force_response']);
            } elseif (isset($data['_raw']) && is_string($data['_raw'])) {
                $this->pushForcedResponse($data['_raw']);
            } elseif (!empty($data['archive'])) {
                $decoded = isset($data['content_b64']) && is_string($data['content_b64']) ? (base64_decode($data['content_b64'], true) ?: '') : '';
                if (isset($data['path']) && is_string($data['path'])) {
                    $this->addArchiveAtPath($data['path'], $decoded);
                } elseif (isset($data['ref']) && is_string($data['ref'])) {
                    $this->addArchive($data['ref'], $decoded);
                }
            } else {
                $this->addPackage($data);
            }

            return [200, 'application/json', json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR)];
        }

        if ('/__mock__/set-github' === $path && 'POST' === $method) {
            $branches = is_array($data['branches'] ?? null) ? $data['branches'] : [];
            $tags = is_array($data['tags'] ?? null) ? $data['tags'] : [];
            $this->setBranches($branches);
            $this->setTags($tags);

            return [200, 'application/json', json_encode(['status' => 'github fixtures set'], JSON_THROW_ON_ERROR)];
        }

        if ('/__mock__/set-status' === $path && 'POST' === $method) {
            if (isset($data['status'])) {
                $this->pushStatus((int) $data['status']);
            }

            return [200, 'application/json', json_encode(['status' => 'next status queued'], JSON_THROW_ON_ERROR)];
        }

        if ('/__mock__/requests' === $path && 'GET' === $method) {
            return [200, 'application/json', json_encode($this->getRequests(), JSON_THROW_ON_ERROR)];
        }

        if ('/__mock__/clear-requests' === $path && 'POST' === $method) {
            $this->clearRequests();

            return [200, 'application/json', json_encode(['status' => 'requests cleared'], JSON_THROW_ON_ERROR)];
        }

        return [404, 'application/json', json_encode(['error' => 'Unknown mock control'], JSON_THROW_ON_ERROR)];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseControlBody(string $body): array
    {
        if ('' === $body) {
            return [];
        }
        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Loads the server state from a file written by a previous request.
     * Used because PHP's built-in server invokes the router script for
     * every request in a fresh process, so the in-memory state has to be
     * persisted between calls.
     */
    public static function load(string $stateFile): self
    {
        $server = new self();
        if (!is_file($stateFile)) {
            return $server;
        }

        $contents = file_get_contents($stateFile);
        if (!is_string($contents) || '' === $contents) {
            return $server;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return $server;
        }

        $server->packages = is_array($data['packages'] ?? null) ? array_values($data['packages']) : [];
        $server->branches = is_array($data['branches'] ?? null) ? array_values($data['branches']) : [];
        $server->tags = is_array($data['tags'] ?? null) ? array_values($data['tags']) : [];
        $archives = [];
        foreach (($data['archives'] ?? []) as $key => $content) {
            $decoded = base64_decode((string) $content, true);
            if (null !== $decoded) {
                $archives[(string) $key] = $decoded;
            }
        }
        $server->archives = $archives;
        $server->nextStatuses = is_array($data['nextStatuses'] ?? null) ? array_map('intval', $data['nextStatuses']) : [];
        $server->requests = is_array($data['requests'] ?? null) ? array_values($data['requests']) : [];
        $server->forcedResponses = is_array($data['forcedResponses'] ?? null) ? array_values($data['forcedResponses']) : [];

        return $server;
    }

    public function save(string $stateFile): void
    {
        $directory = dirname($stateFile);
        if (!is_dir($directory)) {
            createDirectoryTreeForMock($directory);
        }

        $archives = [];
        foreach ($this->archives as $key => $content) {
            $archives[$key] = base64_encode($content);
        }

        $payload = json_encode([
            'packages' => $this->packages,
            'branches' => $this->branches,
            'tags' => $this->tags,
            'archives' => $archives,
            'nextStatuses' => $this->nextStatuses,
            'requests' => $this->requests,
            'forcedResponses' => $this->forcedResponses,
        ], JSON_THROW_ON_ERROR);

        file_put_contents($stateFile, $payload, LOCK_EX);
    }
}

if (!function_exists('createDirectoryTreeForMock')) {
    function createDirectoryTreeForMock(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }
        if (!mkdir($directory, 0o755, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create '.$directory);
        }
    }
}
