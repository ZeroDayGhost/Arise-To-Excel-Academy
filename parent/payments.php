<?php
require_once __DIR__ . '/../includes/parent_auth.php';

$context = current_academic_context($pdo);
$defaultYear = parent_portal_normalize_year($context['academic_year'] ?? '') ?: date('Y');
$year = parent_portal_normalize_year($_GET['year'] ?? '') ?: $defaultYear;
$terms = parent_portal_term_options();
$term = in_array($_GET['term'] ?? '', $terms, true) ? $_GET['term'] : (in_array($context['term'] ?? '', $terms, true) ? $context['term'] : 'Term 1');
$years = parent_portal_available_years($pdo, $parentUserId);
if (!in_array($year, $years, true)) {
    $years[] = $year;
    rsort($years, SORT_STRING);
}
$payments = parent_portal_recent_payments($pdo, $parentUserId, $year, $term, 25);
$totalPaidCents = 0;
foreach ($payments as $payment) {
    if (strcasecmp((string) ($payment['payment_status'] ?? 'Recorded'), 'Recorded') === 0) {
        $totalPaidCents += (int) round((float) $payment['amount_paid'] * 100);
    }
}

$pageTitle = 'Payments';
require __DIR__ . '/../includes/parent_header.php';
?>
<section class="parent-page-heading">
    <div>
        <span class="parent-page-eyebrow">PAYMENT HISTORY</span>
        <h1>Payments</h1>
        <p>Review payments recorded for your linked children.</p>
    </div>
    <form class="parent-period-form" method="get">
        <div>
            <label class="form-label" for="payments-year">Academic year</label>
            <select class="form-select" id="payments-year" name="year">
                <?php foreach ($years as $optionYear): ?>
                    <option value="<?= h($optionYear) ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= h($optionYear) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="payments-term">Term</label>
            <select class="form-select" id="payments-term" name="term">
                <?php foreach ($terms as $optionTerm): ?>
                    <option value="<?= h($optionTerm) ?>" <?= $term === $optionTerm ? 'selected' : '' ?>><?= h($optionTerm) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary parent-period-submit" type="submit"><i class="fa-regular fa-eye" aria-hidden="true"></i> View period</button>
    </form>
</section>

<section class="parent-dashboard-metrics parent-page-metrics" aria-label="Payment summary">
    <article class="parent-overview-tile family"><span class="parent-overview-icon"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Payments found</strong><b><?= count($payments) ?></b></span></article>
    <article class="parent-overview-tile credited"><span class="parent-overview-icon"><i class="fa-solid fa-wallet" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Total credited</strong><b><?= h(money($totalPaidCents / 100)) ?></b></span></article>
    <a class="parent-overview-tile assessed" href="<?= url('parent/fee_statement.php?year=' . rawurlencode($year) . '&term=' . rawurlencode($term)) ?>"><span class="parent-overview-icon"><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Fee statements</strong><small><?= h($year . ' - ' . $term) ?></small></span><i class="fa-solid fa-arrow-right parent-overview-arrow" aria-hidden="true"></i></a>
</section>

<section class="parent-content-panel">
    <div class="parent-section-title">
        <h2><i class="fa-solid fa-credit-card" aria-hidden="true"></i>Payment history</h2>
        <span class="parent-section-count"><?= h($year . ' - ' . $term) ?></span>
    </div>
    <?php if ($payments): ?>
        <div class="parent-table-wrap">
            <table class="table parent-table align-middle">
        <thead><tr><th>Date</th><th>Child</th><th>Service</th><th>Receipt / reference</th><th>Status</th><th class="text-end">Amount</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr data-parent-searchable>
                        <td><?= h($payment['payment_date']) ?></td>
                        <td><?= h($payment['full_name']) ?><span class="d-block text-muted small"><?= h($payment['registration_no']) ?></span></td>
                        <td><span class="parent-payment-child"><?= h($payment['category']) ?><?php if (!empty($payment['description'])): ?><small><?= h($payment['description']) ?></small><?php endif; ?></span></td>
                        <td><?= h($payment['receipt_no'] ?: ($payment['payment_reference'] ?: '—')) ?></td>
                        <td><?= h($payment['payment_status'] ?? 'Recorded') ?></td>
                        <td class="text-end"><strong><?= h(money((float) $payment['amount_paid'])) ?></strong></td>
                        <td class="text-end"><?php if (($payment['source_module'] ?? '') === 'fees'): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('parent/receipt.php?id=' . (int) $payment['source_id']) ?>">View receipt</a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="parent-payment-empty"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>No payments are recorded for this selected period.</span></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/parent_footer.php'; ?>
