-- Arise To Excel Academy additive deployment schema.
-- In phpMyAdmin, select the intended application database before importing.
-- This file creates missing tables only. It never drops databases/tables,
-- truncates records, or changes existing table contents.
-- Existing tables are left intact; apply documented migrations for old columns.

CREATE TABLE IF NOT EXISTS admin (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    username VARCHAR(60) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    class_level VARCHAR(30) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS roles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role_name VARCHAR(50) NOT NULL,
    name VARCHAR(50) NOT NULL,
    description TEXT,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_roles_role_name (role_name),
    UNIQUE KEY uniq_roles_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_roles (
    user_id INT UNSIGNED NOT NULL,
    role_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    permission_key VARCHAR(100) NOT NULL UNIQUE,
    label VARCHAR(150) NOT NULL,
    module VARCHAR(60) NOT NULL,
    action VARCHAR(80) NULL,
    module_name VARCHAR(80) NULL,
    permission_name VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_permissions (
    user_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    registration_no VARCHAR(40) NOT NULL UNIQUE,
    enrollment_status VARCHAR(20) NOT NULL DEFAULT 'Active',
    full_name VARCHAR(150) NOT NULL,
    gender VARCHAR(10) NOT NULL,
    class_level VARCHAR(30) NOT NULL,
    admitted_academic_year VARCHAR(4) NULL,
    student_type VARCHAR(30) NOT NULL DEFAULT 'Normal Student',
    fee_sponsorship_status VARCHAR(30) NOT NULL DEFAULT 'Regular Student',
    parent_name VARCHAR(150) NOT NULL,
    guardian_phone VARCHAR(40) NULL,
    guardian_alternate_phone VARCHAR(40) NULL,
    guardian_email VARCHAR(190) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_students_enrollment_status (enrollment_status)
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS student_class_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    class_level VARCHAR(80) NOT NULL,
    assigned_from_class VARCHAR(80) NULL,
    assignment_reason VARCHAR(30) NOT NULL DEFAULT 'manual',
    assigned_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_class_year (student_id, academic_year),
    KEY idx_class_assignment_year_class (academic_year, class_level),
    CONSTRAINT fk_class_assignment_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_class_assignment_actor FOREIGN KEY (assigned_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_class_promotions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    from_year VARCHAR(4) NOT NULL,
    to_year VARCHAR(4) NOT NULL,
    source_term VARCHAR(20) NOT NULL,
    target_term VARCHAR(20) NOT NULL,
    promoted_by INT UNSIGNED NULL,
    counts_json LONGTEXT NOT NULL,
    undone_at TIMESTAMP NULL DEFAULT NULL,
    undone_by INT UNSIGNED NULL,
    undo_reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_class_year_transition (from_year, to_year),
    CONSTRAINT fk_student_promotion_actor FOREIGN KEY (promoted_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_class_promotion_items (
    promotion_id BIGINT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    previous_class VARCHAR(80) NOT NULL,
    next_class VARCHAR(80) NOT NULL,
    previous_status VARCHAR(20) NOT NULL,
    next_status VARCHAR(20) NOT NULL,
    source_assignment_id BIGINT UNSIGNED NULL,
    target_assignment_id BIGINT UNSIGNED NULL,
    PRIMARY KEY (promotion_id, student_id),
    KEY idx_promotion_item_student (student_id),
    CONSTRAINT fk_promotion_item_promotion FOREIGN KEY (promotion_id) REFERENCES student_class_promotions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_promotion_item_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Idempotently recover source-year classes from promotion snapshots where needed.
INSERT IGNORE INTO student_class_promotion_items
    (promotion_id, student_id, previous_class, next_class, previous_status, next_status, source_assignment_id, target_assignment_id)
SELECT p.id, target.student_id, COALESCE(source.class_level, target.assigned_from_class), target.class_level,
       'Active', 'Active', source.id, target.id
FROM student_class_promotions p
JOIN student_class_assignments target
  ON target.academic_year = p.to_year AND target.assignment_reason IN ('promotion', 'promotion_carryover')
LEFT JOIN student_class_assignments source
  ON source.student_id = target.student_id AND source.academic_year = p.from_year
WHERE COALESCE(source.class_level, target.assigned_from_class) IS NOT NULL;

INSERT IGNORE INTO student_class_assignments
    (student_id, academic_year, class_level, assignment_reason)
SELECT i.student_id, p.from_year, i.previous_class, 'promotion_source'
FROM student_class_promotion_items i
JOIN student_class_promotions p ON p.id = i.promotion_id
WHERE i.previous_class <> '';

UPDATE student_class_promotion_items i
JOIN student_class_promotions p ON p.id = i.promotion_id
JOIN student_class_assignments source
  ON source.student_id = i.student_id AND source.academic_year = p.from_year
SET i.source_assignment_id = source.id
WHERE i.source_assignment_id IS NULL;

CREATE TABLE IF NOT EXISTS student_class_promotion_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    promotion_id BIGINT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(30) NOT NULL,
    reason TEXT NULL,
    affected_students INT UNSIGNED NOT NULL DEFAULT 0,
    details_json LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_promotion_audit_promotion (promotion_id, created_at),
    CONSTRAINT fk_promotion_audit_promotion FOREIGN KEY (promotion_id) REFERENCES student_class_promotions(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_promotion_audit_actor FOREIGN KEY (actor_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_admission_sequence (
    singleton_id TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    next_number BIGINT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_admission_number_migrations (
    student_id INT UNSIGNED NOT NULL PRIMARY KEY,
    old_registration_no VARCHAR(40) NOT NULL,
    new_registration_no VARCHAR(40) NOT NULL UNIQUE,
    migrated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_status_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    previous_status VARCHAR(20) NOT NULL,
    new_status VARCHAR(20) NOT NULL,
    effective_date DATE NOT NULL,
    destination_school VARCHAR(150) NULL,
    reason VARCHAR(255) NULL,
    notes TEXT NULL,
    changed_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_student_status_history_student (student_id, id),
    CONSTRAINT fk_student_status_history_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_student_status_history_actor FOREIGN KEY (changed_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_levels (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_class_levels_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_structures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(4) NOT NULL,
    class_level VARCHAR(30) NOT NULL,
    term1_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    term2_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    term3_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fee_structure_year_class (academic_year, class_level)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_balances (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    required_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    paid_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_fee_balance_student_year_term (student_id, academic_year, term),
    CONSTRAINT fk_fee_balances_student_term
      FOREIGN KEY (student_id) REFERENCES students(id)
      ON DELETE RESTRICT
      ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_fee_discounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    original_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_percentage DECIMAL(5,2) NOT NULL DEFAULT 50.00,
    discounted_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_fee_discount_term (student_id, academic_year, term),
    CONSTRAINT fk_student_fee_discounts_student
      FOREIGN KEY (student_id) REFERENCES students(id)
      ON DELETE RESTRICT
      ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS academic_calendar (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    academic_year VARCHAR(4) NOT NULL,
    term_name VARCHAR(20) NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_academic_calendar_year_term (academic_year, term_name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS academic_settings (
    setting_key VARCHAR(60) PRIMARY KEY,
    setting_value VARCHAR(100) NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fees (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    receipt_no VARCHAR(60) NOT NULL UNIQUE,
    amount_paid DECIMAL(12,2) NOT NULL,
    mpesa_code VARCHAR(30) NOT NULL,
    mpesa_reference_text TEXT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'M-PESA',
    term VARCHAR(20) NOT NULL,
    year VARCHAR(4) NOT NULL,
    balance_after_payment DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_date DATE NOT NULL,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'Recorded',
    reversed_at DATETIME NULL,
    reversed_by INT UNSIGNED NULL,
    reversal_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fees_student
      FOREIGN KEY (student_id) REFERENCES students(id)
      ON DELETE RESTRICT
      ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_receipt_sequences (
    issue_year CHAR(4) NOT NULL PRIMARY KEY,
    next_number INT UNSIGNED NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_payment_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    receipt_no VARCHAR(60) NOT NULL,
    event_type VARCHAR(30) NOT NULL,
    actor_id INT UNSIGNED NULL,
    reason VARCHAR(500) NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fee_payment_audit_payment (payment_id, created_at),
    KEY idx_fee_payment_audit_receipt (receipt_no),
    CONSTRAINT fk_fee_payment_audit_payment FOREIGN KEY (payment_id) REFERENCES fees(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fee_payment_audit_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_payment_correction_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    receipt_no VARCHAR(60) NOT NULL,
    requested_by INT UNSIGNED NULL,
    requested_by_name VARCHAR(150) NOT NULL,
    reason VARCHAR(500) NOT NULL,
    original_values JSON NOT NULL,
    proposed_values JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    decided_by INT UNSIGNED NULL,
    decided_by_name VARCHAR(150) NULL,
    decision_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at DATETIME NULL,
    KEY idx_fee_correction_status_created (status, created_at),
    KEY idx_fee_correction_payment_status (payment_id, status),
    CONSTRAINT fk_fee_correction_payment FOREIGN KEY (payment_id) REFERENCES fees(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fee_correction_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fee_correction_requester FOREIGN KEY (requested_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_fee_correction_decider FOREIGN KEY (decided_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_payment_imports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    source_filename VARCHAR(255) NOT NULL,
    source_sha256 CHAR(64) NOT NULL UNIQUE,
    actor_id INT UNSIGNED NULL,
    academic_year VARCHAR(4) NULL,
    term VARCHAR(20) NULL,
    total_rows INT UNSIGNED NOT NULL DEFAULT 0,
    imported_rows INT UNSIGNED NOT NULL DEFAULT 0,
    skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
    rejected_rows INT UNSIGNED NOT NULL DEFAULT 0,
    error_count INT UNSIGNED NOT NULL DEFAULT 0,
    duplicate_warnings INT UNSIGNED NOT NULL DEFAULT 0,
    summary JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_fee_payment_import_actor (actor_id, created_at),
    CONSTRAINT fk_fee_payment_import_actor FOREIGN KEY (actor_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fee_payment_import_rows (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    import_id BIGINT UNSIGNED NOT NULL,
    source_row_number INT UNSIGNED NOT NULL,
    source_registration_no VARCHAR(60) NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    payment_id INT UNSIGNED NOT NULL,
    outcome VARCHAR(20) NOT NULL DEFAULT 'IMPORTED',
    message TEXT NULL,
    row_data JSON NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fee_payment_import_source_row (import_id, source_row_number),
    KEY idx_fee_payment_import_rows_payment (payment_id),
    CONSTRAINT fk_fee_payment_import_rows_import FOREIGN KEY (import_id) REFERENCES fee_payment_imports(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fee_payment_import_rows_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_fee_payment_import_rows_payment FOREIGN KEY (payment_id) REFERENCES fees(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_historical_fee_actions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    payment_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NULL,
    reason VARCHAR(500) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_historical_payment (payment_id),
    KEY idx_student_historical_fee_student (student_id, created_at),
    CONSTRAINT fk_student_historical_fee_payment FOREIGN KEY (payment_id) REFERENCES fees(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_student_historical_fee_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_student_historical_fee_actor FOREIGN KEY (actor_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feeding_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    feeding_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_feeding_student_year_term (student_id, academic_year, term),
    CONSTRAINT fk_feeding_subscriptions_student
      FOREIGN KEY (student_id) REFERENCES students(id)
      ON DELETE RESTRICT
      ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS feeding_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    feeding_subscription_id INT UNSIGNED NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    reference_no VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_feeding_payments_subscription
      FOREIGN KEY (feeding_subscription_id) REFERENCES feeding_subscriptions(id)
      ON DELETE CASCADE
      ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_inventory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(30) NOT NULL DEFAULT 'kg',
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    item_date DATE NOT NULL,
    supplier VARCHAR(150) NULL,
    purchase_type VARCHAR(30) NOT NULL DEFAULT 'weekly',
    category VARCHAR(80) NOT NULL DEFAULT 'Kitchen',
    academic_year VARCHAR(4) NOT NULL DEFAULT '',
    term VARCHAR(20) NOT NULL DEFAULT '',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS school_expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 1.00,
    unit VARCHAR(30) NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    expense_date DATE NOT NULL,
    academic_year VARCHAR(4) NOT NULL DEFAULT '',
    term VARCHAR(20) NOT NULL DEFAULT '',
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Activities & Trips ledger for student collections and payment history.
CREATE TABLE IF NOT EXISTS activities (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    activity_type VARCHAR(30) NOT NULL,
    activity_date DATE NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    cost_per_student DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    notes TEXT NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_activities_period (academic_year, term, activity_date),
    KEY idx_activities_type_status (activity_type, status),
    CONSTRAINT fk_activities_created_by FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_activities_updated_by FOREIGN KEY (updated_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_classes (
    activity_id INT UNSIGNED NOT NULL,
    class_level VARCHAR(80) NOT NULL,
    PRIMARY KEY (activity_id, class_level),
    KEY idx_activity_classes_class (class_level),
    CONSTRAINT fk_activity_classes_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_participants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    expected_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    participation_status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_activity_participant (activity_id, student_id),
    KEY idx_activity_participant_student (student_id, activity_id),
    CONSTRAINT fk_activity_participants_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_activity_participants_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id INT UNSIGNED NOT NULL,
    participant_id BIGINT UNSIGNED NOT NULL,
    receipt_no VARCHAR(60) NOT NULL UNIQUE,
    request_key CHAR(64) NOT NULL UNIQUE,
    amount DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
    reference_no VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Recorded',
    recorded_by INT UNSIGNED NULL,
    reversed_by INT UNSIGNED NULL,
    reversed_at DATETIME NULL,
    reversal_reason VARCHAR(500) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_activity_payments_period_status (academic_year, term, status, payment_date),
    KEY idx_activity_payments_activity (activity_id, participant_id),
    CONSTRAINT fk_activity_payments_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_activity_payments_participant FOREIGN KEY (participant_id) REFERENCES activity_participants(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_activity_payments_recorded_by FOREIGN KEY (recorded_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_activity_payments_reversed_by FOREIGN KEY (reversed_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS activity_audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id INT UNSIGNED NOT NULL,
    actor_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    payment_id BIGINT UNSIGNED NULL,
    details LONGTEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_activity_audit_activity (activity_id, created_at),
    KEY idx_activity_audit_actor (actor_id, created_at),
    CONSTRAINT fk_activity_audit_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_activity_audit_actor FOREIGN KEY (actor_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_activity_audit_payment FOREIGN KEY (payment_id) REFERENCES activity_payments(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_daily_purchases (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(30) NULL,
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    category VARCHAR(80) NOT NULL DEFAULT 'Daily',
    supplier VARCHAR(150) NULL,
    notes TEXT NULL,
    purchase_date DATE NOT NULL,
    academic_year VARCHAR(4) NOT NULL DEFAULT '',
    term VARCHAR(20) NOT NULL DEFAULT '',
    payment_method VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS weekly_shopping (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    supplier VARCHAR(150) NULL,
    shopping_date DATE NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS weekly_shopping_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    weekly_shopping_id INT UNSIGNED NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(30) NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    supplier VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_weekly_shopping_items_weekly FOREIGN KEY (weekly_shopping_id) REFERENCES weekly_shopping(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transport_students (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NULL,
    student_name VARCHAR(150) NOT NULL,
    school_name VARCHAR(150) NULL,
    gender VARCHAR(10) NOT NULL,
    parent_name VARCHAR(150) NOT NULL,
    parent_phone VARCHAR(40) NULL,
    pickup_location VARCHAR(150) NOT NULL,
    is_outside TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_transport_existing_student (student_id),
    CONSTRAINT fk_transport_students_student
      FOREIGN KEY (student_id) REFERENCES students(id)
      ON DELETE RESTRICT
      ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transport_fee_structures (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    location_name VARCHAR(150) NOT NULL,
    fee_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    academic_year VARCHAR(4) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_transport_fee_location (location_name, academic_year)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transport_accounts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transport_student_id INT UNSIGNED NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    amount_due DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_transport_student_year_term (transport_student_id, academic_year, term),
    CONSTRAINT fk_transport_accounts_student
      FOREIGN KEY (transport_student_id) REFERENCES transport_students(id)
      ON DELETE CASCADE
      ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS transport_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    transport_account_id INT UNSIGNED NOT NULL,
    amount_paid DECIMAL(12,2) NOT NULL,
    payment_date DATE NOT NULL,
    reference_no VARCHAR(80) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_transport_payments_account
      FOREIGN KEY (transport_account_id) REFERENCES transport_accounts(id)
      ON DELETE CASCADE
      ON UPDATE CASCADE
) ENGINE=InnoDB;

-- School Uniform tables
CREATE TABLE IF NOT EXISTS uniforms (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uniform_name VARCHAR(150) NOT NULL,
    category VARCHAR(60) NOT NULL,
    gender VARCHAR(10) NOT NULL,
    size VARCHAR(30) NOT NULL,
    selling_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    opening_stock INT NOT NULL DEFAULT 0,
    reorder_level INT NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'Active',
    description TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

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

CREATE TABLE IF NOT EXISTS uniform_stock_movements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uniform_id INT UNSIGNED NOT NULL,
    uniform_variant_id INT UNSIGNED NULL,
    movement_type VARCHAR(30) NOT NULL,
    quantity INT NOT NULL,
    reference_id INT UNSIGNED NULL,
    note TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_uniform_stock_movements_variant (uniform_variant_id),
    CONSTRAINT fk_uniform_stock_movements_uniform FOREIGN KEY (uniform_id) REFERENCES uniforms(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_uniform_stock_movements_variant FOREIGN KEY (uniform_variant_id) REFERENCES uniform_variants(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS uniform_sales (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    student_id INT UNSIGNED NULL,
    receipt_no VARCHAR(80) NOT NULL UNIQUE,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    grand_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    balance DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method VARCHAR(30) NOT NULL DEFAULT 'Cash',
    mpesa_code VARCHAR(30) NOT NULL DEFAULT '',
    served_by INT UNSIGNED NULL,
    payment_date DATETIME NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_uniform_sales_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS uniform_sale_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    sale_id INT UNSIGNED NOT NULL,
    uniform_id INT UNSIGNED NOT NULL,
    uniform_variant_id INT UNSIGNED NULL,
    size VARCHAR(30) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    line_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_uniform_sale_items_variant (uniform_variant_id),
    CONSTRAINT fk_uniform_sale_items_sale FOREIGN KEY (sale_id) REFERENCES uniform_sales(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_uniform_sale_items_uniform FOREIGN KEY (uniform_id) REFERENCES uniforms(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_uniform_sale_items_variant FOREIGN KEY (uniform_variant_id) REFERENCES uniform_variants(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

-- School Van Fuel tables
CREATE TABLE IF NOT EXISTS vehicles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_name VARCHAR(100) NOT NULL,
    registration_no VARCHAR(60) NULL,
    driver_name VARCHAR(120) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS fuel_transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT UNSIGNED NOT NULL,
    fuel_date DATE NOT NULL,
    fuel_type VARCHAR(60) NOT NULL,
    litres DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    cost_per_litre DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    fuel_station VARCHAR(150) NULL,
    receipt_no VARCHAR(80) NULL,
    notes TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_fuel_transactions_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Permission and audit tables used by current administrator controls.
CREATE TABLE IF NOT EXISTS module_access (
    role_id INT UNSIGNED NOT NULL,
    module_name VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, module_name),
    CONSTRAINT fk_module_access_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_permission_overrides (
    user_id INT UNSIGNED NOT NULL,
    permission_id INT UNSIGNED NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 1,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, permission_id),
    CONSTRAINT fk_user_permission_overrides_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_user_permission_overrides_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_module_overrides (
    user_id INT UNSIGNED NOT NULL,
    module_name VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 1,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, module_name),
    CONSTRAINT fk_user_module_overrides_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(100) NOT NULL,
    resource VARCHAR(200) NULL,
    granted TINYINT(1) NOT NULL DEFAULT 0,
    level ENUM('INFO', 'WARN', 'DENY', 'ERROR') NOT NULL DEFAULT 'INFO',
    details JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_user_action (user_id, action),
    INDEX idx_created_at (created_at),
    INDEX idx_resource (resource),
    CONSTRAINT fk_audit_log_user FOREIGN KEY (user_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Parent portal accounts share the existing admin and student records.
CREATE TABLE IF NOT EXISTS parent_student_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_user_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    relationship_type VARCHAR(50) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    linked_by INT UNSIGNED NULL,
    linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    unlinked_by INT UNSIGNED NULL,
    unlinked_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_parent_student_link (parent_user_id, student_id),
    KEY idx_parent_student_active (parent_user_id, is_active),
    KEY idx_student_parent_active (student_id, is_active),
    CONSTRAINT fk_parent_student_link_parent FOREIGN KEY (parent_user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_parent_student_link_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_parent_student_link_linked_by FOREIGN KEY (linked_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_parent_student_link_unlinked_by FOREIGN KEY (unlinked_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS parent_link_audit (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_admin_id INT UNSIGNED NULL,
    parent_user_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NULL,
    action VARCHAR(40) NOT NULL,
    details VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_parent_audit_parent_time (parent_user_id, created_at),
    KEY idx_parent_audit_student_time (student_id, created_at),
    CONSTRAINT fk_parent_link_audit_actor FOREIGN KEY (actor_admin_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS parent_profiles (
    parent_user_id INT UNSIGNED NOT NULL,
    primary_phone VARCHAR(40) NOT NULL,
    normalized_phone VARCHAR(20) NOT NULL,
    alternate_phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    address VARCHAR(255) NULL,
    identity_verified_at DATETIME NULL,
    identity_verified_by INT UNSIGNED NULL,
    activated_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    updated_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (parent_user_id),
    KEY idx_parent_profiles_phone (normalized_phone),
    CONSTRAINT fk_parent_profiles_user FOREIGN KEY (parent_user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_parent_profiles_verified_by FOREIGN KEY (identity_verified_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_parent_profiles_created_by FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_parent_profiles_updated_by FOREIGN KEY (updated_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS parent_activation_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_user_id INT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_parent_activation_token_hash (token_hash),
    KEY idx_parent_activation_pending (parent_user_id, used_at, expires_at),
    CONSTRAINT fk_parent_activation_user FOREIGN KEY (parent_user_id) REFERENCES admin(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_parent_activation_actor FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS student_registration_tokens (
    token_hash CHAR(64) NOT NULL PRIMARY KEY,
    student_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_student_registration_token_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Kitchen inventory and stock workflows use these alongside purchase records.
CREATE TABLE IF NOT EXISTS kitchen_units (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(80) NOT NULL,
    label VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY code (code)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_name VARCHAR(120) NOT NULL,
    item_name_norm VARCHAR(120) GENERATED ALWAYS AS (LOWER(TRIM(item_name))) STORED,
    unit VARCHAR(30) NOT NULL DEFAULT 'kg',
    category VARCHAR(60) NOT NULL DEFAULT 'Kitchen',
    opening_stock DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    min_stock_level DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    PRIMARY KEY (id),
    UNIQUE KEY ux_kitchen_items_item_name_norm (item_name_norm)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_stock_movements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    kitchen_item_id INT UNSIGNED NOT NULL,
    movement_type VARCHAR(30) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(30) NULL,
    unit_price DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_cost DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    reference_id INT UNSIGNED NULL,
    note TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    transaction_date DATE NOT NULL,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    CONSTRAINT fk_kitchen_stock_movements_item FOREIGN KEY (kitchen_item_id) REFERENCES kitchen_items(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_purchase_records (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_token CHAR(64) NOT NULL,
    item_name VARCHAR(120) NOT NULL,
    category VARCHAR(40) NOT NULL,
    quantity DECIMAL(12,2) NOT NULL,
    unit VARCHAR(30) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    total_amount DECIMAL(12,2) NOT NULL,
    purchase_date DATE NOT NULL,
    supplier VARCHAR(150) NULL,
    description TEXT NULL,
    track_stock TINYINT(1) NOT NULL DEFAULT 1,
    academic_year VARCHAR(4) NOT NULL,
    term VARCHAR(20) NOT NULL,
    school_expense_id INT UNSIGNED NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY ux_kitchen_purchase_request (request_token),
    UNIQUE KEY ux_kitchen_purchase_expense (school_expense_id),
    KEY ix_kitchen_purchase_date (purchase_date, category)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    record_type VARCHAR(30) NOT NULL,
    record_id INT UNSIGNED NOT NULL,
    action VARCHAR(50) NOT NULL,
    before_value DECIMAL(12,2) NULL,
    after_value DECIMAL(12,2) NULL,
    details TEXT NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY ix_kitchen_audit_record (record_type, record_id, created_at),
    KEY ix_kitchen_audit_user (recorded_by, created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS kitchen_expense_categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_name VARCHAR(40) NOT NULL,
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_kitchen_expense_category_name (category_name),
    CONSTRAINT fk_kitchen_expense_category_creator FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bootstrap login is inserted only if its username is absent; imports never reset it.
-- Change the initial password immediately after first login.
INSERT IGNORE INTO admin (name, username, password_hash) VALUES
('System Administrator', 'admin', '$2y$10$TQYvwEbuJWmhxM72Etq28uyX6x2ZcfHpBjnqkPNhHw0bmOM9cYEnK');

-- Daycare module tables. Empty on install; no sample children or payments.
CREATE TABLE IF NOT EXISTS daycare_groups (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    age_range VARCHAR(80) NULL,
    capacity SMALLINT UNSIGNED NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_guardians (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(40) NOT NULL UNIQUE,
    alternate_phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    relationship VARCHAR(60) NULL,
    address VARCHAR(255) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_fee_plans (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL UNIQUE,
    billing_period ENUM('Daily','Weekly','Monthly','Half-day','Custom') NOT NULL DEFAULT 'Monthly',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    late_pickup_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    charge_absent_days TINYINT(1) NOT NULL DEFAULT 0,
    optional_services VARCHAR(500) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_admission_sequences (
    admission_year SMALLINT UNSIGNED PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_children (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admission_no VARCHAR(40) NOT NULL UNIQUE,
    full_name VARCHAR(150) NOT NULL,
    date_of_birth DATE NOT NULL,
    gender VARCHAR(20) NULL,
    admission_date DATE NOT NULL,
    group_id INT UNSIGNED NULL,
    primary_guardian_id INT UNSIGNED NULL,
    fee_plan_id INT UNSIGNED NULL,
    emergency_contact_name VARCHAR(150) NULL,
    emergency_contact_phone VARCHAR(40) NULL,
    address VARCHAR(255) NULL,
    allergies TEXT NULL,
    medical_conditions TEXT NULL,
    special_care_instructions TEXT NULL,
    photo_path VARCHAR(255) NULL,
    enrollment_status ENUM('Active','Inactive','Left') NOT NULL DEFAULT 'Active',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_daycare_children_name (full_name),
    KEY idx_daycare_children_status_group (enrollment_status,group_id),
    CONSTRAINT fk_daycare_child_group FOREIGN KEY (group_id) REFERENCES daycare_groups(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_child_guardian FOREIGN KEY (primary_guardian_id) REFERENCES daycare_guardians(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_child_plan FOREIGN KEY (fee_plan_id) REFERENCES daycare_fee_plans(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_child_creator FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_child_guardians (
    child_id INT UNSIGNED NOT NULL,
    guardian_id INT UNSIGNED NOT NULL,
    relationship VARCHAR(60) NULL,
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (child_id,guardian_id),
    CONSTRAINT fk_daycare_cg_child FOREIGN KEY (child_id) REFERENCES daycare_children(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_cg_guardian FOREIGN KEY (guardian_id) REFERENCES daycare_guardians(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_fee_charges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_id INT UNSIGNED NOT NULL,
    charge_date DATE NOT NULL,
    category VARCHAR(80) NOT NULL,
    billing_period VARCHAR(100) NULL,
    description VARCHAR(255) NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    discount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('Posted','Voided') NOT NULL DEFAULT 'Posted',
    created_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_daycare_charge_child_date (child_id,charge_date,status),
    CONSTRAINT fk_daycare_charge_child FOREIGN KEY (child_id) REFERENCES daycare_children(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_charge_creator FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK (amount >= 0 AND discount >= 0 AND discount <= amount)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_receipt_sequences (
    receipt_year SMALLINT UNSIGNED PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_payments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    child_id INT UNSIGNED NOT NULL,
    guardian_id INT UNSIGNED NULL,
    receipt_no VARCHAR(50) NOT NULL UNIQUE,
    payment_date DATETIME NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(40) NOT NULL,
    payment_reference VARCHAR(100) NULL,
    category VARCHAR(80) NOT NULL DEFAULT 'Daycare fees',
    billing_period VARCHAR(100) NULL,
    notes VARCHAR(500) NULL,
    payment_status ENUM('Recorded','Reversed') NOT NULL DEFAULT 'Recorded',
    reversed_at DATETIME NULL,
    reversed_by INT UNSIGNED NULL,
    reversal_reason VARCHAR(500) NULL,
    recorded_by INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_daycare_payment_date_status (payment_date,payment_status),
    KEY idx_daycare_payment_child (child_id,payment_status),
    KEY idx_daycare_payment_reference (payment_reference),
    CONSTRAINT fk_daycare_payment_child FOREIGN KEY (child_id) REFERENCES daycare_children(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_payment_guardian FOREIGN KEY (guardian_id) REFERENCES daycare_guardians(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_payment_recorder FOREIGN KEY (recorded_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_payment_reverser FOREIGN KEY (reversed_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_payment_allocations (
    payment_id BIGINT UNSIGNED NOT NULL,
    charge_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (payment_id,charge_id),
    CONSTRAINT fk_daycare_allocation_payment FOREIGN KEY (payment_id) REFERENCES daycare_payments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_allocation_charge FOREIGN KEY (charge_id) REFERENCES daycare_fee_charges(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_audit_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_daycare_audit_entity (entity_type,entity_id),
    KEY idx_daycare_audit_date (created_at),
    CONSTRAINT fk_daycare_audit_admin FOREIGN KEY (admin_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

-- One actual payment can be allocated to multiple student or daycare ledgers.
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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;
