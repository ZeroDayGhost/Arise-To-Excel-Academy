<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$host = getenv('ARISE_DB_HOST') ?: 'localhost';
$name = getenv('ARISE_DB_NAME') ?: 'arise_to_excel';
$user = getenv('ARISE_DB_USER') ?: 'root';
$pass = getenv('ARISE_DB_PASS') ?: '';
$port = getenv('ARISE_DB_PORT') ?: '3306';
$pdo = new PDO("mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$migration = file_get_contents(__DIR__ . '/20261103_multi_student_payments.sql');
if ($migration === false) throw new RuntimeException('Could not read the multi-student payment migration.');
$migration = preg_replace('/^\s*--.*$/m', '', $migration) ?? $migration;
foreach (array_filter(array_map('trim', explode(';', $migration))) as $statement) {
    $pdo->exec($statement);
}

function backfill_payment_rows(PDO $pdo, string $module): array
{
    $queries = [
        'fees' => "SELECT f.id AS source_id,f.student_id,NULL AS daycare_child_id,f.amount_paid AS amount,f.payment_date,
            f.payment_method,f.mpesa_code AS reference,f.year AS academic_year,f.term,'School fees' AS payment_category,
            f.payment_status AS source_status,NULL AS guardian_id,NULL AS activity_name,NULL AS activity_type,NULL AS actor_id
            FROM fees f LEFT JOIN payment_allocations x ON x.source_module='fees' AND x.source_payment_id=f.id WHERE x.id IS NULL",
        'activity' => "SELECT p.id AS source_id,s.id AS student_id,NULL AS daycare_child_id,p.amount,p.payment_date,p.payment_method,p.reference_no AS reference,
            p.academic_year,p.term,CONCAT(a.activity_type,': ',a.name) AS payment_category,p.status AS source_status,NULL AS guardian_id,a.name AS activity_name,a.activity_type,p.recorded_by AS actor_id
            FROM activity_payments p JOIN activity_participants ap ON ap.id=p.participant_id JOIN activities a ON a.id=p.activity_id JOIN students s ON s.id=ap.student_id
            LEFT JOIN payment_allocations x ON x.source_module='activity' AND x.source_payment_id=p.id WHERE x.id IS NULL",
        'daycare' => "SELECT p.id AS source_id,NULL AS student_id,p.child_id AS daycare_child_id,p.amount,DATE(p.payment_date) AS payment_date,p.payment_method,p.payment_reference AS reference,
            YEAR(p.payment_date) AS academic_year,'' AS term,p.category AS payment_category,p.payment_status AS source_status,p.guardian_id,NULL AS activity_name,NULL AS activity_type,p.recorded_by AS actor_id
            FROM daycare_payments p LEFT JOIN payment_allocations x ON x.source_module='daycare' AND x.source_payment_id=p.id WHERE x.id IS NULL",
    ];
    $stmt = $pdo->query($queries[$module]);
    return $stmt->fetchAll();
}

function backfill_parent_for_student(PDO $pdo, ?int $studentId): ?int
{
    if (!$studentId) return null;
    static $hasParentLinks = null;
    if ($hasParentLinks === null) {
        $table = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='parent_student_links'");
        $table->execute();
        $hasParentLinks = (bool) $table->fetchColumn();
    }
    if (!$hasParentLinks) return null;
    $stmt = $pdo->prepare('SELECT parent_user_id FROM parent_student_links WHERE student_id=:student AND is_active=1 ORDER BY is_primary DESC,id LIMIT 1');
    $stmt->execute(['student' => $studentId]);
    return (int) ($stmt->fetchColumn() ?: 0) ?: null;
}

function backfill_daycare_period(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare('SELECT academic_year,term_name FROM academic_calendar WHERE start_date<=:date_from AND end_date>=:date_to ORDER BY academic_year DESC LIMIT 1');
    $stmt->execute(['date_from' => $date, 'date_to' => $date]);
    $period = $stmt->fetch();
    return $period ? [(string) $period['academic_year'], (string) $period['term_name']] : [substr($date, 0, 4), ''];
}

$groups = [];
foreach (['fees', 'activity', 'daycare'] as $module) {
    foreach (backfill_payment_rows($pdo, $module) as $row) {
        $method = strtoupper(trim((string) $row['payment_method']));
        $reference = strtoupper(preg_replace('/\s+/', '', (string) ($row['reference'] ?? '')) ?? '');
        $isMpesa = $method === 'M-PESA' && $reference !== '';
        $key = $isMpesa ? 'mpesa:' . $reference : 'legacy:' . $module . ':' . $row['source_id'];
        $row['source_module'] = $module;
        $row['reference'] = $isMpesa ? $reference : null;
        $groups[$key]['is_mpesa'] = $isMpesa;
        $groups[$key]['rows'][] = $row;
    }
}

$migrated = 0;
foreach ($groups as $group) {
    $rows = $group['rows'];
    $total = round(array_sum(array_map(static fn($row) => (float) $row['amount'], $rows)), 2);
    if ($total <= 0) continue;
    usort($rows, static fn($a, $b) => strcmp((string) $a['payment_date'], (string) $b['payment_date']));
    $first = $rows[0];
    $date = substr((string) $first['payment_date'], 0, 10);
    $method = (string) $first['payment_method'];
    $reference = $group['is_mpesa'] ? (string) $first['reference'] : null;
    $parentId = null;
    $daycareGuardianId = null;
    foreach ($rows as $row) {
        if ($row['student_id']) $parentId ??= backfill_parent_for_student($pdo, (int) $row['student_id']);
        if ($row['guardian_id']) $daycareGuardianId ??= (int) $row['guardian_id'];
    }
    $payerName = null;
    if ($parentId) {
        $payer = $pdo->prepare('SELECT name FROM admin WHERE id=:id');
        $payer->execute(['id' => $parentId]);
        $payerName = $payer->fetchColumn() ?: null;
    } elseif ($daycareGuardianId) {
        $payer = $pdo->prepare('SELECT full_name FROM daycare_guardians WHERE id=:id');
        $payer->execute(['id' => $daycareGuardianId]);
        $payerName = $payer->fetchColumn() ?: null;
    }

    $pdo->beginTransaction();
    try {
        $transactionId = 0;
        if ($reference !== null) {
            $find = $pdo->prepare('SELECT id FROM payment_transactions WHERE mpesa_code=:code FOR UPDATE');
            $find->execute(['code' => $reference]);
            $transactionId = (int) ($find->fetchColumn() ?: 0);
        }
        if ($transactionId > 0) {
            $pdo->prepare('UPDATE payment_transactions SET total_amount=total_amount+:amount WHERE id=:id')->execute(['amount' => $total, 'id' => $transactionId]);
        } else {
            $placeholder = 'PENDING-' . strtoupper(bin2hex(random_bytes(12)));
            $requestKey = bin2hex(random_bytes(32));
            $recordedBy = (int) ($first['actor_id'] ?? 0) ?: null;
            $insert = $pdo->prepare("INSERT INTO payment_transactions
                (receipt_no,parent_user_id,daycare_guardian_id,payer_name,payment_method,mpesa_code,total_amount,payment_date,status,request_key,recorded_by)
                VALUES (:receipt,:parent,:guardian,:payer,:method,:code,:amount,:date,:status,:request,:actor)");
            $recordedStatuses = array_filter($rows, static fn($row) => ($row['source_status'] ?? '') === 'Recorded');
            $refundedStatuses = array_filter($rows, static fn($row) => ($row['source_status'] ?? '') === 'Refunded');
            $status = count($recordedStatuses) === count($rows) ? 'Recorded'
                : (count($recordedStatuses) > 0 ? 'Partially Reversed' : (count($refundedStatuses) > 0 ? 'Refunded' : 'Reversed'));
            $insert->execute(['receipt' => $placeholder, 'parent' => $parentId, 'guardian' => $daycareGuardianId, 'payer' => $payerName,
                'method' => $method, 'code' => $reference, 'amount' => $total, 'date' => $date, 'status' => $status,
                'request' => $requestKey, 'actor' => $recordedBy]);
            $transactionId = (int) $pdo->lastInsertId();
            $receipt = sprintf('ATXTX-%04d-%06d', (int) substr($date, 0, 4), $transactionId);
            $pdo->prepare('UPDATE payment_transactions SET receipt_no=:receipt WHERE id=:id')->execute(['receipt' => $receipt, 'id' => $transactionId]);
        }

        $allocation = $pdo->prepare("INSERT IGNORE INTO payment_allocations
            (transaction_id,source_module,source_payment_id,student_id,daycare_child_id,payment_category,amount,academic_year,term,status)
            VALUES (:transaction,:module,:source,:student,:child,:category,:amount,:year,:term,:status)");
        foreach ($rows as $row) {
            $allocationDate = substr((string) $row['payment_date'], 0, 10);
            [$year, $term] = $row['source_module'] === 'daycare'
                ? backfill_daycare_period($pdo, $allocationDate)
                : [(string) $row['academic_year'], (string) $row['term']];
            $allocation->execute(['transaction' => $transactionId, 'module' => $row['source_module'], 'source' => (int) $row['source_id'],
                'student' => (int) ($row['student_id'] ?? 0) ?: null, 'child' => (int) ($row['daycare_child_id'] ?? 0) ?: null,
                'category' => trim((string) $row['payment_category']) ?: ucfirst($row['source_module']), 'amount' => (float) $row['amount'],
                'year' => $year, 'term' => $term, 'status' => (string) $row['source_status']]);
            $migrated += $allocation->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $exception;
    }
}

fwrite(STDOUT, "Backfilled {$migrated} existing payment allocations. Existing module payment rows were preserved.\n");
