#!/bin/bash
#============================================================================
# Royal Dental Services - Custom Setup Entrypoint
# 
# This script runs on container startup and:
# - Sets up .env file
# - Creates fresh database structure
# - Generates APP_KEY
# - Runs migrations with UGX as currency_id = 1
# - Sets up storage permissions
#============================================================================

set -e

# Read version information
version=$(head -n 1 /var/www/html/version.md)

echo "
╔════════════════════════════════════════════════════════════════╗
║  Royal Dental Services - InvoiceShelf Custom                   ║
║  Version: $version                                             ║
╚════════════════════════════════════════════════════════════════╝
"

cd /var/www/html

# Step 1: Setup .env file
if [ ! -e /var/www/html/.env ]; then
    echo "→ Creating .env file from example..."
    cp .env.example .env
    /inject.sh
    echo "✓ .env file created"
else
    echo "→ .env file exists, updating environment variables..."
    /inject.sh
    echo "✓ Environment variables updated"
fi

# Step 2: Generate APP_KEY if not present
if ! grep -q "APP_KEY" /var/www/html/.env; then
    echo "→ Creating APP_KEY variable..."
    echo "$(printf "APP_KEY=\n"; cat /var/www/html/.env)" > /var/www/html/.env
fi

if ! grep -q '^APP_KEY=[^[:space:]]' /var/www/html/.env; then
    echo "→ Generating new APP_KEY..."
    chmod +x artisan
    ./artisan key:generate -n
    echo "✓ APP_KEY generated"
else
    echo "✓ APP_KEY already exists"
fi

# Step 3: Wait for database to be ready
if [ "$DB_CONNECTION" = "mysql" ] || [ "$DB_CONNECTION" = "mariadb" ]; then
    echo "→ Waiting for database to be ready..."
    counter=0
    max_attempts=30
    
    until mysql -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" -p"$DB_PASSWORD" -e "SELECT 1" > /dev/null 2>&1; do
        counter=$((counter + 1))
        if [ $counter -ge $max_attempts ]; then
            echo "✗ Database connection failed after $max_attempts attempts"
            exit 1
        fi
        echo "  Waiting for database... ($counter/$max_attempts)"
        sleep 2
    done
    echo "✓ Database is ready"
fi

# Step 4: Database setup
if [ "$DB_CONNECTION" = "sqlite" ]; then
    echo "→ Setting up SQLite database..."
    if [ -z "$DB_DATABASE" ]; then
        DB_DATABASE='/var/www/html/storage/app/database.sqlite'
    fi
    
    if [ ! -e "$DB_DATABASE" ]; then
        echo "  Creating SQLite database..."
        cp /var/www/html/database/stubs/sqlite.empty.db "$DB_DATABASE"
        chown www-data:www-data "$DB_DATABASE"
        echo "✓ SQLite database created"
    else
        echo "✓ SQLite database already exists"
    fi
fi

# Step 5: Check if installation is needed
echo "→ Checking installation status..."
TABLE_COUNT=$(mysql -h"$DB_HOST" -P"${DB_PORT:-3306}" -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" -sse "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB_DATABASE' AND table_name = 'migrations';" 2>/dev/null || echo "0")

if [ "$TABLE_COUNT" = "0" ]; then
    echo "→ Fresh installation detected. Database will be set up via installation wizard."
    echo "  Navigate to: http://your-domain/installation to complete setup"
    echo ""
    echo "  IMPORTANT: During installation wizard:"
    echo "    - Currency will be automatically seeded with UGX as ID 1"
    echo "    - Default company currency: UGX (Ugandan Shilling)"
    echo "    - All customizations are included"
else
    echo "✓ Installation already completed"
    
    # Run migrations for any updates
    echo "→ Running database migrations..."
    ./artisan migrate --force
    echo "✓ Migrations complete"
fi

# Step 6: Storage setup
echo "→ Setting up storage directories..."
./artisan storage:link 2>/dev/null || true
echo "✓ Storage linked"

# Step 7: Cache optimization
echo "→ Optimizing application..."
./artisan config:cache
./artisan route:cache
./artisan view:cache
echo "✓ Caches generated"

# Step 8: Set permissions
echo "→ Setting permissions..."
chmod +x artisan
chmod -R 775 storage bootstrap/cache 2>/dev/null || true
echo "✓ Permissions set"

echo "
╔════════════════════════════════════════════════════════════════╗
║  ✅ Setup Complete - InvoiceShelf is Ready                     ║
╚════════════════════════════════════════════════════════════════╝

Access InvoiceShelf at: ${APP_URL:-http://localhost:8080}

Features included in this custom build:
  ✓ UGX as primary currency (currency_id = 1)
  ✓ Patient fields support
  ✓ Base amount calculations
  ✓ Royal Dental Services branding
  ✓ All production fixes applied

"
