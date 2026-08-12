-- Ejecutar manualmente solo despues de revisar URL, textos, paises y tiempos.
-- Esta fase no agrega el evaluador al scheduler y no envia notificaciones.

INSERT INTO stj_push_automatizaciones (
    pau_codigo, pau_nombre, pau_descripcion, pau_estado, pau_paises,
    pau_retraso_minutos, pau_cooldown_horas, pau_maximo_por_entidad,
    pau_titulo_plantilla, pau_cuerpo_plantilla, pau_action_plantilla,
    pau_imagen, pau_configuracion, pau_creado_en, pau_actualizado_en
) VALUES (
    'ABANDONED_CART',
    'Carrito abandonado',
    'Primer recordatorio para carritos WEB activos con productos y sin actividad.',
    'INACTIVA',
    NULL,
    120,
    24,
    1,
    '¿Olvidaste algo?',
    'Los productos de tu carrito todavía te están esperando.',
    'https://stjacks.com/{country}/carrito',
    NULL,
    JSON_OBJECT('stages', JSON_ARRAY('PRIMARY')),
    NOW(6),
    NOW(6)
);
