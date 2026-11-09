<?php

declare(strict_types=1);

/**
 * Registers an INSTALL_UUID on srcma.eu so that restricted packages
 * become accessible for updates from this specific installation.
 *
 * Called automatically after `install_all` completes.
 *
 * Usage:
 *   php register-install-uuid.php <api_url> <project_token> <install_uuid> [packages...]
 */

$apiUrl = rtrim($argv[1] ?? '', '/');
$token = $argv[2] ?? '';
$installUuid = $argv[3] ?? '';

if ('' === $apiUrl || '' === $token || '' === $installUuid) {
    fwrite(STDERR, "Usage: php register-install-uuid.php <api_url> <token> <install_uuid>\n");
    exit(1);
}

if (1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $installUuid)) {
    fwrite(STDERR, "Invalid install UUID format: $installUuid\n");
    exit(1);
}

$ch = curl_init($apiUrl.'?register_install_uuid=1');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
        'Authorization: Bearer '.$token,
        'Content-Type: application/x-www-form-urlencoded',
    ],
    CURLOPT_POSTFIELDS => http_build_query(['install_uuid' => strtolower($installUuid)]),
]);

$response = curl_exec($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if (false === $response) {
    fwrite(STDERR, "CURL error: $curlError\n");
    exit(1);
}

$decoded = json_decode((string) $response, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "Invalid response (HTTP $httpCode): ".substr((string) $response, 0, 300)."\n");
    exit(1);
}

if ($httpCode >= 400) {
    fwrite(STDERR, 'HTTP '.$httpCode.': '.($decoded['error'] ?? 'Unknown error')."\n");
    exit(1);
}

echo "Install UUID registered successfully.\n";
echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
