---
layout: ../layouts/DocsLayout.astro
title: Security Model
description: Security details for credentials, remote runner, .env handling, logs, exclusions, and operational hardening
---

# Security Model

FTP Deployer is designed for constrained shared hosting. It reduces manual FTP risk, but it still uploads executable PHP and calls a temporary public runner. Treat deploy configuration and host access as production-sensitive.

---

## Trust boundaries

<div class="mermaid">
  <img src="/ftp-deployer/images/security-1.svg" alt="security diagram 1" />
</div>

Trusted:

- Local developer machine or CI runner.
- Composer dependencies installed locally.
- FTP/FTPS account.
- HTTPS endpoint for `app_url`.
- Remote `.env` already placed on server.

Untrusted or exposed:

- Public internet can reach the temporary runner URL while it exists.
- Shared hosting filesystem may be visible to host administrators.
- FTP without TLS exposes credentials and file contents on the network.

---

## FTP credentials

Store FTP credentials in local `.env` or CI secrets:

```ini
FTP_DEPLOYER_HOST=ftp.example.com
FTP_DEPLOYER_USERNAME=deploy@example.com
FTP_DEPLOYER_PASSWORD=secret
```

Recommendations:

- Use a dedicated FTP account for deploys.
- Restrict that FTP account to the smallest directory possible.
- Prefer FTPS when the host supports it:

```ini
FTP_DEPLOYER_SSL=true
```

- Rotate credentials after team member offboarding or CI compromise.

---

## `.env` handling

The deployer intentionally excludes:

```text
.env
.env.*
```

The runner also refuses to create or update `.env`.

Why:

- `.env` contains production secrets.
- Sending secrets in the HTTP runner payload increases exposure.
- Accidentally overwriting production `.env` can break the app.
- Regenerating `APP_KEY` can invalidate encrypted data.

Required remote state:

Simple mode:

```text
{ftp_root}/{app_root}/.env
```

Versioned mode:

```text
{ftp_root}/{shared_root}/.env
```

Required value:

```ini
APP_KEY=base64:...
```

---

## Temporary HTTP runner

For each deploy, the package generates:

- random filename: `install-{16 hex chars}.php`
- random token: 64 hex chars from 32 random bytes

The runner is uploaded to:

```text
{ftp_root}/{public_root}/{runner_filename}
```

The CLI calls:

```text
{app_url}/{runner_filename}
```

Command payload:

```json
{
  "token": "random-token",
  "commands": [
    {"cmd": "migrate --force", "fail_on_error": true}
  ]
}
```

Archive extraction payload:

```json
{
  "token": "random-token",
  "extract": [
    {"archive": "/home/user/public_html/site/.ftp-deployer/archives/app-random.zip", "destination": "/home/user/public_html/site/app"}
  ]
}
```

No database passwords, app secrets, or `.env` values are sent.

---

## Runner authorization

The runner checks:

```php
hash_equals(DEPLOY_TOKEN, $payload['token'])
```

Invalid or missing token returns JSON `401` and executes no commands.

Security properties:

- Token is not in URL query string.
- Token changes every deploy.
- Filename changes every deploy.
- Extraction runs only after token validation.
- ZIP entries with absolute paths, drive prefixes, UNC paths, or `..` traversal are rejected before extraction.
- Runner is removed over FTP after success or failure.

Remaining risks:

- If `app_url` uses plain HTTP, token can be intercepted.
- If deploy process is killed, runner may remain until manually deleted.
- If server logs request bodies, token may appear in logs depending on host tooling.

Hardening:

- Use HTTPS only.
- Delete stale `install-*.php` files periodically.
- Use host-level access logs to detect unexpected runner requests.

---

## Temporary archive uploads

Archive mode uploads ZIP files under:

```text
{ftp_root}/.ftp-deployer/archives/
```

Protections:

- Random filenames such as `app-{32 hex}.zip`; vendor archive names are deterministic from Composer hashes in versioned mode.
- Deny `.htaccess` files uploaded before ZIPs to `.ftp-deployer/` and `.ftp-deployer/archives/`.
- Short lifetime: ZIPs are deleted after extraction, with best-effort cleanup on failure.
- No ZIP passwords in v1. This avoids weak ZipCrypto and inconsistent AES support on shared hosts.

Remaining risks:

- A non-Apache or misconfigured host may ignore `.htaccess`.
- If deploy is killed, ZIPs may remain until manually deleted.
- Large ZIPs can consume remote disk quota.

---

## Runner command scope

The runner executes configured Artisan command strings, not arbitrary PHP code from the payload.

Example config:

```php
'remote_commands' => [
    'migrate --force',
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

Do not include commands that accept untrusted input or expose secrets.

Avoid:

```php
'config:show'
'env:dump'
'route:list --json' // if routes expose internal metadata you do not want logged
```

Prefer:

```php
'migrate --force'
'optimize:clear'
'optimize'
```

---

## Log sanitization

Runner command output is sanitized for common secret patterns:

- `APP_KEY=...`
- `DB_PASSWORD=...`
- `PASSWORD=...`
- `SECRET=...`
- `TOKEN=...`

Example:

```text
DB_PASSWORD=[redacted]
```

Limits:

- Sanitization is pattern-based.
- It cannot detect every secret format.
- Avoid commands that print full config or environment dumps.

---

## Exclusion safety

Default exclusions protect production state:

```text
.env
.env.*
storage/app/public/
storage/framework/cache/
storage/framework/sessions/
storage/framework/testing/
storage/framework/views/
storage/logs/
bootstrap/cache/*.php
.ftp-deployer/
```

Why these matter:

| Path | Risk if uploaded/deleted |
|---|---|
| `.env` | Overwrites production secrets. |
| `storage/app/public/` | Can overwrite/delete user uploads. |
| `storage/framework/sessions/` | Can invalidate sessions or leak local state. |
| `storage/logs/` | Can expose local logs or overwrite production logs. |
| `bootstrap/cache/*.php` | Can boot app with local absolute paths or stale config. |
| `.ftp-deployer/` | Can corrupt remote deploy state. |

---

## Manifest deletion safety

The deployer deletes only files previously tracked in the remote manifest.

Normal app deletion:

- If a file existed in previous manifest and no longer exists locally, delete its recorded remote path.

Frontend deletion:

- Deletes old frontend assets only if they were previously tracked under configured frontend output paths.
- Does not delete arbitrary public files.

This protects user uploads and unmanaged files from broad FTP cleanup.

---

## Versioned mode security

Versioned mode uses a managed public bootloader:

```text
{ftp_root}/{public_root}/index.php
```

It hardcodes and boots:

```text
{ftp_root}/{release_root}/{release_id}/bootstrap/app.php
```

The deployer also writes `{ftp_root}/{current_path}` for compatibility, but the bootloader does not read it per request.

Security notes:

- `current_path` should not be web-accessible when possible, even though the current bootloader does not read it at runtime.
- `release_root` and `shared_root` should not be inside public web root.
- `shared_root/.env` must not be public.
- The bootloader should be the only public PHP entrypoint in `public_root` besides temporary runner during deploy.

Recommended layout:

```text
app/                  not web-accessible
  current
  releases/
  shared/.env
public_html/          web-accessible
  index.php
  build/
```

---

## CI hardening

When deploying from CI:

- Store FTP credentials in CI secrets.
- Do not echo secrets in shell hooks.
- Use `composer install` with locked dependencies.
- Run tests before deploy.
- Build frontend before deploy.
- Restrict deploy job to protected branches.
- Use environment approvals for production.

Example branch guard:

```yaml
on:
  push:
    branches: [main]
```

---

## Incident response

If you suspect runner exposure:

1. Delete all runner files:

```text
{ftp_root}/{public_root}/install-*.php
```

2. Rotate FTP password.
3. Check web access logs for runner URL hits.
4. Check Laravel logs for unexpected command effects.
5. Rotate app secrets if command output or logs may have exposed them.
6. Re-deploy after cleanup.

If you suspect `.env` exposure:

1. Rotate `APP_KEY` only if you understand encrypted-data impact.
2. Rotate database password.
3. Rotate mail/API credentials.
4. Clear sessions/tokens where needed.
5. Audit public document root to ensure `.env` is not reachable.
