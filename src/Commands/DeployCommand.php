<?php

namespace InjaOnline\FTPDeployer\Commands;

use Illuminate\Console\Command;
use InjaOnline\FTPDeployer\FTPDeployer;
use RuntimeException;
use Symfony\Component\Process\Process;

class DeployCommand extends Command
{
    protected $signature = 'ftp-deploy {profile=production} {--format= : The output format (default, agent)}';

    protected $description = 'Deploy Laravel to a cPanel-style FTP host.';

    public function handle(): int
    {
        $started = microtime(true);
        $profileName = (string) $this->argument('profile');
        $agent = $this->option('format') === 'agent';
        $logs = [];
        $profile = config("ftp-deployer.profiles.{$profileName}");

        if (!$agent) {
            $this->line("Starting FTP deploy [{$profileName}] at ".date('Y-m-d H:i:s'));
        }

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

        try {
            $this->validateProfile($profile);
            $this->runHooks($profile['hooks']['before_deploy'] ?? [], $agent, $logs);
            $result = (new FTPDeployer($profile))->deploy(function (string $line) use ($agent, &$logs): void {
                if ($agent) {
                    $logs[] = $this->log(str_starts_with($line, 'Warning:') ? 'warning' : 'info', 'deploy', $line);

                    return;
                }

                $this->line($line);
            });
            $this->runHooks($profile['hooks']['after_deploy'] ?? [], $agent, $logs);
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

        if ($agent) {
            $this->agentOutput([
                'status' => 'success',
                'profile' => $profileName,
                'uploaded' => $result['uploaded'],
                'deleted' => $result['deleted'],
                'skipped' => $result['skipped'],
                'logs' => $logs,
                'runner' => $result['runner'],
            ]);
        } else {
            $this->info("Deploy complete in ".number_format(microtime(true) - $started, 2)."s. Uploaded {$result['uploaded']}, deleted {$result['deleted']}, skipped {$result['skipped']}.");
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $profile */
    public function validateProfile(array $profile): void
    {
        foreach (['host', 'username', 'password'] as $key) {
            if (empty($profile['ftp'][$key])) {
                throw new RuntimeException("Missing FTP setting: ftp.{$key}");
            }
        }

        foreach (['ftp_root', 'app_root', 'public_root', 'app_url'] as $key) {
            if (empty($profile['paths'][$key])) {
                throw new RuntimeException("Missing path setting: paths.{$key}");
            }
        }

        $mode = $profile['paths']['mode'] ?? 'simple';
        if (!in_array($mode, ['simple', 'versioned'], true)) {
            throw new RuntimeException('paths.mode must be simple or versioned.');
        }

        if (str_contains(trim($profile['paths']['app_root'], '/').'/', trim($profile['paths']['public_root'], '/').'/')) {
            throw new RuntimeException('paths.public_root cannot be inside paths.app_root with reversed nesting.');
        }

        if ($mode === 'versioned') {
            foreach (['release_root', 'shared_root', 'current_path'] as $key) {
                if (empty($profile['paths'][$key])) {
                    throw new RuntimeException("Missing versioned path setting: paths.{$key}");
                }
            }
        }
    }

    /**
     * @param list<string> $hooks
     * @param list<array{level:string,source:string,message:string}> $logs
     */
    private function runHooks(array $hooks, bool $agent, array &$logs): void
    {
        foreach ($hooks as $hook) {
            $process = Process::fromShellCommandline($hook, getcwd() ?: null);
            $process->setTimeout(null);
            $process->run(function ($type, $buffer) use ($agent, &$logs): void {
                if ($agent) {
                    $logs[] = $this->log($type === Process::ERR ? 'warning' : 'info', 'hook', trim($buffer));

                    return;
                }

                $this->output->write($buffer);
            });

            if (!$process->isSuccessful()) {
                throw new RuntimeException("Local hook failed: {$hook}");
            }
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
