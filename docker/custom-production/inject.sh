#!/bin/bash
#============================================================================
# Royal Dental Services - Environment Variable Injection Script
# 
# This script injects environment variables into the .env file
# Includes custom defaults for Royal Dental Services deployment
#============================================================================

function replace_or_insert() {
    # Replace existing line or insert new one
    grep -q "^${1}=" /var/www/html/.env && sed "s|^${1}=.*|${1}=${2}|" -i /var/www/html/.env || sed "$ a\\${1}=${2}" -i /var/www/html/.env
}

echo "Injecting environment variables into .env..."

# Mark as containerized
replace_or_insert "CONTAINERIZED" "true"

# Application settings
if [ "$APP_NAME" != '' ]; then
   replace_or_insert "APP_NAME" "$APP_NAME"
fi
if [ "$APP_ENV" != '' ]; then
   replace_or_insert "APP_ENV" "$APP_ENV"
fi
if [ "$APP_KEY" != '' ]; then
   replace_or_insert "APP_KEY" "$APP_KEY"
fi
if [ "$APP_DEBUG" != '' ]; then
   replace_or_insert "APP_DEBUG" "$APP_DEBUG"
fi
if [ "$APP_URL" != '' ]; then
   replace_or_insert "APP_URL" "$APP_URL"
fi
if [ "$APP_TIMEZONE" != '' ]; then
   replace_or_insert "APP_TIMEZONE" "$APP_TIMEZONE"
fi

# Database settings
if [ "$DB_CONNECTION" != '' ]; then
   replace_or_insert "DB_CONNECTION" "$DB_CONNECTION"
fi
if [ "$DB_HOST" != '' ]; then
   replace_or_insert "DB_HOST" "$DB_HOST"
fi
if [ "$DB_PORT" != '' ]; then
   replace_or_insert "DB_PORT" "$DB_PORT"
fi
if [ "$DB_DATABASE" != '' ]; then
   replace_or_insert "DB_DATABASE" "$DB_DATABASE"
fi
if [ "$DB_USERNAME" != '' ]; then
   replace_or_insert "DB_USERNAME" "$DB_USERNAME"
fi
if [ "$DB_PASSWORD" != '' ]; then
   replace_or_insert "DB_PASSWORD" "$DB_PASSWORD"
elif [ "$DB_PASSWORD_FILE" != '' ]; then
  value=$(<$DB_PASSWORD_FILE)
   replace_or_insert "DB_PASSWORD" "$value"
fi

# Cache settings
if [ "$CACHE_STORE" != '' ]; then
   replace_or_insert "CACHE_STORE" "$CACHE_STORE"
fi

# Session settings
if [ "$SESSION_DRIVER" != '' ]; then
   replace_or_insert "SESSION_DRIVER" "$SESSION_DRIVER"
fi
if [ "$SESSION_LIFETIME" != '' ]; then
   replace_or_insert "SESSION_LIFETIME" "$SESSION_LIFETIME"
fi
if [ "$SESSION_DOMAIN" != '' ]; then
   replace_or_insert "SESSION_DOMAIN" "$SESSION_DOMAIN"
fi

# Sanctum settings
if [ "$SANCTUM_STATEFUL_DOMAINS" != '' ]; then
   replace_or_insert "SANCTUM_STATEFUL_DOMAINS" "$SANCTUM_STATEFUL_DOMAINS"
fi

# Mail settings
if [ "$MAIL_MAILER" != '' ]; then
   replace_or_insert "MAIL_MAILER" "$MAIL_MAILER"
fi
if [ "$MAIL_HOST" != '' ]; then
   replace_or_insert "MAIL_HOST" "$MAIL_HOST"
fi
if [ "$MAIL_PORT" != '' ]; then
   replace_or_insert "MAIL_PORT" "$MAIL_PORT"
fi
if [ "$MAIL_USERNAME" != '' ]; then
   replace_or_insert "MAIL_USERNAME" "$MAIL_USERNAME"
fi
if [ "$MAIL_PASSWORD" != '' ]; then
   replace_or_insert "MAIL_PASSWORD" "$MAIL_PASSWORD"
fi
if [ "$MAIL_ENCRYPTION" != '' ]; then
   replace_or_insert "MAIL_ENCRYPTION" "$MAIL_ENCRYPTION"
fi
if [ "$MAIL_FROM_ADDRESS" != '' ]; then
   replace_or_insert "MAIL_FROM_ADDRESS" "$MAIL_FROM_ADDRESS"
fi
if [ "$MAIL_FROM_NAME" != '' ]; then
   replace_or_insert "MAIL_FROM_NAME" "$MAIL_FROM_NAME"
fi

# Queue settings
if [ "$QUEUE_CONNECTION" != '' ]; then
   replace_or_insert "QUEUE_CONNECTION" "$QUEUE_CONNECTION"
fi

# Custom Royal Dental Services settings
if [ "$DEFAULT_CURRENCY" != '' ]; then
   replace_or_insert "DEFAULT_CURRENCY" "$DEFAULT_CURRENCY"
fi
if [ "$TIMEZONE" != '' ]; then
   replace_or_insert "TIMEZONE" "$TIMEZONE"
fi

echo "Environment injection complete!"
