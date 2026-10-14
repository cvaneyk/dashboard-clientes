#!/bin/sh
# Se ejecuta en cada arranque del contenedor, antes de levantar nginx + PHP-FPM.
# Hace lo mismo que las acciones de deploy de Plesk (docs/DESPLIEGUE.md), pero
# dentro del contenedor: así no depende de un "post-deployment command" que
# puede llegar cuando el contenedor aún no está en marcha.
#
# db:seed es seguro en cada arranque: solo crea el admin si no existe ninguno.
# En una instalación nueva, la contraseña temporal sale UNA vez en este log.
set -e

cd /var/www/html

php artisan migrate --force
php artisan db:seed --force
php artisan optimize:clear
