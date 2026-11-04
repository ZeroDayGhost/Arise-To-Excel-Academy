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
$assessedCents = 0;
$paidCents = 0;
$balanceCents = 0;
foreach ($children as $child) {
    $assessedCents += (int) round((float) $child['assessed_amount'] * 100);
    $paidCents += (int) round((float) $child['paid_amount'] * 100);
    $balanceCents += (int) round((float) $child['balance'] * 100);
}
$pageTitle = 'Fee Statement';
require __DIR__ . '/../includes/parent_header.php';
?>
<section class="parent-page-heading">
    <div>
        <span class="parent-page-eyebrow">FAMILY FINANCES</span>
        <h1>Fee statements</h1>
        <p>Choose a child to view their fee statement and payment history.</p>
    </div>
    <form class="parent-period-form" method="get">
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
</section>

<section class="parent-dashboard-metrics parent-page-metrics" aria-label="Family fee summary">
    <article class="parent-overview-tile family"><span class="parent-overview-icon"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Linked children</strong><b><?= count($children) ?></b></span></article>
    <article class="parent-overview-tile assessed"><span class="parent-overview-icon"><i class="fa-solid fa-sack-dollar" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Fees assessed</strong><b><?= h(money($assessedCents / 100)) ?></b></span></article>
    <article class="parent-overview-tile credited"><span class="parent-overview-icon"><i class="fa-solid fa-credit-card" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Payments credited</strong><b><?= h(money($paidCents / 100)) ?></b></span></article>
    <article class="parent-overview-tile outstanding"><span class="parent-overview-icon"><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Outstanding balance</strong><b><?= h(money($balanceCents / 100)) ?></b></span></article>
</section>

<section class="parent-content-panel">
    <div class="parent-section-title">
        <h2><i class="fa-solid fa-file-invoice-dollar" aria-hidden="true"></i>Children's fee statements</h2>
        <span class="parent-section-count"><?= count($children) ?> <?= count($children) === 1 ? 'child' : 'children' ?></span>
    </div>
    <?php if (!$children): ?>
        <div class="parent-empty-state">
            <h3>No linked children yet</h3>
            <p>Contact the school administration to link your child's account.</p>
        </div>
    <?php else: ?>
        <div class="parent-table-wrap">
            <table class="table parent-table align-middle">
                <thead><tr><th>Child</th><th>Registration</th><th>Class</th><th>Fees assessed</th><th>Paid</th><th>Balance</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($children as $child): ?>
                    <tr data-parent-searchable>
                        <td><span class="parent-table-person"><span class="parent-child-avatar"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i></span><strong><?= h($child['full_name']) ?></strong></span></td>
                        <td><?= h($child['registration_no']) ?></td>
                        <td><?= h($child['class_level']) ?></td>
                        <td><?= h(money((float) $child['assessed_amount'])) ?></td>
                        <td><?= h(money((float) $child['paid_amount'])) ?></td>
                        <td><strong><?= h(money((float) $child['balance'])) ?></strong></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('parent/statement.php?student_id=' . (int) $child['id'] . '&year=' . rawurlencode($year) . '&term=' . rawurlencode($term)) ?>">Open statement</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/parent_footer.php'; ?>
