# ============================================================================
# Royal Dental Services - Custom InvoiceShelf Production Dockerfile
# 
# This Docker image includes all production customizations:
# - UGX as currency_id = 1 (matches Crater structure)
# - Updated currency seeder (UGX first)
# - Patient fields support
# - Base amount calculations
# - All production fixes applied
# - Royal Dental Services branding
# ============================================================================

FROM --platform=$BUILDPLATFORM node:20 AS static_builder
    WORKDIR /var/www/html
    COPY . /var/www/html
    
    # Build frontend assets
    RUN yarn && yarn build

FROM serversideup/php:8.3-fpm-nginx-alpine AS base
    USER root
    
    # Install required PHP extensions and tools
    RUN apk add --no-cache bash nano mysql-client
    RUN install-php-extensions exif
    RUN install-php-extensions pgsql
    RUN install-php-extensions sqlite3
    RUN install-php-extensions imagick
    RUN install-php-extensions mbstring
    RUN install-php-extensions gd
    RUN install-php-extensions xml
    RUN install-php-extensions zip
    RUN install-php-extensions redis
    RUN install-php-extensions bcmath
    RUN install-php-extensions intl
    RUN install-php-extensions curl
    RUN install-php-extensions pdo_mysql

FROM base AS production
    ENV AUTORUN_ENABLED=true
    ENV PHP_OPCACHE_ENABLE=1
    ENV PHP_MEMORY_LIMIT=512M
    ENV PHP_MAX_EXECUTION_TIME=300
    
    # Custom environment variables for Royal Dental Services
    ENV APP_NAME="InvoiceShelf"
    ENV DEFAULT_CURRENCY=UGX
    ENV TIMEZONE=Africa/Nairobi
    
    # Set `www-data` as the user to start FPM
    USER root
    RUN echo "" >> /usr/local/etc/php-fpm.d/docker-php-serversideup-pool.conf && \
        echo "user = www-data" >> /usr/local/etc/php-fpm.d/docker-php-serversideup-pool.conf && \
        echo "group = www-data" >> /usr/local/etc/php-fpm.d/docker-php-serversideup-pool.conf
    
    # Revert back to www-data, non-root user
    USER www-data
    
    # Copy application files
    COPY --from=static_builder --chown=www-data:www-data /var/www/html/public /var/www/html/public
    COPY --chown=www-data:www-data . /var/www/html
    
    # Install PHP dependencies (production optimized)
    RUN composer install --prefer-dist --no-dev --optimize-autoloader --no-interaction
    
    # Copy custom entrypoint and inject scripts
    USER root
    COPY --chmod=755 docker/custom-production/inject.sh /inject.sh
    COPY --chmod=755 docker/custom-production/entrypoint.d/ /etc/entrypoint.d/
    
    # Create necessary directories
    RUN mkdir -p /var/www/html/storage/app/backups && \
        mkdir -p /var/www/html/storage/framework/cache && \
        mkdir -p /var/www/html/storage/framework/sessions && \
        mkdir -p /var/www/html/storage/framework/views && \
        mkdir -p /var/www/html/storage/logs && \
        mkdir -p /var/www/html/bootstrap/cache
    
    # Set proper permissions
    RUN chown -R www-data:www-data /var/www/html/storage && \
        chown -R www-data:www-data /var/www/html/bootstrap/cache && \
        chmod -R 775 /var/www/html/storage && \
        chmod -R 775 /var/www/html/bootstrap/cache
    
    USER www-data
    
    # Health check
    HEALTHCHECK --interval=30s --timeout=10s --start-period=60s --retries=3 \
        CMD curl -f http://localhost:8080/ || exit 1
    
    EXPOSE 8080
    
    # Labels for documentation
    LABEL maintainer="Royal Dental Services"
    LABEL version="1.0.0"
    LABEL description="Custom InvoiceShelf with UGX currency, patient fields, and RDS branding"

