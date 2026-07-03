<?php

namespace InjaOnline\FTPDeployer\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

class ArchiveBuilder
{
    /** @param list<string> $exclusions */
    public static function build(string $root, string $zipPath, array $exclusions = []): void
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Missing PHP zip support: ZipArchive extension is required for archive deployment.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Unable to create ZIP archive: {$zipPath}");
        }

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            if (self::excluded($relative, $exclusions)) {
                continue;
            }
            self::assertSafePath($relative);
            $item->isDir() ? $zip->addEmptyDir($relative) : $zip->addFile($item->getPathname(), $relative);
        }

        $zip->close();
    }

    public static function assertSafePath(string $path): void
    {
        $path = str_replace('\\', '/', $path);
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, '//') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException("Unsafe ZIP entry path: {$path}");
        }
        foreach (explode('/', $path) as $part) {
            if ($part === '..') {
                throw new RuntimeException("Unsafe ZIP entry path: {$path}");
            }
        }
    }

    /** @param list<string> $exclusions */
    private static function excluded(string $path, array $exclusions): bool
    {
        if (Exclusions::matches($path, $exclusions)) {
            return true;
        }

        return str_starts_with($path, 'database/') && (bool) preg_match('/\.sqlite(\d+)?$/i', basename($path));
    }
}
