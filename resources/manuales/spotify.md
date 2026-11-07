# Cómo conectar Spotify Ads al portal

## Antes de empezar

Calcula unos 20 minutos, más hasta **una hora de espera** después de aceptar los términos de la API.

Vas a necesitar:

- Acceso de administrador al portal (`/admin`).
- Una cuenta de Spotify para crear la app.
- Un usuario de Spotify con acceso a la cuenta publicitaria del cliente en [Ads Manager](https://adsmanager.spotify.com/).
- El **ID de la cuenta publicitaria**: el UUID que aparece en la URL de Ads Manager. Tiene este formato: `12345678-abcd-1234-abcd-1234567890ab`.

Spotify tiene un solo método de conexión: un usuario con acceso a la cuenta autoriza el canal.

## Paso 1 · Crear la app de Spotify

1. Entra en el [panel de desarrolladores de Spotify](https://developer.spotify.com/dashboard) y crea una app.
2. Cuando pregunte qué APIs vas a usar, selecciona **Ads API**.
3. Añade como **Redirect URI** la URL de retorno del portal:

   `{url_retorno}`

4. Guarda la app y copia el **Client ID** y el **Client Secret**.

## Paso 2 · Aceptar los términos de la Ads API

Sin este paso la conexión falla, aunque el inicio de sesión funcione.

1. Abre [adsmanager.spotify.com/api-terms](https://adsmanager.spotify.com/api-terms).
2. Introduce tu **Client ID** y acepta los términos.
3. Espera: el Client ID puede tardar **hasta una hora** en quedar habilitado.

## Paso 3 · Guardar las credenciales en el portal

1. En `/admin`, abre **Mi agencia** y despliega **Apps de API**.
2. Rellena **Spotify · Client ID** y **Spotify · Client Secret**.
3. Guarda los cambios. Los secretos se almacenan cifrados.

## Paso 4 · Crear y conectar el canal

1. En `/admin`, ve a **Canales → Nuevo** y rellena **Cliente**, **Plataforma** (Spotify Ads), **Nombre de la cuenta** e **ID de cuenta en la plataforma** (el UUID). Aunque el formulario diga "opcional", para conectar por API es obligatorio.
2. Guarda y abre la ficha del canal.
3. Pulsa **Conectar con Spotify** e inicia sesión con un usuario que tenga acceso a esa cuenta en Ads Manager.
4. Vuelves a la ficha con el aviso **Canal conectado con la API de Spotify**. El portal hace una lectura de prueba en ese momento, así que si algo falla lo verás ahí mismo.

A partir de ahí los datos se sincronizan solos cada mañana. El acceso se renueva solo y no caduca, salvo que se revoque. Si hay que repetir el proceso, pulsa **Reconectar con Spotify**. Para recuperar histórico, usa **Sincronizar ahora** con un rango de fechas.

## Qué se cuenta como lead

El portal usa el **primer campo con valor**, nunca la suma. Por defecto, `LEADS` y luego `SIGN_UPS`, que requieren tener el píxel de Spotify en la web.

Para cambiarlo, edita **Qué cuenta como lead** en la ficha del canal. Valen `LEADS`, `SIGN_UPS`, `PURCHASES`, `ADD_TO_CART`, `START_CHECKOUT`, `PAGE_VIEWS` y de `CUSTOM_EVENT_1` a `CUSTOM_EVENT_5`.

## Diferencias que puedes ver con Ads Manager

- **Pocas conversiones:** por privacidad, Spotify no da la cifra exacta si hay entre 1 y 4. El portal las cuenta como 0, así que el total de leads puede quedar algo por debajo del real.
- **Horario:** los días van en horario UTC, de modo que el reparto por día puede variar respecto a lo que veas en otra zona horaria.

## Sin API

El portal todavía no lee informes exportados de Spotify. Si intentas importar uno verás el error "Todavía no hay lector de informes para spotify". De momento, los datos de esta plataforma solo llegan por la API.

## Problemas frecuentes

| Mensaje o síntoma | Causa probable | Solución |
| :- | :- | :- |
| Falta configurar la app de Spotify (client ID y client secret)… | Faltan datos en Mi agencia | Paso 3 |
| Antes de conectar, rellena el "ID de cuenta en la plataforma"… | El canal no tiene el UUID | Paso 4 |
| El ID de cuenta de Spotify (external_id) debe ser un UUID… | Se ha escrito otro dato | Cópialo de la URL de Ads Manager |
| Error 401 o 403 con el aviso de los términos | No se han aceptado los términos, aún no ha pasado la hora de espera, o el usuario no tiene acceso a la cuenta | Paso 2. Después, comprueba el acceso en Ads Manager |
| La pantalla de Spotify rechaza la URL de redirección | La URL de retorno no está registrada o no coincide | Paso 1 |
| Los leads salen a 0 | No hay píxel, la cuenta mide otro evento o hay muy pocas conversiones | Revisa el píxel y **Qué cuenta como lead** |
| La sesión de conexión caducó o no coincide | Pasó demasiado tiempo o se abrió en otra pestaña | Vuelve a pulsar el botón desde la ficha del canal |

Si un error no aparece aquí, haz una captura del mensaje completo de la ficha del canal y envíanosla.

## Fuentes

- [Spotify · Guía de inicio de la Ads API](https://developer.spotify.com/documentation/ads-api/quick-start)
