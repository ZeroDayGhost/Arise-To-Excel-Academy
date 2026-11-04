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
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_student_class_year_transition (from_year, to_year),
    CONSTRAINT fk_student_promotion_actor FOREIGN KEY (promoted_by) REFERENCES admin(id) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB;

