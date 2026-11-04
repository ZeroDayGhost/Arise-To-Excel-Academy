<?php
$pageTitle = 'Request Payment Review';
require_once __DIR__ . '/../includes/auth.php';

$actorId = (int) ($_SESSION['admin_id'] ?? 0);
if (!fee_correction_actor_has_role($pdo, $actorId, 'Secretary')) {
    http_response_code(403);
    flash('error', 'Only a Secretary may request a payment review.');
    redirect('admin/fees.php');
}

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: (int) ($_POST['payment_id'] ?? 0);
$paymentQuery = $pdo->prepare(
    'SELECT f.*, s.full_name, s.registration_no, s.class_level
     FROM fees f JOIN students s ON s.id = f.student_id WHERE f.id = :id LIMIT 1'
);
$paymentQuery->execute(['id' => $paymentId]);
$payment = $paymentQuery->fetch(PDO::FETCH_ASSOC);
if (!$payment || ($payment['payment_status'] ?? 'Recorded') !== 'Recorded') {
    flash('error', 'Only a recorded payment can be submitted for correction.');
    redirect('admin/fees.php');
}

$pending = fee_correction_has_pending($pdo, $paymentId);
$students = $pdo->query(
    'SELECT DISTINCT s.id, s.registration_no, s.full_name, s.class_level
     FROM students s JOIN fee_balances b ON b.student_id = s.id ORDER BY s.full_name'
)->fetchAll(PDO::FETCH_ASSOC);
$paymentMethods = array_values(array_unique(array_filter(array_merge(
    ['M-PESA', 'Cash', 'Bank', 'Cheque', 'Other'],
    array_column($pdo->query('SELECT DISTINCT payment_method FROM fees')->fetchAll(PDO::FETCH_ASSOC), 'payment_method'),
    [(string) ($payment['payment_method'] ?? 'M-PESA')]
))));
$values = array_merge(fee_correction_payment_snapshot($payment), $_POST);
$errors = [];
$reason = (string) ($_POST['reason'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$pending) {
    try {
        fee_request_payment_correction($pdo, $paymentId, $actorId, $_POST, $reason);
        flash('success', 'Your payment review request was sent to the Director. The payment and balance are unchanged until approval.');
        redirect('admin/fee_corrections.php');
    } catch (Throwable $exception) {
        $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'The payment review request could not be saved.';
    }
}

$formTitle = 'Request Payment Review';
$formAction = url('admin/fee_correction_request.php?id=' . $paymentId);
$submitLabel = 'Send to Director for Approval';
$isRequest = true;
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title fee-correction-page-title">
    <div><p class="eyebrow">Fee Payments</p><h1>Request Payment Review</h1><p class="mb-0 text-muted">The payment and balance will not change until the Director approves.</p></div>
    <a class="btn btn-outline-secondary" href="<?= url('admin/fee_payment_audit.php?id=' . $paymentId) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to payment details</a>
</div>
<?php if ($pending): ?>
    <div class="alert alert-warning">A payment review is already pending for this record. View its status in <a href="<?= url('admin/fee_corrections.php') ?>">Payment Review &amp; Audit</a>.</div>
<?php else: ?>
    <?php require __DIR__ . '/../includes/fee_correction_form.php'; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
