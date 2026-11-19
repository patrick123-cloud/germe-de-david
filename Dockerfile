FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql && a2enmod rewrite
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html/uploads
# Render fournit le port via $PORT
CMD sed -i "s/80/${PORT:-10000}/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf && apache2-foreground
