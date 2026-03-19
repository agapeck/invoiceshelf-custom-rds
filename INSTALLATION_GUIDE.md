# InvoiceShelf Custom - Fresh Installation Guide (NGINX/PHP-FPM)

This guide covers installing the **InvoiceShelf Custom** application on a fresh Linux Mint (or Ubuntu/Debian) system using NGINX and PHP-FPM, with the installation wizard and a new MySQL/MariaDB database.

It also includes production-grade, resource-aware tuning for MySQL, Redis, PHP-FPM, and queue workers so a fresh install starts optimized across all layers.

## Prerequisites

- Linux Mint 21+ / Ubuntu 22.04+ / Debian 12+
- Root or sudo access
- At least 2GB RAM, 10GB disk space (4GB+ RAM recommended for production)

---

## Step 1: Install System Dependencies

```bash
# Update system packages
sudo apt update && sudo apt upgrade -y

# Install MariaDB (or MySQL)
sudo apt install -y mariadb-server mariadb-client

# Install NGINX
sudo apt install -y nginx

# Install Redis
sudo apt install -y redis-server

# Install required tools
sudo apt install -y git curl zip unzip sqlite3 acl

# Install PHP 8.2/8.3 and required extensions
sudo apt install -y php8.2-fpm php8.2-cli php8.2-mysql php8.2-gd php8.2-exif \
    php8.2-mbstring php8.2-zip php8.2-curl php8.2-bcmath php8.2-xml php8.2-intl \
    php8.2-readline php8.2-imagick php8.2-redis

# If PHP 8.2 is not available, use PHP 8.3:
# sudo apt install -y php8.3-fpm php8.3-cli php8.3-mysql php8.3-gd php8.3-exif \
#     php8.3-mbstring php8.3-zip php8.3-curl php8.3-bcmath php8.3-xml php8.3-intl \
#     php8.3-readline php8.3-imagick php8.3-redis
```

### Install Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
sudo chmod +x /usr/local/bin/composer
```

### Install Node.js (via NVM)

```bash
curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.5/install.sh | bash
source ~/.bashrc
nvm install 20
nvm use 20
```

---

## Step 2: Configure MariaDB

```bash
# Secure MariaDB installation
sudo mysql_secure_installation
```

Answer the prompts:
- Set root password: **Yes** (remember this password!)
- Remove anonymous users: **Yes**
- Disallow root login remotely: **Yes**
- Remove test database: **Yes**
- Reload privilege tables: **Yes**

### Create Database and User

```bash
sudo mysql -u root -p
```

Run these SQL commands (replace `your_secure_password` with a strong password):

```sql
CREATE DATABASE invoiceshelf CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'invoiceshelf'@'localhost' IDENTIFIED BY 'your_secure_password';
CREATE USER 'invoiceshelf'@'127.0.0.1' IDENTIFIED BY 'your_secure_password';
GRANT ALL PRIVILEGES ON invoiceshelf.* TO 'invoiceshelf'@'localhost';
GRANT ALL PRIVILEGES ON invoiceshelf.* TO 'invoiceshelf'@'127.0.0.1';

-- Optional but recommended for MySQL-backed tests:
CREATE DATABASE invoiceshelf_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON invoiceshelf_test.* TO 'invoiceshelf'@'localhost';
GRANT ALL PRIVILEGES ON invoiceshelf_test.* TO 'invoiceshelf'@'127.0.0.1';

FLUSH PRIVILEGES;
EXIT;
```

### Apply MySQL/MariaDB Tuning (Resource-Aware)

Use a dedicated drop-in file so package updates do not overwrite your tuning:

```bash
sudo nano /etc/mysql/conf.d/invoiceshelf-tuning.cnf
```

Paste and adjust values based on available RAM and workload:

```ini
[mysqld]
max_connections = 250
innodb_buffer_pool_size = 1G
innodb_buffer_pool_instances = 1
innodb_log_file_size = 256M
innodb_log_buffer_size = 32M
innodb_flush_log_at_trx_commit = 1
sync_binlog = 1
innodb_flush_method = O_DIRECT
tmp_table_size = 64M
max_heap_table_size = 64M
thread_cache_size = 64
table_open_cache = 4000
skip_name_resolve = ON
slow_query_log = ON
slow_query_log_file = /var/log/mysql/mysql-slow.log
long_query_time = 1
```

Sizing guidance:

- Shared host (DB + app + Redis on same VM): set `innodb_buffer_pool_size` to about 20-35% of RAM.
- Dedicated DB host: set `innodb_buffer_pool_size` to about 50-65% of RAM.
- Keep `tmp_table_size` and `max_heap_table_size` equal (typically 32M-128M).
- Increase `max_connections` only if you also scale PHP-FPM workers and monitor memory.

Apply and verify:

```bash
sudo systemctl restart mysql
mysql -u invoiceshelf -p -h 127.0.0.1 -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size'; SHOW VARIABLES LIKE 'tmp_table_size'; SHOW VARIABLES LIKE 'max_connections';"
```

### Configure Redis for Sessions/Cache/Queues

Edit Redis config:

```bash
sudo nano /etc/redis/redis.conf
```

Recommended baseline (resource-aware):

```conf
appendonly yes
appendfsync everysec
maxmemory 256mb
maxmemory-policy noeviction
timeout 0
tcp-keepalive 300
```

Redis sizing guidance:

- 2 GB RAM host: `maxmemory 128mb`
- 4 GB RAM host: `maxmemory 256mb`
- 8 GB RAM host: `maxmemory 512mb`
- 16 GB+ RAM host: start at `maxmemory 1gb`, then adjust from real usage

`noeviction` is safest when Redis stores sessions and queues. If Redis is cache-only, consider `allkeys-lru`.

Apply and verify:

```bash
sudo systemctl restart redis-server
redis-cli ping
redis-cli CONFIG GET appendonly appendfsync maxmemory maxmemory-policy
```

---

## Step 3: Clone the Repository

```bash
# Navigate to web directory
cd /var/www

# Clone the custom InvoiceShelf repository
sudo git clone https://github.com/agapeck/invoiceshelf-custom-rds.git invoiceshelf

# Or if you have local files, copy them:
# sudo cp -r /path/to/invoiceshelf-custom-rds /var/www/invoiceshelf

# Set ownership
sudo chown -R www-data:www-data /var/www/invoiceshelf

# Set permissions
cd /var/www/invoiceshelf
sudo chmod -R 775 storage bootstrap/cache
sudo chmod -R 775 storage/framework storage/logs
```

---

## Step 4: Configure Environment

```bash
cd /var/www/invoiceshelf

# Copy example environment file
sudo cp .env.example .env

# Edit the environment file
sudo nano .env
```

Update these values in `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_KEY=

APP_NAME="InvoiceShelf"
APP_TIMEZONE=Africa/Nairobi
APP_URL=http://your-server-ip
APP_LOCALE=en

# Database Configuration (MySQL/MariaDB)
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=invoiceshelf
DB_USERNAME=invoiceshelf
DB_PASSWORD=your_secure_password

SESSION_DOMAIN=null
SANCTUM_STATEFUL_DOMAIN=your-server-ip
TRUSTED_PROXIES="*"

# Production defaults (recommended)
SESSION_DRIVER=redis
CACHE_STORE=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_CACHE_DB=1
```

**Important Notes:**
- Replace `your-server-ip` with your actual server IP (e.g., `192.168.1.100`)
- Replace `your_secure_password` with the password you set in Step 2
- Set `APP_TIMEZONE` to your local timezone
- For very small hosts (2 GB RAM) you can temporarily use `QUEUE_CONNECTION=database`, but keep Redis for session/cache.

---

## Step 5: Install PHP Dependencies

```bash
cd /var/www/invoiceshelf

# Install as www-data user to avoid permission issues
sudo -u www-data composer install --no-dev --optimize-autoloader
```

If you get memory issues:
```bash
sudo php -d memory_limit=-1 /usr/local/bin/composer install --no-dev --optimize-autoloader
```

---

## Step 6: Install Node Dependencies & Build Assets

```bash
cd /var/www/invoiceshelf

# Install npm packages
npm install

# Build for production
npm run prod
```

---

## Step 7: Generate Application Key

```bash
cd /var/www/invoiceshelf
sudo -u www-data php artisan key:generate
```

**⚠️ IMPORTANT:** After running this, back up your `.env` file! The `APP_KEY` is critical for security and hash generation. If it changes, all PDF links and secure URLs will break.

---

## Step 8: Set Up Storage Link

```bash
cd /var/www/invoiceshelf
sudo -u www-data php artisan storage:link
```

---

## Step 9: Configure NGINX

Create a new NGINX site configuration:

```bash
sudo nano /etc/nginx/sites-available/invoiceshelf
```

Paste the following configuration:

```nginx
server {
    listen 80;
    listen [::]:80;
    
    # Replace with your server IP or domain
    server_name 192.168.1.100;
    
    root /var/www/invoiceshelf/public;
    index index.php index.html index.htm;

    # Security headers
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    charset utf-8;

    # Max upload size (for importing data)
    client_max_body_size 50M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    # Deny access to sensitive files
    location ~ /\.(?!well-known).* {
        deny all;
    }

    location ~ /\.env {
        deny all;
    }
}
```

**Note:** Replace `192.168.1.100` with your server's IP address and `php8.2-fpm.sock` with `php8.3-fpm.sock` if using PHP 8.3.

Enable the site and restart NGINX:

```bash
# Enable the site
sudo ln -s /etc/nginx/sites-available/invoiceshelf /etc/nginx/sites-enabled/

# Remove default site (optional)
sudo rm /etc/nginx/sites-enabled/default

# Test configuration
sudo nginx -t

# Restart NGINX
sudo systemctl restart nginx
```

---

## Step 10: Configure PHP-FPM

Detect your active PHP-FPM version:

```bash
php -v | head -n 1
```

Create a pool tuning override (recommended instead of editing default `www.conf`):

```bash
# Replace 8.2 with your installed version if needed (for example 8.3)
sudo nano /etc/php/8.2/fpm/pool.d/zz-invoiceshelf-tuning.conf
```

Suggested baseline (resource-aware for shared app host):

```ini
[www]
user = www-data
group = www-data
listen = /var/run/php/php8.2-fpm.sock
listen.owner = www-data
listen.group = www-data

pm = dynamic
pm.max_children = 16
pm.start_servers = 4
pm.min_spare_servers = 2
pm.max_spare_servers = 6
pm.max_requests = 500
request_terminate_timeout = 120s
catch_workers_output = yes
```

Sizing guidance for `pm.max_children`:

- Start with: `(RAM available for PHP-FPM) / (average worker MB)`.
- Typical Laravel worker memory is 80-150 MB.
- Example: 2 GB reserved for PHP and ~120 MB/worker gives about 16 workers.

Create PHP runtime tuning override:

```bash
sudo nano /etc/php/8.2/fpm/conf.d/99-invoiceshelf-performance.ini
```

```ini
memory_limit = 256M
max_execution_time = 120

opcache.enable = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 50000
opcache.max_wasted_percentage = 10
opcache.validate_timestamps = 0
opcache.revalidate_freq = 0
opcache.save_comments = 1
opcache.jit = off

realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

OPcache sizing guidance:

- 2-4 GB host: `opcache.memory_consumption=128-192`
- 8 GB host: `opcache.memory_consumption=256`
- 16 GB+ host: `opcache.memory_consumption=256-512`

Validate and restart PHP-FPM:

```bash
# Replace 8.2 with your installed version if needed
sudo php-fpm8.2 -tt
sudo systemctl restart php8.2-fpm
sudo systemctl is-active php8.2-fpm
```

---

## Step 11: Final Permissions Check

```bash
cd /var/www/invoiceshelf

# Ensure proper ownership
sudo chown -R www-data:www-data .

# Set directory permissions
sudo find . -type d -exec chmod 755 {} \;
sudo find . -type f -exec chmod 644 {} \;

# Make storage and bootstrap/cache writable
sudo chmod -R 775 storage bootstrap/cache
sudo chown -R www-data:www-data storage bootstrap/cache
```

---

## Step 12: Access the Installation Wizard

1. Open a browser on any PC connected to your LAN
2. Navigate to: `http://your-server-ip` (e.g., `http://192.168.1.100`)
3. You should see the **InvoiceShelf Installation Wizard**

### Installation Wizard Steps:

1. **Requirements Check** - Verify all PHP extensions are installed
2. **Database Setup** - Enter your database credentials:
   - Database Host: `127.0.0.1`
   - Database Port: `3306`
   - Database Name: `invoiceshelf`
   - Database Username: `invoiceshelf`
   - Database Password: `your_secure_password`
3. **Company Setup** - Enter your company details
4. **Admin User** - Create the first admin account
5. **Complete** - Installation finished!

---

## Step 13: Post-Installation Tasks

### Set Up Cron Job for Scheduled Tasks

```bash
sudo crontab -u www-data -e
```

Add this line:

```cron
* * * * * cd /var/www/invoiceshelf && php artisan schedule:run >> /dev/null 2>&1
```

### Enable Redis Queue Worker (Systemd)

Create service:

```bash
sudo nano /etc/systemd/system/invoiceshelf-queue.service
```

```ini
[Unit]
Description=InvoiceShelf Queue Worker
After=network.target mysql.service redis-server.service
Wants=redis-server.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/invoiceshelf
ExecStart=/usr/bin/php /var/www/invoiceshelf/artisan queue:work redis --sleep=1 --tries=3 --timeout=120 --max-time=3600 --queue=default
ExecStop=/usr/bin/php /var/www/invoiceshelf/artisan queue:restart
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```

Enable and start:

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now invoiceshelf-queue.service
sudo systemctl is-active invoiceshelf-queue.service
```

### Build Laravel Caches for Production

```bash
cd /var/www/invoiceshelf
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```

### Verify Runtime Stack Health

```bash
cd /var/www/invoiceshelf
sudo -u www-data php artisan tinker --execute="dump(config('session.driver')); dump(config('cache.default')); dump(config('queue.default'));"
mysql -u invoiceshelf -p -h 127.0.0.1 -e "SHOW VARIABLES LIKE 'innodb_buffer_pool_size'; SHOW VARIABLES LIKE 'tmp_table_size'; SHOW VARIABLES LIKE 'max_connections';"
redis-cli ping
```

### Configure S3 Cloud Backups (Recommended)

For automatic cloud backups, configure an S3 disk after completing the wizard:

1. Go to **Settings** → **File Disk** → **Add Disk**
2. Select **Amazon S3** and enter your AWS credentials
3. Backups will automatically run 5 times daily (see "Automatic S3 Cloud Backups" section below)

### Configure Firewall (if enabled)

```bash
sudo ufw allow 'Nginx HTTP'
sudo ufw allow 80/tcp
```

---

## Cloud/VPS Production Notes (Cloudflare + UFW + Redis)

This section is for public cloud hosting (AWS, Hetzner, DigitalOcean, etc.) where traffic may pass through Cloudflare and Redis is used for cache/session/queue.

### 1) Choose DNS/SSL mode first

Your TLS certificate and firewall policy must match your Cloudflare DNS mode:

- **Orange cloud (proxied)**:
  - NGINX certificate can be **Cloudflare Origin Certificate**
  - Cloudflare SSL mode: **Full (strict)**
  - UFW for `80/443` should allow **Cloudflare IP ranges only**
- **Grey cloud (DNS only)**:
  - NGINX certificate must be **publicly trusted** (for example Let's Encrypt)
  - Cloudflare Origin cert will show browser TLS warnings in DNS-only mode
  - UFW for `80/443` must allow **public client IPs**

If DNS mode and cert type do not match, the app may look broken even when Laravel itself is healthy.

### 2) Cloudflare + wizard troubleshooting

If browser console shows CSP report-only errors like `script-src 'none'` / `connect-src 'none'`, or Cloudflare challenge responses, check edge behavior first.

- Temporarily disable challenge/protection for:
  - `/installation*`
  - `/api/v1/installation*`
  - `/sanctum/csrf-cookie`
- Confirm origin behavior directly from server:

```bash
curl -k -I -H 'Host: your-domain.com' https://127.0.0.1/installation
curl -k -s -H 'Host: your-domain.com' https://127.0.0.1/api/v1/installation/wizard-step
```

If origin is healthy but public URL fails, the blocker is edge/WAF/DNS mode, not Laravel core.

### 3) Mandatory preflight checks before running the wizard

Run these checks first:

```bash
cd /var/www/invoiceshelf

# Verify DB credentials exactly match MySQL user/password
mysql -u invoiceshelf -p -h 127.0.0.1 -e "select 1;"

# Verify app can run artisan without DB/auth failures
sudo -u www-data php artisan about

# Verify write paths
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache

# Ensure APP_KEY exists
grep '^APP_KEY=' .env
```

### 4) Redis production profile (recommended)

For this repository's production setup:

```dotenv
SESSION_DRIVER=redis
CACHE_STORE=redis
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=null
REDIS_DB=0
REDIS_CACHE_DB=1
```

Also ensure Redis durability is enabled:

```bash
redis-cli CONFIG SET appendonly yes
redis-cli CONFIG SET appendfsync everysec
redis-cli CONFIG REWRITE
```

Quick health checks:

```bash
cd /var/www/invoiceshelf
sudo -u www-data env HOME=/tmp php artisan tinker --execute="echo 'redis='.(\Illuminate\Support\Facades\Redis::ping()).PHP_EOL; echo 'db='.(\Illuminate\Support\Facades\DB::select('select 1 as ok')[0]->ok).PHP_EOL;"
sudo systemctl is-active invoiceshelf-queue.service
redis-cli CONFIG GET appendonly appendfsync maxmemory maxmemory-policy
```

Resource-aware host checks (recommended after deploy):

```bash
# MySQL
mysql -u invoiceshelf -p -h 127.0.0.1 -e "SHOW VARIABLES WHERE Variable_name IN ('innodb_buffer_pool_size','tmp_table_size','max_heap_table_size','max_connections','thread_cache_size');"

# PHP-FPM (replace version if needed)
php-fpm8.2 -i | grep -E "memory_limit|max_execution_time|opcache.memory_consumption|opcache.max_accelerated_files|opcache.validate_timestamps|realpath_cache_size|realpath_cache_ttl"
# If on PHP 8.3, use php-fpm8.3 instead

# Redis memory/eviction
redis-cli INFO memory | grep -E "used_memory_human|maxmemory_human|mem_fragmentation_ratio"
```

### 5) Known installer blocker in this custom branch

If Step 3 (Site URL & Database) crashes during migration with `file_disks.credentials` JSON/check constraint errors:

- Ensure migration `database/migrations/2020_12_02_090527_update_crater_version_400.php` seeds `file_disks` with `DB::table()->insert(...)` (raw JSON), not model writes that may encrypt credentials too early.

### 6) Manual non-wizard recovery (fresh install fallback)

If wizard flow remains blocked and you need a clean install immediately:

```bash
cd /var/www/invoiceshelf
sudo -u www-data php artisan down
sudo -u www-data env HOME=/tmp php artisan migrate:fresh --seed --force
```

Then set:
- admin user details
- company name/address/contact
- `settings.profile_complete = COMPLETED`
- `settings.profile_language = en` (or your language)
- `storage/app/database_created` marker file

Finally:

```bash
sudo -u www-data php artisan optimize:clear
sudo -u www-data php artisan up
```

Use this fallback only when you explicitly want a fresh/empty dataset.

### 7) Post-install security closeout (cloud)

- Change default admin password immediately
- Keep `APP_DEBUG=false`
- If SMTP is not ready, set `MAIL_MAILER=log` until configured
- Keep Cloudflare mode/certificate/UFW aligned
- Keep `/installation` inaccessible in normal operation (app should redirect when completed)

---

## Troubleshooting

### Common Issues:

1. **502 Bad Gateway**
   ```bash
   sudo systemctl restart php8.2-fpm   # or php8.3-fpm
   sudo systemctl restart nginx
   ```

2. **Permission Denied Errors**
   ```bash
   sudo chown -R www-data:www-data /var/www/invoiceshelf
   sudo chmod -R 775 /var/www/invoiceshelf/storage
   ```

3. **Database Connection Failed**
   - Verify credentials in `.env`
   - Test connection: `mysql -u invoiceshelf -p invoiceshelf`

4. **Blank Page / 500 Error**
   ```bash
   # Check Laravel logs
   sudo tail -f /var/www/invoiceshelf/storage/logs/laravel.log
   
   # Clear caches
   sudo -u www-data php artisan config:clear
   sudo -u www-data php artisan cache:clear
   sudo -u www-data php artisan view:clear
   ```

5. **Assets Not Loading**
   ```bash
   cd /var/www/invoiceshelf
   npm run prod
   sudo -u www-data php artisan storage:link
   ```

---

## LAN Multi-User Setup

For your Wakanet 5G router LAN setup:

1. **Set Static IP** for the server in your router's DHCP settings
2. **Access URL**: All LAN users connect to `http://server-ip`
3. **No Internet Required** - All assets are served locally

### Recommended Server Settings:

Add to your router's DHCP reservation:
- Server MAC Address → Fixed IP (e.g., `192.168.1.100`)

---

## Backup Your Installation

```bash
# Backup database
mysqldump -u invoiceshelf -p invoiceshelf > ~/invoiceshelf_backup_$(date +%Y%m%d).sql

# Backup application files
sudo tar -czf ~/invoiceshelf_files_$(date +%Y%m%d).tar.gz /var/www/invoiceshelf

# Backup .env file separately (contains APP_KEY!)
sudo cp /var/www/invoiceshelf/.env ~/invoiceshelf_env_backup
```

---

## Summary

Your InvoiceShelf installation is now ready:

- **URL**: `http://your-server-ip`
- **Database**: MariaDB with proper concurrency protection
- **Multi-User**: Safe for concurrent LAN access
- **Features**: Appointments, Invoices, Payments, Customers all protected against race conditions

Happy invoicing! 🧾

---

## Restoring from Database Backup (Alternative to Fresh Install)

If you have a database backup from a previous InvoiceShelf installation, follow these steps instead of running the installation wizard.

### Prerequisites
- Completed Steps 1-8 above (system dependencies, database, clone, environment, composer, npm, key, storage link)
- A database backup file (`.sql` or `.zip` containing `.sql`)

### Step A: Import Your Database Backup

```bash
# If your backup is from InvoiceShelf app (zip file with db-dumps folder):
unzip only-db-2025-12-10-19-28-04.zip
mysql -u invoiceshelf -p invoiceshelf < db-dumps/mysql-invoiceshelf.sql

# If your backup is a plain .sql file:
mysql -u invoiceshelf -p invoiceshelf < your-backup.sql
```

### Step B: Run Migrations (for any schema updates)

```bash
cd /var/www/invoiceshelf
sudo -u www-data php artisan migrate --force
```

### Step C: Create the Database Marker File

The application checks for this file to determine if the database is set up. Without it, you'll be redirected to the installation wizard.

```bash
echo "$(date +%s)" > /var/www/invoiceshelf/storage/app/database_created
sudo chown www-data:www-data /var/www/invoiceshelf/storage/app/database_created
```

### Step D: Regenerate Hashes (Required after APP_KEY change)

Since your new installation has a different `APP_KEY` than your backup, all unique hashes need to be regenerated for PDF URLs to work.

```bash
cd /var/www/invoiceshelf
php fix_regenerate_all_hashes.php
```

If you encounter hash collisions (duplicate key errors), run:

```bash
php fix_collision_hashes.php
```

**Note:** The `fix_collision_hashes.php` script may need to be updated with the specific IDs that failed. Check the output of `fix_regenerate_all_hashes.php` for failed IDs.

### Step E: Clear Caches and Restart Services

```bash
cd /var/www/invoiceshelf
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan cache:clear
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan route:clear

sudo systemctl restart php8.2-fpm nginx   # or php8.3-fpm nginx
```

### Step F: Verify Installation

1. Open browser to `http://your-server-ip`
2. You should see the login page (not the wizard)
3. Log in with your existing credentials from the backup

### Troubleshooting Database Restoration

1. **Still seeing installation wizard?**
   - Verify the marker file exists: `ls -la storage/app/database_created`
   - Check database has data: `mysql -u invoiceshelf -p -e "SELECT COUNT(*) FROM invoiceshelf.users;"`

2. **PDF links not working?**
   - Run hash regeneration again
   - Check Laravel logs for errors

3. **Login not working?**
   - Your password hash is preserved, use your old password
   - If forgotten, reset via: `php artisan tinker` then update user password

---

## Automatic S3 Cloud Backups

InvoiceShelf Custom includes **internet-detection-based** automatic database backups to AWS S3. Instead of fixed times, backups run opportunistically when internet is available:

| Feature | Behavior |
|---------|----------|
| **Check Frequency** | Every minute (24/7) |
| **Internet Detection** | Only attempts backup when internet connection is detected |
| **Minimum Interval** | At least 4 hours between successful backups |
| **Backup Type** | Database-only (smaller, faster uploads) |

This approach ensures backups succeed even with intermittent internet connectivity, rather than failing silently at fixed times when offline.

### How to Enable Automatic S3 Backups

**Step 1: Configure S3 Disk in InvoiceShelf**

1. Log in as admin
2. Go to **Settings** → **File Disk**
3. Click **Add Disk**
4. Select **Amazon S3** as driver
5. Enter your AWS credentials:
   - **Key**: Your AWS Access Key ID
   - **Secret**: Your AWS Secret Access Key
   - **Region**: e.g., `eu-central-1`, `us-east-1`
   - **Bucket**: Your S3 bucket name
   - **Root**: `/` (or a subfolder like `/backups`)
6. Save the disk

**Step 2: Ensure Cron Job is Running**

The scheduler must be running for automatic backups. Add to crontab if not already done:

```bash
# For development/home setup (user crontab)
crontab -e
# Add: * * * * * cd /home/youruser/invoiceshelf-custom && php artisan schedule:run >> /dev/null 2>&1

# For production (www-data crontab)
sudo crontab -u www-data -e
# Add: * * * * * cd /var/www/invoiceshelf && php artisan schedule:run >> /dev/null 2>&1
```

**Step 3: Verify Scheduled Backups**

```bash
cd /var/www/invoiceshelf
php artisan schedule:list
```

You should see `backup:s3-scheduled --check-interval` entry running every 30 minutes if S3 disk is configured.

### Manual S3 Backup

To trigger a backup manually:

```bash
php artisan backup:s3-scheduled
```

With verbose output:
```bash
php artisan backup:s3-scheduled -v
```

Skip internet check (force backup):
```bash
php artisan backup:s3-scheduled --skip-internet-check
```

### How It Works

- **Internet Detection**: Checks AWS S3 endpoint (with Google DNS fallback) before attempting backup
- **Smart Scheduling**: Checks every minute, but only backs up if 4+ hours since last successful backup (tracked in `storage/app/last_s3_backup.txt`)
- **Automatic S3 Discovery**: Uses first configured S3 disk, or specify with `--disk-name`
- **Database Only**: Scheduled backups are database-only (smaller, faster)
- **24/7 Monitoring**: Backup checks run continuously, not limited to business hours
- **No Overlap**: Won't start a new backup if one is still running

### Backup Encryption (Recommended)

For additional security, encrypt your backup archives with a password. Add to your `.env` file:

```dotenv
BACKUP_ARCHIVE_PASSWORD=your-secure-password-here
```

> [!IMPORTANT]
> **Store this password securely!** If you lose it, you won't be able to restore encrypted backups.
> Consider using a password manager or secure vault for this critical credential.

Benefits of encryption:
- **Data Protection**: Even if S3 bucket is compromised, data remains encrypted
- **Compliance**: Helps meet data protection requirements (GDPR, HIPAA, etc.)
- **Defense in Depth**: Adds extra layer beyond S3 bucket policies

### AWS S3 Setup Tips

1. **Create a dedicated S3 bucket** for InvoiceShelf backups
2. **Create an IAM user** with only S3 permissions for this bucket
3. **Enable versioning** on the bucket for extra safety
4. **Set lifecycle rules** to move old backups to Glacier after 30 days

Example IAM policy:
```json
{
    "Version": "2012-10-17",
    "Statement": [
        {
            "Effect": "Allow",
            "Action": [
                "s3:PutObject",
                "s3:GetObject",
                "s3:DeleteObject",
                "s3:ListBucket"
            ],
            "Resource": [
                "arn:aws:s3:::your-bucket-name",
                "arn:aws:s3:::your-bucket-name/*"
            ]
        }
    ]
}
```

### Viewing Backups

All automatic backups appear in **Settings** → **Backup** in the InvoiceShelf UI, alongside any manual backups.

---

## Monthly Maintenance Reminder

InvoiceShelf Custom includes an automatic **monthly maintenance reminder** system that displays popup notifications to logged-in admin users, reminding them to contact the IT team for scheduled maintenance.

### How It Works

| Feature | Behavior |
|---------|----------|
| **Auto Trigger** | Activates on the **28th of each month** at midnight via cron |
| **Notification Frequency** | Shows every **10 minutes** while user is logged in |
| **Notification Type** | Info popup (blue) - same style as other app notifications |
| **Persistence** | Continues until manually cleared |

### Prerequisites

The Laravel scheduler must be running for the reminder to auto-trigger. Ensure this cron entry exists:

```bash
# Check current crontab
sudo crontab -u www-data -l

# If missing, add it:
sudo crontab -u www-data -e
# Add: * * * * * cd /var/www/invoiceshelf && php artisan schedule:run >> /dev/null 2>&1
```

### Verify Schedule is Configured

```bash
cd /var/www/invoiceshelf
php artisan schedule:list | grep maintenance
```

Expected output:
```
0 0 28 * *  php artisan maintenance:trigger-reminder  Next Due: XX days from now
```

### Manual Control

**Activate the reminder immediately:**
```bash
php artisan maintenance:trigger-reminder
```

**Clear/Deactivate the reminder:**
```bash
php artisan maintenance:clear-reminder
```

### Customization

The notification message can be customized by editing:
- **File**: `resources/scripts/admin/layouts/LayoutBasic.vue`
- **Function**: `showMaintenanceReminder()`

To change the schedule (e.g., run on the 1st instead of 28th):
- **File**: `routes/console.php`
- **Find**: `->monthlyOn(28, '00:00')`
- **Change to**: `->monthlyOn(1, '00:00')` for 1st of month

---

## Development Environment Auto-Start Services

For **development/home setups** where you run InvoiceShelf locally (not on a production server with NGINX), you can configure the Laravel dev server and scheduler to start automatically when you log in.

This uses **systemd user services** which:
- Start when you log in (not on system boot)
- Run as your user (no sudo required)
- Auto-restart if they crash

### Step 1: Create Service Files

```bash
# Create user systemd directory
mkdir -p ~/.config/systemd/user

# Create dev server service
cat > ~/.config/systemd/user/invoiceshelf.service << 'EOF'
[Unit]
Description=InvoiceShelf Laravel Dev Server
After=network.target

[Service]
Type=simple
WorkingDirectory=/home/YOUR_USERNAME/invoiceshelf-custom
ExecStart=/usr/bin/php artisan serve --host=0.0.0.0 --port=8000
Restart=always
RestartSec=5

[Install]
WantedBy=default.target
EOF

# Create scheduler service (for backups, maintenance reminders, etc.)
cat > ~/.config/systemd/user/invoiceshelf-scheduler.service << 'EOF'
[Unit]
Description=InvoiceShelf Laravel Scheduler
After=network.target

[Service]
Type=simple
WorkingDirectory=/home/YOUR_USERNAME/invoiceshelf-custom
ExecStart=/bin/bash -c 'while true; do /usr/bin/php artisan schedule:run --verbose --no-interaction; sleep 60; done'
Restart=always
RestartSec=5

[Install]
WantedBy=default.target
EOF
```

> [!IMPORTANT]
> Replace `YOUR_USERNAME` with your actual Linux username and adjust the path if your installation is in a different location.

### Step 2: Enable and Start Services

```bash
# Reload systemd to pick up new services
systemctl --user daemon-reload

# Enable services to start on login
systemctl --user enable invoiceshelf.service invoiceshelf-scheduler.service

# Start services now
systemctl --user start invoiceshelf.service invoiceshelf-scheduler.service
```

### Step 3: Verify Services

```bash
# Check status
systemctl --user status invoiceshelf.service
systemctl --user status invoiceshelf-scheduler.service
```

You should see both services as **active (running)**.

### Useful Commands

| Command | Description |
|---------|-------------|
| `systemctl --user status invoiceshelf.service` | Check dev server status |
| `systemctl --user restart invoiceshelf.service` | Restart dev server |
| `systemctl --user stop invoiceshelf.service` | Stop dev server |
| `journalctl --user -u invoiceshelf.service -f` | View server logs |
| `journalctl --user -u invoiceshelf-scheduler.service -f` | View scheduler logs |

### Accessing the Application

Once services are running, access InvoiceShelf at:
- **Local**: `http://localhost:8000`
- **LAN**: `http://your-computer-ip:8000`

---
