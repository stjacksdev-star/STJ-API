-- Esquema manual para el reporte diario de inventario e-commerce.
-- Ejecutar manualmente con un usuario autorizado. La aplicacion no ejecuta este archivo.
-- No reemplaza ni modifica rep_inventario o stj_inventario.

CREATE TABLE IF NOT EXISTS stj_inventory_report_runs (
    irr_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    irr_report_date DATE NOT NULL,
    irr_country_id BIGINT UNSIGNED NOT NULL,
    irr_country_code VARCHAR(3) NOT NULL,
    irr_country_name VARCHAR(80) NOT NULL,
    irr_status VARCHAR(20) NOT NULL DEFAULT 'CREATED',
    irr_expected_products INT UNSIGNED NOT NULL DEFAULT 0,
    irr_found_products INT UNSIGNED NOT NULL DEFAULT 0,
    irr_not_returned_products INT UNSIGNED NOT NULL DEFAULT 0,
    irr_not_found_products INT UNSIGNED NOT NULL DEFAULT 0,
    irr_failed_products INT UNSIGNED NOT NULL DEFAULT 0,
    irr_result_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
    irr_started_at DATETIME NULL,
    irr_queries_closed_at DATETIME NULL,
    irr_completed_at DATETIME NULL,
    irr_excel_path VARCHAR(500) NULL,
    irr_excel_sha256 CHAR(64) NULL,
    irr_last_error TEXT NULL,
    irr_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    irr_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (irr_id),
    UNIQUE KEY uq_inventory_report_run_date_country (irr_report_date, irr_country_id),
    KEY ix_inventory_report_runs_status_date (irr_status, irr_report_date),
    KEY ix_inventory_report_runs_country_status (irr_country_code, irr_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stj_inventory_report_products (
    irp_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    irp_run_id BIGINT UNSIGNED NOT NULL,
    irp_product_id BIGINT UNSIGNED NOT NULL,
    irp_code VARCHAR(100) NOT NULL,
    irp_category_id BIGINT UNSIGNED NULL,
    irp_year VARCHAR(20) NULL,
    irp_quarter VARCHAR(30) NULL,
    irp_collection VARCHAR(255) NULL,
    irp_gender VARCHAR(150) NULL,
    irp_brand VARCHAR(150) NULL,
    irp_category VARCHAR(150) NULL,
    irp_license VARCHAR(150) NULL,
    irp_character VARCHAR(150) NULL,
    irp_description VARCHAR(500) NULL,
    irp_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    irp_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    irp_last_http_status SMALLINT UNSIGNED NULL,
    irp_last_error TEXT NULL,
    irp_first_requested_at DATETIME NULL,
    irp_last_requested_at DATETIME NULL,
    irp_completed_at DATETIME NULL,
    irp_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    irp_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (irp_id),
    UNIQUE KEY uq_inventory_report_product (irp_run_id, irp_product_id),
    KEY ix_inventory_report_products_work (irp_run_id, irp_status, irp_attempts, irp_id),
    KEY ix_inventory_report_products_code (irp_run_id, irp_code),
    CONSTRAINT fk_inventory_report_products_run
        FOREIGN KEY (irp_run_id) REFERENCES stj_inventory_report_runs (irr_id)
        ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stj_inventory_report_requests (
    irq_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    irq_run_id BIGINT UNSIGNED NOT NULL,
    irq_adapter VARCHAR(30) NOT NULL,
    irq_attempt TINYINT UNSIGNED NOT NULL,
    irq_batch_key CHAR(36) NOT NULL,
    irq_endpoint VARCHAR(500) NOT NULL,
    irq_requested_products INT UNSIGNED NOT NULL DEFAULT 0,
    irq_returned_products INT UNSIGNED NOT NULL DEFAULT 0,
    irq_returned_rows BIGINT UNSIGNED NOT NULL DEFAULT 0,
    irq_http_status SMALLINT UNSIGNED NULL,
    irq_duration_ms INT UNSIGNED NULL,
    irq_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    irq_error TEXT NULL,
    irq_started_at DATETIME NULL,
    irq_completed_at DATETIME NULL,
    irq_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (irq_id),
    UNIQUE KEY uq_inventory_report_request_batch_attempt (irq_run_id, irq_batch_key, irq_attempt),
    KEY ix_inventory_report_requests_status (irq_run_id, irq_status),
    CONSTRAINT fk_inventory_report_requests_run
        FOREIGN KEY (irq_run_id) REFERENCES stj_inventory_report_runs (irr_id)
        ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stj_inventory_report_rows (
    irw_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    irw_run_id BIGINT UNSIGNED NOT NULL,
    irw_product_id BIGINT UNSIGNED NOT NULL,
    irw_request_id BIGINT UNSIGNED NULL,
    irw_store VARCHAR(100) NOT NULL,
    irw_size VARCHAR(100) NOT NULL,
    irw_quantity DECIMAL(18,4) NOT NULL DEFAULT 0,
    irw_sale_price DECIMAL(18,4) NULL,
    irw_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    irw_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (irw_id),
    UNIQUE KEY uq_inventory_report_row (irw_run_id, irw_product_id, irw_store, irw_size),
    KEY ix_inventory_report_rows_run_store (irw_run_id, irw_store),
    KEY ix_inventory_report_rows_request (irw_request_id),
    CONSTRAINT fk_inventory_report_rows_run
        FOREIGN KEY (irw_run_id) REFERENCES stj_inventory_report_runs (irr_id)
        ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_inventory_report_rows_product
        FOREIGN KEY (irw_product_id) REFERENCES stj_inventory_report_products (irp_id)
        ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_inventory_report_rows_request
        FOREIGN KEY (irw_request_id) REFERENCES stj_inventory_report_requests (irq_id)
        ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS stj_inventory_report_deliveries (
    ird_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ird_report_date DATE NOT NULL,
    ird_attempt TINYINT UNSIGNED NOT NULL DEFAULT 1,
    ird_status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
    ird_to TEXT NOT NULL,
    ird_cc TEXT NULL,
    ird_bcc TEXT NULL,
    ird_subject VARCHAR(255) NOT NULL,
    ird_attachments TEXT NULL,
    ird_provider_reference VARCHAR(255) NULL,
    ird_error TEXT NULL,
    ird_started_at DATETIME NULL,
    ird_sent_at DATETIME NULL,
    ird_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (ird_id),
    UNIQUE KEY uq_inventory_report_delivery_attempt (ird_report_date, ird_attempt),
    KEY ix_inventory_report_deliveries_status (ird_status, ird_report_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
