---
layout: ../layouts/DocsLayout.astro
title: Installation
description: Install FTP Deployer, publish configuration, and prepare your remote host
---

# Installation

Install FTP Deployer into the Laravel application you want to deploy.

---

## Requirements

### Local machine or CI

- PHP `^8.1|^8.2|^8.3` according to package metadata.
- Composer available on `PATH`.
- Laravel application using `illuminate/support` `^10|^11|^12|^13`.
- PHP `ext-ftp` enabled.
- Network access to the target FTP/FTPS server.
- Network access to the deployed application URL over HTTP/HTTPS.

### Remote host

- FTP or FTPS account.
- Public HTTP/HTTPS access to the configured `app_url`.
- PHP version compatible with your Laravel app.
- Required Laravel PHP extensions: `openssl`, `pdo`, `mbstring`, `tokenizer`, `xml`, `ctype`, `json`.
- Existing `.env` file on the host.
- Non-empty `APP_KEY` inside that `.env` file.
- Writable `storage` path.
- Writable `bootstrap/cache` path.

> [!WARNING]
> The runner refuses to deploy if `.env` is missing or `APP_KEY` is empty. This is intentional. Secrets should already exist on the server and should not be sent in HTTP deploy payloads.

---

## Install package

Choose one of the methods below to install the package into your Laravel application.

### Option A: From Packagist (Standard)

From your Laravel application root:

```bash
composer require injaonline/ftp-deployer
```

### Option B: From a local directory (Local Install)

Use this if you want to download or clone the package code directly and put it in a local directory inside your Laravel application.

1. Download or clone this repository and place the files in a local folder within your Laravel project, e.g., `packages/ftp-deployer`.

2. Add a `path` repository pointing to your local folder in your Laravel application's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "path",
      "url": "packages/ftp-deployer",
      "options": {
        "symlink": true
      }
    }
  ]
}
```

> [!NOTE]
> Set `"symlink": false` if you want Composer to mirror/copy the package files instead of creating a symlink. This is useful in environments like Docker or shared hosts where symlinks might not be supported or desired.

3. Run the require command:

```bash
composer require injaonline/ftp-deployer:dev-main
```

### Option C: From custom GitHub repository

Use this when installing from a fork, private repo, or before a Packagist release.

1. Add the repository to your Laravel app's `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/injaonline/ftp-deployer"
    }
  ]
}
```

2. Then require the package:

```bash
composer require injaonline/ftp-deployer:dev-main
```

---

The package auto-discovers:

- Service provider: `InjaOnline\FTPDeployer\FTPDeployerServiceProvider`
- Facade alias: `FTPDeployer`
- Artisan command: `ftp-deploy`

---

## Publish config

```bash
php artisan vendor:publish --provider="InjaOnline\FTPDeployer\FTPDeployerServiceProvider" --tag=config
```

This creates:

```text
config/ftp-deployer.php
```

If your Laravel version or publishing setup does not use tags for this package, run:

```bash
php artisan vendor:publish --provider="InjaOnline\FTPDeployer\FTPDeployerServiceProvider"
```

---

## Verify command registration

```bash
php artisan list | grep ftp-deploy
```

Expected command:

```text
ftp-deploy
```

There are no separate `ftp-deploy:init`, `ftp-deploy:build`, `ftp-deploy:push`, `ftp-deploy:run`, or `ftp-deploy:cleanup` commands in the current implementation. The single `ftp-deploy` command performs the full flow.

---

## Prepare remote `.env`

Upload or create the remote `.env` file before first deploy.

Simple mode default location:

```text
{ftp_root}/app/.env
```

Versioned mode default location:

```text
{ftp_root}/app/shared/.env
```

Make sure it contains:

```ini
APP_KEY=base64:your-existing-app-key
APP_ENV=production
APP_DEBUG=false
APP_URL=https://laravelapp.inja.online
```

Add your database, queue, mail, cache, session, and service credentials directly on the host.

> [!IMPORTANT]
> Do not rely on `key:generate` during deploy unless you understand the consequences. Regenerating `APP_KEY` can invalidate encrypted cookies, sessions, password reset tokens, and encrypted data.

---

## Prepare writable paths

The runner checks write access before executing commands.

Simple mode:

```text
{ftp_root}/app/storage/
{ftp_root}/app/bootstrap/cache/
```

Versioned mode:

```text
{ftp_root}/app/shared/storage/
{ftp_root}/app/releases/{release_id}/bootstrap/cache/
```

On cPanel, create folders with File Manager or FTP if they do not exist. Permissions vary by host, but PHP must be able to write to them.

---

## Prepare public document root

Choose one path model:

### Option A: simple mode

Point your domain document root at:

```text
{ftp_root}/app/public
```

Use default paths:

```ini
FTP_DEPLOYER_MODE=simple
FTP_DEPLOYER_FTP_ROOT=/
FTP_DEPLOYER_APP_ROOT=app
FTP_DEPLOYER_PUBLIC_ROOT=app/public
FTP_DEPLOYER_APP_URL=https://laravelapp.inja.online
```

### Option B: versioned mode

Point your domain document root at:

```text
{ftp_root}/public_html
```

The deployer manages:

```text
{ftp_root}/public_html/index.php
```

Use paths like:

```ini
FTP_DEPLOYER_MODE=versioned
FTP_DEPLOYER_FTP_ROOT=/
FTP_DEPLOYER_APP_ROOT=app
FTP_DEPLOYER_PUBLIC_ROOT=public_html
FTP_DEPLOYER_RELEASE_ROOT=app/releases
FTP_DEPLOYER_SHARED_ROOT=app/shared
FTP_DEPLOYER_CURRENT_PATH=app/current
FTP_DEPLOYER_APP_URL=https://laravelapp.inja.online
```

---

## First dry run checklist

Before running deploy, confirm:

- `php artisan ftp-deploy production` can read your profile.
- FTP credentials work.
- `FTP_DEPLOYER_APP_URL` opens the site domain, like `https://laravelapp.inja.online`.
- Remote `.env` exists.
- `APP_KEY` exists and is not empty.
- Local frontend build output exists if your app uses Vite or Mix.
- `composer install` works locally.

Then run:

```bash
php artisan ftp-deploy production
```

Continue with [Configuration](/ftp-deployer/configuration/) for every profile key, then use [Cookbook & Examples](/ftp-deployer/cookbook/) for frontend builds, no-frontend apps, custom Artisan setup commands, and CI recipes.
