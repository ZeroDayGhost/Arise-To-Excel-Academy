-- Combined parent-payment allocation support. Safe to import more than once.
-- Existing fee/activity/daycare payment rows are not changed or deleted.
CREATE TABLE IF NOT EXISTS payment_transactions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    receipt_no VARCHAR(60) NOT NULL UNIQUE,
    parent_user_id INT UNSIGNED NULL,
    daycare_guardian_id INT UNSIGNED NULL,
    payer_name VARCHAR(150) NULL,
    payment_method VARCHAR(40) NOT NULL,
    mpesa_code VARCHAR(100) NULL UNIQUE,
    total_amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Recorded',
    request_key CHAR(64) NOT NULL UNIQUE,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_payment_transactions_date_status (payment_date,status),
    KEY idx_payment_transactions_parent_date (parent_user_id,payment_date),
    KEY idx_payment_transactions_daycare_guardian (daycare_guardian_id,payment_date),
    CONSTRAINT fk_payment_transactions_parent FOREIGN KEY (parent_user_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payment_transactions_daycare_guardian FOREIGN KEY (daycare_guardian_id) REFERENCES daycare_guardians(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_payment_transactions_recorder FOREIGN KEY (recorded_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK (total_amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_allocations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    transaction_id BIGINT UNSIGNED NOT NULL,
    source_module ENUM('fees','activity','daycare','transport','uniform','feeding') NOT NULL,
    source_payment_id BIGINT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NULL,
    daycare_child_id INT UNSIGNED NULL,
    payment_category VARCHAR(120) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    academic_year VARCHAR(4) NOT NULL DEFAULT '',
    term VARCHAR(20) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'Recorded',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_allocation_source (source_module,source_payment_id),
    KEY idx_payment_allocations_transaction (transaction_id,id),
    KEY idx_payment_allocations_student_period (student_id,academic_year,term),
    KEY idx_payment_allocations_daycare_child (daycare_child_id,academic_year,term),
    CONSTRAINT fk_payment_allocations_transaction FOREIGN KEY (transaction_id) REFERENCES payment_transactions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payment_allocations_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_payment_allocations_daycare_child FOREIGN KEY (daycare_child_id) REFERENCES daycare_children(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK (amount > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The M-Pesa reference is unique on the parent transaction, not on individual
-- daycare allocation rows. Drop the legacy unique index only if present.
SET @daycare_ref_unique = (
    SELECT INDEX_NAME FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='daycare_payments'
      AND COLUMN_NAME='payment_reference' AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY'
    LIMIT 1
);
SET @daycare_ref_ddl = IF(@daycare_ref_unique IS NULL,'SELECT 1',CONCAT('ALTER TABLE daycare_payments DROP INDEX `',REPLACE(@daycare_ref_unique,'`','``'),'`'));
PREPARE daycare_ref_stmt FROM @daycare_ref_ddl;
EXECUTE daycare_ref_stmt;
DEALLOCATE PREPARE daycare_ref_stmt;

SET @daycare_ref_index_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='daycare_payments' AND INDEX_NAME='idx_daycare_payment_reference'
);
SET @daycare_ref_index_ddl = IF(@daycare_ref_index_exists>0,'SELECT 1','ALTER TABLE daycare_payments ADD INDEX idx_daycare_payment_reference (payment_reference)');
PREPARE daycare_ref_index_stmt FROM @daycare_ref_index_ddl;
EXECUTE daycare_ref_index_stmt;
DEALLOCATE PREPARE daycare_ref_index_stmt;
