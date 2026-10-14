# Imagen de producción (Coolify u otro host Docker).
#
# serversideup/php trae nginx + PHP-FPM ya configurados para Laravel: docroot en
# /var/www/html/public, escucha en el puerto 8080 y corre como www-data.
FROM serversideup/php:8.4-fpm-nginx

# Extensiones que no vienen de serie: intl (Filament) y gd (PhpSpreadsheet).
USER root
RUN install-php-extensions intl gd

# Tareas de arranque (migraciones, seeder): ver docker/entrypoint.d/.
COPY --chmod=755 docker/entrypoint.d/ /etc/entrypoint.d/

USER www-data
WORKDIR /var/www/html

# Primero solo las dependencias, para que Docker cachee esta capa entre deploys.
COPY --chown=www-data:www-data composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction

COPY --chown=www-data:www-data . .

# Sin scripts: el post-autoload-dump lanza filament:upgrade, que no hace falta
# (los assets de Filament ya están en public/) y no tiene .env en el build.
RUN composer dump-autoload --optimize --no-dev --no-scripts \
    && php artisan package:discover --ansi
