-- Persist custom Kitchen categories for reuse in future entries.
-- No sample categories or expense records are inserted.
CREATE TABLE IF NOT EXISTS kitchen_expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(40) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kitchen_expense_category_name (category_name),
    CONSTRAINT fk_kitchen_expense_category_creator FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
