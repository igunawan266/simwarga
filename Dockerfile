# Multi-stage build for optimal image size
FROM php:8.2-fpm-alpine as builder

# Install system dependencies
RUN apk add --no-cache \
    curl \
    git \
    unzip \
    ca-certificates

# Install PHP extensions
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    libpng-dev \
    libjpeg-turbo-dev \
    libfreetype6-dev \
    postgresql-dev \
    && \
    docker-php-ext-install \
    -j$(nproc) \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    gd \
    bcmath \
    zip \
    xml \
    intl \
    mbstring \
    curl \
    && \
    apk del .build-deps

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy project files
COPY . .

# Install PHP dependencies
RUN composer install --no-dev --optimize-autoloader --no-interaction

# Production stage
FROM php:8.2-fpm-alpine

# Install runtime dependencies only
RUN apk add --no-cache \
    libpng \
    libjpeg-turbo \
    freetype \
    postgresql-client \
    supervisor \
    bash \
    curl

# Install PHP extensions (production)
RUN apk add --no-cache --virtual .build-deps \
    $PHPIZE_DEPS \
    libpng-dev \
    libjpeg-turbo-dev \
    libfreetype6-dev \
    postgresql-dev \
    && \
    docker-php-ext-install \
    -j$(nproc) \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    gd \
    bcmath \
    zip \
    xml \
    intl \
    mbstring \
    curl \
    && \
    pecl install redis \
    && \
    docker-php-ext-enable redis \
    && \
    apk del .build-deps

# Set working directory
WORKDIR /var/www/html

# Copy from builder
COPY --from=builder /var/www/html /var/www/html
COPY --chown=www-data:www-data . .

# Set permissions
RUN chmod -R 755 storage bootstrap/cache \
    && chown -R www-data:www-data /var/www/html

# PHP Configuration
RUN echo "upload_max_filesize = 100M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/memory.ini && \
    echo "max_execution_time = 300" >> /usr/local/etc/php/conf.d/timeout.ini && \
    echo "extension=redis.so" > /usr/local/etc/php/conf.d/redis.ini

# Create supervisord config
RUN mkdir -p /etc/supervisor/conf.d

COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://localhost:9000/ping || exit 1

# Expose port
EXPOSE 9000

# Start PHP-FPM
CMD ["php-fpm"]
