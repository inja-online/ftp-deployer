<?php

namespace InjaOnline\FTPDeployer\Support;

use FTP\Connection;
use RuntimeException;

class FTPClient
{
    /** @var (callable(array<string, mixed>): self)|null */
    public static $connectUsing = null;

    private function __construct(private Connection $connection)
    {
    }

    /** @param array<string, mixed> $config */
    public static function connect(array $config): self
    {
        if (self::$connectUsing !== null) {
            return (self::$connectUsing)($config);
        }

        $host = (string) $config['host'];
        $port = (int) ($config['port'] ?? 21);
        $timeout = (int) ($config['timeout'] ?? 90);
        $connection = !empty($config['ssl']) ? @ftp_ssl_connect($host, $port, $timeout) : @ftp_connect($host, $port, $timeout);

        if (!$connection || !@ftp_login($connection, (string) $config['username'], (string) $config['password'])) {
            throw new RuntimeException('FTP login failed.');
        }

        @ftp_pasv($connection, (bool) ($config['passive'] ?? true));

        return new self($connection);
    }

    public function put(string $local, string $remote): void
    {
        if (!is_file($local)) {
            throw new RuntimeException("Local file missing: {$local}");
        }
        $this->mkdir(dirname($remote));
        $tmp = $remote.'.tmp-'.bin2hex(random_bytes(4));
        if (!@ftp_put($this->connection, $tmp, $local, FTP_BINARY)) {
            throw new RuntimeException("FTP upload failed: {$remote}");
        }
        @ftp_delete($this->connection, $remote);
        if (!@ftp_rename($this->connection, $tmp, $remote)) {
            @ftp_delete($this->connection, $tmp);

            throw new RuntimeException("FTP rename failed: {$remote}");
        }
    }

    public function putDirectory(string $localDir, string $remoteDir): void
    {
        if (!is_dir($localDir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($localDir, \RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($items as $item) {
            if ($item->isFile()) {
                $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($localDir) + 1));
                $this->put($item->getPathname(), trim($remoteDir, '/').'/'.$relative);
            }
        }
    }

    public function putContent(string $content, string $remote): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ftp-deployer-');
        file_put_contents($tmp, $content);

        try {
            $this->put($tmp, $remote);
        } finally {
            @unlink($tmp);
        }
    }

    public function getContent(string $remote): ?string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ftp-deployer-');

        try {
            if (!@ftp_get($this->connection, $tmp, $remote, FTP_BINARY)) {
                return null;
            }

            return file_get_contents($tmp) ?: null;
        } finally {
            @unlink($tmp);
        }
    }

    public function exists(string $remote): bool
    {
        return @ftp_size($this->connection, $remote) >= 0;
    }

    public function delete(string $remote): void
    {
        @ftp_delete($this->connection, $remote);
    }

    public function mkdir(string $path): void
    {
        $path = trim($path, '/');
        if ($path === '' || $path === '.') {
            return;
        }
        $current = '';
        foreach (explode('/', $path) as $part) {
            $current .= ($current === '' ? '' : '/').$part;
            @ftp_mkdir($this->connection, $current);
        }
    }
}
