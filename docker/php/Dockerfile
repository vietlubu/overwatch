FROM php:8.3-cli-bookworm

ARG UID=1000
ARG GID=1000

WORKDIR /var/www/html

RUN apt-get update && apt-get install -y --no-install-recommends \
    git \
    unzip \
    libpq-dev \
    libsqlite3-dev \
    && docker-php-ext-install \
    bcmath \
    pcntl \
    pdo_mysql \
    pdo_pgsql \
    pdo_sqlite \
    sockets \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

RUN groupmod -o -g ${GID} www-data \
    && usermod -o -u ${UID} -g ${GID} www-data

USER www-data
