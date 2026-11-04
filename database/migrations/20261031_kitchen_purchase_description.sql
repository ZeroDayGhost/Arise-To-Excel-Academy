-- Add the optional description/notes field used by Kitchen purchase entries.
-- Safe to rerun on installations where the column has already been added.
SET @kitchen_purchase_table_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'kitchen_purchase_records'
);
SET @kitchen_purchase_description_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'kitchen_purchase_records'
      AND COLUMN_NAME = 'description'
);
SET @kitchen_purchase_schema_sql = IF(
    @kitchen_purchase_table_exists = 1 AND @kitchen_purchase_description_exists = 0,
    'ALTER TABLE kitchen_purchase_records ADD COLUMN description TEXT NULL AFTER supplier',
    'SELECT 1'
);
PREPARE kitchen_purchase_schema_stmt FROM @kitchen_purchase_schema_sql;
EXECUTE kitchen_purchase_schema_stmt;
DEALLOCATE PREPARE kitchen_purchase_schema_stmt;
