FROM php:8.4-apache-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        libc-client-dev \
        libfreetype6-dev \
        libicu-dev \
        libjpeg62-turbo-dev \
        libkrb5-dev \
        libpng-dev \
        libssl-dev \
        libxml2-dev \
        libzip-dev \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" gd intl mysqli opcache xml zip \
    && printf "yes\nyes\n" | pecl install imap \
    && docker-php-ext-enable imap \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/* /tmp/pear

ARG OSTICKET_VERSION=1.18.4
RUN curl -fsSL "https://github.com/osTicket/osTicket/releases/download/v${OSTICKET_VERSION}/osTicket-v${OSTICKET_VERSION}.zip" -o /tmp/osticket.zip \
    && unzip -q /tmp/osticket.zip -d /tmp/osticket \
    && cp -a /tmp/osticket/upload/. /var/www/html/ \
    && cp /var/www/html/include/ost-sampleconfig.php /var/www/html/include/ost-config.php \
    && chmod 0666 /var/www/html/include/ost-config.php \
    && chown -R www-data:www-data /var/www/html \
    && rm -rf /tmp/osticket /tmp/osticket.zip
