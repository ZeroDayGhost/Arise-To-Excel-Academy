<?php
require_once __DIR__ . '/../includes/parent_auth.php';

$context = current_academic_context($pdo);
$year = parent_portal_normalize_year($context['academic_year'] ?? '') ?: date('Y');
$terms = parent_portal_term_options();
$term = in_array($context['term'] ?? '', $terms, true) ? $context['term'] : 'Term 1';
$children = parent_portal_children($pdo, $parentUserId, $year, $term);
$activeChildren = count(array_filter($children, static fn (array $child): bool => strcasecmp((string) ($child['enrollment_status'] ?? ''), 'Active') === 0));

$pageTitle = 'Student Details';
require __DIR__ . '/../includes/parent_header.php';
?>
<section class="parent-page-heading parent-simple-heading">
    <div>
        <span class="parent-page-eyebrow">YOUR FAMILY</span>
        <h1>Student details</h1>
        <p>Information for children linked to your account.</p>
    </div>
</section>

<section class="parent-dashboard-metrics parent-page-metrics parent-student-metrics" aria-label="Student summary">
    <article class="parent-overview-tile family"><span class="parent-overview-icon"><i class="fa-solid fa-people-group" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Linked children</strong><b><?= count($children) ?></b></span></article>
    <article class="parent-overview-tile assessed"><span class="parent-overview-icon"><i class="fa-solid fa-user-check" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Currently enrolled</strong><b><?= $activeChildren ?></b></span></article>
    <article class="parent-overview-tile credited"><span class="parent-overview-icon"><i class="fa-regular fa-calendar" aria-hidden="true"></i></span><span class="parent-overview-copy"><strong>Academic year</strong><b><?= h($year) ?></b></span></article>
</section>

<section class="parent-content-panel">
    <div class="parent-section-title">
        <h2><i class="fa-solid fa-people-group" aria-hidden="true"></i>Linked children</h2>
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
                <thead><tr><th>Student name</th><th>Admission number</th><th>Class level</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($children as $child): ?>
                    <tr data-parent-searchable>
                        <td><span class="parent-table-person"><span class="parent-child-avatar"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i></span><strong><?= h($child['full_name']) ?></strong></span></td>
                        <td><?= h($child['registration_no']) ?></td>
                        <td><?= h($child['class_level']) ?></td>
                        <td><span class="parent-status-badge <?= strcasecmp((string) ($child['enrollment_status'] ?? ''), 'Active') === 0 ? 'is-active' : '' ?>"><?= h($child['enrollment_status']) ?></span></td>
                        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('parent/statement.php?student_id=' . (int) $child['id'] . '&year=' . rawurlencode($year) . '&term=' . rawurlencode($term)) ?>">Fee statement</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>
<?php require __DIR__ . '/../includes/parent_footer.php'; ?>
