<?php
require_once __DIR__ . '/../includes/parent_auth.php';

$serviceOptions = [
    'feeding' => ['name' => 'Feeding', 'icon' => 'fa-utensils'],
    'transport' => ['name' => 'Transport', 'icon' => 'fa-bus'],
    'activity' => ['name' => 'Activities & Trips', 'icon' => 'fa-person-hiking'],
    'uniform' => ['name' => 'School Uniform', 'icon' => 'fa-shirt'],
];
$serviceKey = (string) ($_GET['service'] ?? '');
if (!isset($serviceOptions[$serviceKey])) {
    http_response_code(404);
    exit('The requested service statement is not available.');
}
$service = $serviceOptions[$serviceKey];
$context = current_academic_context($pdo);
$defaultYear = parent_portal_normalize_year($context['academic_year'] ?? '') ?: date('Y');
$year = parent_portal_normalize_year($_GET['year'] ?? '') ?: $defaultYear;
$terms = parent_portal_term_options();
$term = in_array($_GET['term'] ?? '', $terms, true)
    ? $_GET['term']
    : (in_array($context['term'] ?? '', $terms, true) ? $context['term'] : 'Term 1');
$years = parent_portal_available_years($pdo, $parentUserId);
if (!in_array($year, $years, true)) {
    $years[] = $year;
    rsort($years, SORT_STRING);
}

$children = parent_portal_children($pdo, $parentUserId, $year, $term);
$accounts = [];
$payments = [];
$assessedTotal = 0.0;
$paidTotal = 0.0;
$balanceTotal = 0.0;
foreach ($children as $child) {
    $childId = (int) $child['id'];
    foreach (parent_portal_service_balances($pdo, $parentUserId, $childId, $year, $term) as $balance) {
        if ($balance['name'] !== $service['name']) {
            continue;
        }
        $accounts[] = ['child' => $child, 'balance' => $balance];
        $assessedTotal += (float) $balance['assessed_amount'];
        $paidTotal += (float) $balance['paid_amount'];
        $balanceTotal += (float) $balance['balance'];
    }
    foreach (parent_portal_payments($pdo, $parentUserId, $childId, $year, $term) as $payment) {
        if (($payment['source_module'] ?? '') !== $serviceKey) {
            continue;
        }
        $payment['full_name'] = $child['full_name'];
        $payment['registration_no'] = $child['registration_no'];
        $payments[] = $payment;
    }
}
usort($payments, static function (array $a, array $b): int {
    $dateOrder = strcmp((string) ($b['payment_date'] ?? ''), (string) ($a['payment_date'] ?? ''));
    return $dateOrder !== 0 ? $dateOrder : ((int) ($b['source_id'] ?? 0) <=> (int) ($a['source_id'] ?? 0));
});

$pageTitle = $service['name'];
require __DIR__ . '/../includes/parent_header.php';
?>
<section class="parent-page-heading">
    <div>
        <span class="parent-page-eyebrow">CHILD SERVICE ACCOUNT</span>
        <h1><i class="fa-solid <?= h($service['icon']) ?>" aria-hidden="true"></i> <?= h($service['name']) ?></h1>
        <p>View this service's balances and payment records for your linked children.</p>
    </div>
    <form class="parent-period-form" method="get">
        <input type="hidden" name="service" value="<?= h($serviceKey) ?>">
        <div>
            <label class="form-label" for="service-year">Academic year</label>
            <select class="form-select" id="service-year" name="year">
                <?php foreach ($years as $optionYear): ?>
                    <option value="<?= h($optionYear) ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= h($optionYear) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="service-term">Term</label>
            <select class="form-select" id="service-term" name="term">
                <?php foreach ($terms as $optionTerm): ?>
                    <option value="<?= h($optionTerm) ?>" <?= $term === $optionTerm ? 'selected' : '' ?>><?= h($optionTerm) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary parent-period-submit" type="submit"><i class="fa-regular fa-eye" aria-hidden="true"></i> View period</button>
    </form>
</section>

<section class="parent-dashboard-metrics parent-page-metrics" aria-label="<?= h($service['name']) ?> summary">
    <article class="parent-overview-tile family"><span class="parent-overview-icon"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Linked accounts</strong><b><?= count($accounts) ?></b></span></article>
    <article class="parent-overview-tile assessed"><span class="parent-overview-icon"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Amount assessed</strong><b><?= h(money($assessedTotal)) ?></b></span></article>
    <article class="parent-overview-tile credited"><span class="parent-overview-icon"><i class="fa-solid fa-credit-card" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Payments credited</strong><b><?= h(money($paidTotal)) ?></b></span></article>
    <article class="parent-overview-tile outstanding"><span class="parent-overview-icon"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Outstanding balance</strong><b><?= h(money($balanceTotal)) ?></b></span></article>
</section>

<section class="parent-content-panel">
    <div class="parent-section-title">
        <h2><i class="fa-solid <?= h($service['icon']) ?>" aria-hidden="true"></i> <?= h($service['name']) ?> accounts</h2>
        <span class="parent-section-count"><?= h($year . ' - ' . $term) ?></span>
    </div>
    <?php if ($accounts): ?>
        <div class="parent-table-wrap">
            <table class="table parent-table align-middle">
                <thead><tr><th>Child</th><th>Admission no.</th><th>Class</th><th>Assessed</th><th>Paid</th><th>Balance</th></tr></thead>
                <tbody>
                <?php foreach ($accounts as $account): ?>
                    <tr data-parent-searchable>
                        <td><strong><?= h($account['child']['full_name']) ?></strong></td>
                        <td><?= h($account['child']['registration_no']) ?></td>
                        <td><?= h($account['child']['class_level']) ?></td>
                        <td><?= h(money($account['balance']['assessed_amount'])) ?></td>
                        <td><?= h(money($account['balance']['paid_amount'])) ?></td>
                        <td><strong><?= h(money($account['balance']['balance'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="parent-payment-empty"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>No <?= h(strtolower($service['name'])) ?> account is linked to your children for this period.</span></div>
    <?php endif; ?>
</section>

<section class="parent-content-panel parent-payment-panel">
    <div class="parent-section-title">
        <h2><i class="fa-solid fa-credit-card" aria-hidden="true"></i> <?= h($service['name']) ?> payments</h2>
        <span class="parent-section-count"><?= count($payments) ?> records</span>
    </div>
    <?php if ($payments): ?>
        <div class="parent-table-wrap">
            <table class="table parent-table align-middle">
                <thead><tr><th>Date</th><th>Child</th><th>Description</th><th>Receipt / reference</th><th>Status</th><th class="text-end">Amount</th></tr></thead>
                <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr data-parent-searchable>
                        <td><?= h($payment['payment_date']) ?></td>
                        <td><span class="parent-payment-child"><?= h($payment['full_name']) ?><small><?= h($payment['registration_no']) ?></small></span></td>
                        <td><?= h($payment['description'] ?: $service['name'] . ' payment') ?></td>
                        <td><?= h($payment['receipt_no'] ?: ($payment['payment_reference'] ?: '—')) ?></td>
                        <td><?= h($payment['payment_status'] ?? 'Recorded') ?></td>
                        <td class="text-end"><strong><?= h(money((float) $payment['amount_paid'])) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="parent-payment-empty"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>No <?= h(strtolower($service['name'])) ?> payments are recorded for this period.</span></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/parent_footer.php'; ?>
