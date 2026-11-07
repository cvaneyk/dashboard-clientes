# Cómo conectar Google Ads al portal

## Antes de empezar

Calcula unos 30–45 minutos la primera vez. Los pasos 1 a 4 se hacen una sola vez por agencia y por canal; después eliges uno de los dos métodos de conexión.

Vas a necesitar:

- Acceso de administrador al portal (`/admin`).
- Una cuenta de Google con permiso para crear proyectos en [Google Cloud Console](https://console.cloud.google.com/).
- El **ID de cliente de Google Ads** de cada cuenta publicitaria: el número de 10 cifras que aparece arriba a la derecha en Google Ads (formato `123-456-7890`).
- Solo para el método B: el ID de vuestra cuenta de administrador (MCC) y un usuario con acceso a ella.

### ¿Qué método elijo?

| Situación | Método | Botón en el portal |
| :- | :- | :- |
| La cuenta de Google Ads es del anunciante y entra con su propio email | A · Cuenta del cliente | Conectar con la cuenta del cliente |
| La cuenta cuelga de la MCC de la agencia | B · MCC de la agencia | Conectar con la MCC de la agencia |

Si gestionáis varias cuentas desde una MCC, el método B es el más cómodo: el mismo token sirve para todas ellas y solo cambia el ID de cliente de cada canal.

## Paso 1 · Crear el proyecto de Google Cloud y pedir acceso a la API

Desde el 9 de septiembre de 2026 Google ya no usa el "developer token": el acceso a la API de Google Ads se concede al proyecto de Google Cloud ([fuente](https://developers.google.com/google-ads/api/docs/first-call/dev-token)).

1. Entra en [Google Cloud Console](https://console.cloud.google.com/) con la cuenta de Google de la agencia.
2. Arriba, en el selector de proyectos, pulsa **Proyecto nuevo**. Ponle un nombre reconocible (por ejemplo, Portal Paid Media) y créalo.
3. Con el proyecto seleccionado, ve a **APIs y servicios → Biblioteca**, busca **Google Ads API** y pulsa **Habilitar**.
4. Abre la página de resumen de **Google Ads API** dentro del proyecto y solicita el nivel de acceso **Explorer**. La revisión suele ser automática y rápida.
5. Espera a que el proyecto tenga acceso Explorer (o superior) antes de seguir. Con el acceso "Test" que se da por defecto solo se pueden leer cuentas de prueba, no las reales.

> No solicites el acceso desde el "Centro de API" de la cuenta MCC de Google Ads: Google ya no tramita esas solicitudes.

| Nivel | Cuentas reales | Operaciones al día | Cómo se consigue |
| :- | :- | :- | :- |
| Test | No | 15.000 (solo prueba) | Automático al habilitar la API |
| Explorer | Sí | 2.880 | Solicitud con revisión automática |
| Basic | Sí | 15.000 | Solicitud tras verificar la marca del proyecto |

Para un portal que sincroniza una vez al día, Explorer es suficiente ([niveles de acceso](https://developers.google.com/google-ads/api/docs/api-policy/access-levels)).

## Paso 2 · Pantalla de consentimiento y credenciales OAuth

Aquí obtienes el **Client ID** y el **Client Secret** que pide el portal.

### Pantalla de consentimiento

1. En el mismo proyecto, ve a **APIs y servicios → Pantalla de consentimiento de OAuth** (en algunas cuentas aparece como **Google Auth Platform**).
2. Tipo de usuario: **Externo**. Rellena el nombre de la app (el de tu agencia), el email de asistencia y el email de contacto.
3. En **Público**, pulsa **Publicar app** para pasarla a **En producción**. Importante: si se queda en "Prueba", Google invalida la conexión a los 7 días y habría que reconectar cada semana.

### Credenciales

1. Ve a **APIs y servicios → Credenciales → Crear credenciales → ID de cliente de OAuth**.
2. Tipo de aplicación: **Aplicación web**. Nombre: Portal.
3. En **URIs de redirección autorizados** añade la URL de retorno del portal:

   `{url_retorno}`

4. Si vas a usar el **método B** (MCC), añade también esta segunda URI:

   `https://developers.google.com/oauthplayground`

5. Pulsa **Crear** y copia el **ID de cliente** y el **Secreto del cliente**. Guárdalos en un sitio seguro.

> La URL de retorno tiene que coincidir carácter a carácter (con https, sin barra final). Si no coincide, Google mostrará el error `redirect_uri_mismatch`.

## Paso 3 · Guardar las credenciales en el portal

1. Entra en el portal en `/admin` y abre **Mi agencia**.
2. Despliega la sección **Apps de API** y rellena:

   | Campo | Qué poner |
   | :- | :- |
   | Google Ads · Client ID | El ID de cliente del paso 2 |
   | Google Ads · Client Secret | El secreto del cliente del paso 2 |
   | Google Ads · ID de la MCC | Solo si usáis el método B: el ID de vuestra MCC, con o sin guiones. Si solo vais a usar el método A, podéis dejarlo vacío |

3. Guarda los cambios. Los secretos se almacenan cifrados.

## Paso 4 · Crear el canal de Google Ads

Cada cuenta publicitaria de Google Ads es un **canal** en el portal.

1. En `/admin`, ve a **Canales → Nuevo**.
2. Rellena:
   - **Cliente**: el anunciante al que pertenece.
   - **Plataforma**: Google Ads.
   - **Nombre de la cuenta**: el que quieras ver en el portal.
   - **ID de cuenta en la plataforma**: el ID de cliente de Google Ads de 10 cifras (`123-456-7890`, con o sin guiones). Aunque el formulario diga "opcional", para conectar por API es obligatorio.
3. Guarda. Al abrir la ficha del canal verás arriba los dos botones de conexión.

## Método A · Conectar con la cuenta del cliente

La persona que pulsa el botón inicia sesión en Google, así que tiene que hacerlo alguien con acceso a esa cuenta de Google Ads: el propio anunciante o tú, si te ha dado acceso con tu email.

1. Abre la ficha del canal en `/admin` → **Canales**.
2. Pulsa **Conectar con la cuenta del cliente**.
3. Google te pedirá iniciar sesión. Usa la cuenta de Google que tiene acceso a la cuenta de Google Ads de ese canal.
4. Si aparece "Google no ha verificado esta aplicación", pulsa **Configuración avanzada → Ir a (nombre de tu app)**. Es tu propia app del paso 2.
5. Acepta el permiso para **ver y gestionar tus datos de Google Ads**. El portal solo lee datos; no modifica campañas.
6. Vuelves a la ficha del canal con el aviso **Canal conectado con la API de Google Ads**. El portal hace una lectura de prueba en ese momento, así que si algo falla lo verás ahí mismo.

A partir de ahí los datos se sincronizan solos cada mañana. El botón pasa a llamarse **Reconectar (cuenta del cliente)** por si algún día hay que repetir el proceso.

## Método B · Conectar con la MCC de la agencia

Aquí generas un **refresh token** una sola vez con un usuario que tenga acceso a la MCC, y lo pegas en el portal. El mismo token vale para todos los canales que cuelgan de esa MCC.

### Obtener el refresh token (una vez)

1. Comprueba que en el paso 2 añadiste `https://developers.google.com/oauthplayground` como URI de redirección.
2. Abre [OAuth 2.0 Playground](https://developers.google.com/oauthplayground).
3. Pulsa el engranaje de arriba a la derecha, marca **Use your own OAuth credentials** y pega tu Client ID y Client Secret.
4. En el cuadro de la izquierda, escribe este alcance y pulsa **Authorize APIs**:

   `https://www.googleapis.com/auth/adwords`

5. Inicia sesión con el usuario de Google que tiene acceso a vuestra MCC y acepta los permisos.
6. De vuelta en el Playground, pulsa **Exchange authorization code for tokens**.
7. Copia el valor de **Refresh token** (empieza por `1//`). Trátalo como una contraseña: no lo envíes por email ni lo dejes en documentos compartidos.

### Conectar cada canal

1. Abre la ficha del canal en `/admin` → **Canales**.
2. Pulsa **Conectar con la MCC de la agencia**.
3. Pega el refresh token. El campo **ID de la MCC** déjalo vacío si ya lo pusiste en Mi agencia; rellénalo solo si esta cuenta cuelga de otra MCC.
4. Pulsa **Guardar y comprobar**. Si sale **Canal conectado con Google Ads**, listo.
5. Repite en cada canal con el mismo token.

## Sin API · Subir un informe

Mientras la cuenta no esté conectada puedes cargar los datos a mano.

1. En Google Ads, descarga un informe de campañas **desglosado por día**, en español y en formato CSV. Tiene que traer las columnas `Campaña`, `Día`, `Impr.`, `Coste` y `Clics`. Si añades `Conversiones`, se usan como leads.
2. En `/admin` → **Importaciones**, pulsa **Importar informe**.
3. Elige el canal correcto, sube el archivo y pulsa **Importar**.

Volver a subir el mismo periodo sobrescribe los datos, no los duplica. El aviso "upload complete" solo indica que el archivo se ha subido: el resultado real es la notificación **Importación completada**.

## Problemas frecuentes

| Mensaje o síntoma | Causa probable | Solución |
| :- | :- | :- |
| Falta configurar Google Ads (client ID y client secret)… | Algún campo de Mi agencia está vacío | Revisa el paso 3 |
| Antes de conectar, rellena el "ID de cuenta en la plataforma" | El canal no tiene el ID de 10 cifras | Paso 4 |
| `redirect_uri_mismatch` en la pantalla de Google | La URL de retorno no coincide | Copia exacta de la URL del paso 2 |
| Google no devolvió refresh token | Esa cuenta de Google ya había autorizado la app antes | Quita el acceso en [myaccount.google.com/permissions](https://myaccount.google.com/permissions) y vuelve a conectar |
| `USER_PERMISSION_DENIED` o error de permisos | El usuario no tiene acceso a esa cuenta, o en el método A hay una MCC configurada en Mi agencia a la que el anunciante no pertenece | Método A: deja vacío el ID de la MCC. Método B: comprueba que la cuenta cuelga de la MCC |
| Error de autorización o de nivel de acceso | El proyecto sigue en acceso "Test" | Paso 1: solicita Explorer y espera la aprobación |
| Funciona unos días y luego falla | La app de OAuth está en modo "Prueba" | Paso 2: publica la app (En producción) y reconecta |
| La sesión de conexión caducó o no coincide | Pasó demasiado tiempo o se abrió en otra pestaña | Vuelve a pulsar el botón desde la ficha del canal |

Si un error no aparece aquí, haz una captura del mensaje completo de la ficha del canal y envíanosla.

## Fuentes

- [Google Ads API · Developer token (retirada del token)](https://developers.google.com/google-ads/api/docs/first-call/dev-token)
- [Google Ads API · Niveles de acceso](https://developers.google.com/google-ads/api/docs/api-policy/access-levels)
