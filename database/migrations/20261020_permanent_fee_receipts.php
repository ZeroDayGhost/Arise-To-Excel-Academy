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
$lock->execute(['lock_name' => 'arise_fee_receipt_migration']);
if ((int) $lock->fetchColumn() !== 1) {
    fwrite(STDERR, "Another fee receipt migration is running. Try again later.\n");
    exit(1);
}

function fee_receipt_migration_column_exists(PDO $pdo, string $column): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'fees\' AND COLUMN_NAME = :column_name'
    );
    $statement->execute(['column_name' => $column]);
    return (int) $statement->fetchColumn() > 0;
}

function fee_receipt_migration_has_unique_receipt_index(PDO $pdo): bool
{
    $statement = $pdo->query(
        "SELECT COUNT(*) FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'fees' AND COLUMN_NAME = 'receipt_no' AND NON_UNIQUE = 0"
    );
    return (int) $statement->fetchColumn() > 0;
}

try {
    $duplicate = $pdo->query(
        "SELECT receipt_no, COUNT(*) AS copies FROM fees GROUP BY receipt_no HAVING COUNT(*) > 1 LIMIT 1"
    )->fetch();
    $blank = (int) $pdo->query("SELECT COUNT(*) FROM fees WHERE receipt_no IS NULL OR TRIM(receipt_no) = ''")->fetchColumn();
    if ($duplicate || $blank > 0) {
        throw new RuntimeException('Existing receipt values include duplicates or blanks. Resolve them explicitly before migration; no receipt values were changed.');
    }

    $count = (int) $pdo->query('SELECT COUNT(*) FROM fees')->fetchColumn();
    $receipts = $pdo->query('SELECT id, receipt_no FROM fees ORDER BY id')->fetchAll();
    $atxrMax = [];
    foreach ($receipts as $row) {
        if (preg_match('/^ATXR-(\d{4})-(\d+)$/', (string) $row['receipt_no'], $matches)) {
            $atxrMax[$matches[1]] = max($atxrMax[$matches[1]] ?? 0, (int) $matches[2]);
        }
    }

    echo "Fee payments found: {$count}\n";
    echo "Existing receipt numbers will be preserved exactly. New receipts will use ATXR-YYYY-NNNNN.\n";
    echo 'Existing annual ATXR sequences to preserve: ' . ($atxrMax ? json_encode($atxrMax) : 'none') . "\n";
    echo 'Payment status column: ' . (fee_receipt_migration_column_exists($pdo, 'payment_status') ? 'already present' : 'will be added') . "\n";
    if (!$apply) {
        echo "Preflight only; database unchanged. Re-run with --apply after taking a database backup.\n";
        exit(0);
    }

    $newColumns = [
        'payment_status' => "ALTER TABLE fees ADD COLUMN payment_status VARCHAR(20) NOT NULL DEFAULT 'Recorded' AFTER payment_date",
        'payment_method' => "ALTER TABLE fees ADD COLUMN payment_method VARCHAR(30) NOT NULL DEFAULT 'M-PESA' AFTER mpesa_reference_text",
        'reversed_at' => 'ALTER TABLE fees ADD COLUMN reversed_at DATETIME NULL AFTER payment_status',
        'reversed_by' => 'ALTER TABLE fees ADD COLUMN reversed_by INT UNSIGNED NULL AFTER reversed_at',
        'reversal_reason' => 'ALTER TABLE fees ADD COLUMN reversal_reason VARCHAR(500) NULL AFTER reversed_by',
    ];
    foreach ($newColumns as $column => $sql) {
        if (!fee_receipt_migration_column_exists($pdo, $column)) {
            $pdo->exec($sql);
        }
    }

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_receipt_sequences (
            issue_year CHAR(4) NOT NULL PRIMARY KEY,
            next_number INT UNSIGNED NOT NULL,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB"
    );
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS fee_payment_audit (
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
        ) ENGINE=InnoDB"
    );
    if (!fee_receipt_migration_has_unique_receipt_index($pdo)) {
        $pdo->exec('ALTER TABLE fees ADD UNIQUE KEY uq_fees_receipt_number (receipt_no)');
    }

    $upsertSequence = $pdo->prepare(
        'INSERT INTO fee_receipt_sequences (issue_year, next_number) VALUES (:year, :next_number)
         ON DUPLICATE KEY UPDATE next_number = GREATEST(next_number, VALUES(next_number))'
    );
    foreach ($atxrMax as $year => $lastNumber) {
        $upsertSequence->execute(['year' => $year, 'next_number' => $lastNumber + 1]);
    }
    $pdo->prepare('INSERT IGNORE INTO fee_receipt_sequences (issue_year, next_number) VALUES (:year, 1)')
        ->execute(['year' => (new DateTimeImmutable('now', new DateTimeZone('Africa/Nairobi')))->format('Y')]);

    $auditExists = $pdo->prepare("SELECT 1 FROM fee_payment_audit WHERE payment_id = :payment_id AND event_type = 'LEGACY_IMPORTED' LIMIT 1");
    $auditInsert = $pdo->prepare(
        "INSERT INTO fee_payment_audit (payment_id, student_id, receipt_no, event_type, details)
         SELECT f.id, f.student_id, f.receipt_no, 'LEGACY_IMPORTED', :details FROM fees f WHERE f.id = :payment_id"
    );
    foreach ($pdo->query('SELECT id, student_id, receipt_no, amount_paid, mpesa_code, mpesa_reference_text, payment_method, term, year, payment_date, created_at FROM fees ORDER BY id')->fetchAll() as $row) {
        $auditExists->execute(['payment_id' => $row['id']]);
        if (!$auditExists->fetchColumn()) {
            $details = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $auditInsert->execute(['details' => $details, 'payment_id' => $row['id']]);
        }
    }

    echo "Migration applied. Existing receipts were preserved; new receipt sequences and legacy audit records are ready.\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration stopped: ' . $exception->getMessage() . "\n");
    exit(1);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('arise_fee_receipt_migration')");
}
