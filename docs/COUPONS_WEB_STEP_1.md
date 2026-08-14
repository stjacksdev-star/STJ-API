# Cupones Web - Paso 1: contrato funcional y mapa de datos

Fecha: 2026-08-13

## 1. Alcance

Este documento define la primera fase de la migracion de cupones al nuevo ecosistema:

- `stj-api` sera la unica fuente de verdad para validar, calcular, aplicar y consumir cupones.
- `stj-ecommerce` consumira el contrato storefront para Web.
- `stj-dashboard` administrara headers, codigos, destinatarios y productos mediante endpoints de `stj-api` en una fase posterior.
- `actual_ecommerce` queda solo como referencia y no se modifica.
- APP/Ionic queda fuera de esta fase. Los valores historicos APP/ANDROID/IOS/HW se conservan, pero no se implementan ni se eliminan.

## 2. Decisiones funcionales confirmadas

### 2.1 Cantidad de cupones

Un carrito puede tener uno o varios cupones simultaneos. Cada cupon se valida individualmente y el resultado combinado debe respetar estas reglas:

- Ninguna linea puede alcanzar un descuento total de 100 % o mas.
- El descuento final de una linea debe quedar estrictamente por debajo de 100 %.
- Un cupon que no produzca beneficio positivo no se aplica a esa linea.
- Agregar dos veces el mismo `cup_id` al mismo carrito debe ser una operacion idempotente: no duplica la asignacion ni el descuento.
- Dos codigos distintos pueden coexistir si cada uno continua siendo valido despues de recalcular el carrito completo.

El limite tecnico exacto sera `discount < 100`. Para calculos monetarios, el total de una linea nunca podra quedar en cero ni ser negativo.

### 2.2 Jerarquia

El orden autoritativo de calculo es:

1. Precio regular vigente.
2. Promociones.
3. Cupones aplicables a las lineas resultantes.
4. Cupon de envio gratis.
5. Total final.

Las promociones tienen mayor jerarquia que los cupones. Los cupones nunca deben borrar ni recalcular hacia atras una promocion.

### 2.3 Tipo `DESCUENTO`

Representa un porcentaje de descuento. Se aplica a las lineas elegibles segun `che_aplica_promo`, productos, pais, canal, checkout y demas restricciones.

Al combinar descuentos, el motor debe calcular el resultado completo y persistir/exponer el porcentaje efectivo final de la linea. El resultado nunca puede ser 100 % o mayor.

### 2.4 Tipo `PRECIO`

`PRECIO` representa el precio final objetivo por unidad, no un monto a restar ni un saldo.

Ejemplo sin promocion:

- Precio regular: `$15.00`.
- `cup_monto`: `$10.00`.
- Porcentaje efectivo requerido: `(1 - 10 / 15) * 100 = 33.333333 %`.
- El precio regular de la linea sigue siendo `$15.00`.
- El descuento efectivo de la linea lleva el precio final a `$10.00` por unidad.

Reglas:

- El precio base almacenado en el carrito no se sobrescribe.
- El porcentaje se calcula con precision interna suficiente y solo se redondean los montos de presentacion/cobro.
- Si el precio objetivo es cero o negativo, el cupon es invalido.
- Si el precio objetivo es igual o mayor que el precio efectivo que ya produjo una promocion o un cupon anterior, el cupon no agrega descuento ni puede aumentar el precio.
- Si aplica, el porcentaje total efectivo se expresa respecto al precio regular de la linea.
- Para cantidad mayor que uno, el precio objetivo es por unidad.

### 2.5 Tipo `ENVIO_GRATIS`

- Solo produce beneficio cuando el checkout es `DOMICILIO` y existe un costo de envio positivo.
- Se aplica despues de calcular promociones y descuentos de productos.
- Varios cupones de envio gratis pueden estar asociados al carrito, pero el beneficio monetario se aplica una sola vez.
- En retiro en tienda no produce beneficio y debe quedar no aplicable.

### 2.6 `che_aplica_promo`

| Valor | Lineas elegibles |
| --- | --- |
| `REGULAR` | Solo lineas que no recibieron promocion. |
| `PROMO` | Solo lineas que recibieron promocion. |
| `TODOS` | Lineas regulares y promocionadas. |

Esta clasificacion se determina despues de resolver promociones. Un descuento producido por otro cupon no convierte una linea regular en linea promocionada.

### 2.7 Identidad y cupones personales

- Un cupon personal (`che_generico = NO`) se valida contra `stj_cupones.cup_correo`.
- No exige necesariamente una sesion autenticada.
- El correo autoritativo durante checkout es el correo validado del cliente o el correo ingresado en los datos de checkout.
- La comparacion de correo sera normalizada: `trim` y minusculas.
- Un cambio de correo obliga a revalidar todos los cupones personales.
- Un cupon generico (`che_generico = SI`) puede ser utilizado por diferentes clientes, sujeto a sus restricciones y regla de uso multiple.

### 2.8 Pais, canal y fulfillment

- En esta fase solamente aplican headers con `che_aplica IN ('TODO', 'WEB')`.
- `che_pais` debe coincidir exactamente con el pais del carrito.
- `che_checkout = TODO` aplica a domicilio y tienda.
- `che_checkout = DOMICILIO` o `TIENDA` debe coincidir con `stj_carritos.car_tipo`.
- Cambiar pais, tienda, tipo de entrega, productos, cantidades, tallas, correo o direccion invalida el calculo previo y obliga a recalcular desde cero promociones y cupones.
- Los cupones asociados no se borran automaticamente por cada cambio: se revalidan. Los que dejan de cumplir se marcan/remueven de la aplicacion activa con una razon explicita y dejan de afectar los totales.

### 2.9 Reserva, consumo y liberacion

Estados funcionales del nuevo flujo:

| Estado funcional | Significado |
| --- | --- |
| `AGREGADO` | El codigo esta asociado al carrito actual, pendiente de revalidacion en cada calculo. |
| `NO_APLICABLE` | Sigue registrado para trazabilidad, pero una modificacion del carrito hizo que no produzca beneficio o incumpla una regla. No afecta el total. |
| `ELIMINADO` | El cliente lo retiro o el sistema cerro su asociacion activa. |
| `CONSUMIDO` | Existe un pedido con pago aprobado que utilizo el cupon. |

Compatibilidad con tablas actuales:

- `AGREGADO` corresponde inicialmente a `pcu_estado = AGREGADO` y `pcu_facturado = NO`.
- `ELIMINADO` corresponde a `pcu_estado = ELIMINADO`.
- `CONSUMIDO` corresponde a una asignacion facturada y a un pago realmente `APROBADA`; no basta con que exista un pedido.
- `NO_APLICABLE` no existe en el enum actual y requerira una decision de esquema antes de implementarse. Hasta entonces puede representarse cerrando la asignacion anterior y conservando una razon en una nueva estructura de auditoria.

Reglas de concurrencia:

- En el mismo carrito, repetir el mismo cupon no crea otra fila activa.
- Si el codigo esta agregado en otro navegador pero no existe pedido aprobado, el nuevo navegador puede intentar usarlo; la validacion final y el consumo deben protegerse con transaccion y bloqueo.
- Solo un pedido aprobado puede consumir un cupon personal/no multiple.
- Fallos de pago, pedidos pendientes o abandonos no consumen el cupon.
- Una vez consumido por pago aprobado, no se libera ni se vuelve a aceptar cuando el cupon no es multiple.

### 2.10 Uso multiple

No es prioridad de la primera implementacion, pero se fija esta semantica para evitar ambiguedad:

- `che_multiple = NO` o `NULL`: despues del primer pedido aprobado, el codigo no puede volver a utilizarse.
- `che_multiple = SI`: el mismo codigo puede utilizarse en pedidos aprobados diferentes, siempre que siga vigente y cumpla todas sus reglas.
- Multiple no permite duplicar el mismo codigo dentro de un carrito ni aplicarlo dos veces al mismo pedido.

### 2.11 Headers automaticos

Los registros con `che_config_automatica IS NOT NULL` son plantillas de configuracion. La emision Web puede crear un header individual y un codigo individual para cada cliente cuando la vigencia debe comenzar en la fecha del evento.

Ejemplo `REGISTRO_EMAIL`:

1. Se consulta la plantilla automatica activa.
2. Se copia su configuracion a un header personal.
3. Se asignan `che_inicio` y `che_final` particulares.
4. Se crea un codigo unico en `stj_cupones`, ligado al correo normalizado.
5. La escritura es transaccional.
6. El correo se envia solamente despues de confirmar la transaccion.

Los headers genericos no se clonan por cliente: un header y su codigo pueden servir a muchos clientes.

## 3. Matriz de validacion

Todo cupon debe pasar las siguientes validaciones al agregarlo y nuevamente en cada recalculo, inicio de checkout y creacion/confirmacion del pedido:

| Regla | Resultado si falla |
| --- | --- |
| Codigo existe y coincide de forma normalizada | Rechazado. |
| Header `ACTIVO` | No aplicable/rechazado. |
| Detalle `ACTIVO`, salvo semantica multiple futura | Rechazado. |
| Vigencia incluye la hora actual | No aplicable. |
| Pais coincide | Se retira de la aplicacion activa. |
| Canal es `TODO` o `WEB` | No aplicable en Web. |
| Checkout coincide | Se retira de la aplicacion activa al cambiar fulfillment. |
| Correo coincide para cupon personal | Rechazado o retirado tras cambio de correo. |
| Primera compra, cuando aplica | Rechazado si ya existe pago aprobado del cliente. |
| Monto minimo | No aplicable mientras no se alcance. |
| Productos/genero/coleccion | Solo participan las lineas permitidas. |
| `REGULAR`, `PROMO` o `TODOS` | Solo participan las lineas correspondientes. |
| Beneficio positivo | No aplicable si el resultado es cero. |
| Descuento final menor que 100 % | Se limita/rechaza el aporte que viole la regla; nunca se cobra cero o negativo. |
| No consumido, salvo multiple | Rechazado. |

El monto minimo debe evaluarse sobre el subtotal definido por negocio antes de cupones. Antes de implementar queda por confirmar si historicamente se evalua sobre todo el carrito o solo sobre lineas elegibles; la implementacion no debe asumirlo silenciosamente.

## 4. Mapa real de tablas

### 4.1 `stj_cupones_header`

Contiene la politica del cupon. Se observaron 26 columnas y 27,100 filas en la base local de recuperacion.

Campos principales:

- Identidad: `che_id`, `che_nombre`, `che_nombre_comercial`.
- Alcance: `che_pais`, `che_aplica`, `che_checkout`.
- Beneficio: `che_tipo`, `che_monto`, `che_descuento`, `che_descuento_extra`.
- Vigencia/estado: `che_inicio`, `che_final`, `che_estado`.
- Uso: `che_generico`, `che_multiple`, `che_solo_primera_compra`.
- Minimo: `che_aplica_monto_minimo`, `che_monto_minimo`.
- Promocion/productos: `che_aplica_promo`, `che_tipo_productos`, `che_genero`, `che_coleccion`.
- Emision: `che_config_automatica`, `che_para`.

Hallazgos:

- Solo tiene indice primario; no hay indices para codigo automatico, pais, estado o vigencia.
- Hay siete plantillas con `che_config_automatica`, incluidas `REGISTRO_EMAIL`, `CONFIRMACION_EMAIL`, `CUMPLE`, `PRIMERA_COMPRA` y variantes APP.
- La mayoria de headers son personales Web/TODO y `REGULAR`, producidos por automatizaciones historicas.

### 4.2 `stj_cupones`

Contiene cada codigo emitido y datos de uso/saldo historicos. Se observaron 25 columnas.

Campos principales:

- `cup_id`, `cup_header`, `cup_codigo`, `cup_estado`.
- `cup_monto`, `cup_descuento`, `cup_utilizado`, `cup_disponible`.
- `cup_correo` para cupones personales.
- `cup_fecha_utilizado`, `cup_sesion_utilizado`, `cup_usuario_utilizado`.
- Campos historicos de auditoria y origen.

Hallazgos:

- Solo tiene indice primario.
- No hay restriccion unica para `cup_codigo`.
- No existe llave foranea declarada hacia el header.
- Estados encontrados: 33,753 `ACTIVO`, 1,819 `INACTIVO`, 453 `USADO`.
- El legado mezcla `INACTIVO` y `USADO`; el nuevo motor no debe inferir consumo solo por el enum, sino comprobar el pedido/pago aprobado.

### 4.3 `stj_cupones_producto`

Relaciona un header con productos permitidos:

- `cpr_cupon` referencia `stj_cupones_header.che_id`.
- `cpr_producto` referencia `stj_productos.pro_id`.
- `cpr_descuento` y `cpr_precio` permiten valores particulares por producto.

Existe un indice compuesto `cpr_cupon, cpr_producto`, pero no es unico. La implementacion debe evitar duplicados y definir precedencia de valores por producto frente a los valores del header.

### 4.4 `stj_pedidos_cupones`

Registra la asociacion del codigo con sesion/carrito y posteriormente pedido:

- Identidad: `pcu_id`, `pcu_sesion`, `pcu_user`.
- Cupon: `pcu_cupon_id`, `pcu_cupon`.
- Pedido: `pcu_pedido`, `pcu_facturado`.
- Estado: `pcu_estado` (`AGREGADO`, `ELIMINADO`).
- Contexto historico: pais, tienda, ticket, tipo y monto.

Hallazgos:

- Solo tiene indice primario y no posee llaves foraneas.
- Hay asignaciones `AGREGADO/NO`, `ELIMINADO/NO`, `AGREGADO/SI` y tambien `ELIMINADO/SI`; por ello `pcu_facturado` no equivale por si solo a pago aprobado.
- La tabla usa `pcu_sesion`, mientras el carrito nuevo tiene identidad propia (`stj_carritos.car_id`/`car_uuid`). La integracion necesitara una relacion explicita con el carrito nuevo o una nueva tabla de aplicaciones.

## 5. Integracion prevista con el carrito nuevo

`StorefrontCartService::startCheckout()` ya ejecuta este flujo:

1. Revalida inventario.
2. Resuelve precios regulares.
3. Ejecuta `StorefrontPromotionResolver`.
4. Calcula envio y total.
5. Autoriza el checkout y guarda un resumen idempotente.

El futuro `StorefrontCouponResolver` debe ejecutarse despues del paso 3 y antes del calculo final de envio/total. Debe devolver un resultado determinista, sin confiar en porcentajes enviados por Vue.

Respuesta minima esperada por linea:

```json
{
  "regularUnitPrice": 15.00,
  "promotionDiscount": 0.00,
  "couponDiscount": 5.00,
  "effectiveDiscountPercentage": 33.333333,
  "finalUnitPrice": 10.00,
  "promotion": null,
  "coupons": [{"id": 123, "code": "ABC123", "type": "PRECIO"}]
}
```

Respuesta minima esperada en el resumen:

- `promotionDiscount`.
- `couponDiscount`.
- `productDiscount` total.
- `shippingDiscount`.
- `subtotal` y `total`.
- Cupones aplicados, no aplicables y su razon.
- Version del carrito usada para el calculo.

## 6. Riesgos que no deben copiarse del legado

- Consultas construidas concatenando codigo, correo o sesion.
- Marcar un cupon usado antes de confirmar pago aprobado.
- Confiar solamente en `pcu_facturado` o `cup_estado`.
- Sobrescribir el descuento promocional sin conservar su desglose.
- Dividir por cero en cupones `PRECIO` o carritos sin lineas elegibles.
- Duplicar asignaciones del mismo codigo al repetir una solicitud.
- Crear headers/codigos automaticos sin transaccion ni control de evento duplicado.
- Eliminar fisicamente historial asociado a pedidos.
- Aplicar envio gratis modificando directamente un pedido antes de que el checkout sea confirmado.

## 7. Pendientes antes del paso 2

Se requiere cerrar estas decisiones con datos/pruebas antes de implementar el resolvedor:

1. Base exacta del monto minimo: subtotal completo o subtotal de lineas elegibles.
2. Regla de combinacion entre varios cupones porcentuales: suma de puntos porcentuales sobre precio regular o aplicacion secuencial sobre el precio restante.
3. Precedencia cuando dos cupones `PRECIO` aplican a la misma linea; recomendacion: conservar el menor precio objetivo valido.
4. Precedencia de `cpr_descuento`/`cpr_precio` frente a `cup_*` y `che_*`.
5. Precision maxima persistida para el porcentaje efectivo; el carrito nuevo actualmente maneja montos y porcentajes con redondeos distintos segun el punto del flujo.
6. Resuelto: se crearon `stj_carrito_cupones` y `stj_pedido_cupones_aplicados`; `stj_pedidos_cupones` queda como historial legacy.

El paso 2 inicia con `StorefrontCouponResolver` en `stj-api`, aun sin interfaz de dashboard ni persistencia del flujo storefront.

## 8. Avance del paso 2

Implementado en `stj-api`:

- `StorefrontCouponResolver` para calculo posterior a promociones.
- Persistencia temporal en `stj_carrito_cupones`.
- Revalidacion contra la version, pais, checkout y correo actuales del carrito.
- Consulta de primera compra mediante pedidos con pago `APROBADA`.
- Prevencion de reutilizacion de cupones no multiples consumidos en `stj_pedido_cupones_aplicados`.
- Integracion del descuento de producto en el payload autoritativo del carrito.
- Endpoints Web:
  - `POST /api/storefront/cart/{country}/coupons`
  - `DELETE /api/storefront/cart/{country}/coupons/{application}`

Completado dentro del paso 2:

- Costo y envio gratis forman parte del resumen autorizado de `startCheckout()`.
- Al crear el pedido se genera un snapshot definitivo en `stj_pedido_cupones_aplicados`.
- Efectivo aprobado consume dentro de la transaccion de creacion del pedido.
- Tarjeta consume solamente cuando `StorefrontPaymentEventService` recibe `APROBADA`.
- Estados terminales sin aprobacion cierran la aplicacion como `REVERSADO` y no consumen el codigo.
