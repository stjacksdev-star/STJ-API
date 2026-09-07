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

La fase 3 manual descrita abajo ya está implementada. La selección masiva,
recuperación de aprobados sin registro y programación cron siguen pendientes.

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

## Fase 3: procesador manual de un envío (7 de septiembre de 2026)

El usuario confirmó el registro de pendientes tanto para efectivo como para
tarjeta (esta última en VPS). Se implementó `prism:process-shipment`; no se agregó
ninguna programación en `routes/console.php`, ni modo masivo, ni endpoint web.
Los flags y credenciales del entorno no se activaron/modificaron durante esta fase.

### Preparación del esquema

Ejecutar una sola vez el SQL en `database/sql/prism_manual_processor.sql` usando
el usuario administrador (el usuario técnico no tiene permiso ALTER):

```sql
ALTER TABLE prism_envios ADD COLUMN processing_checkpoint LONGTEXT NULL;
ALTER TABLE prism_envios_log MODIFY COLUMN step VARCHAR(80) NOT NULL;
```

`integration_environment` de fase 2 debe existir. `processing_checkpoint` conserva
huella de datos/instalación, SID de cliente, pasos verificados y escritura incierta;
no contiene los datos personales del cliente. El cambio de ENUM a VARCHAR conserva
logs anteriores y permite etapas reales (sin convertirlas artificialmente a login).
Si se utiliza la migración `2026_09_07_000002_prepare_prism_manual_processor.php`,
detecta los cambios ya realizados manualmente antes de intentar ALTER. Su `down`
conserva datos y nombres de etapas; no ejecutar rollback general.

No se ejecutó este SQL contra la base real durante el desarrollo de fase 3.

### Configuración del resolver SKU

```dotenv
PRISM_HN_SKU_URL=https://HOST_DEL_RESOLVER/api/hn/ec/endpoint_getsid_sku.php
PRISM_HN_SKU_TOKEN=
PRISM_HN_MAX_ATTEMPTS=5
```

Usar la URL real del resolver legacy (`STJ_API_HN`) y su bearer (`STJ_API_TOKEN`),
sin confundirlos con las credenciales de login directo de Prism. Ambos admiten
HTTP/HTTPS, conservando verificación TLS cuando aplique, y tienen timeout finito.
El resolver usa POST únicamente como consulta: `Pais`, `Codigos`, `Tiendas`.
Se rechazan SKU faltantes/duplicados, SID repetidos o estilo/talla que no coincida.
Tenant/subsidiaria/tipos de contacto/controller/cajero tienen los defaults del
legacy HN revisado; los overrides `PRISM_HN_*` están en `config/prism.php`.
Después de configurar ejecutar `php artisan config:clear`.

### Selección y validación sin escritura comercial

Usar SOLO uno de estos selectores:

```bash
php artisan prism:process-shipment --shipment=123
php artisan prism:process-shipment --stj=STJ123456789
php artisan prism:process-shipment --order=100 --payment=200
```

Los números son ejemplos, no IDs reales. `--shipment` corresponde a `pe_id`.
STJ se resuelve exclusivamente en HN; el par pedido/pago debe corresponder al mismo
registro de `prism_envios`. No se aceptan combinaciones de selectores ni ejecuciones
sin selección. El comando devuelve IDs, referencia, tienda, tipo e importe seguros.

Sin `--execute` consulta datos locales, tienda remota, SID de SKU y coincidencias
de cliente. No cambia estado/intentos/checkpoint/logs ni crea documentos/clientes.
Sí abre/cierra sesión Prism. No garantiza todavía que el documento final concilie:
el total definitivo de Prism se comprueba después de agregar artículos.

### Envío de un pedido controlado

```dotenv
STOREFRONT_POST_PURCHASE_INTEGRATIONS_ENABLED=true
STOREFRONT_HN_PRISM_ENABLED=true
```

Ambos son obligatorios para `--execute`. El flag general también habilita GT/CR/PA
si sus flags individuales están activos: mantenerlos apagados en un entorno de
prueba que no deba llamar a esos POS. `STOREFRONT_HN_PRISM_REGISTER_PENDING` afecta
solo el registro de nuevos pendientes, no el envío manual de uno existente.

```bash
php artisan prism:process-shipment --stj=STJ123456789 --execute --confirm-ref=STJ123456789
```

También funciona `--shipment=123` o `--order=100 --payment=200` acompañados de
`--execute --confirm-ref=STJ123456789`. La confirmación exacta siempre es obligatoria.
Código de salida 0 significa validado/enviado/ya_enviado; 1 indica bloqueo/error.

### Validaciones, flujo y recuperación

- Revalida APP_ENV exacto, país HN por `ped_id_pais`, pago APROBADA vinculado,
  referencia, detalle de HN, tienda por código textual+país y SID/número de tienda
  remota activa en la subsidiaria configurada. Históricos con entorno NULL no se adoptan.
- El claim atómico permite un solo proceso con estado pendiente/error y menos de
  cinco intentos (configurable). Después de reclamar vuelve a leer el checkpoint.
  No roba un registro en procesando ni reinicia automáticamente intentos.
- Huella de datos, mapeo y host impide reanudar una operación con otro pedido o
  instalación. No modifica importes de pago ni datos de checkout.
- Crea documento status=3, artículos con descuentos unitarios legacy, cliente
  encontrado por identificación exacta o nuevo, vinculación, tender/depósito solo
  tarjeta y status=4. Relee row_version antes de cada PUT y verifica con GET.
- Verifica artículos completos y conserva el total calculado por Prism después de
  insertar las líneas; ese `transaction_total_amt` alimenta tender y depósito,
  igual que el legacy. También verifica cliente,
  tender/autorización/tipo/emisor y depósito. Un estado enviado solo se registra
  después de la verificación final. Efectivo requiere ausencia de tender y depósito.
- Registra intención ANTES de POST/PUT. Si hay timeout o respuesta ambigua, mantiene
  `uncertain` y `RECONCILE_REQUIRED`. Repetir el mismo comando reconcilia mediante GET
  y continúa solo si puede confirmar lo aplicado. No vuelve a publicar artículos
  parciales ni tenders desconocidos. Un documento creado con respuesta perdida puede
  recuperarse por referencia única y coincidencia de tienda/subsidiaria.
- Un rechazo HTTP 401/403/409/422 deja el paso disponible para corregir y reintentar;
  los POST/PUT nunca se reintentan automáticamente dentro del cliente HTTP.
- Si el proceso muere abruptamente y deja `procesando`, requiere revisión operativa:
  verificar que no sigue ejecutándose antes de cambiar el estado. No borrar checkpoint
  ni SID para forzar un reenvío. La recuperación automática de procesos abandonados
  y de pedidos aprobados sin pendiente se implementará antes del cron masivo.

### Límites que deben resolverse antes de automatizar

- Regla confirmada por el usuario: el envío se cobra para el proveedor y NO se
  factura en Prism. Los artículos usan `car_precio` y `car_descuento`, como el legacy;
  Prism calcula `transaction_total_amt` y tarjeta usa ese valor en tender y depósito.
  `ppa_monto` y `ppa_monto_senv` quedan como referencias locales sin modificar y no
  determinan el importe enviado a Prism. No se agrega cargo/artículo de envío.
- Dirección mayor a 80 caracteres se bloquea antes de escribir, para no truncarla
  silenciosamente al dividirla en dos campos de 40. Revisar contrato real si admite más.
- Búsquedas de cliente/documento y GET de colecciones se validan estrictamente contra
  el contrato de arrays. Se debe confirmar en la prueba real la sintaxis de filtros,
  nombres de campos y paginación de la instalación; respuestas ambiguas se bloquean.
- Contactos existentes usan su link correcto y row_version; si no existe link, se
  registra `SKIP_NO_CONTACT_LINK`, conservando el comportamiento legacy para ese caso.
- No se adopta un documento ajeno preexistente sin checkpoint propio. Casos sin
  resultado observable tras un timeout requieren conciliación manual, no un nuevo POST.

### Diagnóstico y pruebas

```sql
SELECT pe_id, stj_ref, ped_id, ppa_id, status, intentos, document_sid,
       error_code, error_message, processing_checkpoint
FROM prism_envios WHERE pe_id = 123;

SELECT pel_id, step, http_code, request_url, error_message, created_at
FROM prism_envios_log WHERE pe_id = 123 ORDER BY pel_id;
```

Los logs nuevos muestran etapa, estado, ruta sin query y HTTP cuando aplica. Cada
POST/PUT guarda en `request_payload` el JSON exacto enviado a Prism en la fila START;
esto incluye datos del cliente y metadatos del pago necesarios para auditar el envío.
No se guardan credenciales de login, token Auth-Session, headers, query de login ni
cuerpos completos de respuesta. El acceso a estos logs debe limitarse a operadores
autorizados. El error resumido se guarda en el envío. Fallos internos no exponen
SQL/payloads en consola.

42 pruebas / 140 assertions aprobadas entre PrismShipmentProcessorTest,
PrismStoresTest y StorefrontPostPurchaseIntegrationServiceTest. HTTP simulado y
SQLite: efectivo, tarjeta, primary email correcto, selectores, flags, países,
entorno, importe, faltantes SKU, datos modificados y recuperación de timeouts de
documento/artículos/tender/finalización sin duplicación. La ejecución real de un
pedido por este procesador queda pendiente de la prueba controlada en VPS.

### Corrección tras primera ejecución real local

El envío 70, referencia STJ260907104814TVDY, creó documento y artículo (HTTP 201),
pero la verificación local falló en items_verify: Prism devuelve
`manual_disc_value=null`, `manual_disc_type=null` y `discount_amt=0` para el artículo
sin descuento. El total local y remoto de productos era 350.00, correcto.
Se consulta `discount_amt` cuando Prism no devuelve el campo manual; no se convierten
indiscriminadamente los importes ausentes en cero. La huella del pedido sin envío
permanece compatible con el checkpoint ya guardado. Repetir el comando reconciliará
el artículo existente antes de continuar; no borrar documento/checkpoint.
Durante el diagnóstico solo se hicieron consultas remotas GET y apertura/cierre de
sesión; no se reejecutó el envío real ni se modificó el estado del registro.
Se retiró el bloqueo de costo de envío según la regla comercial confirmada arriba.
