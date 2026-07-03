<?php

namespace InjaOnline\FTPDeployer;

use Illuminate\Support\ServiceProvider;
use InjaOnline\FTPDeployer\Commands\CheckConnectionCommand;
use InjaOnline\FTPDeployer\Commands\DeployCommand;
use InjaOnline\FTPDeployer\Commands\MigrateToVersionedCommand;

class FTPDeployerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $config = [__DIR__.'/../config/config.php' => config_path('ftp-deployer.php')];
            $this->publishes($config, 'config');
            $this->publishes($config, 'ftp-deployer-config');

            $this->commands([
                DeployCommand::class,
                CheckConnectionCommand::class,
                MigrateToVersionedCommand::class,
            ]);
        }
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/config.php', 'ftp-deployer');

        $this->app->singleton('ftp-deployer', fn () => new FTPDeployer([]));
    }
}
