-- Add academic-year-aware fee sponsorship without changing existing payment or balance records.
SET @sponsorship_db := DATABASE();

SET @has_sponsorship_status := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @sponsorship_db AND TABLE_NAME = 'students' AND COLUMN_NAME = 'fee_sponsorship_status'
);
SET @sponsorship_sql := IF(@has_sponsorship_status = 0,
    "ALTER TABLE students ADD COLUMN fee_sponsorship_status VARCHAR(30) NOT NULL DEFAULT 'Regular Student' AFTER student_type",
    'SELECT 1');
PREPARE sponsorship_stmt FROM @sponsorship_sql;
EXECUTE sponsorship_stmt;
DEALLOCATE PREPARE sponsorship_stmt;

CREATE TABLE IF NOT EXISTS student_fee_sponsorship_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    sponsorship_status VARCHAR(30) NOT NULL DEFAULT 'Regular Student',
    changed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_sponsorship_year (student_id, academic_year),
    KEY idx_sponsorship_year_status (academic_year, sponsorship_status),
    CONSTRAINT fk_student_sponsorship_history_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;
