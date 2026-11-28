# ---- Dependencias PHP ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
# COMPOSER_NO_DEV=1 (producao) instala sem os pacotes de dev.
# 0 (staging e padrao) mantem os de dev: os seeders de demonstracao usam o Faker.
ARG COMPOSER_NO_DEV=0
RUN if [ "$COMPOSER_NO_DEV" = "1" ]; then set -- --no-dev; fi \
    && composer install --no-scripts --no-autoloader --prefer-dist --no-interaction --ignore-platform-reqs "$@"
COPY . ./
RUN if [ "$COMPOSER_NO_DEV" = "1" ]; then set -- --no-dev; fi \
    && composer dump-autoload --optimize --no-scripts "$@"

# ---- Imagem final (Apache + PHP 8.4) ----
FROM php:8.4-apache

# pcntl: o queue:work termina o job em andamento no SIGTERM (deploy) e respeita o --timeout
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip \
    && docker-php-ext-install pdo_mysql bcmath opcache zip pcntl \
    && pecl install redis-6.3.0 \
    && docker-php-ext-enable redis \
    && rm -rf /tmp/pear \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri 's!AllowOverride None!AllowOverride All!g' /etc/apache2/apache2.conf \
    && cp /usr/local/etc/php/php.ini-production /usr/local/etc/php/php.ini

COPY infra/docker/backend/php.ini /usr/local/etc/php/conf.d/footfy.ini
COPY infra/docker/backend/opcache.ini /usr/local/etc/php/conf.d/footfy-opcache.ini

WORKDIR /var/www/html
COPY --from=vendor /app /var/www/html

RUN mkdir -p bootstrap/cache storage/framework/cache/data storage/framework/sessions \
        storage/framework/views storage/logs storage/app/public \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

COPY infra/docker/backend/entrypoint.sh /usr/local/bin/footfy-entrypoint
RUN chmod +x /usr/local/bin/footfy-entrypoint

ENTRYPOINT ["footfy-entrypoint"]
CMD ["apache2-foreground"]
