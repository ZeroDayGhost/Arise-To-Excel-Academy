<?php
$pageTitle = 'Student History';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/student_lifecycle_helpers.php';

if (!current_admin_has_permission($pdo, 'students.view')) {
    flash('error', 'You do not have permission to view student records.');
    redirect('admin/students.php');
}

$studentId = (int) ($_GET['id'] ?? 0);
$statement = $pdo->prepare('SELECT * FROM students WHERE id = :id LIMIT 1');
$statement->execute(['id' => $studentId]);
$student = $statement->fetch();
if (!$student) {
    flash('error', 'The student record was not found.');
    redirect('admin/students.php');
}
$currentAcademicYear = (string) current_academic_context($pdo)['academic_year'];
$student['fee_sponsorship_status'] = student_fee_sponsorship_status($pdo, $studentId, $currentAcademicYear);
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
if (get_user_role_template_name($pdo, $adminId) === 'Teacher' && current_admin_class_level($pdo) !== $student['class_level']) {
    flash('error', 'You do not have permission to view records outside your assigned class.');
    redirect('admin/students.php');
}

$balanceQuery = $pdo->prepare('SELECT academic_year, term, required_amount, paid_amount, balance FROM fee_balances WHERE student_id = :student_id ORDER BY academic_year DESC, FIELD(term, "Term 1", "Term 2", "Term 3")');
$balanceQuery->execute(['student_id' => $studentId]);
$balances = array_values(array_filter(
    $balanceQuery->fetchAll(),
    static fn(array $balance): bool => student_fee_sponsorship_status($pdo, $studentId, (string) $balance['academic_year']) !== 'Sponsored Student'
));
$paymentQuery = $pdo->prepare('SELECT id, receipt_no, amount_paid, mpesa_code, term, year, payment_date, balance_after_payment, payment_status FROM fees WHERE student_id = :student_id ORDER BY payment_date DESC, id DESC');
$paymentQuery->execute(['student_id' => $studentId]);
$payments = $paymentQuery->fetchAll();
$historyQuery = $pdo->prepare('SELECT h.*, a.name AS changed_by_name FROM student_status_history h LEFT JOIN admin a ON a.id = h.changed_by WHERE h.student_id = :student_id ORDER BY h.id DESC');
$historyQuery->execute(['student_id' => $studentId]);
$statusHistory = $historyQuery->fetchAll();
$linkQuery = $pdo->prepare('SELECT a.name AS parent_name, l.relationship_type, l.is_active, l.linked_at FROM parent_student_links l JOIN admin a ON a.id = l.parent_user_id WHERE l.student_id = :student_id ORDER BY l.linked_at DESC, l.id DESC');
$linkQuery->execute(['student_id' => $studentId]);
$parentLinks = $linkQuery->fetchAll();
$canManageHistoricalFees = current_admin_has_permission($pdo, 'students.manage_historical_fees');
$canManageStatus = current_admin_has_permission($pdo, 'students.transfer');
$canPrintFees = current_admin_has_permission($pdo, 'fees.print');
$canViewPaymentAudit = current_admin_can_access_module($pdo, 'Payment Audit');
$hasOutstanding = (bool) array_filter($balances, static fn(array $balance): bool => (float) $balance['balance'] > 0.005);

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div><p class="eyebrow">Student Records</p><h1>Student History</h1><p class="mb-0 text-muted">Historic fees, payments, guardian links, and enrollment status changes.</p></div>
    <div class="action-row"><a class="btn btn-outline-primary" href="<?= url('admin/students.php?status=' . rawurlencode($student['enrollment_status'])) ?>">Back to Students</a><?php if ($canManageStatus): ?><a class="btn btn-primary" href="<?= url('admin/student_status.php?id=' . $studentId) ?>">Manage Status</a><?php endif; ?><?php if ($canManageHistoricalFees && $student['enrollment_status'] !== 'Active' && $hasOutstanding): ?><a class="btn btn-outline-warning" href="<?= url('admin/fees.php?student_id=' . $studentId . '&historical=1') ?>">Record Historical Balance Payment</a><?php endif; ?></div>
</div>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<section class="panel mb-4">
    <div class="panel-heading"><h2><?= h($student['full_name']) ?></h2><div class="d-flex gap-2"><span class="badge text-bg-<?= $student['enrollment_status'] === 'Active' ? 'success' : 'secondary' ?>"><?= h($student['enrollment_status']) ?></span><?php if ($student['fee_sponsorship_status'] === 'Sponsored Student'): ?><span class="badge text-bg-info">Sponsored</span><?php endif; ?></div></div>
    <div class="row g-3"><div class="col-sm-6 col-lg-3"><strong>Admission number</strong><br><?= h($student['registration_no']) ?></div><div class="col-sm-6 col-lg-3"><strong>Class</strong><br><?= h($student['class_level']) ?></div><div class="col-sm-6 col-lg-3"><strong>Gender</strong><br><?= h($student['gender']) ?></div><div class="col-sm-6 col-lg-3"><strong>Guardian</strong><br><?= h($student['parent_name']) ?> · <?= h($student['guardian_phone']) ?></div><div class="col-sm-6 col-lg-3"><strong>First registered</strong><br><?= h($student['created_at']) ?></div></div>
</section>
<section class="panel mb-4">
    <h2>Fee Balances</h2>
    <?php if ($balances): ?><div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Academic year</th><th>Term</th><th>Assessed</th><th>Paid</th><th>Balance</th></tr></thead><tbody><?php foreach ($balances as $balance): ?><tr><td><?= h($balance['academic_year']) ?></td><td><?= h($balance['term']) ?></td><td><?= money((float) $balance['required_amount']) ?></td><td><?= money((float) $balance['paid_amount']) ?></td><td><?= money((float) $balance['balance']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="text-muted mb-0">No fee balances recorded.</p><?php endif; ?>
</section>
<section class="panel mb-4">
    <h2>Payment History</h2>
    <?php if ($payments): ?><div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Date</th><th>Receipt</th><th>Period</th><th>M-PESA code</th><th>Paid</th><th>Balance after</th><th>Status</th><th>Actions</th></tr></thead><tbody><?php foreach ($payments as $payment): ?><tr><td><?= h($payment['payment_date']) ?></td><td><?= h($payment['receipt_no']) ?></td><td><?= h($payment['year'] . ' - ' . $payment['term']) ?></td><td><?= h($payment['mpesa_code']) ?></td><td><?= money((float) $payment['amount_paid']) ?></td><td><?= money((float) $payment['balance_after_payment']) ?></td><td><?= h($payment['payment_status'] ?? 'Recorded') ?></td><td><?php if ($canPrintFees): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/receipt.php?id=' . (int) $payment['id']) ?>">View receipt</a><?php endif; ?> <?php if ($canViewPaymentAudit): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/fee_payment_audit.php?id=' . (int) $payment['id']) ?>">Audit</a><?php endif; ?><?php if (!$canPrintFees && !$canViewPaymentAudit): ?>—<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="text-muted mb-0">No payments recorded.</p><?php endif; ?>
</section>
<div class="row g-4 mb-4">
    <div class="col-lg-6"><section class="panel h-100"><h2>Guardian Links</h2><?php if ($parentLinks): ?><div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Guardian</th><th>Relationship</th><th>Link status</th><th>Linked</th></tr></thead><tbody><?php foreach ($parentLinks as $link): ?><tr><td><?= h($link['parent_name']) ?></td><td><?= h($link['relationship_type']) ?></td><td><?= (int) $link['is_active'] === 1 ? 'Active' : 'Unlinked' ?></td><td><?= h($link['linked_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="text-muted mb-0">No guardian links found.</p><?php endif; ?></section></div>
    <div class="col-lg-6"><section class="panel h-100"><h2>Enrollment History</h2><?php if ($statusHistory): ?><div class="table-responsive"><table class="table table-striped align-middle"><thead><tr><th>Date</th><th>Status</th><th>Details</th></tr></thead><tbody><?php foreach ($statusHistory as $entry): ?><tr><td><?= h($entry['effective_date']) ?></td><td><?= h($entry['previous_status']) ?> → <?= h($entry['new_status']) ?></td><td><?= h($entry['destination_school'] ?: '') ?><?= $entry['reason'] ? ' · ' . h($entry['reason']) : '' ?><br><small><?= h($entry['changed_by_name'] ?: 'System') ?> · <?= h($entry['created_at']) ?></small></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><p class="text-muted mb-0">No status changes recorded.</p><?php endif; ?></section></div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
