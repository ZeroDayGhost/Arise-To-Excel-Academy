<?php

function fee_prepare_receipt_sequence(PDO $pdo, ?DateTimeImmutable $issuedAt = null): void
{
    if ($pdo->inTransaction()) {
        throw new LogicException('Prepare the annual receipt sequence before starting a payment transaction.');
    }

    $issuedAt ??= new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $year = $issuedAt->format('Y');
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $pdo->prepare('INSERT OR IGNORE INTO fee_receipt_sequences (issue_year, next_number) VALUES (:year, 1)')->execute(['year' => $year]);
        return;
    }

    $lockName = 'arise_fee_seq_' . $year;
    $lock = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $lock->execute(['lock_name' => $lockName]);
    if ((int) $lock->fetchColumn() !== 1) {
        throw new RuntimeException('The annual fee receipt sequence is busy. Please try again.');
    }
    try {
        $pdo->prepare('INSERT IGNORE INTO fee_receipt_sequences (issue_year, next_number) VALUES (:year, 1)')->execute(['year' => $year]);
    } finally {
        $release = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $release->execute(['lock_name' => $lockName]);
    }
}

function fee_allocate_receipt_number(PDO $pdo, ?DateTimeImmutable $issuedAt = null): string
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Receipt numbers must be allocated inside the payment transaction.');
    }

    $issuedAt ??= new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
    $year = $issuedAt->format('Y');

    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    $statement = $pdo->prepare('SELECT next_number FROM fee_receipt_sequences WHERE issue_year = :year' . $suffix);

    $statement->execute(['year' => $year]);
    $number = (int) $statement->fetchColumn();
    if ($number < 1) {
        throw new RuntimeException('The annual fee receipt sequence is unavailable. Please try recording the payment again.');
    }

    $update = $pdo->prepare('UPDATE fee_receipt_sequences SET next_number = :next_number WHERE issue_year = :year');
    $update->execute(['next_number' => $number + 1, 'year' => $year]);

    return sprintf('ATXR-%s-%05d', $year, $number);
}

function fee_acquire_payment_reference_lock(PDO $pdo, string $reference): ?string
{
    $reference = strtoupper(trim(preg_replace('/\s+/', '', $reference)));
    if ($reference === '' || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return null;
    }
    $lockName = 'arise_fee_ref_' . substr(hash('sha256', $reference), 0, 48);
    $statement = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
    $statement->execute(['lock_name' => $lockName]);
    if ((int) $statement->fetchColumn() !== 1) {
        throw new RuntimeException('This payment reference is being processed. Please try again.');
    }
    return $lockName;
}

function fee_release_payment_reference_lock(PDO $pdo, ?string $lockName): void
{
    if ($lockName === '' || $lockName === null || $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        return;
    }
    try {
        $statement = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
        $statement->execute(['lock_name' => $lockName]);
    } catch (Throwable $exception) {
        error_log('Fee payment reference lock release failed.');
    }
}

function fee_log_payment_audit(
    PDO $pdo,
    int $paymentId,
    int $studentId,
    string $receiptNo,
    string $eventType,
    ?int $actorId = null,
    ?string $reason = null,
    array $details = []
): void {
    $allowedEvents = [
        'RECORDED', 'REVERSED', 'CORRECTED', 'CANCELLED', 'LEGACY_IMPORTED', 'BULK_IMPORTED',
        'CORRECTION_REQUESTED', 'CORRECTION_APPROVED', 'CORRECTION_REJECTED', 'DIRECT_CORRECTION',
    ];
    if (!in_array($eventType, $allowedEvents, true)) {
        throw new InvalidArgumentException('Invalid fee payment audit event.');
    }

    $statement = $pdo->prepare(
        'INSERT INTO fee_payment_audit (payment_id, student_id, receipt_no, event_type, actor_id, reason, details)
         VALUES (:payment_id, :student_id, :receipt_no, :event_type, :actor_id, :reason, :details)'
    );
    $statement->execute([
        'payment_id' => $paymentId,
        'student_id' => $studentId,
        'receipt_no' => $receiptNo,
        'event_type' => $eventType,
        'actor_id' => $actorId,
        'reason' => $reason,
        'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
    ]);
}

function fee_receipt_actor_has_role(PDO $pdo, int $actorId, string $roleName): bool
{
    if ($actorId <= 0) {
        return false;
    }
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    $columns = $driver === 'sqlite'
        ? array_column($pdo->query('PRAGMA table_info(roles)')->fetchAll(PDO::FETCH_ASSOC), 'name')
        : array_column($pdo->query('SHOW COLUMNS FROM roles')->fetchAll(PDO::FETCH_ASSOC), 'Field');
    $roleExpr = in_array('role_name', $columns, true) && in_array('name', $columns, true)
        ? "COALESCE(NULLIF(roles.role_name, ''), roles.name)"
        : (in_array('role_name', $columns, true) ? 'roles.role_name' : 'roles.name');
    $statement = $pdo->prepare(
        "SELECT 1 FROM roles JOIN user_roles ON user_roles.role_id = roles.id
         WHERE user_roles.user_id = :user_id AND LOWER(TRIM({$roleExpr})) = LOWER(TRIM(:role_name)) LIMIT 1"
    );
    $statement->execute(['user_id' => $actorId, 'role_name' => $roleName]);
    return (bool) $statement->fetchColumn();
}

function fee_reverse_payment(PDO $pdo, int $paymentId, int $actorId, string $reason, string $eventType = 'REVERSED'): array
{
    if (!fee_receipt_actor_has_role($pdo, $actorId, 'Director')) {
        throw new RuntimeException('Only the Director may reverse or cancel a fee payment.');
    }
    if (!$pdo->inTransaction()) {
        throw new LogicException('Fee payments must be reversed inside a database transaction.');
    }
    if (!in_array($eventType, ['REVERSED', 'CANCELLED'], true)) {
        throw new InvalidArgumentException('Choose a valid payment audit action.');
    }
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500) {
        throw new RuntimeException('A reason of 1 to 500 characters is required.');
    }
    if (function_exists('fee_correction_has_pending') && fee_correction_has_pending($pdo, $paymentId)) {
        throw new RuntimeException('Resolve the pending correction request before reversing or cancelling this payment.');
    }
    $lockSuffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    $paymentQuery = $pdo->prepare('SELECT * FROM fees WHERE id = :id' . $lockSuffix);
    $paymentQuery->execute(['id' => $paymentId]);
    $payment = $paymentQuery->fetch();
    if (!$payment) {
        throw new RuntimeException('Payment not found.');
    }
    if (($payment['payment_status'] ?? 'Recorded') !== 'Recorded') {
        throw new RuntimeException('This payment has already been reversed or cancelled.');
    }

    $balanceQuery = $pdo->prepare(
        'SELECT * FROM fee_balances WHERE student_id = :student_id AND academic_year = :year AND term = :term' . $lockSuffix
    );
    $balanceQuery->execute([
        'student_id' => $payment['student_id'],
        'year' => $payment['year'],
        'term' => $payment['term'],
    ]);
    $balance = $balanceQuery->fetch();
    if (!$balance) {
        throw new RuntimeException('The matching fee balance is unavailable; the payment was not changed.');
    }

    $amount = (float) $payment['amount_paid'];
    if ((float) $balance['paid_amount'] + 0.005 < $amount) {
        throw new RuntimeException('The saved paid balance is smaller than this payment; the payment was not changed.');
    }
    $newPaid = max(0, (float) $balance['paid_amount'] - $amount);
    $newBalance = (float) $balance['balance'] + $amount;
    $updateBalance = $pdo->prepare('UPDATE fee_balances SET paid_amount = :paid, balance = :balance WHERE id = :id');
    $updateBalance->execute(['paid' => $newPaid, 'balance' => $newBalance, 'id' => $balance['id']]);

    $newStatus = $eventType === 'CANCELLED' ? 'Cancelled' : 'Reversed';
    $updatePayment = $pdo->prepare(
        'UPDATE fees SET payment_status = :status, reversed_at = CURRENT_TIMESTAMP, reversed_by = :actor_id, reversal_reason = :reason WHERE id = :id'
    );
    $updatePayment->execute(['status' => $newStatus, 'actor_id' => $actorId, 'reason' => $reason, 'id' => $paymentId]);
    if (function_exists('payment_transaction_sync_legacy_status')) {
        payment_transaction_sync_legacy_status($pdo, 'fees', $paymentId, 'Reversed');
    }
    fee_log_payment_audit(
        $pdo,
        $paymentId,
        (int) $payment['student_id'],
        (string) $payment['receipt_no'],
        $eventType,
        $actorId,
        $reason,
        [
            'payment_status' => ['from' => 'Recorded', 'to' => $newStatus],
            'original_values' => [
                'student_id' => (int) $payment['student_id'],
                'amount_paid' => number_format((float) $payment['amount_paid'], 2, '.', ''),
                'payment_method' => (string) ($payment['payment_method'] ?? 'M-PESA'),
                'mpesa_code' => (string) ($payment['mpesa_code'] ?? ''),
                'mpesa_reference_text' => (string) ($payment['mpesa_reference_text'] ?? ''),
                'term' => (string) $payment['term'],
                'year' => (string) $payment['year'],
                'payment_date' => (string) $payment['payment_date'],
                'balance_after_payment' => number_format((float) $payment['balance_after_payment'], 2, '.', ''),
            ],
            'balance' => ['paid_from' => (float) $balance['paid_amount'], 'paid_to' => $newPaid, 'balance_from' => (float) $balance['balance'], 'balance_to' => $newBalance],
        ]
    );

    return ['receipt_no' => (string) $payment['receipt_no'], 'student_id' => (int) $payment['student_id']];
}
