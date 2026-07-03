# Changelog

All notable changes to `ftp-deployer` will be documented in this file.

## Unreleased

- Added FTP-based Laravel deployment for cPanel/shared hosts.
- Added changed-file manifest support.
- Added conditional `vendor/` upload when `composer.json` or `composer.lock` changes.
- Added short-lived tokenized HTTP runner for remote Artisan commands.
- Added simple and versioned deployment modes.
- Added frontend build output detection for Vite and Mix.
- Added config publishing via `ftp-deployer-config` tag.
