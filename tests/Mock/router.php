<?php

declare(strict_types=1);

/**
 * Front controller for the test mock server. Used as the router script for
 * PHP's built-in HTTP server (`php -S`). The actual server state lives in
 * a shared file that the test trait writes to.
 */

require_once __DIR__.'/MockServer.php';

use Tests\Mock\MockServer;

$stateFile = getenv('OAK_MOCK_STATE_FILE');
if (!is_string($stateFile) || '' === $stateFile) {
    fwrite(STDERR, "OAK_MOCK_STATE_FILE is not set\n");
    http_response_code(500);
    echo 'mock server not configured';

    return;
}

$server = MockServer::load($stateFile);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
$uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
$parts = parse_url($uri);
$path = is_array($parts) ? (string) ($parts['path'] ?? '/') : '/';
parse_str(is_array($parts) ? (string) ($parts['query'] ?? '') : '', $query);
$body = file_get_contents('php://input');
if (false === $body) {
    $body = '';
}
$headers = [];
foreach ($_SERVER as $key => $value) {
    if (str_starts_with((string) $key, 'HTTP_')) {
        $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
        $headers[$name] = (string) $value;
    }
}

[$status, $contentType, $payload] = $server->handle($method, $path, is_array($query) ? $query : [], $body, $headers);

$server->save($stateFile);

http_response_code($status);
header('Content-Type: '.$contentType);
echo $payload;
