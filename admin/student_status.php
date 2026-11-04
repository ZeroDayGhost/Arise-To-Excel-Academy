<?php
$pageTitle = 'Student Status';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

if (!current_admin_has_permission($pdo, 'students.transfer')) {
    flash('error', 'You do not have permission to manage student enrollment status.');
    redirect('admin/students.php');
}

$studentId = (int) ($_GET['id'] ?? $_POST['student_id'] ?? 0);
$studentQuery = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
$studentQuery->execute(['id' => $studentId]);
$student = $studentQuery->fetch();
if (!$student) {
    flash('error', 'The student record was not found.');
    redirect('admin/students.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $newStatus = trim((string) ($_POST['new_status'] ?? ''));
    $effectiveDate = trim((string) ($_POST['effective_date'] ?? ''));
    $destinationSchool = trim((string) ($_POST['destination_school'] ?? ''));
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));
    $confirmed = ($_POST['confirm_change'] ?? '') === '1';

    if (!in_array($newStatus, student_enrollment_statuses(), true)) {
        $errors[] = 'Choose a valid enrollment status.';
    }
    $date = DateTime::createFromFormat('Y-m-d', $effectiveDate);
    if (!$date || $date->format('Y-m-d') !== $effectiveDate) {
        $errors[] = 'Enter a valid effective date.';
    }
    if ($newStatus === 'Transferred Out' && $destinationSchool === '') {
        $errors[] = 'Enter the destination school for a transfer.';
    }
    if (mb_strlen($destinationSchool) > 150 || mb_strlen($reason) > 255 || mb_strlen($notes) > 10000) {
        $errors[] = 'Destination school, reason, or notes exceed the allowed length.';
    }
    if (!$confirmed) {
        $errors[] = 'Confirm that you want to change this student\'s enrollment status.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $lock = $pdo->prepare('SELECT * FROM students WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $studentId]);
            $lockedStudent = $lock->fetch();
            if (!$lockedStudent) {
                throw new RuntimeException('The student record could not be found.');
            }
            $previousStatus = (string) $lockedStudent['enrollment_status'];
            if ($previousStatus === $newStatus) {
                throw new InvalidArgumentException('The student already has that enrollment status.');
            }

            if ($newStatus === 'Active') {
                $context = current_academic_context($pdo);
                if (!get_fee_structure($pdo, (string) $lockedStudent['class_level'], (string) $context['academic_year'])) {
                    throw new InvalidArgumentException('Create the current-year fee structure for this class before reactivating the student.');
                }
            }

            $update = $pdo->prepare('UPDATE students SET enrollment_status = :status WHERE id = :id');
            $update->execute(['status' => $newStatus, 'id' => $studentId]);
            $history = $pdo->prepare(
                'INSERT INTO student_status_history
                    (student_id, previous_status, new_status, effective_date, destination_school, reason, notes, changed_by)
                 VALUES (:student_id, :previous_status, :new_status, :effective_date, :destination_school, :reason, :notes, :changed_by)'
            );
            $history->execute([
                'student_id' => $studentId,
                'previous_status' => $previousStatus,
                'new_status' => $newStatus,
                'effective_date' => $effectiveDate,
                'destination_school' => $destinationSchool !== '' ? $destinationSchool : null,
                'reason' => $reason !== '' ? $reason : null,
                'notes' => $notes !== '' ? $notes : null,
                'changed_by' => (int) ($_SESSION['admin_id'] ?? 0),
            ]);

            if ($newStatus === 'Active') {
                $context = current_academic_context($pdo);
                sync_fee_balance_for_student($pdo, $studentId, (string) $lockedStudent['class_level'], (string) $context['academic_year'], (string) $context['term']);
            }

            $pdo->commit();
            flash('success', $student['full_name'] . ' is now marked ' . $newStatus . '. The admission number and existing records were retained.');
            redirect('admin/student_history.php?id=' . $studentId);
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($exception instanceof InvalidArgumentException) {
                $errors[] = $exception->getMessage();
            } else {
                error_log('Student status change failed: ' . $exception->getMessage());
                $errors[] = 'The status change could not be saved. No change was made.';
            }
        }
    }
}

$historyQuery = $pdo->prepare(
    'SELECT h.*, a.name AS changed_by_name FROM student_status_history h
     LEFT JOIN admin a ON a.id = h.changed_by
     WHERE h.student_id = :student_id ORDER BY h.id DESC'
);
$historyQuery->execute(['student_id' => $studentId]);
$statusHistory = $historyQuery->fetchAll();
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div><p class="eyebrow">Student Records</p><h1>Manage Enrollment Status</h1><p class="mb-0 text-muted">Keep the admission identity and school history intact.</p></div>
    <div class="action-row"><a class="btn btn-outline-primary" href="<?= url('admin/student_history.php?id=' . $studentId) ?>">Student History</a><a class="btn btn-outline-primary" href="<?= url('admin/students.php') ?>">Back to Students</a></div>
</div>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<section class="panel mb-4">
    <div class="panel-heading"><h2><?= h($student['full_name']) ?></h2><span class="badge text-bg-<?= $student['enrollment_status'] === 'Active' ? 'success' : 'secondary' ?>"><?= h($student['enrollment_status']) ?></span></div>
    <p class="mb-0"><strong><?= h($student['registration_no']) ?></strong> · <?= h($student['class_level']) ?> · <?= h($student['parent_name']) ?></p>
</section>
<section class="panel mb-4">
    <h2>Record a Status Change</h2>
    <form method="post" class="row g-3" onsubmit="return confirm('Save this enrollment status change? Historical student, parent, and fee records will be retained.');">
        <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="student_id" value="<?= $studentId ?>">
        <div class="col-md-4"><label class="form-label" for="new_status">New status</label><select class="form-select" id="new_status" name="new_status" required><?php foreach (student_enrollment_statuses() as $status): ?><option value="<?= h($status) ?>" <?= (($_POST['new_status'] ?? '') === $status) ? 'selected' : '' ?>><?= h($status) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-4"><label class="form-label" for="effective_date">Effective date</label><input class="form-control" type="date" id="effective_date" name="effective_date" value="<?= h((string) ($_POST['effective_date'] ?? date('Y-m-d'))) ?>" required></div>
        <div class="col-md-4"><label class="form-label" for="destination_school">Destination school <span class="text-muted">(required for transfer)</span></label><input class="form-control" id="destination_school" name="destination_school" maxlength="150" value="<?= h((string) ($_POST['destination_school'] ?? '')) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="reason">Reason</label><input class="form-control" id="reason" name="reason" maxlength="255" value="<?= h((string) ($_POST['reason'] ?? '')) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="notes">Notes</label><textarea class="form-control" id="notes" name="notes" rows="2" maxlength="10000"><?= h((string) ($_POST['notes'] ?? '')) ?></textarea></div>
        <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="confirm_change" name="confirm_change" value="1" required><label class="form-check-label" for="confirm_change">I confirm this status change is authorized and the student's historic records must be retained.</label></div></div>
        <div class="col-12"><button class="btn btn-primary" type="submit">Save Status Change</button></div>
    </form>
</section>
<section class="panel">
    <h2>Status History</h2>
    <?php if ($statusHistory): ?><div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Effective</th><th>From</th><th>To</th><th>Destination</th><th>Reason</th><th>Recorded by</th><th>Recorded at</th></tr></thead><tbody><?php foreach ($statusHistory as $entry): ?><tr><td><?= h($entry['effective_date']) ?></td><td><?= h($entry['previous_status']) ?></td><td><?= h($entry['new_status']) ?></td><td><?= h($entry['destination_school'] ?: '—') ?></td><td><?= h($entry['reason'] ?: '—') ?></td><td><?= h($entry['changed_by_name'] ?: 'System') ?></td><td><?= h($entry['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="text-muted mb-0">No status changes have been recorded.</p><?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
