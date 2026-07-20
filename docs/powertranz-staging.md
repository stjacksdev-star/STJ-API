# Guía manual de PowerTranz staging

No usar credenciales ni tarjetas de producción. No ejecutar esta guía automáticamente.

## Requisitos

- El retorno real de PowerTranz requiere una URL HTTPS pública; `localhost` sirve únicamente para mocks y retornos simulados.
- La URL pública debe estar registrada o permitida en la cuenta BAC cuando su configuración lo requiera.
- Configurar solamente las credenciales staging del país que se probará.
- Configurar `POWERTRANZ_RETURN_BASE_URL=https://test-api.stjacks.com/api/storefront/payments/powertranz/return`.
- Configurar `POWERTRANZ_FRONTEND_RESULT_URL=https://stjecommerce.stjacks.com/{country}/pago/resultado/{hint}`.
- Ejecutar `php artisan config:clear` después de cambiar el ambiente.

## Primera prueba: TIENDA + TARJETA

1. Configurar las credenciales PowerTranz staging del país.
2. Configurar las dos URLs públicas de staging.
3. Crear un carrito con tienda confirmada.
4. Agregar un producto con inventario.
5. Iniciar checkout.
6. Elegir `TARJETA`.
7. Crear el pedido.
8. Iniciar `/api/spi/sale` mediante el endpoint del storefront.
9. Continuar `RedirectData` y completar 3DS.
10. Confirmar que PowerTranz envía el POST primero a `stj-api`.
11. Confirmar la segunda llamada servidor-a-servidor a `/api/spi/payment`.
12. Verificar `stj_pedidos_pago` y `stj_powertranz_operaciones` sin inspeccionar datos de tarjeta.
13. Verificar que exista un único evento `PURCHASE` cuando se aprueba.
14. Verificar la pantalla final de `stj-ecommerce`.
15. Repetir con una tarjeta oficial de rechazo y confirmar que no se crea `PURCHASE`.

## Segunda prueba: DOMICILIO + TARJETA

Repetir el mismo recorrido con un carrito `DOMICILIO`. El total autorizado persistido ya incluye el envío; PowerTranz recibe solamente `TotalAmount` y nunca `ShippingAmount`.

## Verificaciones de seguridad

- Repetir inicio y retorno con el mismo UUID/token no debe duplicar la operación ni `PURCHASE`.
- Cambiar el contenido reutilizando un UUID debe producir conflicto.
- PAN, CVV, vencimiento, `SpiToken` y `RedirectData` no deben aparecer en base, logs, URLs, cookies, localStorage ni sessionStorage.
- El token de retorno es opaco, se almacena únicamente como hash y expira.
- No habilitar ni implementar tokenización o tarjetas guardadas.

## Consultas sanitizadas posteriores

Reemplazar `:pedido_id` mediante un parámetro enlazado del cliente SQL. No consultar `pto_respuesta_segura`, hashes, PAN, CVV, vencimiento ni payloads completos.

```sql
SELECT ped_id, ped_estatus, ped_checkout, ped_tienda, ped_pais, ped_fecha
FROM stj_pedidos WHERE ped_id = :pedido_id;

SELECT ppa_id, ppa_tipo, ppa_estado, ppa_ref, ppa_monto, ppa_autorizacion,
       ppa_rsp_codigo, ppa_rsp_mensaje, ppa_pagado, ppa_fecha_procesado
FROM stj_pedidos_pago WHERE ppa_pedido = :pedido_id;

SELECT pto_id, pto_pago_id, pto_estado, pto_creado_en, pto_actualizado_en
FROM stj_powertranz_operaciones
WHERE pto_pago_id IN (SELECT ppa_id FROM stj_pedidos_pago WHERE ppa_pedido = :pedido_id);

SELECT car_id, car_estado, car_pedido_id, car_tipo, car_moneda, car_convertido_en
FROM stj_carritos WHERE car_pedido_id = :pedido_id;

SELECT cev_tipo, COUNT(*) AS cantidad
FROM stj_cliente_eventos
WHERE cev_pedido_id = :pedido_id AND cev_tipo IN ('ORDER_CREATED', 'PURCHASE')
GROUP BY cev_tipo;
```

Debe existir un pedido, un pago, una operación PowerTranz y como máximo un `PURCHASE`. La referencia, importe y moneda deben coincidir entre pedido, pago y evento autorizado.

Antes de producción deben validarse las URLs públicas, la autorización del callback en BAC y las credenciales de producción fuera del repositorio.
