-- Additive Daycare schema for the current child-registration and fee-collection module.
-- No child/payment rows are inserted. Retired attendance, pickup, care, expense, and
-- staff tables are intentionally not created on fresh installs. Existing tables are
-- never dropped by this migration, so their historical records remain untouched.

CREATE TABLE IF NOT EXISTS daycare_groups (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL,
    age_range VARCHAR(80) NULL,
    capacity SMALLINT UNSIGNED NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_daycare_groups_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_guardians (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(150) NOT NULL,
    phone VARCHAR(40) NOT NULL,
    alternate_phone VARCHAR(40) NULL,
    email VARCHAR(190) NULL,
    relationship VARCHAR(60) NULL,
    address VARCHAR(255) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_daycare_guardian_phone (phone)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_fee_plans (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    billing_period ENUM('Daily','Weekly','Monthly','Half-day','Custom') NOT NULL DEFAULT 'Monthly',
    amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    late_pickup_fee DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    charge_absent_days TINYINT(1) NOT NULL DEFAULT 0,
    optional_services VARCHAR(500) NULL,
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_daycare_fee_plan_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_admission_sequences (
    admission_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_children (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admission_no VARCHAR(40) NOT NULL,
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
    UNIQUE KEY uq_daycare_children_admission_no (admission_no),
    KEY idx_daycare_children_name (full_name),
    KEY idx_daycare_children_status_group (enrollment_status, group_id),
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
    PRIMARY KEY (child_id, guardian_id),
    CONSTRAINT fk_daycare_cg_child FOREIGN KEY (child_id) REFERENCES daycare_children(id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_cg_guardian FOREIGN KEY (guardian_id) REFERENCES daycare_guardians(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_fee_charges (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
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
    KEY idx_daycare_charge_child_date (child_id, charge_date, status),
    CONSTRAINT fk_daycare_charge_child FOREIGN KEY (child_id) REFERENCES daycare_children(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_charge_creator FOREIGN KEY (created_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE,
    CHECK (amount >= 0 AND discount >= 0 AND discount <= amount)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_receipt_sequences (
    receipt_year SMALLINT UNSIGNED NOT NULL PRIMARY KEY,
    last_number INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    child_id INT UNSIGNED NOT NULL,
    guardian_id INT UNSIGNED NULL,
    receipt_no VARCHAR(50) NOT NULL,
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
    UNIQUE KEY uq_daycare_payment_receipt (receipt_no),
    UNIQUE KEY uq_daycare_payment_reference (payment_reference),
    KEY idx_daycare_payment_date_status (payment_date, payment_status),
    KEY idx_daycare_payment_child (child_id, payment_status),
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
    PRIMARY KEY (payment_id, charge_id),
    CONSTRAINT fk_daycare_allocation_payment FOREIGN KEY (payment_id) REFERENCES daycare_payments(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_daycare_allocation_charge FOREIGN KEY (charge_id) REFERENCES daycare_fee_charges(id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CHECK (amount > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS daycare_audit_log (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(60) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    details JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_daycare_audit_entity (entity_type, entity_id),
    KEY idx_daycare_audit_date (created_at),
    CONSTRAINT fk_daycare_audit_admin FOREIGN KEY (admin_id) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

