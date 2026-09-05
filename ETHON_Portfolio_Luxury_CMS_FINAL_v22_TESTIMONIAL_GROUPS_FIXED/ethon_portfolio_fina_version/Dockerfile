FROM php:8.3-apache
RUN docker-php-ext-install opcache && a2enmod rewrite headers
WORKDIR /var/www/html
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/uploads /var/www/html/runtime 2>/dev/null || true
ENV PORT=80
EXPOSE 80
