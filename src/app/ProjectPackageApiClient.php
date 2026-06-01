<?php

declare(strict_types=1);

namespace Oak\Engine\Installer;

final readonly class ProjectPackageApiClient
{
    public function __construct(
        private string $baseUrl,
        private string $packageType = 'runner',
        private string $installUuid = '',
        private string $projectApiToken = '',
    ) {
    }

    /**
     * @return list<array{
     *     package_type: string,
     *     package_id: string,
     *     version: string,
     *     channel: string,
     *     package_name: string,
     *     archive_size: int,
     *     archive_sha256: string,
     *     download_url: string,
     *     composer: array<string, mixed>
     * }>
     */
    public function listPackages(): array
    {
        $response = $this->request([
            'type' => $this->packageType,
        ]);
        $packages = $response['packages'] ?? null;
        if (!is_array($packages)) {
            throw new \RuntimeException('Project package API returned no package list.');
        }

        $normalized = [];
        foreach ($packages as $package) {
            if (!is_array($package)) {
                continue;
            }

            $packageType = $this->normalizeScalar($package['package_type'] ?? null, $this->packageType);
            $packageId = $this->normalizeScalar($package['package_id'] ?? null);
            $version = $this->normalizeScalar($package['version'] ?? null);
            $channel = $this->normalizeScalar($package['channel'] ?? null, 'unknown');
            $packageName = $this->normalizeScalar($package['package_name'] ?? null);
            $downloadUrl = $this->resolveDownloadUrl($this->normalizeScalar($package['download_url'] ?? null));
            if ('' === $packageId || '' === $version || '' === $packageName || '' === $downloadUrl) {
                continue;
            }

            $normalized[] = [
                'package_type' => $packageType,
                'package_id' => $packageId,
                'version' => $version,
                'channel' => $channel,
                'package_name' => $packageName,
                'archive_size' => is_scalar($package['archive_size'] ?? null) ? (int) $package['archive_size'] : 0,
                'archive_sha256' => $this->normalizeScalar($package['archive_sha256'] ?? null),
                'download_url' => $downloadUrl,
                'composer' => $this->normalizeMetadataArray($package['composer'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @return array{
     *     package_type: string,
     *     package_id: string,
     *     version: string,
     *     channel: string,
     *     package_name: string,
     *     archive_size: int,
     *     archive_sha256: string,
     *     download_url: string,
     *     composer: array<string, mixed>
     * }
     */
    public function getPackage(string $packageId, ?string $version = null): array
    {
        foreach ($this->listPackages() as $package) {
            if ($package['package_id'] === $packageId && (null === $version || $package['version'] === $version)) {
                return $package;
            }
        }

        throw new \RuntimeException(sprintf('%s package "%s" (%s) was not found.', ucfirst($this->packageType), $packageId, $version ?? 'latest'));
    }

    public function downloadPackage(string $packageId, ?string $version = null): string
    {
        $package = $this->getPackage($packageId, $version);
        /** @var non-empty-string $downloadUrl */
        $downloadUrl = $package['download_url'];
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $downloadUrl,
            CURLOPT_POST => false,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => $this->buildHeaders(),
        ]);

        $response = curl_exec($ch);
        if (false === $response) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new \RuntimeException(sprintf('Package download failed: %s', $error));
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (200 !== $httpCode) {
            throw new \RuntimeException(sprintf('Package download failed: HTTP %d', $httpCode));
        }

        return (string) $response;
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array<string, mixed>
     */
    private function request(array $payload): array
    {
        /** @var non-empty-string $baseUrl */
        $baseUrl = $this->baseUrl;
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $baseUrl,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($this->buildPayload($payload)),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $this->buildHeaders(true),
        ]);

        $response = curl_exec($ch);
        if (false === $response) {
            $error = curl_error($ch);
            curl_close($ch);

            throw new \RuntimeException(sprintf('Project package API request failed: %s', $error));
        }

        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 400) {
            throw new \RuntimeException(sprintf('Project package API returned HTTP %d.', $httpCode));
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Project package API returned invalid JSON.');
        }

        return $this->normalizeMetadataArray($decoded);
    }

    /**
     * @param array<string, string> $payload
     *
     * @return array<string, string>
     */
    private function buildPayload(array $payload): array
    {
        $installUuid = trim($this->installUuid);
        if ('' === $installUuid) {
            return $payload;
        }

        if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', strtolower($installUuid))) {
            throw new \RuntimeException(sprintf('The install UUID "%s" is invalid.', $installUuid));
        }

        $payload['install_uuid'] = strtolower($installUuid);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function buildHeaders(bool $acceptJson = false): array
    {
        $headers = [];
        if ($acceptJson) {
            $headers[] = 'Accept: application/json';
        }

        $projectApiToken = trim($this->projectApiToken);
        if ('' !== $projectApiToken) {
            $headers[] = 'Authorization: Bearer '.$projectApiToken;
        }

        $installUuid = trim($this->installUuid);
        if ('' !== $installUuid) {
            $headers[] = 'X-Install-UUID: '.strtolower($installUuid);
        }

        return $headers;
    }

    private function normalizeScalar(mixed $value, string $fallback = ''): string
    {
        if (!is_scalar($value)) {
            return $fallback;
        }

        $normalized = trim((string) $value);

        return '' !== $normalized ? $normalized : $fallback;
    }

    private function resolveDownloadUrl(string $downloadUrl): string
    {
        if ('' === $downloadUrl || str_starts_with($downloadUrl, 'http://') || str_starts_with($downloadUrl, 'https://')) {
            return $downloadUrl;
        }

        return rtrim($this->baseUrl, '/').'/'.ltrim($downloadUrl, '/');
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeMetadataArray(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        /** @var array<string, mixed> $normalized */
        $normalized = [];
        foreach ($value as $key => $item) {
            $normalized[(string) $key] = $item;
        }

        return $normalized;
    }
}
