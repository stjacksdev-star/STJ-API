# Guía manual de PowerTranz staging

No usar credenciales ni tarjetas de producción.

1. Configure `POWERTRANZ_ENVIRONMENT=staging`, las dos URLs de staging, la URL pública HTTPS de resultado del frontend y únicamente las credenciales de prueba del país.
2. Registre en PowerTranz la URL pública generada por la API: `POST /api/storefront/payments/powertranz/return/{country}/{token}`. El token es opaco y cambia por operación.
3. Ejecute `php artisan config:clear` y, cuando la configuración esté validada, `php artisan config:cache`.
4. Cree un carrito de prueba, confirme fulfillment, ejecute `checkout/start` y cree el pedido. Para Domicilio, el inicio permanecerá bloqueado hasta que el costo de envío deje de estar pendiente.
5. Inicie `POST /api/storefront/orders/{order}/payments/powertranz` con un `operation_uuid` estable y datos de tarjeta de prueba suministrados por BAC. No copie esos datos a logs, fixtures o capturas.
6. Renderice `redirectData` en el documento dedicado y complete el challenge 3DS. PowerTranz enviará el POST de retorno y la API confirmará el resultado mediante `/api/spi/payment`.
7. Verifique `stj_pedidos_pago.ppa_estado`, `ppa_transactionidentifier`, la operación cifrada en `stj_powertranz_operaciones` y los eventos `ORDER_CREATED`/`PURCHASE`.
8. Para rechazo use el escenario de prueba documentado por BAC. Confirme que queda `DENEGADA` y no existe PURCHASE.
9. Repita inicio y retorno con el mismo UUID/token para comprobar que no se duplica el intento ni PURCHASE. Cambiar el contenido con el mismo UUID debe producir 409.
10. Identifique datos de prueba por referencia `ppa_ref` y ambiente. No ejecute borrados generales ni elimine pedidos fuera del conjunto de prueba confirmado.

Antes de una prueba real de Domicilio se debe migrar el cálculo autorizado de envío por país. Antes de producción deben rotarse las credenciales históricas y validarse las URLs/credenciales de producción fuera del repositorio.
