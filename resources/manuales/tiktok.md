# Cómo cargar los datos de TikTok Ads en el portal

## Antes de empezar

> **TikTok todavía no tiene conexión por API en el portal.** Los datos se cargan subiendo un informe, así que no se actualizan solos: hay que repetir el proceso cada vez que quieras cifras nuevas.

Calcula unos 5 minutos por informe.

Vas a necesitar:

- Acceso de administrador al portal (`/admin`).
- Acceso a la cuenta en [TikTok Ads Manager](https://ads.tiktok.com/), con el **idioma en inglés**. El portal reconoce las columnas por su nombre en inglés.

## Paso 1 · Crear el canal (una vez)

1. En `/admin`, ve a **Canales → Nuevo**.
2. Rellena **Cliente**, **Plataforma** (TikTok Ads) y **Nombre de la cuenta**. El **ID de cuenta en la plataforma** es opcional.
3. Guarda.

## Paso 2 · Exportar el informe de TikTok

Expórtalo desde la lista de campañas, no desde **Custom reports**: el portal espera una columna llamada `By Day`, y los informes personalizados usan la dimensión `Day`.

1. En TikTok Ads Manager, abre la lista de campañas y elige el periodo que quieres cargar.
2. Añade el desglose por día con **Breakdown → By Day**.
3. Comprueba que se ven estas columnas. Si falta alguna, añádela al personalizar las columnas:

   | Columna | Para qué se usa |
   | :- | :- |
   | `Campaign name` | Nombre de la campaña |
   | `By Day` | Fecha |
   | `Reach` | Alcance |
   | `Impressions` | Impresiones |
   | `Spend` | Gasto |
   | `Clicks (destination)` | Clics |
   | `Registrations (website)` | Leads |

4. Exporta los datos en formato Excel (`.xlsx`).

## Paso 3 · Subir el informe al portal

1. En `/admin` → **Importaciones**, pulsa **Importar informe**.
2. En **Canal**, elige el de TikTok de ese cliente.
3. Sube el archivo y pulsa **Importar**.
4. Espera a la notificación **Importación completada**, que indica filas, líneas nuevas, gasto y fechas. El aviso "upload complete" solo significa que el archivo se ha subido.

> Comprueba que el gasto y las fechas de la notificación coinciden con los de TikTok. Es la forma más rápida de detectar un informe mal exportado.

## Corregir una importación

- **Mismas campañas y fechas:** vuelve a subir el informe correcto. Sobrescribe los datos, no los duplica.
- **Informe equivocado o de otro cliente:** borra esa importación en **Importaciones**, lo que elimina también sus datos, y sube el correcto.

## Problemas frecuentes

| Mensaje o síntoma | Causa probable | Solución |
| :- | :- | :- |
| Importa 0 filas | El informe no trae `Campaign name` o `By Day`, o está en otro idioma | Vuelve a exportarlo como indica el paso 2, con TikTok en inglés |
| Las cifras de una métrica salen a 0 | Falta esa columna o tiene otro nombre | Revisa la tabla del paso 2 |
| Los leads salen a 0 | No hay ninguna columna que empiece por `Registrations` | Añade esa columna. Otras conversiones no se leen todavía |
| Los datos aparecen en otro cliente | El archivo se subió al canal equivocado | Borra la importación y súbelo al canal correcto |
| "Sincronizar ahora" no aparece en la ficha | TikTok no tiene conexión por API | Es normal: sube el informe |

Si un error no aparece aquí, envíanos una captura del mensaje y el archivo que has intentado subir.

## Fuentes

- [TikTok · Ver el rendimiento en Ads Manager](https://ads.tiktok.com/help/article/view-data)
