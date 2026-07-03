---
layout: ../layouts/DocsLayout.astro
title: Configuration
description: Complete reference for profiles, FTP settings, path mapping, hooks, remote commands, frontend builds, and exclusions
---

# Configuration

FTP Deployer reads configuration from `config/ftp-deployer.php`. The default config defines one `production` profile and uses environment variables for secrets and host-specific paths.

---

## Top-level shape

```php
return [
    'default' => env('FTP_DEPLOYER_PROFILE', 'production'),

    'profiles' => [
        'production' => [
            'ftp' => [/* FTP settings */],
            'paths' => [/* remote path model */],
            'exclusions' => [/* local paths excluded from release */],
            'hooks' => [/* local shell hooks */],
            'remote_commands' => [/* remote Artisan commands */],
            'archive' => [/* ZIP archive deploy mode */],
            'frontend' => [/* frontend build detection */],
        ],
    ],
];
```

The current Artisan command accepts the profile name directly:

```bash
php artisan ftp-deploy production
php artisan ftp-deploy staging
```

If omitted, the command default is `production`.

---

## FTP settings

```php
'ftp' => [
    'host' => env('FTP_DEPLOYER_HOST'),
    'username' => env('FTP_DEPLOYER_USERNAME'),
    'password' => env('FTP_DEPLOYER_PASSWORD'),
    'port' => (int) env('FTP_DEPLOYER_PORT', 21),
    'ssl' => (bool) env('FTP_DEPLOYER_SSL', false),
    'passive' => (bool) env('FTP_DEPLOYER_PASSIVE', true),
    'timeout' => (int) env('FTP_DEPLOYER_TIMEOUT', 90),
],
```

| Key | Required | Default | Description |
|---|---:|---|---|
| `host` | yes | `null` | FTP server host name. |
| `username` | yes | `null` | FTP username. |
| `password` | yes | `null` | FTP password. |
| `port` | no | `21` | FTP/FTPS port. |
| `ssl` | no | `false` | Uses `ftp_ssl_connect()` when true. |
| `passive` | no | `true` | Enables passive mode after login. |
| `timeout` | no | `90` | FTP connection timeout in seconds. |

> [!NOTE]
> This package uses PHP FTP functions. It does not use SSH or SFTP in the current implementation.

---

## Path settings

```php
'paths' => [
    'mode' => env('FTP_DEPLOYER_MODE', 'simple'),
    'ftp_root' => env('FTP_DEPLOYER_FTP_ROOT', '/'),
    'app_root' => env('FTP_DEPLOYER_APP_ROOT', 'app'),
    'public_root' => env('FTP_DEPLOYER_PUBLIC_ROOT', 'app/public'),
    'app_url' => env('FTP_DEPLOYER_APP_URL'),
    'filesystem_root' => env('FTP_DEPLOYER_FILESYSTEM_ROOT'),
    'release_root' => env('FTP_DEPLOYER_RELEASE_ROOT', '../app/releases'),
    'shared_root' => env('FTP_DEPLOYER_SHARED_ROOT', '../app/shared'),
    'current_path' => env('FTP_DEPLOYER_CURRENT_PATH', '../app/current'),
],
```

| Key | Required | Default | Description |
|---|---:|---|---|
| `mode` | no | `simple` | `simple` or `versioned`. |
| `ftp_root` | yes | `/` | FTP-login-relative base path prepended to remote FTP paths. |
| `app_root` | yes | `app` | Laravel root for simple mode. Contains `artisan`. |
| `public_root` | yes | `app/public` | Web-accessible public root. Runner is uploaded here. |
| `app_url` | yes | `null` | Public URL used to call the temporary runner. |
| `filesystem_root` | archive mode | `null` | Absolute PHP filesystem path matching `ftp_root`. Used by the runner to find uploaded ZIPs and extract them. |
| `release_root` | versioned | `../app/releases` | Directory containing versioned releases, relative to `ftp_root`. |
| `shared_root` | versioned | `../app/shared` | Directory containing shared `.env` and `storage`, relative to `ftp_root`. |
| `current_path` | versioned | `../app/current` | Compatibility pointer written after deploy, relative to `ftp_root`. Current bootloader hardcodes the release id and does not read this file per request. |

### Simple mode path mapping

Simple mode deploys directly over one live app tree.

Local path:

```text
routes/web.php
app/Http/Controllers/HomeController.php
public/build/assets/app.js
```

Remote path with default simple config:

```text
{ftp_root}/app/routes/web.php
{ftp_root}/app/app/Http/Controllers/HomeController.php
{ftp_root}/app/public/build/assets/app.js
```

`public/*` local files map into `public_root`; all other local files map into `app_root`.

Recommended remote layout:

```text
{ftp_root}/app/.env
{ftp_root}/app/storage/
{ftp_root}/app/vendor/
{ftp_root}/app/public/index.php
```

Use simple mode when you want the smallest remote layout and can accept live overwrite deploys.

### Versioned mode path mapping

Versioned mode deploys each app build to a new release directory and updates managed `public_root/index.php` to point at that release.

Local path:

```text
routes/web.php
app/Http/Controllers/HomeController.php
public/build/assets/app.js
```

Remote path with default versioned config and release id `20260702123456`:

```text
{ftp_root}/app/releases/20260702123456/routes/web.php
{ftp_root}/app/releases/20260702123456/app/Http/Controllers/HomeController.php
{ftp_root}/public_html/build/assets/app.js
```

Recommended remote layout:

```text
{ftp_root}/public_html/index.php                         managed bootloader, overwritten each deploy
{ftp_root}/app/releases/20260702123456/                  current app release
{ftp_root}/app/releases/20260702123456/vendor/           production vendor extracted from hash-named ZIP
{ftp_root}/app/shared/.env                               persistent env
{ftp_root}/app/shared/storage/                           persistent Laravel storage
{ftp_root}/app/current                                   compatibility pointer
```

In versioned mode, PHP app files go under the new release directory. Public assets stay in stable `public_root` because the web server serves static files directly. The bootloader hardcodes the active release id, so normal requests do not read `current_path`.

### Filesystem root for archive mode

Archive mode uploads ZIP files over FTP, then asks the public runner to extract them through PHP. FTP paths are not PHP filesystem paths, so configure the matching absolute filesystem root:

```ini
FTP_DEPLOYER_FTP_ROOT=/public_html/laravelapp.inja.online
FTP_DEPLOYER_FILESYSTEM_ROOT=/home/<cpanel-user>/public_html/laravelapp.inja.online
```

The deployer derives paths like:

```text
FTP upload:   {ftp_root}/.ftp-deployer/archives/app-{random}.zip
PHP reads:    {filesystem_root}/.ftp-deployer/archives/app-{random}.zip
PHP extracts: {filesystem_root}/{app_root}
```

---

## Archive mode

```php
'archive' => [
    'enabled' => (bool) env('FTP_DEPLOYER_ARCHIVE_ENABLED', true),
],
```

Archive mode is enabled by default.

Behavior:

- Reuses local production Composer vendor cache at `/tmp/ftp-deployer-vendor-{composer_json}-{composer_lock}`; runs `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts` only on cache miss.
- Builds and uploads an app ZIP every deploy.
- Names vendor ZIPs from Composer input hashes: `vendor-{composer_json}-{composer_lock}.zip`.
- Uploads vendor ZIP only when that hash archive does not already exist remotely.
- In simple mode, extracts vendor into `{app_root}/vendor` when Composer inputs change.
- In versioned mode, extracts the reused hash-named vendor ZIP into `{release_root}/{release_id}/vendor`.
- In versioned mode, does not move optimized vendor to shared storage because Composer optimized autoload contains release-relative app class paths.
- Excludes `vendor/`, deployer temp files, dev config (`package.json`, `phpunit.xml`, `phpstan.neon`, `pint.json`, `vite.config.*`), runtime junk, and `database/*.sqlite*` from the app ZIP.
- Uploads deny `.htaccess` files before ZIPs.
- Uses random app ZIP filenames under `.ftp-deployer/archives/`; vendor ZIP names are deterministic.
- Extracts ZIPs through the authenticated runner before remote commands.
- Deletes stale manifest-managed files after extraction and before saving the manifest.
- Deletes temporary app ZIPs after extraction on a best-effort basis. Vendor archives are retained for reuse.

Disable archive mode to use recursive FTP uploads:

```ini
FTP_DEPLOYER_ARCHIVE_ENABLED=false
```

Requirements:

- Local PHP `zip` extension.
- Remote PHP `zip` extension.
- Correct `FTP_DEPLOYER_FILESYSTEM_ROOT`.

---

## Exclusions

Default exclusions:

```php
'exclusions' => [
    '.git/',
    '.github/',
    '.idea/',
    '.vscode/',
    'node_modules/',
    'tests/',
    'storage/app/public/',
    'storage/framework/cache/',
    'storage/framework/sessions/',
    'storage/framework/testing/',
    'storage/framework/views/',
    'storage/logs/',
    'bootstrap/cache/*.php',
    '.env',
    '.env.*',
    '.ftp-deployer/',
],
```

The release builder copies your working tree to a temporary directory and skips matching paths.

Pattern behavior:

- Directory-style entries ending in `/` match everything under that directory.
- Exact paths match that file or directory.
- `fnmatch()` patterns are supported, e.g. `bootstrap/cache/*.php`.

Keep these excluded unless you know what you are doing:

- `.env` and `.env.*`: prevents local secrets from overwriting remote secrets.
- `storage/framework/*`: prevents local cache/session/view files from replacing production runtime state.
- `storage/logs/`: prevents local logs from replacing production logs.
- `storage/app/public/`: prevents accidental deletion or overwrite of user uploads.
- `.ftp-deployer/`: prevents deploy state from being copied from local source.

---

## Local hooks

```php
'hooks' => [
    'before_deploy' => [],
    'after_deploy' => [],
],
```

Hooks are local shell commands executed from the current working directory.

Example:

```php
'hooks' => [
    'before_deploy' => [
        'composer test',
        'npm run build',
    ],
    'after_deploy' => [
        'php artisan deploy:notify production',
    ],
],
```

Behavior:

- `before_deploy` runs before release build and FTP connection.
- If any `before_deploy` hook exits non-zero, deployment stops.
- `after_deploy` runs only after upload, runner execution, version activation, manifest save, and cleanup have succeeded.
- If an `after_deploy` hook fails, the command returns failure, but remote deploy work has already happened.

---

## Remote commands

```php
'remote_commands' => [
    'migrate --force',
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

Remote commands are Artisan commands executed by the temporary HTTP runner on the target host.

Supported entry shapes:

```php
'migrate --force'
```

Becomes:

```json
{"cmd":"migrate --force","fail_on_error":true}
```

Structured command:

```php
['command' => 'storage:link', 'ignore_failures' => true]
```

Becomes:

```json
{"cmd":"storage:link","fail_on_error":false}
```

Behavior:

- Required command fails (`fail_on_error = true`) → runner stops and returns failure.
- Tolerated command fails (`fail_on_error = false`) → runner logs failure and continues.
- Commands should be Artisan command names and flags, not arbitrary shell commands.

---

## Frontend configuration

```php
'frontend' => [
    'enabled' => true,
    'auto_detect' => true,
    'builds' => [],
    'warn_if_inputs_changed_without_output_change' => true,
],
```

The deployer does **not** run npm, yarn, pnpm, or bun. Build assets before deploy using local hooks or CI.

### Auto detection

When `builds` is empty and `auto_detect` is true:

1. If `package.json` is missing, frontend logic is skipped silently.
2. If `public/build/manifest.json` exists, Vite build `app` is detected with output `public/build/`.
3. If `public/build/.vite/manifest.json` exists, Vite 5+ build `app` is detected with output `public/build/`.
4. If `public/mix-manifest.json` exists, Laravel Mix build `app` is detected with outputs `public/css/` and `public/js/`.
5. If `package.json` exists but no manifest is found, the deployer warns and continues without frontend upload.

### Explicit builds

Use explicit builds for multiple frontends or custom output paths:

```php
'frontend' => [
    'enabled' => true,
    'auto_detect' => true,
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
```

When explicit builds are present, auto-detection does not add extra builds.

### Stale-build warning inputs

The deployer records hashes for:

- `package.json`
- `package-lock.json`
- `yarn.lock`
- `pnpm-lock.yaml`
- `bun.lock`
- `bun.lockb`
- `webpack.mix.js`
- `vite.config.js`
- `vite.config.ts`

If frontend inputs change but build manifest hashes do not, the deployer warns that build output may be stale.

### Common frontend choices

No frontend:

```php
'frontend' => [
    'enabled' => false,
    'auto_detect' => false,
    'builds' => [],
    'warn_if_inputs_changed_without_output_change' => false,
],
```

React/Vite with Bun in a local hook:

```php
'hooks' => [
    'before_deploy' => [
        'bun install --frozen-lockfile',
        'bun run build',
    ],
    'after_deploy' => [],
],
```

Custom remote Artisan setup after upload/extraction:

```php
'remote_commands' => [
    'migrate --force',
    'app:setup:cache',
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

More copy-paste cases: [Cookbook & Examples](/ftp-deployer/cookbook/).

## Migrating from Simple to Versioned Mode

If you are upgrading an existing profile from simple mode to versioned mode, you can automate the configuration setup locally using the interactive migration command:

```bash
php artisan ftp-deploy:migrate production --write
```

This command will:
1. Ask you for the three required versioned paths (release root, shared root, and current path pointer).
2. Validate the target structure (checking that `public_root` is not nested in `app_root` with reversed nesting).
3. Automatically append the required variables to your local `.env` file if you provide the `--write` flag and confirm.

### Server-Side Migration Actions

The migration command updates your local environment settings, but **does not modify files on your remote FTP server**. You must perform these remote moves before running the first versioned deploy:

1. **Create shared root**: Create `{ftp_root}/{shared_root}` if it does not exist.
2. **Move `.env` file**: Move your remote `.env` from `{ftp_root}/{app_root}/.env` to `{ftp_root}/{shared_root}/.env`.
3. **Move `storage` folder**: Move your remote `storage/` directory to `{ftp_root}/{shared_root}/storage/`.
4. **Check Permissions**: Ensure PHP can write to `{shared_root}/storage` and each new release's `bootstrap/cache`.
5. **Deploy**: Run `php artisan ftp-deploy production` to create `{release_root}/{release_id}`, upload the managed bootloader, reuse the hash-named vendor ZIP upload, and extract vendor into that release.
6. **Verify bootloader**: Confirm `{public_root}/index.php` contains the deployed release id and boots the new release.

Do not manually edit the managed bootloader after versioned deploys. The deployer overwrites it each time with the new hardcoded release id.

---

## Complete `.env` example

```ini
FTP_DEPLOYER_PROFILE=production

FTP_DEPLOYER_HOST=ftp.example.com
FTP_DEPLOYER_USERNAME=deploy@example.com
FTP_DEPLOYER_PASSWORD=change-me
FTP_DEPLOYER_PORT=21
FTP_DEPLOYER_SSL=false
FTP_DEPLOYER_PASSIVE=true
FTP_DEPLOYER_TIMEOUT=90

FTP_DEPLOYER_MODE=simple
FTP_DEPLOYER_FTP_ROOT=/
FTP_DEPLOYER_APP_ROOT=app
FTP_DEPLOYER_PUBLIC_ROOT=app/public
FTP_DEPLOYER_APP_URL=https://laravelapp.inja.online
FTP_DEPLOYER_FILESYSTEM_ROOT=/home/your-user/public_html/laravelapp.inja.online
FTP_DEPLOYER_ARCHIVE_ENABLED=true
```

Versioned additions:

```ini
FTP_DEPLOYER_MODE=versioned
FTP_DEPLOYER_PUBLIC_ROOT=public_html
FTP_DEPLOYER_RELEASE_ROOT=../app/releases
FTP_DEPLOYER_SHARED_ROOT=../app/shared
FTP_DEPLOYER_CURRENT_PATH=../app/current
```
