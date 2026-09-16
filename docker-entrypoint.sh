#!/bin/bash
set -e

echo "🚀 Starting SIMWarga Laravel Application..."

# Wait for database to be ready
echo "⏳ Waiting for database connection..."
while ! php -r "new PDO('mysql:host=${DB_HOST};port=${DB_PORT}', '${DB_USERNAME}', '${DB_PASSWORD}');" 2>/dev/null; do
    sleep 2
    echo "Retrying database connection..."
done

echo "✅ Database is ready!"

# Generate APP_KEY if not exists
if ! grep -q "APP_KEY=" .env; then
    echo "🔑 Generating APP_KEY..."
    php artisan key:generate
fi

# Clear cache
echo "🧹 Clearing cache..."
php artisan config:clear
php artisan cache:clear
php artisan route:clear

# Run migrations
echo "🗄️ Running database migrations..."
php artisan migrate --force

# Seed database (optional - uncomment if you want)
# echo "🌱 Seeding database..."
# php artisan db:seed --force

# Create storage link
echo "🔗 Creating storage symlink..."
php artisan storage:link || true

# Optimize application
echo "⚡ Optimizing application..."
php artisan optimize
php artisan config:cache
php artisan route:cache

# Set permissions
echo "🔐 Setting permissions..."
chown -R www-data:www-data /var/www/html/storage
chmod -R 755 /var/www/html/storage
chmod -R 755 /var/www/html/bootstrap/cache

echo "✨ SIMWarga is ready! Starting services..."

# Start supervisord or php-fpm
if [ -f "/etc/supervisor/conf.d/supervisord.conf" ]; then
    echo "🎯 Starting supervisord..."
    exec /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf
else
    echo "🎯 Starting PHP-FPM..."
    exec php-fpm
fi
