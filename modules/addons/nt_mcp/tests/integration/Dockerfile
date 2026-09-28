FROM php:8.3-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql pcntl
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY composer.json composer.lock /integration/
RUN composer install --working-dir=/integration --no-interaction --prefer-dist --no-dev
WORKDIR /app
CMD ["php", "-d", "zend.exception_ignore_args=1", "tests/integration/oauth_database.php"]
