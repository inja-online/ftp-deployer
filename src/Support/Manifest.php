<?php

namespace InjaOnline\FTPDeployer\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Manifest
{
    public const REMOTE_PATH = '.ftp-deployer/manifest.json';

    /** @param array{builds:array<string,array<string,mixed>>,inputs:array<string,mixed>,warnings:list<string>} $frontend */
    public static function build(string $root, PathMapper $mapper, array $frontend): array
    {
        $files = [];
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if (!$item->isFile()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($root) + 1));
            if (str_starts_with($relative, 'vendor/') || self::isFrontendOutput($relative, $frontend['builds'])) {
                continue;
            }
            $files[$relative] = [
                'remote_path' => $mapper->remotePath($relative),
                'hash' => md5_file($item->getPathname()),
                'size' => $item->getSize(),
                'mtime' => $item->getMTime(),
            ];
        }
        ksort($files);

        return [
            '$schema' => 'https://example.com/ftp-deployer/manifest.v1.schema.json',
            'schema_version' => 1,
            'deployed_at' => gmdate('c'),
            'deployed_from' => self::deployedFrom(),
            'paths' => [],
            'inputs' => [
                'composer' => [
                    'composer_json' => is_file($root.'/composer.json') ? md5_file($root.'/composer.json') : null,
                    'composer_lock' => is_file($root.'/composer.lock') ? md5_file($root.'/composer.lock') : null,
                ],
                'frontend' => $frontend['inputs'],
            ],
            'groups' => [
                'app' => ['strategy' => 'file-diff', 'count' => count($files), 'hash' => md5(json_encode($files) ?: '')],
                'vendor' => ['strategy' => 'composer-inputs', 'paths' => ['vendor/']],
                'frontend' => ['strategy' => 'build-manifest', 'builds' => self::withFrontendAssets($root, $mapper, $frontend['builds'])],
            ],
            'files' => $files,
        ];
    }

    public static function loadRemote(FTPClient $ftp, string $remotePath = self::REMOTE_PATH): ?array
    {
        $json = $ftp->getContent($remotePath);
        if ($json === null) {
            return null;
        }
        $manifest = json_decode($json, true);

        return is_array($manifest) && self::valid($manifest) ? $manifest : null;
    }

    public static function saveRemote(FTPClient $ftp, array $manifest, string $remotePath = self::REMOTE_PATH): void
    {
        $ftp->putContent(json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}', $remotePath);
    }

    public static function frontendInputsChangedWithoutBuildChange(array $local, ?array $remote): bool
    {
        if (!$remote) {
            return false;
        }

        $localBuilds = $local['groups']['frontend']['builds'] ?? [];
        $remoteBuilds = $remote['groups']['frontend']['builds'] ?? [];
        if ($localBuilds === [] || $remoteBuilds === []) {
            return false;
        }

        foreach ($localBuilds as $name => $build) {
            if (($remoteBuilds[$name]['manifest_hash'] ?? null) !== ($build['manifest_hash'] ?? null)) {
                return false;
            }
        }

        return ($local['inputs']['frontend'] ?? []) !== ($remote['inputs']['frontend'] ?? []);
    }

    /** @return array{uploads:array<string,array<string,mixed>>,deletes:list<string>,skips:list<string>,upload_dirs:list<string>} */
    public static function diff(array $local, ?array $remote): array
    {
        $uploads = $deletes = $skips = [];
        $remoteFiles = $remote['files'] ?? [];

        foreach ($local['files'] as $path => $meta) {
            if (($remoteFiles[$path]['hash'] ?? null) === $meta['hash'] && ($remoteFiles[$path]['remote_path'] ?? null) === $meta['remote_path']) {
                $skips[] = $path;
            } else {
                $uploads[$path] = $meta;
            }
        }

        foreach ($remoteFiles as $path => $meta) {
            if (!isset($local['files'][$path])) {
                $deletes[] = $meta['remote_path'];
            }
        }

        $uploadDirs = [];
        foreach (($local['groups']['frontend']['builds'] ?? []) as $name => $build) {
            if (($remote['groups']['frontend']['builds'][$name]['manifest_hash'] ?? null) !== $build['manifest_hash']) {
                foreach ($build['output_paths'] as $path) {
                    $uploadDirs[] = trim($path, '/');
                }
                foreach (($remote['groups']['frontend']['builds'][$name]['assets'] ?? []) as $asset => $remotePath) {
                    if (!isset($build['assets'][$asset]) && self::isFrontendOutput($asset, [$build])) {
                        $deletes[] = $remotePath;
                    }
                }
            }
        }

        return ['uploads' => $uploads, 'deletes' => array_values(array_unique($deletes)), 'skips' => $skips, 'upload_dirs' => $uploadDirs];
    }

    public static function vendorChanged(array $local, ?array $remote): bool
    {
        return !$remote || ($local['inputs']['composer'] ?? []) !== ($remote['inputs']['composer'] ?? []);
    }

    public static function vendorArchiveName(array $local): string
    {
        $composer = $local['inputs']['composer'] ?? [];
        $json = (string) ($composer['composer_json'] ?? 'none');
        $lock = (string) ($composer['composer_lock'] ?? 'none');

        return 'vendor-'.$json.'-'.$lock.'.zip';
    }

    public static function valid(array $manifest): bool
    {
        return ($manifest['schema_version'] ?? null) === 1
            && isset($manifest['files'], $manifest['groups']['frontend']['builds'])
            && is_array($manifest['files'])
            && is_array($manifest['groups']['frontend']['builds']);
    }

    private static function isFrontendOutput(string $path, array $builds): bool
    {
        foreach ($builds as $build) {
            foreach ($build['output_paths'] ?? [] as $output) {
                if (str_starts_with($path, trim($output, '/').'/')) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function withFrontendAssets(string $root, PathMapper $mapper, array $builds): array
    {
        foreach ($builds as &$build) {
            $assets = [];
            foreach ($build['output_paths'] ?? [] as $output) {
                $dir = $root.'/'.trim($output, '/');
                if (!is_dir($dir)) {
                    continue;
                }
                $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS));
                foreach ($items as $item) {
                    if ($item->isFile()) {
                        $relative = trim($output, '/').'/'.str_replace('\\', '/', substr($item->getPathname(), strlen($dir) + 1));
                        $assets[$relative] = $mapper->remotePath($relative);
                    }
                }
            }
            ksort($assets);
            $build['assets'] = $assets;
        }
        unset($build);

        return $builds;
    }

    private static function deployedFrom(): array
    {
        $sha = trim((string) @shell_exec('git rev-parse HEAD 2>/dev/null')) ?: null;
        $ref = trim((string) @shell_exec('git rev-parse --abbrev-ref HEAD 2>/dev/null')) ?: null;

        return ['type' => $sha ? 'git' : 'local', 'ref' => $ref, 'sha' => $sha];
    }
}
