<?php

namespace InjaOnline\FTPDeployer\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

class ReleaseBuilder
{
    private ?string $release = null;

    /** @param list<string> $exclusions */
    public function __construct(private readonly string $source, private readonly array $exclusions, private readonly mixed $progress = null)
    {
    }

    public function build(): string
    {
        $this->release = sys_get_temp_dir().'/ftp-deployer-'.bin2hex(random_bytes(8));
        mkdir($this->release, 0755, true);

        $this->copy($this->source, $this->release);
        $this->ensureRuntimeDirectories();
        $this->prepareComposerVendor();
        $this->removeRuntimeJunk();
        $this->ensureRuntimeDirectories();

        return $this->release;
    }

    public function cleanup(): void
    {
        if ($this->release && is_dir($this->release)) {
            $this->remove($this->release);
        }
    }

    private function copy(string $from, string $to, array $visited = []): void
    {
        $realFrom = realpath($from);
        if (!$realFrom || in_array($realFrom, $visited, true)) {
            return;
        }
        $visited[] = $realFrom;

        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($items as $item) {
            $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($from) + 1));
            if (Exclusions::matches($relative, $this->exclusions)) {
                continue;
            }

            $target = $to.'/'.$relative;
            if (is_link($item->getPathname())) {
                $realPath = realpath($item->getPathname());
                if ($realPath && is_dir($realPath)) {
                    is_dir($target) || mkdir($target, 0755, true);
                    $this->copy($realPath, $target, $visited);

                    continue;
                }
            }

            if ($item->isDir()) {
                is_dir($target) || mkdir($target, 0755, true);
            } else {
                is_dir(dirname($target)) || mkdir(dirname($target), 0755, true);
                copy($item->getPathname(), $target);
            }
        }
    }

    private function prepareComposerVendor(): void
    {
        if (!file_exists($this->release.'/composer.json')) {
            return;
        }

        $cache = $this->composerVendorCachePath();
        if (is_dir($this->release.'/vendor')) {
            $this->remove($this->release.'/vendor');
        }
        if (is_file($cache.'/vendor/autoload.php')) {
            $this->progress("Composer vendor cache hit: {$cache}");
            $this->copy($cache.'/vendor', $this->release.'/vendor');

            return;
        }

        $this->progress("Composer vendor cache miss: {$cache}");
        $this->progress('Running composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts');
        $process = Process::fromShellCommandline('composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts', $this->release);
        $process->setTimeout(null);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new RuntimeException('Composer production install failed: '.$process->getErrorOutput().$process->getOutput());
        }

        if (is_dir($this->release.'/vendor')) {
            is_dir($cache) || mkdir($cache, 0755, true);
            $this->copy($this->release.'/vendor', $cache.'/vendor');
        }
    }

    private function composerVendorCachePath(): string
    {
        $json = md5_file($this->release.'/composer.json') ?: 'missing';
        $lock = is_file($this->release.'/composer.lock') ? (md5_file($this->release.'/composer.lock') ?: 'missing') : 'missing';

        return sys_get_temp_dir()."/ftp-deployer-vendor-{$json}-{$lock}";
    }

    private function progress(string $line): void
    {
        is_callable($this->progress) && ($this->progress)($line);
    }

    private function removeRuntimeJunk(): void
    {
        foreach (['storage/framework/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/testing', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $path) {
            $full = $this->release.'/'.$path;
            if (is_dir($full)) {
                $this->remove($full, true);
            }
        }
    }

    private function ensureRuntimeDirectories(): void
    {
        foreach (['storage/framework/cache', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/framework/views', 'storage/logs', 'bootstrap/cache'] as $path) {
            is_dir($this->release.'/'.$path) || mkdir($this->release.'/'.$path, 0755, true);
        }
    }

    private function remove(string $path, bool $keepRoot = false): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }
        if (!file_exists($path)) {
            return;
        }
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        $keepRoot ? null : rmdir($path);
    }
}
