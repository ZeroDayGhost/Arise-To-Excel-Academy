<?php
$pageTitle = 'Adjust Payment Record';
require_once __DIR__ . '/../includes/auth.php';

require_action_permission($pdo, 'fees.correct', 'You do not have permission to correct fee payments.');

$actorId = (int) ($_SESSION['admin_id'] ?? 0);
if (!fee_correction_actor_has_role($pdo, $actorId, 'Director')) {
    http_response_code(403);
        flash('error', 'Only the Director may adjust a payment record directly.');
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
    flash('error', 'Only a recorded payment can be corrected.');
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
        $result = fee_apply_direct_payment_correction($pdo, $paymentId, $actorId, $_POST, $reason);
        flash('success', 'Payment corrected. Receipt ' . $result['receipt_no'] . ' was preserved and the audit was recorded.');
        redirect('admin/fee_payment_audit.php?id=' . $paymentId);
    } catch (Throwable $exception) {
        $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'The correction could not be saved.';
    }
}

$formTitle = 'Direct Payment Adjustment';
$formAction = url('admin/fee_correct.php?id=' . $paymentId);
$submitLabel = 'Save Direct Correction';
$isRequest = false;
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title fee-correction-page-title">
    <div><p class="eyebrow">Director Action</p><h1>Adjust Payment Record</h1><p class="mb-0 text-muted">A reason is required. The original values and receipt number will remain in the audit history.</p></div>
    <a class="btn btn-outline-secondary" href="<?= url('admin/fee_payment_audit.php?id=' . $paymentId) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to payment details</a>
</div>
<?php if ($pending): ?>
    <div class="alert alert-warning">A Secretary-submitted payment review is pending. Decide it before making a direct adjustment.</div>
<?php else: ?>
    <?php require __DIR__ . '/../includes/fee_correction_form.php'; ?>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
