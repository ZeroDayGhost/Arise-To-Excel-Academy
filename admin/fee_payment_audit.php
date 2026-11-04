<?php
$pageTitle = 'Payment Audit';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if (!current_admin_can_access_module($pdo, 'Payment Audit')) {
    http_response_code(403);
    flash('error', 'You do not have permission to view payment audit records.');
    redirect('admin/dashboard.php');
}

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$paymentQuery = $pdo->prepare(
    'SELECT f.id, f.receipt_no, f.student_id, f.year, f.term, f.amount_paid, f.payment_status, s.full_name, s.class_level
     FROM fees f JOIN students s ON s.id = f.student_id WHERE f.id = :id'
);
$paymentQuery->execute(['id' => $paymentId]);
$payment = $paymentQuery->fetch();
if (!$payment) {
    flash('error', 'The payment could not be found.');
    redirect('admin/fees.php');
}
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
if (get_user_role_template_name($pdo, $adminId) === 'Teacher' && current_admin_class_level($pdo) !== $payment['class_level']) {
    flash('error', 'You do not have permission to view payments for this class.');
    redirect('admin/fees.php');
}
$isSecretary = fee_correction_actor_has_role($pdo, $adminId, 'Secretary');
$isDirector = fee_correction_actor_has_role($pdo, $adminId, 'Director');
$hasPendingCorrection = fee_correction_has_pending($pdo, $paymentId);
$auditQuery = $pdo->prepare(
    'SELECT a.*, u.name AS actor_name FROM fee_payment_audit a LEFT JOIN admin u ON u.id = a.actor_id
     WHERE a.payment_id = :payment_id ORDER BY a.id DESC'
);
$auditQuery->execute(['payment_id' => $paymentId]);
$events = $auditQuery->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div><p class="eyebrow">Fee Payments</p><h1>Payment Audit</h1><p class="mb-0 text-muted"><?= h($payment['full_name'] . ' · ' . $payment['receipt_no']) ?></p></div>
    <div class="action-row">
        <?php if ($isSecretary && ($payment['payment_status'] ?? 'Recorded') === 'Recorded' && !$hasPendingCorrection): ?><a class="btn btn-outline-warning" href="<?= url('admin/fee_correction_request.php?id=' . $paymentId) ?>">Request Payment Review</a><?php endif; ?>
        <?php if ($isDirector && ($payment['payment_status'] ?? 'Recorded') === 'Recorded' && !$hasPendingCorrection): ?><a class="btn btn-warning" href="<?= url('admin/fee_correct.php?id=' . $paymentId) ?>">Adjust Payment Record</a><?php endif; ?>
        <?php if ($hasPendingCorrection && ($isSecretary || $isDirector)): ?><a class="btn btn-outline-warning" href="<?= url('admin/fee_corrections.php') ?>">View Pending Review</a><?php endif; ?>
        <?php if (current_admin_has_permission($pdo, 'fees.print')): ?><a class="btn btn-outline-primary" href="<?= url('admin/receipt.php?id=' . $paymentId) ?>">View original receipt</a><?php endif; ?>
        <a class="btn btn-outline-secondary" href="<?= url('admin/fees.php') ?>">Back to fees</a>
    </div>
</div>
<section class="panel">
    <div class="table-responsive"><table class="table table-striped align-middle">
        <thead><tr><th>Date</th><th>Action</th><th>Performed by</th><th>Reason</th><th>Recorded details</th></tr></thead>
        <tbody>
        <?php foreach ($events as $event): ?>
            <tr>
                <td><?= h($event['created_at']) ?></td>
                <td><?= h(str_replace('_', ' ', $event['event_type'])) ?></td>
                <td><?= h($event['actor_name'] ?: 'System / legacy record') ?></td>
                <td><?= h($event['reason'] ?: '—') ?></td>
                <td><details><summary>View</summary><pre class="small mb-0 mt-2"><?= h($event['details'] ?: '{}') ?></pre></details></td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$events): ?><tr><td colspan="5">No audit events found.</td></tr><?php endif; ?>
        </tbody>
    </table></div>
</section>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
