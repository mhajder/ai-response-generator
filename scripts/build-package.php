#!/usr/bin/env php
<?php

/**
 * Package the osTicket plugin into dist/ai-response-generator.phar and
 * dist/ai-response-generator-<version>.zip.
 *
 * Requires: php -dphar.readonly=0 scripts/build-package.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root . '/plugin.php';
if (!is_file($manifestPath)) {
    fwrite(STDERR, "Missing plugin.php\n");
    exit(1);
}

$manifest = include $manifestPath;
if (!is_array($manifest) || empty($manifest['version'])) {
    fwrite(STDERR, "Invalid plugin.php manifest\n");
    exit(1);
}

$version = (string) $manifest['version'];
$distDir = $root . '/dist';
if (!is_dir($distDir) && !mkdir($distDir, 0755, true) && !is_dir($distDir)) {
    fwrite(STDERR, "Unable to create dist directory\n");
    exit(1);
}

$includeFiles = [
    'LICENSE',
    'README.md',
    'plugin.php',
];

$includeDirs = [
    'api',
    'assets',
    'src',
];

$filesToPack = [];
foreach ($includeFiles as $rel) {
    $abs = $root . '/' . $rel;
    if (is_file($abs)) {
        $filesToPack[$rel] = $abs;
    }
}

foreach ($includeDirs as $dir) {
    $absDir = $root . '/' . $dir;
    if (!is_dir($absDir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($absDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }
        $absPath = $fileInfo->getPathname();
        $relPath = ltrim(str_replace('\\', '/', substr($absPath, strlen($root))), '/');
        $filesToPack[$relPath] = $absPath;
    }
}

ksort($filesToPack);

$pharPath = $distDir . '/ai-response-generator.phar';
if (is_file($pharPath)) {
    unlink($pharPath);
}

if (ini_get('phar.readonly')) {
    fwrite(STDERR, "Run with: php -dphar.readonly=0 scripts/build-package.php\n");
    exit(1);
}

$phar = new Phar($pharPath, 0, 'ai-response-generator.phar');
$phar->startBuffering();
foreach ($filesToPack as $rel => $abs) {
    $phar->addFile($abs, $rel);
}
$phar->setStub('<?php __HALT_COMPILER();');
$phar->stopBuffering();

fwrite(STDOUT, sprintf("Built PHAR: %s (%d files)\n", $pharPath, count($filesToPack)));

if (class_exists('ZipArchive')) {
    $zipPath = sprintf('%s/ai-response-generator-v%s.zip', $distDir, $version);
    if (is_file($zipPath)) {
        unlink($zipPath);
    }
    $zip = new ZipArchive();
    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "Failed to open zip archive: $zipPath\n");
        exit(1);
    }
    foreach ($filesToPack as $rel => $abs) {
        $zip->addFile($abs, 'ai-response-generator/' . $rel);
    }
    $zip->close();
    fwrite(STDOUT, sprintf("Built ZIP:  %s (%d files)\n", $zipPath, count($filesToPack)));
}
