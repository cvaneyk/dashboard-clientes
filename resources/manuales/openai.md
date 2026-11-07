# Cómo conectar OpenAI Ads al portal

## Antes de empezar

Es la conexión más sencilla: unos 5 minutos por canal. No hay que crear ninguna app ni rellenar nada en Mi agencia.

Vas a necesitar:

- Acceso de administrador al portal (`/admin`).
- Acceso a la cuenta publicitaria del cliente en [Ads Manager de OpenAI](https://ads.openai.com/).

> **Cada clave da acceso a una sola cuenta publicitaria**, así que cada canal necesita la suya. Tiene que ser una clave creada en Ads Manager: las de `platform.openai.com` no sirven aquí.

## Paso 1 · Generar la clave de API

1. Entra en la [página de ajustes de Ads Manager](https://ads.openai.com/settings) con acceso a la cuenta del cliente.
2. Genera una clave de API.
3. Cópiala y guárdala en un sitio seguro. Trátala como una contraseña: no la envíes por email ni la dejes en documentos compartidos.

## Paso 2 · Crear y conectar el canal

1. En `/admin`, ve a **Canales → Nuevo** y rellena **Cliente**, **Plataforma** (OpenAI Ads) y **Nombre de la cuenta**. El **ID de cuenta en la plataforma** puede quedar vacío: se rellena solo al conectar.
2. Guarda y abre la ficha del canal.
3. Pulsa **Conectar con clave de API de OpenAI**, pega la clave y pulsa **Guardar y comprobar**.
4. Si sale **Canal conectado con OpenAI Ads**, la conexión funciona.

Si el canal ya tenía un ID y la clave es de otra cuenta, el portal la rechaza. Así se evita cargar por error los datos de otro cliente.

A partir de ahí los datos se sincronizan solos cada mañana. Para recuperar histórico, usa **Sincronizar ahora** con un rango de fechas. Si hay que sustituir la clave, pulsa **Cambiar clave de API de OpenAI**.

## Qué datos llegan

- **Leads:** son las conversiones de la campaña, con las ventanas de atribución por defecto (30 días tras el clic y 1 día tras la visualización).
- **Alcance:** OpenAI no lo facilita, así que aparece a 0.
- **Día en curso:** solo se traen días completos. Los datos de hoy llegan en una sincronización posterior.
- **Horario:** los días van en la zona horaria de la cuenta publicitaria.

## Sin API

El portal todavía no lee informes exportados de OpenAI. Si intentas importar uno verás el error "Todavía no hay lector de informes para openai". De momento, los datos de esta plataforma solo llegan por la API.

## Problemas frecuentes

| Mensaje o síntoma | Causa probable | Solución |
| :- | :- | :- |
| La clave de API no es válida o se revocó… | Clave mal copiada, revocada o de `platform.openai.com` | Genera otra en Ads Manager y vuelve a pegarla |
| Esa clave es de la cuenta … no de … | La clave es de otro cliente, o el ID del canal está mal | Usa la clave de este cliente o corrige el ID |
| OpenAI solo da días completos… | Se ha pedido solo el día de hoy | Es normal: llegará en la próxima sincronización |
| Los leads salen a 0 | La campaña no tiene conversiones configuradas o no las ha registrado | Revísalo en Ads Manager |

Si un error no aparece aquí, haz una captura del mensaje completo de la ficha del canal y envíanosla.

## Fuentes

- [OpenAI · Resumen de la Advertiser API](https://developers.openai.com/ads/api-overview)
- [OpenAI · Autenticación](https://developers.openai.com/ads/api-reference/authentication)
