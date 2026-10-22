# Taquería San Isidro

App web para llevar el control de una taquería pequeña: ventas, gastos e insumos, costo por taco y ganancia.

Laravel 13, Livewire 4, Tailwind 4, PostgreSQL 17. Todo corre en Docker.

## Desarrollo local

Requiere Docker con Compose.

```sh
git clone <url-del-repositorio> taqueria
cd taqueria
export UID GID=$(id -g)
docker compose up -d --build
```

La primera vez crea `.env`, instala dependencias, genera la llave y corre las migraciones.

- Clientes: http://localhost:8090
- Sistema del taquero: http://localhost:8090/admon
- Vite (HMR): puerto 5174
- PostgreSQL: puerto 5433, solo desde este equipo (usuario `taqueria`, password `secret`, base `taqueria`)

En local el seeder crea el usuario `admin@taqueria.test` con contraseña `password`.

Los puertos se cambian en `.env` (`APP_PORT`, `VITE_PORT`, `FORWARD_DB_PORT`).

Los tests corren contra Postgres, en la base `taqueria_test`. Se crea sola con un volumen nuevo; si el volumen ya existía:

```sh
docker compose exec db createdb -U taqueria taqueria_test
```

## Producción

Se despliega como stack de Portainer desde este repo, detrás de Nginx Proxy Manager. `compose.prod.yaml` construye la imagen con el código, las dependencias sin las de desarrollo y los assets ya compilados; no publica puertos (el proxy entra por la red `npm`) y al arrancar corre las migraciones y `php artisan optimize`.

En Portainer, Stacks > Add stack > Repository:

- Repository URL: la de este repo, rama `refs/heads/main`.
- Compose path: `compose.prod.yaml`.
- Authentication: token de GitHub, si el repo es privado.
- Environment variables:
  - `APP_KEY`: `echo "base64:$(openssl rand -base64 32)"`. No se cambia después.
  - `APP_URL`: la URL pública con https.
  - `DB_PASSWORD`: `openssl rand -base64 24`.
  - `REVERB_ALLOWED_ORIGINS`: el dominio público, sin https (ver Tiempo real).

En Nginx Proxy Manager, un Proxy Host con el dominio que reenvía a `taqueria-web`, puerto 80, con certificado SSL.

Primer usuario, desde la consola del contenedor `taqueria-app` en Portainer:

```sh
php artisan app:create-user
```

El logo subido vive en el volumen `storage` y la base en `db-data`.

### Actualización de PHP del 21 de octubre de 2026

Los Dockerfile de desarrollo y producción usan `php:8.4.27-fpm`, que incluye las correcciones de CVE-2026-109618 y CVE-2026-109619. El [aviso oficial de PHP](https://www.php.net/archive/2026.php#2026-10-21-5) incluye los servidores PHP-FPM y pide actualizar. Cambiar el código del repositorio no actualiza los contenedores que ya están funcionando.

Desde el servidor, con las variables de producción cargadas, reconstruye y recrea los servicios PHP:

```sh
docker compose -f compose.prod.yaml build --pull app web
docker compose -f compose.prod.yaml up -d --force-recreate app web reverb scheduler
docker compose -f compose.prod.yaml exec app php -v
docker compose -f compose.prod.yaml exec reverb php -v
docker compose -f compose.prod.yaml exec scheduler php -v
```

Comprueba PHP 8.4.27 en los tres servicios. En Portainer, reconstruye las imágenes del stack y recrea esos servicios; comprueba la versión desde sus consolas. Conserva los volúmenes. `composer audit` revisa las bibliotecas del proyecto y no verifica la versión del motor PHP.

El contenedor `taqueria-backup` respalda la base una vez al día en el volumen `backups` y guarda los últimos 14 días (`BACKUP_KEEP_DAYS`). Están en el mismo servidor: de vez en cuando conviene bajar uno a otro lado.

```sh
# Ver los respaldos
docker exec taqueria-backup ls -lh /backups
# Bajar uno a esta carpeta
docker cp taqueria-backup:/backups/taqueria-AAAA-MM-DD.dump .
# Restaurar (borra lo que haya en la base)
docker exec -i taqueria-db pg_restore -U taqueria -d taqueria --clean --if-exists < taqueria-AAAA-MM-DD.dump
```

## Tiempo real

Docker levanta `reverb` en el puerto interno 8080; solo desarrollo lo publica en `127.0.0.1:8080`. Laravel publica con `BROADCAST_CONNECTION=reverb`, `REVERB_HOST=reverb`, `REVERB_PORT=8080` y `REVERB_SCHEME=http`. Si corres PHP fuera de Docker, usa `REVERB_HOST=localhost`; Compose fija `reverb` dentro de los contenedores. El servidor escucha con `REVERB_SERVER_HOST=0.0.0.0` y `REVERB_SERVER_PORT=8080`.

En desarrollo, `.env.example` incluye credenciales locales. En Portainer son obligatorias `REVERB_APP_ID`, `REVERB_APP_KEY` y `REVERB_APP_SECRET`; usa valores propios (por ejemplo, `taqueria` como ID y `openssl rand -hex 32` para cada clave). La app y Reverb comparten estas variables. Solo el contenedor PHP-FPM corre migraciones y optimiza cachés al arrancar.

El navegador recibe la clave pública mediante `<x-realtime />` y usa el mismo dominio y puerto que la página, sin variables `VITE_REVERB_*` ni reconstruir assets al cambiar las credenciales. Nginx reenvía `/app` y `/apps` al servicio interno. En Nginx Proxy Manager activa **Websockets Support** y HTTPS; `/broadcasting/auth` permanece en Laravel, protegido por sesión y `auth`, independientemente de `ADMIN_PATH`.

Si Reverb está caído, la app sigue funcionando y los pedidos se guardan; las pantallas se actualizan por consulta periódica. Al reconectar se consulta de nuevo el contador. El aviso sonoro requiere que el usuario haya tocado la pantalla y respeta el silencio de cocina.

## Rutas

La raíz del dominio es para los clientes (`/`). El sistema del taquero (vender, pedidos, cocina, gastos, ajustes) vive bajo `ADMIN_PATH`, por defecto `/admon`: `https://tu-dominio.com/admon`. Se cambia con la variable `ADMIN_PATH` (minúsculas, números y guiones). Si se cambia, hay que volver a instalar la app en el celular, porque su dirección de inicio es esa ruta.

## Pedidos en línea

La raíz del dominio es el menú público: el cliente arma su pedido, escribe nombre y teléfono, elige recoger o domicilio (si está disponible) y lo manda. Cada pedido llega **Por aceptar** a Pedidos (suena y avisa al instante); el taquero lo acepta (entra a Pedidos y Cocina como cualquier pedido por cobrar) o lo rechaza con un motivo. El cliente sigue su pedido en un enlace secreto (`/pedido/{token}`) que se actualiza solo.

En el sistema, **Tienda en línea** controla: el estado (según horario, abierta o cerrada a mano, con un mensaje para avisos como mal clima), si se reciben pedidos en línea (independiente de estar abierto), si hay domicilio (si no, a domicilio queda bloqueado con un mensaje), el horario por día (por defecto jueves a domingo, 8 pm a 12 am) y las credenciales de Mercado Pago. Los pedidos solo se aceptan con la tienda abierta y los pedidos en línea activados.

Contra pedidos falsos: nombre y teléfono obligatorios, límite de pedidos por equipo y por teléfono, campo trampa para programas y aceptación manual de cada pedido. La integración de cobro con Mercado Pago queda pendiente: las credenciales ya se guardan (cifradas), pero todavía no cobra.

### Seguridad de los pedidos en línea (para producción)

- **IP del cliente.** Los límites de pedidos por equipo usan la IP que ve Laravel. Con `TRUSTED_PROXIES=*` (valor por defecto) se confía en cualquier proxy; si tu proxy exterior (Nginx Proxy Manager) añade a `X-Forwarded-For` en vez de reemplazarlo, alguien podría falsear su IP y saltarse solo el límite por equipo (siguen los límites por teléfono, global y de pedidos esperando, y cada pedido se acepta a mano). Lo mejor es poner en `TRUSTED_PROXIES` la IP o red del proxy real.
- **Reverb.** `REVERB_APP_ID`, `REVERB_APP_KEY` y `REVERB_APP_SECRET` deben ser cadenas largas y aleatorias (no uses los valores del ejemplo). `REVERB_ALLOWED_ORIGINS` limita los orígenes de WebSocket y es obligatoria en producción (el dominio público sin https, por ejemplo `tu-dominio.com`; en desarrollo acepta cualquiera). La API HTTP de Reverb (`/apps`) no se publica: solo `/app/` pasa por el proxy.
- **Datos de los clientes.** El enlace de seguimiento deja de funcionar 24 horas después de que el pedido termina. El teléfono y la dirección de un pedido en línea se borran a los 60 días (se conserva el nombre y lo vendido). Los pedidos que nadie acepta ni rechaza se cancelan solos a los 90 minutos. El token del enlace no se guarda en los registros de acceso de nginx.
- **Límites.** Por equipo: 5 pedidos cada 10 minutos y 30 intentos; por teléfono: 3 por hora; entre todos: 40 cada 10 minutos y 25 pedidos esperando respuesta a la vez.

## Bot de Telegram

Crea un bot con `/newbot` en **@BotFather** y pega su token en **Tienda en línea** del sistema. Para vincular el Telegram del taquero, genera ahí un código y envía `/vincular CODIGO` al bot.

El chat vinculado entra en modo taquero: `/panel` muestra los pedidos por aceptar y activos (listo, en camino, entregado, cobrar y cancelar con botones), el resumen de hoy y la tienda (abrir, cerrar, pedidos en línea y domicilio). **Ver como cliente** (o `/cliente`) muestra el bot como lo ve un cliente, con un botón para volver. Esos pasos de pedido viven en `App\Support\OrderFlow`, los mismos que usan Pedidos y la cocina.

En producción se requiere una URL pública con **HTTPS**: el webhook usa una clave secreta y se registra con el botón del sistema o con `php artisan telegram:webhook`. Para quitarlo sin perder mensajes pendientes: `php artisan telegram:webhook --delete`.

En desarrollo, conecta primero el bot y ejecuta `docker compose --profile telegram up -d` para levantar la consulta de mensajes y el programador. También puedes ejecutar `php artisan telegram:poll` directamente, junto con `php artisan schedule:work`. La consulta quita el webhook mientras está en uso; al volver a producción, regístralo otra vez. Usa un bot distinto para desarrollo si producción sigue activa.

El botón **Pedir** y el comando `/pedido` abren la página de pedidos dentro de Telegram como Mini App, que también requiere HTTPS. El servicio `scheduler` es necesario para los recordatorios, el resumen al cerrar por horario y la caducidad de pedidos; en producción se levanta siempre y en desarrollo pertenece al perfil `telegram`.

## Comandos útiles

```sh
docker compose exec app php artisan migrate
docker compose exec app php artisan test
docker compose exec app composer require <paquete>
docker compose logs -f app
```

## Módulos

- Vender: botones por producto, indicaciones por pieza ("sin cebolla" sale de la receta y descuenta su costo; extras con precio), nota libre y nombre del pedido. Se cobra de una vez o se guarda sin cobrar.
- Pedidos: cola de lo que falta entregar y de lo que falta cobrar, con tiempo de espera y hora de recogida. Los guardados sin cobrar de días anteriores van aparte. Pantalla de cocina para una tablet, solo con lo de hoy; si un pedido listo se edita, vuelve a la cocina.
- Combos con opciones para elegir (refresco), para llevar, aquí o a domicilio (dirección, teléfono, envío, accesos a llamar, WhatsApp y mapa), productos agotados por día.
- Propinas aparte de las ventas, descuentos, cortesías y pago mixto.
- Ventas: historial por día, con cancelación. Una orden cuenta el día en que se cobró, igual que en el corte y en Resumen.
- Gastos: compras de insumos, gas, desechables, etc. Una compra de insumo con cantidad actualiza el costo por unidad.
- Productos: precio, categoría, receta (marcando lo que se puede pedir sin) y extras. Calcula costo y margen. Cada variedad (pastor, tripa) es un producto aparte. Se acomodan arrastrando, en el mismo orden que salen en Vender. Un producto con ventas no se borra, se oculta.
- Insumos: catálogo con unidad y costo. Si un insumo en uso pasa de kg a g (o de lt a ml), recetas y compras se convierten solas.
- Resumen: ventas, gastos, ganancia por receta y ventas menos gastos, por periodo, con comparativo y horas fuertes.
- Corte de caja: efectivo esperado contra contado, con entradas y retiros de efectivo. Al cerrar se anota cuánto se queda de fondo; es el fondo inicial del día siguiente.
- Lista de compras: sugerencia por insumo según lo usado los mismos días de semanas anteriores.
- Ajustes: nombre y logo del negocio (se usan en el login, el menú y el ícono de la app instalable), hora en que termina el día de trabajo (si se cierra después de medianoche, lo de madrugada cuenta en el día que se abrió) y cambio de contraseña.
- Explicaciones: cada pantalla tiene un recorrido guiado (driver.js) que resalta sus partes. Sale sola la primera vez (se puede omitir o apagar) y se repite con el botón Tutorial del encabezado. Los pasos y textos están en `resources/js/tours.js`.

Cada venta guarda el costo de receta del momento, así el historial no cambia si luego sube la carne.

## Notas

- Agregar y quitar productos en Vender ocurre en el navegador; el servidor solo interviene al cobrar y recalcula todo con los precios de la base. El pedido en curso se guarda en el navegador (`localStorage`), así sobrevive a una recarga o a una sesión vencida.
- El logo por defecto está en `public/images/brand/`. El que se sube desde Ajustes se guarda en `storage/app/private/branding/`.
- Detrás de un túnel o proxy HTTPS: `TRUSTED_PROXIES=*` y `APP_URL` con la URL pública.

## Datos de ejemplo

`php artisan db:seed --class=DemoRecipesSeeder` carga insumos, recetas, extras y opciones de ejemplo para los productos de la taquería.

## Pendiente

- Cobro con tarjeta (Mercado Pago). La orden ya guarda `payment_method` y `payment_reference`.
- Inventario y mermas.
