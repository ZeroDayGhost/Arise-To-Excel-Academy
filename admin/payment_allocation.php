<?php
$pageTitle = 'Combined Payment';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/payment_transactions.php';
require_once __DIR__ . '/../includes/activities_helpers.php';
require_once __DIR__ . '/../includes/daycare_helpers.php';
require_once __DIR__ . '/../includes/fee_receipt_helpers.php';

$actorId = (int) ($_SESSION['admin_id'] ?? 0);
$canFees = current_admin_has_permission($pdo, 'fees.record');
$canActivities = current_admin_has_permission($pdo, 'activities.payments.collect');
$canDaycare = current_admin_has_permission($pdo, 'daycare.payments.record');
$canTransport = current_admin_has_permission($pdo, 'transport.save');
$canFeeding = current_admin_has_permission($pdo, 'feeding.record_payment');
$canUniform = current_admin_has_permission($pdo, 'school_uniform.pay_balance');
$allowedCategories = [];
if ($canFees) $allowedCategories['fees'] = 'School fees';
if ($canActivities) $allowedCategories['activity'] = 'Activity / trip';
if ($canDaycare) $allowedCategories['daycare'] = 'Daycare';
if ($canTransport) $allowedCategories['transport'] = 'Transport';
if ($canUniform) $allowedCategories['uniform'] = 'School uniform';
if ($canFeeding) $allowedCategories['feeding'] = 'Feeding';
if (!$allowedCategories) {
    http_response_code(403);
    exit('You do not have permission to record payments.');
}

$context = current_academic_context($pdo);
$year = (string) $context['academic_year'];
$term = (string) $context['term'];
if ($canFees) sync_current_term_fee_balances($pdo);

$students = [];
if ($canFees) {
    $studentClass = student_class_sql_for_year('s', $year);
    $sponsorshipFilter = student_fee_not_sponsored_sql('s', ':sponsorship_year');
    $stmt = $pdo->prepare("SELECT s.id,s.registration_no,s.full_name,{$studentClass} AS class_level,
        COALESCE(b.required_amount,0) AS required_amount,COALESCE(b.paid_amount,0) AS paid_amount,COALESCE(b.balance,0) AS balance
        FROM students s LEFT JOIN fee_balances b ON b.student_id=s.id AND b.academic_year=:year AND b.term=:term
        WHERE s.enrollment_status='Active' AND {$sponsorshipFilter} ORDER BY s.full_name");
    $stmt->execute(['year' => $year, 'term' => $term, 'sponsorship_year' => $year]);
    $students = $stmt->fetchAll();
}

$activityParticipants = [];
if ($canActivities) {
    $stmt = $pdo->query("SELECT ap.id AS participant_id,ap.activity_id,ap.student_id,ap.expected_amount,a.name AS activity_name,a.activity_type,a.academic_year,a.term,
        s.full_name,s.registration_no,
        COALESCE((SELECT SUM(p.amount) FROM activity_payments p WHERE p.participant_id=ap.id AND p.status='Recorded'),0) AS amount_paid
        FROM activity_participants ap JOIN activities a ON a.id=ap.activity_id JOIN students s ON s.id=ap.student_id
        WHERE a.status='Active' AND ap.participation_status='Active' ORDER BY a.activity_date DESC,s.full_name");
    $activityParticipants = $stmt->fetchAll();
}

$daycareChildren = $canDaycare
    ? $pdo->query("SELECT c.id,c.admission_no,c.full_name,c.primary_guardian_id,
        GREATEST(COALESCE((SELECT SUM(ch.amount-ch.discount) FROM daycare_fee_charges ch WHERE ch.child_id=c.id AND ch.status='Posted'),0)-
        COALESCE((SELECT SUM(p.amount) FROM daycare_payments p WHERE p.child_id=c.id AND p.payment_status='Recorded'),0),0) AS balance
        FROM daycare_children c WHERE c.enrollment_status='Active' ORDER BY c.full_name")->fetchAll()
    : [];
$transportAccounts = [];
if ($canTransport) {
    $stmt = $pdo->prepare("SELECT ta.id AS account_id,ts.student_id,ts.student_name,ts.is_outside,
        COALESCE(s.registration_no, '') AS registration_no,COALESCE(s.full_name,ts.student_name) AS full_name,
        COALESCE(s.class_level,'Outside transport student') AS class_level,ts.pickup_location,
        GREATEST(ta.amount_due-COALESCE((SELECT SUM(p.amount_paid) FROM transport_payments p WHERE p.transport_account_id=ta.id),0),0) AS balance
        FROM transport_accounts ta JOIN transport_students ts ON ts.id=ta.transport_student_id
        LEFT JOIN students s ON s.id=ts.student_id
        WHERE ta.status='Active' AND ta.academic_year=:year AND ta.term=:term
          AND (ts.is_outside=1 OR (ts.student_id IS NOT NULL AND s.enrollment_status='Active'))
        HAVING balance>0 ORDER BY full_name");
    $stmt->execute(['year' => $year, 'term' => $term]);
    $transportAccounts = $stmt->fetchAll();
}
$feedingSubscriptions = [];
if ($canFeeding) {
    $stmt = $pdo->prepare("SELECT fs.id AS subscription_id,fs.student_id,s.full_name,s.registration_no,s.class_level,fs.academic_year,fs.term,
        GREATEST(fs.feeding_amount-COALESCE((SELECT SUM(p.amount_paid) FROM feeding_payments p WHERE p.feeding_subscription_id=fs.id),0),0) AS balance
        FROM feeding_subscriptions fs JOIN students s ON s.id=fs.student_id
        WHERE fs.status='Active' AND fs.academic_year=:year AND fs.term=:term AND s.enrollment_status='Active'
        HAVING balance>0 ORDER BY s.full_name");
    $stmt->execute(['year' => $year, 'term' => $term]);
    $feedingSubscriptions = $stmt->fetchAll();
}
$uniformSales = $canUniform
    ? $pdo->query("SELECT us.id AS sale_id,us.receipt_no,us.student_id,us.balance,s.full_name,s.registration_no,s.class_level
        FROM uniform_sales us JOIN students s ON s.id=us.student_id
        WHERE us.balance>0.005 AND s.enrollment_status='Active' ORDER BY us.payment_date DESC,us.id DESC")->fetchAll()
    : [];
$parents = payment_transaction_payer_options($pdo);

$errors = [];
$old = $_POST;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $method = (string) ($_POST['payment_method'] ?? '');
    $date = trim((string) ($_POST['payment_date'] ?? ''));
    $amount = round((float) ($_POST['total_amount'] ?? 0), 2);
    $code = normalize_payment_code((string) ($_POST['mpesa_code'] ?? ''));
    $payerKey = trim((string) ($_POST['payer_key'] ?? ''));
    $parentId = 0;
    $guardianId = 0;
    $payerName = null;
    $rows = is_array($_POST['allocations'] ?? null) ? array_values($_POST['allocations']) : [];
    $requestKey = (string) ($_POST['request_key'] ?? '');
    $today = date('Y-m-d');
    $validDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    $cents = 0;
    foreach ($rows as $row) $cents += (int) round((float) ($row['amount'] ?? 0) * 100);

    if (!in_array($method, ['M-PESA', 'Cash'], true)) $errors[] = 'Choose M-Pesa or Cash.';
    if (!$validDate || $validDate->format('Y-m-d') !== $date || $date > $today) $errors[] = 'Enter a valid payment date that is not in the future.';
    if ($amount <= 0 || $amount > 9999999999.99) $errors[] = 'Enter the total amount received.';
    if ($method === 'M-PESA' && !preg_match('/^[A-Z0-9]{8,20}$/', $code)) $errors[] = 'Enter a valid M-Pesa transaction code (8 to 20 letters or numbers).';
    if ($method !== 'M-PESA') $code = '';
    if (!preg_match('/^[a-f0-9]{64}$/', $requestKey)) $errors[] = 'Refresh the form and try again.';
    if ($payerKey !== '') {
        $payer = null;
        foreach ($parents as $candidate) if ($candidate['payer_key'] === $payerKey) { $payer = $candidate; break; }
        if ($payerKey === 'manual') {
            $payerName = trim((string) ($_POST['payer_name'] ?? ''));
            if (mb_strlen($payerName) < 2 || mb_strlen($payerName) > 150) $errors[] = 'Enter a parent or guardian name between 2 and 150 characters.';
        } elseif (!$payer) $errors[] = 'Choose a valid parent or guardian.';
        else {
            $payerName = (string) $payer['payer_name'];
            if (str_starts_with($payerKey, 'parent:')) $parentId = (int) substr($payerKey, 7);
            elseif (str_starts_with($payerKey, 'guardian:')) $guardianId = (int) substr($payerKey, 9);
        }
    }
    if (!$rows || count($rows) > 30) $errors[] = 'Add between one and thirty student allocations.';
    if ($amount > 0 && $cents !== (int) round($amount * 100)) $errors[] = 'The allocated amounts must equal the total amount received.';

    $targets = [];
    foreach ($rows as $index => $row) {
        $category = (string) ($row['category'] ?? '');
        $rowAmount = round((float) ($row['amount'] ?? 0), 2);
        if (!isset($allowedCategories[$category])) $errors[] = 'Allocation ' . ($index + 1) . ': you do not have permission for that payment type.';
        if ($rowAmount <= 0) $errors[] = 'Allocation ' . ($index + 1) . ': enter an amount greater than zero.';
        $target = match ($category) {
            'fees' => 'fees:' . (int) ($row['student_id'] ?? 0),
            'activity' => 'activity:' . (int) ($row['participant_id'] ?? 0),
            'daycare' => 'daycare:' . (int) ($row['daycare_child_id'] ?? 0),
            'transport' => 'transport:' . (int) ($row['transport_account_id'] ?? 0),
            'uniform' => 'uniform:' . (int) ($row['uniform_sale_id'] ?? 0),
            'feeding' => 'feeding:' . (int) ($row['feeding_subscription_id'] ?? 0),
            default => 'invalid:' . $index,
        };
        if (str_ends_with($target, ':0') || isset($targets[$target])) $errors[] = 'Choose a different valid student or child for each allocation.';
        $targets[$target] = true;
    }

    $referenceLock = null;
    if (!$errors && $method === 'M-PESA') {
        try {
            $referenceLock = fee_acquire_payment_reference_lock($pdo, $code);
            if (payment_code_duplicate_exists($pdo, $code, 'fee_mpesa_code')) $errors[] = 'This M-Pesa transaction has already been recorded. Check the existing receipt before retrying.';
        } catch (Throwable $exception) {
            $errors[] = $exception->getMessage();
        }
    }

    if (!$errors) {
        $hasFees = count(array_filter($rows, static fn($row) => ($row['category'] ?? '') === 'fees')) > 0;
        if ($hasFees) {
            try {
                fee_prepare_receipt_sequence($pdo, new DateTimeImmutable('now'));
            } catch (Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }
        if (!$errors) {
            try {
            $pdo->beginTransaction();
            $transactionId = payment_transaction_insert($pdo, [
                'total_amount' => $amount, 'payment_method' => $method, 'mpesa_code' => $code,
                'payment_date' => $date, 'parent_user_id' => $parentId, 'daycare_guardian_id' => $guardianId,
                'payer_name' => $payerName, 'recorded_by' => $actorId,
                'request_key' => $requestKey,
            ]);
            $transactionReceipt = (string) $pdo->query('SELECT receipt_no FROM payment_transactions WHERE id=' . $transactionId)->fetchColumn();
            foreach ($rows as $row) {
                $category = (string) $row['category'];
                $part = round((float) $row['amount'], 2);
                if ($category === 'fees') {
                    $studentId = (int) ($row['student_id'] ?? 0);
                    $lock = $pdo->prepare('SELECT * FROM students WHERE id=:id FOR UPDATE');
                    $lock->execute(['id' => $studentId]);
                    $student = $lock->fetch();
                    if (!$student || $student['enrollment_status'] !== 'Active') throw new RuntimeException('A selected fee student is no longer active. Refresh the payment form.');
                    if (student_fee_sponsorship_status($pdo, $studentId, $year) === 'Sponsored Student') throw new RuntimeException('This student is sponsored for ' . $year . ' and cannot receive a school-fee allocation.');
                    $class = student_class_for_year($pdo, $studentId, $year, (string) $student['class_level']);
                    if (!get_fee_structure($pdo, $class, $year)) throw new RuntimeException('No fee structure is set for ' . $class . ' in ' . $year . '.');
                    sync_fee_balance_for_student($pdo, $studentId, $class, $year, $term);
                    $balance = get_fee_balance($pdo, $studentId, $year, $term, true);
                    $due = (float) ($balance['balance'] ?? 0);
                    if (!$balance || $part > $due + 0.005) throw new RuntimeException('A school-fee allocation exceeds the student\'s current term balance.');
                    $balanceAfter = max($due - $part, 0);
                    $receipt = fee_allocate_receipt_number($pdo, new DateTimeImmutable('now'));
                    $insert = $pdo->prepare("INSERT INTO fees (student_id,receipt_no,amount_paid,mpesa_code,mpesa_reference_text,payment_method,term,year,balance_after_payment,payment_date,payment_status)
                        VALUES (:student,:receipt,:amount,'',:ref,:method,:term,:year,:balance,:date,'Recorded')");
                    $insert->execute(['student' => $studentId, 'receipt' => $receipt, 'amount' => $part, 'ref' => 'Combined payment ' . $transactionReceipt, 'method' => $method, 'term' => $term, 'year' => $year, 'balance' => $balanceAfter, 'date' => $date]);
                    $feeId = (int) $pdo->lastInsertId();
                    $pdo->prepare('UPDATE fee_balances SET paid_amount=:paid,balance=:balance WHERE id=:id')->execute(['paid' => (float) $balance['paid_amount'] + $part, 'balance' => $balanceAfter, 'id' => (int) $balance['id']]);
                    fee_log_payment_audit($pdo, $feeId, $studentId, $receipt, 'RECORDED', $actorId, null, ['amount_paid' => $part, 'payment_method' => $method, 'transaction_reference' => $transactionReceipt, 'combined_receipt' => $transactionReceipt, 'term' => $term, 'academic_year' => $year, 'payment_date' => $date]);
                    payment_transaction_attach_allocation($pdo, ['transaction_id' => $transactionId, 'source_module' => 'fees', 'source_payment_id' => $feeId, 'student_id' => $studentId, 'payment_category' => 'School fees', 'amount' => $part, 'academic_year' => $year, 'term' => $term]);
                } elseif ($category === 'activity') {
                    $participantId = (int) ($row['participant_id'] ?? 0);
                    $lock = $pdo->prepare("SELECT ap.*,a.name,a.activity_type,a.academic_year,a.term,a.status AS activity_status,s.id AS linked_student_id
                        FROM activity_participants ap JOIN activities a ON a.id=ap.activity_id JOIN students s ON s.id=ap.student_id
                        WHERE ap.id=:id FOR UPDATE");
                    $lock->execute(['id' => $participantId]);
                    $participant = $lock->fetch();
                    if (!$participant || $participant['activity_status'] !== 'Active' || $participant['participation_status'] !== 'Active') throw new RuntimeException('A selected activity participant is no longer active.');
                    $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM activity_payments WHERE participant_id=:id AND status='Recorded'");
                    $paidStmt->execute(['id' => $participantId]);
                    $due = max(0, (float) $participant['expected_amount'] - (float) $paidStmt->fetchColumn());
                    if ($part > $due + 0.005) throw new RuntimeException('An activity allocation exceeds the remaining amount due for that student.');
                    $request = bin2hex(random_bytes(32));
                    $receipt = 'PENDING-' . strtoupper(bin2hex(random_bytes(10)));
                    $insert = $pdo->prepare('INSERT INTO activity_payments (activity_id,participant_id,receipt_no,request_key,amount,payment_date,academic_year,term,payment_method,reference_no,recorded_by) VALUES (:activity,:participant,:receipt,:request,:amount,:date,:year,:term,:method,:reference,:actor)');
                    $insert->execute(['activity' => (int) $participant['activity_id'], 'participant' => $participantId, 'receipt' => $receipt, 'request' => $request, 'amount' => $part, 'date' => $date, 'year' => $participant['academic_year'], 'term' => $participant['term'], 'method' => $method, 'reference' => $transactionReceipt, 'actor' => $actorId]);
                    $activityPaymentId = (int) $pdo->lastInsertId();
                    $receipt = sprintf('ATXACT-%04d-%06d', (int) $participant['academic_year'], $activityPaymentId);
                    $pdo->prepare('UPDATE activity_payments SET receipt_no=:receipt WHERE id=:id')->execute(['receipt' => $receipt, 'id' => $activityPaymentId]);
                    activity_audit($pdo, (int) $participant['activity_id'], 'payment_recorded', ['receipt_no' => $receipt, 'amount' => $part, 'payment_method' => $method, 'combined_receipt' => $transactionReceipt], $activityPaymentId);
                    payment_transaction_attach_allocation($pdo, ['transaction_id' => $transactionId, 'source_module' => 'activity', 'source_payment_id' => $activityPaymentId, 'student_id' => (int) $participant['student_id'], 'payment_category' => $participant['activity_type'] . ': ' . $participant['name'], 'amount' => $part, 'academic_year' => $participant['academic_year'], 'term' => $participant['term']]);
                } elseif ($category === 'daycare') {
                    $childId = (int) ($row['daycare_child_id'] ?? 0);
                    $lock = $pdo->prepare("SELECT id,primary_guardian_id FROM daycare_children WHERE id=:id AND enrollment_status='Active' FOR UPDATE");
                    $lock->execute(['id' => $childId]);
                    $child = $lock->fetch();
                    if (!$child) throw new RuntimeException('A selected daycare child is no longer active.');
                    $receipt = daycare_allocate_number($pdo, 'daycare_receipt_sequences', 'receipt_year', (int) date('Y', strtotime($date)), 'ATXD');
                    $insert = $pdo->prepare('INSERT INTO daycare_payments (child_id,guardian_id,receipt_no,payment_date,amount,payment_method,payment_reference,category,billing_period,notes,recorded_by) VALUES (:child,:guardian,:receipt,:date,:amount,:method,:reference,:category,:period,:notes,:actor)');
                    $insert->execute(['child' => $childId, 'guardian' => $child['primary_guardian_id'], 'receipt' => $receipt, 'date' => $date . ' ' . date('H:i:s'), 'amount' => $part, 'method' => $method, 'reference' => $transactionReceipt, 'category' => 'Daycare fees', 'period' => $year . ' - ' . $term, 'notes' => 'Combined payment ' . $transactionReceipt, 'actor' => $actorId]);
                    $daycarePaymentId = (int) $pdo->lastInsertId();
                    $charges = $pdo->prepare("SELECT c.id,GREATEST(c.amount-c.discount-COALESCE(SUM(CASE WHEN p.payment_status='Recorded' THEN a.amount ELSE 0 END),0),0) AS due
                        FROM daycare_fee_charges c LEFT JOIN daycare_payment_allocations a ON a.charge_id=c.id LEFT JOIN daycare_payments p ON p.id=a.payment_id
                        WHERE c.child_id=:child AND c.status='Posted' GROUP BY c.id,c.charge_date,c.created_at,c.amount,c.discount HAVING due>0 ORDER BY c.charge_date,c.id FOR UPDATE");
                    $charges->execute(['child' => $childId]);
                    $remaining = $part;
                    $allocate = $pdo->prepare('INSERT INTO daycare_payment_allocations (payment_id,charge_id,amount) VALUES (:payment,:charge,:amount)');
                    foreach ($charges->fetchAll() as $charge) {
                        if ($remaining <= 0.0001) break;
                        $portion = min($remaining, (float) $charge['due']);
                        if ($portion > 0) {
                            $allocate->execute(['payment' => $daycarePaymentId, 'charge' => (int) $charge['id'], 'amount' => $portion]);
                            $remaining = round($remaining - $portion, 2);
                        }
                    }
                    daycare_audit($pdo, 'PAYMENT_RECORDED', 'payment', $daycarePaymentId, ['receipt_no' => $receipt, 'amount' => $part, 'method' => $method, 'combined_receipt' => $transactionReceipt]);
                    payment_transaction_attach_allocation($pdo, ['transaction_id' => $transactionId, 'source_module' => 'daycare', 'source_payment_id' => $daycarePaymentId, 'daycare_child_id' => $childId, 'payment_category' => 'Daycare fees', 'amount' => $part, 'academic_year' => $year, 'term' => $term]);
                } elseif ($category === 'transport') {
                    $accountId = (int) ($row['transport_account_id'] ?? 0);
                    $lock = $pdo->prepare("SELECT id,amount_due FROM transport_accounts WHERE id=:id AND academic_year=:year AND term=:term AND status='Active' FOR UPDATE");
                    $lock->execute(['id' => $accountId, 'year' => $year, 'term' => $term]);
                    $account = $lock->fetch();
                    $studentStmt = $pdo->prepare("SELECT ts.student_id,ts.is_outside,s.enrollment_status FROM transport_accounts ta
                        JOIN transport_students ts ON ts.id=ta.transport_student_id LEFT JOIN students s ON s.id=ts.student_id WHERE ta.id=:id");
                    $studentStmt->execute(['id' => $accountId]);
                    $transportStudent = $studentStmt->fetch();
                    $outsideAccount = $transportStudent && (int) ($transportStudent['is_outside'] ?? 0) === 1;
                    $linkedStudentIsActive = $transportStudent
                        && (int) ($transportStudent['student_id'] ?? 0) > 0
                        && ($transportStudent['enrollment_status'] ?? '') === 'Active';
                    if (!$account || !$transportStudent || (!$outsideAccount && !$linkedStudentIsActive)) {
                        throw new RuntimeException('The selected transport account is no longer available to this student.');
                    }
                    $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM transport_payments WHERE transport_account_id=:id');
                    $paidStmt->execute(['id' => $accountId]);
                    $due = max(0, (float) $account['amount_due'] - (float) $paidStmt->fetchColumn());
                    if ($part > $due + 0.005) throw new RuntimeException('A transport allocation exceeds the remaining transport balance.');
                    $insert = $pdo->prepare('INSERT INTO transport_payments (transport_account_id,amount_paid,payment_date,reference_no) VALUES (:account,:amount,:date,:reference)');
                    $insert->execute(['account' => $accountId, 'amount' => $part, 'date' => $date, 'reference' => $transactionReceipt]);
                    payment_transaction_attach_allocation($pdo, ['transaction_id' => $transactionId, 'source_module' => 'transport', 'source_payment_id' => (int) $pdo->lastInsertId(), 'student_id' => (int) ($transportStudent['student_id'] ?? 0), 'payment_category' => 'Transport', 'amount' => $part, 'academic_year' => $year, 'term' => $term]);
                } elseif ($category === 'feeding') {
                    $subscriptionId = (int) ($row['feeding_subscription_id'] ?? 0);
                    $lock = $pdo->prepare("SELECT fs.id,fs.student_id,fs.feeding_amount,s.enrollment_status FROM feeding_subscriptions fs
                        JOIN students s ON s.id=fs.student_id WHERE fs.id=:id AND fs.academic_year=:year AND fs.term=:term AND fs.status='Active' FOR UPDATE");
                    $lock->execute(['id' => $subscriptionId, 'year' => $year, 'term' => $term]);
                    $subscription = $lock->fetch();
                    if (!$subscription || $subscription['enrollment_status'] !== 'Active') throw new RuntimeException('The selected feeding account is no longer active.');
                    $paidStmt = $pdo->prepare('SELECT COALESCE(SUM(amount_paid),0) FROM feeding_payments WHERE feeding_subscription_id=:id');
                    $paidStmt->execute(['id' => $subscriptionId]);
                    $due = max(0, (float) $subscription['feeding_amount'] - (float) $paidStmt->fetchColumn());
                    if ($part > $due + 0.005) throw new RuntimeException('A feeding allocation exceeds the remaining feeding balance.');
                    $insert = $pdo->prepare('INSERT INTO feeding_payments (feeding_subscription_id,amount_paid,payment_date,reference_no) VALUES (:subscription,:amount,:date,:reference)');
                    $insert->execute(['subscription' => $subscriptionId, 'amount' => $part, 'date' => $date, 'reference' => $transactionReceipt]);
                    payment_transaction_attach_allocation($pdo, ['transaction_id' => $transactionId, 'source_module' => 'feeding', 'source_payment_id' => (int) $pdo->lastInsertId(), 'student_id' => (int) $subscription['student_id'], 'payment_category' => 'Feeding', 'amount' => $part, 'academic_year' => $year, 'term' => $term]);
                } else {
                    $saleId = (int) ($row['uniform_sale_id'] ?? 0);
                    $lock = $pdo->prepare("SELECT us.id,us.student_id,us.grand_total,us.amount_paid,us.balance,s.enrollment_status
                        FROM uniform_sales us JOIN students s ON s.id=us.student_id WHERE us.id=:id FOR UPDATE");
                    $lock->execute(['id' => $saleId]);
                    $sale = $lock->fetch();
                    if (!$sale || $sale['enrollment_status'] !== 'Active') throw new RuntimeException('The selected uniform sale is no longer available to this student.');
                    $due = min((float) $sale['balance'], max(0, (float) $sale['grand_total'] - (float) $sale['amount_paid']));
                    if ($part > $due + 0.005) throw new RuntimeException('A uniform allocation exceeds the remaining uniform-sale balance.');
                    $newPaid = min((float) $sale['grand_total'], (float) $sale['amount_paid'] + $part);
                    $newBalance = max(0, (float) $sale['grand_total'] - $newPaid);
                    $insert = $pdo->prepare('INSERT INTO uniform_sale_payments (sale_id,amount_paid,payment_date,payment_method,reference_no,academic_year,term,recorded_by) VALUES (:sale,:amount,:date,:method,:reference,:year,:term,:actor)');
                    $insert->execute(['sale' => $saleId, 'amount' => $part, 'date' => $date, 'method' => $method, 'reference' => $transactionReceipt, 'year' => $year, 'term' => $term, 'actor' => $actorId]);
                    $uniformPaymentId = (int) $pdo->lastInsertId();
                    $pdo->prepare('UPDATE uniform_sales SET amount_paid=:paid,balance=:balance WHERE id=:id')->execute(['paid' => $newPaid, 'balance' => $newBalance, 'id' => $saleId]);
                    payment_transaction_attach_allocation($pdo, ['transaction_id' => $transactionId, 'source_module' => 'uniform', 'source_payment_id' => $uniformPaymentId, 'student_id' => (int) $sale['student_id'], 'payment_category' => 'School uniform', 'amount' => $part, 'academic_year' => $year, 'term' => $term]);
                }
            }
            $pdo->commit();
            if ($referenceLock !== null) fee_release_payment_reference_lock($pdo, $referenceLock);
            redirect('admin/payment_transaction_receipt.php?id=' . $transactionId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($referenceLock !== null) fee_release_payment_reference_lock($pdo, $referenceLock);
            $referenceLock = null;
            $errors[] = $exception instanceof PDOException && (string) $exception->getCode() === '23000'
                ? 'This M-Pesa transaction or payment submission has already been recorded.'
                : $exception->getMessage();
            }
        }
    }
    if ($referenceLock !== null) fee_release_payment_reference_lock($pdo, $referenceLock);
}

$feeOptions = array_map(static fn($student) => [
    'id' => (int) $student['id'], 'name' => $student['full_name'], 'admission' => $student['registration_no'],
    'balance' => (float) $student['balance'], 'detail' => $student['class_level'] . ' · ' . $year . ' ' . $term,
], $students);
$activityOptions = array_map(static fn($participant) => [
    'id' => (int) $participant['participant_id'], 'student_id' => (int) $participant['student_id'],
    'name' => $participant['full_name'], 'admission' => $participant['registration_no'],
    'activity' => $participant['activity_name'], 'detail' => $participant['activity_type'] . ' · ' . $participant['academic_year'] . ' ' . $participant['term'],
    'balance' => max(0, (float) $participant['expected_amount'] - (float) $participant['amount_paid']),
], $activityParticipants);
$childOptions = array_map(static fn($child) => [
    'id' => (int) $child['id'], 'name' => $child['full_name'], 'admission' => $child['admission_no'], 'balance' => (float) $child['balance'],
], $daycareChildren);
$transportOptions = array_map(static fn($account) => [
    'id' => (int) $account['account_id'], 'student_id' => (int) ($account['student_id'] ?? 0) ?: null, 'name' => $account['full_name'],
    'admission' => $account['registration_no'] ?: 'Outside account', 'balance' => (float) $account['balance'],
    'detail' => $account['class_level'] . ' · ' . $account['pickup_location'],
], $transportAccounts);
$feedingOptions = array_map(static fn($subscription) => [
    'id' => (int) $subscription['subscription_id'], 'student_id' => (int) $subscription['student_id'],
    'name' => $subscription['full_name'], 'admission' => $subscription['registration_no'],
    'balance' => (float) $subscription['balance'], 'detail' => $subscription['class_level'] . ' · ' . $subscription['academic_year'] . ' ' . $subscription['term'],
], $feedingSubscriptions);
$uniformOptions = array_map(static fn($sale) => [
    'id' => (int) $sale['sale_id'], 'student_id' => (int) $sale['student_id'], 'name' => $sale['full_name'],
    'admission' => $sale['registration_no'], 'balance' => (float) $sale['balance'],
    'detail' => 'Sale ' . $sale['receipt_no'],
], $uniformSales);
$allocationTargets = [
    'fees' => ['field' => 'student_id', 'label' => 'Student', 'options' => $feeOptions],
    'activity' => ['field' => 'participant_id', 'label' => 'Activity and student', 'options' => $activityOptions],
    'daycare' => ['field' => 'daycare_child_id', 'label' => 'Daycare child', 'options' => $childOptions],
    'transport' => ['field' => 'transport_account_id', 'label' => 'Student transport account', 'options' => $transportOptions],
    'uniform' => ['field' => 'uniform_sale_id', 'label' => 'Student uniform sale', 'options' => $uniformOptions],
    'feeding' => ['field' => 'feeding_subscription_id', 'label' => 'Student feeding account', 'options' => $feedingOptions],
];
$pageStyles = ['css/payment-allocation.css'];
$pageTitle = 'Combined Payment';
require_once __DIR__ . '/../includes/admin_header.php';
?>
<main class="container-fluid py-3 payment-allocation-page">
    <header class="payment-allocation-hero">
        <div class="payment-allocation-hero-icon" aria-hidden="true"><i class="fa-solid fa-wallet"></i></div>
        <div class="payment-allocation-hero-copy"><p>FEES <span>›</span> PAYMENT COLLECTION <span>›</span> COMBINED PAYMENT</p><h1>Combined Payment</h1><div>Record one payment and allocate it across students and school services.</div></div>
        <a class="btn payment-back-link" href="<?= h(url('admin/fees.php')) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i><span>Back to Fees</span></a>
    </header>
    <?php foreach ($errors as $error): ?><div class="alert alert-danger" role="alert"><?= h($error) ?></div><?php endforeach; ?>
    <form method="post" class="payment-allocation-form" id="combined-payment-form">
        <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="request_key" value="<?= h((string) ($old['request_key'] ?? bin2hex(random_bytes(32)))) ?>">
        <div class="payment-allocation-top-grid">
            <section class="panel payment-section payment-details-panel" aria-labelledby="payment-details-heading">
                <div class="payment-section-title"><span class="payment-step-number">1</span><div><h2 id="payment-details-heading">Payment Details</h2><p>Enter the payment information below.</p></div></div>
                <div class="payment-details-fields">
                    <div class="payment-field payment-field-parent"><label for="parent-user"><i class="fa-solid fa-users" aria-hidden="true"></i>Parent / Guardian <span class="text-muted">(optional)</span></label><select class="form-select" name="payer_key" id="parent-user"><option value="">Select parent or guardian</option><?php foreach ($parents as $parent): ?><option value="<?= h($parent['payer_key']) ?>" <?= ($old['payer_key'] ?? '') === $parent['payer_key'] ? 'selected' : '' ?>><?= h($parent['payer_label']) ?></option><?php endforeach; ?><option value="manual" <?= ($old['payer_key'] ?? '') === 'manual' ? 'selected' : '' ?>>Other - enter a name</option></select><input class="form-control mt-2" type="text" name="payer_name" id="manual-payer-name" maxlength="150" placeholder="Type parent or guardian name" value="<?= h($old['payer_name'] ?? '') ?>" <?= ($old['payer_key'] ?? '') === 'manual' ? 'required' : 'hidden disabled' ?>></div>
                    <div class="payment-field"><label for="payment-date"><i class="fa-regular fa-calendar" aria-hidden="true"></i>Payment Date <span class="required-mark">*</span></label><input class="form-control" type="date" name="payment_date" id="payment-date" value="<?= h((string) ($old['payment_date'] ?? date('Y-m-d'))) ?>" max="<?= h(date('Y-m-d')) ?>" required></div>
                    <div class="payment-field"><label for="payment-method"><i class="fa-regular fa-credit-card" aria-hidden="true"></i>Payment Method <span class="required-mark">*</span></label><select class="form-select" name="payment_method" id="payment-method" required><option value="M-PESA" <?= ($old['payment_method'] ?? 'M-PESA') === 'M-PESA' ? 'selected' : '' ?>>M-Pesa</option><option value="Cash" <?= ($old['payment_method'] ?? '') === 'Cash' ? 'selected' : '' ?>>Cash</option></select></div>
                    <div class="payment-field" id="mpesa-code-wrap"><label for="mpesa-code"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i>M-Pesa Transaction Code <span class="required-mark">*</span></label><input class="form-control" name="mpesa_code" id="mpesa-code" maxlength="20" autocomplete="off" placeholder="Enter transaction code" value="<?= h((string) ($old['mpesa_code'] ?? '')) ?>"></div>
                    <div class="payment-field payment-field-total"><label for="total-amount"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i>Total Amount Received (KES) <span class="required-mark">*</span></label><input class="form-control" type="number" name="total_amount" id="total-amount" min="0.01" step="0.01" placeholder="0.00" value="<?= h((string) ($old['total_amount'] ?? '')) ?>" required></div>
                </div>
            </section>
            <aside class="panel payment-section payment-summary-panel" aria-labelledby="payment-summary-heading">
                <div class="payment-section-title"><span class="payment-summary-icon" aria-hidden="true"><i class="fa-solid fa-receipt"></i></span><div><h2 id="payment-summary-heading">Payment Summary</h2><p>Overview of this allocation.</p></div></div>
                <div class="payment-summary-metric metric-received"><span class="metric-icon"><i class="fa-solid fa-wallet" aria-hidden="true"></i></span><div><span>Total Received</span><strong>KES <b id="summary-received">0.00</b></strong></div></div>
                <div class="payment-summary-metric metric-allocated"><span class="metric-icon"><i class="fa-solid fa-circle-check" aria-hidden="true"></i></span><div><span>Total Allocated</span><strong>KES <b id="summary-allocated">0.00</b></strong></div></div>
                <div class="payment-summary-metric metric-remaining"><span class="metric-icon"><i class="fa-solid fa-hourglass-half" aria-hidden="true"></i></span><div><span>Remaining Balance</span><strong>KES <b id="summary-remaining">0.00</b></strong></div></div>
                <div class="payment-progress-heading"><span>Allocation Progress</span><strong id="allocation-progress-label">0%</strong></div>
                <div class="progress payment-progress" role="progressbar" aria-label="Payment allocation progress" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" id="allocation-progress-bar" style="width:0%"></div></div>
                <div id="allocation-summary-message" class="payment-summary-message"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Enter an amount and distribute it across the selected items.</span></div>
            </aside>
        </div>
        <section class="panel payment-section payment-allocations-panel" aria-labelledby="allocations-heading">
            <div class="payment-section-title payment-allocations-title"><span class="payment-step-number">2</span><div><h2 id="allocations-heading">Student Allocations</h2><p>Choose a category, select the student account, and enter the amount to allocate.</p></div><button class="btn btn-outline-primary" type="button" id="add-allocation"><i class="fa-solid fa-circle-plus" aria-hidden="true"></i><span>Add Allocation</span></button></div>
            <div class="allocation-table-heading" aria-hidden="true"><span>#</span><span>Payment Category</span><span>Student / Account</span><span>Amount (KES)</span><span>Balance After</span><span>Action</span></div>
            <div id="allocation-rows" class="allocation-rows"></div>
            <div class="allocation-total-bar"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><strong>Total Allocated: KES <span id="allocated-total">0.00</span></strong><span class="allocation-total-divider">|</span><strong>Remaining: KES <span id="remaining-total">0.00</span></strong><span id="allocation-status" class="allocation-status">Amounts must match the total received.</span></div>
        </section>
        <footer class="payment-allocation-actions"><a class="btn btn-outline-secondary" href="<?= h(url('admin/fees.php')) ?>"><i class="fa-solid fa-xmark" aria-hidden="true"></i><span>Cancel</span></a><button class="btn btn-primary payment-submit-button" type="submit"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i><span>Save Payment &amp; Generate Receipt</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button></footer>
        <script type="application/json" id="initial-allocation-data"><?= json_encode(is_array($old['allocations'] ?? null) ? array_values($old['allocations']) : [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
    </form>
</main>
<template id="allocation-template">
    <div class="allocation-row">
        <span class="allocation-row-number"></span>
        <div class="allocation-field allocation-category-field"><label>Payment category</label><select class="form-select allocation-category" data-field="category" required><?php foreach ($allowedCategories as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
        <?php foreach ($allocationTargets as $category => $definition): if (!isset($allowedCategories[$category])) continue; ?>
            <div class="allocation-target-wrap" data-target="<?= h($category) ?>" <?= $category === 'fees' ? '' : 'hidden' ?>><label><?= h($definition['label']) ?></label><input class="form-control allocation-search" type="search" placeholder="Search name or admission no." autocomplete="off"><select class="form-select allocation-target" data-field="<?= h($definition['field']) ?>" <?= $category === 'fees' ? 'required' : 'disabled' ?>><option value="">Choose <?= h(strtolower($definition['label'])) ?></option><?php foreach ($definition['options'] as $option): $optionTitle = $category === 'activity' ? $option['activity'] . ' · ' . $option['name'] . ' (' . $option['admission'] . ')' : $option['name'] . ' · ' . $option['admission']; ?><option value="<?= (int) $option['id'] ?>" data-name="<?= h($option['name']) ?>" data-admission="<?= h($option['admission']) ?>" data-balance="<?= h((string) $option['balance']) ?>" <?= isset($option['student_id']) ? 'data-student-id="' . (int) $option['student_id'] . '"' : '' ?>><?= h($optionTitle . (!empty($option['detail']) ? ' · ' . $option['detail'] : '')) ?></option><?php endforeach; ?></select></div>
        <?php endforeach; ?>
        <div class="allocation-field allocation-amount-field"><label>Amount (KES)</label><input class="form-control allocation-amount" type="number" data-field="amount" min="0.01" step="0.01" placeholder="0.00" required></div>
        <div class="allocation-balance-cell"><span class="allocation-balance">Select an account</span></div>
        <button type="button" class="btn btn-outline-danger remove-allocation" aria-label="Remove allocation" title="Remove allocation"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
    </div>
</template>
<script>
(() => {
    const payerSelect = document.getElementById('parent-user');
    const manualPayer = document.getElementById('manual-payer-name');
    const syncManualPayer = () => {
        const show = payerSelect?.value === 'manual';
        if (!manualPayer) return;
        manualPayer.hidden = !show;
        manualPayer.disabled = !show;
        manualPayer.required = show;
        if (!show) manualPayer.value = '';
    };
    payerSelect?.addEventListener('change', syncManualPayer);
    syncManualPayer();

    const rows = document.getElementById('allocation-rows');
    const template = document.getElementById('allocation-template');
    const form = document.getElementById('combined-payment-form');
    const method = document.getElementById('payment-method');
    const code = document.getElementById('mpesa-code');
    const total = document.getElementById('total-amount');
    const status = document.getElementById('allocation-status');
    const money = value => (Number(value || 0)).toFixed(2);
    const recount = () => {
        let cents = 0;
        rows.querySelectorAll('.allocation-amount').forEach(input => cents += Math.round(Number(input.value || 0) * 100));
        const allocated = cents / 100;
        const received = Number(total.value || 0);
        const remaining = Math.max(0, received - allocated);
        const matches = received > 0 && Math.round(received * 100) === cents;
        const progress = received > 0 ? Math.min(100, Math.round((allocated / received) * 100)) : 0;
        document.getElementById('allocated-total').textContent = money(allocated);
        document.getElementById('remaining-total').textContent = money(remaining);
        document.getElementById('summary-received').textContent = money(received);
        document.getElementById('summary-allocated').textContent = money(allocated);
        document.getElementById('summary-remaining').textContent = money(remaining);
        document.getElementById('allocation-progress-label').textContent = `${progress}%`;
        document.getElementById('allocation-progress-bar').style.width = `${progress}%`;
        document.querySelector('.payment-progress').setAttribute('aria-valuenow', String(progress));
        const message = document.getElementById('allocation-summary-message');
        message.classList.toggle('is-complete', matches);
        message.innerHTML = matches
            ? '<i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Payment fully allocated.</span>'
            : '<i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>Distribute the full amount received.</span>';
        status.textContent = matches ? 'Allocation matches the amount received.' : 'Amounts must match the total received.';
        status.classList.toggle('is-valid', matches);
        return matches;
    };
    const updateRowBalance = row => {
        const select = row.querySelector('.allocation-target-wrap:not([hidden]) .allocation-target');
        const option = select?.selectedOptions[0];
        const balance = option?.value ? Number(option.dataset.balance || 0) : null;
        const amount = Number(row.querySelector('.allocation-amount').value || 0);
        const display = row.querySelector('.allocation-balance');
        display.textContent = balance === null ? 'Select an account' : `KES ${money(Math.max(0, balance - amount))} remaining`;
        display.classList.toggle('has-selection', balance !== null);
    };
    const setCategory = (row, clear = true) => {
        const category = row.querySelector('.allocation-category').value;
        row.querySelectorAll('.allocation-target-wrap').forEach(wrap => {
            const active = wrap.dataset.target === category;
            const select = wrap.querySelector('.allocation-target');
            wrap.hidden = !active;
            select.disabled = !active;
            select.required = active;
            if (!active && clear) {
                select.value = '';
                wrap.querySelector('.allocation-search').value = '';
            }
        });
        updateRowBalance(row);
        recount();
    };
    const renumber = () => rows.querySelectorAll('.allocation-row').forEach((row, index) => {
        row.querySelector('.allocation-row-number').textContent = String(index + 1);
        row.querySelectorAll('[data-field]').forEach(input => input.name = `allocations[${index}][${input.dataset.field}]`);
    });
    const addRow = initial => {
        if (rows.children.length >= 30) return;
        const row = template.content.firstElementChild.cloneNode(true);
        rows.appendChild(row);
        const category = row.querySelector('.allocation-category');
        if (initial?.category && Array.from(category.options).some(option => option.value === initial.category)) category.value = initial.category;
        category.addEventListener('change', () => setCategory(row));
        row.querySelectorAll('.allocation-target').forEach(select => select.addEventListener('change', () => { updateRowBalance(row); }));
        row.querySelectorAll('.allocation-search').forEach(search => search.addEventListener('input', () => {
            const select = search.parentElement.querySelector('.allocation-target');
            const query = search.value.trim().toLocaleLowerCase();
            Array.from(select.options).slice(1).forEach(option => { option.hidden = query !== '' && !option.textContent.toLocaleLowerCase().includes(query); });
        }));
        row.querySelector('.allocation-amount').addEventListener('input', () => { updateRowBalance(row); recount(); });
        row.querySelector('.remove-allocation').addEventListener('click', () => { if (rows.children.length > 1) row.remove(); renumber(); recount(); });
        setCategory(row, false);
        const activeWrap = row.querySelector(`.allocation-target-wrap[data-target="${CSS.escape(category.value)}"]`);
        const targetField = activeWrap?.querySelector('.allocation-target');
        if (initial && targetField) {
            const field = targetField.dataset.field;
            targetField.value = initial[field] || '';
            if (targetField.value) {
                const selected = targetField.selectedOptions[0];
                if (selected) activeWrap.querySelector('.allocation-search').value = selected.textContent;
            }
            row.querySelector('.allocation-amount').value = initial.amount || '';
        }
        setCategory(row, false);
        renumber();
        updateRowBalance(row);
        recount();
    };
    document.getElementById('add-allocation').addEventListener('click', () => addRow());
    total.addEventListener('input', recount);
    method.addEventListener('change', () => {
        const mpesa = method.value === 'M-PESA';
        document.getElementById('mpesa-code-wrap').hidden = !mpesa;
        code.required = mpesa;
        if (!mpesa) code.value = '';
    });
    form.addEventListener('submit', event => { if (!recount()) { event.preventDefault(); status.focus?.(); } });
    method.dispatchEvent(new Event('change'));
    let initial = [];
    try { initial = JSON.parse(document.getElementById('initial-allocation-data').textContent || '[]'); } catch (error) {}
    if (initial.length) initial.slice(0, 30).forEach(row => addRow(row));
    else addRow();
})();
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
