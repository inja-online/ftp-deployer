<?php

namespace InjaOnline\FTPDeployer;

use InjaOnline\FTPDeployer\Support\ArchiveBuilder;
use InjaOnline\FTPDeployer\Support\FrontendDetector;
use InjaOnline\FTPDeployer\Support\FTPClient;
use InjaOnline\FTPDeployer\Support\Manifest;
use InjaOnline\FTPDeployer\Support\PathMapper;
use InjaOnline\FTPDeployer\Support\ReleaseBuilder;
use InjaOnline\FTPDeployer\Support\Runner;
use RuntimeException;

class FTPDeployer
{
    /** @param array<string, mixed> $profile */
    public function __construct(private readonly array $profile)
    {
    }

    /** @return array{uploaded:int,deleted:int,skipped:int,runner:array<string,mixed>} */
    public function deploy(callable $progress): array
    {
        $builder = new ReleaseBuilder(getcwd() ?: base_path(), $this->profile['exclusions'] ?? [], $progress);
        $progress('Building temporary release');
        $release = $builder->build();
        $progress("Temporary release ready: {$release}");
        $ftp = null;
        $runner = null;
        $archives = [];
        $rootHtaccessPath = null;
        $rootHtaccessOriginal = null;

        try {
            $mapper = new PathMapper($this->profile['paths']);
            $frontend = (new FrontendDetector($release, $this->profile['frontend'] ?? []))->detect();
            foreach ($frontend['warnings'] as $warning) {
                $progress("Warning: {$warning}");
            }
            $local = Manifest::build($release, $mapper, $frontend);
            $composerJson = $local['inputs']['composer']['composer_json'] ?? null;
            $composerLock = $local['inputs']['composer']['composer_lock'] ?? null;
            $progress('Composer inputs: composer.json='.($composerJson ?: 'missing').', composer.lock='.($composerLock ?: 'missing'));

            $progress('Connecting FTP: '.$this->profile['ftp']['host']);
            $ftp = FTPClient::connect($this->profile['ftp']);
            $manifestPath = $mapper->configuredRemotePath(Manifest::REMOTE_PATH);
            $remote = Manifest::loadRemote($ftp, $manifestPath);
            if (($this->profile['frontend']['warn_if_inputs_changed_without_output_change'] ?? true) && Manifest::frontendInputsChangedWithoutBuildChange($local, $remote)) {
                $progress('Warning: frontend inputs changed but build output manifests did not — did you run your build step?');
            }
            $diff = Manifest::diff($local, $remote);

            $versioned = ($this->profile['paths']['mode'] ?? 'simple') === 'versioned';
            $archiveMode = (bool) ($this->profile['archive']['enabled'] ?? true);
            $vendorAutoload = $versioned ? $mapper->appFilesystemPath('vendor/autoload.php') : null;
            $vendorChanged = Manifest::vendorChanged($local, $remote);
            if (!$archiveMode && ($versioned || $vendorChanged)) {
                $diff['upload_dirs'][] = 'vendor';
            }
            if ($archiveMode) {
                $this->validateArchiveConfig($mapper);
                $progress('Archive deploy: '.count($diff['uploads']).' changed file(s), '.count($diff['deletes']).' delete(s), '.count($diff['skips']).' unchanged file(s).');
            }

            if ($versioned) {
                $bootloader = Runner::bootloader($this->profile['paths'] + ['vendor_autoload' => $vendorAutoload], $mapper->releaseId());
                $bootloaderPath = $mapper->publicRemotePath('index.php');
                $progress('Uploading managed public bootloader for release '.$mapper->releaseId());
                $ftp->putContent($bootloader, $bootloaderPath);
            }

            $deny = "Options -Indexes\nRequire all denied\nDeny from all\n";
            $ftp->putContent($deny, $mapper->configuredRemotePath('.ftp-deployer/.htaccess'));

            if ($archiveMode) {
                $archives = $this->buildArchives($release, $mapper, $local, $versioned, fn (string $remote) => $ftp->exists($remote), $vendorChanged, $progress);
                $ftp->putContent($deny, $mapper->configuredRemotePath('.ftp-deployer/archives/.htaccess'));
                foreach ($archives as $archive) {
                    if ($archive['upload']) {
                        $progress("Uploading {$archive['name']} (".self::bytes(filesize($archive['local']) ?: 0).") -> {$archive['remote']}");
                        $ftp->put($archive['local'], $archive['remote']);
                    } else {
                        $progress("Reusing {$archive['name']} already on remote: {$archive['remote']}");
                    }
                }
            } else {
                foreach ($diff['uploads'] as $path => $meta) {
                    $progress("Uploading {$path}");
                    $ftp->put($release.'/'.$path, $meta['remote_path']);
                }

                foreach ($diff['upload_dirs'] as $dir) {
                    $progress("Uploading {$dir}/");
                    $ftp->putDirectory($release.'/'.$dir, $mapper->remotePath($dir));
                }

                foreach ($diff['deletes'] as $path) {
                    $progress("Deleting {$path}");
                    $ftp->delete($path);
                }
            }

            $runnerPaths = $this->profile['paths'];
            if ($versioned) {
                $runnerPaths['release_root'] = $mapper->releaseRoot();
                $runnerPaths['vendor_autoload'] = $vendorAutoload;
            }
            $runner = Runner::generate($runnerPaths);
            $progress("Temporary installer ready: {$runner['filename']}");
            $runnerRemote = $mapper->publicRemotePath($runner['filename']);
            $progress("Uploading {$runner['filename']} -> {$runnerRemote}");
            $ftp->putContent($runner['content'], $runnerRemote);
            $rootHtaccessPath = $mapper->configuredRemotePath('.htaccess');
            $rootHtaccessOriginal = $this->temporarilyAllowRunner($ftp, $rootHtaccessPath, $runner['filename']);

            if ($archiveMode) {
                $progress("Calling {$runner['filename']} to extract ".count($archives).' archive(s)');
                $response = Runner::callPayload($this->profile['paths']['app_url'], $runner['filename'], [
                    'token' => $runner['token'],
                    'extract' => array_map(fn ($archive) => array_filter(['archive' => $archive['filesystem'], 'destination' => $archive['destination'], 'skip_if_exists' => $archive['skip_if_exists'] ?? null], fn ($value) => $value !== null), $archives),
                ]);
                foreach ($diff['deletes'] as $path) {
                    $progress("Deleting {$path}");
                    $ftp->delete($path);
                }
                Manifest::saveRemote($ftp, $local, $manifestPath);
                $steps = $this->remoteCommandSteps();
                $progress("Calling {$runner['filename']} to run ".count($steps).' remote command(s)'.self::commandList($steps));
                $response = Runner::call($this->profile['paths']['app_url'], $runner['filename'], $runner['token'], $steps);
                if ($versioned) {
                    $ftp->putContent($mapper->releaseId(), $mapper->configuredRemotePath($this->profile['paths']['current_path']));
                }
            } else {
                $steps = $this->remoteCommandSteps();
                $progress("Calling {$runner['filename']} to run ".count($steps).' remote command(s)'.self::commandList($steps));
                $response = Runner::call($this->profile['paths']['app_url'], $runner['filename'], $runner['token'], $steps);

                if ($versioned) {
                    $ftp->putContent($mapper->releaseId(), $mapper->configuredRemotePath($this->profile['paths']['current_path']));
                }

                Manifest::saveRemote($ftp, $local, $manifestPath);
            }

            return [
                'uploaded' => $archiveMode ? count(array_filter($archives, fn ($archive) => $archive['upload'])) : count($diff['uploads']) + count($diff['upload_dirs']),
                'deleted' => count($diff['deletes']),
                'skipped' => count($diff['skips']),
                'runner' => $response,
            ];
        } finally {
            if ($rootHtaccessPath !== null && $rootHtaccessOriginal !== null && $ftp) {
                try {
                    $ftp->putContent($rootHtaccessOriginal, $rootHtaccessPath);
                } catch (\Throwable) {
                    // cleanup best effort
                }
            }
            if ($archives && $ftp) {
                foreach ($archives as $archive) {
                    try {
                        if ($this->shouldDeleteArchiveRemote($archive)) {
                            $ftp->delete($archive['remote']);
                        }
                    } catch (\Throwable) {
                        // cleanup best effort
                    }
                    @unlink($archive['local']);
                }
            }
            if ($runner && $ftp) {
                try {
                    $ftp->delete((new PathMapper($this->profile['paths']))->publicRemotePath($runner['filename']));
                } catch (\Throwable) {
                    // cleanup best effort
                }
            }
            $builder->cleanup();
        }
    }

    private function temporarilyAllowRunner(FTPClient $ftp, string $htaccessPath, string $filename): ?string
    {
        $original = $ftp->getContent($htaccessPath);
        if ($original === null) {
            return null;
        }

        $patched = $this->allowRunnerInHtaccess($original, $filename);
        if ($patched === $original) {
            return null;
        }

        $ftp->putContent($patched, $htaccessPath);

        return $original;
    }

    private function allowRunnerInHtaccess(string $content, string $filename): string
    {
        $condition = '    RewriteCond %{REQUEST_URI} !^/'.preg_quote($filename, '/').'$';
        if (str_contains($content, $condition)) {
            return $content;
        }

        return preg_replace('/^(\s*RewriteRule\s+\^\(\.\*\)\$\s+app\/public\/\$1\s+\[L\]\s*)$/m', $condition."\n".'$1', $content, 1) ?? $content;
    }

    /** @param array{name:string,local:string,remote:string,filesystem:string,destination:string,upload:bool,skip_if_exists?:string} $archive */
    private function shouldDeleteArchiveRemote(array $archive): bool
    {
        return $archive['name'] !== 'vendor.zip';
    }

    private function validateArchiveConfig(PathMapper $mapper): void
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new RuntimeException('Missing PHP zip support: ZipArchive extension is required for archive deployment.');
        }
        if (trim((string) ($this->profile['paths']['filesystem_root'] ?? '')) === '') {
            throw new RuntimeException('Archive deployment requires FTP_DEPLOYER_FILESYSTEM_ROOT / paths.filesystem_root.');
        }
        if ($mapper->filesystemPath() === '/') {
            throw new RuntimeException('Archive deployment requires a concrete filesystem root, not /.');
        }
    }

    /** @return list<array{name:string,local:string,remote:string,filesystem:string,destination:string,upload:bool,skip_if_exists?:string}> */
    private function buildArchives(string $release, PathMapper $mapper, array $local, bool $versioned, callable $remoteArchiveExists, bool $vendorChanged, callable $progress): array
    {
        $archives = [];
        $appName = 'app-'.bin2hex(random_bytes(16)).'.zip';
        $appLocal = sys_get_temp_dir().'/'.$appName;
        $progress("Creating app archive temp: {$appLocal}");
        ArchiveBuilder::build($release, $appLocal, [
            'vendor/',
            '.ftp-deployer/',
            'node_modules/',
            '.git/',
            '.github/',
            '.editorconfig',
            '.gitattributes',
            '.gitignore',
            '.styleci.yml',
            '.php-cs-fixer.dist.php',
            '.php-cs-fixer.cache',
            '.phpunit.result.cache',
            'phpunit.xml',
            'phpunit.xml.dist',
            'phpstan.neon',
            'phpstan.neon.dist',
            'pint.json',
            'pint.json.dist',
            'package.json',
            'package-lock.json',
            'yarn.lock',
            'pnpm-lock.yaml',
            'vite.config.*',
            'storage/framework/cache/data/*',
            'storage/framework/sessions/*',
            'storage/framework/testing/*',
            'storage/framework/views/*',
            'storage/logs/*',
            'bootstrap/cache/*.php',
        ]);
        $archives[] = ['name' => 'app.zip', 'local' => $appLocal, 'remote' => $mapper->archiveRemotePath($appName), 'filesystem' => $mapper->archiveFilesystemPath($appName), 'destination' => $mapper->appFilesystemPath(), 'upload' => true];

        $vendorName = Manifest::vendorArchiveName($local);
        $vendorLocal = sys_get_temp_dir().'/'.$vendorName;
        $vendorRemote = $mapper->archiveRemotePath($vendorName);
        $progress("Vendor archive name from composer hashes: {$vendorName}");
        $vendorUpload = $versioned ? !$remoteArchiveExists($vendorRemote) : $vendorChanged;
        if (($versioned || $vendorUpload) && is_dir($release.'/vendor')) {
            if ($vendorUpload) {
                $progress("Creating vendor archive temp: {$vendorLocal}");
                ArchiveBuilder::build($release.'/vendor', $vendorLocal);
            }
            $vendorDestination = $mapper->appFilesystemPath('vendor');
            $archive = ['name' => 'vendor.zip', 'local' => $vendorLocal, 'remote' => $vendorRemote, 'filesystem' => $mapper->archiveFilesystemPath($vendorName), 'destination' => $vendorDestination, 'upload' => $vendorUpload];
            if ($versioned) {
                $archive['skip_if_exists'] = $vendorDestination.'/autoload.php';
            }
            $archives[] = $archive;
        }

        return $archives;
    }

    /** @param list<array{cmd:string,fail_on_error:bool}> $steps */
    private static function commandList(array $steps): string
    {
        return $steps === [] ? '' : ': '.implode(', ', array_column($steps, 'cmd'));
    }

    private static function bytes(int|float $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, $unit === 'B' ? 0 : 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return '0 B';
    }

    /** @return list<array{cmd:string,fail_on_error:bool}> */
    public function remoteCommandSteps(): array
    {
        return array_map(function ($command): array {
            if (is_string($command)) {
                return ['cmd' => $command, 'fail_on_error' => true];
            }

            if (is_array($command) && isset($command['command'])) {
                return ['cmd' => (string) $command['command'], 'fail_on_error' => !(bool) ($command['ignore_failures'] ?? false)];
            }

            throw new RuntimeException('Invalid remote command configuration.');
        }, $this->profile['remote_commands'] ?? []);
    }
}
