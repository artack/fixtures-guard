# switch to php:8.6-cli-alpine once PHP 8.6 is released
FROM php:8.6-rc-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php/99-custom.ini $PHP_INI_DIR/conf.d/99-custom.ini

# the 8.6 release candidate ships without mbstring, which PHPUnit needs
RUN apk add --no-cache git unzip oniguruma \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS oniguruma-dev \
    && docker-php-ext-install mbstring \
    && apk del .build-deps

WORKDIR /app
