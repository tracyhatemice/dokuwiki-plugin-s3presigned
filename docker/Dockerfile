FROM php:8.3-apache

# The extension set DokuWiki's own CI installs (.github/workflows/testLinux.yml)
RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libonig-dev \
        libpng-dev \
        libjpeg62-turbo-dev \
        libfreetype6-dev \
        libbz2-dev \
        libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" mbstring intl gd bz2 pdo_sqlite \
    && a2enmod rewrite

COPY entrypoint-wiki.sh /usr/local/bin/entrypoint-wiki
RUN chmod +x /usr/local/bin/entrypoint-wiki
