-- Ejecutar solamente despues de desplegar stj-api e Ionic sin idSesion/sessionCode.
-- La identidad push queda en psu_instalacion_uuid y la del pedido en car_uuid.
-- Antes de ejecutar, esta consulta debe devolver cero filas:
-- SELECT TABLE_NAME, COLUMN_NAME, CONSTRAINT_NAME
-- FROM information_schema.KEY_COLUMN_USAGE
-- WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
--   AND REFERENCED_TABLE_NAME = 'stj_sesiones';

ALTER TABLE `stj_push_suscripciones`
  DROP COLUMN `psu_sesion_id`,
  DROP COLUMN `psu_sesion_codigo`;

DROP TABLE `stj_sesiones`;
