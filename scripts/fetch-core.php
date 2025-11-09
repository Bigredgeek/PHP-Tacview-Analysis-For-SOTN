<?php

declare(strict_types=1);

/**
 * Recursively copy a directory tree from $source to $destination.
 */
function recursiveCopy(string $source, string $destination): bool
{
    if (!is_dir($source)) {
        return false;
    }

    if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
        return false;
    }

    $entries = scandir($source);
    if ($entries === false) {
        return false;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $sourcePath = $source . DIRECTORY_SEPARATOR . $entry;
        $destinationPath = $destination . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($sourcePath)) {
            if (!recursiveCopy($sourcePath, $destinationPath)) {
                return false;
            }
        } else {
            if (!copy($sourcePath, $destinationPath)) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Recursively remove a directory tree if it exists.
 */
function removeDirectory(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    $entries = scandir($dir);
    if ($entries === false) {
        return;
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
}

// Simple bootstrap helper to ensure the shared Tacview core exists during builds.

function main(): int
{
    $root = dirname(__DIR__);
    $targetDir = $root . DIRECTORY_SEPARATOR . 'php-tacview-core';
    $fallbackLocalCore = $root . DIRECTORY_SEPARATOR . 'core';

    if (is_dir($targetDir) || is_dir($fallbackLocalCore)) {
        fwrite(STDOUT, "Tacview core assets already present locally; skipping download." . PHP_EOL);
        return 0;
    }

    $archiveUrl = getenv('TACVIEW_CORE_ARCHIVE_URL');
    if ($archiveUrl === false || trim($archiveUrl) === '') {
        $archiveUrl = 'https://codeload.github.com/Bigredgeek/php-tacview-core/zip/refs/heads/main';
    }

    $tmpZip = tempnam(sys_get_temp_dir(), 'tacview-core-');
    if ($tmpZip === false) {
        fwrite(STDERR, "Failed to create temporary file for core download." . PHP_EOL);
        return 1;
    }

    $zipPath = $tmpZip . '.zip';
    if (!rename($tmpZip, $zipPath)) {
        fwrite(STDOUT, "Warning: failed to rename temporary file to .zip extension; proceeding with original name." . PHP_EOL);
        $zipPath = $tmpZip; // fall back to original temp file without extension
    }

    touch($zipPath);

    fwrite(STDOUT, "Downloading php-tacview-core from {$archiveUrl}..." . PHP_EOL);

    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $downloaded = file_get_contents($archiveUrl, false, $context);
    if ($downloaded === false) {
        $error = error_get_last();
        $errorMsg = $error['message'] ?? 'unknown error';
        fwrite(STDERR, "Unable to download core archive from {$archiveUrl}: {$errorMsg}." . PHP_EOL);
        @unlink($tmpZip);
        return 1;
    }

    if (file_put_contents($zipPath, $downloaded) === false) {
        fwrite(STDERR, "Failed to write core archive to temporary file." . PHP_EOL);
        return 1;
    }

    $zipClass = 'ZipArchive';
    if (!class_exists($zipClass)) {
        fwrite(STDERR, "ZipArchive extension is required to unpack php-tacview-core." . PHP_EOL);
        return 1;
    }

    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        fwrite(STDERR, "Failed to open downloaded core archive." . PHP_EOL);
        return 1;
    }

    $extractDirectory = $zipPath . '_dir';
    if (!mkdir($extractDirectory) && !is_dir($extractDirectory)) {
        fwrite(STDERR, "Unable to create temporary extraction directory." . PHP_EOL);
        $zip->close();
        return 1;
    }

    if (!$zip->extractTo($extractDirectory)) {
        fwrite(STDERR, "Failed to extract core archive." . PHP_EOL);
        $zip->close();
        return 1;
    }

    $zip->close();

    $entries = array_filter(
        scandir($extractDirectory) ?: [],
        static fn(string $entry): bool => $entry !== '.' && $entry !== '..'
    );

    if ($entries === []) {
        fwrite(STDERR, "Core archive did not contain any files." . PHP_EOL);
        return 1;
    }

    $extractedRoot = $extractDirectory . DIRECTORY_SEPARATOR . reset($entries);

    if (!is_dir($extractedRoot)) {
        fwrite(STDERR, "Unexpected archive structure: {$extractedRoot} is not a directory." . PHP_EOL);
        return 1;
    }

    $renameSucceeded = @rename($extractedRoot, $targetDir);
    if (!$renameSucceeded) {
        $renameError = error_get_last();
        $errorDetail = $renameError['message'] ?? 'unknown error';
        fwrite(STDOUT, "Rename from {$extractedRoot} to {$targetDir} failed ({$errorDetail}). Attempting recursive copy..." . PHP_EOL);

        if (!recursiveCopy($extractedRoot, $targetDir)) {
            fwrite(STDERR, "Failed to copy extracted core into place at {$targetDir}." . PHP_EOL);
            removeDirectory($targetDir);
            removeDirectory($extractDirectory);
            @unlink($zipPath);
            return 1;
        }
    }

    @unlink($zipPath);

    removeDirectory($extractDirectory);

    fwrite(STDOUT, "php-tacview-core installed to {$targetDir}." . PHP_EOL);

    return 0;
}

exit(main());
