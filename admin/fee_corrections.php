<?php
$pageTitle = 'Payment Review & Audit';
require_once __DIR__ . '/../includes/auth.php';

$actorId = (int) ($_SESSION['admin_id'] ?? 0);
$isDirector = fee_correction_actor_has_role($pdo, $actorId, 'Director');
$isSecretary = fee_correction_actor_has_role($pdo, $actorId, 'Secretary');
if (!current_admin_can_access_module($pdo, 'Payment Audit')) {
    http_response_code(403);
    flash('error', 'You do not have permission to access payment audit records.');
    redirect('admin/dashboard.php');
}
$pendingCorrectionCount = $isDirector ? fee_pending_correction_count($pdo) : 0;
$pendingCorrectionRequests = $isDirector && $pendingCorrectionCount > 0
    ? fee_pending_correction_requests($pdo, 10)
    : [];

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isDirector) {
        http_response_code(403);
        exit('Only the Director may decide payment review requests.');
    }
    try {
        $result = fee_decide_payment_correction(
            $pdo,
            (int) ($_POST['request_id'] ?? 0),
            $actorId,
            (string) ($_POST['decision'] ?? ''),
            (string) ($_POST['decision_reason'] ?? '')
        );
        flash('success', $result['status'] === 'Approved'
            ? 'Payment review approved and applied. Receipt ' . $result['receipt_no'] . ' remains unchanged.'
            : 'Payment review declined. The original payment and balance are unchanged.');
        redirect('admin/fee_corrections.php');
    } catch (Throwable $exception) {
        $errors[] = $exception instanceof RuntimeException ? $exception->getMessage() : 'The correction decision could not be saved.';
    }
}

if ($isDirector || !$isSecretary) {
    $requests = $pdo->query(
        "SELECT r.*, s.full_name AS student_name, s.registration_no, s.class_level,
                f.payment_status, f.amount_paid AS current_amount, f.payment_method AS current_method,
                f.mpesa_code AS current_reference, f.term AS current_term, f.year AS current_year,
                f.payment_date AS current_payment_date
         FROM fee_payment_correction_requests r
         JOIN fees f ON f.id = r.payment_id
         JOIN students s ON s.id = r.student_id
         ORDER BY CASE WHEN r.status = 'Pending' THEN 0 ELSE 1 END, r.created_at DESC, r.id DESC
         LIMIT 100"
    )->fetchAll(PDO::FETCH_ASSOC);
} else {
    $statement = $pdo->prepare(
        'SELECT r.*, s.full_name AS student_name, s.registration_no, s.class_level,
                f.payment_status, f.amount_paid AS current_amount, f.payment_method AS current_method,
                f.mpesa_code AS current_reference, f.term AS current_term, f.year AS current_year,
                f.payment_date AS current_payment_date
         FROM fee_payment_correction_requests r
         JOIN fees f ON f.id = r.payment_id
         JOIN students s ON s.id = r.student_id
         WHERE r.requested_by = :actor_id
         ORDER BY r.created_at DESC, r.id DESC LIMIT 100'
    );
    $statement->execute(['actor_id' => $actorId]);
    $requests = $statement->fetchAll(PDO::FETCH_ASSOC);
}

$studentMap = [];
foreach ($pdo->query('SELECT id, full_name, registration_no, class_level FROM students')->fetchAll(PDO::FETCH_ASSOC) as $student) {
    $studentMap[(int) $student['id']] = $student;
}
$valueLabels = [
    'student_id' => 'Student', 'amount_paid' => 'Amount', 'payment_method' => 'Payment method',
    'mpesa_code' => 'Payment reference', 'mpesa_reference_text' => 'Reference details',
    'term' => 'Term', 'year' => 'Academic year', 'payment_date' => 'Payment date',
];
$renderCorrectionValue = static function (string $key, mixed $value) use ($studentMap): string {
    if ($key === 'student_id') {
        $student = $studentMap[(int) $value] ?? null;
        return $student
            ? $student['registration_no'] . ' · ' . $student['full_name'] . ' · ' . $student['class_level']
            : 'Student #' . (int) $value;
    }
    if ($key === 'amount_paid') {
        return money((float) $value);
    }
    return trim((string) $value) !== '' ? (string) $value : '—';
};

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Fee Payments</p>
        <h1>Payment Review &amp; Audit</h1>
        <p class="mb-0 text-muted"><?= $isDirector ? 'Review payment change requests, record decisions, and keep a clear audit trail.' : ($isSecretary ? 'Track submitted payment review requests and their decisions.' : 'View payment review requests and audit history. Only the Director can approve or reject changes.') ?></p>
    </div>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>
<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>

<?php if ($isDirector): ?>
    <section class="panel mb-4" id="pending-payment-reviews">
        <div class="panel-heading">
            <div>
                <h2><i class="fa-solid fa-file-circle-exclamation text-warning me-2"></i>Pending Payment Reviews <span class="badge text-bg-danger ms-2"><?= $pendingCorrectionCount ?></span></h2>
                <p class="panel-subtitle">Secretary-submitted changes stay on hold until reviewed.</p>
            </div>
        </div>
        <?php if ($pendingCorrectionRequests): ?>
            <div class="table-responsive">
                <table class="table table-striped align-middle mb-0">
                    <thead><tr><th>Student</th><th>Receipt</th><th>Requested by</th><th>Reason</th><th>Date</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($pendingCorrectionRequests as $request): ?>
                        <tr>
                            <td><?= h($request['student_name'] . ' · ' . $request['registration_no']) ?></td>
                            <td><?= h($request['receipt_no']) ?></td>
                            <td><?= h($request['requested_by_name']) ?></td>
                            <td><?= h($request['reason']) ?></td>
                            <td><?= h($request['created_at']) ?></td>
                            <td><a class="btn btn-sm btn-primary" href="#correction-<?= (int) $request['id'] ?>">Review</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-success mb-0">There are no payment reviews awaiting a decision.</div>
        <?php endif; ?>
    </section>
<?php endif; ?>

<?php if (!$requests && !$isDirector): ?>
    <section class="panel"><div class="empty-state"><div><strong>No payment review requests</strong><span><?= $isSecretary ? 'Requests you submit will appear here.' : 'Payment review requests will appear here.' ?></span></div></div></section>
<?php endif; ?>

<?php foreach ($requests as $request): ?>
    <?php
        $original = fee_correction_json_array($request['original_values']);
        $proposed = fee_correction_json_array($request['proposed_values']);
        $pending = $request['status'] === 'Pending';
    ?>
    <section class="panel mb-4" id="correction-<?= (int) $request['id'] ?>">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
            <div>
                <h2 class="mb-1"><?= h($request['receipt_no']) ?></h2>
                <p class="mb-0 text-muted"><?= h($request['student_name'] . ' (' . $request['registration_no'] . ') · ' . $request['class_level']) ?></p>
            </div>
            <span class="badge <?= $pending ? 'text-bg-warning' : ($request['status'] === 'Approved' ? 'text-bg-success' : 'text-bg-secondary') ?>"><?= h($request['status']) ?></span>
        </div>
        <div class="row g-3 mb-3">
            <div class="col-md-6"><strong>Requested by</strong><div><?= h($request['requested_by_name']) ?> · <?= h($request['created_at']) ?></div></div>
            <div class="col-md-6"><strong>Secretary's reason</strong><div><?= h($request['reason']) ?></div></div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle">
                <thead><tr><th>Value</th><th>Original</th><th>Proposed correction</th></tr></thead>
                <tbody>
                <?php foreach ($valueLabels as $key => $label): ?>
                    <tr>
                        <th><?= h($label) ?></th>
                        <td><?= h($renderCorrectionValue($key, $original[$key] ?? '')) ?></td>
                        <td><?= h($renderCorrectionValue($key, $proposed[$key] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$pending): ?>
            <div class="small text-muted">Decision by <?= h($request['decided_by_name'] ?: 'Director') ?><?= $request['decided_at'] ? ' · ' . h($request['decided_at']) : '' ?><?= $request['decision_reason'] ? ' · ' . h($request['decision_reason']) : '' ?></div>
        <?php elseif ($isDirector): ?>
            <div class="alert alert-info mb-3">The original payment remains recorded and the student balance has not changed while this request is pending.</div>
            <div class="row g-3">
                <div class="col-lg-6">
                    <form method="post" class="border rounded p-3 h-100">
                        <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <input type="hidden" name="decision" value="APPROVE">
                        <label class="form-label" for="approve-note-<?= (int) $request['id'] ?>">Director note (optional)</label>
                        <textarea class="form-control mb-3" id="approve-note-<?= (int) $request['id'] ?>" name="decision_reason" maxlength="500" rows="2"></textarea>
                        <button class="btn btn-success" type="submit">Approve and Apply Correction</button>
                    </form>
                </div>
                <div class="col-lg-6">
                    <form method="post" class="border rounded p-3 h-100">
                        <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                        <input type="hidden" name="request_id" value="<?= (int) $request['id'] ?>">
                        <input type="hidden" name="decision" value="REJECT">
                        <label class="form-label" for="reject-reason-<?= (int) $request['id'] ?>">Rejection reason</label>
                        <textarea class="form-control mb-3" id="reject-reason-<?= (int) $request['id'] ?>" name="decision_reason" maxlength="500" rows="2" required></textarea>
                        <button class="btn btn-outline-danger" type="submit">Reject Request</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
        <div class="mt-3"><a class="btn btn-sm btn-outline-secondary" href="<?= url('admin/fee_payment_audit.php?id=' . (int) $request['payment_id']) ?>">Payment audit and receipt</a></div>
    </section>
<?php endforeach; ?>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
