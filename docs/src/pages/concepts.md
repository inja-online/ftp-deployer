---
layout: ../layouts/DocsLayout.astro
title: Core Concepts & Architecture
description: Detailed architecture of release building, manifests, FTP sync, versioned deploys, frontend assets, and the HTTP runner
---

# Core Concepts & Architecture

This page explains how the deployer works internally from command start to cleanup.

---

## Execution pipeline

<div class="mermaid">
  <img src="/ftp-deployer/images/concepts-1.svg" alt="concepts diagram 1" />
</div>

---

## ReleaseBuilder

`ReleaseBuilder` creates a deployable release directory under your system temp directory:

```text
/tmp/ftp-deployer-{random}/
```

It performs three steps:

1. Copy the source working tree into the temp release.
2. Run production Composer install inside the temp release.
3. Remove runtime/cache junk before manifest generation.

### Why a temp release exists

Deploying directly from your source tree is risky:

- `vendor/` may include dev dependencies.
- The package itself may be installed as a dev dependency.
- Local caches may contain paths from your machine.
- Local `.env` may contain secrets for a different environment.
- Tests and local tooling should not be uploaded.

The temp release lets the deployer prepare production `vendor/` without modifying your working tree.

### Composer strategy

If `composer.json` exists, the deployer hashes `composer.json` and `composer.lock` and checks a local production vendor cache:

```text
/tmp/ftp-deployer-vendor-{composer_json}-{composer_lock}/vendor
```

Cache hit:

```text
Composer vendor cache hit: /tmp/ftp-deployer-vendor-{hashes}
```

The cached `vendor/` is copied into the temp release and Composer is not run.

Cache miss:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts
```

After a successful install, the generated production `vendor/` is copied into the local cache for future deploys with the same Composer hashes.

This means:

- Dependencies are installed locally/CI, not on the shared host.
- Composer only runs when Composer input hashes change or the local cache is missing.
- Dev dependencies are not shipped.
- Optimized autoload files are shipped.

### Runtime cleanup

Before manifest generation, these paths are emptied but their root folders are kept:

```text
storage/framework/cache
storage/framework/sessions
storage/framework/testing
storage/framework/views
storage/logs
bootstrap/cache
```

This prevents stale local runtime files from being uploaded.

---

## Exclusion matching

Exclusions are applied while copying the source tree into the temp release.

Supported behavior:

- `path/` matches everything under `path/`.
- `path` matches exact path or anything under that path.
- `*.php`-style globs use PHP `fnmatch()`.
- Backslashes are normalized to forward slashes.

Examples:

| Pattern | Matches | Does not match |
|---|---|---|
| `node_modules/` | `node_modules/vite/bin/vite.js` | `app/node_modules_helper.php` |
| `.env` | `.env` | `.env.production` |
| `.env.*` | `.env.production` | `.env` |
| `bootstrap/cache/*.php` | `bootstrap/cache/config.php` | `bootstrap/cache/nested/config.php` |

---

## PathMapper

`PathMapper` converts local release-relative paths into FTP target paths.

It uses:

- `ftp_root`
- `mode`
- `app_root`
- `public_root`
- `release_root`
- generated `release_id`

### Simple mode

Simple mode maps one deploy to one live app directory.

Rule:

```text
public/*  -> {ftp_root}/{public_root}/*
other     -> {ftp_root}/{app_root}/*
```

Example:

```text
public/index.php -> app/public/index.php
routes/web.php   -> app/routes/web.php
```

With `ftp_root = deployments/site-a`:

```text
public/index.php -> deployments/site-a/app/public/index.php
routes/web.php   -> deployments/site-a/app/routes/web.php
```

Runtime model:

```text
{app_root}/.env
{app_root}/storage/
{app_root}/vendor/
{public_root}/index.php
```

Simple mode is easiest to understand, but app ZIP extraction overwrites live files. If extraction fails midway, the live app can contain a mix of old and new files.

### Versioned mode

Versioned mode maps each deploy to a fresh release directory.

Rule:

```text
public/*  -> {ftp_root}/{public_root}/*
other     -> {ftp_root}/{release_root}/{release_id}/*
```

Example release id: `20260702123456`

```text
public/build/app.js -> public_html/build/app.js
routes/web.php      -> app/releases/20260702123456/routes/web.php
```

Runtime model:

```text
{public_root}/index.php               managed bootloader, overwritten each deploy
{release_root}/{release_id}/          immutable app release
{release_root}/{release_id}/vendor/  production vendor extracted from hash-named ZIP
{shared_root}/.env                    persistent environment file
{shared_root}/storage/                persistent Laravel storage
{current_path}                        compatibility pointer, not used by bootloader per request
```

The public bootloader hardcodes the active release id when deployed:

```php
$release = rtrim('app/releases', '/').'/20260702123456';
$shared = rtrim('app/shared', '/');
```

It does not read `current_path` on every request. This avoids one filesystem read per web request while keeping the active release explicit in `index.php`.

---

## Remote manifest

The remote manifest lives at:

```text
{ftp_root}/.ftp-deployer/manifest.json
```

It records deploy state so future deploys can upload only what changed.

### Manifest shape

```json
{
  "schema_version": 1,
  "deployed_at": "2026-07-02T12:34:56+00:00",
  "deployed_from": {
    "type": "git",
    "ref": "main",
    "sha": "abc123"
  },
  "paths": {},
  "inputs": {
    "composer": {
      "composer_json": "md5",
      "composer_lock": "md5"
    },
    "frontend": {
      "package_json": "md5",
      "lock_files": {},
      "config_files": {}
    }
  },
  "groups": {
    "app": {
      "strategy": "file-diff",
      "count": 120,
      "hash": "aggregate-md5"
    },
    "vendor": {
      "strategy": "composer-inputs",
      "paths": ["vendor/"]
    },
    "frontend": {
      "strategy": "build-manifest",
      "builds": {
        "app": {
          "type": "vite",
          "manifest_file": "public/build/manifest.json",
          "manifest_hash": "md5",
          "output_paths": ["public/build/"],
          "assets": {
            "public/build/assets/app.js": "app/public/build/assets/app.js"
          }
        }
      }
    }
  },
  "files": {
    "routes/web.php": {
      "remote_path": "app/routes/web.php",
      "hash": "md5",
      "size": 512,
      "mtime": 1751459696
    }
  }
}
```

### Normal app files

`files` contains normal app files only. It does not include:

- `vendor/*`
- frontend build output paths
- excluded paths
- the manifest itself

Diff rules:

- Upload when file is new.
- Upload when file hash changed.
- Upload when target `remote_path` changed, which is critical for versioned releases.
- Skip when hash and remote path match.
- Delete remote managed files that existed in previous manifest but no longer exist locally.

### Vendor group

`vendor/` is not diffed file-by-file. Instead:

- Hash `composer.json`.
- Hash `composer.lock`.
- Build deterministic archive name: `vendor-{composer_json}-{composer_lock}.zip`.
- If hashes match an existing remote archive, reuse it instead of uploading vendor again.
- In simple archive mode, extract vendor to `{app_root}/vendor` when Composer inputs changed.
- In versioned archive mode, reuse the remote hash-named vendor ZIP upload, but extract it into each release's `vendor/`.
- In recursive FTP mode, upload `vendor/` as a directory when Composer inputs changed, or always in versioned mode.

This avoids scanning thousands of vendor files into the manifest and avoids re-uploading/re-extracting vendor when Composer inputs have not changed.

### Frontend group

Frontend build output is tracked by build manifest hash and asset list.

- If build manifest hash changed, upload configured output directories.
- Previously managed frontend assets that disappeared locally are deleted only if they are inside configured output paths.
- Files outside configured frontend outputs are never deleted as frontend cleanup.

---

## Archive upload behavior

Archive mode is the default upload strategy. It reduces FTP round-trips by uploading ZIP artifacts instead of thousands of individual files.

```text
temp release
├── app-{random}.zip                              every deploy, excludes vendor/dev/runtime junk/SQLite DBs
└── vendor-{composer_json}-{composer_lock}.zip    deterministic vendor archive, reused when hashes match

FTP upload
└── {ftp_root}/.ftp-deployer/archives/{archive}.zip

HTTP runner
├── extract app archive using {filesystem_root}
├── extract vendor archive into app root or release vendor
├── delete stale manifest-managed files
├── save manifest
└── run configured commands
```

Why `filesystem_root` exists:

```text
FTP path: /public_html/laravelapp.inja.online/.ftp-deployer/archives/app.zip
PHP path: /home/user/public_html/laravelapp.inja.online/.ftp-deployer/archives/app.zip
```

The runner runs in PHP and needs the PHP filesystem path, not the FTP path.

Failure semantics:

- Extraction fails → deploy fails, manifest is not saved, stale deletes are not run.
- Extraction succeeds → stale remote files from the previous manifest are deleted.
- Manifest saves before remote commands.
- Command failure after extraction leaves the manifest representing extracted remote files.

Simple mode extracts over live files, so partial extraction can leave mixed files if the host fails mid-extract. Versioned mode is safer because extraction targets a new release directory and only the managed public bootloader points traffic at the new release after upload/extraction succeeds.

### Archive behavior by mode

| Behavior | Simple mode | Versioned mode |
|---|---|---|
| App archive destination | `{app_root}` | `{release_root}/{release_id}` |
| Public files | `{public_root}` | `{public_root}` with managed bootloader |
| Vendor archive name | `vendor-{composer_json}-{composer_lock}.zip` | same |
| Vendor extract destination | `{app_root}/vendor` | `{release_root}/{release_id}/vendor` |
| Vendor reuse | skip upload if archive exists and hashes match | skip upload if archive exists; still extract into fresh release |
| Release `vendor` path | real directory | real directory |
| `.env` | `{app_root}/.env` | `{shared_root}/.env` |
| Storage | `{app_root}/storage` | `{shared_root}/storage` |
| Request routing | normal `public/index.php` | managed `public/index.php` hardcodes release id |

---

## FTP upload behavior

Recursive FTP mode is used when archive mode is disabled. FTP operations are intentionally small:

- Connect using `ftp_connect()` or `ftp_ssl_connect()`.
- Login.
- Enable passive mode when configured.
- Recursively create parent directories before uploading.
- Upload to temporary remote name.
- Delete old final path.
- Rename temp file to final path.
- Throw if upload or rename fails.

Temporary upload names look like:

```text
remote/path/file.php.tmp-a1b2c3d4
```

This reduces the chance of leaving a half-written final file.

---

## Frontend detection

The deployer does not run build tools. It only detects and uploads existing build output.

### Auto-detection order

1. `frontend.enabled = false` → skip.
2. No `package.json` → skip silently.
3. `public/build/manifest.json` → Vite.
4. `public/build/.vite/manifest.json` → Vite 5+.
5. `public/mix-manifest.json` → Laravel Mix.
6. `package.json` exists but no manifest → warn and continue.

### Explicit builds

Explicit builds are exact. If `frontend.builds` contains entries, auto-detection does not add more.

Use this for:

- admin bundles
- Filament panels
- custom Vite output dirs
- multiple frontend applications

---

## HTTP runner

The runner is generated from `stubs/install.php.stub` for each deploy.

Generated values:

- random filename: `install-{16 hex chars}.php`
- random token: 32 bytes encoded as 64 hex chars
- app/root mode placeholders

The runner is uploaded under `public_root`, then called at:

```text
{app_url}/{runner_filename}
```

### Request payload

Command phase payload:

```json
{
  "token": "random-deploy-token",
  "commands": [
    {"cmd": "migrate --force", "fail_on_error": true},
    {"cmd": "storage:link", "fail_on_error": false}
  ]
}
```

Archive extraction phase payload:

```json
{
  "token": "random-deploy-token",
  "extract": [
    {"archive": "/home/user/public_html/site/.ftp-deployer/archives/app-random.zip", "destination": "/home/user/public_html/site/app/releases/20260702123456"},
    {"archive": "/home/user/public_html/site/.ftp-deployer/archives/vendor-a-b.zip", "destination": "/home/user/public_html/site/app/releases/20260702123456/vendor", "skip_if_exists": "/home/user/public_html/site/app/releases/20260702123456/vendor/autoload.php"}
  ]
}
```

`skip_if_exists` prevents duplicate extraction into the same destination if a runner call is retried. Versioned releases still get their own `vendor/` directory because optimized Composer autoload paths are release-relative.

No `.env` values or secrets are sent.

### Runner checks

Before commands run, the runner checks:

- token matches
- required PHP extensions loaded
- `artisan` exists
- `vendor/autoload.php` exists
- `bootstrap/app.php` exists
- `.env` exists
- `.env` contains non-empty `APP_KEY`
- storage path is writable
- `bootstrap/cache` is writable

If any check fails, it returns JSON failure and runs no commands.

### Cache invalidation

The runner deletes:

```text
bootstrap/cache/*.php
```

before booting Laravel or running fallback commands. This prevents stale cached config/routes/events from breaking the new release.

### Command execution modes

Simple mode:

- If `exec()` exists, run commands through shell:

```bash
cd APP_ROOT && php artisan {command} --no-interaction 2>&1
```

Fallback / versioned mode:

- Boot Laravel in the web process.
- Run `Artisan::call()`.
- Inject shared `.env` and storage paths for versioned mode.

### JSON response

Success:

```json
{
  "ok": true,
  "logs": [
    {"command": "migrate --force", "ok": true, "output": "..."}
  ]
}
```

Failure:

```json
{
  "ok": false,
  "logs": [
    {"command": "migrate --force", "ok": false, "output": "..."}
  ]
}
```

Outputs are sanitized for common secret patterns like `APP_KEY`, `DB_PASSWORD`, `PASSWORD`, `SECRET`, and `TOKEN`.

---

## Versioned bootloader

Versioned mode manages public `index.php` from `stubs/versioned-index.php.stub`.

The bootloader:

1. Hardcodes active release path: `{release_root}/{release_id}`.
2. Sets `APP_ENV_FILE` to `{shared_root}/.env`.
3. Sets `LARAVEL_STORAGE_PATH` to `{shared_root}/storage`.
4. Requires active release `bootstrap/app.php`.
5. Calls `useStoragePath()` when available.
6. Handles the HTTP request with Laravel HTTP kernel.

The bootloader is uploaded on every versioned deploy because the release id changes. It does not read `current_path` on each request.

---

## Activation and manifest save order

The deployer saves the new manifest only after:

1. Files upload successfully.
2. Removed managed files are deleted.
3. Runner uploads successfully.
4. Runner returns successful JSON.
5. Versioned compatibility current pointer is updated, if enabled.
6. New manifest is saved.
7. Runner deletion is attempted in final cleanup, best effort.

If upload or runner execution fails, the new manifest is not saved. This keeps remote state from claiming a failed deploy succeeded. Runner cleanup is still attempted after failure.

---

## Failure model

| Failure | Result |
|---|---|
| Missing profile | Command fails before FTP connection. |
| Missing FTP credential | Command fails before FTP connection. |
| Composer install fails locally | Command fails before FTP connection. |
| FTP upload fails | Command fails, runner may never upload. |
| FTP rename fails | Command fails, temp file deletion is attempted. |
| Runner upload succeeds but HTTP call fails | Runner deletion is attempted in cleanup. |
| Runner returns failed requirement check | Command fails, manifest is not saved. |
| Required remote command fails | Runner stops, command fails, manifest is not saved. |
| Ignored remote command fails | Runner logs failure and continues. |
| Version activation fails | Command fails, manifest is not saved. |
| Local cleanup fails | Could leave temp release locally; remote state may already be deployed. |

---

## Security model

Security relies on multiple layers:

- Runner filename has random entropy.
- Runner token is random and required for every request.
- Runner accepts JSON payload only and does not use query-string secrets.
- Runner sends no `.env` secrets.
- Runner validates `.env` exists instead of writing it.
- Runner output is sanitized.
- Runner is deleted over FTP after success or failure.
- Deployment state excludes `.env` and runtime paths by default.

> [!WARNING]
> Use HTTPS for `app_url`. The token travels in the HTTP request body. Plain HTTP exposes it to network observers.
