-- Propuesta revisable. No ejecutar directamente en produccion.
-- SV conserva las fuentes locales que ya estan persistidas.
-- GT/CR/PA/HN usan la integracion generica externa existente; checkout no tiene fallback.
INSERT INTO stj_inventory_source_rules
    (isr_country_code, isr_scope, isr_source, isr_fallback_source, isr_is_active, isr_notes, isr_created_at, isr_updated_at)
VALUES
    ('SV', 'cart', 'external_api', 'local_inventory', 1, 'Persiste explicitamente el default actual del scope cart', NOW(), NOW()),
    ('GT', 'product_detail', 'external_api', 'local_inventory', 1, 'API-Inventario generica; fallback local permitido fuera de checkout', NOW(), NOW()),
    ('GT', 'cart', 'external_api', 'local_inventory', 1, 'API-Inventario generica; fallback local permitido', NOW(), NOW()),
    ('GT', 'checkout', 'external_api', NULL, 1, 'Regla critica: sin fallback silencioso', NOW(), NOW()),
    ('CR', 'product_detail', 'external_api', 'local_inventory', 1, 'API-Inventario generica; fallback local permitido fuera de checkout', NOW(), NOW()),
    ('CR', 'cart', 'external_api', 'local_inventory', 1, 'API-Inventario generica; fallback local permitido', NOW(), NOW()),
    ('CR', 'checkout', 'external_api', NULL, 1, 'Regla critica: sin fallback silencioso', NOW(), NOW()),
    ('PA', 'product_detail', 'external_api', 'local_inventory', 1, 'API-Inventario generica; fallback local permitido fuera de checkout', NOW(), NOW()),
    ('PA', 'cart', 'external_api', 'local_inventory', 1, 'API-Inventario generica; fallback local permitido', NOW(), NOW()),
    ('PA', 'checkout', 'external_api', NULL, 1, 'Regla critica: sin fallback silencioso', NOW(), NOW()),
    ('HN', 'product_detail', 'external_api', 'local_inventory', 1, 'API-Inventario generica; fallback local permitido fuera de checkout', NOW(), NOW()),
    ('HN', 'cart', 'external_api', 'local_inventory', 1, 'API-Inventario generica; fallback local permitido', NOW(), NOW()),
    ('HN', 'checkout', 'external_api', NULL, 1, 'Regla critica: sin fallback silencioso', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    isr_source = VALUES(isr_source),
    isr_fallback_source = VALUES(isr_fallback_source),
    isr_is_active = VALUES(isr_is_active),
    isr_notes = VALUES(isr_notes),
    isr_updated_at = NOW();
