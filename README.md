# Laravel FTP Deployer

Deploy Laravel apps to FTP-only cPanel/shared hosts without SSH.

This package uploads ZIP artifacts over FTP by default, skips unchanged `vendor/` uploads with a remote manifest, then runs remote Laravel maintenance commands through a short-lived tokenized HTTP runner. Disable archive mode to fall back to recursive changed-file FTP uploads.

Use it when shared hosting gives you FTP/FTPS but no SSH, no remote Composer, and no safe way to run Laravel maintenance commands after upload.

## What problem does this solve?

After multiple privilege escalation vulnerabilities, such as CVE-2021-4034 “PwnKit” and CVE-2021-3156 “Baron Samedit”, showed non-root to root escalation was possible, many hosts restricted or disabled SSH access for deployment. OpenSSH hardening guidance and CIS Benchmarks pushed the same direction.

At the same time, many hosts still lacked built-in deployment tooling, especially shared-hosting panels like DirectAdmin with limited automation APIs.

This gap created the need for a minimal, secure deployment path that does not rely on full SSH access.

GitHub: <https://github.com/inja-online/ftp-deployer>

## Requirements

- PHP 8.1+
- Laravel 10, 11, 12, or 13
- PHP `ftp` extension
- PHP `zip` extension locally and on the remote host when archive mode is enabled
- FTP/FTPS access to your host
- Public HTTPS URL for the deployed app
- Local/CI build already completed for frontend assets

## Install

### From Packagist

```bash
composer require inja-online/ftp-deployer
php artisan vendor:publish --tag=ftp-deployer-config
```

### From a local directory (Local Install)

Use this if you want to download the package code directly and put it in a local directory inside your Laravel application.

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

*(Note: Set `"symlink": false` if you want Composer to mirror/copy the package files instead of creating a symlink, which is useful in environments like Docker or shared hosts where symlinks might not be supported or desired).*

3. Run the require command:

```bash
composer require inja-online/ftp-deployer:dev-main
php artisan vendor:publish --tag=ftp-deployer-config
```

### From custom GitHub repository

Use this when installing from a fork, private repo, or before Packagist release.

Add repository to your Laravel app `composer.json`:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/inja-online/ftp-deployer"
    }
  ]
}
```

Then require package:

```bash
composer require inja-online/ftp-deployer:dev-main
php artisan vendor:publish --tag=ftp-deployer-config
```

For a fork/private repo, replace URL:

```json
{
  "repositories": [
    {
      "type": "vcs",
      "url": "https://github.com/YOUR-USER/YOUR-REPO"
    }
  ]
}
```

Then install from chosen branch:

```bash
composer require inja-online/ftp-deployer:dev-main
```

## Configure

Published config file:

```text
config/ftp-deployer.php
```

Add deploy values to your local/CI `.env`:

```dotenv
FTP_DEPLOYER_PROFILE=production
FTP_DEPLOYER_HOST=ftp.example.com
FTP_DEPLOYER_USERNAME=ftp-user
FTP_DEPLOYER_PASSWORD=secret
FTP_DEPLOYER_PORT=21
FTP_DEPLOYER_SSL=false
FTP_DEPLOYER_PASSIVE=true
FTP_DEPLOYER_FTP_ROOT=/public_html
FTP_DEPLOYER_APP_URL=https://laravelapp.inja.online

# Archive deploys are enabled by default.
# This must be the PHP filesystem path matching FTP_DEPLOYER_FTP_ROOT.
FTP_DEPLOYER_FILESYSTEM_ROOT=/home/your-user/public_html/laravelapp.inja.online
FTP_DEPLOYER_ARCHIVE_ENABLED=true

# Default layout
FTP_DEPLOYER_MODE=simple
FTP_DEPLOYER_APP_ROOT=app
FTP_DEPLOYER_PUBLIC_ROOT=app/public
```

Default remote layout:

```text
{ftp_root}/app/          Laravel root: artisan, vendor, .env, bootstrap, storage
{ftp_root}/app/public/   public root and temporary runner location
{ftp_root}/.ftp-deployer temporary manifests and archive uploads
```

If your domain root is `FTP_DEPLOYER_FTP_ROOT=/public_html/laravelapp.inja.online`, set `FTP_DEPLOYER_FILESYSTEM_ROOT` to the matching absolute PHP path, for example `/home/<cpanel-user>/public_html/laravelapp.inja.online`.

Remote `.env` must already exist and contain `APP_KEY`. This package does not upload your local `.env`.

## Deploy

Build your app locally or in CI first:

```bash
composer install --no-dev --prefer-dist --optimize-autoloader
npm ci
npm run build
```

For Bun/React/Vite projects, build with Bun instead:

```bash
bun install --frozen-lockfile
bun run build
```

No frontend? Skip Node/Bun entirely; if no `package.json` exists, frontend detection is skipped automatically.

Run deploy:

```bash
php artisan ftp-deploy production
```

For automation or AI agents, use structured JSON output:

```bash
php artisan ftp-deploy production --format=agent
```

Default remote commands:

```text
migrate --force
optimize:clear
optimize
storage:link
```

Change them in `config/ftp-deployer.php` under `remote_commands`:

```php
'remote_commands' => [
    'migrate --force',
    'app:setup:cache',
    'optimize:clear',
    'optimize',
    ['command' => 'storage:link', 'ignore_failures' => true],
],
```

For Bun/React builds, no-frontend apps, custom commands, queues, caches, and CI, see [Cookbook & Examples](docs/src/pages/cookbook.md). For customization and overrides, see [Extending & Overriding](docs/src/pages/extending.md).

## Connection Check

Before performing a deploy, you can verify your FTP connection settings (host, username, password, port, SSL, and passive mode) using:

```bash
php artisan ftp-deploy:check production
```

For automation or AI agents, use structured JSON output:

```bash
php artisan ftp-deploy:check production --format=agent
```

## Migration to Versioned Mode

If you have an existing profile configured in `simple` mode and want to upgrade it to `versioned` mode, you can use the interactive migration command:

```bash
php artisan ftp-deploy:migrate production
```

Arguments & Options:
* `profile` (optional): The profile key to migrate (defaults to `production`).
* `--write`: Automatically updates/appends the required environment variables to your local `.env` file (if writable).
* `--format=agent`: Emits a structured JSON payload instead of interactive prompts, making it ideal for automation.

### Remote Server Changes
Note that the migration command only updates your local configuration and **does not touch your remote `.env`**. To complete the transition:
1. Log into your FTP server.
2. Move your remote `.env` from `{ftp_root}/{app_root}/.env` to `{ftp_root}/{shared_root}/.env`.
3. Move your remote `storage/` directory to `{ftp_root}/{shared_root}/storage/`.
4. Ensure the remote `storage/` directory remains writable (`chmod -R 775` or equivalent).
5. Run `php artisan ftp-deploy <profile>` to deploy the bootloader and link the new release layout.

## Archive deploy mode

Archive mode is enabled by default. It uploads an app ZIP every deploy and a vendor ZIP only when `composer.json` or `composer.lock` changes. Temporary ZIPs use random filenames under `.ftp-deployer/archives/`, are protected with deny `.htaccess` files, and are deleted after extraction. SQLite files under `database/` are excluded from app archives. ZIP passwords are not used in v1.

After extraction succeeds, stale files from the previous manifest are deleted, then the new manifest is saved before remote commands run. If extraction fails, the manifest and stale files are left unchanged. If your host times out during extraction or filesystem-root mapping is unavailable, set `FTP_DEPLOYER_ARCHIVE_ENABLED=false` to use recursive FTP uploads.

Simple mode extracts over live files and is best-effort. Use versioned mode for safer releases.

## Versioned deploy mode

Simple mode uploads to stable app/public paths. Versioned mode uploads app code into release directories and updates a current-release pointer after success.

```dotenv
FTP_DEPLOYER_MODE=versioned
FTP_DEPLOYER_RELEASE_ROOT=../app/releases
FTP_DEPLOYER_SHARED_ROOT=../app/shared
FTP_DEPLOYER_CURRENT_PATH=../app/current
```

## AI Agent Integration

This repository includes a pre-configured AI Agent Skill (`ftp-deployer`) to help autonomous coding assistants (such as Google Antigravity or Claude Code) run, configure, and troubleshoot deployments safely.

To install this skill to your agent globally:

```bash
npx skills add inja-online/ftp-deployer --skill ftp-deployer
```



For more details on integration, configuration custom roots, and sample agent prompts, see the [AI Agent Integration docs](docs/src/pages/agent-skill.md).

## Documentation

- [Installation](docs/src/pages/installation.md)
- [Configuration](docs/src/pages/configuration.md)
- [Cookbook & Examples](docs/src/pages/cookbook.md)
- [Extending & Overriding](docs/src/pages/extending.md)
- [CLI Commands](docs/src/pages/commands.md)
- [Troubleshooting](docs/src/pages/troubleshooting.md)

## Security

- Runner filename and token are random per deploy.
- Runner accepts JSON and returns JSON.
- Runner is deleted after deploy success or failure.
- `.env` is excluded by default.
- Use HTTPS for `FTP_DEPLOYER_APP_URL`.

## Manual release workflow

Repository includes a manual GitHub Actions workflow for tagged package releases:

1. Open GitHub → Actions → `manual-release`.
2. Click **Run workflow**.
3. Enter version like `v1.2.3`.
4. Optionally mark as prerelease or add release notes.

The workflow validates Composer metadata, runs tests, PHPStan, lint dry-run, creates an annotated Git tag, then creates the GitHub release. Packagist can pick up the tag from GitHub.

## Useful commands

```bash
composer test
composer stan
composer lint-test
```

## License

AGPL-3.0-or-later. See [LICENSE.md](LICENSE.md).
