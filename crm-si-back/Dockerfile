# ── Composer dependencies ──
FROM composer:latest AS deps
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist

# ── Build (autoload with full source) ──
FROM deps AS build
COPY . .
RUN composer dump-autoload --optimize --no-dev

# ── Base: system deps, PHP extensions, Apache config ──
FROM php:8.4-apache AS base

RUN apt-get update && apt-get install -y \
    curl \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libzip-dev \
    libpq-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libwebp-dev \
    zip \
    unzip \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_pgsql pgsql mbstring xml zip bcmath gd pcntl \
    # pecl.php.net se cae cada tanto y devuelve "does not have REST xml
    # available", rompiendo el deploy entero. Reintentamos con backoff.
    && for i in 1 2 3 4 5; do \
           pecl install redis-6.1.0 && break; \
           echo "pecl install redis failed (attempt $i/5), retrying in $((i * 10))s..."; \
           [ "$i" = 5 ] && exit 1; \
           sleep $((i * 10)); \
       done \
    && docker-php-ext-enable redis

COPY --from=deps /usr/bin/composer /usr/bin/composer

RUN a2enmod rewrite ssl headers

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Config de PHP para todos los SAPIs. Los php_value de common.conf sólo aplican
# al SAPI de Apache; sin esto la CLI se queda en el memory_limit default de 128M
# y `artisan test` muere a mitad de la suite.
COPY ./docker/php/php.ini /usr/local/etc/php/conf.d/zz-app.ini

COPY ./docker/apache/common.conf /etc/apache2/conf-available/common.conf
COPY ./docker/apache/servername.conf /etc/apache2/conf-available/servername.conf
COPY ./docker/apache/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY ./docker/apache/default-ssl.conf /etc/apache2/sites-available/default-ssl.conf
COPY ./docker/apache/mpm_prefork.conf /etc/apache2/mods-available/mpm_prefork.conf

RUN a2enconf servername

WORKDIR /var/www/html
EXPOSE 80 443

# ── Production ──
FROM base AS production

# --chown en el COPY: un chown -R posterior duplicaría todo el árbol (vendor
# incluido) en una capa nueva y tarda varios minutos en el VPS. Solo storage y
# bootstrap/cache necesitan permisos de escritura extra (árboles chicos).
COPY --from=build --chown=www-data:www-data /app /var/www/html

RUN chmod -R 775 /var/www/html/storage /var/www/html/bootstrap/cache

COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

HEALTHCHECK --interval=30s --timeout=10s --start-period=30s --retries=3 \
    CMD curl -f http://localhost:80/api/health || exit 1

ENTRYPOINT ["entrypoint.sh"]
CMD ["apache2-foreground"]

# ── Development (code via bind mount, no build stages needed) ──
FROM base AS dev

RUN a2ensite default-ssl || true

CMD ["apache2-foreground"]
