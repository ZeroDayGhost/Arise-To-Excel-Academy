<?php
$pageTitle = 'School Feeding';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'feeding.access')) {
    flash('error', 'You do not have permission to access Feeding.');
    redirect('admin/dashboard.php');
}

// Current admin quick permissions & role
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$currentAdminRole = $adminId ? get_user_role_template_name($pdo, $adminId) : '';
$currentAdminClass = $adminId ? current_admin_class_level($pdo) : null;
$canViewFeeding = current_admin_has_permission($pdo, 'feeding.view');
$canAddFeeding = current_admin_has_permission($pdo, 'feeding.save');
$canEditFeeding = current_admin_has_permission($pdo, 'feeding.edit');
$canDeleteFeeding = current_admin_has_permission($pdo, 'feeding.delete');
$canRecordFeedingPayment = current_admin_has_permission($pdo, 'feeding.record_payment');

$classLevels = class_level_options();
$terms = term_options();
$statuses = feeding_status_options();
$errors = [];
$currentContext = current_academic_context($pdo);
$suggestedFeedingAmount = feeding_term_amount();
$editingId = (int) ($_GET['edit'] ?? ($_POST['feeding_subscription_id'] ?? 0));
$payingId = (int) ($_GET['pay'] ?? ($_POST['payment_subscription_id'] ?? 0));
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editingId > 0 && !$canEditFeeding) {
    rbac_deny($pdo, 'You do not have permission to edit feeding accounts.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $payingId > 0 && !$canRecordFeedingPayment) {
    rbac_deny($pdo, 'You do not have permission to record feeding payments.');
}
$form = [
    'id' => '',
    'student_id' => '',
    'academic_year' => $currentContext['academic_year'],
    'term' => $currentContext['term'],
    'feeding_amount' => $suggestedFeedingAmount,
    'status' => 'Active',
];

function feeding_subscription_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        "SELECT feeding_subscriptions.*, students.full_name, students.registration_no, students.class_level, students.enrollment_status
         FROM feeding_subscriptions
         JOIN students ON students.id = feeding_subscriptions.student_id
         WHERE feeding_subscriptions.id = :id"
    );
    $statement->execute(['id' => $id]);
    $subscription = $statement->fetch();

    return $subscription ?: null;
}

function feeding_duplicate_exists(PDO $pdo, int $studentId, string $academicYear, string $term, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM feeding_subscriptions
            WHERE student_id = :student_id
              AND academic_year = :academic_year
              AND term = :term";
    $params = [
        'student_id' => $studentId,
        'academic_year' => $academicYear,
        'term' => $term,
    ];

    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

function feeding_paid_amount(PDO $pdo, int $subscriptionId): float
{
    $statement = $pdo->prepare("SELECT COALESCE(SUM(amount_paid), 0) FROM feeding_payments WHERE feeding_subscription_id = :id");
    $statement->execute(['id' => $subscriptionId]);
    return (float) $statement->fetchColumn();
}

if ($editingId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $editing = feeding_subscription_by_id($pdo, $editingId);
    if ($editing) {
        $form = array_merge($form, $editing);
    } else {
        flash('error', 'Feeding subscription was not found.');
        redirect('admin/feeding.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_subscription';
    $requiredPermission = match ($action) {
        'delete_subscription' => 'feeding.delete',
        'save_payment' => 'feeding.record_payment',
        'update_subscription' => 'feeding.update',
        default => 'feeding.save',
    };
    require_action_permission($pdo, $requiredPermission);

    if ($action === 'delete_subscription') {
        $subscriptionId = (int) ($_POST['feeding_subscription_id'] ?? 0);
        $subscription = $subscriptionId > 0 ? feeding_subscription_by_id($pdo, $subscriptionId) : null;

        if (!$canDeleteFeeding) {
            flash('error', 'You do not have permission to delete feeding subscriptions.');
            redirect('admin/feeding.php');
        }

        // If current admin is a teacher, ensure subscription belongs to their class
        if ($currentAdminRole === 'Teacher' && $currentAdminClass && $subscription && ($subscription['class_level'] ?? '') !== $currentAdminClass) {
            flash('error', 'You do not have permission to modify subscriptions outside your class.');
            redirect('admin/feeding.php');
        }
        if (!$subscription) {
            $errors[] = 'Choose a feeding subscription to delete.';
        } else {
            $statement = $pdo->prepare("DELETE FROM feeding_subscriptions WHERE id = :id");
            $statement->execute(['id' => $subscriptionId]);
            flash('success', 'Feeding subscription removed successfully.');
            redirect('admin/feeding.php');
        }
    } elseif ($action === 'save_payment') {
        $subscriptionId = (int) ($_POST['payment_subscription_id'] ?? 0);
        $amountPaid = trim($_POST['amount_paid'] ?? '');
        $paymentDate = trim($_POST['payment_date'] ?? date('Y-m-d'));
        $referenceNo = normalize_payment_code(trim($_POST['reference_no'] ?? ''));
        $subscription = $subscriptionId > 0 ? feeding_subscription_by_id($pdo, $subscriptionId) : null;

        if (!$subscription) {
            $errors[] = 'Choose a valid feeding subscription.';
        } elseif ($subscription['enrollment_status'] !== 'Active') {
            $errors[] = 'Routine feeding payments are only available for active students.';
        }
        if (!valid_money_value($amountPaid) || (float) $amountPaid <= 0) {
            $errors[] = 'Payment amount must be greater than zero.';
        }
        if (!valid_date_value($paymentDate)) {
            $errors[] = 'Payment date must be valid.';
        }

        if (!$errors) {
        if (!$canRecordFeedingPayment) {
                flash('error', 'You do not have permission to record feeding payments.');
                redirect('admin/feeding.php');
            }

            if ($currentAdminRole === 'Teacher' && $currentAdminClass && $subscription && ($subscription['class_level'] ?? '') !== $currentAdminClass) {
                $errors[] = 'You do not have permission to record payments for students outside your class.';
            }

            if ($referenceNo !== '' && payment_code_duplicate_exists($pdo, $referenceNo, 'feeding_reference')) {
                $errors[] = 'This payment reference has already been used. Please use a unique reference code.';
            }
            $paid = feeding_paid_amount($pdo, $subscriptionId);
            $balance = max((float) $subscription['feeding_amount'] - $paid, 0);

            if ((float) $amountPaid > $balance + 0.005) {
                $errors[] = 'Payment cannot exceed the feeding balance.';
            }
        }

        if (!$errors) {
            $statement = $pdo->prepare(
                "INSERT INTO feeding_payments (feeding_subscription_id, amount_paid, payment_date, reference_no)
                 VALUES (:feeding_subscription_id, :amount_paid, :payment_date, :reference_no)"
            );
            $statement->execute([
                'feeding_subscription_id' => $subscriptionId,
                'amount_paid' => (float) $amountPaid,
                'payment_date' => $paymentDate,
                'reference_no' => $referenceNo,
            ]);
            flash('success', 'Feeding payment recorded successfully.');
            redirect('admin/feeding.php');
        }
    } else {
        $subscriptionId = (int) ($_POST['feeding_subscription_id'] ?? 0);
        $form = [
            'id' => $subscriptionId,
            'student_id' => (int) ($_POST['student_id'] ?? 0),
            'academic_year' => trim($_POST['academic_year'] ?? ''),
            'term' => trim($_POST['term'] ?? ''),
            'feeding_amount' => trim($_POST['feeding_amount'] ?? ''),
            'status' => trim($_POST['status'] ?? 'Active'),
        ];

        if ($form['student_id'] <= 0) {
            $errors[] = 'Choose a student.';
        }
        if (!$errors) {
            $activeCheck = $pdo->prepare('SELECT enrollment_status FROM students WHERE id = :id LIMIT 1');
            $activeCheck->execute(['id' => (int) $form['student_id']]);
            if ($activeCheck->fetchColumn() !== 'Active') {
                $errors[] = 'New or updated feeding subscriptions are only available for active students.';
            }
        }
        if (!preg_match('/^\d{4}$/', $form['academic_year'])) {
            $errors[] = 'Academic year must be four digits.';
        }
        if (!in_array($form['term'], $terms, true)) {
            $errors[] = 'Choose a valid term.';
        }
        if (!valid_money_value($form['feeding_amount']) || (float) $form['feeding_amount'] <= 0) {
            $errors[] = 'Feeding required amount must be greater than zero.';
        }
        if (!in_array($form['status'], $statuses, true)) {
            $errors[] = 'Choose a valid status.';
        }
        if (!$errors && feeding_duplicate_exists($pdo, (int) $form['student_id'], $form['academic_year'], $form['term'], $action === 'update_subscription' ? $subscriptionId : 0)) {
            $errors[] = 'This student already has a feeding subscription for the selected year and term.';
        }
        if (!$errors && $action === 'update_subscription' && $subscriptionId > 0 && (float) $form['feeding_amount'] < feeding_paid_amount($pdo, $subscriptionId)) {
            $errors[] = 'Feeding required amount cannot be less than payments already recorded.';
        }

        if (!$errors) {
            // permission checks for creating/updating subscriptions
            if ($action === 'update_subscription' && !$canEditFeeding) {
                flash('error', 'You do not have permission to update feeding subscriptions.');
                redirect('admin/feeding.php');
            }
            if ($action === 'save_subscription' && !$canAddFeeding) {
                flash('error', 'You do not have permission to add feeding subscriptions.');
                redirect('admin/feeding.php');
            }

            // teachers can only add/update subscriptions for students in their class
            if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
                $studentStmt = $pdo->prepare('SELECT class_level FROM students WHERE id = :id LIMIT 1');
                $studentStmt->execute(['id' => (int)$form['student_id']]);
                $srow = $studentStmt->fetch();
                if (!$srow || ($srow['class_level'] ?? '') !== $currentAdminClass) {
                    $errors[] = 'You can only create or edit subscriptions for students in your class.';
                }
            }
            $params = [
                'student_id' => (int) $form['student_id'],
                'academic_year' => $form['academic_year'],
                'term' => $form['term'],
                'feeding_amount' => (float) $form['feeding_amount'],
                'status' => $form['status'],
            ];

            if ($action === 'update_subscription' && $subscriptionId > 0) {
                $params['id'] = $subscriptionId;
                $statement = $pdo->prepare(
                    "UPDATE feeding_subscriptions
                     SET student_id = :student_id,
                         academic_year = :academic_year,
                         term = :term,
                         feeding_amount = :feeding_amount,
                         status = :status
                     WHERE id = :id"
                );
                $statement->execute($params);
                flash('success', 'Feeding subscription updated successfully.');
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO feeding_subscriptions (student_id, academic_year, term, feeding_amount, status)
                     VALUES (:student_id, :academic_year, :term, :feeding_amount, :status)"
                );
                $statement->execute($params);
                flash('success', 'Feeding subscription added successfully.');
            }

            redirect('admin/feeding.php');
        }
    }
}

$studentSql = "SELECT id, registration_no, full_name, class_level FROM students WHERE enrollment_status = 'Active'";
$studentParams = [];
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $studentSql .= " AND class_level = :class_level";
    $studentParams['class_level'] = $currentAdminClass;
}
$studentSql .= " ORDER BY full_name ASC";
$stmtStudents = $pdo->prepare($studentSql);
$stmtStudents->execute($studentParams);
$students = $stmtStudents->fetchAll();

$search = trim($_GET['search'] ?? '');
$filterClass = in_array(($_GET['class_level'] ?? ''), $classLevels, true) ? $_GET['class_level'] : '';
$filterYear = preg_match('/^\d{4}$/', ($_GET['year'] ?? '')) ? $_GET['year'] : '';
$filterTerm = in_array(($_GET['term'] ?? ''), $terms, true) ? $_GET['term'] : '';
$currentContext = current_academic_context($pdo);

if ($filterYear === '') {
    $filterYear = $currentContext['academic_year'];
}
if ($filterTerm === '') {
    $filterTerm = $currentContext['term'];
}

// If admin is a teacher, force class filter to their assigned class
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $filterClass = $currentAdminClass;
}

// If the active academic context was just switched, ignore explicit year/term filters so pages show the new context
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!empty($_SESSION['academic_context_switched'])) {
    $filterYear = '';
    $filterTerm = '';
    unset($_SESSION['academic_context_switched']);
}
$where = [];
$params = [];

if ($search !== '') {
    $where[] = '(students.registration_no LIKE :search_registration OR students.full_name LIKE :search_name OR students.parent_name LIKE :search_parent)';
    $searchLike = '%' . $search . '%';
    $params['search_registration'] = $searchLike;
    $params['search_name'] = $searchLike;
    $params['search_parent'] = $searchLike;
}
if ($filterClass !== '') {
    $where[] = 'students.class_level = :class_level';
    $params['class_level'] = $filterClass;
}
if ($filterYear !== '') {
    $where[] = 'feeding_subscriptions.academic_year = :year';
    $params['year'] = $filterYear;
}
if ($filterTerm !== '') {
    $where[] = 'feeding_subscriptions.term = :term';
    $params['term'] = $filterTerm;
}

$sql = "SELECT
            feeding_subscriptions.*,
            students.registration_no,
            students.full_name,
            students.parent_name,
            students.class_level,
            feeding_subscriptions.feeding_amount AS feeding_required,
            COALESCE(SUM(feeding_payments.amount_paid), 0) AS feeding_paid,
            feeding_subscriptions.feeding_amount - COALESCE(SUM(feeding_payments.amount_paid), 0) AS feeding_balance
        FROM feeding_subscriptions
        JOIN students ON students.id = feeding_subscriptions.student_id
        LEFT JOIN feeding_payments ON feeding_payments.feeding_subscription_id = feeding_subscriptions.id";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' GROUP BY feeding_subscriptions.id ORDER BY feeding_subscriptions.academic_year DESC, FIELD(feeding_subscriptions.term, "Term 1", "Term 2", "Term 3"), students.full_name ASC';
$statement = $pdo->prepare($sql);
$statement->execute($params);
$subscriptions = $statement->fetchAll();
$paymentTarget = $payingId > 0 ? feeding_subscription_by_id($pdo, $payingId) : null;
$feedingPaymentBalanceSql = "CASE WHEN feeding_subscriptions.feeding_amount - (
        SELECT COALESCE(SUM(prior_payment.amount_paid), 0)
        FROM feeding_payments prior_payment
        WHERE prior_payment.feeding_subscription_id = feeding_payments.feeding_subscription_id
          AND (prior_payment.payment_date < feeding_payments.payment_date
               OR (prior_payment.payment_date = feeding_payments.payment_date AND prior_payment.id <= feeding_payments.id))
    ) > 0 THEN feeding_subscriptions.feeding_amount - (
        SELECT COALESCE(SUM(prior_payment.amount_paid), 0)
        FROM feeding_payments prior_payment
        WHERE prior_payment.feeding_subscription_id = feeding_payments.feeding_subscription_id
          AND (prior_payment.payment_date < feeding_payments.payment_date
               OR (prior_payment.payment_date = feeding_payments.payment_date AND prior_payment.id <= feeding_payments.id))
    ) ELSE 0 END AS balance_after_payment";
$paymentHistoryStmt = $pdo->prepare(
    "SELECT feeding_payments.*, students.registration_no, students.full_name, feeding_subscriptions.term, feeding_subscriptions.academic_year,
            feeding_subscriptions.feeding_amount AS feeding_required, {$feedingPaymentBalanceSql}
     FROM feeding_payments
     JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
     JOIN students ON students.id = feeding_subscriptions.student_id
     WHERE feeding_subscriptions.academic_year = :year AND feeding_subscriptions.term = :term
     ORDER BY feeding_payments.payment_date DESC, feeding_payments.id DESC
     LIMIT 20"
);
$params = ['year' => $currentContext['academic_year'], 'term' => $currentContext['term']];
if ($currentAdminRole === 'Teacher' && $currentAdminClass) {
    $paymentHistoryStmt = $pdo->prepare(
        "SELECT feeding_payments.*, students.registration_no, students.full_name, feeding_subscriptions.term, feeding_subscriptions.academic_year,
                feeding_subscriptions.feeding_amount AS feeding_required, {$feedingPaymentBalanceSql}
         FROM feeding_payments
         JOIN feeding_subscriptions ON feeding_subscriptions.id = feeding_payments.feeding_subscription_id
         JOIN students ON students.id = feeding_subscriptions.student_id
         WHERE feeding_subscriptions.academic_year = :year AND feeding_subscriptions.term = :term AND students.class_level = :class_level
         ORDER BY feeding_payments.payment_date DESC, feeding_payments.id DESC
         LIMIT 20"
    );
    $params['class_level'] = $currentAdminClass;
}
$paymentHistoryStmt->execute($params);
$paymentHistory = $paymentHistoryStmt->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">School Meals</p>
        <h1>Students Eating at School</h1>
        <p class="mb-0 text-muted"><?= h($currentContext['academic_year'] . ' - ' . $currentContext['term'] . ' | Suggested feeding fee: ' . money($suggestedFeedingAmount)) ?></p>
    </div>
    <div class="action-row d-flex flex-wrap gap-2">
        <?php if ($canRecordFeedingPayment): ?><a class="btn btn-primary" href="<?= h(url('admin/payment_allocation.php')) ?>"><i class="fa-solid fa-people-arrows-left-right me-1" aria-hidden="true"></i>Combined Payment</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= h(url('admin/reports.php')) ?>">Reports</a>
    </div>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <?php if ($canAddFeeding || $canEditFeeding): ?>
                <h2><?= (int) $form['id'] > 0 ? 'Edit Subscription' : 'Add Subscription' ?></h2>
                <form class="row g-3" method="post">
                    <input type="hidden" name="feeding_subscription_id" value="<?= (int) $form['id'] ?>">
                    <input type="hidden" name="academic_year" value="<?= h((string) $form['academic_year']) ?>">
                    <input type="hidden" name="term" value="<?= h((string) $form['term']) ?>">
                    <div class="col-12">
                        <label class="form-label" for="student_id">Student</label>
                        <select class="form-select" id="student_id" name="student_id" required>
                            <option value="">Choose student...</option>
                            <?php foreach ($students as $student): ?>
                                <option value="<?= (int) $student['id'] ?>" <?= (int) $form['student_id'] === (int) $student['id'] ? 'selected' : '' ?>>
                                    <?= h($student['registration_no'] . ' - ' . $student['full_name'] . ' - ' . $student['class_level']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="academic_year">Academic Year</label>
                        <input class="form-control" id="academic_year" value="<?= h((string) $form['academic_year']) ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="term">Term</label>
                        <input class="form-control" id="term" value="<?= h((string) $form['term']) ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="feeding_amount">Required Amount</label>
                        <input class="form-control" type="number" min="0.01" step="0.01" id="feeding_amount" name="feeding_amount" value="<?= h((string) $form['feeding_amount']) ?>" placeholder="<?= h((string) $suggestedFeedingAmount) ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="status">Status</label>
                        <select class="form-select" id="status" name="status" required>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= h($status) ?>" <?= $form['status'] === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="action-row">
                            <?php if ($canAddFeeding && (int) $form['id'] === 0): ?><button class="btn btn-primary" name="action" value="save_subscription" type="submit">Save</button><?php endif; ?>
                            <?php if ($canEditFeeding && (int) $form['id'] > 0): ?><button class="btn btn-outline-primary" name="action" value="update_subscription" type="submit">Update</button><?php endif; ?>
                        </div>
                    </div>
                </form>
            <?php else: ?>
                <div class="alert alert-secondary">
                    You do not have permission to manage feeding subscriptions.
                </div>
            <?php endif; ?>
        </section>
        <?php if ($paymentTarget && $canRecordFeedingPayment): ?>
            <section class="panel mt-4">
                <h2>Record Feeding Payment</h2>
                <p class="text-muted"><?= h($paymentTarget['registration_no'] . ' - ' . $paymentTarget['full_name'] . ' - ' . $paymentTarget['term'] . ' ' . $paymentTarget['academic_year']) ?></p>
                <form class="row g-3" method="post">
                    <input type="hidden" name="payment_subscription_id" value="<?= (int) $paymentTarget['id'] ?>">
                    <div class="col-md-6">
                        <label class="form-label" for="amount_paid">Amount Paid</label>
                        <input class="form-control" type="number" min="0.01" step="0.01" id="amount_paid" name="amount_paid" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="payment_date">Date</label>
                        <input class="form-control" type="date" id="payment_date" name="payment_date" value="<?= h(date('Y-m-d')) ?>" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="reference_no">Reference</label>
                        <input class="form-control" id="reference_no" name="reference_no">
                    </div>
                    <div class="col-12">
                        <button class="btn btn-primary w-100" name="action" value="save_payment" type="submit">Save Payment</button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <h2>Feeding Report</h2>
            <form class="row g-3 mb-4" method="get">
                <div class="col-md-3"><input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search student"></div>
                <div class="col-md-3">
                    <select class="form-select" name="class_level">
                        <option value="">All classes</option>
                        <?php foreach ($classLevels as $classLevel): ?>
                            <option value="<?= h($classLevel) ?>" <?= $filterClass === $classLevel ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><input class="form-control" name="year" value="<?= h($filterYear) ?>" maxlength="4" placeholder="Year"></div>
                <div class="col-md-2">
                    <select class="form-select" name="term">
                        <option value="">All terms</option>
                        <?php foreach ($terms as $term): ?><option value="<?= h($term) ?>" <?= $filterTerm === $term ? 'selected' : '' ?>><?= h($term) ?></option><?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2"><button class="btn btn-outline-primary w-100" type="submit">Filter</button></div>
            </form>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr><th>Student Name</th><th>Class</th><th>Academic Year</th><th>Term</th><th>Required Amount</th><th>Paid Amount</th><th>Balance</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subscriptions as $subscription): ?>
                            <tr class="<?= (float) $subscription['feeding_balance'] > 0.005 ? 'balance-high' : '' ?>">
                                <td><?= h($subscription['full_name']) ?><br><small><?= h($subscription['registration_no']) ?></small></td>
                                <td><?= h($subscription['class_level']) ?></td>
                                <td><?= h($subscription['academic_year']) ?></td>
                                <td><?= h($subscription['term']) ?></td>
                                <td><?= money((float) $subscription['feeding_required']) ?></td>
                                <td><?= money((float) $subscription['feeding_paid']) ?></td>
                                <td><?= money((float) $subscription['feeding_balance']) ?></td>
                                <td><?= h($subscription['status']) ?></td>
                                <td>
                                    <div class="action-row">
                                        <?php if ($canEditFeeding): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/feeding.php?edit=' . $subscription['id']) ?>">Edit</a><?php endif; ?>
                                        <?php if ($canRecordFeedingPayment && (float) $subscription['feeding_balance'] > 0.005): ?><a class="btn btn-sm btn-primary" href="<?= url('admin/feeding.php?pay=' . $subscription['id']) ?>">Pay</a><?php endif; ?>
                                        <?php if ($canDeleteFeeding): ?><form method="post" onsubmit="return confirm('Remove this feeding subscription and its payments?');">
                                            <input type="hidden" name="feeding_subscription_id" value="<?= (int) $subscription['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="delete_subscription" type="submit">Delete</button>
                                        </form><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$subscriptions): ?><tr><td colspan="9">No feeding subscriptions found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php if ($canViewFeeding): ?>
        <section class="panel mt-4">
            <h2>Payment History</h2>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead><tr><th>Student</th><th>Term</th><th>Amount</th><th>Balance after payment</th><th>Date</th><th>Reference</th><th>Receipt</th></tr></thead>
                    <tbody>
                        <?php foreach ($paymentHistory as $payment): ?>
                            <tr>
                                <td><?= h($payment['full_name']) ?><br><small><?= h($payment['registration_no']) ?></small></td>
                                <td><?= h($payment['term'] . ' ' . $payment['academic_year']) ?></td>
                                <td><?= money((float) $payment['amount_paid']) ?></td>
                                <td><?= money((float) $payment['balance_after_payment']) ?></td>
                                <td><?= h($payment['payment_date']) ?></td>
                                <td><?= h($payment['reference_no']) ?></td>
                                <td><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/feeding_receipt.php?id=' . (int)$payment['id']) ?>">Receipt</a></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$paymentHistory): ?><tr><td colspan="7">No feeding payments recorded.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <?php endif; ?>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
