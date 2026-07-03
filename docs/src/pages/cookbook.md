---
layout: ../layouts/DocsLayout.astro
title: Cookbook & Examples
description: Copy-paste deployment recipes for Laravel-only apps, React/Vite builds with Bun, custom Artisan setup commands, queues, caches, and common shared-host cases
---

# Cookbook & Examples

Use these recipes as starting points. Pick the smallest one that matches your app.

---

## Laravel app with no frontend

Use this when the app has no `package.json`, no Vite, no Mix, and no built assets.

```php
'frontend' => [
    'enabled' => false,
    'auto_detect' => false,
    'builds' => [],
    'warn_if_inputs_changed_without_output_change' => false,
],

'hooks' => [
    'before_deploy' => [],
    'after_deploy' => [],
],
```

Run:

```bash
php artisan ftp-deploy production
```

If `package.json` does not exist, frontend detection is already skipped silently. Disabling frontend config just makes intent explicit.

---

## Laravel + Vite/React with Bun

Use this when your Laravel app builds frontend assets with Vite and React, and `bun run build` writes to `public/build/`.

```php
'hooks' => [
    'before_deploy' => [
        'bun install --frozen-lockfile',
        'bun run build',
    ],
    'after_deploy' => [],
],

'frontend' => [
    'enabled' => true,
    'auto_detect' => true,
    'builds' => [],
    'warn_if_inputs_changed_without_output_change' => true,
],
```

Run:

```bash
php artisan ftp-deploy production
```

Flow:

1. Bun installs local/CI dependencies.
2. Vite builds `public/build/manifest.json` or `public/build/.vite/manifest.json`.
3. Deployer copies built files into the temp release.
4. Deployer uploads `public/build/` assets.

> [!IMPORTANT]
> The deployer does not run Bun automatically. Put `bun run build` in `hooks.before_deploy` or run it in CI before `php artisan ftp-deploy`.

---

## Laravel + Vite/React with npm

```php
'hooks' => [
    'before_deploy' => [
        'npm ci',
        'npm run build',
    ],
    'after_deploy' => [],
],
```

Use this when `package-lock.json` is committed.

---

## Build frontend manually before deploy

Use this when CI already builds assets, or when you want deploy to do upload only.

```bash
bun install --frozen-lockfile
bun run build
php artisan ftp-deploy production
```

Keep hooks empty:

```php
'hooks' => [
    'before_deploy' => [],
    'after_deploy' => [],
],
```

---

## Custom frontend output path

Use explicit builds when Vite output is not `public/build/`.

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

Make sure your `vite.config.js` uses matching output:

```js
export default defineConfig({
  build: {
    manifest: true,
    outDir: 'public/frontend',
  },
});
```

---

## App and admin frontend builds

Use this when the app has separate user and admin bundles.

```php
'frontend' => [
    'enabled' => true,
    'auto_detect' => false,
    'builds' => [
        'app' => [
            'type' => 'vite',
            'manifest' => 'public/build/manifest.json',
            'outputs' => ['public/build/'],
        ],
        'admin' => [
            'type' => 'vite',
            'manifest' => 'public/admin-build/manifest.json',
            'outputs' => ['public/admin-build/'],
        ],
    ],
],

'hooks' => [
    'before_deploy' => [
        'bun install --frozen-lockfile',
        'bun run build',
        'bun run build:admin',
    ],
    'after_deploy' => [],
],
```

---

## Add custom Artisan setup after install

Remote commands run after upload/extraction and before deploy completes. Add your app setup command there.

```php
'remote_commands' => [
    'migrate --force',
    'app:setup:cache',
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

Use required string commands when failure should fail the deploy. Use structured commands when failure is acceptable:

```php
'remote_commands' => [
    'migrate --force',
    ['command' => 'app:setup:cache', 'ignore_failures' => false],
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

> [!NOTE]
> `remote_commands` are Artisan commands, not shell commands. Use `hooks.before_deploy` or `hooks.after_deploy` for local shell commands.

---

## Seed required production data

```php
'remote_commands' => [
    'migrate --force',
    'db:seed --class=RequiredProductionSeeder --force',
    'app:setup:cache',
    'optimize:clear',
    'optimize',
],
```

Do not run broad seeders in production unless they are idempotent.

---

## First deploy without migrations

Use this when database already exists or you want to verify file deploy first.

```php
'remote_commands' => [
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

Add `migrate --force` after first successful deploy.

---

## Queue worker restart

Use this when remote app runs Laravel queues and the host lets PHP write cache.

```php
'remote_commands' => [
    'migrate --force',
    'optimize:clear',
    'optimize',
    'queue:restart',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

If queues are managed outside Laravel or not available on shared hosting, skip this.

---

## Clear then rebuild Laravel caches

```php
'remote_commands' => [
    'migrate --force',
    'optimize:clear',
    'config:cache',
    'route:cache',
    'view:cache',
    ['command' => 'event:cache', 'ignore_failures' => true],
],
```

Simpler default:

```php
'remote_commands' => [
    'migrate --force',
    'optimize:clear',
    'optimize',
],
```

Use the simpler default unless you need separate cache control.

---

## Local notification after successful deploy

`after_deploy` is local. It runs after remote deploy work succeeds.

```php
'hooks' => [
    'before_deploy' => [
        'bun run build',
    ],
    'after_deploy' => [
        'php artisan deploy:notify production',
    ],
],
```

If `after_deploy` fails, deploy command returns failure, but remote files have already been deployed.

---

## GitHub Actions with Bun

```yaml
name: Deploy

on:
  push:
    branches: [main]

jobs:
  deploy:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          extensions: ftp, zip
      - uses: oven-sh/setup-bun@v2
      - run: composer install --no-interaction --prefer-dist
      - run: bun install --frozen-lockfile
      - run: bun run build
      - run: php artisan ftp-deploy production --format=agent
        env:
          FTP_DEPLOYER_HOST: ${{ secrets.FTP_DEPLOYER_HOST }}
          FTP_DEPLOYER_USERNAME: ${{ secrets.FTP_DEPLOYER_USERNAME }}
          FTP_DEPLOYER_PASSWORD: ${{ secrets.FTP_DEPLOYER_PASSWORD }}
          FTP_DEPLOYER_APP_URL: https://example.com
          FTP_DEPLOYER_FILESYSTEM_ROOT: /home/user/public_html/example.com
```

---

## CI without frontend

```yaml
steps:
  - uses: actions/checkout@v4
  - uses: shivammathur/setup-php@v2
    with:
      php-version: '8.3'
      extensions: ftp, zip
  - run: composer install --no-interaction --prefer-dist
  - run: php artisan ftp-deploy production --format=agent
```

No Node, npm, pnpm, yarn, or Bun step needed.

---

## Simple cPanel layout

Use when domain document root can point to `app/public`.

```ini
FTP_DEPLOYER_MODE=simple
FTP_DEPLOYER_FTP_ROOT=/public_html/example.com
FTP_DEPLOYER_APP_ROOT=app
FTP_DEPLOYER_PUBLIC_ROOT=app/public
FTP_DEPLOYER_APP_URL=https://example.com
FTP_DEPLOYER_FILESYSTEM_ROOT=/home/user/public_html/example.com
```

Remote files:

```text
/public_html/example.com/app/.env
/public_html/example.com/app/storage/
/public_html/example.com/app/public/index.php
```

---

## Versioned cPanel layout

Use when domain document root is stable `public_html` and app releases live outside it.

```ini
FTP_DEPLOYER_MODE=versioned
FTP_DEPLOYER_FTP_ROOT=/
FTP_DEPLOYER_PUBLIC_ROOT=public_html
FTP_DEPLOYER_RELEASE_ROOT=../app/releases
FTP_DEPLOYER_SHARED_ROOT=../app/shared
FTP_DEPLOYER_CURRENT_PATH=../app/current
FTP_DEPLOYER_APP_URL=https://example.com
FTP_DEPLOYER_FILESYSTEM_ROOT=/home/user
```

Remote files:

```text
/home/user/public_html/index.php          managed bootloader
/home/user/app/shared/.env
/home/user/app/shared/storage/
/home/user/app/releases/20260702123456/
```

---

## Disable archive mode

Use this only when remote PHP cannot extract ZIP files or `filesystem_root` cannot be mapped.

```ini
FTP_DEPLOYER_ARCHIVE_ENABLED=false
```

Archive mode is usually faster because it avoids thousands of FTP round trips.

---

## Which hook should I use?

| Need | Use |
|---|---|
| Build React/Vite with Bun | `hooks.before_deploy` |
| Run tests before upload | `hooks.before_deploy` |
| Run Laravel migrations on remote host | `remote_commands` |
| Run `php artisan app:setup:cache` on remote host | `remote_commands` |
| Send local Slack/email notification after success | `hooks.after_deploy` |
| Run shell commands on remote host | Not supported; FTP-only hosts have no SSH |

