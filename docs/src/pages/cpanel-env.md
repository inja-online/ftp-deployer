---
layout: ../layouts/DocsLayout.astro
title: cPanel Env Setup
description: Guide on configuring the remote .env file for shared cPanel hosting using database or file-based drivers
---

# cPanel .env Setup Guide

Unlike local development or dedicated cloud servers, shared cPanel hosting environments have unique constraints. They typically lack in-memory caching servers (such as Redis or Memcached) and do not support long-running daemon supervisors (like Supervisor for queue workers). 

This guide details how to configure your Laravel `.env` file on cPanel to ensure stability, reliability, and performance.

---

## 1. Key Driver Recommendations for cPanel

On shared hosting, choosing the right drivers for cache, session, and queues is critical.

### Cache (`CACHE_STORE`)
* **Recommended: `database` or `file`**
* **Why:** Redis and Memcached are rarely available on shared hosting. 
  * `database`: Highly recommended if you are using **Versioned Mode** or if your hosting filesystem experiences file-locking latency.
  * `file`: Simple and has zero database overhead, but can cause permission errors or file-locking issues on certain shared filesystems.
  * *Note:* If using `database`, you must run `php artisan cache:table` and migrate before deploying.

### Session (`SESSION_DRIVER`)
* **Recommended: `database`**
* **Why:** 
  * `file` sessions on shared hosts write thousands of small files into `storage/framework/sessions/`. This can hit inode limits, cause permission conflicts, and slow down requests due to filesystem locking.
  * `database` sessions are clean, scale better on shared DBs, and prevent session loss during versioned deployment rollbacks.
  * *Note:* Run `php artisan session:table` and migrate before deploying.

### Queue (`QUEUE_CONNECTION`)
* **Recommended: `database` (with Cron) or `sync`**
* **Why:** You cannot run `php artisan queue:work` as a persistent background daemon on cPanel.
  * `database`: If you need asynchronous processing (e.g. sending emails, processing uploads), use `database`. You must set up a cPanel Cron Job running every minute:
    ```bash
    * * * * * /usr/local/bin/php /home/username/app/artisan queue:work --stop-when-empty > /dev/null 2>&1
    ```
  * `sync`: If you do not have complex background tasks and want requests to process tasks immediately in-line.
  * *Note:* Run `php artisan queue:table` and migrate if using `database`.

### Database Connection (`DB_CONNECTION`)
* **Recommended: `mysql`**
* **Why:** While `sqlite` is supported, shared hosts typically perform better with `mysql` (MariaDB). You must create the MySQL database, database user, and assign full privileges using the **MySQL Database Wizard** in cPanel. Set `DB_HOST=127.0.0.1` or `localhost`.

---

## 2. Annotated cPanel `.env` Template

Here is a recommended `.env` template for a production Laravel app running on cPanel:

```ini
# ==============================================================================
# 1. APP CONFIGURATION
# ==============================================================================
APP_NAME="Laravel App"
APP_ENV=production
# IMPORTANT: Generate a secure APP_KEY locally using `php artisan key:generate` 
# and copy it here. Do not leave it blank.
APP_KEY=base64:YOUR_SECURE_PRODUCTION_APP_KEY
APP_DEBUG=false
APP_URL=https://laravelapp.inja.online

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

# Set maintenance mode driver to file for simplicity
APP_MAINTENANCE_DRIVER=file
# APP_MAINTENANCE_STORE=database

BCRYPT_ROUNDS=12

# ==============================================================================
# 2. LOGGING
# ==============================================================================
LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

# ==============================================================================
# 3. DATABASE CONFIGURATION
# ==============================================================================
# MySQL is standard for production. SQLite can be used for simple testing.
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
# These must match the Database and User created in cPanel:
DB_DATABASE=cpaneluser_laravel_db
DB_USERNAME=cpaneluser_db_user
DB_PASSWORD="your_secure_db_password"

# ==============================================================================
# 4. SESSION, CACHE, AND QUEUE DRIVERS
# ==============================================================================
# Replaces Redis/Memcached with reliable database-backed drivers
SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=false
SESSION_PATH=/
SESSION_DOMAIN=null

CACHE_STORE=database
# CACHE_PREFIX=

# Use database for async queues (with cPanel cron jobs) or "sync" for synchronous
QUEUE_CONNECTION=database

# ==============================================================================
# 5. MAIL CONFIGURATION
# ==============================================================================
# Recommend using SMTP via cPanel email account or third-party service (Mailgun, SES, etc.)
MAIL_MAILER=smtp
MAIL_HOST=mail.yourdomain.com
MAIL_PORT=465
MAIL_USERNAME=noreply@yourdomain.com
MAIL_PASSWORD="your_email_password"
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="noreply@yourdomain.com"
MAIL_FROM_NAME="${APP_NAME}"

# ==============================================================================
# 6. FILE SYSTEM AND THIRD-PARTY SERVICES
# ==============================================================================
BROADCAST_CONNECTION=log
FILESYSTEM_DISK=local

# Redis is disabled for shared hosting
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PASSWORD=null
REDIS_PORT=6379

# Memcached is disabled for shared hosting
MEMCACHED_HOST=127.0.0.1

# AWS configuration (optional, if using S3)
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=
AWS_USE_PATH_STYLE_ENDPOINT=false

# ==============================================================================
# 7. FRONTEND CONFIGURATION
# ==============================================================================
VITE_APP_NAME="${APP_NAME}"
```

---

## 3. Step-by-Step Database Setup

If you choose to use the `database` driver for Cache, Sessions, or Queues as recommended above, follow these steps:

### Step 1: Create Database Tables Locally
Before deploying, run the following Artisan commands in your local development environment to generate the necessary migration files:

```bash
# Create migration for cache table
php artisan cache:table

# Create migration for sessions table
php artisan session:table

# Create migration for queue jobs table
php artisan queue:table
```

### Step 2: Run Migrations Locally (Optional)
Test the migrations locally to make sure the tables are created successfully:
```bash
php artisan migrate
```

### Step 3: Configure the Remote `.env`
Place the `.env` file on the remote server (e.g. in `{ftp_root}/app/.env` for Simple Mode, or `{ftp_root}/app/shared/.env` for Versioned Mode) with the database credentials and drivers set to `database` as shown above.

### Step 4: Deploy and Migrate Remotely
Run the deploy command. The temporary HTTP runner will automatically run `php artisan migrate --force` (if configured in your `remote_commands`) to create these tables on your cPanel database:

```bash
php artisan ftp-deploy production
```

---

## 4. Routing and Web Root Configuration (.htaccess)

By default, Laravel's entry point is the `public` directory. On shared cPanel hosting, your domain's document root typically points to `/public_html` or a subdomain subdirectory (e.g. `/public_html/subdomain`). 

Depending on your host's capabilities, choose one of the following methods to route incoming web requests to the Laravel `public` directory:

### Method A: Change Document Root in cPanel (Recommended)
If your cPanel account allows changing the document root for your domain or subdomain:
1. Log in to cPanel and navigate to **Domains** or **Subdomains**.
2. Edit the document root of your target domain and set it to:
   ```text
   /public_html/your-subdomain/app/public
   ```
3. This is the cleanest and most secure approach because it keeps your application's core code (`app/`, `.env`, `vendor/`) completely outside of the web root folder, preventing any accidental exposure.

### Method B: Use a Root `.htaccess` Rewrite
If your hosting provider does not permit changing the domain's document root, you must redirect all incoming browser requests into the `app/public` folder using Apache rewrite rules.

Create or edit a `.htaccess` file directly in your domain's root directory (e.g. `/public_html/your-subdomain/.htaccess`) and add the following content:

```apache
<IfModule mod_rewrite.c>
    RewriteEngine On

    # Prevent directory listing
    Options -Indexes

    # Rewrite all requests to the app/public subdirectory
    RewriteCond %{REQUEST_URI} !^/app/public/
    RewriteRule ^(.*)$ app/public/$1 [L]
</IfModule>
```

> [!NOTE]
> The deployer pipeline automatically syncs Laravel's default `.htaccess` from your local `public/` directory to the remote `/app/public/.htaccess` folder, which takes care of Laravel's internal front-controller routing once the request has been rewritten to that directory.

---

## 5. Where each value comes from

| Variable | Where to get it |
|---|---|
| `FTP_DEPLOYER_PROFILE` | Pick a profile name from `config/ftp-deployer.php`; `production` is the default. |
| `FTP_DEPLOYER_HOST` | FTP host shown by cPanel, your hosting panel, or FTP account details. |
| `FTP_DEPLOYER_USERNAME` | FTP account username from cPanel **FTP Accounts**. |
| `FTP_DEPLOYER_PASSWORD` | Password you set when creating or resetting the FTP account. |
| `FTP_DEPLOYER_PORT` | Port from host docs; usually `21` for FTP/FTPS. |
| `FTP_DEPLOYER_SSL` | Use `true` only if host supports FTPS. Check host docs or ask support. |
| `FTP_DEPLOYER_PASSIVE` | Usually `true` on shared hosting. Change only if host tells you to. |
| `FTP_DEPLOYER_FTP_ROOT` | Remote folder relative to FTP login root. Find it by logging in with File Manager/FTP and checking the top-level app folder. |
| `FTP_DEPLOYER_APP_URL` | Public site URL users open in browser, like `https://laravelapp.inja.online`. |
| `FTP_DEPLOYER_MODE` | Choose `simple` or `versioned` based on how you want the app laid out on the host. |
| `FTP_DEPLOYER_APP_ROOT` | Remote app folder name under `FTP_DEPLOYER_FTP_ROOT`; usually `app`. |
| `FTP_DEPLOYER_PUBLIC_ROOT` | Remote public folder name under `FTP_DEPLOYER_FTP_ROOT`; usually `app/public` or `public_html`. |
| `FTP_DEPLOYER_FILESYSTEM_ROOT` | Absolute server path for the same folder as `FTP_DEPLOYER_FTP_ROOT`; cPanel File Manager shows this path. |

