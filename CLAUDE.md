# CLAUDE.md — clientes-portal

Portal de clientes de Paid Media de **OliMedia**. Laravel + Filament, independiente del
WordPress de olimedia.es, desplegado en `clientes.olimedia.es` (VPS con Plesk).
**Estado: MVP en producción** con un cliente piloto ("Mi universidad", Meta + Google + TikTok).

Documentación complementaria: [docs/CONTEXTO-PROYECTO.md](docs/CONTEXTO-PROYECTO.md) (concepto,
estado, roadmap) y [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md) (flujo de despliegue Plesk).

---

## 1. La regla de negocio que manda sobre todo: el motor de precios

**El coste que ve el cliente NO es el gasto real de la plataforma.** Es el objetivo mensual
(lo que se le factura) repartido por días:

```
coste_del_dia = (objetivo_mes − acumulado_hasta_ayer) / días_restantes_del_mes
```

Implementado en [app/Services/PricingEngine.php](app/Services/PricingEngine.php). Absorbe
prorrateo diario, subir/bajar el objetivo a mitad de mes (renivela solo los días que quedan),
pausar una línea (0 €/día desde la pausa), línea que arranca a mitad de mes y mover presupuesto
entre líneas.

Reglas derivadas, **no romper ninguna**:

- El coste-cliente **no se persiste**: se calcula al vuelo. Cambiar un presupuesto recalcula todo
  el histórico sin migrar datos. No añadas una columna `coste_cliente` a `metricas_diarias`.
- Los cambios de presupuesto se modelan como **eventos** en `presupuesto_eventos`
  (`fecha_efectiva`, `objetivo_mes`, `estado` = `activa|pausada`). Para cada día el motor toma el
  último evento vigente. Nunca edites "el presupuesto" en sitio: añade un evento.
- `gasto_real` (lo que se paga a la plataforma) es **solo uso interno / margen**.
  **Nunca debe aparecer en ninguna vista del cliente.**
- CPC cliente = `coste_cliente / clics_reales`; CPL cliente = `coste_cliente / leads_reales`.
- `App\Models\Override` permite ajustes manuales del account manager sobre `coste_cliente`
  (por día concreto, o del mes completo redistribuyendo proporcionalmente).

---

## 2. Stack real

| Pieza | Versión / nota |
|---|---|
| PHP | 8.4 en el servidor (`composer.json` pide `^8.3`) |
| Laravel | v13.25 |
| Filament | **v5.7.6** — estructura nueva, ojo (ver abajo) |
| MySQL | — |
| Chart.js | por CDN, **sin build step** |
| PhpSpreadsheet | ^5.9, lectura de Excel |

**Sin pipeline de front**: el dashboard es Blade + JS inline + Chart.js por CDN. No introduzcas
dependencias de Vite/npm para el portal sin un motivo fuerte; el deploy de Plesk no ejecuta
`npm run build`.

### Convenciones de Filament v5 (distinto de v3/v4)

- Un recurso se reparte en `Resource.php` + `Schemas/{Model}Form.php` + `Tables/{Model}Table.php`.
- Los formularios usan `Filament\Schemas\Schema` con `->components([...])` (no `Form`/`->schema()`).
- Las tablas usan `->recordActions([...])` y `->toolbarActions([...])` (no `->actions()` ni `->bulkActions()`).
- Las acciones vienen de `Filament\Actions\*` (no `Filament\Tables\Actions\*`).

Copia el patrón de un recurso existente antes de inventar; es la fuente de verdad más fiable.

---

## 3. Autenticación: dos guards separados (esto arregló el 403)

[config/auth.php](config/auth.php) define **dos guards sobre el mismo provider `users`**:

- `web` → clientes (`/login`, `/dashboard`)
- `admin` → panel Filament (`/admin`), fijado con `->authGuard('admin')` en
  [AdminPanelProvider.php](app/Providers/Filament/AdminPanelProvider.php)

Así un admin y un cliente pueden estar logueados a la vez sin pisarse la sesión. El
"403 Forbidden intermitente" era colisión de sesión, no el servidor. **No unifiques los guards.**

Tipos de usuario (`users.cliente_id`, `users.es_admin`):

- **Admin / account manager**: `es_admin = true`, `cliente_id = null` → panel `/admin`.
- **Cliente**: `cliente_id = X` → solo ve el dashboard de SU cliente.

Tras tocar `config/auth.php` en producción: `php artisan optimize:clear` y volver a iniciar
sesión, o el cambio no surte efecto.

---

## 4. Mapa del código

```
app/
  Services/PricingEngine.php        ← motor de precios (sección 1)
  Services/Import/
    Parser.php                      ← base: fecha() ISO + DD/MM/AAAA, dec() EN, esDec() ES
    MetaParser.php                  ← Excel, hoja "Raw Data Report", salta filas "All"
    GoogleParser.php                ← CSV UTF-16 + tabuladores, números ES, "Conversiones" opcional
    TikTokParser.php                ← Excel, salta "Total of N results"
    CampaignImporter.php            ← firstOrCreate de Línea + upsert métricas + import_log
  Services/Sync/
    MetaConnector.php               ← Marketing API de Meta → importRows() (sección 6b)
  Console/Commands/PortalImport.php ← php artisan portal:import {cuenta_id} {ruta}
  Console/Commands/PortalSync.php   ← php artisan portal:sync {--cuenta=} {--dias=3}
  Http/Controllers/
    DashboardController.php         ← exporta el dataset diario completo a JSON
    Auth/LoginController.php        ← login cliente; admin → /admin
    AccountController.php           ← /cuenta, cambio de contraseña del cliente
    Conexiones/MetaConexionController.php ← OAuth de Meta (autorizar + callback)
  Filament/Resources/               ← Clientes, Cuentas, Lineas, PresupuestoEventos,
                                      MetricaDiarias, ImportLogs, Users
  Models/                           ← Cliente, Cuenta, Linea, PresupuestoEvento,
                                      MetricaDiaria, Override, ImportLog, User, ConexionApi
resources/views/portal/             ← dashboard.blade.php, login.blade.php, cuenta.blade.php
routes/web.php                      ← /login, /dashboard, /cuenta, /media/logo/{cliente},
                                      /conexiones/meta/{autorizar,callback}
routes/console.php                  ← portal:sync diario a las 07:30 (Europe/Madrid)
database/migrations/2026_08_11_*    ← tablas del portal + cliente_id/es_admin en users
database/migrations/2026_09_23_*    ← conexiones_api (tokens de API por cuenta)
```

**Nota**: `Override` tiene modelo y lo aplica el motor, pero **no tiene recurso Filament**; hoy
solo se crean por base de datos o tinker. Si el account manager necesita tocarlos desde el panel,
hay que crear `app/Filament/Resources/Overrides/`.

---

## 5. Modelo de datos

- `clientes` — cliente final (nombre, slug, logo, color, activo).
- `cuentas` — cuenta publicitaria por plataforma (`meta|google|tiktok|linkedin`).
- `lineas` — campaña/adset presupuestada; `match_key` normalizada, `nivel` (`campania|adset`).
- `presupuesto_eventos` — línea temporal del objetivo mensual (la flexibilidad vive aquí).
- `metricas_diarias` — cifras **reales** por día y línea. **Único por `(cuenta_id, match_key, fecha)`**
  → es lo que permite el upsert idempotente. Guarda `import_id`. El cast de `fecha` es
  `date:Y-m-d` **a propósito**: con `date` se escribía `Y-m-d H:i:s` y el upsert dejaba de casar
  en cualquier motor que no trunque como la columna DATE de MySQL.
- `overrides` — ajustes manuales.
- `import_logs` — auditoría de importaciones, con `origen` (`manual|api`) y `filas_error`.
- `conexiones_api` — credenciales de API **por cuenta** (1:1 con `cuentas`). `metodo`
  (`system_user|oauth`), `access_token` **cifrado** (cast `encrypted`), `expires_at`,
  `ultima_sincronizacion`, `ultimo_error`.
- `users` — + `cliente_id`, `es_admin`.

---

## 6. Importador: cómo funciona y dónde se falla

[CampaignImporter](app/Services/Import/CampaignImporter.php) tiene **dos puntos de entrada** que
acaban en el mismo sitio:

- `import(Cuenta, $path)` — informe descargado a mano. Elige el parser según la plataforma de la
  cuenta y delega en el siguiente. Es lo que usan la acción de Filament y `portal:import`.
- `importRows(Cuenta, $rows, $meta)` — filas **ya normalizadas**. Es el punto de entrada para la
  sincronización por API.

Una fila normalizada es la que produce `Parser::make()`. De sus claves, **`match_key` y `fecha` son
obligatorias** (identifican la fila); el resto se dan por 0 si faltan, para que un conector de API
no tenga que rellenar métricas que su plataforma no devuelve. Las filas sin campaña o sin fecha se
descartan y se cuentan en `import_logs.filas_error`.

`$meta` admite `origen` (`manual|api`), `fichero` y `user_id` (por defecto `Auth::id()`, null en
procesos automáticos).

**Las fechas deben llegar ya en `Y-m-d` a `importRows()`.** Se aceptan objetos fecha y cadenas que
empiecen por `Y-m-d` (con o sin hora); cualquier otra cosa se descarta a propósito, porque
`01/04/2026` es el 1 de abril en España y el 4 de enero en formato americano y aquí no se adivina.
Convertir fechas españolas es trabajo del `Parser`.

El flujo de usuario no cambia: se sube el archivo **a una Cuenta concreta**, la cuenta define
cliente y plataforma, y esa plataforma elige el parser. La acción "Importar informe" vive en
[ListImportLogs.php](app/Filament/Resources/ImportLogs/Pages/ListImportLogs.php).

- `Linea::normalizar()` quita emojis/símbolos y pasa a minúsculas (Meta los usa como prefijo:
  `🟥 INFANTIL_LEADADS`). Es lo que hace casar el CSV con la línea presupuestada.
- **Idempotente**: reimportar el archivo corregido sobre la misma cuenta sobrescribe las mismas
  campañas/fechas, no duplica.
- Borrar un `ImportLog` **borra también sus métricas** (evento `deleting` en
  [ImportLog.php](app/Models/ImportLog.php)) → sirve para deshacer un informe mal subido. Solo hace
  falta Eliminar+reimportar si el archivo malo traía fechas/campañas que el bueno ya no tiene.
- Emparejado típico de archivos: Meta `.xlsx` · Google `.csv` · TikTok `.xlsx`.

**Error más frecuente del usuario**: subir el archivo a la cuenta equivocada. El mensaje
"upload complete" es del campo de subida, no el resultado de la importación.

**Números y fechas en español**: `Parser::esDec()` (miles con punto, decimal con coma) y
`Parser::fecha()` (acepta `AAAA-MM-DD` y `DD/MM/AAAA`). Al escribir un parser nuevo —LinkedIn es
el siguiente— hereda de `Parser`, devuelve filas con `make()` y no toques nada más: el esquema
interno ya está resuelto.

---

## 6b. Sincronización por API: Meta (implementado); Google y TikTok (pendiente)

[MetaConnector](app/Services/Sync/MetaConnector.php) baja insights de campaña día a día de la
Marketing API de Meta y los vuelca en `importRows()`. Por eso API e informe manual son
intercambiables e idempotentes entre sí (mismo upsert, mismo `import_logs`, `gasto_real` sigue
siendo interno). El scheduler lo lanza a diario a las 07:30 (`Europe/Madrid`,
[routes/console.php](routes/console.php)) y re-baja 3 días porque las plataformas corrigen cifras
hacia atrás.

**Autenticación por cuenta, a elegir en la ficha de la Cuenta de `/admin`** (no hay una global):

- `system_user` — token de System User del Business Manager de OliMedia, pegado a mano. No
  caduca; para las cuentas que gestiona la agencia. No necesita app de Facebook configurada.
- `oauth` — cada cliente conecta su propio acceso con Facebook Login. Rutas
  `/conexiones/meta/{autorizar,callback}` (guard `admin`); el `cuenta_id` viaja en la sesión
  dentro del `state` (nonce anti-CSRF). El token de vida larga (~60 días) se renueva solo al
  canjearlo cuando quedan <7 días. Requiere `META_APP_ID`/`META_APP_SECRET` en `.env` y el
  redirect `{APP_URL}/conexiones/meta/callback` en "Valid OAuth Redirect URIs" de la app.

En ambas modalidades la Cuenta necesita su `external_id` rellenado (acepta con o sin `act_`).
El token va cifrado en `conexiones_api` (cast `encrypted`); los errores de sincronización quedan
en `conexiones_api.ultimo_error` y se ven en el badge/modal de la ficha. Al crear un conector de
otra plataforma (Google, TikTok), copia este patrón: reutiliza `conexiones_api`, que su método
devuelva filas normalizadas y llame a `importRows()`; solo MetaConnector conoce Graph.

Comandos: `php artisan portal:sync` (todas las Meta conectadas), `--cuenta=ID`, `--dias=7`.
El cron de Plesk que mueve el scheduler está documentado en docs/DESPLIEGUE.md. El botón
"Sincronizar ahora" vive en la ficha de la Cuenta; "Desconectar API" borra el token (los datos
importados se conservan).

---

## 7. Dashboard del cliente

[resources/views/portal/dashboard.blade.php](resources/views/portal/dashboard.blade.php) +
[DashboardController.php](app/Http/Controllers/DashboardController.php).

Funciona como SPA: el controlador vuelca a la página **todo el dataset diario por línea/canal en
JSON** y el navegador resuelve rango de fechas, comparación, filtros por canal, orden, búsqueda,
paginación y gráfico sin recargar. Scope estricto por `cliente_id`.

Incluye: presets de rango (7/30 días, este mes, mes anterior, todo), comparación con periodo
anterior y deltas en los KPIs, selector de métrica del gráfico, chips por canal, tarjetas por canal
con logos SVG inline, tabla de campañas con borde de color por canal, export CSV (formato ES: `;`
+ BOM) y PDF (`window.print()` + `@media print`).

- El logo del cliente se sirve por ruta propia `/media/logo/{cliente}` (`route('cliente.logo')`),
  **para no depender de `storage:link`**. Si añades otras imágenes subidas, sigue este patrón o
  asegúrate primero del symlink.
- **Una línea sin presupuesto del mes muestra inversión/CPC/CPL a 0.** Clics, leads e impresiones
  reales sí se ven. No es un bug: falta el evento de presupuesto en la ficha de la Línea.

---

## 8. Flujo de trabajo

- **El repo GitHub `cvaneyk/clientes-portal` es la fuente de verdad.** Push → Plesk despliega.
  Detalle en [docs/DESPLIEGUE.md](docs/DESPLIEGUE.md).
- No se hacen commits ni push sin que lo pida el usuario.
- Comentarios y nombres de dominio **en español** (`lineas`, `presupuesto_eventos`,
  `costeClienteMes`). Mantén ese idioma; el código es mixto español/inglés por convención de
  Laravel, no lo uniformes.
- `.env` está en `.gitignore` y **no se sube**. No escribas credenciales en el repo ni en docs.
- **`DatabaseSeeder` corre en producción en cada push** (`db:seed --force` del deploy). Solo puede
  contener operaciones repetibles que no pisen datos reales: comprueba antes de crear y nunca
  escribas contraseñas fijas ni datos de ejemplo ahí. Los datos de demo están en
  `PortalDemoSeeder`, que se lanza a mano y se niega a correr en producción.
- Si un commit deja `.git/index.lock` o `.git/HEAD.lock` bloqueando, bórralos antes del push.

Comandos útiles:

```bash
php artisan portal:import 3 "storage/app/informes/meta-julio.xlsx"
php artisan portal:sync              # sincroniza las cuentas Meta conectadas por API
php artisan migrate --force
php artisan optimize:clear     # obligatorio tras tocar config/ en producción
composer test
```
