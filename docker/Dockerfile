FROM php:8.4-cli

RUN apt-get update && apt-get install -y --no-install-recommends \
      git unzip libicu-dev libzip-dev libpq-dev libonig-dev libxml2-dev \
  && docker-php-ext-install intl zip pdo pdo_mysql opcache \
  && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
