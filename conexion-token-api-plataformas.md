## Meta

# Paso 1 Crear el System User
Entra en business.facebook.com/settings.
En el menú lateral: Usuarios → Usuarios del sistema.
Pulsa Añadir.
Ponle un nombre descriptivo, por ejemplo api-olimedia-ads o portal-clientes-bot. Evita espacios y nombres de personas.
Elige el rol:
Empleado: es la opción recomendada si solo va a leer o gestionar las cuentas publicitarias que le asignes. Es más segura.
Administrador: tiene acceso a todo el Business Manager. Úsalo solo si realmente lo necesitas.
Acepta las condiciones si te las muestra y pulsa Crear usuario del sistema.

# Paso 2 Asignarle la app

En Usuarios → Usuarios del sistema, selecciona el usuario que vayas a usar.
Pulsa Asignar activos.
En el tipo de activo, elige Apps.
Marca tu app, la que usará el token.
En permisos, activa Control total (en algunas interfaces aparece como Gestionar app o Desarrollar app). Es necesario para poder generar tokens para esa app.
Pulsa Asignar o Guardar cambios.

# Paso 3 Darle acceso a la cuenta publicitaria

Con Dashboard seleccionado, pulsa Asignar activos.
En el tipo de activo, elige Cuentas publicitarias.
Marca la cuenta o cuentas publicitarias a las que tenga que acceder. Si el panel es para varios clientes, marca todas las que necesites.
Elige el nivel de permiso:
Ver rendimiento: solo lectura de métricas. Es la opción adecuada si el dashboard solo muestra datos.
Gestionar campañas: permite crear y editar anuncios.
Control total: incluye además la gestión de la cuenta y de los permisos.
Pulsa Asignar.

# Paso 4 Generar el token con sus permisos

Con Dashboard seleccionado, pulsa Generar token (o Generar nuevo token).
Selecciona la app que asignaste en el paso 2.
Caducidad del token: elige Nunca. Así no tendrás que renovarlo cada 60 días, que es lo habitual en una integración de servidor.
Permisos (scopes): marca solo los que necesites:
ads_read: leer campañas, anuncios y métricas (insights). Imprescindible para un dashboard.
ads_management: solo si la app va a crear o editar anuncios.
business_management: para listar las cuentas publicitarias del Business Manager y acceder a sus activos. Recomendable.
read_insights: solo si también vas a leer estadísticas de páginas de Facebook.
Pulsa Generar token.
Copia el token en ese momento. Meta no te lo vuelve a mostrar. Si lo pierdes, tendrás que generar otro.