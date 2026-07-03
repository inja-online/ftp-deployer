---
layout: ../layouts/DocsLayout.astro
title: Troubleshooting
description: Diagnose common FTP, runner, Laravel, manifest, frontend, and versioned deployment failures
---

# Troubleshooting

Use this page when `php artisan ftp-deploy` fails or deploy succeeds but the remote app does not behave as expected.

---

## Fast diagnosis flow

<div class="mermaid">
  <img src="/ftp-deployer/images/troubleshooting-1.svg" alt="troubleshooting diagram 1" />
</div>

---

## Profile and configuration errors

### `Deploy profile [x] is missing.`

Cause: command argument does not match a key under `profiles`.

Fix:

```php
'profiles' => [
    'production' => [...],
    'staging' => [...],
],
```

Run:

```bash
php artisan ftp-deploy staging
```

### `Missing FTP setting: ftp.host`

Cause: required FTP config value is empty.

Fix local `.env`:

```ini
FTP_DEPLOYER_HOST=ftp.example.com
FTP_DEPLOYER_USERNAME=deploy@example.com
FTP_DEPLOYER_PASSWORD=secret
```

### `Missing path setting: paths.app_url`

Cause: `FTP_DEPLOYER_APP_URL` is missing.

Fix:

```ini
FTP_DEPLOYER_APP_URL=https://laravelapp.inja.online
```

The runner URL is built as:

```text
{app_url}/{runner_filename}
```

### `Archive deployment requires FTP_DEPLOYER_FILESYSTEM_ROOT / paths.filesystem_root.`

Cause: archive mode is enabled, but the PHP filesystem root matching `FTP_DEPLOYER_FTP_ROOT` is not configured.

Fix:

```ini
FTP_DEPLOYER_FTP_ROOT=/public_html/laravelapp.inja.online
FTP_DEPLOYER_FILESYSTEM_ROOT=/home/<cpanel-user>/public_html/laravelapp.inja.online
```

If you cannot determine the absolute filesystem path, disable archive mode:

```ini
FTP_DEPLOYER_ARCHIVE_ENABLED=false
```

---

## Local build errors

### Composer production install fails

Error shape:

```text
Composer production install failed: ...
```

Cause: `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction` failed inside the temp release.

Fix:

```bash
composer validate
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
```

Common causes:

- Missing PHP extension locally.
- Bad `composer.lock`.
- Private package auth missing in CI.
- Package installed only in `require-dev` but needed at runtime.

### Before-deploy hook fails

Error:

```text
Local hook failed: npm run build
```

Fix: run the command manually from your Laravel app root.

```bash
npm run build
```

Hooks run from the current working directory.

### Missing local ZIP support

Error:

```text
Missing PHP zip support: ZipArchive extension is required for archive deployment.
```

Fix: install/enable local PHP `zip` extension, or disable archive mode:

```ini
FTP_DEPLOYER_ARCHIVE_ENABLED=false
```

---

## FTP errors

### `FTP login failed.`

Causes:

- Wrong host.
- Wrong username/password.
- Wrong port.
- Host requires FTPS but `ssl=false`.
- Host blocks your IP.

Fix:

```ini
FTP_DEPLOYER_SSL=true
FTP_DEPLOYER_PORT=21
FTP_DEPLOYER_PASSIVE=true
```

If passive mode fails, try:

```ini
FTP_DEPLOYER_PASSIVE=false
```

### `FTP upload failed: path/to/file`

Causes:

- Parent directory not writable.
- Disk quota reached.
- Path outside FTP account root.
- `ftp_root`, `app_root`, or `public_root` wrong.

Fix:

1. Confirm FTP user can create files in target directory.
2. Confirm `FTP_DEPLOYER_FTP_ROOT` is relative to FTP login root.
3. Check cPanel disk usage.

### `FTP rename failed: path/to/file`

Cause: server accepted temp upload but rejected final rename.

Fix:

- Confirm target directory allows rename/delete.
- Check if file is locked by host security tooling.
- Check permissions on existing final file.

### Archive ZIP remains under `.ftp-deployer/archives/`

Cause: deploy process was killed or cleanup failed after upload.

Fix: delete old ZIP files manually from:

```text
{ftp_root}/.ftp-deployer/archives/
```

Keep `.htaccess` files in `.ftp-deployer/` and `.ftp-deployer/archives/`.

---

## Runner errors

### `Runner request failed: https://laravelapp.inja.online/install-xxxx.php`

Causes:

- `app_url` points to wrong domain/path.
- DNS not pointing to host.
- HTTPS certificate invalid.
- Public root path wrong, so runner uploaded somewhere web cannot access.
- Host blocks direct PHP file access.

Fix:

- Confirm `FTP_DEPLOYER_PUBLIC_ROOT` maps to the web document root.
- Confirm `FTP_DEPLOYER_APP_URL` opens that document root.
- In simple mode, runner uploads to `{ftp_root}/{public_root}`.
- In versioned mode, runner also uploads to stable `{ftp_root}/{public_root}`.

### `Runner returned invalid JSON.`

Causes:

- PHP fatal error before JSON response.
- Host injected HTML error page.
- Wrong URL returned website HTML instead of runner.
- `display_errors` output broke JSON.

Fix:

- Visit the runner URL only during a deploy window if needed; it should return JSON unauthorized without token.
- Check server error logs in cPanel.
- Verify remote PHP version and extensions.

### `Unauthorized`

Cause: request token did not match generated runner token.

This usually only happens if:

- Someone manually called the runner URL.
- A stale runner from an older deploy remains.
- HTTP request body was modified by proxy/security tooling.

Fix:

- Delete stale `install-*.php` files from public root.
- Rerun deploy.

### `Runner failed: ... Missing PHP extension: zip`

Cause: remote PHP used by the web runner lacks `ZipArchive`.

Fix options:

1. Enable PHP `zip` extension for the domain in cPanel/MultiPHP.
2. Disable archive mode:

```ini
FTP_DEPLOYER_ARCHIVE_ENABLED=false
```

### `Runner failed: ... Unable to open archive`

Cause: `FTP_DEPLOYER_FILESYSTEM_ROOT` does not match `FTP_DEPLOYER_FTP_ROOT`, or uploaded archive path is not readable by PHP.

Fix: verify mapping:

```text
FTP_DEPLOYER_FTP_ROOT=/public_html/laravelapp.inja.online
FTP_DEPLOYER_FILESYSTEM_ROOT=/home/<cpanel-user>/public_html/laravelapp.inja.online
```

The runner must be able to read:

```text
{filesystem_root}/.ftp-deployer/archives/{random}.zip
```

### `Runner failed: ... Unsafe archive entry`

Cause: ZIP contains an absolute path, drive prefix, UNC path, or `..` traversal entry.

Fix: rebuild locally from a clean release. If this repeats, inspect generated archive contents and report it as a bug.

### `.env` missing

Error:

```text
Missing required path: .../.env
```

Fix:

Simple mode:

```text
{ftp_root}/{app_root}/.env
```

Versioned mode:

```text
{ftp_root}/{shared_root}/.env
```

### Missing `APP_KEY`

Error:

```text
Remote .env must contain a non-empty APP_KEY.
```

Fix remote `.env`:

```ini
APP_KEY=base64:...
```

Do not generate a new key on an existing production app unless you intentionally want to rotate encryption keys.

### Writable path failure

Error:

```text
Path is not writable: .../storage
Path is not writable: .../bootstrap/cache
```

Example:

```text
Path is not writable: /home/tinabwoo/public_html/laravelapp.inja.online/app/bootstrap/cache
```

Fix permissions or ownership on the server:

```bash
chmod -R 775 /home/tinabwoo/public_html/laravelapp.inja.online/app/bootstrap/cache
```

If you do not have SSH, use cPanel File Manager and open:

```text
app/bootstrap/cache
```

Set the directory writable by the PHP user. `775` is often enough. If shared hosting blocks writes because the owner is wrong, ask the host to fix ownership or use cPanel “Fix Permissions”. Then rerun `php artisan ftp-deploy`.

---

## Laravel command failures

Runner logs include the failing command:

```json
{"command":"migrate --force","ok":false,"output":"..."}
```

Common fixes:

| Command | Common issue | Fix |
|---|---|---|
| `migrate --force` | DB credentials wrong | Fix remote `.env` database values. |
| `migrate --force` | DB user lacks permission | Grant migration permissions or run migration another way. |
| `optimize` | Config references missing class | Clear caches, fix config, redeploy. |
| `storage:link` | Symlink disabled | Mark command `ignore_failures=true` or create link manually. |

---

## Frontend issues

### Warning: no build manifest detected

Message:

```text
Warning: package.json found but no build manifest detected — did you run your build step?
```

Cause: `package.json` exists, but no Vite or Mix manifest was found.

Fix:

```bash
npm run build
php artisan ftp-deploy production
```

Or configure explicit frontend build paths.

### Stale assets after deploy

Causes:

- Build command did not run.
- Build manifest hash did not change.
- Explicit `outputs` missing actual asset directory.
- Public root does not match document root.

Fix:

1. Run frontend build locally/CI.
2. Confirm manifest file exists.
3. Confirm output path contains built files.
4. Confirm `public_root` points to web document root.

---

## Versioned mode issues

### Site uses old release after versioned deploy

Cause: managed bootloader was not uploaded or still hardcodes an old release id.

Fix:

Check `{ftp_root}/{public_root}/index.php` and confirm it contains the new release id:

```php
$release = rtrim('app/releases', '/').'/20260702123456';
```

Also confirm release exists:

```text
{ftp_root}/{release_root}/20260702123456/bootstrap/app.php
```

`{current_path}` is written for compatibility, but current bootloader does not read it per request.

### Static assets 404 in versioned mode

Cause: static assets are served from stable `public_root`, not release `public/` through Laravel.

Fix:

- Keep frontend outputs under local `public/...`.
- Ensure `public_root` is correct.
- Ensure frontend build manifest changed so output directory uploads.

### Rollback did not undo database change

Expected. Versioned mode bootloader changes active PHP code only. It does not roll back database migrations, shared storage, public assets, vendor cache, or external side effects.

---

## Manifest issues

### Remote manifest missing

First deploy has no remote manifest. That is normal. The deployer treats it as first deploy and uploads everything needed.

### Remote manifest invalid

If JSON is invalid or schema is unsupported, the deployer ignores it as missing and treats deploy as first deploy.

Fix manually if needed:

```text
{ftp_root}/.ftp-deployer/manifest.json
```

You can delete it to force a full managed upload on next deploy.

---

## Cleanup stale runners

Runner cleanup is best-effort. If a process is killed hard, a runner file may remain.

Delete files matching:

```text
{ftp_root}/{public_root}/install-*.php
```

They still require a token, but stale public deploy scripts should not remain online.
