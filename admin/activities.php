<?php
$pageTitle = 'Activities & Trips';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/activities_helpers.php';
require_once __DIR__ . '/../includes/payment_transactions.php';

activities_ensure_schema($pdo);
$actorId = (int) ($_SESSION['admin_id'] ?? 0);
$context = current_academic_context($pdo);
$terms = ['Term 1', 'Term 2', 'Term 3'];
$activityTypes = ['Swimming' => 'Swimming', 'Trip' => 'Educational trip', 'Other' => 'Other activity'];
$csrf = csrf_token();

function activities_valid_date(string $date): bool
{
    $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    return $parsed instanceof DateTimeImmutable && $parsed->format('Y-m-d') === $date;
}

function activities_require_action(PDO $pdo, string $permission): void
{
    if (!current_admin_has_permission($pdo, $permission)) {
        rbac_deny($pdo, 'You do not have permission to perform this activity action.');
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    $returnEdit = filter_var($_POST['return_edit'] ?? '', FILTER_VALIDATE_INT) ?: 0;
    $returnToForm = (string) ($_POST['return_form'] ?? '') === '1';
    try {
        if ($action === 'save_activity') {
            activities_require_action($pdo, 'activities.manage');
            $activityId = filter_var($_POST['activity_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
            $name = trim((string) ($_POST['name'] ?? ''));
            $type = activity_type_from_name($name);
            $activityDate = trim((string) ($_POST['activity_date'] ?? ''));
            $year = trim((string) ($_POST['academic_year'] ?? ''));
            $term = (string) ($_POST['term'] ?? '');
            $cost = filter_var($_POST['cost_per_student'] ?? null, FILTER_VALIDATE_FLOAT);
            $notes = trim((string) ($_POST['notes'] ?? ''));
            $studentIds = array_values(array_unique(array_filter(array_map('intval', (array) ($_POST['participant_ids'] ?? [])), static fn($id) => $id > 0)));
            if ($name === '' || mb_strlen($name) > 150 || !activities_valid_date($activityDate)
                || !preg_match('/^\d{4}$/', $year) || !in_array($term, $terms, true) || $cost === false || $cost < 0 || $cost > 9999999999.99) {
                throw new InvalidArgumentException('Enter a valid activity name, date, academic period, and non-negative cost.');
            }
            if (!$studentIds) {
                throw new InvalidArgumentException('Choose at least one participating student.');
            }
            if (count($studentIds) > 2000) {
                throw new InvalidArgumentException('Select no more than 2,000 participating students at a time.');
            }
            $calendar = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1');
            $calendar->execute(['year' => $year, 'term' => $term]);
            $termRange = $calendar->fetch();
            if (!$termRange) {
                throw new InvalidArgumentException('That term is not set up in the Academic Calendar yet.');
            }
            if ($activityDate < $termRange['start_date'] || $activityDate > $termRange['end_date']) {
                throw new InvalidArgumentException('The activity date must fall within the selected academic term.');
            }

            $placeholders = [];
            $studentParams = ['selected_year' => $year];
            foreach ($studentIds as $index => $studentId) {
                $placeholder = 'student_' . $index;
                $placeholders[] = ':' . $placeholder;
                $studentParams[$placeholder] = $studentId;
            }
            $classExpr = student_class_sql('s', ':selected_year');
            $studentQuery = $pdo->prepare("SELECT s.id, {$classExpr} AS class_level FROM students s WHERE s.enrollment_status = 'Active' AND s.id IN (" . implode(',', $placeholders) . ')');
            $studentQuery->execute($studentParams);
            $eligible = $studentQuery->fetchAll();
            if (count($eligible) !== count($studentIds)) {
                throw new InvalidArgumentException('One or more selected students are no longer active. Refresh and try again.');
            }
            $classes = array_values(array_unique(array_filter(array_map(static fn($student) => trim((string) $student['class_level']), $eligible))));

            $pdo->beginTransaction();
            try {
                $old = null;
                if ($activityId > 0) {
                    $lock = $pdo->prepare("SELECT * FROM activities WHERE id = :id AND status = 'Active' FOR UPDATE");
                    $lock->execute(['id' => $activityId]);
                    $old = $lock->fetch();
                    if (!$old) {
                        throw new InvalidArgumentException('This activity is missing or has already been cancelled.');
                    }
                    $update = $pdo->prepare('UPDATE activities SET name = :name, activity_type = :type, activity_date = :date, academic_year = :year, term = :term, cost_per_student = :cost, notes = :notes, updated_by = :actor WHERE id = :id');
                    $update->execute(['name' => $name, 'type' => $type, 'date' => $activityDate, 'year' => $year, 'term' => $term, 'cost' => $cost, 'notes' => $notes ?: null, 'actor' => $actorId, 'id' => $activityId]);
                    $oldClassesStmt = $pdo->prepare('SELECT class_level FROM activity_classes WHERE activity_id = :id ORDER BY class_level');
                    $oldClassesStmt->execute(['id' => $activityId]);
                    $oldClasses = array_column($oldClassesStmt->fetchAll(), 'class_level');
                    $pdo->prepare('DELETE FROM activity_classes WHERE activity_id = :id')->execute(['id' => $activityId]);
                    activity_audit($pdo, $activityId, 'activity_updated', ['before' => $old, 'classes_before' => $oldClasses, 'classes_after' => $classes, 'participants_after' => count($studentIds), 'cost_per_student' => (float) $cost]);
                } else {
                    $insert = $pdo->prepare('INSERT INTO activities (name, activity_type, activity_date, academic_year, term, cost_per_student, notes, created_by, updated_by) VALUES (:name, :type, :date, :year, :term, :cost, :notes, :created_by, :updated_by)');
                    $insert->execute(['name' => $name, 'type' => $type, 'date' => $activityDate, 'year' => $year, 'term' => $term, 'cost' => $cost, 'notes' => $notes ?: null, 'created_by' => $actorId, 'updated_by' => $actorId]);
                    $activityId = (int) $pdo->lastInsertId();
                    activity_audit($pdo, $activityId, 'activity_created', ['name' => $name, 'type' => $type, 'academic_year' => $year, 'term' => $term, 'classes' => $classes, 'participants' => count($studentIds), 'cost_per_student' => (float) $cost]);
                }
                $classInsert = $pdo->prepare('INSERT INTO activity_classes (activity_id, class_level) VALUES (:activity_id, :class_level)');
                foreach ($classes as $className) {
                    $classInsert->execute(['activity_id' => $activityId, 'class_level' => $className]);
                }
                $withdraw = $pdo->prepare("UPDATE activity_participants SET participation_status = 'Withdrawn' WHERE activity_id = :activity_id AND participation_status = 'Active'");
                $withdraw->execute(['activity_id' => $activityId]);
                $participantUpsert = $pdo->prepare("INSERT INTO activity_participants (activity_id, student_id, expected_amount, participation_status) VALUES (:activity_id, :student_id, :expected_amount, 'Active') ON DUPLICATE KEY UPDATE expected_amount = VALUES(expected_amount), participation_status = 'Active'");
                foreach ($studentIds as $studentId) {
                    $participantUpsert->execute(['activity_id' => $activityId, 'student_id' => $studentId, 'expected_amount' => $cost]);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $exception;
            }
            flash('success', $old ? 'Activity updated.' : 'Activity created.');
            redirect('admin/activities.php');
        }

        if ($action === 'cancel_activity') {
            activities_require_action($pdo, 'activities.manage');
            $activityId = filter_var($_POST['activity_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($activityId <= 0 || $reason === '') { throw new InvalidArgumentException('Enter a reason before cancelling an activity.'); }
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("UPDATE activities SET status = 'Cancelled', updated_by = :actor WHERE id = :id AND status = 'Active'");
                $stmt->execute(['actor' => $actorId, 'id' => $activityId]);
                if ($stmt->rowCount() !== 1) { throw new InvalidArgumentException('This activity is already cancelled or could not be found.'); }
                activity_audit($pdo, $activityId, 'activity_cancelled', ['reason' => $reason]);
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $exception;
            }
            flash('success', 'Activity cancelled. Existing student and payment records remain available.');
            redirect('admin/activities.php');
        }

        if ($action === 'collect_payment') {
            activities_require_action($pdo, 'activities.payments.collect');
            $participantId = filter_var($_POST['participant_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
            $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
            $paymentDate = trim((string) ($_POST['payment_date'] ?? ''));
            $method = (string) ($_POST['payment_method'] ?? 'Cash');
            $activityId = filter_var($_POST['activity_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
            $reference = trim((string) ($_POST['reference_no'] ?? ''));
            $requestKey = (string) ($_POST['request_key'] ?? '');
            if ($participantId <= 0 || $amount === false || $amount <= 0 || $amount > 9999999999.99 || !activities_valid_date($paymentDate)
                || !in_array($method, ['Cash', 'M-PESA'], true) || ($method === 'M-PESA' && $reference === '') || mb_strlen($reference) > 100
                || !preg_match('/^[a-f0-9]{64}$/', $requestKey)) {
                throw new InvalidArgumentException('Enter a valid activity, student, payment date, method, amount, and required M-Pesa code.');
            }
            $referenceLock = null;
            if ($method === 'M-PESA') {
                $reference = normalize_payment_code($reference);
                $referenceLock = fee_acquire_payment_reference_lock($pdo, $reference);
                if (payment_code_duplicate_exists($pdo, $reference, 'activity_reference')) {
                    fee_release_payment_reference_lock($pdo, $referenceLock);
                    throw new InvalidArgumentException('This M-Pesa transaction has already been recorded. Check the existing receipt before retrying.');
                }
            }
            $pdo->beginTransaction();
            try {
                $paymentLock = $pdo->prepare("SELECT ap.id, ap.activity_id, ap.expected_amount, ap.participation_status, a.name, a.academic_year, a.term, a.status AS activity_status FROM activity_participants ap JOIN activities a ON a.id = ap.activity_id WHERE ap.id = :id FOR UPDATE");
                $paymentLock->execute(['id' => $participantId]);
                $participant = $paymentLock->fetch();
                if (!$participant || (int) $participant['activity_id'] !== $activityId || $participant['activity_status'] !== 'Active' || $participant['participation_status'] !== 'Active') {
                    throw new InvalidArgumentException('Payments can only be recorded for an active participant in an active activity.');
                }
                $duplicate = $pdo->prepare('SELECT id FROM activity_payments WHERE request_key = :key LIMIT 1');
                $duplicate->execute(['key' => $requestKey]);
                if ($duplicate->fetchColumn()) { throw new InvalidArgumentException('This payment was already submitted.'); }
                $paidStmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) FROM activity_payments WHERE participant_id = :participant_id AND status = 'Recorded'");
                $paidStmt->execute(['participant_id' => $participantId]);
                $outstanding = max(0.0, (float) $participant['expected_amount'] - (float) $paidStmt->fetchColumn());
                if ($amount > $outstanding + 0.005) { throw new InvalidArgumentException('The payment is greater than the remaining activity balance.'); }
                $insert = $pdo->prepare("INSERT INTO activity_payments (activity_id, participant_id, receipt_no, request_key, amount, payment_date, academic_year, term, payment_method, reference_no, recorded_by) VALUES (:activity_id, :participant_id, :receipt, :request_key, :amount, :date, :year, :term, :method, :reference, :actor)");
                $insert->execute(['activity_id' => $participant['activity_id'], 'participant_id' => $participantId, 'receipt' => 'PENDING-' . strtoupper(bin2hex(random_bytes(10))), 'request_key' => $requestKey, 'amount' => $amount, 'date' => $paymentDate, 'year' => $participant['academic_year'], 'term' => $participant['term'], 'method' => $method, 'reference' => $reference ?: null, 'actor' => $actorId]);
                $paymentId = (int) $pdo->lastInsertId();
                $receipt = sprintf('ATXACT-%04d-%06d', (int) $participant['academic_year'], $paymentId);
                $pdo->prepare('UPDATE activity_payments SET receipt_no = :receipt WHERE id = :id')->execute(['receipt' => $receipt, 'id' => $paymentId]);
                payment_transaction_attach_legacy($pdo, 'activity', $paymentId, [
                    'total_amount' => $amount,
                    'payment_method' => $method,
                    'mpesa_code' => $method === 'M-PESA' ? $reference : '',
                    'payment_date' => $paymentDate,
                    'student_id' => (int) $participant['student_id'],
                    'payment_category' => $participant['activity_type'] . ': ' . $participant['name'],
                    'academic_year' => $participant['academic_year'],
                    'term' => $participant['term'],
                    'recorded_by' => $actorId,
                    'request_key' => $requestKey,
                ]);
                activity_audit($pdo, (int) $participant['activity_id'], 'payment_recorded', ['receipt_no' => $receipt, 'amount' => (float) $amount, 'payment_method' => $method], $paymentId);
                $pdo->commit();
                if ($referenceLock !== null) fee_release_payment_reference_lock($pdo, $referenceLock);
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                if ($referenceLock !== null) fee_release_payment_reference_lock($pdo, $referenceLock);
                throw $exception;
            }
            flash('success', 'Payment recorded. Receipt ' . $receipt . '.');
            redirect('admin/activities.php');
        }

        if ($action === 'reverse_payment' || $action === 'refund_payment') {
            activities_require_action($pdo, 'activities.payments.reverse');
            $paymentId = filter_var($_POST['payment_id'] ?? '', FILTER_VALIDATE_INT) ?: 0;
            $reason = trim((string) ($_POST['reason'] ?? ''));
            if ($paymentId <= 0 || $reason === '' || mb_strlen($reason) > 500) {
                throw new InvalidArgumentException('Enter a reason for this payment action.');
            }
            $pdo->beginTransaction();
            try {
                $lock = $pdo->prepare("SELECT p.*, a.name AS activity_name FROM activity_payments p JOIN activities a ON a.id = p.activity_id WHERE p.id = :id FOR UPDATE");
                $lock->execute(['id' => $paymentId]);
                $payment = $lock->fetch();
                if (!$payment || $payment['status'] !== 'Recorded') { throw new InvalidArgumentException('Only a recorded payment can be reversed or refunded.'); }
                $newStatus = $action === 'refund_payment' ? 'Refunded' : 'Reversed';
                $pdo->prepare('UPDATE activity_payments SET status = :status, reversed_by = :actor, reversed_at = NOW(), reversal_reason = :reason WHERE id = :id')->execute(['status' => $newStatus, 'actor' => $actorId, 'reason' => $reason, 'id' => $paymentId]);
                payment_transaction_sync_legacy_status($pdo, 'activity', $paymentId, $newStatus);
                if ($newStatus === 'Refunded') {
                    activity_audit($pdo, (int) $payment['activity_id'], 'payment_refunded', ['receipt_no' => $payment['receipt_no'], 'amount' => (float) $payment['amount'], 'reason' => $reason], $paymentId);
                } else {
                    activity_audit($pdo, (int) $payment['activity_id'], 'payment_reversed', ['receipt_no' => $payment['receipt_no'], 'amount' => (float) $payment['amount'], 'reason' => $reason], $paymentId);
                }
                $pdo->commit();
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                throw $exception;
            }
            flash('success', $newStatus === 'Refunded' ? 'Payment marked refunded; it no longer counts as collected income.' : 'Payment reversed; it no longer counts as collected income.');
            redirect('admin/activities.php');
        }

        throw new InvalidArgumentException('The requested action was not recognized.');
    } catch (Throwable $exception) {
        error_log('Activities & Trips action failed: ' . $exception->getMessage());
        flash('error', $exception instanceof InvalidArgumentException ? $exception->getMessage() : 'The entry could not be saved. No partial changes were kept.');
        if ($returnToForm) {
            $_SESSION['activity_form_old'] = $_POST;
            $formQuery = [];
            if ($returnEdit > 0) { $formQuery['edit'] = $returnEdit; }
            $postedYear = (string) ($_POST['academic_year'] ?? '');
            if (preg_match('/^\d{4}$/', $postedYear)) { $formQuery['students_year'] = $postedYear; }
            redirect('admin/activity_form.php' . ($formQuery ? '?' . http_build_query($formQuery) : ''));
        }
        redirect('admin/activities.php');
    }
}

$activityList = $pdo->query("SELECT a.*,
    (SELECT COUNT(*) FROM activity_participants ap WHERE ap.activity_id = a.id AND ap.participation_status = 'Active') AS participant_count,
    (SELECT COALESCE(SUM(ap.expected_amount), 0) FROM activity_participants ap WHERE ap.activity_id = a.id AND ap.participation_status = 'Active' AND a.status = 'Active') AS expected_total,
    (SELECT COALESCE(SUM(p.amount), 0) FROM activity_payments p WHERE p.activity_id = a.id AND p.status = 'Recorded') AS collected_total
    FROM activities a ORDER BY a.activity_date DESC, a.id DESC LIMIT 12")->fetchAll();
$participantPaidSql = activity_paid_sql('ap');
$participantOptions = $pdo->query("SELECT ap.id, ap.expected_amount, ap.activity_id, a.name AS activity_name, a.academic_year, a.term, s.full_name, s.registration_no,
    {$participantPaidSql} AS amount_paid
    FROM activity_participants ap JOIN activities a ON a.id = ap.activity_id JOIN students s ON s.id = ap.student_id
    WHERE a.status = 'Active' AND ap.participation_status = 'Active'
    ORDER BY a.activity_date DESC, s.full_name ASC")->fetchAll();
$paymentRows = $pdo->query("SELECT p.*, a.name AS activity_name, s.full_name, s.registration_no
    FROM activity_payments p JOIN activities a ON a.id = p.activity_id
    JOIN activity_participants ap ON ap.id = p.participant_id JOIN students s ON s.id = ap.student_id
    ORDER BY p.id DESC LIMIT 8")->fetchAll();
$auditRows = $pdo->query("SELECT l.*, a.name AS activity_name, u.name AS actor_name FROM activity_audit_logs l JOIN activities a ON a.id = l.activity_id LEFT JOIN admin u ON u.id = l.actor_id ORDER BY l.id DESC LIMIT 12")->fetchAll();
$periodSummary = activity_report_data($pdo, ['year' => $context['academic_year'], 'term' => $context['term']]);
$canManageActivities = current_admin_has_permission($pdo, 'activities.manage');
$canCollectActivityPayments = current_admin_has_permission($pdo, 'activities.payments.collect');
$collectedPercent = $periodSummary['expected'] > 0
    ? (int) round(min(100, $periodSummary['collected'] / $periodSummary['expected'] * 100))
    : 0;
$pageTitle = 'Activities & Trips';
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="activities-dashboard">
    <div class="page-title">
        <div><p class="eyebrow">School Activities</p><h1>Activities &amp; Trips</h1><p class="text-muted mb-0">Collect and track swimming, trips, and other activity payments.</p></div>
        <?php if ($canCollectActivityPayments || current_admin_has_permission($pdo, 'fees.record') || current_admin_has_permission($pdo, 'daycare.payments.record')): ?><a class="btn btn-outline-primary" href="<?= h(url('admin/payment_allocation.php')) ?>"><i class="fa-solid fa-people-arrows-left-right me-2"></i>Combined Payment</a><?php endif; ?>
    </div>
    <?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
    <?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>

    <section class="row g-3 mb-4 activity-metrics" aria-label="Current term activity collections">
        <div class="col-md-4"><div class="activity-metric activity-metric-expected"><span class="activity-metric-icon"><i class="fa-solid fa-bullseye"></i></span><div><small>Total Expected</small><strong><?= money($periodSummary['expected']) ?></strong><span><?= h($context['academic_year'] . ' · ' . $context['term']) ?></span></div></div></div>
        <div class="col-md-4"><div class="activity-metric activity-metric-collected"><span class="activity-metric-icon"><i class="fa-solid fa-circle-check"></i></span><div><small>Total Collected</small><strong><?= money($periodSummary['collected']) ?></strong><span><?= $collectedPercent ?>% of expected</span></div></div></div>
        <div class="col-md-4"><div class="activity-metric activity-metric-outstanding"><span class="activity-metric-icon"><i class="fa-solid fa-triangle-exclamation"></i></span><div><small>Outstanding</small><strong><?= money($periodSummary['outstanding']) ?></strong><span><?= max(0, 100 - $collectedPercent) ?>% pending</span></div></div></div>
    </section>

    <div class="row g-4 mb-4">
        <div class="col-xl-8">
            <section class="panel activity-panel h-100">
                <div class="panel-heading"><div><h2>Recent Activities</h2><p class="panel-subtitle">Expected amounts, recorded collections, and balances.</p></div><?php if ($canManageActivities): ?><a class="btn btn-primary" href="<?= url('admin/activity_form.php') ?>"><i class="fa-solid fa-plus me-2"></i>New Activity</a><?php endif; ?></div>
                <div class="table-responsive"><table class="table table-striped align-middle activity-table"><thead><tr><th>#</th><th>Activity Name</th><th>Date</th><th>Term</th><th>Classes</th><th>Expected</th><th>Collected</th><th>Balance</th><th>Status</th><th>Actions</th></tr></thead><tbody>
                <?php foreach ($activityList as $index => $activity):
                    $activityClassesStmt = $pdo->prepare('SELECT class_level FROM activity_classes WHERE activity_id = :id ORDER BY class_level');
                    $activityClassesStmt->execute(['id' => $activity['id']]);
                    $activityClassList = array_column($activityClassesStmt->fetchAll(), 'class_level');
                    $due = max(0.0, (float) $activity['expected_total'] - (float) $activity['collected_total']);
                    $paymentStatus = $activity['status'] === 'Cancelled' ? 'Cancelled' : ((float) $activity['expected_total'] <= 0.005 || $due <= 0.005 ? 'Fully Paid' : ((float) $activity['collected_total'] > 0.005 ? 'Partially Paid' : 'Unpaid'));
                    $statusClass = $paymentStatus === 'Fully Paid' ? 'text-bg-success' : (in_array($paymentStatus, ['Partially Paid', 'Unpaid'], true) ? 'text-bg-warning' : 'text-bg-secondary');
                ?>
                    <tr><td><?= $index + 1 ?></td><td><strong><?= h($activity['name']) ?></strong></td><td><?= h(date('d M Y', strtotime($activity['activity_date']))) ?></td><td><?= h($activity['term']) ?></td><td><?= h(implode(', ', $activityClassList) ?: '—') ?></td><td><?= money((float) $activity['expected_total']) ?></td><td><?= money((float) $activity['collected_total']) ?></td><td><?= money($due) ?></td><td><span class="badge <?= $statusClass ?>"><?= h($paymentStatus) ?></span></td><td><div class="activity-actions">
                        <?php if ($activity['status'] === 'Active' && $canManageActivities): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/activity_form.php?edit=' . (int) $activity['id']) ?>" title="Edit activity" aria-label="Edit activity"><i class="fa-solid fa-pen"></i></a><form method="post" onsubmit="return requestActivityReason(this, 'Why are you cancelling this activity?');"><input type="hidden" name="_csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="cancel_activity"><input type="hidden" name="activity_id" value="<?= (int) $activity['id'] ?>"><input type="hidden" name="reason" value=""><button class="btn btn-sm btn-outline-danger" type="submit" title="Cancel activity" aria-label="Cancel activity"><i class="fa-solid fa-xmark"></i></button></form><?php endif; ?>
                    </div></td></tr>
                <?php endforeach; ?>
                <?php if (!$activityList): ?><tr><td colspan="10" class="text-center text-muted py-4">No activities yet.<?php if ($canManageActivities): ?> <a href="<?= url('admin/activity_form.php') ?>">Create the first activity</a>.<?php endif; ?></td></tr><?php endif; ?>
                </tbody></table></div>
            </section>
        </div>
        <div class="col-xl-4">
            <section class="panel activity-panel activity-summary h-100">
                <div class="panel-heading"><div><h2>Activity Summary</h2><p class="panel-subtitle"><?= h($context['academic_year'] . ' · ' . $context['term']) ?></p></div></div>
                <div class="activity-summary-body">
                    <div class="activity-donut <?= $periodSummary['expected'] > 0 ? '' : 'activity-donut-empty' ?>" style="--collected: <?= $collectedPercent ?>%"><div><strong><?= $collectedPercent ?>%</strong><span>Collected</span></div></div>
                    <div class="activity-summary-legend"><div><span><i class="activity-dot activity-dot-collected"></i>Collected</span><strong><?= money($periodSummary['collected']) ?></strong></div><div><span><i class="activity-dot activity-dot-balance"></i>Outstanding</span><strong><?= money($periodSummary['outstanding']) ?></strong></div></div>
                </div>
            </section>
        </div>
    </div>

    <div class="row g-4 mb-4">
        <?php if ($canCollectActivityPayments): ?>
        <div class="col-xl-6"><section class="panel activity-panel h-100"><div class="panel-heading"><div><h2>Record Payment</h2><p class="panel-subtitle">Record money received for a participating student.</p></div></div>
            <form method="post" class="row g-3" id="activity-payment-form"><input type="hidden" name="_csrf_token" value="<?= h($csrf) ?>"><input type="hidden" name="action" value="collect_payment"><input type="hidden" name="request_key" value="<?= h(bin2hex(random_bytes(32))) ?>">
                <div class="col-12"><label class="form-label" for="payment-activity">Activity</label><select class="form-select" id="payment-activity" name="activity_id" required><option value="">Choose activity</option><?php foreach ($activityList as $activity): if ($activity['status'] !== 'Active') { continue; } ?><option value="<?= (int) $activity['id'] ?>"><?= h($activity['name'] . ' · ' . $activity['academic_year'] . ' ' . $activity['term']) ?></option><?php endforeach; ?></select></div>
                <div class="col-12"><label class="form-label" for="payment-participant">Student</label><select class="form-select" id="payment-participant" name="participant_id" required disabled><option value="">Choose activity first</option><?php foreach ($participantOptions as $participant): ?><option value="<?= (int) $participant['id'] ?>" data-activity="<?= (int) $participant['activity_id'] ?>" data-expected="<?= h((string) $participant['expected_amount']) ?>" data-paid="<?= h((string) $participant['amount_paid']) ?>" data-registration="<?= h($participant['registration_no']) ?>" data-name="<?= h($participant['full_name']) ?>"><?= h($participant['full_name'] . ' · ' . $participant['registration_no']) ?></option><?php endforeach; ?></select><small class="text-muted" id="payment-student-number"></small></div>
                <div class="col-md-6"><label class="form-label" for="payment-amount">Amount paid (KES)</label><input class="form-control" type="number" id="payment-amount" name="amount" min="0.01" step="0.01" required></div>
                <div class="col-md-6"><label class="form-label" for="payment-date">Payment date</label><input class="form-control" type="date" id="payment-date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div>
                <div class="col-md-6"><label class="form-label" for="payment-method">Payment method</label><select class="form-select" id="payment-method" name="payment_method" required><option value="Cash">Cash</option><option value="M-PESA">M-Pesa</option></select></div>
                <div class="col-md-6" id="payment-reference-wrap" hidden><label class="form-label" for="payment-reference">M-Pesa code</label><input class="form-control" id="payment-reference" name="reference_no" maxlength="100"></div>
                <div class="col-12"><div class="activity-balance" id="payment-outstanding" role="status">Outstanding balance: KES 0.00</div></div>
                <div class="col-12"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i>Save Payment</button></div>
            </form>
        </section></div>
        <?php endif; ?>
        <div class="col-xl-6"><section class="panel activity-panel h-100"><div class="panel-heading"><div><h2>Recent Activity Payments</h2><p class="panel-subtitle">Payment history and receipts.</p></div></div>
            <div class="table-responsive"><table class="table table-striped align-middle activity-payment-table"><thead><tr><th>Date</th><th>Student</th><th>Activity</th><th>Amount</th><th>Method</th><th>Receipt</th></tr></thead><tbody>
            <?php foreach ($paymentRows as $payment): ?><tr><td><?= h(date('d M Y', strtotime($payment['payment_date']))) ?></td><td><?= h($payment['full_name']) ?><small class="d-block text-muted"><?= h($payment['registration_no']) ?></small></td><td><?= h($payment['activity_name']) ?></td><td><strong><?= money((float) $payment['amount']) ?></strong><small class="d-block"><span class="badge <?= $payment['status'] === 'Recorded' ? 'text-bg-success' : ($payment['status'] === 'Refunded' ? 'text-bg-warning' : 'text-bg-secondary') ?>"><?= h($payment['status']) ?></span></small></td><td><?= h($payment['payment_method']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/activity_receipt.php?id=' . (int) $payment['id']) ?>" target="_blank" rel="noopener" title="View receipt" aria-label="View receipt">Receipt</a></td></tr><?php endforeach; ?>
            <?php if (!$paymentRows): ?><tr><td colspan="6" class="text-center text-muted py-3">No activity payments have been recorded.</td></tr><?php endif; ?>
            </tbody></table></div>
        </section></div>
    </div>

    <?php if ($canManageActivities): ?><section class="panel activity-panel mb-4"><div class="panel-heading"><div><h2>Activity Audit History</h2><p class="panel-subtitle">Recent activity changes and payment reversals.</p></div></div><div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Time</th><th>Activity</th><th>Action</th><th>By</th><th>Details</th></tr></thead><tbody><?php foreach ($auditRows as $log): ?><tr><td><?= h($log['created_at']) ?></td><td><?= h($log['activity_name']) ?></td><td><?= h(str_replace('_', ' ', ucfirst($log['action']))) ?></td><td><?= h($log['actor_name'] ?? 'Former user') ?></td><td><?= h((string) $log['details']) ?></td></tr><?php endforeach; ?><?php if (!$auditRows): ?><tr><td colspan="5" class="text-center text-muted py-3">Audit entries will appear as records are created or changed.</td></tr><?php endif; ?></tbody></table></div></section><?php endif; ?>
</div>

<script>
function requestActivityReason(form, message) {
    const reason = window.prompt(message);
    if (!reason || !reason.trim()) return false;
    form.elements.reason.value = reason.trim();
    return true;
}
(function () {
    const activity = document.getElementById('payment-activity');
    const student = document.getElementById('payment-participant');
    const method = document.getElementById('payment-method');
    const reference = document.getElementById('payment-reference');
    const referenceWrap = document.getElementById('payment-reference-wrap');
    const amount = document.getElementById('payment-amount');
    const studentNumber = document.getElementById('payment-student-number');
    const outstanding = document.getElementById('payment-outstanding');
    if (!activity || !student) return;

    const formatMoney = (value) => new Intl.NumberFormat('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(value);
    const updateStudents = () => {
        let available = 0;
        Array.from(student.options).slice(1).forEach((option) => {
            const due = Math.max(0, Number(option.dataset.expected || 0) - Number(option.dataset.paid || 0));
            option.hidden = option.disabled = !activity.value || option.dataset.activity !== activity.value || due <= 0.005;
            if (!option.hidden) available++;
        });
        student.value = '';
        student.disabled = !activity.value || available === 0;
        student.options[0].textContent = !activity.value ? 'Choose activity first' : (available ? 'Choose student' : 'No outstanding students');
        updateBalance();
    };
    const updateBalance = () => {
        const option = student.selectedOptions[0];
        const due = option && option.value ? Math.max(0, Number(option.dataset.expected || 0) - Number(option.dataset.paid || 0)) : 0;
        studentNumber.textContent = option && option.value ? 'Admission number: ' + option.dataset.registration : '';
        outstanding.textContent = 'Outstanding balance: KES ' + formatMoney(due);
        amount.max = due > 0 ? due.toFixed(2) : '';
        if (Number(amount.value) > due) amount.value = due > 0 ? due.toFixed(2) : '';
    };
    const updateMethod = () => {
        const mpesa = method.value === 'M-PESA';
        reference.required = mpesa;
        referenceWrap.hidden = !mpesa;
        if (!mpesa) reference.value = '';
    };
    activity.addEventListener('change', updateStudents);
    student.addEventListener('change', updateBalance);
    method.addEventListener('change', updateMethod);
    updateStudents();
    updateMethod();
})();
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
