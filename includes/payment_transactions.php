<?php

function payment_transaction_payer_options(PDO $pdo): array
{
    $parentAccounts = $pdo->query("SELECT DISTINCT 'parent' AS payer_source,a.id AS payer_source_id,a.name AS payer_name,pp.primary_phone AS payer_phone
        FROM admin a
        LEFT JOIN parent_profiles pp ON pp.parent_user_id=a.id
        WHERE EXISTS (
            SELECT 1 FROM user_roles ur JOIN roles r ON r.id=ur.role_id
            WHERE ur.user_id=a.id AND COALESCE(r.role_name,r.name)='Parent'
        )")->fetchAll(PDO::FETCH_ASSOC);
    $daycareGuardians = $pdo->query("SELECT 'guardian' AS payer_source,g.id AS payer_source_id,g.full_name AS payer_name,g.phone AS payer_phone
        FROM daycare_guardians g")->fetchAll(PDO::FETCH_ASSOC);
    $studentContacts = $pdo->query("SELECT 'student_contact' AS payer_source,MIN(s.id) AS payer_source_id,
            MIN(TRIM(s.parent_name)) AS payer_name,MIN(NULLIF(TRIM(s.guardian_phone),'')) AS payer_phone
        FROM students s
        WHERE s.parent_name IS NOT NULL AND TRIM(s.parent_name)<>''
        GROUP BY LOWER(TRIM(s.parent_name)),COALESCE(NULLIF(TRIM(s.guardian_phone),''),'')")->fetchAll(PDO::FETCH_ASSOC);
    $transportContacts = $pdo->query("SELECT 'transport_contact' AS payer_source,MIN(ts.id) AS payer_source_id,
            MIN(TRIM(ts.parent_name)) AS payer_name,MIN(NULLIF(TRIM(ts.parent_phone),'')) AS payer_phone
        FROM transport_students ts
        WHERE ts.parent_name IS NOT NULL AND TRIM(ts.parent_name)<>''
        GROUP BY LOWER(TRIM(ts.parent_name)),COALESCE(NULLIF(TRIM(ts.parent_phone),''),'')")->fetchAll(PDO::FETCH_ASSOC);

    $options = [];
    $indexesByName = [];
    foreach (array_merge($parentAccounts, $daycareGuardians, $studentContacts, $transportContacts) as $row) {
        $name = trim((string) ($row['payer_name'] ?? ''));
        if ($name === '') {
            continue;
        }
        $phone = trim((string) ($row['payer_phone'] ?? ''));
        $phoneDigits = preg_replace('/\D+/', '', $phone) ?? '';
        $normalizedName = preg_replace('/\s+/u', ' ', $name) ?? $name;
        $nameKey = function_exists('mb_strtolower') ? mb_strtolower($normalizedName, 'UTF-8') : strtolower($normalizedName);

        $duplicateIndex = null;
        foreach ($indexesByName[$nameKey] ?? [] as $existingIndex) {
            $existingPhone = (string) $options[$existingIndex]['payer_phone_digits'];
            if ($existingPhone === '' || $phoneDigits === '' || $existingPhone === $phoneDigits) {
                $duplicateIndex = $existingIndex;
                break;
            }
        }
        if ($duplicateIndex !== null) {
            if ($options[$duplicateIndex]['payer_phone_digits'] === '' && $phoneDigits !== '') {
                $options[$duplicateIndex]['payer_phone_digits'] = $phoneDigits;
                $options[$duplicateIndex]['payer_label'] = $name . ' · ' . $phone;
            }
            continue;
        }

        $options[] = [
            'payer_key' => (string) $row['payer_source'] . ':' . (int) $row['payer_source_id'],
            'payer_name' => $name,
            'payer_label' => $phone !== '' ? $name . ' · ' . $phone : $name,
            'payer_phone_digits' => $phoneDigits,
        ];
        $indexesByName[$nameKey][] = array_key_last($options);
    }

    foreach ($options as &$option) {
        unset($option['payer_phone_digits']);
    }
    unset($option);
    usort($options, static fn(array $left, array $right): int => strcasecmp($left['payer_label'], $right['payer_label']));
    return $options;
}

function payment_transaction_insert(PDO $pdo, array $data): int
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Payment transactions must be saved inside the payment database transaction.');
    }
    $amount = round((float) ($data['total_amount'] ?? 0), 2);
    $method = trim((string) ($data['payment_method'] ?? ''));
    $date = trim((string) ($data['payment_date'] ?? ''));
    $code = normalize_payment_code((string) ($data['mpesa_code'] ?? ''));
    if ($amount <= 0 || !in_array($method, ['M-PESA', 'Cash', 'Bank Transfer', 'Cheque', 'Other'], true)) {
        throw new InvalidArgumentException('Enter a valid payment amount and method.');
    }
    if ($method === 'M-PESA' && $code === '') {
        throw new InvalidArgumentException('Enter the M-PESA transaction code.');
    }
    if ($method !== 'M-PESA') {
        $code = '';
    }
    $requestKey = (string) ($data['request_key'] ?? bin2hex(random_bytes(32)));
    if (!preg_match('/^[a-f0-9]{64}$/', $requestKey)) {
        throw new InvalidArgumentException('Refresh the payment form and try again.');
    }

    $placeholderReceipt = 'PENDING-' . strtoupper(bin2hex(random_bytes(12)));
    $insert = $pdo->prepare("INSERT INTO payment_transactions
        (receipt_no, parent_user_id, daycare_guardian_id, payer_name, payment_method, mpesa_code, total_amount, payment_date, status, request_key, recorded_by)
        VALUES (:receipt, :parent, :guardian, :payer_name, :method, :code, :amount, :date, 'Recorded', :request_key, :actor)");
    $insert->execute([
        'receipt' => $placeholderReceipt,
        'parent' => (int) ($data['parent_user_id'] ?? 0) ?: null,
        'guardian' => (int) ($data['daycare_guardian_id'] ?? 0) ?: null,
        'payer_name' => trim((string) ($data['payer_name'] ?? '')) ?: null,
        'method' => $method,
        'code' => $code !== '' ? $code : null,
        'amount' => $amount,
        'date' => $date,
        'request_key' => $requestKey,
        'actor' => (int) ($data['recorded_by'] ?? 0) ?: null,
    ]);
    $id = (int) $pdo->lastInsertId();
    $receipt = sprintf('ATXTX-%04d-%06d', (int) date('Y', strtotime($date)), $id);
    $pdo->prepare('UPDATE payment_transactions SET receipt_no = :receipt WHERE id = :id')
        ->execute(['receipt' => $receipt, 'id' => $id]);
    return $id;
}

function payment_transaction_attach_allocation(PDO $pdo, array $allocation): void
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Payment allocations must be saved inside the payment database transaction.');
    }
    $module = (string) ($allocation['source_module'] ?? '');
    if (!in_array($module, ['fees', 'activity', 'daycare', 'transport', 'uniform', 'feeding'], true)) {
        throw new InvalidArgumentException('Choose a supported payment category.');
    }
    $pdo->prepare("INSERT INTO payment_allocations
        (transaction_id, source_module, source_payment_id, student_id, daycare_child_id, payment_category, amount, academic_year, term, status)
        VALUES (:transaction_id, :module, :source_id, :student_id, :child_id, :category, :amount, :year, :term, 'Recorded')")
        ->execute([
            'transaction_id' => (int) $allocation['transaction_id'],
            'module' => $module,
            'source_id' => (int) $allocation['source_payment_id'],
            'student_id' => (int) ($allocation['student_id'] ?? 0) ?: null,
            'child_id' => (int) ($allocation['daycare_child_id'] ?? 0) ?: null,
            'category' => trim((string) ($allocation['payment_category'] ?? '')) ?: ucfirst($module),
            'amount' => round((float) $allocation['amount'], 2),
            'year' => (string) ($allocation['academic_year'] ?? ''),
            'term' => (string) ($allocation['term'] ?? ''),
        ]);
}

function payment_transaction_attach_legacy(PDO $pdo, string $module, int $sourceId, array $data): int
{
    $studentId = (int) ($data['student_id'] ?? 0);
    $childId = (int) ($data['daycare_child_id'] ?? 0);
    if ($studentId > 0 && empty($data['parent_user_id'])) {
        $parent = $pdo->prepare('SELECT parent_user_id FROM parent_student_links WHERE student_id=:student AND is_active=1 ORDER BY is_primary DESC,id LIMIT 1');
        $parent->execute(['student' => $studentId]);
        $data['parent_user_id'] = (int) ($parent->fetchColumn() ?: 0) ?: null;
    }
    if ($childId > 0 && empty($data['daycare_guardian_id'])) {
        $guardian = $pdo->prepare('SELECT c.primary_guardian_id,g.full_name FROM daycare_children c LEFT JOIN daycare_guardians g ON g.id=c.primary_guardian_id WHERE c.id=:id');
        $guardian->execute(['id' => $childId]);
        $child = $guardian->fetch(PDO::FETCH_ASSOC) ?: [];
        $data['daycare_guardian_id'] = (int) ($child['primary_guardian_id'] ?? 0) ?: null;
        $data['payer_name'] = $data['payer_name'] ?? ($child['full_name'] ?? null);
    }
    if (!empty($data['parent_user_id']) && empty($data['payer_name'])) {
        $parentName = $pdo->prepare('SELECT name FROM admin WHERE id=:id');
        $parentName->execute(['id' => (int) $data['parent_user_id']]);
        $data['payer_name'] = $parentName->fetchColumn() ?: null;
    }
    $id = payment_transaction_insert($pdo, $data);
    payment_transaction_attach_allocation($pdo, [
        'transaction_id' => $id,
        'source_module' => $module,
        'source_payment_id' => $sourceId,
        'student_id' => $data['student_id'] ?? null,
        'daycare_child_id' => $data['daycare_child_id'] ?? null,
        'payment_category' => $data['payment_category'] ?? ucfirst($module),
        'amount' => $data['total_amount'],
        'academic_year' => $data['academic_year'] ?? '',
        'term' => $data['term'] ?? '',
    ]);
    return $id;
}

function payment_transaction_sync_legacy_status(PDO $pdo, string $module, int $sourceId, string $status): void
{
    if (!in_array($module, ['fees', 'activity', 'daycare', 'transport', 'uniform', 'feeding'], true)
        || !in_array($status, ['Recorded', 'Reversed', 'Refunded'], true)) {
        return;
    }
    $pdo->prepare('UPDATE payment_allocations SET status = :status WHERE source_module = :module AND source_payment_id = :source_id')
        ->execute(['status' => $status, 'module' => $module, 'source_id' => $sourceId]);
    $transactionIds = $pdo->prepare('SELECT DISTINCT transaction_id FROM payment_allocations WHERE source_module = :module AND source_payment_id = :source_id');
    $transactionIds->execute(['module' => $module, 'source_id' => $sourceId]);
    foreach ($transactionIds->fetchAll(PDO::FETCH_COLUMN) as $transactionId) {
        $summary = $pdo->prepare("SELECT COUNT(*) AS allocation_count,
            SUM(status = 'Recorded') AS recorded_count,
            SUM(status = 'Refunded') AS refunded_count
            FROM payment_allocations WHERE transaction_id = :id");
        $summary->execute(['id' => (int) $transactionId]);
        $row = $summary->fetch(PDO::FETCH_ASSOC) ?: [];
        $count = (int) ($row['allocation_count'] ?? 0);
        $recorded = (int) ($row['recorded_count'] ?? 0);
        $refunded = (int) ($row['refunded_count'] ?? 0);
        $txStatus = $recorded > 0 ? ($recorded < $count ? 'Partially Reversed' : 'Recorded') : ($refunded > 0 ? 'Refunded' : 'Reversed');
        $pdo->prepare('UPDATE payment_transactions SET status = :status WHERE id = :id')->execute(['status' => $txStatus, 'id' => (int) $transactionId]);
    }
}

function payment_transaction_report_rows(PDO $pdo, array $filters = []): array
{
    $where = ['1=1'];
    $params = [];
    if (($filters['date_from'] ?? '') !== '') {
        $where[] = 't.payment_date >= :date_from';
        $params['date_from'] = $filters['date_from'];
    }
    if (($filters['date_to'] ?? '') !== '') {
        $where[] = 't.payment_date <= :date_to';
        $params['date_to'] = $filters['date_to'];
    }
    if (($filters['year'] ?? '') !== '' || ($filters['term'] ?? '') !== '') {
        $exists = ['x.transaction_id=t.id'];
        if (($filters['year'] ?? '') !== '') {
            $exists[] = 'x.academic_year=:academic_year';
            $params['academic_year'] = $filters['year'];
        }
        if (($filters['term'] ?? '') !== '') {
            $exists[] = 'x.term=:academic_term';
            $params['academic_term'] = $filters['term'];
        }
        $where[] = 'EXISTS (SELECT 1 FROM payment_allocations x WHERE ' . implode(' AND ', $exists) . ')';
    }

    $sql = "SELECT t.id,t.receipt_no,t.payment_method,t.mpesa_code,t.total_amount,t.payment_date,t.status,t.parent_user_id,COALESCE(p.name,g.full_name,t.payer_name) AS parent_name,
            a.id AS allocation_id,a.payment_category,a.amount,a.status AS allocation_status,a.academic_year,a.term,
            COALESCE(s.full_name,d.full_name,ts.student_name,'Unknown') AS student_name,
            COALESCE(s.registration_no,d.admission_no,CASE WHEN ts.is_outside=1 THEN 'Outside transport account' ELSE '' END,'') AS admission_no
        FROM payment_transactions t
        LEFT JOIN admin p ON p.id=t.parent_user_id
        LEFT JOIN daycare_guardians g ON g.id=t.daycare_guardian_id
        LEFT JOIN payment_allocations a ON a.transaction_id=t.id
        LEFT JOIN students s ON s.id=a.student_id
        LEFT JOIN daycare_children d ON d.id=a.daycare_child_id
        LEFT JOIN transport_payments tp ON a.source_module='transport' AND tp.id=a.source_payment_id
        LEFT JOIN transport_accounts ta ON ta.id=tp.transport_account_id
        LEFT JOIN transport_students ts ON ts.id=ta.transport_student_id
        WHERE " . implode(' AND ', $where) . ' ORDER BY t.payment_date DESC,t.id DESC,a.id';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $grouped = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int) $row['id'];
        if (!isset($grouped[$id])) {
            $grouped[$id] = [
                'id' => $id, 'receipt_no' => $row['receipt_no'], 'payment_method' => $row['payment_method'],
                'mpesa_code' => $row['mpesa_code'], 'total_amount' => (float) $row['total_amount'],
                'payment_date' => $row['payment_date'], 'status' => $row['status'], 'parent_name' => $row['parent_name'],
                'allocations' => [], 'allocation_summary' => '',
            ];
        }
        if ($row['allocation_id'] !== null) {
            $allocation = [
                'student_name' => $row['student_name'], 'admission_no' => $row['admission_no'],
                'payment_category' => $row['payment_category'], 'amount' => (float) $row['amount'],
                'status' => $row['allocation_status'], 'academic_year' => $row['academic_year'], 'term' => $row['term'],
            ];
            $grouped[$id]['allocations'][] = $allocation;
            $grouped[$id]['allocation_summary'] .= ($grouped[$id]['allocation_summary'] === '' ? '' : "\n")
                . $allocation['student_name'] . ' (' . $allocation['admission_no'] . ') · '
                . $allocation['payment_category'] . ' · KES ' . number_format($allocation['amount'], 2)
                . ' · ' . $allocation['status'];
        }
    }
    return array_values($grouped);
}

