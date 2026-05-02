FROM php:7.4-fpm-alpine AS build

RUN apk add --no-cache libpng-dev git ${PHPIZE_DEPS} \
    && docker-php-ext-install gd pdo_mysql \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

COPY pathfinder /app
WORKDIR /app
RUN composer install --no-dev --optimize-autoloader

COPY patches/apply.php /patches/apply.php
RUN php /patches/apply.php

FROM php:7.4-fpm-alpine

RUN apk add --no-cache \
        nginx \
        supervisor \
        bash \
        gettext \
        apache2-utils \
        logrotate \
        busybox-suid \
        sudo \
        shadow \
        libpng \
        ca-certificates

RUN apk add --no-cache --virtual .build-deps libpng-dev ${PHPIZE_DEPS} \
    && docker-php-ext-install gd pdo_mysql \
    && pecl install redis-5.3.7 \
    && docker-php-ext-enable redis \
    && apk del .build-deps

RUN ln -sf /dev/stdout /var/log/nginx/access.log \
 && ln -sf /dev/stderr /var/log/nginx/error.log

RUN mkdir -p /etc/nginx/sites_enabled/ /var/www/html

COPY static/logrotate/pathfinder /etc/logrotate.d/pathfinder
COPY static/nginx/nginx.conf /etc/nginx/templateNginx.conf
COPY static/nginx/site.conf  /etc/nginx/templateSite.conf
COPY static/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zzz_custom.conf
COPY static/php/php.ini /usr/local/etc/php/conf.d/zzz_custom.ini.template
COPY static/crontab.txt /var/crontab.txt
COPY static/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY static/entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html
COPY --chown=nobody:nobody --from=build /app pathfinder

RUN chmod 0766 pathfinder/logs pathfinder/tmp/ \
    && rm -f index.php \
    && touch /etc/nginx/.setup_pass

COPY static/pathfinder/routes.ini /var/www/html/pathfinder/app/
COPY static/pathfinder/environment.ini /var/www/html/pathfinder/app/templateEnvironment.ini

EXPOSE 80

ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
