<?php
require_once __DIR__ . '/../includes/parent_auth.php';

$studentId = filter_input(INPUT_GET, 'student_id', FILTER_VALIDATE_INT) ?: 0;
$context = current_academic_context($pdo);
$defaultYear = parent_portal_normalize_year($context['academic_year'] ?? '') ?: date('Y');
$year = parent_portal_normalize_year($_GET['year'] ?? '') ?: $defaultYear;
$student = parent_portal_child($pdo, $parentUserId, $studentId, $year);
if (!$student) {
    http_response_code(404);
    exit('The requested student record is not available.');
}

$terms = parent_portal_term_options();
$term = in_array($_GET['term'] ?? '', $terms, true) ? $_GET['term'] : (in_array($context['term'] ?? '', $terms, true) ? $context['term'] : 'Term 1');
$period = parent_portal_child_period($pdo, $parentUserId, $studentId, $year, $term);
if (!$period) {
    http_response_code(404);
    exit('The requested student record is not available.');
}
$payments = parent_portal_payments($pdo, $parentUserId, $studentId, $year, $term);
$payments = array_values(array_filter($payments, static fn(array $payment): bool => ($payment['source_module'] ?? '') === 'fees'));
$years = parent_portal_available_years($pdo, $parentUserId);
if (!in_array($year, $years, true)) {
    $years[] = $year;
    rsort($years, SORT_STRING);
}

$pageTitle = 'Fee Statement';
require __DIR__ . '/../includes/parent_header.php';
?>
<div class="parent-page-heading">
    <div>
        <span class="parent-page-eyebrow">STUDENT FEE SUMMARY</span>
        <h1>Fee statement</h1>
        <p class="parent-statement-student"><span class="parent-child-avatar"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i></span><span><strong><?= h($student['full_name']) ?></strong><small><?= h($student['registration_no']) ?> · <?= h($student['class_level']) ?> · <?= h($student['enrollment_status']) ?></small></span></p>
    </div>
    <form class="parent-period-form" method="get">
        <input type="hidden" name="student_id" value="<?= (int) $studentId ?>">
        <div>
            <label class="form-label" for="statement-year">Academic year</label>
            <select class="form-select" id="statement-year" name="year">
                <?php foreach ($years as $optionYear): ?>
                    <option value="<?= h($optionYear) ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= h($optionYear) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="statement-term">Term</label>
            <select class="form-select" id="statement-term" name="term">
                <?php foreach ($terms as $optionTerm): ?>
                    <option value="<?= h($optionTerm) ?>" <?= $term === $optionTerm ? 'selected' : '' ?>><?= h($optionTerm) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary parent-period-submit" type="submit"><i class="fa-regular fa-eye" aria-hidden="true"></i> View period</button>
    </form>
</div>

<?php if (empty($period['fee_balance_id'])): ?>
    <div class="alert alert-info">No fee assessment has been recorded for this child in the selected period.</div>
<?php endif; ?>

<section class="parent-dashboard-metrics parent-statement-metrics" aria-label="Fee summary for <?= h($year . ' ' . $term) ?>">
    <article class="parent-overview-tile assessed"><span class="parent-overview-icon"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Fees assessed</strong><b><?= h(money((float) ($period['assessed_amount'] ?? 0))) ?></b></span></article>
    <article class="parent-overview-tile credited"><span class="parent-overview-icon"><i class="fa-solid fa-credit-card" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Payments credited</strong><b><?= h(money((float) ($period['paid_amount'] ?? 0))) ?></b></span></article>
    <article class="parent-overview-tile outstanding"><span class="parent-overview-icon"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Outstanding balance</strong><b><?= h(money((float) ($period['balance'] ?? 0))) ?></b></span></article>
</section>

<section class="parent-content-panel">
<div class="parent-section-title"><h2><i class="fa-solid fa-credit-card" aria-hidden="true"></i>Payment history</h2><span class="parent-section-count"><?= h($year . ' - ' . $term) ?></span></div>
<?php if ($payments): ?>
    <div class="parent-table-wrap">
        <table class="table parent-table align-middle">
            <thead><tr><th>Date</th><th>Service</th><th>Details</th><th>Receipt / reference</th><th>Status</th><th class="text-end">Amount</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <tr data-parent-searchable>
                    <td><?= h($payment['payment_date']) ?></td>
                    <td><?= h($payment['category']) ?></td>
                    <td><?= h($payment['description'] ?: 'School fee payment') ?></td>
                    <td><?= h($payment['receipt_no'] ?: ($payment['payment_reference'] ?: '—')) ?></td>
                    <td><?= h($payment['payment_status'] ?? 'Recorded') ?></td>
                    <td class="text-end"><?= h(money((float) $payment['amount_paid'])) ?></td>
                    <td class="text-end"><?php if (($payment['source_module'] ?? '') === 'fees'): ?><a class="btn btn-sm btn-outline-secondary" href="<?= url('parent/receipt.php?id=' . (int) $payment['source_id']) ?>">View receipt</a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php else: ?>
    <div class="parent-payment-empty"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>No payments are recorded for this selected period.</span></div>
<?php endif; ?>
</section>
<a class="btn btn-outline-primary parent-back-link" href="<?= url('parent/dashboard.php?year=' . rawurlencode($year) . '&term=' . rawurlencode($term)) ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to dashboard</a>
<?php require __DIR__ . '/../includes/parent_footer.php'; ?>
