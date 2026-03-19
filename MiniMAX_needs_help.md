# MiniMAX Needs Help - Installation Handover

## Goal

Install InvoiceShelf custom app on localhost:3000 with:

- MySQL database (already imported)
- Redis (already installed and running)
- Access to login page

## What's Been Completed

### 1. MySQL Setup ✅

- Created database `invoiceshelf`
- Created user `invoiceshelf` with password `invoiceshelf123`
- Imported database backup: `/home/hp/invoiceshelf-custom-rds/DB recovery - to be deleted after successful db import./db-dumps/mysql-invoiceshelf.sql`

### 2. Redis Setup ✅

- Installed Redis server: `sudo apt-get install -y redis-server`
- Redis is running: `redis-cli ping` returns `PONG`
- Installed PHP Redis extension: `php8.3-redis`

### 3. Environment Configuration ✅

- Created `.env` file from `.env.example`
- Generated APP_KEY: `base64:nWscbW+nGv6FhuhWMFyhMRG8xLZkDfuwjE9rL968ag8=`
- Configured MySQL connection (127.0.0.1, port 3306, database invoiceshelf)
- Changed SESSION_DRIVER and CACHE_DRIVER from redis to **file** (redis was causing connection issues)
- Changed QUEUE_CONNECTION from redis to **sync**

### 4. PHP Dependencies ✅

- Ran `composer install --no-dev --optimize-autoloader`

### 5. Node/Frontend ✅

- Ran `npm install`
- Ran `npm run build` (production build to public/build/)

### 6. Laravel Configuration ✅

- Created storage link: `php artisan storage:link`
- Created database marker file: `storage/app/database_created`
- Cleared caches: `config:clear`, `view:clear`, `route:clear`

### 7. Server Start Attempts ❌

The Laravel server starts but immediately terminates when trying to curl. Process shows on port 3000 but doesn't respond to requests.

## Current State

- MySQL: ✅ Running, database imported
- Redis: ✅ Running (ping returns PONG)
- PHP: ✅ 8.3.6 with redis extension
- Node: ✅ Dependencies installed, built
- Laravel: Server process starts but doesn't respond

## Issue

The `php artisan serve --host=0.0.0.0 --port=3000` process appears in `ps` but curl/wget returns nothing. The server seems to exit immediately or not accept connections.

## How to Access App

URL: **http://localhost:3000**
Expected: Login page (not installation wizard - database is already imported)

## What Needs to Be Done

1. Fix the Laravel dev server issue so it actually responds to HTTP requests
2. Verify the login page loads
3. Provide admin credentials or password reset method

## Key Files

- App directory: `/home/hp/invoiceshelf-custom-rds`
- .env: `/home/hp/invoiceshelf-custom-rds/.env`
- Logs: `/home/hp/invoiceshelf-custom-rds/storage/logs/laravel.log`
- DB backup: `/home/hp/invoiceshelf-custom-rds/DB recovery - to be deleted after successful db import./db-dumps/mysql-invoiceshelf.sql`

## User Credentials

The database was imported from backup - need to either:

- Find admin credentials in the imported database, OR
- Reset admin password via Laravel tinker
