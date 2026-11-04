-- Adds optional guardian contact fields without changing existing student records.

SET @has_guardian_alternate_phone = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'guardian_alternate_phone'
);
SET @guardian_alternate_phone_sql = IF(
    @has_guardian_alternate_phone = 0,
    'ALTER TABLE students ADD COLUMN guardian_alternate_phone VARCHAR(40) NULL AFTER guardian_phone',
    'SELECT 1'
);
PREPARE guardian_alternate_phone_statement FROM @guardian_alternate_phone_sql;
EXECUTE guardian_alternate_phone_statement;
DEALLOCATE PREPARE guardian_alternate_phone_statement;

SET @has_guardian_email = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'students' AND column_name = 'guardian_email'
);
SET @guardian_email_sql = IF(
    @has_guardian_email = 0,
    'ALTER TABLE students ADD COLUMN guardian_email VARCHAR(190) NULL AFTER guardian_alternate_phone',
    'SELECT 1'
);
PREPARE guardian_email_statement FROM @guardian_email_sql;
EXECUTE guardian_email_statement;
DEALLOCATE PREPARE guardian_email_statement;
