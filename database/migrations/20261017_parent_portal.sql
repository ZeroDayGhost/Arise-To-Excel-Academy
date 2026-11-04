-- Non-destructive parent portal migration. Existing students and financial rows are unchanged.
CREATE TABLE IF NOT EXISTS parent_student_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    parent_user_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS module_access (
    role_id INT UNSIGNED NOT NULL,
    module_name VARCHAR(80) NOT NULL,
    allowed TINYINT(1) NOT NULL DEFAULT 0,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, module_name),
    CONSTRAINT fk_module_access_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Parent is a portal-only role. Remove legacy admin module/action grants, but keep role assignments and user records.
DELETE ma
FROM module_access ma
JOIN roles r ON r.id = ma.role_id
WHERE r.name = 'Parent';

DELETE rp
FROM role_permissions rp
JOIN roles r ON r.id = rp.role_id
WHERE r.name = 'Parent';
