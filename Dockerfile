FROM php:8.2-apache

RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    zip \
    git \
    libpng-dev \
    libjpeg62-turbo-dev \
    libfreetype6-dev

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

RUN docker-php-ext-install \
    mysqli \
    zip \
    gd

COPY . /var/www/html/

WORKDIR /var/www/html

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

RUN composer install

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
