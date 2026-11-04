-- Additive promotion snapshots and audited undo metadata.
-- Existing student, class, payment, receipt, and report rows are never deleted.

SET @promotion_db := DATABASE();

SET @has_admitted_year := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @promotion_db AND TABLE_NAME = 'students' AND COLUMN_NAME = 'admitted_academic_year'
);
SET @sql := IF(@has_admitted_year = 0,
    'ALTER TABLE students ADD COLUMN admitted_academic_year VARCHAR(4) NULL AFTER class_level',
    'SELECT 1');
PREPARE promotion_stmt FROM @sql;
EXECUTE promotion_stmt;
DEALLOCATE PREPARE promotion_stmt;

SET @has_undone_at := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @promotion_db AND TABLE_NAME = 'student_class_promotions' AND COLUMN_NAME = 'undone_at'
);
SET @sql := IF(@has_undone_at = 0,
    'ALTER TABLE student_class_promotions ADD COLUMN undone_at TIMESTAMP NULL DEFAULT NULL',
    'SELECT 1');
PREPARE promotion_stmt FROM @sql;
EXECUTE promotion_stmt;
DEALLOCATE PREPARE promotion_stmt;

SET @has_undone_by := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @promotion_db AND TABLE_NAME = 'student_class_promotions' AND COLUMN_NAME = 'undone_by'
);
SET @sql := IF(@has_undone_by = 0,
    'ALTER TABLE student_class_promotions ADD COLUMN undone_by INT UNSIGNED NULL',
    'SELECT 1');
PREPARE promotion_stmt FROM @sql;
EXECUTE promotion_stmt;
DEALLOCATE PREPARE promotion_stmt;

SET @has_undo_reason := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = @promotion_db AND TABLE_NAME = 'student_class_promotions' AND COLUMN_NAME = 'undo_reason'
);
SET @sql := IF(@has_undo_reason = 0,
    'ALTER TABLE student_class_promotions ADD COLUMN undo_reason TEXT NULL',
    'SELECT 1');
PREPARE promotion_stmt FROM @sql;
EXECUTE promotion_stmt;
DEALLOCATE PREPARE promotion_stmt;

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

-- Rebuild missing source-year assignments from the immutable promotion snapshots.
-- Existing assignments are preserved; this is safe to run more than once.
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

