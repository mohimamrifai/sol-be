FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
    git \
    curl \
    zip \
    unzip \
    libpng-dev \
    libxml2-dev \
    libonig-dev \
    libicu-dev \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-install pdo_mysql intl

COPY docker/php/entrypoint.sh /usr/local/bin/laravel-entrypoint.sh
RUN chmod +x /usr/local/bin/laravel-entrypoint.sh

WORKDIR /var/www
