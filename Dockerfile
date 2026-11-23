FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libxml2-dev libonig-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo pdo_mysql dom mbstring gd \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers
COPY php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh
WORKDIR /var/www/html
COPY --from=vendor /app/vendor ./vendor
COPY . /var/www/html/
RUN mkdir -p /var/www/private_uploads && chown -R www-data:www-data /var/www/html /var/www/private_uploads
EXPOSE 80
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
CMD ["apache2-foreground"]
