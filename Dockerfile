FROM php:8.3-fpm

# Install system dependencies
RUN apt-get update \
 && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libzip-dev \
    libicu-dev \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev \
 && docker-php-ext-configure intl \
 && docker-php-ext-install \
    pdo \
    pdo_mysql \
    intl \
    bcmath \
    zip \
 && rm -rf /var/lib/apt/lists/*

 # Copy php ini tweaks
COPY docker/php/conf.d/opcache.ini /usr/local/etc/php/conf.d/opcache.ini

# Install Node.js 20.x
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - \
 && apt-get update \
 && apt-get install -y --no-install-recommends nodejs \
 && node --version \
 && npm --version

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# PHP-FPM user permissions (use existing www-data)
RUN chown -R www-data:www-data /var/www

# Switch to non-root user
USER www-data

# Default command comes from php:8.3-fpm base image (php-fpm)
# Application code is mounted via docker-compose
