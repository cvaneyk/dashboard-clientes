# clientes-portal — contexto del proyecto

Documento de traspaso: qué es el proyecto, por qué está hecho así, en qué punto está y qué queda.
Para las reglas técnicas del día a día, ver [../CLAUDE.md](../CLAUDE.md).

Última revisión: 22 de septiembre de 2026.

---

## 1. Qué resuelve

Portal de clientes de Paid Media de OliMedia. Cada cliente entra con usuario y contraseña a su
dashboard y ve sus campañas por canal (Meta, Google, TikTok; LinkedIn a futuro) y una visión
consolidada mensual.

Los datos se cargan desde los CSV/Excel que exportan las plataformas: subida manual, varias veces
al mes, siempre con desglose por día. La fase futura es sustituir esa subida por las APIs de cada
plataforma.

Dos roles:

- **Admin / account manager** (`users.es_admin = true`, `cliente_id = null`): panel Filament en
  `/admin` — clientes, cuentas, líneas, presupuestos, importaciones, usuarios.
- **Cliente** (`users.cliente_id = X`): solo el dashboard de su cliente, entrando por `/login`.

La app es **independiente del WordPress de olimedia.es**. Vive en el subdominio
`clientes.olimedia.es`.

---

## 2. La decisión de diseño central

El cliente no ve el gasto real de la plataforma, ve **lo que se le factura**: el objetivo mensual
prorrateado por días. Eso permite subir o bajar un presupuesto a mitad de mes, pausar una línea o
mover dinero entre líneas sin que el histórico quede incoherente, porque el reparto se renivela
solo sobre los días que quedan.

Dos consecuencias que explican casi toda la arquitectura:

1. Los presupuestos se guardan como **eventos con fecha efectiva**, no como un valor editable.
2. El coste-cliente **no se guarda en base de datos**: se calcula al vuelo en cada consulta. Por
   eso corregir un presupuesto del mes pasado recalcula todo sin migrar ni reimportar nada.

El gasto real sí se guarda (`metricas_diarias.gasto_real`), pero es solo interno: sirve para el
margen y nunca se muestra al cliente.

---

## 3. Estado actual

MVP funcional y en producción, con un cliente piloto: **"Mi universidad"**, con los tres canales
importados (Meta + Google + TikTok). El informe de Google cargado son 628 filas, abril–julio de
2026, con conversiones.

Lo que ya funciona de punta a punta:

- Panel admin completo (clientes, cuentas, líneas, presupuestos, métricas, importaciones, usuarios).
- Importación multicanal desde el panel, idempotente y reversible (borrar la importación borra sus
  métricas).
- Dashboard de cliente v2: rango de fechas con presets, comparación con periodo anterior, filtros
  por canal, gráfico con selector de métrica, tabla de campañas con buscador/orden/paginación,
  export CSV y PDF.
- Logos de cliente subibles y servidos por ruta propia.
- Cambio de contraseña del cliente en `/cuenta`.
- Guards separados admin/cliente (fin del 403 intermitente).

---

## 4. Historial de commits clave

| Commit | Qué trajo |
|---|---|
| `9a453d7` | Dashboard v2 (rango de fechas, comparar, filtros de canal, logos, tabla, export CSV/PDF) + cambio de contraseña del cliente + separación admin/cliente + logo subible |
| `8d9edd2` | Guards admin/cliente separados (fin del 403) + tipo de usuario claro + logo servido por ruta propia |
| `08a8e29` | Importaciones: eliminar una importación borra sus métricas + botón eliminar + columnas legibles |
| `b1eb74b` | Importaciones: botones Editar y Eliminar juntos en cada fila |
| `e5f99fb` | Importador: fechas `DD/MM/AAAA` además de ISO (Google Ads en español) — último desplegado y confirmado en producción |

---

## 5. Pendiente / roadmap

Por orden aproximado de prioridad:

1. **Panel de margen interno** (real vs. cliente) mediante un % de incremento por línea y mes.
   Javier lo estaba validando con los account managers antes de implementarlo.
2. **Añadir `php artisan storage:link` a las acciones de deploy**, por si se usan otras URLs de
   `public` además de la ruta `/media/logo/{cliente}`.
3. **Línea "Última actualización"** discreta antes del footer del dashboard. Aprobada, sin hacer.
4. **Verificar el responsive móvil** del dashboard v2.
5. **Alertas de picos**: job programado que marque líneas con gasto o clics anómalos.
6. **LinkedIn**: parser propio. Mismo esquema interno, solo cambia el parser.
7. **APIs de cada plataforma** para sustituir la subida manual.
8. **Backups de la base de datos.**

Trampa conocida a documentar para el usuario final: una línea sin presupuesto del mes enseña
inversión, CPC y CPL a 0. Los clics, leads e impresiones reales sí se ven. La solución no es
tocar código, es rellenar el presupuesto en la ficha de la Línea.

---

## 6. Entorno y accesos

- Subdominio: `clientes.olimedia.es` · panel admin en `/admin` · clientes en `/login`.
- Usuario admin del panel: `admin@olimedia.es` (la contraseña la custodia Javier; no se guarda
  en el repo).
- Repo: `github.com/cvaneyk/clientes-portal` — fuente de verdad.
- Despliegue: Plesk Git. Ver [DESPLIEGUE.md](DESPLIEGUE.md).
- Base de datos: MySQL en el mismo VPS. Credenciales solo en el `.env` del servidor, que está en
  `.gitignore`.
