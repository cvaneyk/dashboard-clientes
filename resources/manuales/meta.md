# Cómo conectar Meta Ads al portal

## Antes de empezar

Calcula unos 30–45 minutos la primera vez, más lo que tarde Meta en revisar la app si necesitas el método B.

Vas a necesitar:

- Acceso de administrador al portal (`/admin`).
- Acceso de administrador al portfolio empresarial (Business Manager) de la agencia.
- El **ID de la cuenta publicitaria** de cada canal. Aparece en el selector de cuentas de Ads Manager y en la URL (`act=123456789`). Vale con o sin el prefijo `act_`.

### ¿Qué método elijo?

| Situación | Método | Botón en el portal |
| :- | :- | :- |
| La cuenta publicitaria está compartida con el portfolio de la agencia | A · System User | Conectar con token de System User |
| La cuenta no está en vuestro portfolio y el acceso lo da una persona con su usuario de Facebook | B · Cuenta del cliente | Conectar con OAuth del cliente |

Si gestionáis las cuentas desde vuestro portfolio, usa el método A: el token sirve para todas las cuentas que le asignes y no depende del usuario de ninguna persona.

## Paso 1 · Crear la app de Meta

Los dos métodos necesitan una app. En el método A solo se usa para generar el token: no hay que guardar sus datos en el portal.

1. Entra en [Meta for Developers](https://developers.facebook.com/apps/) y crea una app de empresa vinculada al portfolio de la agencia.
2. Añade el producto **Marketing API**.
3. Comprueba el nivel en **App Dashboard → App Review → Permissions and Features**.

| Nivel de la Marketing API | Límites de peticiones | Uso según Meta | Cómo se consigue |
| :- | :- | :- | :- |
| Limited Access (antes Standard) | Muy estrictos, por cuenta publicitaria | Desarrollo; no para apps en producción con anunciantes reales | Automático al añadir el producto |
| Full Access (antes Advanced) | Mucho más amplios | Producción | App Review, con al menos 500 llamadas a la API en 15 días y menos de un 15 % de errores en las últimas 500 |

> Meta indica que una app que gestiona cuentas publicitarias de otras personas necesita **acceso avanzado** a `ads_read`. Pídelo en **App Review → Permissions and Features → Request advanced access**. Puede exigir la verificación de la empresa.

## Método A · Conectar con System User

### Generar el token (una vez)

1. Abre la configuración de tu portfolio empresarial en [business.facebook.com/settings](https://business.facebook.com/settings) y ve a **Usuarios → Usuarios del sistema**.
2. Crea un usuario del sistema con el rol de empleado.
3. Asígnale las cuentas publicitarias que vayas a conectar, al menos con permiso para ver el rendimiento.
4. Pulsa **Generar token** y elige la app del paso 1.
5. En la caducidad elige **Nunca**. El portal no renueva los tokens de System User: uno de 60 días dejaría de funcionar sin más.
6. Marca el permiso `ads_read` y genera el token.
7. Cópialo enseguida y guárdalo en un sitio seguro. Trátalo como una contraseña.

Cuando añadas una cuenta nueva, asígnala a este usuario del sistema antes de conectarla en el portal.

### Conectar cada canal

1. En `/admin`, ve a **Canales → Nuevo** y rellena **Cliente**, **Plataforma** (Meta Ads), **Nombre de la cuenta** e **ID de cuenta en la plataforma**. Aunque el formulario diga "opcional", para conectar por API es obligatorio.
2. Guarda y abre la ficha del canal.
3. Pulsa **Conectar con token de System User**, pega el token y pulsa **Guardar token**.
4. Pulsa **Sincronizar ahora** para comprobarlo. Este método no hace ninguna lectura de prueba al guardar, así que un token incorrecto no da error hasta este paso.

## Método B · Conectar con la cuenta del cliente

La persona que pulsa el botón inicia sesión en Facebook, así que tiene que hacerlo alguien con acceso a esa cuenta publicitaria.

### Configurar la app (una vez)

1. Añade a la app del paso 1 el producto **Facebook Login**.
2. En sus ajustes, añade la URL de retorno del portal en **Valid OAuth Redirect URIs**:

   `{url_retorno}`

3. Publica la app (modo **Live**). En modo de desarrollo solo pueden autorizar las personas con un rol en la app.
4. Pide el acceso avanzado a `ads_read`, como se indica en el paso 1.
5. Copia el **App ID** y el **App Secret** desde **App settings → Basic**.
6. En `/admin` → **Mi agencia → Apps de API**, rellena **Meta · App ID** y **Meta · App Secret**, y guarda.

### Conectar cada canal

1. Crea el canal como se indica en el método A, con su ID de cuenta.
2. En la ficha del canal, pulsa **Conectar con OAuth del cliente**.
3. Inicia sesión en Facebook y acepta el permiso. El portal solo lee datos; no modifica campañas.
4. Vuelves a la ficha con el aviso **Canal conectado con la API de Meta**.

El token dura unos 60 días y el portal lo renueva solo cuando quedan menos de 7. Si llega a caducar o la persona pierde el acceso, pulsa **Reconectar (OAuth del cliente)**.

## Qué se cuenta como lead

Meta devuelve varios tipos de conversión a la vez. El portal usa, para cada campaña, el **primero de la lista que aparezca**, nunca la suma, porque un mismo registro puede venir contado dos veces. La lista por defecto es:

`lead, complete_registration, offsite_conversion.fb_pixel_complete_registration, onsite_conversion.lead_grouped`

Si una cuenta mide otra cosa, escribe su lista en **Qué cuenta como lead** dentro de la ficha del canal.

## Sin API · Subir un informe

1. En Ads Manager, exporta un informe en Excel **desglosado por día**, en español. Tiene que traer las columnas `Nombre de la campaña`, `Día`, `Alcance`, `Impresiones`, `Importe gastado (EUR)`, `Clics (todos)` y `Registros completados`.
2. En `/admin` → **Importaciones**, pulsa **Importar informe**.
3. Elige el canal correcto, sube el archivo y pulsa **Importar**.

Los nombres de columna tienen que coincidir exactamente: una columna con otro nombre se importa a 0. Por informe, los leads salen **solo** de `Registros completados`, sea cual sea la lista de la ficha. En una cuenta de formularios de Lead Ads, usa la API.

## Problemas frecuentes

| Mensaje o síntoma | Causa probable | Solución |
| :- | :- | :- |
| Falta configurar la app de Meta (App ID y App Secret)… | Faltan datos en Mi agencia | Método B, configuración de la app |
| Antes de conectar, rellena el "ID de cuenta en la plataforma" | El canal no tiene el ID | Rellénalo en la ficha del canal |
| La pantalla de Facebook rechaza la URL de redirección | La URL de retorno no está registrada o no coincide | Cópiala exacta en **Valid OAuth Redirect URIs** |
| `(#274) The ad account is not enabled for usage in Ads API` | El usuario o System User no tiene un rol adecuado en esa cuenta | Asígnale la cuenta en el portfolio |
| Error de permisos en cuentas de terceros | La app no tiene acceso avanzado a `ads_read` | Paso 1 |
| Errores de límite de peticiones | La app sigue en Limited Access o el rango pedido es muy largo | Pide Full Access o sincroniza por tramos más cortos |
| Funciona unos 60 días y luego falla | Token de 60 días o token de usuario caducado | Genera uno que no caduque (método A) o reconecta (método B) |
| Los leads salen a 0 o no cuadran | La cuenta mide un tipo de conversión distinto | Ajusta **Qué cuenta como lead** |
| La sesión de conexión caducó o no coincide | Pasó demasiado tiempo o se abrió en otra pestaña | Vuelve a pulsar el botón desde la ficha del canal |

Si un error no aparece aquí, haz una captura del mensaje completo de la ficha del canal y envíanosla.

## Fuentes

- [Meta · Usuarios del sistema](https://developers.facebook.com/docs/marketing-api/system-users/)
- [Meta · Generar tokens de usuario del sistema](https://developers.facebook.com/docs/marketing-api/businessmanager/systemuser/install-apps-and-generate-tokens)
- [Meta · Autorización y niveles de acceso](https://developers.facebook.com/docs/marketing-api/get-started/authorization)
