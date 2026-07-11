FROM php:8.5-apache

RUN apt-get update && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libicu-dev \
        libzip-dev \
        libonig-dev \
        default-mysql-client \
        mariadb-client \
    && docker-php-ext-install -j1 pdo_mysql intl zip \
    && (docker-php-ext-install -j1 opcache || true) \
    && mkdir -p /tmp/xdebug && cd /tmp/xdebug \
    && git clone --depth 1 https://github.com/xdebug/xdebug.git . \
    && phpize \
    && ./configure \
    && make -j2 \
    && cp modules/xdebug.so "$(php -r 'echo ini_get("extension_dir");')" \
    && docker-php-ext-enable xdebug \
    && cd / && rm -rf /tmp/xdebug \
    && a2enmod rewrite \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2.8 /usr/bin/composer /usr/bin/composer

WORKDIR /app

RUN echo "ServerName transformer-app" >> /etc/apache2/apache2.conf
COPY vhost.conf /etc/apache2/sites-available/transformer-app.conf
RUN a2dissite 000-default && a2ensite transformer-app
