<?php

declare(strict_types=1);

class GitHubClient
{
    private readonly string $baseUrl;
    private readonly string $userAgent;

    public function __construct(string $baseUrl, private readonly string $token, private readonly string $installerVersion = 'unknown')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->userAgent = 'OakEngineInstaller/'.$this->installerVersion;
    }

    /**
     * @return array<mixed>
     */
    private function request(string $endpoint): array
    {
        $url = $this->baseUrl.$endpoint;
        $headers = ['User-Agent: '.$this->userAgent, 'Accept: application/vnd.github.v3+json'];
        if ('' !== $this->token) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }
        $ch = curl_init();
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 30,
        ];
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if (false === $response) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("CURL Error: {$error}");
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (403 === $httpCode && '' !== $this->token) {
            $headers = ['User-Agent: '.$this->userAgent, 'Accept: application/vnd.github.v3+json'];
            $ch = curl_init();
            $options[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            if (false !== $response) {
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }
            curl_close($ch);
        }

        if ($httpCode >= 400) {
            throw new RuntimeException("GitHub API Error: HTTP {$httpCode}");
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);
        } else {
            $decoded = null;
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * @return array<int, array{name: string, commit: string}>
     */
    public function getBranches(string $repo): array
    {
        $branches = [];
        $page = 1;
        do {
            $response = $this->request("/repos/{$repo}/branches?per_page=100&page={$page}");
            if (empty($response)) {
                break;
            }
            foreach ($response as $branch) {
                if (!is_array($branch)) {
                    continue;
                }
                if (isset($branch['name']) && (is_string($branch['name']) || is_int($branch['name']))) {
                    $branchName = trim((string) $branch['name']);
                } else {
                    $branchName = '';
                }

                $commitData = $branch['commit'] ?? null;
                if (is_array($commitData)) {
                    $sha = '';
                    if (isset($commitData['sha']) && (is_string($commitData['sha']) || is_int($commitData['sha']))) {
                        $sha = (string) $commitData['sha'];
                    }
                    if ('' !== $branchName && '' !== $sha) {
                        $branches[] = ['name' => $branchName, 'commit' => $sha];
                    }
                }
            }
            ++$page;
        } while (100 === count($response));

        return $branches;
    }

    /**
     * @return array<int, array{name: string, commit: string}>
     */
    public function getTags(string $repo): array
    {
        $tags = [];
        $page = 1;
        do {
            $response = $this->request("/repos/{$repo}/tags?per_page=100&page={$page}");
            if (empty($response)) {
                break;
            }
            foreach ($response as $tag) {
                if (!is_array($tag)) {
                    continue;
                }
                if (isset($tag['name']) && (is_string($tag['name']) || is_int($tag['name']))) {
                    $tagName = trim((string) $tag['name']);
                } else {
                    $tagName = '';
                }

                $commitData = $tag['commit'] ?? null;
                if (is_array($commitData)) {
                    $sha = '';
                    if (isset($commitData['sha']) && (is_string($commitData['sha']) || is_int($commitData['sha']))) {
                        $sha = (string) $commitData['sha'];
                    }
                    if ('' !== $tagName && '' !== $sha) {
                        $tags[] = ['name' => $tagName, 'commit' => $sha];
                    }
                }
            }
            ++$page;
        } while (100 === count($response));

        return $tags;
    }

    public function downloadArchive(string $repo, string $ref, string $refType = 'branch'): string
    {
        $url = $this->baseUrl."/repos/{$repo}/zipball/{$ref}";
        $headers = ['User-Agent: '.$this->userAgent];
        if ('' !== $this->token) {
            $headers[] = 'Authorization: Bearer '.$this->token;
        }
        $ch = curl_init();
        /** @var array<int, mixed> $options */
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 300,
        ];
        curl_setopt_array($ch, $options);
        $response = curl_exec($ch);
        if (false === $response) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("CURL Error: {$error}");
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // If request with token failed with 403, retry without token (for public repos)
        if (403 === $httpCode && '' !== $this->token) {
            $headers = ['User-Agent: '.$this->userAgent];
            $ch = curl_init();
            $options[CURLOPT_HTTPHEADER] = $headers;
            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            if (false !== $response) {
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }
            curl_close($ch);
        }

        if (200 !== $httpCode) {
            throw new RuntimeException("Download failed: HTTP {$httpCode}");
        }

        return is_string($response) ? $response : '';
    }
}
