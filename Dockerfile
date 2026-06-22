FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        git \
        unzip \
        libzip-dev \
        oniguruma-dev \
        libxml2-dev \
    && docker-php-ext-install \
        zip \
        mbstring \
        opcache \
    && curl -sS https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
