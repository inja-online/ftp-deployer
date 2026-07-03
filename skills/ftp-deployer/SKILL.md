---
name: ftp-deployer
description: Safely deploy Laravel apps to FTP-only cPanel/shared hosts with this repository's ftp-deploy command. Use when an agent needs to run, automate, diagnose, or document FTP Deployer deployments.
---

# FTP Deployer

Use this skill when working with Laravel FTP Deployer in this repository.

## Documentation map

Read these before changing deploy behavior:

- `docs/src/pages/installation.md` — install and first deploy checklist.
- `docs/src/pages/configuration.md` — profile keys, paths, hooks, frontend, archive mode.
- `docs/src/pages/cookbook.md` — copy-paste recipes for Bun/React, no frontend, custom commands, queues, caches, CI, cPanel layouts.
- `docs/src/pages/extending.md` — how to extend/override safely with config, hooks, wrapper commands, or forks.
- `docs/src/pages/commands.md` — command signatures and output.
- `docs/src/pages/troubleshooting.md` — failure diagnosis.

## Preferred command for agents

### Deployment
Prefer structured output:

```bash
php artisan ftp-deploy production --format=agent
```

Use human output only when a person is reading the terminal directly:

```bash
php artisan ftp-deploy production
```

### Migration
To migrate a profile from simple to versioned mode, run:

```bash
php artisan ftp-deploy:migrate production --write --format=agent
```

Or for planning/dry-run without writing:

```bash
php artisan ftp-deploy:migrate production --format=agent
```

## Agent JSON output

### Deployment Success Shape:

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

### Deployment Failure Shape:

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

### Migration Success Shape:

```json
{
  "status": "success",
  "profile": "production",
  "logs": [
    {"level": "info", "source": "migration", "message": "Migration plan generated successfully."},
    {"level": "info", "source": "migration", "message": "Local configuration successfully updated."}
  ],
  "migration": {
    "profile": "production",
    "old_mode": "simple",
    "new_mode": "versioned",
    "paths": {
      "release_root": "../app/releases",
      "shared_root": "../app/shared",
      "current_path": "../app/current"
    },
    "written": true
  }
}
```

## Common recipes

### No frontend

If no `package.json` exists, frontend detection is skipped automatically. To be explicit:

```php
'frontend' => [
    'enabled' => false,
    'auto_detect' => false,
    'builds' => [],
    'warn_if_inputs_changed_without_output_change' => false,
],
```

### React/Vite with Bun

Build locally/CI before deploy, or put it in `before_deploy`:

```php
'hooks' => [
    'before_deploy' => [
        'bun install --frozen-lockfile',
        'bun run build',
    ],
    'after_deploy' => [],
],
```

### Custom remote setup command

Use `remote_commands` for Artisan commands on the host after upload/extraction:

```php
'remote_commands' => [
    'migrate --force',
    'app:setup:cache',
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

Remote commands are Artisan commands, not shell commands.

## Extension guidance

Use first extension point that works:

1. Config: profiles, paths, exclusions, frontend builds, remote commands.
2. Local hooks: shell commands before/after deploy on local machine or CI.
3. Remote commands: Artisan commands on target host.
4. Wrapper Artisan command: app-specific prechecks around `ftp-deploy`.
5. Fork/PR: package internals like upload protocol, runner, manifest, activation.

Do not edit files under `vendor/`. Publish config and customize app config instead.

The package has no formal plugin API for replacing `ReleaseBuilder`, `FTPClient`, `Runner`, or `Manifest` from config. The built-in `ftp-deploy` command constructs `new FTPDeployer($profile)` directly, so rebinding the `ftp-deployer` facade does not replace the built-in command pipeline.

## Composer/vendor cache

During release build, `composer.json` and `composer.lock` are hashed before production vendor preparation.

- Cache path: `/tmp/ftp-deployer-vendor-{composer_json}-{composer_lock}`.
- Cache hit: copy cached `vendor/` into temp release; skip Composer.
- Cache miss: run `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts`, then save `vendor/` to that cache.
- Remote vendor archive still uses the same Composer hashes: `vendor-{composer_json}-{composer_lock}.zip`.

Do not assume every deploy runs Composer. Check logs for `Composer vendor cache hit` or `Composer vendor cache miss`.

## Deployment modes

### Simple mode

Use for small/shared-host apps where overwriting live files is acceptable.

- App files deploy to `paths.app_root`.
- Public files deploy to `paths.public_root`.
- Archive mode extracts `app.zip` over the live app directory.
- `vendor.zip` extracts to `{app_root}/vendor` when Composer inputs change.
- Simpler layout, but failed extraction can leave mixed old/new files.

### Versioned mode

Use for safer deploys and faster Composer/vendor reuse.

- Each deploy creates a new release under `{release_root}/{release_id}`.
- Public `index.php` is a managed bootloader with the release id hardcoded; it does **not** read `current_path` on every request.
- `.env` and `storage/` live under `shared_root`.
- Vendor archives are named from `composer.json` + `composer.lock` hashes: `vendor-{composer_json}-{composer_lock}.zip`.
- Vendor ZIP upload is reused when hashes match, but the ZIP is extracted into each release's `vendor/`.
- Do not move optimized vendor to shared storage: Composer optimized autoload stores app class paths relative to the release.
- `current_path` is still written for compatibility, but the bootloader does not depend on it at runtime.

## Safe runbook

### Deployment
1. Confirm local/CI build is complete before deploy (`bun run build`, `npm run build`, Composer install as needed).
2. Confirm profile exists in `config/ftp-deployer.php`.
3. Confirm secrets come from environment variables, not committed files.
4. Run `php artisan ftp-deploy <profile> --format=agent`.
5. Parse stdout as JSON and trust exit code: `0` success, non-zero failure.
6. Inspect `logs` and `runner.logs` for warnings or remote Artisan failures.

### Migration
1. Confirm profile exists in config.
2. Run `php artisan ftp-deploy:migrate <profile> --write --format=agent`.
3. Parse stdout as JSON and verify `status` is `success`.
4. **Automated remote moves**: Do not force the user to perform manual moves. Instead, write and execute a temporary local PHP script to connect to the FTP host and rename/move the remote files:
   - Create a scratch script `scratch/ftp-move.php` that bootstraps Laravel, retrieves the profile's FTP credentials, and uses the `ftp_rename()` command.
   - Example automation snippet:
     ```php
     <?php
     $app = require_once __DIR__ . '/bootstrap/app.php';
     $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
     $profile = config('ftp-deployer.profiles.production');
     $ftp = \inja-online\FTPDeployer\Support\FTPClient::connect($profile['ftp']);
     $ref = new ReflectionClass($ftp);
     $prop = $ref->getProperty('connection');
     $prop->setAccessible(true);
     $conn = $prop->getValue($ftp); // Get underlying FTP connection

     $ftpRoot = rtrim($profile['paths']['ftp_root'] ?? '', '/');
     $sharedRoot = rtrim($profile['paths']['shared_root'], '/');
     $appRoot = rtrim($profile['paths']['app_root'], '/');

     // Create shared root directory
     $ftp->mkdir($sharedRoot);

     // Move remote .env and storage/
     ftp_rename($conn, "{$ftpRoot}/{$appRoot}/.env", "{$ftpRoot}/{$sharedRoot}/.env");
     ftp_rename($conn, "{$ftpRoot}/{$appRoot}/storage", "{$ftpRoot}/{$sharedRoot}/storage");
     ```
   - Execute the script using `php scratch/ftp-move.php` and then delete the file.
5. Rerun deployment: `php artisan ftp-deploy <profile> --format=agent` to upload the hardcoded-release bootloader, extract the app archive into a fresh release, and extract the reused hash-named vendor ZIP into that release's `vendor/`.

## Troubleshooting

- `Deploy profile [...] is missing.`: add profile or use existing profile.
- `Missing FTP setting`: set FTP host, username, or password environment variable.
- `Missing path setting`: configure required paths and `FTP_DEPLOYER_APP_URL`.
- `Runner failed`: inspect `runner.logs`; remote Laravel command failed or remote host prerequisites are missing.
- Frontend warning: build output manifest not detected; run `bun run build`, `npm run build`, or disable frontend for backend-only apps.
- Custom command failure: inspect `runner.logs`; ensure command exists in deployed app and is safe for production.
- Migration path error: verify `app_root` is not reversed or nested inside `public_root`.

Never print secrets from `.env`, FTP credentials, runner token, or host control panel credentials in logs or issue comments.
