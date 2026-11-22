FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql opcache && a2enmod rewrite deflate expires headers
# Performances : OPcache + compression + cache navigateur des fichiers statiques
COPY docker/opcache.ini /usr/local/etc/php/conf.d/zz-opcache.ini
COPY docker/apache-perf.conf /etc/apache2/conf-available/egd-perf.conf
RUN a2enconf egd-perf
COPY . /var/www/html/
RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html/uploads
# Render fournit le port via $PORT
CMD sed -i "s/80/${PORT:-10000}/g" /etc/apache2/ports.conf /etc/apache2/sites-enabled/000-default.conf && apache2-foreground
