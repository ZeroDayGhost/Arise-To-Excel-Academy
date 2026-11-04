<?php

require_once __DIR__ . '/fee_receipt_helpers.php';

function fee_correction_actor_has_role(PDO $pdo, int $actorId, string $roleName): bool
{
    return fee_receipt_actor_has_role($pdo, $actorId, $roleName);
}

function fee_correction_require_role(PDO $pdo, int $actorId, string $roleName): void
{
    if (!fee_correction_actor_has_role($pdo, $actorId, $roleName)) {
        throw new RuntimeException("Only the {$roleName} role may perform this payment correction action.");
    }
}

function fee_correction_actor_name(PDO $pdo, int $actorId): string
{
    $statement = $pdo->prepare('SELECT name FROM admin WHERE id = :id LIMIT 1');
    $statement->execute(['id' => $actorId]);
    return (string) ($statement->fetchColumn() ?: "User #{$actorId}");
}

function fee_correction_payment_snapshot(array $payment): array
{
    return [
        'student_id' => (int) $payment['student_id'],
        'amount_paid' => number_format((float) $payment['amount_paid'], 2, '.', ''),
        'payment_method' => (string) ($payment['payment_method'] ?? 'M-PESA'),
        'mpesa_code' => (string) ($payment['mpesa_code'] ?? ''),
        'mpesa_reference_text' => (string) ($payment['mpesa_reference_text'] ?? ''),
        'term' => (string) $payment['term'],
        'year' => (string) $payment['year'],
        'payment_date' => (string) $payment['payment_date'],
        'balance_after_payment' => number_format((float) $payment['balance_after_payment'], 2, '.', ''),
    ];
}

function fee_correction_json_array(?string $json): array
{
    if (!$json) {
        return [];
    }
    $decoded = json_decode($json, true);
    return is_array($decoded) ? $decoded : [];
}

function fee_correction_require_reason(string $reason, string $label = 'Reason'): string
{
    $reason = trim($reason);
    if ($reason === '' || mb_strlen($reason) > 500) {
        throw new RuntimeException("{$label} must be between 1 and 500 characters.");
    }
    return $reason;
}

function fee_correction_reference_duplicate_exists(PDO $pdo, string $code, int $paymentId): bool
{
    $code = strtoupper(trim(preg_replace('/\s+/', '', $code)));
    if ($code === '') {
        return false;
    }
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM fees
         WHERE LOWER(TRIM(mpesa_code)) = LOWER(TRIM(:code))
           AND payment_status = 'Recorded' AND id <> :payment_id"
    );
    $statement->execute(['code' => $code, 'payment_id' => $paymentId]);
    return (int) $statement->fetchColumn() > 0;
}

function fee_correction_normalize_values(PDO $pdo, array $input, array $current, int $paymentId): array
{
    $studentId = filter_var($input['student_id'] ?? $current['student_id'], FILTER_VALIDATE_INT);
    $amountInput = trim((string) ($input['amount_paid'] ?? $current['amount_paid']));
    $method = trim((string) ($input['payment_method'] ?? $current['payment_method']));
    $code = strtoupper(trim(preg_replace('/\s+/', '', (string) ($input['mpesa_code'] ?? $current['mpesa_code']))));
    $referenceText = trim((string) ($input['mpesa_reference_text'] ?? $current['mpesa_reference_text']));
    $term = trim((string) ($input['term'] ?? $current['term']));
    $year = trim((string) ($input['year'] ?? $current['year']));
    $date = trim((string) ($input['payment_date'] ?? $current['payment_date']));

    if (!$studentId || $studentId <= 0) {
        throw new RuntimeException('Choose a valid student.');
    }
    $student = $pdo->prepare('SELECT id FROM students WHERE id = :id LIMIT 1');
    $student->execute(['id' => $studentId]);
    if (!$student->fetchColumn()) {
        throw new RuntimeException('The selected student could not be found.');
    }
    if (!preg_match('/^\d{1,10}(?:\.\d{1,2})?$/', $amountInput) || (float) $amountInput <= 0) {
        throw new RuntimeException('Amount must be greater than zero and use no more than two decimal places.');
    }
    if (!preg_match('/^\d{4}$/', $year)) {
        throw new RuntimeException('Enter a valid four-digit academic year.');
    }
    if (!in_array($term, ['Term 1', 'Term 2', 'Term 3'], true)) {
        throw new RuntimeException('Choose a valid academic term.');
    }
    $dateObject = DateTime::createFromFormat('!Y-m-d', $date);
    if (!$dateObject || $dateObject->format('Y-m-d') !== $date) {
        throw new RuntimeException('Enter a valid payment date.');
    }

    $methods = ['M-PESA', 'Cash', 'Bank', 'Cheque', 'Other'];
    try {
        $methods = array_merge($methods, array_column($pdo->query('SELECT DISTINCT payment_method FROM fees')->fetchAll(PDO::FETCH_ASSOC), 'payment_method'));
    } catch (Throwable $ignored) {
    }
    $methodMap = [];
    foreach ($methods as $knownMethod) {
        $knownMethod = trim((string) $knownMethod);
        if ($knownMethod !== '') {
            $methodMap[strtolower($knownMethod)] = $knownMethod;
        }
    }
    if (!isset($methodMap[strtolower($method)])) {
        throw new RuntimeException('Choose a payment method already supported by the fee system.');
    }
    $method = $methodMap[strtolower($method)];
    if (mb_strlen($code) > 30 || mb_strlen($referenceText) > 2000) {
        throw new RuntimeException('The payment reference is too long.');
    }
    if ($method === 'M-PESA' && !preg_match('/^[A-Z0-9]{8,20}$/', $code)) {
        $unchangedLegacyCode = $code === strtoupper((string) ($current['mpesa_code'] ?? ''));
        if (!$unchangedLegacyCode) {
            throw new RuntimeException('Enter a valid 8 to 20 character M-PESA transaction code.');
        }
    }
    if ($code !== '' && !preg_match('/^[A-Z0-9-]{1,30}$/', $code)) {
        throw new RuntimeException('Payment references may contain letters, numbers, and hyphens only.');
    }
    $currentCode = strtoupper(trim((string) ($current['mpesa_code'] ?? '')));
    if ($code !== '' && $code !== $currentCode && fee_correction_reference_duplicate_exists($pdo, $code, $paymentId)) {
        throw new RuntimeException('That payment reference is already used by another recorded payment.');
    }

    return [
        'student_id' => (int) $studentId,
        'amount_paid' => number_format((float) $amountInput, 2, '.', ''),
        'payment_method' => $method,
        'mpesa_code' => $code,
        'mpesa_reference_text' => $referenceText,
        'term' => $term,
        'year' => $year,
        'payment_date' => $date,
    ];
}

function fee_correction_group_key(array $values): string
{
    return implode('|', [(int) $values['student_id'], (string) $values['year'], (string) $values['term']]);
}

function fee_correction_lock_payment(PDO $pdo, int $paymentId): array
{
    $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
    $statement = $pdo->prepare('SELECT * FROM fees WHERE id = :id' . $suffix);
    $statement->execute(['id' => $paymentId]);
    $payment = $statement->fetch(PDO::FETCH_ASSOC);
    if (!$payment) {
        throw new RuntimeException('Payment not found.');
    }
    if (($payment['payment_status'] ?? 'Recorded') !== 'Recorded') {
        throw new RuntimeException('Only a recorded payment can be corrected.');
    }
    return $payment;
}

function fee_correction_has_pending(PDO $pdo, int $paymentId): bool
{
    $statement = $pdo->prepare("SELECT COUNT(*) FROM fee_payment_correction_requests WHERE payment_id = :id AND status = 'Pending'");
    $statement->execute(['id' => $paymentId]);
    return (int) $statement->fetchColumn() > 0;
}

function fee_request_payment_correction(PDO $pdo, int $paymentId, int $actorId, array $input, string $reason): int
{
    fee_correction_require_role($pdo, $actorId, 'Secretary');
    $reason = fee_correction_require_reason($reason, 'Correction reason');
    if (!$pdo->inTransaction()) {
        $pdo->beginTransaction();
    }
    try {
        $payment = fee_correction_lock_payment($pdo, $paymentId);
        if (fee_correction_has_pending($pdo, $paymentId)) {
            throw new RuntimeException('A correction request is already pending for this payment.');
        }
        $original = fee_correction_payment_snapshot($payment);
        if (isset($input['_original_values'])) {
            $expected = fee_correction_json_array((string) $input['_original_values']);
            if ($expected !== $original) {
                throw new RuntimeException('This payment changed while the request form was open. Refresh and review it again.');
            }
        }
        $proposed = fee_correction_normalize_values($pdo, $input, $original, $paymentId);
        if ($proposed === array_intersect_key($original, $proposed)) {
            throw new RuntimeException('Change at least one payment value before submitting a request.');
        }
        $balance = $pdo->prepare(
            'SELECT id FROM fee_balances WHERE student_id = :student_id AND academic_year = :year AND term = :term LIMIT 1'
        );
        $balance->execute(['student_id' => $proposed['student_id'], 'year' => $proposed['year'], 'term' => $proposed['term']]);
        if (!$balance->fetchColumn()) {
            throw new RuntimeException('The proposed student and academic period have no saved fee balance.');
        }

        $insert = $pdo->prepare(
            "INSERT INTO fee_payment_correction_requests
                (payment_id, student_id, receipt_no, requested_by, requested_by_name, reason, original_values, proposed_values, status)
             VALUES (:payment_id, :student_id, :receipt_no, :requested_by, :requested_by_name, :reason, :original_values, :proposed_values, 'Pending')"
        );
        $insert->execute([
            'payment_id' => $paymentId,
            'student_id' => (int) $payment['student_id'],
            'receipt_no' => (string) $payment['receipt_no'],
            'requested_by' => $actorId,
            'requested_by_name' => fee_correction_actor_name($pdo, $actorId),
            'reason' => $reason,
            'original_values' => json_encode($original, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            'proposed_values' => json_encode($proposed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        ]);
        $requestId = (int) $pdo->lastInsertId();
        fee_log_payment_audit($pdo, $paymentId, (int) $payment['student_id'], (string) $payment['receipt_no'], 'CORRECTION_REQUESTED', $actorId, $reason, [
            'request_id' => $requestId,
            'original_values' => $original,
            'proposed_values' => $proposed,
            'payment_and_balance_unchanged' => true,
        ]);
        $pdo->commit();
        return $requestId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function fee_correction_update_balances(PDO $pdo, array $original, array $proposed): array
{
    $groups = [];
    $groups[fee_correction_group_key($original)] = $original;
    $groups[fee_correction_group_key($proposed)] = $proposed;
    ksort($groups, SORT_STRING);

    $studentIds = array_values(array_unique(array_map(static fn(array $group): int => (int) $group['student_id'], array_values($groups))));
    sort($studentIds, SORT_NUMERIC);
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));
    $studentLockSql = "SELECT id FROM students WHERE id IN ({$placeholders}) ORDER BY id";
    if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
        $studentLockSql .= ' FOR UPDATE';
    }
    $studentLock = $pdo->prepare($studentLockSql);
    $studentLock->execute($studentIds);
    if (count($studentLock->fetchAll(PDO::FETCH_COLUMN)) !== count($studentIds)) {
        throw new RuntimeException('A student on this correction no longer exists.');
    }

    $balances = [];
    foreach ($groups as $key => $group) {
        $sql = 'SELECT * FROM fee_balances WHERE student_id = :student_id AND academic_year = :year AND term = :term';
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            $sql .= ' FOR UPDATE';
        }
        $statement = $pdo->prepare($sql);
        $statement->execute(['student_id' => $group['student_id'], 'year' => $group['year'], 'term' => $group['term']]);
        $balance = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$balance) {
            throw new RuntimeException('A saved fee balance for the corrected student and period is unavailable.');
        }
        $balances[$key] = $balance;
    }

    $oldKey = fee_correction_group_key($original);
    $newKey = fee_correction_group_key($proposed);
    $oldAmount = (float) $original['amount_paid'];
    $newAmount = (float) $proposed['amount_paid'];
    $oldBalance = $balances[$oldKey];
    if ((float) $oldBalance['paid_amount'] + 0.005 < $oldAmount) {
        throw new RuntimeException('The saved paid balance cannot be reconciled with the original payment.');
    }

    $before = [];
    foreach ($balances as $key => $balance) {
        $before[$key] = [
            'student_id' => (int) $balance['student_id'],
            'year' => (string) $balance['academic_year'],
            'term' => (string) $balance['term'],
            'paid_amount' => number_format((float) $balance['paid_amount'], 2, '.', ''),
            'balance' => number_format((float) $balance['balance'], 2, '.', ''),
        ];
    }

    if ($oldKey === $newKey) {
        $newPaid = (float) $oldBalance['paid_amount'] - $oldAmount + $newAmount;
        $newBalance = (float) $oldBalance['balance'] + $oldAmount - $newAmount;
        if ($newPaid < -0.005 || $newBalance < -0.005) {
            throw new RuntimeException('The corrected amount would exceed the remaining fee balance.');
        }
        $balances[$oldKey]['paid_amount'] = max(0, $newPaid);
        $balances[$oldKey]['balance'] = max(0, $newBalance);
    } else {
        $target = $balances[$newKey];
        if ((float) $target['balance'] + 0.005 < $newAmount) {
            throw new RuntimeException('The corrected payment exceeds the target period balance.');
        }
        $balances[$oldKey]['paid_amount'] = max(0, (float) $oldBalance['paid_amount'] - $oldAmount);
        $balances[$oldKey]['balance'] = (float) $oldBalance['balance'] + $oldAmount;
        $balances[$newKey]['paid_amount'] = (float) $target['paid_amount'] + $newAmount;
        $balances[$newKey]['balance'] = max(0, (float) $target['balance'] - $newAmount);
    }

    $update = $pdo->prepare('UPDATE fee_balances SET paid_amount = :paid, balance = :balance WHERE id = :id');
    foreach ($balances as $balance) {
        $update->execute([
            'paid' => number_format((float) $balance['paid_amount'], 2, '.', ''),
            'balance' => number_format((float) $balance['balance'], 2, '.', ''),
            'id' => (int) $balance['id'],
        ]);
    }

    return ['before' => $before, 'after' => array_map(static function (array $balance): array {
        return [
            'student_id' => (int) $balance['student_id'],
            'year' => (string) $balance['academic_year'],
            'term' => (string) $balance['term'],
            'paid_amount' => number_format((float) $balance['paid_amount'], 2, '.', ''),
            'balance' => number_format((float) $balance['balance'], 2, '.', ''),
        ];
    }, $balances)];
}

function fee_correction_update_payment(PDO $pdo, int $paymentId, array $proposed): array
{
    $balanceChanges = fee_correction_update_balances($pdo, fee_correction_payment_snapshot(
        (function () use ($pdo, $paymentId): array {
            $statement = $pdo->prepare('SELECT * FROM fees WHERE id = :id');
            $statement->execute(['id' => $paymentId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('Payment not found.');
            }
            return $row;
        })()
    ), $proposed);

    $update = $pdo->prepare(
        'UPDATE fees SET student_id = :student_id, amount_paid = :amount_paid, payment_method = :payment_method,
            mpesa_code = :mpesa_code, mpesa_reference_text = :reference_text, term = :term, year = :year,
            payment_date = :payment_date WHERE id = :id'
    );
    $update->execute([
        'student_id' => $proposed['student_id'],
        'amount_paid' => $proposed['amount_paid'],
        'payment_method' => $proposed['payment_method'],
        'mpesa_code' => $proposed['mpesa_code'],
        'reference_text' => $proposed['mpesa_reference_text'],
        'term' => $proposed['term'],
        'year' => $proposed['year'],
        'payment_date' => $proposed['payment_date'],
        'id' => $paymentId,
    ]);

    $snapshotBalanceQuery = $pdo->prepare(
        'SELECT balance, paid_amount FROM fee_balances WHERE student_id = :student_id AND academic_year = :year AND term = :term LIMIT 1'
    );
    $snapshotBalanceQuery->execute(['student_id' => $proposed['student_id'], 'year' => $proposed['year'], 'term' => $proposed['term']]);
    $targetBalance = $snapshotBalanceQuery->fetch(PDO::FETCH_ASSOC);
    $ledgerQuery = $pdo->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) FROM fees
         WHERE student_id = :student_id AND year = :year AND term = :term AND payment_status = 'Recorded'"
    );
    $ledgerQuery->execute(['student_id' => $proposed['student_id'], 'year' => $proposed['year'], 'term' => $proposed['term']]);
    $ledgerTotal = (float) $ledgerQuery->fetchColumn();
    $beforePaymentQuery = $pdo->prepare(
        "SELECT COALESCE(SUM(amount_paid), 0) FROM fees
         WHERE student_id = :student_id AND year = :year AND term = :term AND payment_status = 'Recorded'
           AND (payment_date < :payment_date OR (payment_date = :payment_date AND id <= :payment_id))"
    );
    $beforePaymentQuery->execute([
        'student_id' => $proposed['student_id'], 'year' => $proposed['year'], 'term' => $proposed['term'],
        'payment_date' => $proposed['payment_date'], 'payment_id' => $paymentId,
    ]);
    $ledgerThroughPayment = (float) $beforePaymentQuery->fetchColumn();
    $paymentBalance = max(0, (float) $targetBalance['balance'] + $ledgerTotal - $ledgerThroughPayment);
    $pdo->prepare('UPDATE fees SET balance_after_payment = :balance WHERE id = :id')->execute([
        'balance' => number_format($paymentBalance, 2, '.', ''), 'id' => $paymentId,
    ]);
    $balanceChanges['corrected_balance_after_payment'] = number_format($paymentBalance, 2, '.', '');
    return $balanceChanges;
}

function fee_apply_direct_payment_correction(PDO $pdo, int $paymentId, int $actorId, array $input, string $reason): array
{
    fee_correction_require_role($pdo, $actorId, 'Director');
    $reason = fee_correction_require_reason($reason, 'Correction reason');
    $candidateCode = strtoupper(trim(preg_replace('/\s+/', '', (string) ($input['mpesa_code'] ?? ''))));
    $referenceLock = $candidateCode !== '' ? fee_acquire_payment_reference_lock($pdo, $candidateCode) : null;
    try {
        $pdo->beginTransaction();
        $payment = fee_correction_lock_payment($pdo, $paymentId);
        if (fee_correction_has_pending($pdo, $paymentId)) {
            throw new RuntimeException('Resolve the pending Secretary request before making a direct correction.');
        }
        $original = fee_correction_payment_snapshot($payment);
        if (isset($input['_original_values'])) {
            $expected = fee_correction_json_array((string) $input['_original_values']);
            if ($expected !== $original) {
                throw new RuntimeException('This payment changed while the correction form was open. Refresh and review it again.');
            }
        }
        $proposed = fee_correction_normalize_values($pdo, $input, $original, $paymentId);
        if ($proposed === array_intersect_key($original, $proposed)) {
            throw new RuntimeException('Change at least one payment value before saving a correction.');
        }
        $balanceChanges = fee_correction_update_payment($pdo, $paymentId, $proposed);
        fee_log_payment_audit($pdo, $paymentId, (int) $payment['student_id'], (string) $payment['receipt_no'], 'DIRECT_CORRECTION', $actorId, $reason, [
            'original_values' => $original,
            'corrected_values' => array_merge($proposed, ['balance_after_payment' => $balanceChanges['corrected_balance_after_payment']]),
            'balance_changes' => $balanceChanges,
            'receipt_no_preserved' => (string) $payment['receipt_no'],
        ]);
        $pdo->commit();
        return ['receipt_no' => (string) $payment['receipt_no'], 'student_id' => (int) $proposed['student_id']];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } finally {
        fee_release_payment_reference_lock($pdo, $referenceLock);
    }
}

function fee_decide_payment_correction(PDO $pdo, int $requestId, int $actorId, string $decision, string $decisionReason = ''): array
{
    fee_correction_require_role($pdo, $actorId, 'Director');
    $decision = strtoupper(trim($decision));
    if (!in_array($decision, ['APPROVE', 'REJECT'], true)) {
        throw new RuntimeException('Choose approve or reject.');
    }
    $decisionReason = trim($decisionReason);
    if ($decision === 'REJECT') {
        $decisionReason = fee_correction_require_reason($decisionReason, 'Rejection reason');
    } elseif (mb_strlen($decisionReason) > 500) {
        throw new RuntimeException('Decision note cannot exceed 500 characters.');
    }

    $referenceLock = null;
    if ($decision === 'APPROVE') {
        $preview = $pdo->prepare('SELECT proposed_values FROM fee_payment_correction_requests WHERE id = :id LIMIT 1');
        $preview->execute(['id' => $requestId]);
        $proposedPreview = fee_correction_json_array($preview->fetchColumn() ?: null);
        $code = strtoupper(trim((string) ($proposedPreview['mpesa_code'] ?? '')));
        if ($code !== '') {
            $referenceLock = fee_acquire_payment_reference_lock($pdo, $code);
        }
    }

    try {
        $pdo->beginTransaction();
        $suffix = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? '' : ' FOR UPDATE';
        $requestQuery = $pdo->prepare('SELECT * FROM fee_payment_correction_requests WHERE id = :id' . $suffix);
        $requestQuery->execute(['id' => $requestId]);
        $request = $requestQuery->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['status'] !== 'Pending') {
            throw new RuntimeException('This correction request is no longer pending.');
        }
        $payment = fee_correction_lock_payment($pdo, (int) $request['payment_id']);
        $original = fee_correction_payment_snapshot($payment);
        $requestedOriginal = fee_correction_json_array($request['original_values']);
        if ($original !== $requestedOriginal) {
            throw new RuntimeException('The payment changed after this request was submitted. Review the current payment before deciding.');
        }
        $directorName = fee_correction_actor_name($pdo, $actorId);

        if ($decision === 'REJECT') {
            $update = $pdo->prepare(
                "UPDATE fee_payment_correction_requests
                 SET status = 'Rejected', decided_by = :actor_id, decided_by_name = :actor_name,
                     decision_reason = :reason, decided_at = CURRENT_TIMESTAMP WHERE id = :id"
            );
            $update->execute(['actor_id' => $actorId, 'actor_name' => $directorName, 'reason' => $decisionReason, 'id' => $requestId]);
            fee_log_payment_audit($pdo, (int) $payment['id'], (int) $payment['student_id'], (string) $payment['receipt_no'], 'CORRECTION_REJECTED', $actorId, $decisionReason, [
                'request_id' => $requestId,
                'requested_by' => (int) $request['requested_by'],
                'requested_by_name' => $request['requested_by_name'],
                'request_reason' => $request['reason'],
                'decision_reason' => $decisionReason,
                'original_values' => $original,
                'proposed_values' => fee_correction_json_array($request['proposed_values']),
                'payment_and_balance_unchanged' => true,
            ]);
            $pdo->commit();
            return ['status' => 'Rejected', 'receipt_no' => (string) $payment['receipt_no']];
        }

        $proposed = fee_correction_normalize_values($pdo, fee_correction_json_array($request['proposed_values']), $original, (int) $payment['id']);
        $balanceChanges = fee_correction_update_payment($pdo, (int) $payment['id'], $proposed);
        $update = $pdo->prepare(
            "UPDATE fee_payment_correction_requests
             SET status = 'Approved', decided_by = :actor_id, decided_by_name = :actor_name,
                 decision_reason = :reason, decided_at = CURRENT_TIMESTAMP WHERE id = :id"
        );
        $update->execute(['actor_id' => $actorId, 'actor_name' => $directorName, 'reason' => $decisionReason, 'id' => $requestId]);
        fee_log_payment_audit($pdo, (int) $payment['id'], (int) $payment['student_id'], (string) $payment['receipt_no'], 'CORRECTION_APPROVED', $actorId, $decisionReason !== '' ? $decisionReason : $request['reason'], [
            'request_id' => $requestId,
            'requested_by' => (int) $request['requested_by'],
            'requested_by_name' => $request['requested_by_name'],
            'request_reason' => $request['reason'],
            'decision_reason' => $decisionReason,
            'original_values' => $original,
            'corrected_values' => array_merge($proposed, ['balance_after_payment' => $balanceChanges['corrected_balance_after_payment']]),
            'balance_changes' => $balanceChanges,
            'receipt_no_preserved' => (string) $payment['receipt_no'],
        ]);
        $pdo->commit();
        return ['status' => 'Approved', 'receipt_no' => (string) $payment['receipt_no']];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } finally {
        fee_release_payment_reference_lock($pdo, $referenceLock);
    }
}

function fee_pending_correction_count(PDO $pdo): int
{
    return (int) $pdo->query("SELECT COUNT(*) FROM fee_payment_correction_requests WHERE status = 'Pending'")->fetchColumn();
}

function fee_pending_correction_requests(PDO $pdo, int $limit = 10): array
{
    $limit = max(1, min(50, $limit));
    return $pdo->query(
        "SELECT r.*, s.full_name AS student_name, s.registration_no, s.class_level,
                f.amount_paid AS current_amount, f.payment_method AS current_method,
                f.mpesa_code AS current_reference, f.term AS current_term, f.year AS current_year, f.payment_date AS current_payment_date
         FROM fee_payment_correction_requests r
         JOIN fees f ON f.id = r.payment_id
         JOIN students s ON s.id = r.student_id
         WHERE r.status = 'Pending'
         ORDER BY r.created_at ASC, r.id ASC LIMIT {$limit}"
    )->fetchAll(PDO::FETCH_ASSOC);
}
