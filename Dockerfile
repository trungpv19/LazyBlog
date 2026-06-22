FROM php:8.2-fpm-alpine

RUN apk add --no-cache \
        git \
        unzip \
        libzip-dev \
        oniguruma-dev \
        libxml2-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        libwebp-dev \
        freetype-dev \
    && docker-php-ext-configure gd --with-webp --with-jpeg --with-freetype \
    && docker-php-ext-install \
        zip \
        mbstring \
        opcache \
        gd \
    && curl -sS https://getcomposer.org/installer \
        | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /var/www/html
