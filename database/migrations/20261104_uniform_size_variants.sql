-- Size-specific uniform inventory. Safe to import more than once; preserves existing catalog and sales.
CREATE TABLE IF NOT EXISTS uniform_variants (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uniform_id INT UNSIGNED NOT NULL,
    size VARCHAR(30) NOT NULL,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    opening_stock INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_uniform_variant_size (uniform_id, size),
    KEY idx_uniform_variants_status (status),
    CONSTRAINT fk_uniform_variants_uniform FOREIGN KEY (uniform_id) REFERENCES uniforms(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

DELIMITER //
DROP PROCEDURE IF EXISTS migrate_uniform_size_variants//
CREATE PROCEDURE migrate_uniform_size_variants()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uniform_stock_movements' AND COLUMN_NAME = 'uniform_variant_id'
    ) THEN
        ALTER TABLE uniform_stock_movements ADD COLUMN uniform_variant_id INT UNSIGNED NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uniform_stock_movements' AND INDEX_NAME = 'idx_uniform_stock_movements_variant'
    ) THEN
        ALTER TABLE uniform_stock_movements ADD INDEX idx_uniform_stock_movements_variant (uniform_variant_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uniform_sale_items' AND COLUMN_NAME = 'uniform_variant_id'
    ) THEN
        ALTER TABLE uniform_sale_items ADD COLUMN uniform_variant_id INT UNSIGNED NULL;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'uniform_sale_items' AND INDEX_NAME = 'idx_uniform_sale_items_variant'
    ) THEN
        ALTER TABLE uniform_sale_items ADD INDEX idx_uniform_sale_items_variant (uniform_variant_id);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'uniform_stock_movements' AND CONSTRAINT_NAME = 'fk_uniform_stock_movements_variant'
    ) THEN
        ALTER TABLE uniform_stock_movements
            ADD CONSTRAINT fk_uniform_stock_movements_variant FOREIGN KEY (uniform_variant_id)
            REFERENCES uniform_variants(id) ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'uniform_sale_items' AND CONSTRAINT_NAME = 'fk_uniform_sale_items_variant'
    ) THEN
        ALTER TABLE uniform_sale_items
            ADD CONSTRAINT fk_uniform_sale_items_variant FOREIGN KEY (uniform_variant_id)
            REFERENCES uniform_variants(id) ON DELETE SET NULL ON UPDATE CASCADE;
    END IF;
END//
CALL migrate_uniform_size_variants()//
DROP PROCEDURE migrate_uniform_size_variants//
DELIMITER ;

-- Preserve each existing one-size item as its first size variant.
INSERT INTO uniform_variants (uniform_id, size, selling_price, opening_stock, reorder_level, status)
SELECT u.id, u.size, u.selling_price, u.opening_stock, u.reorder_level, u.status
FROM uniforms u
WHERE NOT EXISTS (
    SELECT 1 FROM uniform_variants uv WHERE uv.uniform_id = u.id AND uv.size = u.size
);

-- Existing sale line snapshots already contain the sold size; attach them to the matching variant.
UPDATE uniform_sale_items si
JOIN uniform_variants uv ON uv.uniform_id = si.uniform_id AND uv.size = si.size
SET si.uniform_variant_id = uv.id
WHERE si.uniform_variant_id IS NULL;

-- Old stock movements had no size column, so attach them to the legacy product's original size.
UPDATE uniform_stock_movements sm
JOIN uniforms u ON u.id = sm.uniform_id
JOIN uniform_variants uv ON uv.uniform_id = u.id AND uv.size = u.size
SET sm.uniform_variant_id = uv.id
WHERE sm.uniform_variant_id IS NULL;
