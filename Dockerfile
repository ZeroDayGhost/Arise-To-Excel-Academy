FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mbstring zip opcache \
    && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/apache.conf /etc/apache2/conf-available/app.conf
RUN a2enconf app

COPY . /var/www/html
