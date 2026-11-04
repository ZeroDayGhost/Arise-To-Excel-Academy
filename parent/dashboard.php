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
$children = parent_portal_children($pdo, $parentUserId, $year, $term);
$childServices = [];
$combinedAssessedCents = 0;
$combinedPaidCents = 0;
$combinedBalanceCents = 0;
foreach ($children as $child) {
    $childServices[(int) $child['id']] = parent_portal_service_balances($pdo, $parentUserId, (int) $child['id'], $year, $term);
    $combinedAssessedCents += (int) round((float) $child['assessed_amount'] * 100);
    $combinedPaidCents += (int) round((float) $child['paid_amount'] * 100);
    $combinedBalanceCents += (int) round((float) $child['balance'] * 100);
}
$recentPayments = parent_portal_recent_payments($pdo, $parentUserId, $year, $term, 10, true);

$pageTitle = 'Parent Dashboard';
require __DIR__ . '/../includes/parent_header.php';
?>
<section class="parent-dashboard-hero">
    <div class="parent-page-heading parent-dashboard-heading">
        <div class="parent-welcome-copy">
            <span class="dashboard-greeting"><?= h(ucwords(time_of_day_greeting())) ?></span>
            <h1><?= h($parentAccount['name']) ?></h1>
            <p>Balances and payment history for your children across school services.</p>
            <span class="parent-welcome-motto">Small steps. Big dreams.</span>
        </div>
        <div class="parent-welcome-art"><img src="<?= asset('images/campus-hero.png') ?>" alt="Illustration of Arise To Excel Academy"></div>
    </div>
    <form class="parent-period-form parent-period-card" method="get">
        <span class="parent-period-icon"><i class="fa-regular fa-calendar-days" aria-hidden="true"></i></span>
        <div>
            <label class="form-label" for="period-year">Academic year</label>
            <select class="form-select" id="period-year" name="year">
                <?php foreach ($years as $optionYear): ?>
                    <option value="<?= h($optionYear) ?>" <?= $year === $optionYear ? 'selected' : '' ?>><?= h($optionYear) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="form-label" for="period-term">Term</label>
            <select class="form-select" id="period-term" name="term">
                <?php foreach ($terms as $optionTerm): ?>
                    <option value="<?= h($optionTerm) ?>" <?= $term === $optionTerm ? 'selected' : '' ?>><?= h($optionTerm) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button class="btn btn-primary parent-period-submit" type="submit"><i class="fa-regular fa-eye" aria-hidden="true"></i> View period</button>
    </form>
</section>

<section class="parent-dashboard-metrics" aria-label="Family fee overview">
    <a class="parent-overview-tile family" href="<?= url('parent/fee_statement.php?year=' . rawurlencode($year) . '&term=' . rawurlencode($term)) ?>">
        <span class="parent-overview-icon"><i class="fa-solid fa-coins" aria-hidden="true"></i></span>
        <span class="parent-overview-copy"><strong>Family fee overview</strong><small><?= h($year . ' - ' . $term) ?></small></span>
        <i class="fa-solid fa-chevron-right parent-overview-arrow" aria-hidden="true"></i>
    </a>
    <article class="parent-overview-tile assessed">
        <span class="parent-overview-icon"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span>
        <span class="parent-overview-copy"><strong>Fees assessed</strong><b><?= h(money($combinedAssessedCents / 100)) ?></b></span>
    </article>
    <article class="parent-overview-tile credited">
        <span class="parent-overview-icon"><i class="fa-solid fa-credit-card" aria-hidden="true"></i></span>
        <span class="parent-overview-copy"><strong>Payments credited</strong><b><?= h(money($combinedPaidCents / 100)) ?></b></span>
    </article>
    <article class="parent-overview-tile outstanding">
        <span class="parent-overview-icon"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i></span>
        <span class="parent-overview-copy"><strong>Outstanding balance</strong><b><?= h(money($combinedBalanceCents / 100)) ?></b></span>
    </article>
</section>

<section class="parent-content-panel" id="linked-children">
    <div class="parent-section-title">
        <h2><i class="fa-solid fa-people-group" aria-hidden="true"></i>Linked children</h2>
        <span class="parent-section-count"><?= count($children) ?> <?= count($children) === 1 ? 'child' : 'children' ?></span>
    </div>
    <?php if (!$children): ?>
        <div class="parent-empty-state">
            <h3>No children are linked to this account</h3>
            <p>Please contact the school administration to verify your account and link your child or children.</p>
        </div>
    <?php else: ?>
        <div class="parent-child-list">
            <?php foreach ($children as $child): ?>
                <?php
                    $childPhone = trim((string) ($child['guardian_phone'] ?? ''));
                    $alternatePhone = trim((string) ($child['guardian_alternate_phone'] ?? ''));
                    $guardianEmail = trim((string) ($child['guardian_email'] ?? ''));
                    $phoneLink = preg_replace('/[^0-9+]/', '', $childPhone);
                ?>
                <article class="parent-child-row" data-parent-searchable>
                    <div class="parent-child-person">
                        <span class="parent-child-avatar" aria-hidden="true"><i class="fa-solid fa-user-graduate"></i></span>
                        <div class="parent-child-name">
                            <strong><?= h($child['full_name']) ?></strong>
                            <span><?= h($child['registration_no']) ?> - <?= h($child['class_level']) ?> · <?= h($child['enrollment_status']) ?></span>
                        </div>
                    </div>
                    <div class="parent-child-details" aria-label="Student and guardian details">
                        <div class="parent-child-detail">
                            <span><i class="fa-solid fa-venus-mars" aria-hidden="true"></i> Gender</span>
                            <strong><?= h(trim((string) ($child['gender'] ?? '')) ?: 'Not provided') ?></strong>
                        </div>
                        <div class="parent-child-detail">
                            <span><i class="fa-solid fa-user-shield" aria-hidden="true"></i> Parent / guardian</span>
                            <strong><?= h(trim((string) ($child['parent_name'] ?? '')) ?: 'Not provided') ?></strong>
                        </div>
                        <div class="parent-child-detail">
                            <span><i class="fa-solid fa-phone" aria-hidden="true"></i> Phone</span>
                            <?php if ($childPhone !== '' && $phoneLink !== ''): ?>
                                <a href="tel:<?= h($phoneLink) ?>"><?= h($childPhone) ?></a>
                            <?php else: ?>
                                <strong><?= h($alternatePhone !== '' ? $alternatePhone : 'Not provided') ?></strong>
                            <?php endif; ?>
                            <?php if ($childPhone !== '' && $alternatePhone !== '' && $alternatePhone !== $childPhone): ?>
                                <small>Alt: <?= h($alternatePhone) ?></small>
                            <?php endif; ?>
                        </div>
                        <div class="parent-child-detail">
                            <span><i class="fa-solid fa-envelope" aria-hidden="true"></i> Email</span>
                            <?php if (filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)): ?>
                                <a href="mailto:<?= h($guardianEmail) ?>"><?= h($guardianEmail) ?></a>
                            <?php else: ?>
                                <strong><?= h($guardianEmail !== '' ? $guardianEmail : 'Not provided') ?></strong>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php $services = $childServices[(int) $child['id']] ?? []; ?>
                    <?php if ($services): ?>
                        <div class="parent-child-services" aria-label="Other service balances">
                            <?php foreach ($services as $service): ?>
                                <?php $serviceKey = [
                                    'Feeding' => 'feeding',
                                    'Transport' => 'transport',
                                    'Activities & Trips' => 'activity',
                                    'School Uniform' => 'uniform',
                                ][$service['name']] ?? ''; ?>
                                <a class="parent-child-service" href="<?= url('parent/service_statement.php?service=' . rawurlencode($serviceKey) . '&year=' . rawurlencode($year) . '&term=' . rawurlencode($term)) ?>">
                                    <strong><?= h($service['name']) ?></strong>
                                    <span>Paid <?= h(money($service['paid_amount'])) ?></span>
                                    <b><?= h(money($service['balance'])) ?> due</b>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="parent-child-actions">
                        <a class="btn btn-outline-primary" href="<?= url('parent/statement.php?student_id=' . (int) $child['id'] . '&year=' . rawurlencode($year) . '&term=' . rawurlencode($term)) ?>">Statement &amp; payments <i class="fa-solid fa-chevron-right" aria-hidden="true"></i></a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<section class="parent-content-panel parent-payment-panel" id="recent-payments">
    <div class="parent-section-title">
        <h2><i class="fa-solid fa-credit-card" aria-hidden="true"></i>Recent payments</h2>
        <span class="parent-section-count"><?= h('All terms - ' . $year) ?></span>
    </div>
    <?php if ($recentPayments): ?>
        <div class="parent-table-wrap">
            <table class="table parent-table align-middle">
                <thead><tr><th>Date</th><th>Child</th><th>Service</th><th>Receipt / reference</th><th>Status</th><th class="text-end">Amount</th><th class="text-end">Action</th></tr></thead>
                <tbody>
                <?php foreach ($recentPayments as $payment): ?>
                    <tr data-parent-searchable>
                        <td><?= h($payment['payment_date']) ?></td>
                        <td><span class="parent-payment-child"><?= h($payment['full_name']) ?><small><?= h($payment['registration_no']) ?></small></span></td>
                        <td><span class="parent-payment-child"><?= h($payment['category']) ?><?php if (!empty($payment['description'])): ?><small><?= h($payment['description']) ?></small><?php endif; ?></span></td>
                        <td><?= h($payment['receipt_no'] ?: ($payment['payment_reference'] ?: '—')) ?></td>
                        <td><?= h($payment['payment_status'] ?? 'Recorded') ?></td>
                        <td class="text-end"><?= h(money((float) $payment['amount_paid'])) ?></td>
                        <td class="text-end"><?php if (($payment['source_module'] ?? '') === 'fees'): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('parent/receipt.php?id=' . (int) $payment['source_id']) ?>"><i class="fa-regular fa-eye" aria-hidden="true"></i> View receipt</a><?php else: ?><span class="text-muted">—</span><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="parent-payment-empty"><i class="fa-solid fa-circle-info" aria-hidden="true"></i><span>No payments are recorded for this academic year.</span></div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/parent_footer.php'; ?>
