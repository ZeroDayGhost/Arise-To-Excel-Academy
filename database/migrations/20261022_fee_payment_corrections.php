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
$lock->execute(['lock_name' => 'arise_fee_payment_correction_migration']);
if ((int) $lock->fetchColumn() !== 1) {
    fwrite(STDERR, "Another payment-correction migration is running. Try again later.\n");
    exit(1);
}

try {
    $roleColumns = array_column($pdo->query('SHOW COLUMNS FROM roles')->fetchAll(), 'Field');
    $roleExpr = in_array('role_name', $roleColumns, true) && in_array('name', $roleColumns, true)
        ? "COALESCE(NULLIF(role_name, ''), name)"
        : (in_array('role_name', $roleColumns, true) ? 'role_name' : 'name');
    $secretary = $pdo->query("SELECT id FROM roles WHERE LOWER(TRIM({$roleExpr})) = 'secretary' LIMIT 1")->fetchColumn();

    if (!$apply) {
        $existingRequests = (int) $pdo->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fee_payment_correction_requests'"
        )->fetchColumn();
        echo 'Preflight: correction request table ' . ($existingRequests ? 'already exists' : 'will be created') . ".\n";
        echo 'Secretary role ' . ($secretary ? 'was found and will receive Fees history access only' : 'was not found; no role will be created') . ".\n";
        echo "No database changes were made. Back up the database, then rerun with --apply.\n";
        exit(0);
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_payment_correction_requests (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $pdo->beginTransaction();
    $pdo->exec(
        "INSERT INTO permissions (permission_key, label, module, action)
         VALUES ('fees.access', 'Fees', 'fees', 'access'), ('fees.view', 'View Payments', 'fees', 'view')
         ON DUPLICATE KEY UPDATE label = VALUES(label), module = VALUES(module), action = VALUES(action)"
    );
    if ($secretary) {
        $grant = $pdo->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id)
             SELECT :role_id, id FROM permissions WHERE permission_key IN (\'fees.access\', \'fees.view\')'
        );
        $grant->execute(['role_id' => (int) $secretary]);
        $module = $pdo->prepare(
            "INSERT INTO module_access (role_id, module_name, allowed) VALUES (:role_id, 'Fees', 1)
             ON DUPLICATE KEY UPDATE allowed = 1"
        );
        $module->execute(['role_id' => (int) $secretary]);
    }
    $pdo->commit();

    echo "Correction request table is ready. Only Secretary and Director role checks can use the workflow; Secretary received Fees history access only.\n";
    echo "Existing fee payments, balances, and receipts were not changed.\n";
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Migration stopped: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
    $release->execute(['lock_name' => 'arise_fee_payment_correction_migration']);
}
