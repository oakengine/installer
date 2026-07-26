<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../src/index.php';

final class ZipSecurityTest extends TestCase
{
    private static function extractor(): \Oak\Engine\Installer\ProjectPackageArchiveExtractor
    {
        return new \Oak\Engine\Installer\ProjectPackageArchiveExtractor();
    }

    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/oakengine_zipsectest_' . uniqid();
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeRecursively($this->tempDir);
        $this->cleanupEscapedFiles();
    }

    private function removeRecursively(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);

            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }

    private function cleanupEscapedFiles(): void
    {
        foreach (['oak_evil_escape.txt', 'oak_evil_target_xyz'] as $candidate) {
            $path = sys_get_temp_dir() . '/' . $candidate;
            if (is_file($path)) {
                @unlink($path);
            }
            if (is_dir($path)) {
                $this->removeRecursively($path);
            }
        }
    }

    private function createZip(array $entries): string
    {
        $zipFile = $this->tempDir . '/test.zip';
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $zipFile;
    }

    public function testIsZipEntryPathSafeValidPaths(): void
    {
        $this->assertTrue(isZipEntryPathSafe('file.txt'));
        $this->assertTrue(isZipEntryPathSafe('dir/file.txt'));
        $this->assertTrue(isZipEntryPathSafe('deep/nested/path/file.txt'));
        $this->assertTrue(isZipEntryPathSafe('./file.txt'));
        $this->assertTrue(isZipEntryPathSafe('dir/./file.txt'));
        $this->assertTrue(isZipEntryPathSafe('dir/sub/'));
    }

    public function testIsZipEntryPathRejectsPathTraversal(): void
    {
        $this->assertFalse(isZipEntryPathSafe('../file.txt'));
        $this->assertFalse(isZipEntryPathSafe('../../etc/passwd'));
        $this->assertFalse(isZipEntryPathSafe('dir/../file.txt'));
        $this->assertFalse(isZipEntryPathSafe('dir/../../escape.txt'));
    }

    public function testIsZipEntryPathRejectsAbsolutePaths(): void
    {
        $this->assertFalse(isZipEntryPathSafe('/etc/passwd'));
        $this->assertFalse(isZipEntryPathSafe('/file.txt'));
        $this->assertFalse(isZipEntryPathSafe('C:\\Windows\\file.txt'));
        $this->assertFalse(isZipEntryPathSafe('C:/Windows/file.txt'));
        $this->assertFalse(isZipEntryPathSafe('D:\\file.txt'));
    }

    public function testIsZipEntryPathRejectsEmpty(): void
    {
        $this->assertFalse(isZipEntryPathSafe(''));
    }

    public function testOpenZipForSafeExtractionAcceptsValidArchive(): void
    {
        $zipFile = $this->createZip([
            'repo/file.txt' => 'safe content',
            'repo/dir/other.txt' => 'more content',
        ]);

        $opened = openZipForSafeExtraction($zipFile);
        $this->assertSame(2, $opened['entry_count']);
        $opened['zip']->close();
    }

    public function testOpenZipForSafeExtractionRejectsTraversalArchive(): void
    {
        $zipFile = $this->createZip([
            'repo/legit.txt' => 'fine',
            '../../oak_evil_escape.txt' => 'malicious content',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/unsafe path|Zip Slip/i');
        try {
            openZipForSafeExtraction($zipFile);
        } finally {
            $this->assertFileDoesNotExist(sys_get_temp_dir() . '/oak_evil_escape.txt');
        }
    }

    public function testOpenZipForSafeExtractionRejectsAbsolutePathArchive(): void
    {
        $zipFile = $this->createZip([
            '/tmp/oak_evil_escape.txt' => 'malicious',
        ]);

        $this->expectException(\RuntimeException::class);
        try {
            openZipForSafeExtraction($zipFile);
        } finally {
            $this->assertFileDoesNotExist(sys_get_temp_dir() . '/oak_evil_escape.txt');
        }
    }

    public function testExtractTarGzRejectsMaliciousArchive(): void
    {
        $tarGzContent = $this->buildMaliciousTarGz();

        $extractor = self::extractor();
        $targetDir = $this->tempDir . '/target';
        mkdir($targetDir);

        $threw = false;
        try {
            $extractor->extractTarGz($tarGzContent, $targetDir, [], []);
        } catch (\Throwable $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Malicious tar archive should have been rejected');
        $this->assertDirectoryDoesNotExist(sys_get_temp_dir() . '/oak_evil_target_xyz');
    }

    public function testExtractTarGzWithSafeArchiveExtractsCorrectly(): void
    {
        $tarGzContent = $this->buildSafeTarGz();

        $extractor = self::extractor();
        $targetDir = $this->tempDir . '/target';

        $result = $extractor->extractTarGz($tarGzContent, $targetDir, [], []);

        $this->assertFileExists($targetDir . '/file1.txt');
        $this->assertFileExists($targetDir . '/dir/file2.txt');
        $this->assertGreaterThan(0, count($result['extracted']));
    }

    private function buildSafeTarGz(): string
    {
        $tarFile = $this->tempDir . '/safe.tar';
        $tar = new \PharData($tarFile);
        $tar->addFromString('repo/file1.txt', 'content 1');
        $tar->addFromString('repo/dir/file2.txt', 'content 2');
        unset($tar);

        $gzFile = $tarFile . '.gz';
        $gz = gzencode((string) file_get_contents($tarFile), 9);
        if (false === $gz) {
            $this->fail('Failed to gzip the tar archive.');
        }
        file_put_contents($gzFile, $gz);
        unlink($tarFile);

        return (string) file_get_contents($gzFile);
    }

    private function buildMaliciousTarGz(): string
    {
        $entries = [
            'repo/legit.txt' => 'legit content',
            '../../oak_evil_target_xyz/malicious.txt' => 'pwned',
        ];

        $tarStream = fopen('php://memory', 'w+');
        foreach ($entries as $name => $content) {
            $this->appendTarEntry($tarStream, $name, $content);
        }
        rewind($tarStream);
        $tarContent = (string) stream_get_contents($tarStream);
        fclose($tarStream);

        $gz = gzencode($tarContent, 9);
        if (false === $gz) {
            $this->fail('Failed to gzip the malicious tar archive.');
        }

        return $gz;
    }

    private function appendTarEntry($stream, string $name, string $content): void
    {
        $size = strlen($content);
        $header = '';
        $header .= str_pad(substr($name, 0, 100), 100, "\0");
        $header .= str_pad('0000644', 8, '0', STR_PAD_LEFT);
        $header .= str_pad('0001750', 8, '0', STR_PAD_LEFT);
        $header .= str_pad('0001750', 8, '0', STR_PAD_LEFT);
        $header .= str_pad((string) decoct($size), 12, '0', STR_PAD_LEFT);
        $header .= str_pad((string) time(), 12, '0', STR_PAD_LEFT);
        $header .= '        ';
        $header .= '0';
        $header .= str_pad('', 100, "\0");
        $header .= 'ustar ';
        $header .= '00';
        $header .= str_pad('', 32, "\0");
        $header .= str_pad('', 32, "\0");
        $header .= str_pad('', 8, "\0");
        $header .= str_pad('', 8, "\0");
        $header .= str_pad('', 155, "\0");
        $header .= str_pad('', 12, "\0");

        if (512 !== strlen($header)) {
            throw new \RuntimeException('Tar header length mismatch: '.strlen($header).' bytes (expected 512).');
        }

        $checksum = 0;
        for ($i = 0; $i < 512; ++$i) {
            $checksum += ord($header[$i]);
        }
        $checksumStr = str_pad((string) decoct($checksum), 6, '0', STR_PAD_LEFT)."\0 ";
        $header = substr_replace($header, $checksumStr, 148, 8);

        fwrite($stream, $header);
        fwrite($stream, $content);
        $padTo512 = 512 - ($size % 512);
        if (512 !== $padTo512) {
            fwrite($stream, str_repeat("\0", $padTo512));
        }

        fwrite($stream, str_repeat("\0", 1024));
    }
}