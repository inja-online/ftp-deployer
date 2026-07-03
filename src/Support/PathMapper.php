<?php

namespace InjaOnline\FTPDeployer\Support;

class PathMapper
{
    private string $releaseId;

    /** @param array<string, mixed> $paths */
    public function __construct(private readonly array $paths)
    {
        $this->releaseId = date('YmdHis');
    }

    public function releaseId(): string
    {
        return $this->releaseId;
    }

    public function remotePath(string $local): string
    {
        $local = trim(str_replace('\\', '/', $local), '/');
        if (($this->paths['mode'] ?? 'simple') === 'versioned') {
            if (str_starts_with($local, 'public/')) {
                return $this->join($this->remoteConfiguredPath((string) $this->paths['public_root']), substr($local, 7));
            }

            return $this->join($this->remoteConfiguredPath((string) $this->paths['release_root']), $this->releaseId, $local);
        }

        return str_starts_with($local, 'public/')
            ? $this->join($this->remoteConfiguredPath((string) $this->paths['public_root']), substr($local, 7))
            : $this->join($this->remoteConfiguredPath((string) $this->paths['app_root']), $local);
    }

    public function publicRemotePath(string $file): string
    {
        return $this->join($this->remoteConfiguredPath((string) $this->paths['public_root']), $file);
    }

    public function configuredRemotePath(string $path): string
    {
        return $this->join($this->remoteConfiguredPath($path));
    }

    public function releaseRoot(): string
    {
        return $this->filesystemPath($this->joinWithoutFtpRoot($this->remoteConfiguredPath((string) $this->paths['release_root']), $this->releaseId));
    }

    public function filesystemPath(string $path = ''): string
    {
        $root = rtrim((string) ($this->paths['filesystem_root'] ?? ''), '/');
        $path = trim($path, '/');

        return $path === '' ? ($root === '' ? '/' : $root) : ($root === '' ? $path : $root.'/'.$path);
    }

    public function appFilesystemPath(string $path = ''): string
    {
        $root = (($this->paths['mode'] ?? 'simple') === 'versioned')
            ? $this->joinWithoutFtpRoot($this->remoteConfiguredPath((string) $this->paths['release_root']), $this->releaseId)
            : $this->remoteConfiguredPath((string) $this->paths['app_root']);

        return $this->filesystemPath($this->joinWithoutFtpRoot($root, $path));
    }

    public function sharedFilesystemPath(string $path = ''): string
    {
        return $this->filesystemPath($this->joinWithoutFtpRoot($this->remoteConfiguredPath((string) $this->paths['shared_root']), $path));
    }

    public function archiveRemotePath(string $filename): string
    {
        return $this->join('.ftp-deployer/archives', $filename);
    }

    public function archiveFilesystemPath(string $filename): string
    {
        return $this->filesystemPath($this->joinWithoutFtpRoot('.ftp-deployer/archives', $filename));
    }

    private function join(string ...$parts): string
    {
        return $this->joinWithoutFtpRoot($this->paths['ftp_root'] ?? '', ...$parts);
    }

    private function remoteConfiguredPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '.') {
            return '';
        }
        $root = trim(str_replace('\\', '/', (string) ($this->paths['filesystem_root'] ?? '')), '/');
        if ($root !== '' && str_starts_with($path, $root.'/')) {
            return trim(substr($path, strlen($root) + 1), '/');
        }

        return $path;
    }

    private function joinWithoutFtpRoot(string ...$parts): string
    {
        $path = implode('/', array_filter(array_map(fn ($part) => trim($part, '/'), $parts), fn ($part) => $part !== ''));

        return $path === '' ? '/' : $path;
    }
}
