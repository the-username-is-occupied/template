# syntax=docker/dockerfile:1

ARG PHP_VERSION=8.3
ARG NODE_VERSION=22

# --- Stage: composer dependencies ---
FROM dunglas/frankenphp:1.11-php${PHP_VERSION} AS vendor

RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*

RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

WORKDIR /app

COPY composer.json composer.lock ./

RUN --mount=type=cache,target=/tmp/cache \
    composer install \
    --no-interaction \
    --no-scripts \
    --no-dev \
    --prefer-dist \
    --ignore-platform-reqs

# --- Stage: frontend build ---
FROM node:${NODE_VERSION}-alpine AS frontend

WORKDIR /app

COPY package.json package-lock.json ./
COPY vite.config.js ./
COPY resources/ ./resources/

RUN --mount=type=cache,target=/root/.npm \
    npm ci --quiet && npm run build

# --- Stage: base PHP image ---
FROM dunglas/frankenphp:1.11-php${PHP_VERSION} AS base

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    unzip \
    curl \
    supervisor \
    librabbitmq-dev \
    libpq-dev \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

RUN install-php-extensions \
    bcmath \
    curl \
    exif \
    gd \
    intl \
    mbstring \
    opcache \
    pcntl \
    pdo \
    pdo_mysql \
    pdo_pgsql \
    pgsql \
    redis \
    xsl \
    zip

WORKDIR /var/www/html

# --- Stage: dev target ---
FROM base AS dev

RUN apt-get update \
    && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
    git \
    nodejs \
    npm \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

COPY --from=vendor /usr/local/bin/composer /usr/local/bin/composer

EXPOSE 80
EXPOSE 5173

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]

# --- Stage: prod target ---
FROM base AS prod

COPY --from=vendor /usr/local/bin/composer /usr/local/bin/composer
COPY --from=vendor /app/vendor ./vendor/
COPY --from=frontend /app/public/build ./public/build/
COPY . .

RUN composer dump-autoload --optimize --no-dev \
    && rm -f /usr/local/bin/composer \
    && cp docker/supervisor/common.conf /etc/supervisor/common.conf \
    && cp docker/supervisor/prod.conf /etc/supervisor/conf.d/supervisord.conf \
    && cp docker/app/php.ini /usr/local/etc/php/php.ini \
    && cp Caddyfile /etc/caddy/Caddyfile

RUN chmod -R 775 storage bootstrap/cache \
    && chown -R www-data:www-data storage bootstrap/cache

HEALTHCHECK --interval=30s --timeout=3s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/up || exit 1

EXPOSE 80

CMD ["/usr/bin/supervisord", "-n", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
