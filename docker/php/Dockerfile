FROM php:8.2-fpm

RUN apt-get update && apt-get install -y \
git \
curl \
zip \
unzip \
libpng-dev \
libxml2-dev \
libonig-dev

RUN docker-php-ext-install pdo_mysql

WORKDIR /var/www
