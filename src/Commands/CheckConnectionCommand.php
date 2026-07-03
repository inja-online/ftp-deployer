<?php

namespace InjaOnline\FTPDeployer\Commands;

use Illuminate\Console\Command;
use InjaOnline\FTPDeployer\Support\FTPClient;
use RuntimeException;

class CheckConnectionCommand extends Command
{
    protected $signature = 'ftp-deploy:check {profile=production} {--format= : The output format (default, agent)}';

    protected $description = 'Verify the FTP connection and credentials for a specified profile.';

    public function handle(): int
    {
        $profileName = (string) $this->argument('profile');
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

        try {
            $this->validateProfile($profile);

            if ($agent) {
                $logs[] = $this->log('info', 'connection', 'Attempting FTP connection...');
            } else {
                $this->info('Connecting to FTP host...');
            }

            // Attempt connection
            FTPClient::connect($profile['ftp']);

            if ($agent) {
                $logs[] = $this->log('info', 'connection', 'FTP connection and login successful.');
                $this->agentOutput([
                    'status' => 'success',
                    'profile' => $profileName,
                    'message' => 'FTP connection and login successful.',
                    'logs' => $logs,
                ]);
            } else {
                $this->info('FTP connection and login successful.');
            }

        } catch (\Throwable $e) {
            if ($agent) {
                $logs[] = $this->log('error', 'connection', $e->getMessage());
                $this->agentOutput([
                    'status' => 'error',
                    'profile' => $profileName,
                    'message' => $e->getMessage(),
                    'logs' => $logs,
                ]);
            } else {
                $this->error("FTP connection failed: {$e->getMessage()}");
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @param array<string, mixed> $profile */
    private function validateProfile(array $profile): void
    {
        foreach (['host', 'username', 'password'] as $key) {
            if (empty($profile['ftp'][$key])) {
                throw new RuntimeException("Missing FTP setting: ftp.{$key}");
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
