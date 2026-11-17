FROM php:8.5-cli-alpine

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY php/99-custom.ini $PHP_INI_DIR/conf.d/99-custom.ini

# pcov collects the coverage for composer coverage
RUN apk add --no-cache git unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install pcov \
    && docker-php-ext-enable pcov \
    && apk del .build-deps

WORKDIR /app
