FROM composer:2 AS composer

FROM php:8.5-cli-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpng-dev libzip-dev unzip \
    && docker-php-ext-install gd zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json .
RUN composer install --no-interaction --no-scripts --no-plugins --prefer-dist
COPY . .
CMD ["composer", "test:behat"]
