<?php
$pageTitle = 'Review Fee Payment';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if (!fee_correction_actor_has_role($pdo, (int) ($_SESSION['admin_id'] ?? 0), 'Director')) {
    http_response_code(403);
    flash('error', 'Only the Director may reverse or cancel fee payments.');
    redirect('admin/fees.php');
}

$paymentId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: (int) ($_POST['payment_id'] ?? 0);
$statement = $pdo->prepare(
    'SELECT f.*, s.full_name, s.registration_no, s.class_level
     FROM fees f JOIN students s ON s.id = f.student_id WHERE f.id = :id'
);
$statement->execute(['id' => $paymentId]);
$payment = $statement->fetch();
if (!$payment) {
    flash('error', 'The payment could not be found.');
    redirect('admin/fees.php');
}
$hasPendingCorrection = fee_correction_has_pending($pdo, $paymentId);

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$role = get_user_role_template_name($pdo, $adminId);
$adminClass = current_admin_class_level($pdo);
if ($role === 'Teacher' && $adminClass && $payment['class_level'] !== $adminClass) {
    flash('error', 'You do not have permission to manage payments for this class.');
    redirect('admin/fees.php');
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $reason = trim((string) ($_POST['reason'] ?? ''));
    $eventType = (string) ($_POST['event_type'] ?? 'REVERSED');
    if (!in_array($eventType, ['REVERSED', 'CANCELLED'], true)) {
        $errors[] = 'Choose a valid payment action.';
    }
    if ($reason === '' || mb_strlen($reason) > 500) {
        $errors[] = 'Enter a reason of 1 to 500 characters.';
    }
    if (!$errors) {
        try {
            $pdo->beginTransaction();
            $result = fee_reverse_payment($pdo, $paymentId, $adminId, $reason, $eventType);
            $pdo->commit();
            $label = $eventType === 'CANCELLED' ? 'Payment cancelled' : 'Payment reversed';
            flash('success', $label . '. Original receipt ' . $result['receipt_no'] . ' remains in the audit history.');
            redirect('admin/fees.php');
        } catch (PDOException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Fee payment reversal failed with code ' . (string) $exception->getCode());
            $errors[] = 'Unable to update this payment. No changes were saved.';
        } catch (RuntimeException $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Fee payment reversal failed with code ' . (string) $exception->getCode());
            $errors[] = 'Unable to update this payment. No changes were saved.';
        }
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div><p class="eyebrow">Fee Payments</p><h1>Review Payment</h1></div>
    <a class="btn btn-outline-primary" href="<?= url('admin/fees.php') ?>">Back to fees</a>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<section class="panel" style="max-width: 760px">
    <h2><?= h($payment['receipt_no']) ?></h2>
    <dl class="row mb-4">
        <dt class="col-sm-4">Student</dt><dd class="col-sm-8"><?= h($payment['full_name'] . ' (' . $payment['registration_no'] . ')') ?></dd>
        <dt class="col-sm-4">Academic period</dt><dd class="col-sm-8"><?= h($payment['year'] . ' - ' . $payment['term']) ?></dd>
        <dt class="col-sm-4">Amount</dt><dd class="col-sm-8"><?= money((float) $payment['amount_paid']) ?></dd>
        <dt class="col-sm-4">Payment reference</dt><dd class="col-sm-8"><?= h($payment['mpesa_code']) ?></dd>
        <dt class="col-sm-4">Status</dt><dd class="col-sm-8"><?= h($payment['payment_status']) ?></dd>
    </dl>
    <?php if ($payment['payment_status'] !== 'Recorded'): ?>
        <div class="alert alert-info">This payment has already been reversed. Its receipt number remains permanently assigned.</div>
    <?php elseif ($hasPendingCorrection): ?>
        <div class="alert alert-warning">A Secretary-submitted payment review is pending. Decide it before reversing or cancelling this payment.</div>
        <?php if (current_admin_can_access_module($pdo, 'Payment Audit')): ?><a class="btn btn-primary" href="<?= url('admin/fee_corrections.php') ?>">Open Payment Review &amp; Audit</a><?php endif; ?>
    <?php else: ?>
        <div class="alert alert-warning">This Director-only action restores the payment amount to the student's outstanding balance. It does not delete the payment or receipt. Corrections must use the correction workflow.</div>
        <form method="post" class="row g-3">
            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="payment_id" value="<?= (int) $paymentId ?>">
            <div class="col-md-5">
                <label class="form-label" for="event_type">Action</label>
                <select class="form-select" id="event_type" name="event_type" required>
                    <option value="REVERSED">Reverse payment</option>
                    <option value="CANCELLED">Cancel transaction</option>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label" for="reason">Reason</label>
                <textarea class="form-control" id="reason" name="reason" maxlength="500" rows="3" required><?= h($_POST['reason'] ?? '') ?></textarea>
            </div>
            <div class="col-12"><button class="btn btn-danger" type="submit">Confirm and update balance</button></div>
        </form>
    <?php endif; ?>
</section>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
