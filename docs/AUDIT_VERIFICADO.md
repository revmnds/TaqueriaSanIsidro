**Revisión contrastada — Taquería San Isidro**

21 de octubre de 2026. Código original: `e96a08520668f5e7b220890816bc75b206229845`. Las referencias de archivo y línea de los hallazgos apuntan a ese commit, salvo donde se indica «cambio local». Revisión entre **Sol High y Astra Ultra**: Sol revisó negocio, dinero y Telegram; Astra revisó los recorridos de interfaz y verificó de forma independiente los cambios de arqueo, sonido y cocina. También hubo revisión cruzada de los cambios entre los dos Sol.

Se leyó `AUDIT.md` antes de contrastar sus cinco puntos con el código y buscar problemas adicionales. Este documento distingue el código original, las correcciones locales y lo pendiente. Las correcciones se proponen en la rama `fix/audit-findings` para revisión del mantenedor; no actualizan el servidor de producción.

**Los cinco puntos de «Problemas encontrados»**

1. **Gastos revive un gasto borrado — PARTIALLY CORRECT.** `resources/views/pages/gastos.blade.php:150-152` consulta el insumo anterior y llama a `Expense::updateOrCreate(['id' => $this->editingId], ...)` sin exigir que siga existiendo. Si se borró, crea otro gasto y anuncia una actualización (`:158`). El matiz importa: no conserva el mismo ID, porque `app/Models/Expense.php:10` excluye `id` de los campos que se pueden llenar. La comparación con Productos e Insumos sí es correcta: `productos.blade.php:199-202` e `insumos.blade.php:281` usan `findOrFail`. La transacción de Gastos no evita recrearlo: agrupa la escritura y la actualización del costo, pero no exige encontrar la fila. Prioridad **media**: vuelve a sumar un gasto y puede cambiar el costo del insumo. **Corregido localmente:** búsqueda con bloqueo al editar y mensaje si falta; creación solo para un formulario nuevo. La prueba abre, borra desde fuera y vuelve a guardar.

2. **ID de extra u opción de otro producto causa 500 — FALSE.** Las llamadas existen en `resources/views/pages/productos.blade.php:211-224`, dentro de las relaciones del producto. Sin embargo, `app/Models/ProductExtra.php:9`, `ProductOptionGroup.php:9` y `ProductOptionChoice.php:9` excluyen `id` de los campos que se pueden llenar. Si la relación no encuentra ese ID, crea una fila nueva con otro ID; no intenta insertar el ID del otro producto. El otro producto queda intacto. Se añadió una prueba con IDs ajenos de extra, grupo y elección: guarda sin errores, crea filas propias y conserva las ajenas. **No se cambió ese guardado**, porque la causa descrita por el reporte no existe con estos modelos y esta versión del framework.

3. **Los dos caminos del número diario pueden coincidir — CONFIRMED.** `app/Support/OnlineOrder.php:200-216` toma `Cache::lock('orders:daily-number', ...)`; `resources/views/pages/vender.blade.php:362-364` toma `pg_advisory_xact_lock(72431901)`. Ambos usan `app/Models/Order.php:313-315`, que calcula `max(daily_number) + 1`. Los bloqueos no se coordinan y no hay una restricción única sobre ese número que lo impida. La importancia «baja» y la frase «no afecta reportes» se quedan cortas: `Order.php:282-289` también usa el mismo número para decidir si una venta cancelada ya fue corregida; `ventas.blade.php:133` usa esa decisión para impedir restaurarla y `corte.blade.php:216` para omitirla de cancelaciones. Un número repetido por coincidencia puede confundirse con una corrección real. Prioridad **media**, además de la confusión en cocina. **Corregido localmente:** ambos caminos usan el mismo bloqueo de PostgreSQL, dentro de la transacción. No se añadió una prueba de dos procesos específica para esta carrera; la evidencia del arreglo es el bloqueo compartido en ambos caminos.

4. **Producción confía por defecto en cualquier proxy — CONFIRMED, con condición de despliegue.** `compose.prod.yaml:64` contiene `${TRUSTED_PROXIES:-*}`. En los pedidos, las claves por dirección están en `app/Support/OnlineOrder.php:376-382` y `:388`; las claves por teléfono y global están separadas en `:389-390`; el máximo de pendientes se cuenta en `:140-142`. Por tanto, cambiar la dirección aparente no cambia el teléfono ni el contador global. Hace falta que el proxy admita encabezados proporcionados por el visitante para que ocurra el efecto descrito: no se comprobó la configuración de Nginx Proxy Manager. «Solo» debe limitarse a los topes de pedidos: el acceso al sistema también limita por dirección en `resources/views/pages/login.blade.php:31-34`. **Pendiente:** configurar las direcciones concretas del proxy real. No se inventó una red para el servidor ni se cambió el valor a ciegas. Una red privada muy amplia tampoco identifica por sí sola al proxy autorizado.

5. **Mercado Pago muestra una dirección sin ruta — CONFIRMED.** `resources/views/pages/tienda.blade.php:542` muestra `/mercadopago/webhook`; `routes/web.php:20-74` no registra esa ruta, y sí registra separadamente `/telegram/webhook` (`:24`). La propia pantalla ya aclara que aún no cobra (`tienda.blade.php:513-515`), así que la prioridad **baja** es razonable. **Corregido localmente:** se reemplazó la dirección por un aviso de que estará disponible al terminar la conexión. No se añadió una ruta vacía ni se presentó Mercado Pago como terminado.

**Problemas adicionales que sí requieren una decisión del mantenedor**

Las prioridades significan: alta = atender primero; media = puede cambiar dinero, preparación o funcionamiento; baja = confusión localizada. «Corregido» aquí significa cambio local revisado, no cambio desplegado.

| # | Prioridad y efecto | Evidencia del código original | Estado local |
| --- | --- | --- | --- |
| 6 | **Alta, condicionada a la versión instalada. PHP publicó dos problemas críticos hoy.** El servicio web usa PHP-FPM; una imagen construida antes de la corrección puede conservar una versión anterior. | `docker/prod/Dockerfile:23` y `docker/php/Dockerfile:1` usan `php:8.4-fpm`, una etiqueta sin versión de parche. El aviso oficial incluye PHP-FPM. | Ambos Dockerfile fijan `php:8.4.27-fpm`. Se comprobó que esa etiqueta existe en el registro oficial. **Falta reconstruir, recrear y comprobar los servicios del servidor.** No hay acceso a su versión real. |
| 7 | **Media. Cobrar un pedido atrasado puede mover su recogida a mañana.** Se carga solo HH:MM y se vuelve a interpretar respecto a hoy. | `vender.blade.php:151` carga `pickupTime`; `:308-317` agrega un día si esa hora pasó hace más de una hora. | Corregido: se conserva la fecha completa guardada mientras no cambie la hora. Prueba: pedido de 13:00 cobrado a 15:00 sigue siendo del mismo día. |
| 8 | **Media. Corregir una venta en línea pierde el seguimiento y el contacto del pedido nuevo.** El original queda cancelado y el sustituto se crea con los datos básicos de caja. | `vender.blade.php:384-393` crea el sustituto sin `source`, `public_token`, `customer_phone`, `telegram_chat_id`, `accepted_at` ni `notes`; `:632-639` cancela el original. | Corregido para nuevas correcciones: conserva los datos, transfiere el enlace en la misma transacción y muestra que se está corrigiendo. Un reenvío del formulario original devuelve el sustituto vigente. **No repara correcciones históricas ya guardadas como ventas de caja.** |
| 9 | **Media. El resumen enviado al cerrar a medianoche puede contar el día que acaba de empezar.** | `TelegramAlerts.php:32-36` detecta la transición al cierre; `TelegramNotifier.php:110-112` usa `BusinessDay::of(now())`. `BusinessDay.php:48-50` define la frontera según `day_ends_at`. | Corregido: el cierre pasa el instante inmediatamente anterior a la transición. `/hoy` conserva el día actual. No cambia los totales guardados; corrige el resumen enviado. |
| 10 | **Media. Dos chats pueden consumir el mismo código de vinculación al mismo tiempo.** | `app/Support/Telegram.php:331-359` lee, compara y borra por separado; el bloqueo de la lista de personal no cubre esa lectura del código. | Corregido: generación y consumo usan el mismo bloqueo. Se conserva el código si otro consumidor ocupa el bloqueo. |
| 11 | **Media. Un chat desvinculado puede seguir recibiendo cambios de pedidos antiguos.** | `Telegram.php:270-274` quita el chat de ajustes, pero `TelegramNotifier.php:73-77` edita todos los avisos guardados sin volver a comprobar la lista. | Corregido: se borran sus referencias de avisos y se comprueba que siga vinculado antes de editar. No borra mensajes que la persona ya recibió. |
| 12 | **Media. Un fallo de Telegram puede dejar avisos sin enviar y sin reintento.** | `TelegramNotifier.php:56-59` marca el estado antes de enviar; `TelegramAlerts.php:27-28,35-36` marca recordatorio/resumen antes de enviarlo; `Telegram.php:94-119` devuelve `null` ante fallos. | **Pendiente:** guardar envíos pendientes y confirmados con reintentos. Mover la marca detrás de `send()` no basta: hace falta quien vuelva a intentarlo y controlar envíos repetidos. Los pedidos sí permanecen en la base. |
| 13 | **Media. El comando normal de pruebas se detiene antes de ejecutar la suite.** | `phpunit.xml:9-11` declara `tests/Unit`, que no existe. Se ejecutó el comando y PHPUnit 12.5.38 respondió `Test directory .../tests/Unit not found`. | Corregido: se quitó la suite inexistente. El reporte decía «Funciona»; en este entorno no funciona así. |
| 14 | **Media. Deshacer durante dos minutos permite cancelar una venta del día contable anterior.** | `vender.blade.php:620-639` comprueba antigüedad y estado, pero no el día bajo bloqueo. El recorrido de Ventas sí usa `BusinessDay::isCurrent($order->countedAt())` (`ventas.blade.php:77-79`). | Corregido usando `countedAt()` bajo bloqueo. Pruebas cubren cambio de día y el caso legítimo de pedido abierto ayer pero cobrado hoy. La fecha para conservar el número se decide por separado. |
| 15 | **Media. Más de 30 renglones se recortan sin decir cuáles se descartaron.** Con el total esperado puede aparecer un aviso de precios aunque el problema sea la cantidad de renglones. | `OnlineOrder.php:342` aplica `take(MAX_LINES)` antes de construir; `:130-135` compara el total. El editor público agrega líneas en `resources/js/order-page.js:139-145` sin ese máximo. | Corregido: se rechaza el pedido completo con un mensaje de hasta 30 renglones. Se cambió la prueba anterior que exigía recortar; ese comportamiento era el problema. |
| 16 | **Media. Un aviso largo de Telegram pierde partidas o queda con formato incompleto.** | `OnlineOrder.php:26` admite 30 renglones; `TelegramNotifier.php:123-166` los reúne con sus notas; `Telegram.php:140,155` corta el texto a 4000 caracteres sin respetar el formato. `/menu` también agrupa categorías completas (`TelegramBot.php:114-132`). | Corregido: aviso compacto con enlace al panel autenticado cuando no cabe; menú dividido por producto. No promete mensajes de detalle que luego queden desactualizados. **Límite:** pedidos rechazados sin aceptar no tienen vista de detalle histórico en el panel. |
| 17 | **Baja. Abrir o cerrar a mano puede mostrar una próxima hora que no se cumplirá.** | `Store.php:119-136` combina el modo manual con el siguiente horario; `pedir.blade.php:45-48,101-104` y `TelegramBot.php:135-148` lo muestran. | Corregido: solo el modo automático ofrece horas del horario. |
| 18 | **Media. Un error al procesar un mensaje de Telegram se confirma como recibido y puede perder la acción.** | `TelegramWebhookController.php:26-38` marca el mensaje antes del trabajo y siempre devuelve 200, incluso en la excepción. | **Pendiente:** procesamiento persistente con acciones repetibles sin duplicar sus efectos. Devolver 503 por sí solo puede repetir un cambio de tienda ya aplicado: `TelegramAdmin.php:118-132` invierte valores. No se entregó ese arreglo incompleto. |
| 19 | **Media. Guardar por primera vez el mismo corte desde dos equipos puede fallar.** | `corte.blade.php:251-262` hace `first() ?? new` y luego inserta; `database/migrations/2026_10_10_000002_create_cash_closes_table.php:14` exige una fecha única. | Corregido usando `updateOrCreate`, cuyo camino de creación maneja la coincidencia de la fila única. Se comprobó actualización desde dos formularios; **no se añadió prueba simultánea de dos procesos para este caso**. |
| 20 | **Media, desarrollo por consulta. Cambiar de bot conserva el índice del anterior y puede saltar mensajes del nuevo.** | `TelegramPoll.php:40,59-72` usa `telegram:offset` global; `Telegram.php:233-247` no lo borra al desconectar. | Corregido: índice separado por ID del bot y salida del proceso si se cambia de bot mientras consulta. No afecta al servidor que solo usa webhook. |
| 21 | **Media. Caja puede cobrar un total distinto del mostrado si cambió un precio.** | `vender.blade.php:539-552` recalcula el total, pero `:966-976` envía la acción sin el total que se confirmó en pantalla. La comprobación pública de `OnlineOrder.php:130-135` no cubre caja. | Corregido: la interfaz envía el total, el servidor lo compara, rechaza el cambio y refresca el catálogo antes de volver a confirmar. Los precios siempre salen de la base. |
| 22 | **Media. Reemplazar un insumo puede borrar la indicación «sin» al reabrir y cobrar un pedido.** | `insumos.blade.php:635-658` cambia recetas, extras y compras pero no modificadores guardados; `OrderBuilder.php:178` recupera el ID antiguo y `:78-79` descarta el que ya no está en la receta. | **Pendiente:** preservar la indicación al sustituir el insumo y comprobar pedidos abiertos en otras pantallas. Debe decidirse qué hacer cuando ambos insumos ya existen en una receta o cambia si se puede quitar. No se hizo una sustitución automática ambigua. |
| 23 | **Media. El arqueo puede mostrar cero y guardar el importe anterior.** | `corte.blade.php:433` solo copia la suma si `sum > 0`; `:449` muestra cero; `:257` guarda `counted`. | Corregido: después de usar el desglose también se sincroniza cero; abrirlo sin usarlo no borra un monto escrito a mano. Verificado con el objeto y efecto reales extraídos de Blade. |
| 24 | **Media. Un clic de cocina anterior a un cambio puede marcar como preparado el pedido nuevo.** | `cocina.blade.php:246` manda solo ID; `:64-74` delega; `OrderFlow.php:17-21` no comprueba la versión que cocina vio. | Corregido para cocina web: se envía la versión y se compara en el mismo `UPDATE`. Prueba con cambio intermedio. **Pendiente equivalente de Telegram:** `TelegramAdmin.php:193` sigue llamando sin versión. |
| 25 | **Baja. El primer toque para activar sonido lo apaga.** | `cocina.blade.php:98,105-109,133,149`: `sound=true`, `audioReady=false`, texto de activación y función que invierte `sound`. | Corregido: primer toque activa; siguientes alternan. Se evita que el evento global de desbloqueo adelante el cambio de estado. Prueba aislada con sustituto de AudioContext; no demuestra audio real en un teléfono. |
| 26 | **Media al usar PHP 8.5. Cantidades numéricas extremas pueden terminar en error de servidor antes de aplicar el máximo.** | `OnlineOrder.php:344-356` convierte a entero antes de acotar. Tres casos existentes de `OnlineSecurityTest.php:45` fallaron en la corrida inicial con PHP 8.5.11. | Corregido: se comprueba que el número sea finito y se acota antes de convertir. Se añadió el extremo negativo. No se confunde este problema de aplicación con los dos avisos del motor PHP. |

Los nombres de archivos de la tabla sin prefijo están bajo `resources/views/pages/` cuando terminan en `.blade.php`, bajo `app/Support/` para servicios, bajo `app/Console/Commands/` para comandos y bajo `app/Http/Controllers/` para el controlador.

**El aviso de PHP de hoy**

El [comunicado oficial del 21 de octubre](https://www.php.net/archive/2026.php#2026-10-21-5) identifica **CVE-2026-109618 y CVE-2026-109619**. Explica que pueden permitir que una petición web termine ejecutando código ajeno antes de que la aplicación pueda impedirlo. Incluye expresamente PHP-FPM, que es lo que usa este repositorio. Las versiones corregidas son **8.2.35, 8.3.36, 8.4.27 y 8.5.12**. PHP pide actualizar; cambiar opciones por separado no resuelve completamente ambos problemas.

No se puede deducir la versión instalada de `FROM php:8.4-fpm`: la etiqueta puede apuntar hoy a una imagen nueva y el servidor seguir usando otra construida antes. Docker negó acceso al socket en esta máquina, y no se inspeccionó el servidor del dueño. El veredicto sobre producción es **pendiente de comprobar versión**, con actualización prioritaria si conserva una anterior. No se intentó reproducir el problema con peticiones al sitio.

Se fijó localmente la imagen PHP en 8.4.27 y se añadió al README cómo reconstruir, recrear y comprobar `app`, `reverb` y `scheduler`. La etiqueta `8.4.27-fpm` respondió en el registro oficial al verificarla. El cambio no se ha construido con Docker ni se ha desplegado. Las siguientes actualizaciones de PHP también deberán reflejarse en las dos imágenes.

`composer audit` con las dependencias bloqueadas y `npm audit --package-lock-only` no devolvieron avisos. Esto no verifica PHP, Node, las imágenes ya construidas ni el servidor desplegado.

**Código incluido**

El diff que acompaña este informe contiene los cambios completos y las pruebas nuevas, además de este reporte. Algunos ejemplos del cambio final:

```diff
- FROM php:8.4-fpm AS app
+ FROM php:8.4.27-fpm AS app
```

```php
// OnlineOrder::accept: el mismo bloqueo de transacción que usa Vender.
DB::select('SELECT pg_advisory_xact_lock(72431901)');
```

```php
// OrderFlow::markReady: la versión visible forma parte del UPDATE.
->when($expectedVersion !== null, fn ($query) => $query->where('version', $expectedVersion))
```

```html
<!-- Arqueo: cero también cuenta después de utilizar el desglose. -->
@input="used = true"
x-effect="if (open && used) $wire.counted = String(Math.round(sum * 100) / 100)"
```

La corrección de seguimiento transfiere el enlace en la misma transacción que crea el sustituto; los avisos de cambio se emiten después de confirmar la transacción. Las pruebas verifican que se conserva el contacto, que el enlace sigue resolviendo y que repetir el envío original devuelve el sustituto. Se conservó la diferencia entre el día en que nació el pedido y el día en que se cobró: la corrección valida el día contable, no solo `sold_at`.

**Comprobación ejecutada y límites**

Se intentaron los pasos del README: `docker compose up -d --build` y `docker compose exec app php artisan test`. Ambos quedaron bloqueados por permisos sobre el socket Docker. **No se ejecutó la suite dentro de Docker ni se construyeron las imágenes.**

Para no dejar la revisión sin pruebas, se instaló PHP/Composer y se preparó PostgreSQL temporal en `127.0.0.1:55439`, con una base de pruebas independiente. Se usó PHP **8.5.11**, PHPUnit **12.5.38** y PostgreSQL **18.6**; producción declara PHP 8.4 y PostgreSQL 17. Esta diferencia limita lo que demuestra la corrida nativa.

La corrida inicial del comando normal se detuvo por `tests/Unit`. La corrida inicial restringida a Feature ejecutó **487 casos: 484 pasaron y 3 dieron error** por la conversión numérica de PHP 8.5. Tras los cambios, una primera suite completa detectó la prueba anterior que exigía recortar renglones; se adaptó al rechazo explícito y se repitió la comprobación.

**Resultado final: 506 casos aprobados, 2,111 comprobaciones, sin errores ni fallos, en unos 58 segundos.** Las dos pruebas de comportamiento JavaScript también pasaron. Pint y la comprobación de espacios del diff pasaron. La compilación de vistas y los recursos web se comprobaron por separado.

Comandos de comprobación en el directorio de cambios:

```sh
DB_DATABASE=taqueria_test_audit php artisan test --compact
node --test tests/js/audit-ui.test.mjs
vendor/bin/pint --dirty --format agent
php artisan view:cache
npm run build
git diff --check
```

No se envió ningún mensaje a Telegram: sus llamadas se sustituyeron en las pruebas. Las pruebas de JavaScript ejecutan el código extraído de las vistas; no equivalen a una sesión en navegador, a audio real ni a una revisión de accesibilidad. No se añadieron pruebas simultáneas de dos procesos para los nuevos bloqueos de número diario, vinculación o primer corte. La suite conserva su prueba real de dos procesos para reemplazar pagos sin duplicarlos (`tests/Feature/OrderConcurrencyTest.php:15-34`).

**Calidad del reporte original**

El documento es legible y acierta en varios recorridos, pero su conclusión de que todo es menor es demasiado amplia. El punto 2 es falso; el punto 1 describe mal el ID que resulta; el punto 3 subestima el uso del número en cancelaciones y restauración. El punto 4 distingue bien los topes por teléfono y global, aunque no puede afirmar cómo se comporta el proxy real sin revisarlo y deja fuera los límites de acceso por dirección. El punto 5 es correcto y su prioridad es razonable.

La cifra de «420 pruebas en 25 archivos» corresponde al conteo de métodos de prueba del código original, no al número de casos que PHPUnit ejecuta con sus datos. La afirmación de «dos [pruebas] de concurrencia real con dos procesos» tampoco se sostiene: se encontró un método que usa dos procesos en `OrderConcurrencyTest`. Las dos observaciones sobre comprobaciones débiles sí tienen base: `TelegramBotTest.php:626-637` comprueba que una variable recién creada no sea nula, y `OnlineStoreTest.php:128` busca un `2` demasiado general. No se usaron esas comprobaciones como evidencia de funcionamiento de los arreglos.

El reporte también exagera al decir que «cuando algo falla» los avisos están resueltos: guardar el pedido y entregar todos sus avisos son cosas distintas. La base conserva el pedido, pero Telegram puede perder la acción entrante o el aviso saliente descritos arriba. El problema del motor PHP se publicó hoy y se incorpora como una comprobación nueva; no se atribuye al documento original conocimiento previo del aviso.

El audit original se sostiene parcialmente: tres de sus cinco puntos están confirmados, uno es parcialmente correcto y uno es falso. Tiene una buena base de lectura, pero no justifica la conclusión de que solo quedan detalles menores; se encontraron errores que afectan importes mostrados, preparación, seguimiento, día contable y avisos, además de la actualización urgente de PHP si producción aún conserva una versión anterior. Los cambios locales corrigen una parte verificable; los pendientes y la versión desplegada siguen expresamente abiertos.
