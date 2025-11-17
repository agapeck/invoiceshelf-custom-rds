# Royal Dental Services - Custom InvoiceShelf Docker Deployment

This is a production-ready Docker setup for InvoiceShelf with all customizations for Royal Dental Services.

## Features Included

✅ **Currency Configuration**
- UGX (Ugandan Shilling) as primary currency with currency_id = 1
- Matches Crater database structure for seamless migration
- Updated currency seeder

✅ **Patient Fields**
- Patient diagnosis, treatment, attended_to_by fields
- Age, next of kin support
- Review date tracking

✅ **Production Fixes**
- Base amount calculations for multi-currency support
- Hash generation with 30-character ultra-robust algorithm
- All financial calculations optimized

✅ **Branding**
- Royal Dental Services customization
- Custom logos and assets included

## Quick Start

### 1. Prerequisites
- Docker 20.10+ installed
- Docker Compose 2.0+ installed
- 2GB RAM minimum, 4GB recommended
- 10GB disk space for database and storage

### 2. Initial Setup

```bash
cd docker/custom-production

# Copy environment example
cp .env.docker.example .env.docker

# Edit .env.docker and set your passwords and domain
nano .env.docker

# Build and start containers
docker-compose --env-file .env.docker up -d --build
```

### 3. Complete Installation

Navigate to: `http://localhost:8080/installation`

Follow the installation wizard:
- Database is already configured (via environment variables)
- Set up admin account
- Configure company details
- **Currency will be automatically set to UGX**

### 4. Verify Installation

```bash
# Check container status
docker-compose ps

# View logs
docker-compose logs -f webapp

# Check database
docker-compose exec database mysql -u invoiceshelf -p invoiceshelf
```

## Container Management

### Start/Stop Containers

```bash
# Start
docker-compose --env-file .env.docker up -d

# Stop
docker-compose --env-file .env.docker down

# Stop and remove volumes (WARNING: deletes all data!)
docker-compose --env-file .env.docker down -v
```

### View Logs

```bash
# All containers
docker-compose logs -f

# Just webapp
docker-compose logs -f webapp

# Just database
docker-compose logs -f database
```

### Execute Commands

```bash
# Laravel artisan commands
docker-compose exec webapp php artisan migrate

# Database backup
docker-compose exec database mysqldump -u invoiceshelf -p invoiceshelf > backup.sql

# Enter container
docker-compose exec webapp bash
```

## Data Persistence

Data is stored in Docker volumes:
- `invoiceshelf_mysql_data` - Database files
- `invoiceshelf_storage` - Uploaded files, PDFs, backups
- `invoiceshelf_public` - Public storage symlink

### Backup Strategy

```bash
# Backup database
docker-compose exec database mysqldump -u invoiceshelf -p'YOUR_PASSWORD' invoiceshelf > backup-$(date +%Y%m%d).sql

# Backup storage volume
docker run --rm -v invoiceshelf_storage:/data -v $(pwd):/backup ubuntu tar czf /backup/storage-backup-$(date +%Y%m%d).tar.gz /data

# Backup all volumes
docker-compose down
docker run --rm -v invoiceshelf_mysql_data:/mysql -v invoiceshelf_storage:/storage -v $(pwd):/backup ubuntu tar czf /backup/full-backup-$(date +%Y%m%d).tar.gz /mysql /storage
docker-compose up -d
```

## Production Deployment

### Security Checklist

- [ ] Change default passwords in `.env.docker`
- [ ] Use strong DB_PASSWORD and DB_ROOT_PASSWORD
- [ ] Set APP_ENV=production and APP_DEBUG=false
- [ ] Configure HTTPS (use nginx-proxy with Let's Encrypt)
- [ ] Restrict database port (don't expose 3306)
- [ ] Set up firewall rules
- [ ] Enable Docker logging driver
- [ ] Configure automatic backups
- [ ] Set up monitoring (Uptime Kuma, Prometheus, etc.)

### HTTPS Setup (Recommended)

Use nginx-proxy with Let's Encrypt:

```bash
# Clone nginx-proxy
git clone https://github.com/nginx-proxy/nginx-proxy
cd nginx-proxy

# Start reverse proxy
docker-compose up -d

# Update InvoiceShelf docker-compose.yml to add:
environment:
  - VIRTUAL_HOST=invoices.yourdomain.com
  - LETSENCRYPT_HOST=invoices.yourdomain.com
  - LETSENCRYPT_EMAIL=admin@yourdomain.com
```

### Resource Limits

For production, add resource limits:

```yaml
services:
  webapp:
    deploy:
      resources:
        limits:
          cpus: '2'
          memory: 2G
        reservations:
          memory: 512M
```

## Troubleshooting

### Container won't start

```bash
# Check logs
docker-compose logs webapp

# Common issues:
# 1. Port 8080 already in use - change APP_PORT in .env.docker
# 2. Database connection failed - check DB_PASSWORD matches
# 3. Permission denied - run: chmod -R 775 storage bootstrap/cache
```

### Database connection refused

```bash
# Check database is running
docker-compose ps database

# Check database logs
docker-compose logs database

# Test connection
docker-compose exec webapp php artisan tinker
>>> DB::connection()->getPdo();
```

### APP_KEY not generated

```bash
# Generate manually
docker-compose exec webapp php artisan key:generate
```

### Storage permissions

```bash
# Fix permissions
docker-compose exec webapp chmod -R 775 storage bootstrap/cache
docker-compose exec webapp chown -R www-data:www-data storage bootstrap/cache
```

## Updating InvoiceShelf

```bash
# Pull latest code from GitHub
git pull origin main

# Rebuild containers
docker-compose down
docker-compose --env-file .env.docker up -d --build

# Run migrations
docker-compose exec webapp php artisan migrate --force

# Clear caches
docker-compose exec webapp php artisan config:cache
docker-compose exec webapp php artisan route:cache
docker-compose exec webapp php artisan view:cache
```

## Migrating from Existing Installation

If you have an existing InvoiceShelf installation and want to move to Docker:

### 1. Backup existing database

```bash
mysqldump -u invoiceshelf_user -p invoiceshelf > existing_backup.sql
```

### 2. Start Docker containers

```bash
docker-compose --env-file .env.docker up -d
```

### 3. Import database

```bash
docker-compose exec -T database mysql -u invoiceshelf -p'YOUR_PASSWORD' invoiceshelf < existing_backup.sql
```

### 4. Copy storage files

```bash
docker cp /path/to/existing/storage/. invoiceshelf-app:/var/www/html/storage/
```

### 5. Set permissions

```bash
docker-compose exec webapp chown -R www-data:www-data storage
docker-compose exec webapp chmod -R 775 storage
```

## Support

For issues specific to this custom deployment:
- Check logs: `docker-compose logs -f`
- Verify environment variables: `docker-compose exec webapp env | grep DB_`
- Test database connection: `docker-compose exec webapp php artisan migrate:status`

For InvoiceShelf issues:
- Official docs: https://docs.invoiceshelf.com
- GitHub: https://github.com/InvoiceShelf/InvoiceShelf

---

**Maintained by:** Royal Dental Services IT Team  
**Version:** 1.0.0  
**Last Updated:** 2025-11-17
