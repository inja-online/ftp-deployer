<?php

namespace InjaOnline\FTPDeployer\Tests\Unit;

use InjaOnline\FTPDeployer\FTPDeployerServiceProvider;
use InjaOnline\FTPDeployer\Support\FTPClient;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use RuntimeException;

class CheckConnectionCommandTest extends OrchestraTestCase
{
    protected function getPackageProviders($app)
    {
        return [
            FTPDeployerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('ftp-deployer.profiles.production', [
            'ftp' => [
                'host' => 'ftp.example.com',
                'username' => 'user',
                'password' => 'pass',
                'port' => 21,
                'ssl' => false,
                'passive' => true,
                'timeout' => 90,
            ],
        ]);
    }

    protected function tearDown(): void
    {
        FTPClient::$connectUsing = null;
        parent::tearDown();
    }

    public function test_it_verifies_successful_ftp_connection(): void
    {
        FTPClient::$connectUsing = function (array $config) {
            $reflector = new \ReflectionClass(FTPClient::class);

            return $reflector->newInstanceWithoutConstructor();
        };

        $this->artisan('ftp-deploy:check', ['profile' => 'production'])
            ->expectsOutput('Connecting to FTP host...')
            ->expectsOutput('FTP connection and login successful.')
            ->assertExitCode(0);
    }

    public function test_it_verifies_failed_ftp_connection(): void
    {
        FTPClient::$connectUsing = function (array $config) {
            throw new RuntimeException('FTP login failed.');
        };

        $this->artisan('ftp-deploy:check', ['profile' => 'production'])
            ->expectsOutput('Connecting to FTP host...')
            ->expectsOutput('FTP connection failed: FTP login failed.')
            ->assertExitCode(1);
    }

    public function test_it_verifies_successful_ftp_connection_in_agent_format(): void
    {
        FTPClient::$connectUsing = function (array $config) {
            $reflector = new \ReflectionClass(FTPClient::class);

            return $reflector->newInstanceWithoutConstructor();
        };

        $this->artisan('ftp-deploy:check', ['profile' => 'production', '--format' => 'agent'])
            ->expectsOutput(json_encode([
                'status' => 'success',
                'profile' => 'production',
                'message' => 'FTP connection and login successful.',
                'logs' => [
                    ['level' => 'info', 'source' => 'connection', 'message' => 'Attempting FTP connection...'],
                    ['level' => 'info', 'source' => 'connection', 'message' => 'FTP connection and login successful.'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->assertExitCode(0);
    }

    public function test_it_verifies_failed_ftp_connection_in_agent_format(): void
    {
        FTPClient::$connectUsing = function (array $config) {
            throw new RuntimeException('FTP login failed.');
        };

        $this->artisan('ftp-deploy:check', ['profile' => 'production', '--format' => 'agent'])
            ->expectsOutput(json_encode([
                'status' => 'error',
                'profile' => 'production',
                'message' => 'FTP login failed.',
                'logs' => [
                    ['level' => 'info', 'source' => 'connection', 'message' => 'Attempting FTP connection...'],
                    ['level' => 'error', 'source' => 'connection', 'message' => 'FTP login failed.'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->assertExitCode(1);
    }

    public function test_it_fails_for_invalid_profile(): void
    {
        $this->artisan('ftp-deploy:check', ['profile' => 'nonexistent'])
            ->expectsOutput('Deploy profile [nonexistent] is missing.')
            ->assertExitCode(1);
    }

    public function test_it_fails_for_invalid_profile_in_agent_format(): void
    {
        $this->artisan('ftp-deploy:check', ['profile' => 'nonexistent', '--format' => 'agent'])
            ->expectsOutput(json_encode([
                'status' => 'error',
                'profile' => 'nonexistent',
                'message' => 'Deploy profile [nonexistent] is missing.',
                'logs' => [
                    ['level' => 'error', 'source' => 'validation', 'message' => 'Deploy profile [nonexistent] is missing.'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->assertExitCode(1);
    }
}
