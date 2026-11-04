-- Extend combined-payment allocations to all student payment modules.
-- Import after 20261103_multi_student_payments.sql. Existing payment history is retained.
ALTER TABLE payment_allocations
    MODIFY source_module ENUM('fees','activity','daycare','transport','uniform','feeding') NOT NULL;

CREATE TABLE IF NOT EXISTS uniform_sale_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    payment_method VARCHAR(30) NOT NULL,
    reference_no VARCHAR(80) NULL,
    academic_year VARCHAR(4) NOT NULL DEFAULT '',
    term VARCHAR(20) NOT NULL DEFAULT '',
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_uniform_sale_payments_sale_date (sale_id,payment_date,id),
    CONSTRAINT fk_uniform_sale_payments_sale FOREIGN KEY (sale_id) REFERENCES uniform_sales(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_uniform_sale_payments_recorder FOREIGN KEY (recorded_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK (amount_paid > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
