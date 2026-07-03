---
layout: ../layouts/DocsLayout.astro
title: CLI Commands Reference
description: Reference for the ftp-deploy Artisan command, profile argument, output, and failure behavior
---

# CLI Commands Reference

FTP Deployer exposes two Artisan commands: `ftp-deploy` to execute the full deployment pipeline, and `ftp-deploy:check` to verify the FTP connection settings.

---

## Command signature

```bash
php artisan ftp-deploy {profile=production} {--format=}
```

Arguments:

| Argument | Required | Default | Description |
|---|---:|---|---|
| `profile` | no | `production` | Profile key under `config('ftp-deployer.profiles')`. |

Options:

| Option | Required | Default | Description |
|---|---:|---|---|
| `--format` | no | human text | Use `--format=agent` to print one structured JSON object for automation. |

Examples:

```bash
php artisan ftp-deploy
php artisan ftp-deploy production
php artisan ftp-deploy staging
php artisan ftp-deploy production --format=agent
```

---

## What the command does

In order:

1. Reads selected profile from `config/ftp-deployer.php`.
2. Validates required FTP settings.
3. Validates required path settings.
4. Validates `paths.mode` is `simple` or `versioned`.
5. Validates versioned-only settings when versioned mode is enabled.
6. Runs local `hooks.before_deploy` commands.
7. Creates temp release directory.
8. Copies deployable files while applying exclusions.
9. Reuses local production vendor cache when Composer hashes match; otherwise runs production Composer install and saves cache.
10. Removes runtime/cache files from temp release.
11. Detects frontend builds and warnings.
12. Builds local manifest.
13. Connects to FTP/FTPS.
14. Loads remote manifest from `.ftp-deployer/manifest.json`.
15. Computes uploads, deletes, skipped files, and upload directories.
16. In archive mode, creates `app-{random}.zip` and deterministic `vendor-{composer_json}-{composer_lock}.zip` metadata.
17. Uploads app archive every deploy.
18. Uploads vendor archive only when the hash-named archive is missing remotely.
19. In versioned mode, uploads managed public bootloader with the new release id hardcoded.
20. Generates and uploads temporary HTTP runner.
21. Calls runner via HTTP POST to extract app archive.
22. Calls runner to extract vendor into the live app root or fresh release.
23. Deletes removed managed files.
24. Saves new remote manifest.
25. Calls runner via HTTP POST with remote command list.
26. Updates compatibility current pointer in versioned mode.
27. Deletes runner and temporary app archive over FTP, best effort.
28. Deletes local temp release directory.
29. Runs local `hooks.after_deploy` commands.
30. Prints summary with elapsed time.

---

## Output

During deploy, progress lines show start time, temp paths, Composer hashes, archive reuse, runner phases, and final duration:

```text
Starting FTP deploy [production] at 2026-07-02 21:08:55
Building temporary release
Composer vendor cache hit: /tmp/ftp-deployer-vendor-376a...-d4a...
Temporary release ready: /tmp/ftp-deployer-6ebf04d3dd3700e2
Composer inputs: composer.json=376a..., composer.lock=d4a...
Connecting FTP: ftp.example.com
Archive deploy: 89 changed file(s), 0 delete(s), 6 unchanged file(s).
Creating app archive temp: /tmp/app-714b....zip
Vendor archive name from composer hashes: vendor-376a...-d4a....zip
Uploading app.zip (323.1 KB) -> .ftp-deployer/archives/app-714b....zip
Reusing vendor.zip already on remote: .ftp-deployer/archives/vendor-376a...-d4a....zip
Uploading managed public bootloader for release 20260702210855
Temporary installer ready: install-3cf46566f3ae7312.php
Uploading install-3cf46566f3ae7312.php -> public_html/install-3cf46566f3ae7312.php
Calling install-3cf46566f3ae7312.php to extract 2 archive(s)
Calling install-3cf46566f3ae7312.php to run 4 remote command(s): migrate --force, optimize:clear, optimize, storage:link
Deploy complete in 12.34s. Uploaded 1, deleted 0, skipped 6.
```

`Uploaded` counts archives/files actually uploaded. A reused vendor archive does not increase this count.

Frontend warnings are printed as warning lines:

```text
Warning: package.json found but no build manifest detected — did you run your build step?
```

### Agent JSON output

Use `--format=agent` when scripts or AI agents need parseable output:

```bash
php artisan ftp-deploy production --format=agent
```

In agent format, progress messages and local hook output are buffered into `logs`; stdout receives one JSON object at the end.

Successful deploy example:

```json
{
  "status": "success",
  "profile": "production",
  "uploaded": 3,
  "deleted": 1,
  "skipped": 120,
  "logs": [
    {"level": "info", "source": "deploy", "message": "Uploading routes/web.php"}
  ],
  "runner": {"ok": true, "logs": []}
}
```

Failed deploy example:

```json
{
  "status": "error",
  "profile": "production",
  "message": "Missing FTP setting: ftp.host",
  "logs": [
    {"level": "error", "source": "validation", "message": "Missing FTP setting: ftp.host"}
  ]
}
```

---

## Exit codes

The command returns Laravel console status codes:

| Result | Exit code meaning |
|---|---|
| Deploy succeeds | `Command::SUCCESS` (`0`) |
| Deploy fails | `Command::FAILURE` (`1`) |

Failures are printed as one error message from the thrown exception.

---

## Migration command (`ftp-deploy:migrate`)

Guides a user from `simple` deployment settings to `versioned` deployment settings for a selected profile.

### Command signature

```bash
php artisan ftp-deploy:migrate {profile=production} {--mode=versioned} {--write} {--format=}
```

Arguments:

| Argument | Required | Default | Description |
|---|---:|---|---|
| `profile` | no | `production` | Profile key under `config('ftp-deployer.profiles')`. |

Options:

| Option | Required | Default | Description |
|---|---:|---|---|
| `--mode` | no | `versioned` | Target mode to migrate to. |
| `--write` | no | (plan only) | Update local `.env` with the generated configuration. |
| `--format` | no | human text | Use `--format=agent` to print one structured JSON object for automation. |

### Output

The migration command will prompt for missing values (release root, shared root, and compatibility current pointer path) and run validation on the target profile configuration. It then shows a migration plan, environment variables block, and server-side instructions:

```text
Migration Plan for profile [production]:
----------------------------------------
Target Mode: versioned
Proposed Path Settings:
  - ftp_root:     /public_html
  - app_root:     app
  - public_root:  public
  - release_root: app/releases
  - shared_root:  app/shared
  - current_path: app/current

WARNING: Remote .env and storage directory must be manually moved on your FTP server.

Required Server-Side Follow-up Steps:
1. Log into your FTP server.
2. Move remote .env from /public_html/app/.env to /public_html/app/shared/.env
3. Move remote storage/ from /public_html/app/storage to /public_html/app/shared/storage
4. Ensure write permissions (e.g., chmod -R 775) are set on the remote storage directory.
5. Run: php artisan ftp-deploy production
6. Confirm public/index.php contains the deployed release id and that the release contains `vendor/autoload.php`.
```

### Agent JSON output

Use `--format=agent` when scripts or AI agents need parseable output:

Successful migration example:

```json
{
  "status": "success",
  "profile": "production",
  "logs": [
    {"level": "info", "source": "migration", "message": "Migration plan generated successfully."}
  ],
  "migration": {
    "profile": "production",
    "old_mode": "simple",
    "new_mode": "versioned",
    "paths": {
      "release_root": "app/releases",
      "shared_root": "app/shared",
      "current_path": "app/current"
    },
    "written": false
  }
}
```

Failed migration example:

```json
{
  "status": "error",
  "profile": "production",
  "message": "Missing path setting: paths.app_root",
  "logs": [
    {"level": "error", "source": "validation", "message": "Missing path setting: paths.app_root"}
  ]
}
```

---

## Connection check command (`ftp-deploy:check`)

Verify FTP connection and credentials for a specified profile without performing any deployment operations.

### Command signature

```bash
php artisan ftp-deploy:check {profile=production} {--format=}
```

Arguments:

| Argument | Required | Default | Description |
|---|---:|---|---|
| `profile` | no | `production` | Profile key under `config('ftp-deployer.profiles')`. |

Options:

| Option | Required | Default | Description |
|---|---:|---|---|
| `--format` | no | human text | Use `--format=agent` to print one structured JSON object for automation. |

### Output

During the check, progress and status are printed:

```text
Connecting to FTP host...
FTP connection and login successful.
```

If it fails:

```text
Connecting to FTP host...
FTP connection failed: FTP login failed.
```

### Agent JSON output

Use `--format=agent` when scripts or AI agents need parseable output:

Successful check example:

```json
{
  "status": "success",
  "profile": "production",
  "message": "FTP connection and login successful.",
  "logs": [
    {"level": "info", "source": "connection", "message": "Attempting FTP connection..."},
    {"level": "info", "source": "connection", "message": "FTP connection and login successful."}
  ]
}
```

Failed check example:

```json
{
  "status": "error",
  "profile": "production",
  "message": "FTP login failed.",
  "logs": [
    {"level": "info", "source": "connection", "message": "Attempting FTP connection..."},
    {"level": "error", "source": "connection", "message": "FTP login failed."}
  ]
}
```

### Exit codes

The command returns Laravel console status codes:

| Result | Exit code meaning |
|---|---|
| Connection succeeds | `Command::SUCCESS` (`0`) |
| Connection fails | `Command::FAILURE` (`1`) |

---

## Profile validation errors

### Missing profile

```text
Deploy profile [staging] is missing.
```

Fix: add `profiles.staging` to `config/ftp-deployer.php` or use an existing profile.

### Missing FTP setting

```text
Missing FTP setting: ftp.host
Missing FTP setting: ftp.username
Missing FTP setting: ftp.password
```

Fix: set `FTP_DEPLOYER_HOST`, `FTP_DEPLOYER_USERNAME`, and `FTP_DEPLOYER_PASSWORD` or hardcode non-secret equivalents in config.

### Missing path setting

```text
Missing path setting: paths.ftp_root
Missing path setting: paths.app_root
Missing path setting: paths.public_root
Missing path setting: paths.app_url
```

Fix: configure required path keys.

### Invalid mode

```text
paths.mode must be simple or versioned.
```

Fix: use:

```ini
FTP_DEPLOYER_MODE=simple
```

or:

```ini
FTP_DEPLOYER_MODE=versioned
```

### Missing versioned path

```text
Missing versioned path setting: paths.release_root
Missing versioned path setting: paths.shared_root
Missing versioned path setting: paths.current_path
```

Fix: configure versioned paths.

---

## Remote runner failures

The runner returns JSON. The CLI wraps failed runner responses as:

```text
Runner failed: {"ok":false,"logs":[...]}
```

Common causes:

| Error | Cause | Fix |
|---|---|---|
| `Unauthorized` | Token missing or wrong. | Usually means stale/manual runner call; rerun deploy. |
| `Missing PHP extension: ...` | Remote PHP lacks required extension. | Enable extension in cPanel PHP settings. |
| `Missing required path: .../.env` | Remote `.env` missing. | Upload/create `.env` on host. |
| `Remote .env must contain a non-empty APP_KEY.` | `.env` lacks app key. | Set `APP_KEY` before deploy. |
| `Path is not writable: .../storage` | PHP cannot write storage. | Fix directory ownership/permissions. |
| Command log with `ok:false` | Artisan command failed. | Inspect sanitized output and fix app/config/database issue. |

---

## Recommended command workflows

### Local development deploy

No frontend:

```bash
php artisan ftp-deploy production
```

React/Vite with Bun:

```bash
bun install --frozen-lockfile
bun run build
php artisan ftp-deploy production
```

Or put build in `before_deploy` hook:

```php
'hooks' => [
    'before_deploy' => [
        'bun install --frozen-lockfile',
        'bun run build',
    ],
    'after_deploy' => [],
],
```

Then run:

```bash
php artisan ftp-deploy production
```

### CI deploy

Example GitHub Actions shape:

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
          extensions: ftp
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: composer install --no-interaction --prefer-dist
      - run: npm ci
      - run: npm run build
      - run: php artisan ftp-deploy production
        env:
          FTP_DEPLOYER_HOST: ${{ secrets.FTP_DEPLOYER_HOST }}
          FTP_DEPLOYER_USERNAME: ${{ secrets.FTP_DEPLOYER_USERNAME }}
          FTP_DEPLOYER_PASSWORD: ${{ secrets.FTP_DEPLOYER_PASSWORD }}
          FTP_DEPLOYER_APP_URL: https://laravelapp.inja.online
```

---

## Remote command examples

### Default Laravel deploy

```php
'remote_commands' => [
    'migrate --force',
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

### Safer first deploy without migrations

```php
'remote_commands' => [
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

### Custom app setup

```php
'remote_commands' => [
    'migrate --force',
    'db:seed --class=RequiredProductionSeeder --force',
    'app:setup:cache',
    'app:sync-permissions',
    'optimize:clear',
    'optimize',
],
```

See [Cookbook & Examples](/ftp-deployer/cookbook/) for Bun/React, no-frontend, queue, cache, CI, and cPanel layout recipes.

---

## Rollback in versioned mode

Versioned mode uses a managed public bootloader with the release id hardcoded. The deployer still writes `{current_path}` for compatibility, but the bootloader does not read it on each request.

To roll back manually, update `{public_root}/index.php` so the release line points to a previous release id:

```php
$release = rtrim('app/releases', '/').'/20260701112233';
```

A safer rollback is to redeploy the desired code revision, letting the deployer regenerate the bootloader and manifest.

> [!WARNING]
> This only rolls back PHP code routing through the bootloader. It does not roll back database migrations, queues, uploaded public assets, vendor archives, or external side effects.
