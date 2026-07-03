#!/bin/sh

set -e

echo "=== PreOrder DEV install started ==="

cd /var/www/html

echo "=== Installing Composer dependencies ==="
composer install

echo "=== Preparing Laravel directories ==="
mkdir -p storage/app/tmp
mkdir -p storage/framework/cache/data
mkdir -p storage/framework/sessions
mkdir -p storage/framework/views
mkdir -p storage/logs
mkdir -p bootstrap/cache

chmod -R 777 storage bootstrap/cache

echo "=== Creating .env if missing ==="
if [ ! -f .env ]; then
    if [ -f .env.development.example ]; then
        cp .env.development.example .env
    elif [ -f .env.example ]; then
        cp .env.example .env
    else
        echo "ERROR: No .env.example file found."
        exit 1
    fi
fi

echo "=== Clearing Laravel caches ==="
php artisan optimize:clear || true

echo "=== Generating APP_KEY if missing ==="
if ! grep -q "^APP_KEY=base64:" .env; then
    php artisan key:generate
fi

echo "=== Clearing Laravel caches again ==="
php artisan optimize:clear

echo "=== Checking database connection ==="
php artisan migrate:status || true

echo "=== PreOrder DEV install finished ==="