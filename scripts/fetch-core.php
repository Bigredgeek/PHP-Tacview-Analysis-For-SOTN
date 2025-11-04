<?php

declare(strict_types=1);

// Simple bootstrap helper to ensure the shared Tacview core exists during builds.

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
    $zipPath = $tmpZip; // fall back to original temp file without extension
}

touch($zipPath);

fwrite(STDOUT, "Downloading php-tacview-core from {$archiveUrl}..." . PHP_EOL);

$context = stream_context_create([
    'http' => [
        'timeout' => 60,
    ],
]);

$downloaded = @file_get_contents($archiveUrl, false, $context);
if ($downloaded === false) {
    fwrite(STDERR, "Unable to download core archive from {$archiveUrl}." . PHP_EOL);
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

if (!rename($extractedRoot, $targetDir)) {
    fwrite(STDERR, "Failed to move extracted core into place at {$targetDir}." . PHP_EOL);
    return 1;
}

@unlink($zipPath);

$removeDir = static function (string $dir) use (&$removeDir): void {
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $dir . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            $removeDir($path);
        } else {
            @unlink($path);
        }
    }

    @rmdir($dir);
};

$removeDir($extractDirectory);

fwrite(STDOUT, "php-tacview-core installed to {$targetDir}." . PHP_EOL);

return 0;
