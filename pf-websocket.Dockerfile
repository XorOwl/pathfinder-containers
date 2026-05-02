FROM php:7.4-cli-alpine AS build
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
COPY websocket /app
WORKDIR /app
RUN composer install --no-dev --optimize-autoloader

FROM php:7.4-cli-alpine
COPY --from=build /app /app
WORKDIR /app
ENTRYPOINT ["/usr/local/bin/php", "cmd.php"]
