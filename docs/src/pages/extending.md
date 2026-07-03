---
layout: ../layouts/DocsLayout.astro
title: Extending & Overriding
description: How to customize FTP Deployer with config, hooks, custom Artisan commands, wrapper commands, and Laravel container overrides
---

# Extending & Overriding

Start with config. Only replace code when config cannot do it.

---

## Extension ladder

Use the first option that works:

1. **Config**: profiles, paths, exclusions, frontend builds, remote commands.
2. **Local hooks**: shell commands before/after deploy on your machine or CI.
3. **Remote commands**: Artisan commands executed on the shared host after upload/extraction.
4. **Custom Artisan command**: wrap `ftp-deploy` with your own checks or workflow.
5. **Fork/PR**: change package internals when deploy pipeline behavior itself must change.

Current package has no formal plugin API for replacing `ReleaseBuilder`, `FTPClient`, `Runner`, or `Manifest` from config.

---

## Add behavior before deployment

Use `hooks.before_deploy` for local shell work.

```php
'hooks' => [
    'before_deploy' => [
        'composer test',
        'bun install --frozen-lockfile',
        'bun run build',
    ],
    'after_deploy' => [],
],
```

Good for:

- tests
- static analysis
- frontend builds
- generating local files included in release

Bad for:

- remote migrations
- remote cache rebuilds
- remote shell commands

If any command exits non-zero, deploy stops before FTP work starts.

---

## Add behavior after remote install

Use `remote_commands` for remote Laravel Artisan work.

```php
'remote_commands' => [
    'migrate --force',
    'app:setup:cache',
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

Commands are run by the temporary HTTP runner after upload/extraction.

Use strings for required commands:

```php
'app:setup:cache'
```

Use structured commands for optional steps:

```php
['command' => 'storage:link', 'ignore_failures' => true]
```

> [!IMPORTANT]
> Remote commands are Artisan commands only. They are not shell commands. Shared FTP-only hosts usually do not provide SSH shell access.

---

## Add behavior after successful deploy

Use `hooks.after_deploy` for local notification or local follow-up.

```php
'hooks' => [
    'before_deploy' => [],
    'after_deploy' => [
        'php artisan deploy:notify production',
    ],
],
```

`after_deploy` runs after upload, runner execution, manifest save, version activation, and cleanup succeed.

If it fails, the command returns failure, but remote deploy work already happened.

---

## Add custom profile behavior

Create another profile instead of branching inside commands.

```php
'profiles' => [
    'production' => [
        // normal deploy
    ],

    'staging' => [
        'ftp' => [/* staging FTP */],
        'paths' => [/* staging paths */],
        'hooks' => [
            'before_deploy' => ['bun run build:staging'],
            'after_deploy' => [],
        ],
        'remote_commands' => [
            'migrate --force',
            'optimize:clear',
        ],
    ],
],
```

Run:

```bash
php artisan ftp-deploy staging
```

---

## Override frontend detection with explicit builds

Use this when auto-detection does not match your build output.

```php
'frontend' => [
    'enabled' => true,
    'auto_detect' => false,
    'builds' => [
        'app' => [
            'type' => 'vite',
            'manifest' => 'public/frontend/manifest.json',
            'outputs' => ['public/frontend/'],
        ],
    ],
    'warn_if_inputs_changed_without_output_change' => true,
],
```

No package code needed.

---

## Extend deployment with your own Artisan wrapper

Use a wrapper when you need app-specific checks around deploy but not a changed deploy engine.

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class ProductionDeployCommand extends Command
{
    protected $signature = 'app:deploy-production';

    protected $description = 'Run app-specific checks, then FTP deploy production.';

    public function handle(): int
    {
        $this->call('test');

        if (! file_exists(public_path('build/manifest.json'))
            && ! file_exists(public_path('build/.vite/manifest.json'))) {
            $this->error('Frontend build missing. Run bun run build first.');

            return self::FAILURE;
        }

        return $this->call('ftp-deploy', [
            'profile' => 'production',
        ]);
    }
}
```

Run:

```bash
php artisan app:deploy-production
```

This keeps package updates easy.

---

## Override the facade binding

The service provider registers a singleton named `ftp-deployer` for facade usage.

```php
$this->app->singleton('ftp-deployer', function () {
    return new \App\Deploy\CustomFTPDeployer(config('ftp-deployer.profiles.production'));
});
```

Use this only if your own code calls the facade:

```php
FTPDeployer::deploy($progress);
```

> [!WARNING]
> The built-in `php artisan ftp-deploy` command currently constructs `new FTPDeployer($profile)` directly. Rebinding `ftp-deployer` does not replace the built-in command pipeline.

---

## Replace the built-in command behavior

Laravel will not let two commands with the same name behave predictably. Do not register another `ftp-deploy` command with the same signature unless you control provider order and accept the risk.

Safer option: create your own command name and call your own deploy class.

```php
<?php

namespace App\Console\Commands;

use App\Deploy\CustomFTPDeployer;
use Illuminate\Console\Command;

class CustomFtpDeployCommand extends Command
{
    protected $signature = 'app:ftp-deploy {profile=production}';

    public function handle(): int
    {
        $profile = config('ftp-deployer.profiles.'.$this->argument('profile'));

        if (! is_array($profile)) {
            $this->error('Deploy profile missing.');

            return self::FAILURE;
        }

        $result = (new CustomFTPDeployer($profile))->deploy(fn (string $line) => $this->line($line));

        $this->info("Uploaded {$result['uploaded']}, deleted {$result['deleted']}, skipped {$result['skipped']}.");

        return self::SUCCESS;
    }
}
```

Run:

```bash
php artisan app:ftp-deploy production
```

---

## Publish and edit config safely

Publish config once:

```bash
php artisan vendor:publish --tag=ftp-deployer-config
```

Then keep changes in your app's `config/ftp-deployer.php`. Do not edit files in `vendor/`; Composer updates will overwrite them.

---

## When to fork instead

Fork or open a PR when you need to change package internals, such as:

- different upload protocol
- different archive naming
- custom manifest format
- custom runner payload/response handling
- different version activation strategy
- new first-class config option useful to other apps

For one app, wrapper command is usually cheaper. For reusable behavior, PR is cleaner.

---

## Common customizations matrix

| Need | Best extension point |
|---|---|
| Run React/Vite/Bun build | `hooks.before_deploy` |
| No frontend | `frontend.enabled = false` |
| Add `php artisan app:setup:cache` on host | `remote_commands` |
| Ignore `storage:link` failure | structured `remote_commands` entry |
| Deploy staging and production differently | separate profiles |
| Validate build output before deploy | wrapper Artisan command |
| Send notification after success | `hooks.after_deploy` |
| Change FTP engine or runner internals | fork/PR |
| Replace built-in deploy algorithm | custom command with different name |
