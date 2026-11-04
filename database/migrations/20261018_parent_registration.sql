-- Adds guardian identity/contact profiles and secure activation support.
-- Existing accounts, students, fee balances, and payment history are preserved.
-- Apply after 20261017_parent_portal.sql, which creates parent_student_links.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS student_registration_tokens (
    token_hash CHAR(64) NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (token_hash),
    CONSTRAINT fk_student_registration_token_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @has_relationship_type = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'parent_student_links' AND column_name = 'relationship_type'
);
SET @parent_link_sql = IF(
    @has_relationship_type = 0,
    'ALTER TABLE parent_student_links ADD COLUMN relationship_type VARCHAR(50) NULL AFTER student_id',
    'SELECT 1'
);
PREPARE parent_link_statement FROM @parent_link_sql;
EXECUTE parent_link_statement;
DEALLOCATE PREPARE parent_link_statement;

SET @has_is_primary = (
    SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'parent_student_links' AND column_name = 'is_primary'
);
SET @parent_link_sql = IF(
    @has_is_primary = 0,
    'ALTER TABLE parent_student_links ADD COLUMN is_primary TINYINT(1) NOT NULL DEFAULT 0 AFTER relationship_type',
    'SELECT 1'
);
PREPARE parent_link_statement FROM @parent_link_sql;
EXECUTE parent_link_statement;
DEALLOCATE PREPARE parent_link_statement;
