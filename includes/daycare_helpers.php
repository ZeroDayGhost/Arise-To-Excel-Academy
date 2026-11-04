<?php
require_once __DIR__ . '/fee_helpers.php';
require_once __DIR__ . '/payment_transactions.php';

function daycare_require(PDO $pdo, string $permission): void
{
    require_module_access($pdo, 'Daycare');
    if ($permission !== '') {
        require_action_permission($pdo, $permission);
    }
}

function daycare_audit(PDO $pdo, string $action, string $entity, ?int $entityId, array $details = []): void
{
    $stmt = $pdo->prepare('INSERT INTO daycare_audit_log (admin_id, action, entity_type, entity_id, details) VALUES (:admin, :action, :entity, :id, :details)');
    $stmt->execute([
        'admin' => (int) ($_SESSION['admin_id'] ?? 0) ?: null,
        'action' => $action,
        'entity' => $entity,
        'id' => $entityId,
        'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
}

function daycare_allocate_number(PDO $pdo, string $table, string $yearColumn, int $year, string $prefix): string
{
    if (!in_array($table, ['daycare_admission_sequences', 'daycare_receipt_sequences'], true)) {
        throw new InvalidArgumentException('Invalid daycare sequence.');
    }
    $pdo->prepare("INSERT IGNORE INTO {$table} ({$yearColumn}, last_number) VALUES (:year, 0)")->execute(['year' => $year]);
    $stmt = $pdo->prepare("SELECT last_number FROM {$table} WHERE {$yearColumn} = :year FOR UPDATE");
    $stmt->execute(['year' => $year]);
    $next = (int) $stmt->fetchColumn() + 1;
    $update = $pdo->prepare("UPDATE {$table} SET last_number = :number WHERE {$yearColumn} = :year");
    $update->execute(['number' => $next, 'year' => $year]);
    return sprintf('%s-%d-%05d', $prefix, $year, $next);
}

function daycare_valid_date(string $value): bool
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date instanceof DateTimeImmutable && $date->format('Y-m-d') === $value;
}

function daycare_register_child(PDO $pdo, array $input): int
{
    $name = trim((string) ($input['full_name'] ?? ''));
    $dob = trim((string) ($input['date_of_birth'] ?? ''));
    $admissionDate = trim((string) ($input['admission_date'] ?? date('Y-m-d')));
    $guardianName = trim((string) ($input['guardian_name'] ?? ''));
    $guardianPhone = trim((string) ($input['guardian_phone'] ?? ''));
    $enrollmentStatus = (string) ($input['enrollment_status'] ?? 'Active');
    if ($name === '' || !daycare_valid_date($dob) || !daycare_valid_date($admissionDate)) {
        throw new InvalidArgumentException('Enter the child name and valid birth and admission dates.');
    }
    if ($dob > $admissionDate || $admissionDate > date('Y-m-d')) {
        throw new InvalidArgumentException('Birth date must be before admission, and admission cannot be in the future.');
    }
    if ($guardianName === '' || $guardianPhone === '') {
        throw new InvalidArgumentException('A primary guardian name and phone number are required.');
    }

    $pdo->beginTransaction();
    try {
        $find = $pdo->prepare('SELECT id FROM daycare_guardians WHERE phone = :phone LIMIT 1');
        $find->execute(['phone' => $guardianPhone]);
        $guardianId = (int) $find->fetchColumn();
        if ($guardianId) {
            $pdo->prepare('UPDATE daycare_guardians SET full_name = :name, alternate_phone = :alt, email = :email, relationship = :relationship, address = :address WHERE id = :id')
                ->execute(['name' => $guardianName, 'alt' => trim((string) ($input['guardian_alternate_phone'] ?? '')) ?: null, 'email' => trim((string) ($input['guardian_email'] ?? '')) ?: null, 'relationship' => trim((string) ($input['guardian_relationship'] ?? '')) ?: null, 'address' => trim((string) ($input['address'] ?? '')) ?: null, 'id' => $guardianId]);
        } else {
            $insertGuardian = $pdo->prepare('INSERT INTO daycare_guardians (full_name, phone, alternate_phone, email, relationship, address) VALUES (:name, :phone, :alt, :email, :relationship, :address)');
            $insertGuardian->execute(['name' => $guardianName, 'phone' => $guardianPhone, 'alt' => trim((string) ($input['guardian_alternate_phone'] ?? '')) ?: null, 'email' => trim((string) ($input['guardian_email'] ?? '')) ?: null, 'relationship' => trim((string) ($input['guardian_relationship'] ?? '')) ?: null, 'address' => trim((string) ($input['address'] ?? '')) ?: null]);
            $guardianId = (int) $pdo->lastInsertId();
        }

        $admissionNo = trim((string) ($input['admission_no'] ?? ''));
        if ($admissionNo === '') {
            $admissionNo = daycare_allocate_number($pdo, 'daycare_admission_sequences', 'admission_year', (int) date('Y', strtotime($admissionDate)), 'DC');
        }
        $insert = $pdo->prepare('INSERT INTO daycare_children (admission_no, full_name, date_of_birth, gender, admission_date, group_id, primary_guardian_id, fee_plan_id, emergency_contact_name, emergency_contact_phone, address, allergies, medical_conditions, special_care_instructions, enrollment_status, created_by) VALUES (:admission, :name, :dob, :gender, :admission_date, :group_id, :guardian, :plan, :emergency_name, :emergency_phone, :address, :allergies, :conditions, :care, :status, :admin)');
        $insert->execute([
            'admission' => $admissionNo, 'name' => $name, 'dob' => $dob,
            'gender' => trim((string) ($input['gender'] ?? '')) ?: null, 'admission_date' => $admissionDate,
            'group_id' => (int) ($input['group_id'] ?? 0) ?: null, 'guardian' => $guardianId,
            'plan' => (int) ($input['fee_plan_id'] ?? 0) ?: null,
            'emergency_name' => trim((string) ($input['emergency_contact_name'] ?? '')) ?: null,
            'emergency_phone' => trim((string) ($input['emergency_contact_phone'] ?? '')) ?: null,
            'address' => trim((string) ($input['address'] ?? '')) ?: null,
            'allergies' => trim((string) ($input['allergies'] ?? '')) ?: null,
            'conditions' => trim((string) ($input['medical_conditions'] ?? '')) ?: null,
            'care' => trim((string) ($input['special_care_instructions'] ?? '')) ?: null,
            'status' => in_array($enrollmentStatus, ['Active', 'Inactive', 'Left'], true) ? $enrollmentStatus : 'Active',
            'admin' => (int) ($_SESSION['admin_id'] ?? 0) ?: null,
        ]);
        $childId = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO daycare_child_guardians (child_id, guardian_id, relationship, is_primary) VALUES (:child, :guardian, :relationship, 1)')
            ->execute(['child' => $childId, 'guardian' => $guardianId, 'relationship' => trim((string) ($input['guardian_relationship'] ?? '')) ?: null]);
        daycare_audit($pdo, 'CHILD_REGISTERED', 'child', $childId, ['admission_no' => $admissionNo]);
        $pdo->commit();
        return $childId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) $exception->getCode() === '23000') {
            throw new RuntimeException('That admission number or guardian phone is already in use. Check the details and try again.');
        }
        throw $exception;
    }
}

function daycare_update_child(PDO $pdo, int $childId, array $input): void
{
    $name = trim((string) ($input['full_name'] ?? ''));
    $admissionNo = trim((string) ($input['admission_no'] ?? ''));
    $dob = trim((string) ($input['date_of_birth'] ?? ''));
    $admissionDate = trim((string) ($input['admission_date'] ?? ''));
    $guardianName = trim((string) ($input['guardian_name'] ?? ''));
    $guardianPhone = trim((string) ($input['guardian_phone'] ?? ''));
    if ($childId <= 0 || $name === '' || $admissionNo === '' || !daycare_valid_date($dob) || !daycare_valid_date($admissionDate) || $dob > $admissionDate || $admissionDate > date('Y-m-d') || $guardianName === '' || $guardianPhone === '') {
        throw new InvalidArgumentException('Check the child name, unique admission number, dates, and primary guardian details.');
    }
    $status = (string) ($input['enrollment_status'] ?? 'Active');
    if (!in_array($status, ['Active', 'Inactive', 'Left'], true)) {
        throw new InvalidArgumentException('Select a valid enrollment status.');
    }
    $pdo->beginTransaction();
    try {
        $existing = $pdo->prepare('SELECT primary_guardian_id FROM daycare_children WHERE id=:id FOR UPDATE');
        $existing->execute(['id' => $childId]);
        $old = $existing->fetch();
        if (!$old) throw new RuntimeException('Child record was not found.');

        $find = $pdo->prepare('SELECT id FROM daycare_guardians WHERE phone=:phone LIMIT 1 FOR UPDATE');
        $find->execute(['phone' => $guardianPhone]);
        $guardianId = (int) $find->fetchColumn();
        $guardianData = ['name' => $guardianName, 'phone' => $guardianPhone, 'alt' => trim((string) ($input['guardian_alternate_phone'] ?? '')) ?: null, 'email' => trim((string) ($input['guardian_email'] ?? '')) ?: null, 'relationship' => trim((string) ($input['guardian_relationship'] ?? '')) ?: null, 'address' => trim((string) ($input['address'] ?? '')) ?: null];
        if ($guardianId) {
            $guardianUpdate = $guardianData;
            unset($guardianUpdate['phone']);
            $guardianUpdate['id'] = $guardianId;
            $pdo->prepare('UPDATE daycare_guardians SET full_name=:name,alternate_phone=:alt,email=:email,relationship=:relationship,address=:address WHERE id=:id')->execute($guardianUpdate);
        } else {
            $pdo->prepare('INSERT INTO daycare_guardians (full_name,phone,alternate_phone,email,relationship,address) VALUES (:name,:phone,:alt,:email,:relationship,:address)')->execute($guardianData);
            $guardianId = (int) $pdo->lastInsertId();
        }
        $pdo->prepare('UPDATE daycare_children SET admission_no=:admission,full_name=:name,date_of_birth=:dob,gender=:gender,admission_date=:admission_date,group_id=:group_id,primary_guardian_id=:guardian,fee_plan_id=:plan,emergency_contact_name=:emergency_name,emergency_contact_phone=:emergency_phone,address=:address,allergies=:allergies,medical_conditions=:conditions,special_care_instructions=:care,enrollment_status=:status WHERE id=:id')->execute([
            'admission' => $admissionNo, 'name' => $name, 'dob' => $dob,
            'gender' => trim((string) ($input['gender'] ?? '')) ?: null, 'admission_date' => $admissionDate,
            'group_id' => (int) ($input['group_id'] ?? 0) ?: null, 'guardian' => $guardianId,
            'plan' => (int) ($input['fee_plan_id'] ?? 0) ?: null,
            'emergency_name' => trim((string) ($input['emergency_contact_name'] ?? '')) ?: null,
            'emergency_phone' => trim((string) ($input['emergency_contact_phone'] ?? '')) ?: null,
            'address' => trim((string) ($input['address'] ?? '')) ?: null,
            'allergies' => trim((string) ($input['allergies'] ?? '')) ?: null,
            'conditions' => trim((string) ($input['medical_conditions'] ?? '')) ?: null,
            'care' => trim((string) ($input['special_care_instructions'] ?? '')) ?: null,
            'status' => $status, 'id' => $childId,
        ]);
        if (!empty($old['primary_guardian_id']) && (int) $old['primary_guardian_id'] !== $guardianId) {
            $pdo->prepare('UPDATE daycare_child_guardians SET is_primary=0 WHERE child_id=:child AND guardian_id=:old')->execute(['child' => $childId, 'old' => (int) $old['primary_guardian_id']]);
        }
        $pdo->prepare('INSERT INTO daycare_child_guardians (child_id,guardian_id,relationship,is_primary) VALUES (:child,:guardian,:relationship,1) ON DUPLICATE KEY UPDATE relationship=VALUES(relationship),is_primary=1')->execute(['child' => $childId, 'guardian' => $guardianId, 'relationship' => $guardianData['relationship']]);
        daycare_audit($pdo, 'CHILD_UPDATED', 'child', $childId, ['admission_no' => $admissionNo]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((string) $exception->getCode() === '23000') throw new RuntimeException('That admission number or guardian phone is already in use.');
        throw $exception;
    }
}

function daycare_child_balance(PDO $pdo, int $childId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE((SELECT SUM(amount - discount) FROM daycare_fee_charges WHERE child_id = :child AND status = 'Posted'), 0)
        - COALESCE((SELECT SUM(amount) FROM daycare_payments WHERE child_id = :child AND payment_status = 'Recorded'), 0)");
    $stmt->execute(['child' => $childId]);
    return (float) $stmt->fetchColumn();
}

function daycare_record_attendance(PDO $pdo, int $childId, string $action, array $input): void
{
    $date = trim((string) ($input['attendance_date'] ?? date('Y-m-d')));
    if (!daycare_valid_date($date) || $date > date('Y-m-d')) {
        throw new InvalidArgumentException('Select a valid attendance date that is not in the future.');
    }
    $pdo->beginTransaction();
    try {
        if ($action === 'check_in') {
            if ($date !== date('Y-m-d')) {
                throw new RuntimeException('Check-in must be recorded for today. Use the attendance report for historical review.');
            }
            $child = $pdo->prepare("SELECT id FROM daycare_children WHERE id = :id AND enrollment_status = 'Active' FOR UPDATE");
            $child->execute(['id' => $childId]);
            if (!$child->fetchColumn()) {
                throw new RuntimeException('Only active daycare children can be checked in.');
            }
            $existing = $pdo->prepare('SELECT id FROM daycare_attendance WHERE child_id = :child AND attendance_date = :date FOR UPDATE');
            $existing->execute(['child' => $childId, 'date' => $date]);
            if ($existing->fetchColumn()) {
                throw new RuntimeException('Attendance already exists for this child today.');
            }
            $pdo->prepare("INSERT INTO daycare_attendance (child_id, attendance_date, status, check_in_at, brought_by, received_by, notes) VALUES (:child, :date, 'Present', NOW(), :brought, :staff, :notes)")
                ->execute(['child' => $childId, 'date' => $date, 'brought' => trim((string) ($input['brought_by'] ?? '')) ?: null, 'staff' => (int) ($_SESSION['admin_id'] ?? 0) ?: null, 'notes' => trim((string) ($input['attendance_notes'] ?? '')) ?: null]);
            daycare_audit($pdo, 'CHILD_CHECKED_IN', 'child', $childId, ['date' => $date]);
        } elseif ($action === 'mark_absent') {
            $child = $pdo->prepare("SELECT id FROM daycare_children WHERE id = :id AND enrollment_status = 'Active' FOR UPDATE");
            $child->execute(['id' => $childId]);
            if (!$child->fetchColumn()) {
                throw new RuntimeException('Only active daycare children can be marked absent.');
            }
            $pdo->prepare("INSERT INTO daycare_attendance (child_id, attendance_date, status, notes, received_by) VALUES (:child, :date, 'Absent', :notes, :staff)")
                ->execute(['child' => $childId, 'date' => $date, 'notes' => trim((string) ($input['attendance_notes'] ?? '')) ?: null, 'staff' => (int) ($_SESSION['admin_id'] ?? 0) ?: null]);
            daycare_audit($pdo, 'CHILD_MARKED_ABSENT', 'child', $childId, ['date' => $date]);
        } elseif ($action === 'check_out') {
            if ($date !== date('Y-m-d')) {
                throw new RuntimeException('Check-out must be recorded for today.');
            }
            $pickupId = (int) ($input['pickup_person_id'] ?? 0);
            $pickup = $pdo->prepare("SELECT id, full_name FROM daycare_authorized_pickups WHERE id = :pickup AND child_id = :child AND authorization_status = 'Authorized' FOR UPDATE");
            $pickup->execute(['pickup' => $pickupId, 'child' => $childId]);
            $authorizedPerson = $pickup->fetch();
            if (!$authorizedPerson) {
                throw new RuntimeException('Checkout requires an active authorized pickup person for this child.');
            }
            $record = $pdo->prepare("SELECT id FROM daycare_attendance WHERE child_id = :child AND attendance_date = :date AND check_in_at IS NOT NULL AND check_out_at IS NULL FOR UPDATE");
            $record->execute(['child' => $childId, 'date' => $date]);
            $attendanceId = (int) $record->fetchColumn();
            if (!$attendanceId) {
                throw new RuntimeException('No open check-in was found for this child and date.');
            }
            $pdo->prepare('UPDATE daycare_attendance SET check_out_at = NOW(), pickup_person_id = :pickup, collected_by = :person, released_by = :staff WHERE id = :id')
                ->execute(['pickup' => $pickupId, 'person' => $authorizedPerson['full_name'], 'staff' => (int) ($_SESSION['admin_id'] ?? 0) ?: null, 'id' => $attendanceId]);
            daycare_audit($pdo, 'CHILD_CHECKED_OUT', 'child', $childId, ['date' => $date, 'pickup_person_id' => $pickupId]);
        } else {
            throw new InvalidArgumentException('Unknown attendance action.');
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((string) $exception->getCode() === '23000') {
            throw new RuntimeException('Attendance already exists for this child and date.');
        }
        throw $exception;
    }
}

function daycare_add_pickup(PDO $pdo, int $childId, array $input): int
{
    $name = trim((string) ($input['full_name'] ?? ''));
    $phone = trim((string) ($input['phone'] ?? ''));
    if ($name === '' || $phone === '') {
        throw new InvalidArgumentException('Pickup person name and phone are required.');
    }
    $stmt = $pdo->prepare("INSERT INTO daycare_authorized_pickups (child_id, full_name, relationship, phone, id_reference, restrictions, created_by) VALUES (:child, :name, :relationship, :phone, :idref, :restrictions, :admin)");
    $stmt->execute(['child' => $childId, 'name' => $name, 'relationship' => trim((string) ($input['relationship'] ?? '')) ?: null, 'phone' => $phone, 'idref' => trim((string) ($input['id_reference'] ?? '')) ?: null, 'restrictions' => trim((string) ($input['restrictions'] ?? '')) ?: null, 'admin' => (int) ($_SESSION['admin_id'] ?? 0) ?: null]);
    $id = (int) $pdo->lastInsertId();
    daycare_audit($pdo, 'PICKUP_AUTHORIZED', 'authorized_pickup', $id, ['child_id' => $childId]);
    return $id;
}

function daycare_add_charge(PDO $pdo, array $input): int
{
    $childId = (int) ($input['child_id'] ?? 0);
    $amount = round((float) ($input['amount'] ?? 0), 2);
    $discount = round((float) ($input['discount'] ?? 0), 2);
    $date = trim((string) ($input['charge_date'] ?? date('Y-m-d')));
    $description = trim((string) ($input['description'] ?? ''));
    if ($childId <= 0 || !daycare_valid_date($date) || $description === '' || $amount <= 0 || $discount < 0 || $discount > $amount) {
        throw new InvalidArgumentException('Enter a child, description, valid date, and a valid amount and discount.');
    }
    $stmt = $pdo->prepare("INSERT INTO daycare_fee_charges (child_id, charge_date, category, billing_period, description, amount, discount, created_by) VALUES (:child, :date, :category, :period, :description, :amount, :discount, :admin)");
    $stmt->execute(['child' => $childId, 'date' => $date, 'category' => trim((string) ($input['category'] ?? 'Daycare fees')) ?: 'Daycare fees', 'period' => trim((string) ($input['billing_period'] ?? '')) ?: null, 'description' => $description, 'amount' => $amount, 'discount' => $discount, 'admin' => (int) ($_SESSION['admin_id'] ?? 0) ?: null]);
    $id = (int) $pdo->lastInsertId();
    daycare_audit($pdo, 'CHARGE_POSTED', 'charge', $id, ['child_id' => $childId, 'net_amount' => $amount - $discount]);
    return $id;
}

function daycare_record_payment(PDO $pdo, array $input): int
{
    $childId = (int) ($input['child_id'] ?? 0);
    $amount = round((float) ($input['amount'] ?? 0), 2);
    $method = trim((string) ($input['payment_method'] ?? ''));
    $date = trim((string) ($input['payment_date'] ?? date('Y-m-d')));
    $reference = trim((string) ($input['payment_reference'] ?? '')) ?: null;
    if ($childId <= 0 || $amount <= 0 || $method === '' || !daycare_valid_date($date) || $date > date('Y-m-d')) {
        throw new InvalidArgumentException('Select a child, payment method, valid date, and an amount greater than zero.');
    }
    $allowedMethods = ['M-PESA', 'Cash', 'Bank Transfer', 'Cheque', 'Other'];
    if (!in_array($method, $allowedMethods, true)) {
        throw new InvalidArgumentException('Select a supported payment method.');
    }
    if ($method === 'M-PESA' && $reference === null) {
        throw new InvalidArgumentException('Enter the M-PESA transaction code.');
    }
    if ($method === 'M-PESA') {
        $reference = normalize_payment_code((string) $reference);
        $referenceLock = fee_acquire_payment_reference_lock($pdo, $reference);
        if (payment_code_duplicate_exists($pdo, $reference, 'daycare_reference')) {
            fee_release_payment_reference_lock($pdo, $referenceLock);
            throw new RuntimeException('That M-PESA transaction has already been recorded. Check the existing receipt before retrying.');
        }
    } else {
        $referenceLock = null;
    }

    $pdo->beginTransaction();
    try {
        $child = $pdo->prepare('SELECT id, primary_guardian_id FROM daycare_children WHERE id = :id FOR UPDATE');
        $child->execute(['id' => $childId]);
        $childRow = $child->fetch();
        if (!$childRow) {
            throw new RuntimeException('The selected daycare child no longer exists.');
        }
        $receiptNo = daycare_allocate_number($pdo, 'daycare_receipt_sequences', 'receipt_year', (int) date('Y', strtotime($date)), 'ATXD');
        $insert = $pdo->prepare("INSERT INTO daycare_payments (child_id, guardian_id, receipt_no, payment_date, amount, payment_method, payment_reference, category, billing_period, notes, recorded_by) VALUES (:child, :guardian, :receipt, :date, :amount, :method, :reference, :category, :period, :notes, :admin)");
        $insert->execute(['child' => $childId, 'guardian' => $childRow['primary_guardian_id'], 'receipt' => $receiptNo, 'date' => $date . ' ' . date('H:i:s'), 'amount' => $amount, 'method' => $method, 'reference' => $reference, 'category' => trim((string) ($input['category'] ?? 'Daycare fees')) ?: 'Daycare fees', 'period' => trim((string) ($input['billing_period'] ?? '')) ?: null, 'notes' => trim((string) ($input['notes'] ?? '')) ?: null, 'admin' => (int) ($_SESSION['admin_id'] ?? 0) ?: null]);
        $paymentId = (int) $pdo->lastInsertId();

        $openCharges = $pdo->prepare("SELECT c.id, GREATEST(c.amount - c.discount - COALESCE(SUM(CASE WHEN p.payment_status = 'Recorded' THEN a.amount ELSE 0 END), 0), 0) AS due
            FROM daycare_fee_charges c
            LEFT JOIN daycare_payment_allocations a ON a.charge_id = c.id
            LEFT JOIN daycare_payments p ON p.id = a.payment_id
            WHERE c.child_id = :child AND c.status = 'Posted'
            GROUP BY c.id, c.charge_date, c.created_at, c.amount, c.discount
            HAVING due > 0 ORDER BY c.charge_date, c.id FOR UPDATE");
        $openCharges->execute(['child' => $childId]);
        $remaining = $amount;
        $allocate = $pdo->prepare('INSERT INTO daycare_payment_allocations (payment_id, charge_id, amount) VALUES (:payment, :charge, :amount)');
        foreach ($openCharges->fetchAll() as $charge) {
            if ($remaining <= 0.0001) {
                break;
            }
            $portion = min($remaining, (float) $charge['due']);
            if ($portion > 0) {
                $allocate->execute(['payment' => $paymentId, 'charge' => (int) $charge['id'], 'amount' => $portion]);
                $remaining = round($remaining - $portion, 2);
            }
        }
        daycare_audit($pdo, 'PAYMENT_RECORDED', 'payment', $paymentId, ['receipt_no' => $receiptNo, 'amount' => $amount, 'method' => $method]);
        payment_transaction_attach_legacy($pdo, 'daycare', $paymentId, [
            'total_amount' => $amount,
            'payment_method' => $method,
            'mpesa_code' => $method === 'M-PESA' ? (string) $reference : '',
            'payment_date' => $date,
            'daycare_child_id' => $childId,
            'payment_category' => trim((string) ($input['category'] ?? 'Daycare fees')) ?: 'Daycare fees',
            'academic_year' => (string) (current_academic_context($pdo)['academic_year'] ?? ''),
            'term' => (string) (current_academic_context($pdo)['term'] ?? ''),
            'recorded_by' => (int) ($_SESSION['admin_id'] ?? 0),
        ]);
        $pdo->commit();
        if ($referenceLock !== null) fee_release_payment_reference_lock($pdo, $referenceLock);
        return $paymentId;
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($referenceLock !== null) fee_release_payment_reference_lock($pdo, $referenceLock);
        if ((string) $exception->getCode() === '23000') {
            throw new RuntimeException('This payment reference or receipt has already been used. Verify the transaction before retrying.');
        }
        throw $exception;
    }
}

function daycare_reverse_payment(PDO $pdo, int $paymentId, string $reason): void
{
    $reason = trim($reason);
    if ($reason === '') {
        throw new InvalidArgumentException('A reason is required to reverse a daycare payment.');
    }
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT id, payment_status, receipt_no FROM daycare_payments WHERE id = :id FOR UPDATE");
        $stmt->execute(['id' => $paymentId]);
        $payment = $stmt->fetch();
        if (!$payment || $payment['payment_status'] !== 'Recorded') {
            throw new RuntimeException('Payment not found or already reversed.');
        }
        $pdo->prepare("UPDATE daycare_payments SET payment_status = 'Reversed', reversed_at = NOW(), reversed_by = :admin, reversal_reason = :reason WHERE id = :id")
            ->execute(['admin' => (int) ($_SESSION['admin_id'] ?? 0) ?: null, 'reason' => $reason, 'id' => $paymentId]);
        payment_transaction_sync_legacy_status($pdo, 'daycare', $paymentId, 'Reversed');
        daycare_audit($pdo, 'PAYMENT_REVERSED', 'payment', $paymentId, ['receipt_no' => $payment['receipt_no'], 'reason' => $reason]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function daycare_add_care_record(PDO $pdo, array $input): int
{
    $childId = (int) ($input['child_id'] ?? 0);
    $date = trim((string) ($input['record_date'] ?? date('Y-m-d')));
    $type = trim((string) ($input['record_type'] ?? 'Observation'));
    $details = trim((string) ($input['details'] ?? ''));
    $types = ['Nap', 'Activity', 'Observation', 'Meal', 'Toileting', 'Health', 'Incident', 'Other'];
    if ($childId <= 0 || !daycare_valid_date($date) || !in_array($type, $types, true) || $details === '') {
        throw new InvalidArgumentException('Choose a child, date, care type, and enter the observation details.');
    }
    $stmt = $pdo->prepare('INSERT INTO daycare_care_records (child_id, record_date, record_type, details, recorded_by) VALUES (:child, :date, :type, :details, :admin)');
    $stmt->execute(['child' => $childId, 'date' => $date, 'type' => $type, 'details' => $details, 'admin' => (int) ($_SESSION['admin_id'] ?? 0) ?: null]);
    $id = (int) $pdo->lastInsertId();
    daycare_audit($pdo, 'CARE_RECORDED', 'care_record', $id, ['child_id' => $childId, 'type' => $type]);
    return $id;
}

function daycare_dashboard_data(PDO $pdo, string $from, string $to, string $grain = 'daily'): array
{
    if (!in_array($grain, ['daily', 'weekly', 'monthly'], true)) $grain = 'daily';
    $count = $pdo->prepare("SELECT
        (SELECT COUNT(*) FROM daycare_children WHERE enrollment_status = 'Active') AS children,
        (SELECT COALESCE(SUM(amount),0) FROM daycare_payments WHERE payment_status='Recorded' AND DATE(payment_date) BETWEEN :from_date AND :to_date) AS collected");
    $count->execute(['from_date' => $from, 'to_date' => $to]);

    $groupStmt = $pdo->query("SELECT g.id,g.name,COUNT(DISTINCT c.id) AS children FROM daycare_groups g LEFT JOIN daycare_children c ON c.group_id=g.id AND c.enrollment_status='Active' WHERE g.status='Active' GROUP BY g.id,g.name ORDER BY g.name");
    $paymentStmt = $pdo->prepare("SELECT p.*, c.full_name, c.admission_no FROM daycare_payments p JOIN daycare_children c ON c.id=p.child_id WHERE p.payment_status='Recorded' AND DATE(p.payment_date) BETWEEN :from_date AND :to_date ORDER BY p.payment_date DESC LIMIT 8");
    $paymentStmt->execute(['from_date' => $from, 'to_date' => $to]);
    $methods = $pdo->prepare("SELECT payment_method, SUM(amount) AS total FROM daycare_payments WHERE payment_status='Recorded' AND DATE(payment_date) BETWEEN :from_date AND :to_date GROUP BY payment_method ORDER BY total DESC");
    $methods->execute(['from_date' => $from, 'to_date' => $to]);
    $chartGroup = match ($grain) {
        'weekly' => 'YEARWEEK(payment_date,1)',
        'monthly' => "DATE_FORMAT(payment_date,'%Y-%m')",
        default => 'DATE(payment_date)',
    };
    $chartDate = $grain === 'weekly' ? 'MIN(DATE(payment_date))' : $chartGroup;
    $chart = $pdo->prepare("SELECT {$chartDate} AS day, SUM(amount) AS total FROM daycare_payments WHERE payment_status='Recorded' AND DATE(payment_date) BETWEEN :from_date AND :to_date GROUP BY {$chartGroup} ORDER BY {$chartGroup}");
    $chart->execute(['from_date' => $from, 'to_date' => $to]);
    $activity = $pdo->query("SELECT action,entity_type,entity_id,details,created_at FROM daycare_audit_log WHERE action IN ('PAYMENT_RECORDED','PAYMENT_REVERSED') ORDER BY created_at DESC LIMIT 8")->fetchAll();
    return ['summary' => $count->fetch(), 'groups' => $groupStmt->fetchAll(), 'payments' => $paymentStmt->fetchAll(), 'methods' => $methods->fetchAll(), 'chart' => $chart->fetchAll(), 'activity' => $activity];
}

