# Cómo conectar LinkedIn Ads al portal

## Antes de empezar

La configuración lleva unos 30 minutos, pero hay que contar además con la **revisión de LinkedIn**, que no es inmediata. Solicita el acceso con tiempo.

Vas a necesitar:

- Acceso de administrador al portal (`/admin`).
- Una **página de empresa de LinkedIn** de la agencia, necesaria para crear la app.
- Un usuario de LinkedIn con rol en la cuenta publicitaria del cliente. Basta con el rol de solo lectura (**Viewer**).
- El **ID de la cuenta publicitaria**: el número de nueve cifras que aparece en Campaign Manager.

LinkedIn tiene un solo método de conexión. No existe un token de agencia: cada canal lo autoriza un usuario con acceso a esa cuenta.

## Paso 1 · Crear la app y pedir acceso a la API

1. Crea la app en el [portal de desarrolladores de LinkedIn](https://www.linkedin.com/developers/apps/new) y asóciala a la página de empresa.
2. En la pestaña **Products**, solicita **Advertising API** (el portal la llama también Marketing Developer Platform) y rellena el formulario.
3. Espera la aprobación. Los permisos se conceden solos al aprobarla.
4. En la pestaña **Auth**, comprueba que aparecen `r_ads` y `r_ads_reporting`.

El nivel **Development**, que es el que se da por defecto, es suficiente: permite leer sin límite las cuentas que administras, y el portal solo lee.

> En el nivel Development hay que dar de alta cada cuenta: en la pestaña **Products**, pulsa **View Ad Accounts → Add Ad Account**, escribe el ID de nueve cifras y guarda.

## Paso 2 · Registrar la URL de retorno

1. En la pestaña **Auth**, añade en **Authorized redirect URLs for your app**:

   `{url_retorno}`

2. Copia el **Client ID** y el **Client Secret** de esa misma pestaña.

## Paso 3 · Guardar las credenciales en el portal

1. En `/admin`, abre **Mi agencia** y despliega **Apps de API**.
2. Rellena **LinkedIn · Client ID** y **LinkedIn · Client Secret**.
3. Guarda los cambios. Los secretos se almacenan cifrados.

## Paso 4 · Conseguir acceso a la cuenta del cliente

1. Pide al cliente que dé acceso a la cuenta publicitaria a un usuario de la agencia, desde Campaign Manager o LinkedIn Business Manager.
2. Comprueba que ese usuario ve la cuenta en Campaign Manager antes de seguir.

## Paso 5 · Crear y conectar el canal

1. En `/admin`, ve a **Canales → Nuevo** y rellena **Cliente**, **Plataforma** (LinkedIn Ads), **Nombre de la cuenta** e **ID de cuenta en la plataforma**. Aunque el formulario diga "opcional", para conectar por API es obligatorio.
2. Guarda y abre la ficha del canal.
3. Pulsa **Conectar con LinkedIn** e inicia sesión con el usuario del paso 4.
4. Vuelves a la ficha con el aviso **Canal conectado con la API de LinkedIn**.
5. Pulsa **Sincronizar ahora** para confirmar que llegan datos. Si eliges unas fechas, también recupera el histórico.

A partir de ahí los datos se sincronizan solos cada mañana. El acceso dura unos 60 días y el portal lo renueva con el refresh token, que dura alrededor de un año. Cuando caduque, pulsa **Reconectar con LinkedIn**.

## Qué se cuenta como lead

El portal usa el **primer campo con valor**, nunca la suma, porque un lead de formulario suele registrarse también como conversión web. Por defecto:

1. `oneClickLeads`: formularios de LinkedIn.
2. `externalWebsiteConversions`: conversiones en la web con la Insight Tag.

Para cambiar el orden, edita **Qué cuenta como lead** en la ficha del canal.

## Sin API

El portal todavía no lee informes exportados de LinkedIn. Si intentas importar uno verás el error "Todavía no hay lector de informes para linkedin". De momento, los datos de esta plataforma solo llegan por la API.

## Problemas frecuentes

| Mensaje o síntoma | Causa probable | Solución |
| :- | :- | :- |
| Falta configurar la app de LinkedIn (client ID y client secret)… | Faltan datos en Mi agencia | Paso 3 |
| Antes de conectar, rellena el "ID de cuenta en la plataforma"… | El canal no tiene el ID | Paso 5 |
| The requested permission scope is not valid | Los permisos pedidos no coinciden con los concedidos a la app, o aún no está aprobada | Paso 1. Si la app tiene otros permisos, quien administre el servidor debe ajustar `LINKEDIN_ADS_SCOPES` |
| La pantalla de LinkedIn rechaza la URL de redirección | La URL de retorno no está registrada o no coincide | Paso 2 |
| Error de permisos al sincronizar | El usuario no tiene rol en la cuenta o, en nivel Development, la cuenta no está dada de alta en la app | Pasos 1 y 4 |
| Filas descartadas en el resultado | LinkedIn no devolvió el nombre de alguna campaña | Revisa el acceso del usuario a la cuenta y vuelve a sincronizar |
| Requested version … is not active | LinkedIn retiró la versión de la API que usa el portal | Avisa a quien administre el servidor: hay que subir `LINKEDIN_ADS_API_VERSION` |
| La sesión de conexión caducó o no coincide | Pasó demasiado tiempo o se abrió en otra pestaña | Vuelve a pulsar el botón desde la ficha del canal |

Si un error no aparece aquí, haz una captura del mensaje completo de la ficha del canal y envíanosla.

## Fuentes

- [LinkedIn · Guía de inicio de la API de marketing](https://learn.microsoft.com/en-us/linkedin/marketing/quick-start)
- [LinkedIn · Niveles de acceso](https://learn.microsoft.com/en-us/linkedin/marketing/increasing-access)
- [LinkedIn · Roles de las cuentas publicitarias](https://learn.microsoft.com/en-us/linkedin/marketing/integrations/ads/account-structure/account-access-controls)
