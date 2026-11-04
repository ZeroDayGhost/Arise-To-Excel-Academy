<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$apply = in_array('--apply', $argv, true);
$pdo = new PDO(
    'mysql:host=' . (getenv('ARISE_DB_HOST') ?: 'localhost') . ';dbname=' . (getenv('ARISE_DB_NAME') ?: 'arise_to_excel') . ';charset=utf8mb4',
    getenv('ARISE_DB_USER') ?: 'root',
    getenv('ARISE_DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 30)');
$lock->execute(['lock_name' => 'arise_fee_payment_import_migration']);
if ((int) $lock->fetchColumn() !== 1) {
    fwrite(STDERR, "Another fee payment import migration is running. Try again later.\n");
    exit(1);
}

try {
    if (!$apply) {
        echo "Preflight: import audit tables are ready to be created and fees.import will be granted only to Director and Accountant roles.\n";
        echo "No database changes were made. Re-run with --apply after taking a database backup.\n";
        exit(0);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_payment_imports (
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
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_payment_import_rows (
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
        ) ENGINE=InnoDB"
    );

    $permission = $pdo->prepare(
        "INSERT INTO permissions (permission_key, label, module, action, module_name, permission_name)
         VALUES ('fees.import', 'Import Historical Payments', 'fees', 'fees.import', 'Fees', 'Import Historical Payments')
         ON DUPLICATE KEY UPDATE label = VALUES(label), module = VALUES(module), action = VALUES(action), module_name = VALUES(module_name), permission_name = VALUES(permission_name)"
    );

    $pdo->beginTransaction();
    $permission->execute();
    $hasRoleName = false;
    $roleColumns = $pdo->query("SHOW COLUMNS FROM roles")->fetchAll(PDO::FETCH_COLUMN);
    $hasRoleName = in_array('role_name', $roleColumns, true);
    $hasLegacyName = in_array('name', $roleColumns, true);
    if (!$hasRoleName && !$hasLegacyName) {
        throw new RuntimeException('The roles table has no supported role-name column.');
    }
    $roleExpression = $hasRoleName && $hasLegacyName
        ? 'COALESCE(NULLIF(r.role_name, \'\'), r.name)'
        : ($hasRoleName ? 'r.role_name' : 'r.name');
    $grant = $pdo->prepare(
        'INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
         WHERE LOWER(' . $roleExpression . ') = LOWER(:role_name) AND p.permission_key = \'fees.import\''
    );
    foreach (['Director', 'Accountant'] as $roleName) {
        $grant->execute(['role_name' => $roleName]);
    }
    $pdo->commit();

    echo "Fee payment import audit tables created.\n";
    echo "The import permission was granted to Director and Accountant roles only. Existing payment rows were not changed.\n";
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration stopped: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $release->execute(['lock_name' => 'arise_fee_payment_import_migration']);
}
