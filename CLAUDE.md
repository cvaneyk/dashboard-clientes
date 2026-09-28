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
- `lineas.presupuesto_plataforma` (el tope de gasto de la campaña en Meta, que trae la API) es de
  esa misma familia: **referencia interna para el account manager, solo visible en `/admin`**.
  No es el objetivo facturable y **no puede convertirse en uno automáticamente**: el objetivo lo
  decide OliMedia porque lleva el margen dentro. Ninguna API conoce esa cifra.
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

- **Admin / account manager**: `es_admin = true`, `cliente_id = null` → panel `/admin`, y además
  puede abrir `/dashboard` y cambiar de cliente con el selector (sección 7).
- **Cliente**: `cliente_id = X` → solo ve el dashboard de SU cliente.

Las rutas del portal usan `auth:web,admin` (dos guards admitidos, **no unificados**): el cliente
entra por `/login` con el guard `web` y el admin llega desde Filament con el guard `admin`. Las
sesiones siguen siendo independientes, que es lo que arregló el 403.

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
    Conector.php / Conectores.php   ← contrato común + fábrica por plataforma
    MetaConnector.php               ← Marketing API de Meta → importRows() (sección 6b)
    GoogleAdsConnector.php          ← Google Ads API (GAQL) → importRows() (sección 6b)
  Console/Commands/PortalImport.php ← php artisan portal:import {cuenta_id} {ruta}
  Console/Commands/PortalSync.php   ← php artisan portal:sync {--cuenta=} {--dias=3}
  Http/Controllers/
    DashboardController.php         ← exporta el dataset diario completo a JSON
    Auth/LoginController.php        ← login cliente; admin → /admin
    AccountController.php           ← /cuenta, cambio de contraseña del cliente
    Conexiones/MetaConexionController.php ← OAuth de Meta (autorizar + callback)
    Conexiones/GoogleConexionController.php ← OAuth de Google Ads
  Filament/Resources/               ← Clientes, Cuentas, Lineas, PresupuestoEventos,
                                      MetricaDiarias, ImportLogs, Users
  Models/                           ← Cliente, Cuenta, Linea, PresupuestoEvento,
                                      MetricaDiaria, Override, ImportLog, User, ConexionApi
resources/views/portal/             ← dashboard.blade.php, login.blade.php, cuenta.blade.php
routes/web.php                      ← /login, /dashboard, /cuenta, /media/logo/{cliente},
                                      /conexiones/{meta,google}/{autorizar,callback}
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
  `tipos_conversion` (opcional) fija qué conversiones de Meta cuentan como lead en esa cuenta.
- `lineas` — campaña/adset presupuestada; `match_key` normalizada, `nivel` (`campania|adset`).
  `presupuesto_plataforma` (+ `_tipo` `diario|total`, `_nivel` `campania|adsets`, `_actualizado_en`)
  es el tope en la plataforma, **referencia interna** que refresca la sincronización.
- `presupuesto_eventos` — línea temporal del objetivo mensual (la flexibilidad vive aquí).
- `metricas_diarias` — cifras **reales** por día y línea. **Único por `(cuenta_id, match_key, fecha)`**
  → es lo que permite el upsert idempotente. Guarda `import_id`. El cast de `fecha` es
  `date:Y-m-d` **a propósito**: con `date` se escribía `Y-m-d H:i:s` y el upsert dejaba de casar
  en cualquier motor que no trunque como la columna DATE de MySQL.
- `overrides` — ajustes manuales.
- `import_logs` — auditoría de importaciones, con `origen` (`manual|api`) y `filas_error`.
- `conexiones_api` — credenciales de API **por cuenta** (1:1 con `cuentas`). `metodo`
  (`system_user|oauth` en Meta, `mcc|oauth` en Google), `access_token` y `refresh_token` **cifrados**
  (cast `encrypted`), `login_customer_id` (la MCC de Google, opcional), `expires_at`,
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

## 6b. Sincronización por API: Meta y Google Ads (implementados); TikTok y LinkedIn (pendientes)

Todos los conectores implementan [Conector](app/Services/Sync/Conector.php) (`sincronizar` /
`sincronizarRango`) y [Conectores::para($cuenta)](app/Services/Sync/Conectores.php) elige el que
toca. Ni `portal:sync` ni la ficha de la Cuenta saben de plataformas: **añadir TikTok es escribir
su conector y apuntarlo en `Conectores::PLATAFORMAS`**. Todos acaban en `importRows()`.

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

**Qué cuenta como lead**: Meta devuelve en `actions` varios tipos de conversión a la vez y cada
cuenta mide distinto (formularios de Lead Ads → `lead`; registros en web con píxel →
`complete_registration`). `tiposConversion()` resuelve una **lista por prioridad** y se queda con
el **primer tipo presente en cada fila, nunca la suma**: Meta devuelve a la vez el agregado y el
específico del píxel, que son el mismo evento contado dos veces. La lista por defecto está en
`config/services.php` (`services.meta.tipos_conversion`) y cada Cuenta puede sobrescribirla en su
ficha (`cuentas.tipos_conversion`). Para ver qué devuelve una cuenta de verdad:
`php artisan portal:meta-acciones {cuenta} --dias=30`.

**Presupuestos**: la sincronización llama además a `refrescarPresupuestos()`, que anota en cada
Línea el tope de gasto de su campaña en Meta. Lee `/campaigns` y, para las campañas sin CBO (cuyo
presupuesto vive en los adsets), suma `/adsets` por campaña. Graph da los importes en céntimos.
Va en su propio `try/catch`: si Meta falla ahí, las métricas ya están importadas y la
sincronización no se tira por un dato de apoyo. **Nunca escribe en `presupuesto_eventos`.**

**Rango de fechas**: `sincronizar($cuenta, $dias)` hace la ventana móvil de siempre;
`sincronizarRango($cuenta, $desde, $hasta)` baja un periodo concreto para recuperar histórico
(fechas `Y-m-d`, se enderezan si vienen al revés y el final se recorta a hoy). Mismo upsert, así
que rebajar días ya importados los sobrescribe. Está en el botón "Sincronizar ahora" (dos fechas
opcionales) y en el comando (`--desde` / `--hasta`).

### Google Ads

[GoogleAdsConnector](app/Services/Sync/GoogleAdsConnector.php) consulta GAQL contra
`googleAds:search` (REST, con paginación) y mapea a las mismas filas normalizadas. Diferencias con
Meta que explican por qué el código no es simétrico:

- **Tokens en dos escalones**: el `refresh_token` no caduca y con él se pide un `access_token` que
  dura una hora. El access se guarda en `conexiones_api.access_token` con su `expires_at` y se
  renueva solo cuando toca. No confundir esa fecha con la vida de la conexión.
- **Tres credenciales de agencia** en `.env`, comunes a todos los clientes: `developer_token`
  (aprobado por Google desde la MCC), `client_id`/`client_secret` (proyecto de Google Cloud) y
  `login_customer_id` (la MCC). Lo único por cliente es su customer ID (`cuentas.external_id`).
- La cabecera `login-customer-id` y el customer ID van **solo con dígitos**: el conector quita los
  guiones de `123-456-7890`.
- Importes en **micros** (1.000.000 = 1 €), no en céntimos.
- **No hay alcance**: se importa 0.
- `metrics.conversions` son los leads.
- Los errores útiles de Google vienen anidados en `error.details[].errors[].message`; el conector
  los extrae, porque el `error.message` de fuera solo dice "invalid argument".

Dos métodos de conexión, por cuenta: `mcc` (refresh token de la MCC pegado a mano — **uno solo
sirve para todas las cuentas que cuelgan de ella**) y `oauth` (el cliente autoriza su propia
cuenta, rutas `/conexiones/google/{autorizar,callback}`). El OAuth pide `access_type=offline` y
`prompt=consent`: sin ellos Google no devuelve refresh token y la conexión moriría en una hora.
Al conectar se hace una consulta de prueba, para que el error salga en ese momento y no de
madrugada.

Comandos: `php artisan portal:sync` (todas las cuentas conectadas, de cualquier plataforma con
conector), `--cuenta=ID`, `--dias=7`, `--desde=2026-04-01 --hasta=2026-07-31`.
El cron de Plesk que mueve el scheduler está documentado en docs/DESPLIEGUE.md. El botón
"Sincronizar ahora" vive en la ficha de la Cuenta; "Desconectar API" borra el token (los datos
importados se conservan).

---

## 7. Dashboard del cliente

[resources/views/portal/dashboard.blade.php](resources/views/portal/dashboard.blade.php) +
[DashboardController.php](app/Http/Controllers/DashboardController.php).

Funciona como SPA: el controlador vuelca a la página **todo el dataset diario por línea/canal en
JSON** y el navegador resuelve rango de fechas, comparación, filtros por canal, orden, búsqueda,
paginación y gráfico sin recargar.

**Scope y selector de cliente.** El alcance lo decide `User::clientesVisibles()`, que es la única
fuente de verdad: un usuario de cliente ve el suyo, un admin ve todos los clientes activos.
Ampliar el acceso (una cartera por usuario, un permiso de solo lectura) es cambiar **ese método**,
no los controladores. Reglas que no se pueden romper:

- El cliente a pintar se resuelve **siempre contra esa lista**, nunca desde la URL. Un slug que no
  esté permitido se ignora y se cae al primero, sin filtrar si ese cliente existe.
- La página lleva el dataset de **un solo cliente**, así que el selector recarga (`?cliente=slug`,
  con la última elección recordada en sesión). No mandes varios clientes al navegador.
- Para el admin es la **vista del cliente**: `gasto_real` y el tope de plataforma siguen sin salir.
  Se marca con una etiqueta "vista de cliente" y el enlace de volver al panel.

Desde `/admin → Clientes` hay una acción "Ver dashboard" que abre el de ese cliente.

Incluye: presets de rango (7/30 días, este mes, mes anterior, todo), comparación con periodo
anterior y deltas en los KPIs, selector de métrica del gráfico, chips por canal, tarjetas por canal
con logos SVG inline, tabla de campañas con borde de color por canal, export CSV (formato ES: `;`
+ BOM) y PDF (`window.print()` + `@media print`).

- El logo del cliente se sirve por ruta propia `/media/logo/{cliente}` (`route('cliente.logo')`),
  **para no depender de `storage:link`**. Si añades otras imágenes subidas, sigue este patrón o
  asegúrate primero del symlink.
- **Una línea sin presupuesto del mes muestra inversión/CPC/CPL a 0.** Clics, leads e impresiones
  reales sí se ven. No es un bug: falta el evento de presupuesto en la ficha de la Línea. Como la
  sincronización crea líneas solas, en `/admin → Líneas` hay columna "Objetivo cliente", filtro
  "Solo las que no tienen presupuesto" y acción masiva "Asignar objetivo del mes" para cazarlas.

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
php artisan portal:sync --cuenta=3 --desde=2026-04-01 --hasta=2026-07-31   # recuperar histórico
php artisan portal:meta-acciones 3   # qué conversiones devuelve Meta en esa cuenta
php artisan migrate --force
php artisan optimize:clear     # obligatorio tras tocar config/ en producción
composer test
```
