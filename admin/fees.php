<?php
$pageTitle = 'Fees';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/payment_transactions.php';

// Check permission
if (!current_admin_has_permission($pdo, 'fees.access')) {
    flash('error', 'You do not have permission to access Fees.');
    redirect('admin/dashboard.php');
}

// Current admin quick permissions & role
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$currentAdminRole = $adminId ? get_user_role_template_name($pdo, $adminId) : '';
$currentAdminClass = $adminId ? current_admin_class_level($pdo) : null;
$canViewFees = current_admin_has_permission($pdo, 'fees.view');
$canAddFees = current_admin_has_permission($pdo, 'fees.record');
$canPrintFees = current_admin_has_permission($pdo, 'fees.print');
$canCorrectFeeRecords = current_admin_has_permission($pdo, 'fees.correct');
$canExportFees = current_admin_has_permission($pdo, 'fees.export');
$canImportFees = current_admin_has_permission($pdo, 'fees.import');
$canManageHistoricalFees = current_admin_has_permission($pdo, 'students.manage_historical_fees');
$canPaymentAudit = current_admin_can_access_module($pdo, 'Payment Audit');
$canSecretaryRequestCorrection = $canPaymentAudit && fee_correction_actor_has_role($pdo, $adminId, 'Secretary');
$canDirectorCorrectPayments = $canPaymentAudit && fee_correction_actor_has_role($pdo, $adminId, 'Director');
$canReverseFees = fee_correction_actor_has_role($pdo, $adminId, 'Director');

$currentContext = current_academic_context($pdo);
$currentAcademicYear = $currentContext['academic_year'];
$currentTerm = $currentContext['term'];
sync_current_term_fee_balances($pdo);
$historyYear = trim((string) ($_GET['history_year'] ?? $currentAcademicYear));
$historyTerm = trim((string) ($_GET['history_term'] ?? $currentTerm));
$historyYears = array_values(array_filter(
    array_map('strval', $pdo->query("SELECT DISTINCT year FROM fees WHERE year REGEXP '^[0-9]{4}$' ORDER BY year DESC")->fetchAll(PDO::FETCH_COLUMN)),
    static fn(string $year): bool => preg_match('/^\d{4}$/', $year) === 1
));
if (!in_array((string) $currentAcademicYear, $historyYears, true)) {
    $historyYears[] = (string) $currentAcademicYear;
}
if (!preg_match('/^\d{4}$/', $historyYear) || !in_array($historyYear, $historyYears, true)) {
    $historyYear = (string) $currentAcademicYear;
}
if ($historyTerm !== 'all' && !in_array($historyTerm, term_options(), true)) {
    $historyTerm = $currentTerm;
}
$selectedStudentId = (int) ($_GET['student_id'] ?? ($_POST['student_id'] ?? 0));
$historicalRequested = ($_GET['historical'] ?? '') === '1' || ($_POST['historical_fee_action'] ?? '') === '1';
$historicalMode = $historicalRequested && $canManageHistoricalFees;
$historicalBalances = [];

$currentStudentClassSql = student_class_sql_for_year('students', (string) $currentAcademicYear);
$studentSql = "SELECT
        students.id,
        students.registration_no,
        students.full_name,
        students.parent_name,
        {$currentStudentClassSql} AS class_level,
        students.enrollment_status,
        COALESCE(current_balances.required_amount, 0) AS required_amount,
        COALESCE(current_balances.paid_amount, 0) AS paid_amount,
        COALESCE(current_balances.balance, 0) AS balance
     FROM students
     LEFT JOIN fee_balances AS current_balances
       ON current_balances.student_id = students.id
      AND current_balances.academic_year = :academic_year
      AND current_balances.term = :term
     WHERE students.enrollment_status = 'Active'";
$studentSql .= ' AND ' . student_fee_not_sponsored_sql('students', ':sponsorship_year');

// If current admin is a Teacher with a class assigned, restrict students to that class
$studentParams = ['academic_year' => $currentAcademicYear, 'term' => $currentTerm, 'sponsorship_year' => $currentAcademicYear];
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $studentSql .= " AND {$currentStudentClassSql} = :class_level";
    $studentParams['class_level'] = $currentAdminClass;
}
$studentSql .= " ORDER BY students.full_name ASC";
$studentStatement = $pdo->prepare($studentSql);
$studentStatement->execute($studentParams);
$students = $studentStatement->fetchAll();

if ($historicalMode && $selectedStudentId > 0) {
    $historicalStudentStmt = $pdo->prepare(
        "SELECT id, registration_no, full_name, parent_name, " . student_class_sql_for_year('students', $historyYear) . " AS class_level, enrollment_status,
                0 AS required_amount, 0 AS paid_amount, 0 AS balance
         FROM students WHERE id = :id AND enrollment_status <> 'Active' LIMIT 1"
    );
    $historicalStudentStmt->execute(['id' => $selectedStudentId]);
    $historicalStudent = $historicalStudentStmt->fetch();
    if ($historicalStudent) {
        $students[] = $historicalStudent;
        $balanceStmt = $pdo->prepare(
            'SELECT academic_year, term, balance FROM fee_balances
             WHERE student_id = :student_id AND balance > 0.005
             ORDER BY academic_year DESC, FIELD(term, "Term 1", "Term 2", "Term 3") DESC'
        );
        $balanceStmt->execute(['student_id' => $selectedStudentId]);
        $historicalBalances = array_values(array_filter(
            $balanceStmt->fetchAll(),
            static fn(array $row): bool => student_fee_sponsorship_status($pdo, $selectedStudentId, (string) $row['academic_year']) !== 'Sponsored Student'
        ));
    }
}

$errors = [];
$paymentReferenceLockName = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$canAddFees) {
        flash('error', 'You do not have permission to add fee payments.');
        redirect('admin/fees.php');
    }
    $studentId = (int) ($_POST['student_id'] ?? 0);
    $amountPaid = (float) ($_POST['amount_paid'] ?? 0);
    $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
    $paymentMethod = trim((string) ($_POST['payment_method'] ?? 'M-PESA'));
    $mpesaCode = normalize_payment_code(trim($_POST['mpesa_code'] ?? ''));
    $mpesaReferenceText = trim($_POST['mpesa_reference_text'] ?? '');
    $historicalPayment = ($_POST['historical_fee_action'] ?? '') === '1';
    $historicalReason = trim((string) ($_POST['historical_reason'] ?? ''));
    $term = $currentTerm;
    $year = $currentAcademicYear;

    if ($historicalPayment && $canManageHistoricalFees) {
        $period = explode('|', (string) ($_POST['historical_period'] ?? ''), 2);
        if (count($period) !== 2 || !preg_match('/^\d{4}$/', $period[0]) || !in_array($period[1], term_options(), true)) {
            $errors[] = 'Choose a valid historical academic period.';
        } else {
            [$year, $term] = $period;
        }
        if ($historicalReason === '' || mb_strlen($historicalReason) > 500) {
            $errors[] = 'Explain the authorized historical balance payment in 1 to 500 characters.';
        }
    } elseif ($historicalRequested && !$canManageHistoricalFees) {
        $errors[] = 'You do not have permission to record historical student payments.';
    }

    if ($studentId <= 0) {
        $errors[] = 'Please choose a student.';
    }
    if ($amountPaid <= 0) {
        $errors[] = 'Amount paid must be greater than zero.';
    }
    if ($paymentDate === '') {
        $errors[] = 'Payment date is required.';
    }
    if ($paymentDate !== '') {
        $paymentDateObject = DateTime::createFromFormat('Y-m-d', $paymentDate);
        if (!$paymentDateObject || $paymentDateObject->format('Y-m-d') !== $paymentDate) {
            $errors[] = 'Payment date must be a valid date.';
        }
    }
    if (!in_array($paymentMethod, ['M-PESA', 'Cash'], true)) {
        $errors[] = 'Choose M-PESA or Cash as the payment method.';
    }
    if ($paymentMethod === 'M-PESA' && $mpesaCode === '') {
        $errors[] = 'M-PESA transaction code is required.';
    }
    if ($paymentMethod === 'M-PESA' && $mpesaCode !== '' && !preg_match('/^[A-Z0-9]{8,20}$/', $mpesaCode)) {
        $errors[] = 'M-PESA transaction code should contain 8 to 20 letters or numbers.';
    }
    if ($paymentMethod !== 'M-PESA') {
        $mpesaCode = '';
    }
    if (!$errors && $paymentMethod === 'M-PESA') {
        try {
            $paymentReferenceLockName = fee_acquire_payment_reference_lock($pdo, $mpesaCode);
            if (payment_code_duplicate_exists($pdo, $mpesaCode, 'fee_mpesa_code')) {
                $errors[] = 'This M-PESA code has already been used. Please use a unique code.';
            }
        } catch (Throwable $exception) {
            $errors[] = $exception instanceof PDOException
                ? 'Unable to verify this M-PESA code right now. Please try again.'
                : $exception->getMessage();
        }
    }
    if ($errors && $paymentReferenceLockName !== null) {
        fee_release_payment_reference_lock($pdo, $paymentReferenceLockName);
        $paymentReferenceLockName = null;
    }
    if (!$errors) {
        try {
            $receiptIssuedAt = new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));
            fee_prepare_receipt_sequence($pdo, $receiptIssuedAt);
            $pdo->beginTransaction();
            $statement = $pdo->prepare("SELECT * FROM students WHERE id = :id FOR UPDATE");
            $statement->execute(['id' => $studentId]);
            $student = $statement->fetch();
            $studentClassLevel = $student
                ? student_class_for_year($pdo, $studentId, $year, (string) $student['class_level'])
                : null;

            if (!$student) {
                $errors[] = 'Selected student was not found.';
            } elseif (student_fee_sponsorship_status($pdo, $studentId, $year) === 'Sponsored Student') {
                $errors[] = "This student is sponsored for {$year}; school-fee payments are not accepted for that academic year.";
            } elseif ($student['enrollment_status'] !== 'Active' && (!$historicalPayment || !$canManageHistoricalFees)) {
                $errors[] = 'Payments for inactive students require an authorized historical-balance action.';
            } elseif ($student['enrollment_status'] === 'Active' && $historicalPayment) {
                $errors[] = 'Historical payment mode is only available for inactive students.';
            } elseif ($student['enrollment_status'] === 'Active' && !get_fee_structure($pdo, (string) $studentClassLevel, $year)) {
                $errors[] = "No fee structure exists for {$studentClassLevel} in {$year}.";
            } else {
                if ($student['enrollment_status'] === 'Active') {
                    sync_fee_balance_for_student($pdo, $studentId, (string) $studentClassLevel, $year, $term);
                    $balance = get_fee_balance($pdo, $studentId, $year, $term, true);
                } else {
                    $balance = get_fee_balance($pdo, $studentId, $year, $term, false);
                }

                if (!$balance) {
                    $errors[] = $student['enrollment_status'] === 'Active'
                        ? 'Fee balance could not be created for the selected student.'
                        : 'This student has no saved balance for the selected historical period.';
                } else {
                    $termBalance = (float) $balance['balance'];
                    if ($student['enrollment_status'] !== 'Active' && $termBalance <= 0.005) {
                        $errors[] = 'Historical payments are only allowed against an existing outstanding balance.';
                    }
                }

                if (!$errors && $amountPaid > $termBalance + 0.005) {
                    $errors[] = 'Amount paid cannot exceed the current term balance.';
                } elseif (!$errors) {
                    $balanceAfter = max($termBalance - $amountPaid, 0);
                    $paidAfter = (float) $balance['paid_amount'] + $amountPaid;
                    $receiptNo = fee_allocate_receipt_number($pdo, $receiptIssuedAt);

                    $insert = $pdo->prepare(
                        "INSERT INTO fees (student_id, receipt_no, amount_paid, mpesa_code, mpesa_reference_text, payment_method, term, year, balance_after_payment, payment_date, payment_status)
                         VALUES (:student_id, :receipt_no, :amount_paid, :mpesa_code, :mpesa_reference_text, :payment_method, :term, :year, :balance_after_payment, :payment_date, 'Recorded')"
                    );
                    $insert->execute([
                        'student_id' => $studentId,
                        'receipt_no' => $receiptNo,
                        'amount_paid' => $amountPaid,
                        'mpesa_code' => $mpesaCode,
                        'mpesa_reference_text' => $mpesaReferenceText,
                        'payment_method' => $paymentMethod,
                        'term' => $term,
                        'year' => $year,
                        'balance_after_payment' => $balanceAfter,
                        'payment_date' => $paymentDate,
                    ]);
                    $feeId = (int) $pdo->lastInsertId();
                    payment_transaction_attach_legacy($pdo, 'fees', $feeId, [
                        'total_amount' => $amountPaid,
                        'payment_method' => $paymentMethod,
                        'mpesa_code' => $mpesaCode,
                        'payment_date' => $paymentDate,
                        'student_id' => $studentId,
                        'payment_category' => 'School fees',
                        'academic_year' => $year,
                        'term' => $term,
                        'recorded_by' => $adminId,
                    ]);
                    fee_log_payment_audit($pdo, $feeId, $studentId, $receiptNo, 'RECORDED', $adminId, null, [
                        'amount_paid' => $amountPaid,
                        'payment_method' => $paymentMethod,
                        'transaction_reference' => $mpesaCode,
                        'term' => $term,
                        'academic_year' => $year,
                        'payment_date' => $paymentDate,
                    ]);

                    if ($historicalPayment) {
                        $historyInsert = $pdo->prepare(
                            'INSERT INTO student_historical_fee_actions (payment_id, student_id, actor_id, reason)
                             VALUES (:payment_id, :student_id, :actor_id, :reason)'
                        );
                        $historyInsert->execute([
                            'payment_id' => $feeId,
                            'student_id' => $studentId,
                            'actor_id' => $adminId,
                            'reason' => $historicalReason,
                        ]);
                    }

                    $updateBalance = $pdo->prepare(
                        "UPDATE fee_balances
                         SET paid_amount = :paid_amount,
                             balance = :balance
                         WHERE id = :id"
                    );
                    $updateBalance->execute([
                        'paid_amount' => $paidAfter,
                        'balance' => $balanceAfter,
                        'id' => (int) $balance['id'],
                    ]);

                    $pdo->commit();
                    fee_release_payment_reference_lock($pdo, $paymentReferenceLockName);
                    $paymentReferenceLockName = null;
                    redirect('admin/receipt.php?id=' . $feeId);
                }
            }

            if ($errors) {
                $pdo->rollBack();
                fee_release_payment_reference_lock($pdo, $paymentReferenceLockName);
                $paymentReferenceLockName = null;
            }
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            fee_release_payment_reference_lock($pdo, $paymentReferenceLockName);
            $paymentReferenceLockName = null;
            error_log('Fee payment save failed with code ' . (string) $exception->getCode());
            $errors[] = 'Unable to save payment. Please try again or contact the system administrator.';
        }
    }
}

$paymentsSql = "SELECT fees.*, students.registration_no, students.full_name, " . student_class_sql('students', 'fees.year') . " AS class_level, students.enrollment_status
     FROM fees
     JOIN students ON students.id = fees.student_id
     WHERE fees.year = :year";
$paymentsParams = ['year' => $historyYear];
if ($historyTerm !== 'all') {
    $paymentsSql .= ' AND fees.term = :term';
    $paymentsParams['term'] = $historyTerm;
}
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $paymentsSql .= " AND " . student_class_sql('students', 'fees.year') . " = :class_level";
    $paymentsParams['class_level'] = $currentAdminClass;
}
$paymentsSql .= " ORDER BY fees.payment_date DESC, fees.id DESC";
$paymentsStmt = $pdo->prepare($paymentsSql);
$paymentsStmt->execute($paymentsParams);
$payments = $paymentsStmt->fetchAll();
$pendingCorrectionPaymentIds = [];
if ($canSecretaryRequestCorrection || $canDirectorCorrectPayments) {
    $pendingCorrectionPaymentIds = array_fill_keys(array_map(
        'intval',
        $pdo->query("SELECT payment_id FROM fee_payment_correction_requests WHERE status = 'Pending'")->fetchAll(PDO::FETCH_COLUMN)
    ), true);
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Fee Payments</p>
        <h1>Record Payment</h1>
        <p class="mb-0 text-muted"><?= h($currentAcademicYear . ' - ' . $currentTerm) ?></p>
    </div>
    <div class="d-flex flex-wrap fee-page-actions">
        <?php if ($canAddFees || current_admin_has_permission($pdo, 'activities.payments.collect') || current_admin_has_permission($pdo, 'daycare.payments.record')): ?>
            <a class="btn btn-outline-primary" href="<?= h(url('admin/payment_allocation.php')) ?>"><i class="fa-solid fa-people-arrows-left-right me-2"></i>Combined Payment</a>
        <?php endif; ?>
        <?php if ($canExportFees): ?>
            <button class="btn btn-outline-primary" type="button" id="openFeeExport" aria-haspopup="dialog" aria-controls="feeExportDialog">Export Fees to Excel</button>
        <?php else: ?>
            <button class="btn btn-outline-primary" disabled>Export Fees to Excel</button>
        <?php endif; ?>
        <?php if ($canImportFees): ?>
            <a class="btn btn-primary" href="<?= url('admin/import_fee_payments.php') ?>">Import Fee Payments</a>
        <?php endif; ?>
    </div>
</div>

<?php if ($canExportFees): ?>
    <dialog class="fee-export-dialog" id="feeExportDialog" aria-labelledby="feeExportDialogTitle">
        <form method="get" action="<?= url('admin/export.php') ?>" class="fee-export-form">
            <input type="hidden" name="type" value="fees">
            <header class="fee-export-header">
                <h2 id="feeExportDialogTitle">Export fee payments</h2>
                <button type="button" class="fee-export-close" data-close-fee-export aria-label="Close"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </header>
            <div class="fee-export-body">
                <p>Choose the academic period to include in the export.</p>
                <div class="fee-export-fields">
                    <div>
                        <label class="form-label" for="fee_export_year">Academic year</label>
                        <select class="form-select" id="fee_export_year" name="year" required>
                            <?php foreach ($historyYears as $yearOption): ?>
                                <option value="<?= h($yearOption) ?>" <?= $historyYear === $yearOption ? 'selected' : '' ?>><?= h($yearOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label" for="fee_export_term">Term</label>
                        <select class="form-select" id="fee_export_term" name="term" required>
                            <option value="all" <?= $historyTerm === 'all' ? 'selected' : '' ?>>All terms</option>
                            <?php foreach (term_options() as $termOption): ?>
                                <option value="<?= h($termOption) ?>" <?= $historyTerm === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <footer class="fee-export-actions">
                <button type="button" class="btn btn-outline-secondary" data-close-fee-export>Cancel</button>
                <button type="submit" class="btn btn-primary">Download export</button>
            </footer>
        </form>
    </dialog>
    <script>
        (() => {
            const dialog = document.getElementById('feeExportDialog');
            const openButton = document.getElementById('openFeeExport');
            if (!dialog || !openButton || typeof dialog.showModal !== 'function') return;
            openButton.addEventListener('click', () => dialog.showModal());
            dialog.querySelectorAll('[data-close-fee-export]').forEach((button) => {
                button.addEventListener('click', () => dialog.close());
            });
            dialog.addEventListener('click', (event) => {
                if (event.target === dialog) dialog.close();
            });
        })();
    </script>
<?php endif; ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <h2>New Payment</h2>
            <?php if ($canAddFees): ?>
            <?php if ($historicalMode): ?>
                <div class="alert alert-warning">Historical payment mode: only saved outstanding balances can be paid. The student record and admission number remain unchanged.</div>
            <?php endif; ?>
            <form class="row g-3" method="post">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <div class="col-12">
                    <label class="form-label" for="student_id">Student</label>
                    <select class="form-select" id="student_id" name="student_id" required>
                        <option value="">Choose student...</option>
                        <?php foreach ($students as $student): ?>
                            <option value="<?= (int) $student['id'] ?>" <?= $selectedStudentId === (int) $student['id'] ? 'selected' : '' ?>>
                                <?= h($student['registration_no'] . ' - ' . $student['full_name'] . ' | ' . $student['enrollment_status'] . ($student['enrollment_status'] === 'Active' ? ' | ' . $currentTerm . ' Balance: ' . money((float) $student['balance']) : '')) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php if ($historicalMode): ?>
                    <input type="hidden" name="historical_fee_action" value="1">
                    <div class="col-12">
                        <label class="form-label" for="historical_period">Outstanding historical period</label>
                        <select class="form-select" id="historical_period" name="historical_period" required>
                            <option value="">Choose outstanding period...</option>
                            <?php foreach ($historicalBalances as $historicalBalance): ?>
                                <option value="<?= h($historicalBalance['academic_year'] . '|' . $historicalBalance['term']) ?>" <?= ($_POST['historical_period'] ?? '') === $historicalBalance['academic_year'] . '|' . $historicalBalance['term'] ? 'selected' : '' ?>>
                                    <?= h($historicalBalance['academic_year'] . ' - ' . $historicalBalance['term'] . ' | Outstanding: ' . money((float) $historicalBalance['balance'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="historical_reason">Reason for authorized historical payment</label>
                        <textarea class="form-control" id="historical_reason" name="historical_reason" rows="2" maxlength="500" required><?= h($_POST['historical_reason'] ?? '') ?></textarea>
                    </div>
                <?php endif; ?>
                <div class="col-md-6">
                    <label class="form-label" for="amount_paid">Amount Paid</label>
                    <input class="form-control" type="number" min="0.01" step="0.01" id="amount_paid" name="amount_paid" value="<?= h($_POST['amount_paid'] ?? '') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="payment_date">Date</label>
                    <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h($_POST['payment_date'] ?? date('Y-m-d')) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="current_term">Current Term</label>
                    <input class="form-control" id="current_term" value="<?= h($currentTerm) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="current_year">Academic Year</label>
                    <input class="form-control" id="current_year" value="<?= h($currentAcademicYear) ?>" readonly>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="payment_method">Payment Method</label>
                    <select class="form-select" id="payment_method" name="payment_method" required>
                        <option value="M-PESA" <?= ($_POST['payment_method'] ?? 'M-PESA') === 'M-PESA' ? 'selected' : '' ?>>M-PESA</option>
                        <option value="Cash" <?= ($_POST['payment_method'] ?? '') === 'Cash' ? 'selected' : '' ?>>Cash</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="mpesa_code">M-PESA Transaction Code</label>
                    <input class="form-control" id="mpesa_code" name="mpesa_code" value="<?= h($_POST['mpesa_code'] ?? '') ?>" placeholder="Required for M-PESA" <?= ($_POST['payment_method'] ?? 'M-PESA') === 'M-PESA' ? 'required' : '' ?>>
                </div>
                
                <div class="col-12">
                    <button class="btn btn-primary w-100" type="submit">Save Payment and Open Receipt</button>
                </div>
            </form>
            <script>
                (() => {
                    const method = document.getElementById('payment_method');
                    const code = document.getElementById('mpesa_code');
                    if (!method || !code) return;
                    const updateCodeRequirement = () => {
                        code.required = method.value === 'M-PESA';
                        code.placeholder = code.required ? 'Required for M-PESA' : 'Not required for cash';
                    };
                    method.addEventListener('change', updateCodeRequirement);
                    updateCodeRequirement();
                })();
            </script>
            <?php else: ?>
                <div class="alert alert-secondary">You do not have permission to record fee payments.</div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <h2>Payment History</h2>
            <?php if ($canViewFees): ?>
            <form class="row g-2 align-items-end mb-3" method="get" action="<?= url('admin/fees.php') ?>">
                <div class="col-6 col-md-4">
                    <label class="form-label" for="history_year">Academic year</label>
                    <select class="form-select" id="history_year" name="history_year">
                        <?php foreach ($historyYears as $yearOption): ?>
                            <option value="<?= h($yearOption) ?>" <?= $historyYear === $yearOption ? 'selected' : '' ?>><?= h($yearOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-4">
                    <label class="form-label" for="history_term">Term</label>
                    <select class="form-select" id="history_term" name="history_term">
                        <option value="all" <?= $historyTerm === 'all' ? 'selected' : '' ?>>All terms</option>
                        <?php foreach (term_options() as $termOption): ?>
                            <option value="<?= h($termOption) ?>" <?= $historyTerm === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-auto">
                    <button class="btn btn-outline-primary" type="submit">View history</button>
                </div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Receipt No</th>
                            <th>Student</th>
                            <th>Enrollment Status</th>
                            <th>Term</th>
                            <th>M-PESA Code</th>
                            <th>Amount Paid</th>
                            <th>Term Balance</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payments as $payment): ?>
                            <tr>
                                <td><?= h($payment['receipt_no']) ?></td>
                                <td><?= h($payment['full_name']) ?><br><small><?= h($payment['registration_no']) ?></small></td>
                                <td><?= h($payment['enrollment_status']) ?></td>
                                <td><?= h($payment['term'] . ' - ' . $payment['year']) ?></td>
                                <td><?= h($payment['mpesa_code']) ?></td>
                                <td><?= money((float) $payment['amount_paid']) ?></td>
                                <td><?= money((float) $payment['balance_after_payment']) ?></td>
                                <td><?= h($payment['payment_status'] ?? 'Recorded') ?></td>
                                <td><?= date('M d, Y', strtotime($payment['payment_date'])) ?></td>
                                <td>
                                    <?php $hasPendingCorrection = isset($pendingCorrectionPaymentIds[(int) $payment['id']]); ?>
                                    <?php if ($canPrintFees): ?>
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/receipt.php?id=' . $payment['id']) ?>">Receipt</a>
                                    <?php endif; ?>
                                    <?php if ($hasPendingCorrection && ($canSecretaryRequestCorrection || $canDirectorCorrectPayments)): ?>
                                        <span class="badge text-bg-warning">Correction pending</span>
                                    <?php elseif ($canSecretaryRequestCorrection && ($payment['payment_status'] ?? 'Recorded') === 'Recorded'): ?>
                                        <a class="btn btn-sm btn-outline-warning" href="<?= url('admin/fee_correction_request.php?id=' . (int) $payment['id']) ?>">Request correction</a>
                                    <?php elseif ($canDirectorCorrectPayments && $canCorrectFeeRecords && ($payment['payment_status'] ?? 'Recorded') === 'Recorded'): ?>
                                        <a class="btn btn-sm btn-outline-warning" href="<?= url('admin/fee_correct.php?id=' . (int) $payment['id']) ?>">Correct</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$payments): ?>
                            <tr><td colspan="10">No fee payments recorded yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
                <div class="alert alert-secondary">You do not have permission to view fee records.</div>
            <?php endif; ?>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
