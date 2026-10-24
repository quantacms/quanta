FROM composer:2.10.3 AS composer
FROM php:8.5.12-apache

RUN apt-get update && apt-get install -y --no-install-recommends git unzip libpng-dev libonig-dev \
    && docker-php-ext-install gd mbstring \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json /app/composer.json
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-interaction --prefer-dist --no-scripts --no-plugins
COPY --chown=www-data:www-data . /app
RUN chown -R www-data:www-data /app
USER www-data
ENV HOME=/tmp
ENTRYPOINT ["php", "tests/behat/run.php"]
