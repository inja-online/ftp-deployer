<?php

namespace InjaOnline\FTPDeployer\Tests\Unit;

use InjaOnline\FTPDeployer\FTPDeployerServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

class MigrateToVersionedCommandTest extends OrchestraTestCase
{
    private string $tempEnvPath;

    protected function getPackageProviders($app)
    {
        return [
            FTPDeployerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app)
    {
        // Simple mode profile
        $app['config']->set('ftp-deployer.profiles.production', [
            'paths' => [
                'mode' => 'simple',
                'ftp_root' => '/public_html',
                'app_root' => 'app',
                'public_root' => 'public',
                'app_url' => 'http://example.com',
            ],
        ]);

        // Profile with missing required fields
        $app['config']->set('ftp-deployer.profiles.incomplete', [
            'paths' => [
                'mode' => 'simple',
                'ftp_root' => '/public_html',
                // app_root is missing
                'public_root' => 'public',
                'app_url' => 'http://example.com',
            ],
        ]);

        // Already versioned profile
        $app['config']->set('ftp-deployer.profiles.versioned_profile', [
            'paths' => [
                'mode' => 'versioned',
                'ftp_root' => '/public_html',
                'app_root' => 'app',
                'public_root' => 'public',
                'app_url' => 'http://example.com',
                'release_root' => 'app/releases',
                'shared_root' => 'app/shared',
                'current_path' => 'app/current',
            ],
        ]);

        // Profile with filesystem_root
        $app['config']->set('ftp-deployer.profiles.with_filesystem_root', [
            'paths' => [
                'mode' => 'simple',
                'ftp_root' => '/public_html',
                'app_root' => 'app',
                'public_root' => 'public',
                'app_url' => 'http://example.com',
                'filesystem_root' => '/home/tinabwoo/public_html/laravelapp.inja.online',
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempEnvPath = base_path('.env');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempEnvPath)) {
            unlink($this->tempEnvPath);
        }
        parent::tearDown();
    }

    public function test_it_fails_when_profile_is_missing(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'nonexistent'])
            ->expectsOutput('Deploy profile [nonexistent] is missing.')
            ->assertExitCode(1);
    }

    public function test_it_fails_when_profile_is_missing_in_agent_format(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'nonexistent', '--format' => 'agent'])
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

    public function test_it_fails_if_target_mode_is_not_versioned(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'production', '--mode' => 'simple'])
            ->expectsOutput('Target mode must be versioned.')
            ->assertExitCode(1);
    }

    public function test_it_fails_if_target_mode_is_not_versioned_in_agent_format(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'production', '--mode' => 'simple', '--format' => 'agent'])
            ->expectsOutput(json_encode([
                'status' => 'error',
                'profile' => 'production',
                'message' => 'Target mode must be versioned.',
                'logs' => [
                    ['level' => 'error', 'source' => 'validation', 'message' => 'Target mode must be versioned.'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->assertExitCode(1);
    }

    public function test_it_runs_interactive_migration_and_prints_plan_and_checklist(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'production'])
            ->expectsQuestion('Enter release root', '../app/releases')
            ->expectsQuestion('Enter shared root', '../app/shared')
            ->expectsQuestion('Enter current path', '../app/current')
            ->expectsOutput('Migration Plan for profile [production]:')
            ->expectsOutput('Proposed Path Settings:')
            ->expectsOutput('  - release_root: ../app/releases')
            ->expectsOutput('  - shared_root:  ../app/shared')
            ->expectsOutput('  - current_path: ../app/current')
            ->expectsOutput('Required Server-Side Follow-up Steps:')
            ->expectsOutput('6. Confirm the first versioned release contains vendor/autoload.php before switching traffic.')
            ->assertExitCode(0);
    }

    public function test_it_fails_validation_if_required_path_setting_is_missing(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'incomplete'])
            ->expectsQuestion('Enter release root', '../app/releases')
            ->expectsQuestion('Enter shared root', '../app/shared')
            ->expectsQuestion('Enter current path', '../app/current')
            ->expectsOutput('Missing path setting: paths.app_root')
            ->assertExitCode(1);
    }

    public function test_it_fails_validation_in_agent_format_if_required_path_setting_is_missing(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'incomplete', '--format' => 'agent'])
            ->expectsOutput(json_encode([
                'status' => 'error',
                'profile' => 'incomplete',
                'message' => 'Missing path setting: paths.app_root',
                'logs' => [
                    ['level' => 'error', 'source' => 'validation', 'message' => 'Missing path setting: paths.app_root'],
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->assertExitCode(1);
    }

    public function test_it_can_write_to_local_env_file(): void
    {
        file_put_contents($this->tempEnvPath, "FTP_DEPLOYER_MODE=simple\n");

        $this->artisan('ftp-deploy:migrate', ['profile' => 'production', '--write' => true])
            ->expectsQuestion('Enter release root', '../app/releases')
            ->expectsQuestion('Enter shared root', '../app/shared')
            ->expectsQuestion('Enter current path', '../app/current')
            ->expectsConfirmation('Are you sure you want to write these settings to your local .env file?', 'yes')
            ->expectsOutput('Local configuration successfully updated in your .env file!')
            ->assertExitCode(0);

        $envContent = file_get_contents($this->tempEnvPath);
        $this->assertStringContainsString('FTP_DEPLOYER_MODE=versioned', $envContent);
        $this->assertStringContainsString('FTP_DEPLOYER_RELEASE_ROOT=../app/releases', $envContent);
        $this->assertStringContainsString('FTP_DEPLOYER_SHARED_ROOT=../app/shared', $envContent);
        $this->assertStringContainsString('FTP_DEPLOYER_CURRENT_PATH=../app/current', $envContent);
    }

    public function test_it_handles_missing_local_env_file_gracefully(): void
    {
        if (file_exists($this->tempEnvPath)) {
            unlink($this->tempEnvPath);
        }

        $this->artisan('ftp-deploy:migrate', ['profile' => 'production', '--write' => true])
            ->expectsQuestion('Enter release root', '../app/releases')
            ->expectsQuestion('Enter shared root', '../app/shared')
            ->expectsQuestion('Enter current path', '../app/current')
            ->expectsConfirmation('Are you sure you want to write these settings to your local .env file?', 'yes')
            ->expectsOutput('Local write requested but could not be completed (e.g. .env is missing or not writable).')
            ->expectsOutput('FTP_DEPLOYER_MODE=versioned')
            ->expectsOutput('FTP_DEPLOYER_RELEASE_ROOT=../app/releases')
            ->expectsOutput('FTP_DEPLOYER_SHARED_ROOT=../app/shared')
            ->expectsOutput('FTP_DEPLOYER_CURRENT_PATH=../app/current')
            ->assertExitCode(0);
    }

    public function test_it_runs_non_interactively_in_agent_format(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'production', '--format' => 'agent'])
            ->expectsOutput(json_encode([
                'status' => 'success',
                'profile' => 'production',
                'logs' => [
                    ['level' => 'info', 'source' => 'migration', 'message' => 'Migration plan generated successfully.'],
                ],
                'migration' => [
                    'profile' => 'production',
                    'old_mode' => 'simple',
                    'new_mode' => 'versioned',
                    'paths' => [
                        'release_root' => '../app/releases',
                        'shared_root' => '../app/shared',
                        'current_path' => '../app/current',
                    ],
                    'written' => false,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->assertExitCode(0);
    }

    public function test_it_runs_non_interactively_in_agent_format_with_write_enabled(): void
    {
        file_put_contents($this->tempEnvPath, "FTP_DEPLOYER_MODE=simple\n");

        $this->artisan('ftp-deploy:migrate', ['profile' => 'production', '--write' => true, '--format' => 'agent'])
            ->expectsOutput(json_encode([
                'status' => 'success',
                'profile' => 'production',
                'logs' => [
                    ['level' => 'info', 'source' => 'migration', 'message' => 'Migration plan generated successfully.'],
                    ['level' => 'info', 'source' => 'migration', 'message' => 'Local configuration successfully updated.'],
                ],
                'migration' => [
                    'profile' => 'production',
                    'old_mode' => 'simple',
                    'new_mode' => 'versioned',
                    'paths' => [
                        'release_root' => '../app/releases',
                        'shared_root' => '../app/shared',
                        'current_path' => '../app/current',
                    ],
                    'written' => true,
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->assertExitCode(0);

        $envContent = file_get_contents($this->tempEnvPath);
        $this->assertStringContainsString('FTP_DEPLOYER_MODE=versioned', $envContent);
        $this->assertStringContainsString('FTP_DEPLOYER_RELEASE_ROOT=../app/releases', $envContent);
    }

    public function test_it_uses_absolute_paths_when_filesystem_root_is_defined(): void
    {
        $this->artisan('ftp-deploy:migrate', ['profile' => 'with_filesystem_root'])
            ->expectsQuestion('Enter release root', '/home/tinabwoo/public_html/laravelapp.inja.online/app/releases')
            ->expectsQuestion('Enter shared root', '/home/tinabwoo/public_html/laravelapp.inja.online/app/shared')
            ->expectsQuestion('Enter current path', '/home/tinabwoo/public_html/laravelapp.inja.online/app/current')
            ->expectsOutput('Migration Plan for profile [with_filesystem_root]:')
            ->expectsOutput('Proposed Path Settings:')
            ->expectsOutput('  - release_root: /home/tinabwoo/public_html/laravelapp.inja.online/app/releases')
            ->expectsOutput('  - shared_root:  /home/tinabwoo/public_html/laravelapp.inja.online/app/shared')
            ->expectsOutput('  - current_path: /home/tinabwoo/public_html/laravelapp.inja.online/app/current')
            ->assertExitCode(0);
    }
}
