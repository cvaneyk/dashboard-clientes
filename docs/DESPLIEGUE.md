# Despliegue y flujo de trabajo

## Cadena de trabajo

```
edición local  →  commit + push a GitHub (cvaneyk/clientes-portal)  →  Plesk despliega en el VPS
```

El repo de GitHub es la **fuente de verdad**. Nada se edita directamente en el servidor: lo que no
esté en el repo se pierde en el siguiente despliegue.

Dos formas de trabajar:

1. **Recomendada** — repo clonado en local, editar, y push con GitHub Desktop.
2. Editar directamente en GitHub web, solo para cambios de una línea.

## Qué ejecuta Plesk al recibir un push

Acciones de deploy configuradas en Plesk Git:

```bash
/opt/plesk/php/8.4/bin/php artisan migrate --force
/opt/plesk/php/8.4/bin/php artisan db:seed --force
/opt/plesk/php/8.4/bin/php artisan optimize:clear
```

**Importante**: hay que usar la ruta completa a PHP 8.4. El `php` por defecto del sistema es 8.3 y
da error.

### Cuidado con `db:seed --force`

Se ejecuta **en cada push, contra la base de datos de producción**. Todo lo que esté en
`DatabaseSeeder` tiene que ser seguro de repetir indefinidamente y no puede pisar datos reales.

Hoy solo crea el usuario admin **si no existe**, y nada más. Si vuelve a hacer falta añadir algo
ahí, que sea siempre con esa forma: comprobar antes, no sobrescribir.

Lo que **no** puede ir en ese seeder: contraseñas fijas escritas en el código (un `updateOrCreate`
con el campo `password` devuelve la contraseña a su valor original en cada despliegue) ni datos de
ejemplo. Los datos de demostración viven en `PortalDemoSeeder`, que se ejecuta a mano y se niega a
correr en producción:

```bash
php artisan db:seed --class=Database\\Seeders\\PortalDemoSeeder
```

Pendiente de añadir a esas acciones: `php artisan storage:link` (hoy no hace falta porque el logo
del cliente se sirve por la ruta `/media/logo/{cliente}`, pero lo hará en cuanto se usen otras URLs
de `public`).

## Cron del scheduler (sincronización diaria por API)

La sincronización automática con las APIs (`portal:sync`, 07:30 hora española) la dispara el
scheduler de Laravel, pero en el VPS **hay que crear el cron una vez a mano** en
Plesk → *Scheduled Tasks* (tarea del tipo "Ejecutar un comando PHP" o cron del sistema):

```bash
cd /var/www/vhosts/olimedia.es/clientes.olimedia.es && /opt/plesk/php/8.4/bin/php artisan schedule:run
```

Cada minuto (`* * * * *`); el propio scheduler decide cuándo toca ejecutar. Sin este cron, la
sincronización solo va a mano: botón "Sincronizar ahora" en la ficha de la cuenta o
`php artisan portal:sync`.

Variables del `.env` del servidor para la conexión OAuth de Meta:
`META_APP_ID` y `META_APP_SECRET` (ver CLAUDE.md §6b).

## Configuración del servidor

- Docroot del subdominio `clientes.olimedia.es` → carpeta `public/` de Laravel.
- SSL: Let's Encrypt.
- MySQL en el mismo VPS.
- El `.env` vive **solo en el servidor** (está en `.gitignore`). Si hace falta una variable nueva,
  se añade a `.env.example` en el repo y a mano en el `.env` del servidor.

## Después de desplegar

- Cambios en `config/` (sobre todo `config/auth.php`): `php artisan optimize:clear` y **volver a
  iniciar sesión**. Si no, el cambio parece no haber surtido efecto.
- Cambios en migraciones: se aplican solas con el `migrate --force` del deploy.
- Cambios solo en Blade/JS del dashboard: basta con el `optimize:clear` del deploy.

## Problema conocido en local

Algunos commits hechos desde el entorno sandbox dejan ficheros de bloqueo de git. Si el push falla,
bórralos antes de reintentar:

```bash
rm -f .git/index.lock .git/HEAD.lock
```
