# Retail Prism Honduras

Actualizado: 2026-09-07.

## Fase 1 implementada: lectura de tiendas

`php artisan prism:stores --country=HN` consulta tiendas activas y muestra SID,
código, número y nombre. `--json` entrega `country`, `count` y `stores`.
SID y códigos se conservan como strings. No sincroniza `stj_tiendas`, no crea
clientes/documentos, no escribe en `prism_envios` y no tiene programación automática.

Configurar en el `.env` privado del API (valores vacíos en `.env.example`):

```dotenv
PRISM_HN_HOST=http://192.0.2.10:8080
PRISM_HN_USERNAME=
PRISM_HN_PASSWORD=
PRISM_HN_WORKSTATION=
PRISM_HN_CONNECT_TIMEOUT=5
PRISM_HN_TIMEOUT=30
```

La IP y el puerto del ejemplo son ilustrativos: usar los reales de Prism.
Se admite HTTP o HTTPS con IP o dominio y puerto opcional. Es obligatorio incluir
`http://` o `https://`; no agregar credenciales ni parámetros al host.
Corrección del 7 de septiembre: se retiró la restricción exclusiva a HTTPS para
respetar la instalación existente por HTTP/IP, con prueba automatizada del flujo.

Después de configurar, regenerar o limpiar la caché de configuración según el
procedimiento de despliegue del API. No copiar secretos al dashboard o al frontend.

Secuencia: `GET /api/security/login?usr=...&pwd=...&ws=...`, token de `[0].token`,
`GET /v1/rest/store?cols=*&filter=active,eq,true` con `Auth-Session`, y logout en
`GET /api/security/logout`. Se reproduce el login activo de PrismRetailHN;
no se llama `/sit` automáticamente. Confirmar ese requisito en la prueba real.
El segundo `?` del ejemplo Postman no se incorpora al valor de `cols`.

La sesión pertenece a cada ejecución, sin caché persistente compartida ni sesión
del navegador. Ante 401/403 de tiendas se renueva una sola vez. En HTTPS se verifica TLS,
no se siguen redirects y no se imprimen tokens, URLs de login ni cuerpos de error.
El login legacy transporta secretos en query: cualquier observador HTTP o proxy
del entorno debe redactar esos parámetros. No habilitar trazas HTTP con secretos.
Una falla de logout se advierte en stderr sin invalidar una lectura exitosa.

El contrato inicial espera un arreglo JSON como el observado en Postman. No se
asume que el servidor entrega todas las páginas: comprobar cantidad/paginación
en la prueba real antes de utilizar esta lectura como sincronización completa.

Validación automatizada: `php vendor/bin/phpunit tests/Feature/PrismStoresTest.php`.
Usa HTTP simulado, entorno testing y SQLite; no requiere consultas a base real.
El usuario confirmó la consulta real de tiendas por HTTP/IP el 7 de septiembre.

## Fase 2 implementada: pendientes locales

Postcompra reemplaza la llamada HTTP legacy HN por `PrismPendingShipmentService`.
Se ejecuta después del commit mediante el servicio compartido por web/mobile y
tarjeta/efectivo. Una excepción se registra en el log Laravel y no revierte el pago.

- `STOREFRONT_HN_PRISM_REGISTER_PENDING=true`: permite registrar localmente.
  Por defecto y en el `.env` local permanece false hasta aplicar la columna nueva.
- `STOREFRONT_POST_PURCHASE_INTEGRATIONS_ENABLED`: controla llamadas externas a
  POS GT/CR/PA y será requisito del futuro emisor Prism. No bloquea el insert local.
- `STOREFRONT_HN_PRISM_ENABLED`: reservado para el futuro emisor independiente.
  No controla el registro local ni el comando de lectura de tiendas.
- `STOREFRONT_HN_PRISM_URL`: configuración legacy conservada, sin consumo desde
  el servicio postcompra nuevo. No hay fallback al endpoint anterior.

Se valida `ped_id_pais` contra país HN, pago vinculado y APROBADA, referencia y
tienda única por `tie_pais = ped_id_pais` y `tie_codigo = ped_tienda` textual,
con SID/número Prism válidos. No se resuelven códigos por conversión numérica.
Se bloquea el pedido durante el registro y se conserva el índice único
`uq_pais_stj`. Un registro existente no se reinicia ni cambia de estado/entorno;
una referencia vinculada a otro pedido/pago produce error.

`tienda_codigo` ya fue convertido por el usuario a VARCHAR(20). Migración aditiva:

```bash
php artisan migrate --path=database/migrations/2026_09_07_000001_add_environment_to_prism_envios.php --force
```

Agrega `integration_environment` nullable; históricos quedan NULL y nuevos usan
APP_ENV. La aplicación local fue rechazada: `stj_api` no tiene permiso ALTER.
Pendiente de ejecutar con el usuario administrador:

```sql
ALTER TABLE prism_envios
ADD COLUMN integration_environment VARCHAR(50) NULL DEFAULT NULL;
```

Después ejecutar la migración específica indicada arriba (reconoce la columna
existente y registra la migración), establecer
`STOREFRONT_HN_PRISM_REGISTER_PENDING=true` y ejecutar `php artisan config:clear`.
No se modificaron registros históricos ni se crearon pedidos de prueba reales.
Al desplegar en otro entorno aplicar esta migración y configurar el interruptor
local antes de probar. El futuro cron deberá filtrar explícitamente por entorno,
no adoptar históricos NULL ni procesar copias de test/local en producción.

No se escribe un log `login` falso para el insert: `prism_envios` conserva estado
y fechas; los errores quedan en el log Laravel. La ampliación del ENUM de logs
y las etapas remotas corresponden al procesador posterior.

Pruebas: 18 tests, 50 assertions entre `StorefrontPostPurchaseIntegrationServiceTest`
y `PrismStoresTest`; incluyen no HTTP HN con interruptores remotos activos,
idempotencia, aislamiento de país, validaciones y falla de insert sin revertir pago.

En fase 2 los pendientes NO se envían. No activar este reemplazo en producción
hasta coordinar el procesador. Recuperación de aprobados sin registro y reintentos
quedan para la fase 3; actualmente una falla de insert exige revisión del log.

## Fases acordadas pendientes

3. Comando independiente programado por el scheduler de Laravel: reclamar
   pendientes atómicamente, prevalidar tienda/SKU/importes, crear documento,
   artículos, cliente y tender/depósito solo para tarjeta. Persistir avance y SID,
   reconciliar respuestas ambiguas antes de reintentar y limitar intentos.
4. Activación gradual: un solo responsable por envío; coordinar sustitución del
   puente legacy y cron para no duplicar ni abandonar pedidos pendientes.
5. Pantalla `stj-dashboard`: listado/búsqueda por referencia, estado, intentos,
   documento y fechas; detalle de logs y reenvío autorizado y auditable. Referencia
   funcional: `actual_ecommerce/.../Honduras/Prism.php::envios`, `envio_detalle`,
   `reenviar`. Los reenvíos reutilizarán el procesador con recuperación por etapas.

El checkout/pago de Laravel ya no llama a Retail Prism. El envío remoto queda
pendiente de la fase 3; GT/CR/PA conservan sus integraciones actuales.
