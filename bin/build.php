<?php

/**
 * OakEngine Installer - Build Script.
 *
 * Creates a self-extracting PHP archive (oakengine-installer.php) that
 * contains the complete installer and extracts it to ./update/ when
 * called in a browser.
 *
 * Usage: php bin/build.php
 */

declare(strict_types=1);

$srcDir = dirname(__DIR__) . '/src';
$buildDir = dirname(__DIR__) . '/build';
$outputFile = $buildDir . '/oakengine-installer.php';

if (!class_exists(ZipArchive::class)) {
    fwrite(STDERR, "Error: ZipArchive extension is required.\n");
    exit(1);
}

if (!is_dir($srcDir)) {
    fwrite(STDERR, "Error: Source directory not found: {$srcDir}\n");
    exit(1);
}

// Create in-memory ZIP archive
$zip = new ZipArchive();
$tmpZip = tempnam(sys_get_temp_dir(), 'oakengine_build_');

if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Error: Cannot create temporary ZIP archive.\n");
    exit(1);
}

// Add all files from src/ recursively
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($srcDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

$fileCount = 0;
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }

    $relativePath = substr($file->getPathname(), strlen($srcDir) + 1);
    $zip->addFile($file->getPathname(), $relativePath);
    $fileCount++;
}

$zip->close();

if ($fileCount === 0) {
    fwrite(STDERR, "Error: No files found in {$srcDir}\n");
    @unlink($tmpZip);
    exit(1);
}

// Read ZIP content and base64 encode
$zipContent = file_get_contents($tmpZip);
$base64 = base64_encode($zipContent);
@unlink($tmpZip);

$zipSize = strlen($zipContent);
$b64Size = strlen($base64);

// Create build directory
if (!is_dir($buildDir)) {
    mkdir($buildDir, 0755, true);
}

// Build the self-extracting PHP as three parts: prefix + base64 + suffix
$prefix = <<<'PHP_PREFIX'
<?php

/**
 * OakEngine Installer - Self-Extracting Archive.
 *
 * This file extracts the complete OakEngine Installer into the ./update/
 * directory and redirects the browser there.
 *
 * Usage: Upload this file to your webroot and call it in the browser.
 */

declare(strict_types=1);

// Embedded archive (base64-encoded ZIP)
$archive = '

PHP_PREFIX;

$suffix = <<<'PHP_SUFFIX'
';

// Only run in web context
if (PHP_SAPI === 'cli') {
    fwrite(STDERR, "This file is designed to be called from a web browser.\n");
    fwrite(STDERR, "Upload it to your webroot and open it in a browser.\n");
    exit(1);
}

$updateDir = __DIR__ . '/update';

// Decode archive
$zipData = base64_decode($archive, true);
if ($zipData === false) {
    http_response_code(500);
    echo 'Error: Corrupted archive data.';
    exit;
}

// Write temporary ZIP
$tmpFile = tempnam(sys_get_temp_dir(), 'oakengine_extract_');
if ($tmpFile === false || file_put_contents($tmpFile, $zipData) === false) {
    http_response_code(500);
    echo 'Error: Cannot write temporary archive.';
    exit;
}

// Create update directory
if (!is_dir($updateDir)) {
    mkdir($updateDir, 0755, true);
}

// Backup config.php if it exists (preserve user settings)
$configFile = $updateDir . '/config.php';
$configBackup = null;
if (file_exists($configFile)) {
    $configBackup = file_get_contents($configFile);
}

// Extract (always, to support updates)
$zip = new ZipArchive();
if ($zip->open($tmpFile) !== true) {
    @unlink($tmpFile);
    http_response_code(500);
    echo 'Error: Cannot open archive.';
    exit;
}

if (!$zip->extractTo($updateDir)) {
    $zip->close();
    @unlink($tmpFile);
    http_response_code(500);
    echo 'Error: Extraction failed.';
    exit;
}

$zip->close();
@unlink($tmpFile);

// Rename config.example.php to config.php
$configExample = $updateDir . '/config.example.php';
if (file_exists($configExample)) {
    if (null !== $configBackup) {
        // Restore existing config.php (preserve user settings)
        file_put_contents($configFile, $configBackup);
        @unlink($configExample);
    } elseif (!file_exists($configFile)) {
        // First install: rename example to config.php
        rename($configExample, $configFile);
    }
}

// Set permissions on extracted files
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($updateDir, RecursiveDirectoryIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY
);

foreach ($iterator as $file) {
    if ($file->isFile()) {
        @chmod($file->getPathname(), 0644);
    }
}

// Redirect to installer
header('Location: ./update/');
exit;
PHP_SUFFIX;

$phpCode = $prefix . $base64 . $suffix;

file_put_contents($outputFile, $phpCode);

$outputSize = filesize($outputFile);

echo "Build successful!\n";
echo sprintf("  Files packed:     %d\n", $fileCount);
echo sprintf("  ZIP size:         %s\n", formatBytes($zipSize));
echo sprintf("  Base64 size:      %s\n", formatBytes($b64Size));
echo sprintf("  Output file:      %s\n", $outputFile);
echo sprintf("  Output size:      %s\n", formatBytes($outputSize));

function formatBytes(int $bytes): string
{
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1) . ' MB';
    }
    if ($bytes >= 1024) {
        return number_format($bytes / 1024, 1) . ' KB';
    }
    return $bytes . ' B';
}
