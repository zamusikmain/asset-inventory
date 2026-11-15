FROM php:8.3-fpm-alpine

RUN apk add --no-cache \
        icu-dev libzip-dev libpng-dev freetype-dev libjpeg-turbo-dev \
        postgresql-dev oniguruma-dev linux-headers git unzip bash \
        postgresql16-client openssl tzdata \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql zip gd mbstring opcache bcmath \
    && cp /usr/share/zoneinfo/Asia/Almaty /etc/localtime && echo "Asia/Almaty" > /etc/timezone

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

WORKDIR /var/www/app

# Документы и вложения хранятся вне public-каталога
RUN mkdir -p /var/www/storage && chown -R www-data:www-data /var/www/storage
