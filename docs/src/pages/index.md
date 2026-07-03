---
layout: ../layouts/DocsLayout.astro
title: Introduction
description: FTP-only Laravel deployment with local release builds, manifest diffing, and a short-lived HTTP runner
---

# Introduction

**FTP Deployer** deploys Laravel applications to shared cPanel-style hosting where you have FTP/FTPS and HTTPS, but no SSH.

The package runs from your local machine or CI. It prepares a production-ready release directory, uploads changed files over FTP, then calls a temporary token-protected HTTP runner on the target host to execute Laravel Artisan commands.

> [!IMPORTANT]
> The package does **not** create or update the remote `.env` file. Place `.env` on the server before deploying, and make sure it contains a non-empty `APP_KEY`.

---

## What problem this solves

Shared hosting usually blocks SSH, so normal Laravel deploy steps become manual:

- Upload files with an FTP client.
- Manually avoid overwriting `.env`, `storage`, logs, sessions, and cache files.
- Upload `vendor/` because Composer cannot run remotely.
- Visit some installer URL or click around cPanel to clear caches and run migrations.
- Hope stale `bootstrap/cache/*.php` files do not break the new code.

FTP Deployer turns that into one command:

```bash
php artisan ftp-deploy production
```

---

## Current feature set

- One Artisan command: `ftp-deploy {profile=production}`.
- Named deploy profiles in `config/ftp-deployer.php`.
- FTP/FTPS upload using PHP `ext-ftp`; the package currently also keeps `lazzard/php-ftp-client` as a dependency.
- Temporary release directory outside the working tree.
- Production Composer install inside the temporary release directory.
- Exclusion matching for source-control, local dev, `.env`, cache, log, session, and runtime paths.
- Manifest-based file diffing using `.ftp-deployer/manifest.json` on the remote host.
- Special `vendor/` handling driven by `composer.json` and `composer.lock` hashes.
- Frontend build output detection for Vite, Vite 5+, Laravel Mix, and explicit named builds.
- Stale-build warnings when frontend input files change but build manifests do not.
- Versioned deployment mode with release directories, shared state, compatibility current pointer, hashed vendor cache, and managed public bootloader.
- Short-lived HTTP runner with random filename and random token.
- Runner requirement checks, `.env` validation, cache invalidation, and remote Artisan command execution.
- Runner cleanup over FTP after success or failure.
- Local shell hooks before and after deploy.

---

## Architecture in one picture

<div class="mermaid">
  <img src="/ftp-deployer/images/index-1.svg" alt="index diagram 1" />
</div>

---

## Simple mode vs versioned mode

### Simple mode

Simple mode is default. It maps local Laravel files into one remote app root and maps local `public/` files into one public root.

Default layout:

```text
{ftp_root}/app/          Laravel app root: artisan, vendor, bootstrap, storage
{ftp_root}/app/public/   public web root
```

Simple mode is best when your cPanel account points the domain directly at `app/public` or a similar public folder.

### Versioned mode

Versioned mode uploads app code under release directories and keeps shared state outside releases.

Typical layout:

```text
{ftp_root}/app/
  current                 compatibility pointer written after deploy
  shared/
    .env
    storage/
  releases/
    20260702123456/
      artisan
      vendor/
      bootstrap/
      public/
{ftp_root}/public_html/
  index.php               managed bootloader with hardcoded release id
  build/                  stable frontend assets
```

The managed `public_html/index.php` hardcodes the deployed release id, boots that release, and injects shared `.env` and `storage` paths. It does not read `app/current` on every request.

Versioned mode is useful because each deploy extracts into a fresh release while reusing hash-named vendor ZIP uploads. It is not full zero-downtime deployment: migrations and stable public assets can still affect live traffic.

---

## What the package deliberately does not do

- No SSH deploys.
- No remote Composer install.
- No remote npm/yarn/pnpm/bun build.
- No cPanel API integration.
- No web dashboard.
- No `.env` generation or secret transport through the runner payload.
- No guaranteed zero downtime.

---

## Next steps

1. Read [Installation](/ftp-deployer/installation/).
2. Configure a profile in [Configuration](/ftp-deployer/configuration/).
3. Learn the deploy flow in [Core Concepts](/ftp-deployer/concepts/).
4. Run the command from [CLI Commands](/ftp-deployer/commands/).
5. Copy common setups from [Cookbook & Examples](/ftp-deployer/cookbook/).
6. Customize behavior from [Extending & Overriding](/ftp-deployer/extending/).
