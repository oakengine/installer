<?php

declare(strict_types=1);

namespace Oak\Engine\Installer;

function createDirectoryTree(string $directory, int $mode = 0o755): bool
{
    if (is_dir($directory)) {
        return true;
    }

    $directoriesToCreate = [];
    $currentDirectory = $directory;
    while (!is_dir($currentDirectory)) {
        $directoriesToCreate[] = $currentDirectory;
        $parentDirectory = dirname($currentDirectory);
        if ($parentDirectory === $currentDirectory) {
            break;
        }

        $currentDirectory = $parentDirectory;
    }

    foreach (array_reverse($directoriesToCreate) as $directoryToCreate) {
        if (!mkdir($directoryToCreate, $mode) && !is_dir($directoryToCreate)) {
            return false;
        }

        if (!chmod($directoryToCreate, $mode)) {
            return false;
        }
    }

    return true;
}
