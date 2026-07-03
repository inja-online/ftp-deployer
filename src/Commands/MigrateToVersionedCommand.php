<?php

namespace InjaOnline\FTPDeployer\Commands;

use Illuminate\Console\Command;
use RuntimeException;

class MigrateToVersionedCommand extends Command
{
    protected $signature = 'ftp-deploy:migrate {profile=production} {--mode=versioned} {--write} {--format=}';

    protected $description = 'Migrate a deployment profile from simple mode to versioned mode.';

    public function handle(): int
    {
        $profileName = (string) $this->argument('profile');
        $targetMode = (string) $this->option('mode');
        $write = (bool) $this->option('write');
        $agent = $this->option('format') === 'agent';
        $logs = [];

        $profile = config("ftp-deployer.profiles.{$profileName}");

        if (!is_array($profile)) {
            $message = "Deploy profile [{$profileName}] is missing.";

            if ($agent) {
                $this->agentOutput([
                    'status' => 'error',
                    'profile' => $profileName,
                    'message' => $message,
                    'logs' => [$this->log('error', 'validation', $message)],
                ]);
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        if ($targetMode !== 'versioned') {
            $message = 'Target mode must be versioned.';

            if ($agent) {
                $this->agentOutput([
                    'status' => 'error',
                    'profile' => $profileName,
                    'message' => $message,
                    'logs' => [$this->log('error', 'validation', $message)],
                ]);
            } else {
                $this->error($message);
            }

            return self::FAILURE;
        }

        $paths = $profile['paths'] ?? [];
        $currentMode = $paths['mode'] ?? 'simple';

        $interactive = !$agent && $this->input->isInteractive();
        $filesystemRoot = rtrim((string) ($paths['filesystem_root'] ?? ''), '/');
        $defaultRelease = $filesystemRoot !== '' ? $filesystemRoot . '/app/releases' : '../app/releases';
        $defaultShared = $filesystemRoot !== '' ? $filesystemRoot . '/app/shared' : '../app/shared';
        $defaultCurrent = $filesystemRoot !== '' ? $filesystemRoot . '/app/current' : '../app/current';

        $releaseRoot = $paths['release_root'] ?? null;
        $sharedRoot = $paths['shared_root'] ?? null;
        $currentPath = $paths['current_path'] ?? null;

        if ($currentMode === 'simple' || empty($releaseRoot) || in_array($releaseRoot, ['app/releases', '../app/releases'], true)) {
            $releaseRoot = $defaultRelease;
        }
        if ($currentMode === 'simple' || empty($sharedRoot) || in_array($sharedRoot, ['app/shared', '../app/shared'], true)) {
            $sharedRoot = $defaultShared;
        }
        if ($currentMode === 'simple' || empty($currentPath) || in_array($currentPath, ['app/current', '../app/current'], true)) {
            $currentPath = $defaultCurrent;
        }

        if ($interactive) {
            $releaseRoot = $this->ask('Enter release root', $releaseRoot);
            $sharedRoot = $this->ask('Enter shared root', $sharedRoot);
            $currentPath = $this->ask('Enter current path', $currentPath);
        }

        $proposedPaths = array_merge($paths, [
            'mode' => $targetMode,
            'release_root' => $releaseRoot,
            'shared_root' => $sharedRoot,
            'current_path' => $currentPath,
        ]);

        try {
            $this->validatePaths($proposedPaths);
        } catch (\Throwable $e) {
            if ($agent) {
                $logs[] = $this->log('error', 'validation', $e->getMessage());
                $this->agentOutput([
                    'status' => 'error',
                    'profile' => $profileName,
                    'message' => $e->getMessage(),
                    'logs' => $logs,
                ]);
            } else {
                $this->error($e->getMessage());
            }

            return self::FAILURE;
        }

        $written = false;
        $envPath = base_path('.env');
        $envExists = file_exists($envPath);

        $envBlock = [
            'FTP_DEPLOYER_MODE' => $targetMode,
            'FTP_DEPLOYER_RELEASE_ROOT' => $releaseRoot,
            'FTP_DEPLOYER_SHARED_ROOT' => $sharedRoot,
            'FTP_DEPLOYER_CURRENT_PATH' => $currentPath,
        ];

        if ($write) {
            $confirmed = true;
            if ($interactive) {
                $confirmed = $this->confirm('Are you sure you want to write these settings to your local .env file?', true);
            }

            if ($confirmed) {
                if ($envExists && is_writable($envPath)) {
                    $content = file_get_contents($envPath);
                    if ($content !== false) {
                        foreach ($envBlock as $key => $val) {
                            $pattern = "/^" . preg_quote($key, '/') . "=.*/m";
                            if (preg_match($pattern, $content)) {
                                $content = preg_replace($pattern, "{$key}={$val}", $content);
                            } else {
                                $content = rtrim($content) . "\n{$key}={$val}\n";
                            }
                        }
                        if (file_put_contents($envPath, $content) !== false) {
                            $written = true;
                        }
                    }
                }
            }
        }

        if ($agent) {
            $logs[] = $this->log('info', 'migration', 'Migration plan generated successfully.');
            if ($written) {
                $logs[] = $this->log('info', 'migration', 'Local configuration successfully updated.');
            }
            $this->agentOutput([
                'status' => 'success',
                'profile' => $profileName,
                'logs' => $logs,
                'migration' => [
                    'profile' => $profileName,
                    'old_mode' => $currentMode,
                    'new_mode' => $targetMode,
                    'paths' => [
                        'release_root' => $releaseRoot,
                        'shared_root' => $sharedRoot,
                        'current_path' => $currentPath,
                    ],
                    'written' => $written,
                ],
            ]);
        } else {
            $this->info("Migration Plan for profile [{$profileName}]:");
            $this->line('----------------------------------------');
            $this->line("Target Mode: {$targetMode}");
            $this->line('Proposed Path Settings:');
            $this->line('  - ftp_root:     ' . ($paths['ftp_root'] ?? '/'));
            $this->line('  - app_root:     ' . ($paths['app_root'] ?? ''));
            $this->line('  - public_root:  ' . ($paths['public_root'] ?? ''));
            $this->line("  - release_root: {$releaseRoot}");
            $this->line("  - shared_root:  {$sharedRoot}");
            $this->line("  - current_path: {$currentPath}");
            $this->line('');

            if ($write) {
                if ($written) {
                    $this->info('Local configuration successfully updated in your .env file!');
                } else {
                    $this->warn('Local write requested but could not be completed (e.g. .env is missing or not writable).');
                    $this->line('Please add the following variables manually to your local .env file:');
                    foreach ($envBlock as $key => $val) {
                        $this->line("{$key}={$val}");
                    }
                }
            } else {
                $this->line('Generated environment settings (add these to your local .env file):');
                foreach ($envBlock as $key => $val) {
                    $this->line("{$key}={$val}");
                }
            }

            $this->line('');
            $this->warn('WARNING: Remote .env and storage directory must be manually moved on your FTP server.');
            $this->line('');
            $oldEnvPath = $this->normalizeFtpPath(rtrim($paths['ftp_root'] ?? '', '/') . '/' . rtrim($paths['app_root'] ?? '', '/') . '/.env');
            $newEnvPath = $this->normalizeFtpPath(rtrim($paths['ftp_root'] ?? '', '/') . '/' . rtrim($sharedRoot, '/') . '/.env');
            $oldStoragePath = $this->normalizeFtpPath(rtrim($paths['ftp_root'] ?? '', '/') . '/' . rtrim($paths['app_root'] ?? '', '/') . '/storage');
            $newStoragePath = $this->normalizeFtpPath(rtrim($paths['ftp_root'] ?? '', '/') . '/' . rtrim($sharedRoot, '/') . '/storage');

            $this->info('Required Server-Side Follow-up Steps:');
            $this->line('1. Log into your FTP server.');
            $this->line("2. Move remote .env from {$oldEnvPath} to {$newEnvPath}");
            $this->line("3. Move remote storage/ from {$oldStoragePath} to {$newStoragePath}");
            $this->line('4. Ensure write permissions (e.g., chmod -R 775) are set on the remote storage directory.');
            $this->line("5. Run: php artisan ftp-deploy {$profileName}");
            $this->line('6. Confirm the first versioned release contains vendor/autoload.php before switching traffic.');
        }

        return self::SUCCESS;
    }

    private function normalizeFtpPath(string $path): string
    {
        $parts = explode('/', $path);
        $resolved = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($resolved);
            } else {
                $resolved[] = $part;
            }
        }

        return '/' . implode('/', $resolved);
    }

    /** @param array<string, mixed> $paths */
    private function validatePaths(array $paths): void
    {
        foreach (['ftp_root', 'app_root', 'public_root', 'app_url'] as $key) {
            if (empty($paths[$key])) {
                throw new RuntimeException("Missing path setting: paths.{$key}");
            }
        }

        foreach (['release_root', 'shared_root', 'current_path'] as $key) {
            if (empty($paths[$key])) {
                throw new RuntimeException("Missing versioned path setting: paths.{$key}");
            }
        }

        if (str_contains(trim((string) $paths['app_root'], '/').'/', trim((string) $paths['public_root'], '/').'/')) {
            throw new RuntimeException('paths.public_root cannot be inside paths.app_root with reversed nesting.');
        }
    }

    /** @return array{level:string,source:string,message:string} */
    private function log(string $level, string $source, string $message): array
    {
        return compact('level', 'source', 'message');
    }

    /** @param array<string, mixed> $payload */
    private function agentOutput(array $payload): void
    {
        $this->output->writeln(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
