-- Additive Activities & Trips module deployment. Select the existing application
-- database before importing. Safe to run again; no existing records are removed.
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
