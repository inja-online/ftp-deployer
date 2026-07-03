# Change Summary

## Deployment behavior

- Added verbose `php artisan ftp-deploy` output:
  - deploy start timestamp and profile
  - temporary release path
  - Composer input hashes
  - FTP host
  - archive diff summary
  - app ZIP path, size, and remote destination
  - vendor ZIP hash name and reuse status
  - installer filename/path
  - remote command count/list
  - final deploy duration and upload/delete/skip counts

## Composer and vendor reuse

- Added local production vendor cache keyed by `composer.json` and `composer.lock` hashes:
  - cache path: `/tmp/ftp-deployer-vendor-{composer_json}-{composer_lock}`
  - cache hit copies cached `vendor/` into temp release and skips Composer
  - cache miss runs `composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction --no-scripts`, then saves `vendor/` to cache
- Reused remote `vendor-{composer_json}-{composer_lock}.zip` when it already exists.
- Fixed uploaded count so reused vendor ZIPs do not count as uploads.
- Kept versioned vendor extraction release-local: `release/vendor/`.
  - Shared vendor directories/symlinks were avoided because Composer optimized autoload stores app class paths relative to the release.

## Archive filtering

- Expanded app ZIP exclusions for dev/tooling files and folders:
  - `.editorconfig`
  - package manager files/lockfiles
  - PHPStan/PHPUnit/Pint config
  - Vite config
  - Git files
  - `node_modules/`
  - other non-runtime build/test artifacts
- Added tests verifying real `FTPDeployer::buildArchives()` excludes those files.

## Versioned deploy mode

- Versioned bootloader now hardcodes the release ID instead of reading `app/current` on every request.
- Bootloader is uploaded every versioned deploy.
- `app/current` is still written for compatibility.
- Installer/runner extracts app and vendor into the fresh release.
- Remote Artisan commands run against the hardcoded release.
- Fixed `public_root='.'` path handling so installer uploads as `install-*.php` instead of `./install-*.php`.

## Runner and cleanup fixes

- Fixed temp cleanup for symlinked package paths in local `vendor/`.
- Removed versioned release vendor symlink behavior after deploy validation showed optimized autoload path breakage.
- Versioned runner now uses release-local `vendor/autoload.php`.

## Documentation and agent skill

Updated docs:

- `docs/src/pages/agent-skill.md`
- `docs/src/pages/commands.md`
- `docs/src/pages/concepts.md`
- `docs/src/pages/configuration.md`
- `docs/src/pages/index.md`
- `docs/src/pages/security.md`
- `docs/src/pages/troubleshooting.md`

Updated skill:

- `skills/ftp-deployer/SKILL.md`

Docs now describe:

- simple vs versioned mode
- local Composer vendor cache
- remote vendor ZIP reuse
- release-local vendor extraction in versioned mode
- hardcoded versioned bootloader
- verbose deploy output
- archive exclusions

## Validation

Passed:

```bash
composer test
composer stan
cd docs && npm run build
```

Latest deploy validation in `/home/amin/Desktop/example-app` passed:

```text
Deploy complete in 106.02s. Uploaded 1, deleted 0, skipped 6.
```
