<?php
$pageTitle = 'Results Processing';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.view_results')) {
    flash('error', 'You do not have permission to access Results.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
ensure_playgroup_exists($pdo);
$currentContext = current_academic_context($pdo);
$classLevels = class_level_options();
$filter = [
    'academic_year' => preg_match('/^\d{4}$/', $_GET['academic_year'] ?? ($_POST['academic_year'] ?? '')) ? ($_GET['academic_year'] ?? $_POST['academic_year']) : $currentContext['academic_year'],
    'term' => in_array($_GET['term'] ?? ($_POST['term'] ?? ''), term_options(), true) ? ($_GET['term'] ?? $_POST['term']) : $currentContext['term'],
    'class_level' => in_array($_GET['class_level'] ?? ($_POST['class_level'] ?? ''), $classLevels, true) ? ($_GET['class_level'] ?? $_POST['class_level']) : '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'process_results') {
    if ($filter['class_level'] !== '') {
        academic_recalculate_class_results($pdo, $filter['class_level'], $filter['academic_year'], $filter['term']);
        flash('success', 'Results recalculated for ' . $filter['class_level'] . '.');
    } else {
        academic_recalculate_term_results($pdo, $filter['academic_year'], $filter['term']);
        flash('success', 'Results recalculated for all classes.');
    }
    redirect('admin/results.php?' . http_build_query($filter));
}

$rankings = [];
$classTotalMarks = 0.0;
$classTotalPoints = 0;
$classMeanPoints = 0.0;
$processedCount = 0;
if ($filter['class_level'] !== '') {
    $rankings = academic_class_term_rankings($pdo, $filter['class_level'], $filter['academic_year'], $filter['term']);
    $processedCount = count(array_filter($rankings, fn($row) => (int) $row['marks_count'] > 0));
    $classTotalMarks = array_sum(array_map(fn($row) => (float) $row['student_total'], $rankings));
    $classTotalPoints = (int) array_sum(array_map(fn($row) => (int) ($row['total_points'] ?? 0), $rankings));
    $classMeanPoints = $processedCount > 0 ? round(array_sum(array_map(fn($row) => (float) ($row['mean_points'] ?? $row['average']), $rankings)) / $processedCount, 2) : 0.0;
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Results Processing</h1>
        <p class="mb-0 text-muted">Calculate total marks, points, performance levels, and positions from raw marks.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/report_cards.php?academic_year=' . urlencode($filter['academic_year']) . '&term=' . urlencode($filter['term']) . '&class_level=' . urlencode($filter['class_level'])) ?>">
        <i class="fa-solid fa-file-lines me-2"></i>Report Cards
    </a>
</div>

<?php render_academic_module_nav(); ?>

<section class="panel mb-4">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-3">
            <label class="form-label">Academic Year</label>
            <input class="form-control" name="academic_year" value="<?= h($filter['academic_year']) ?>" pattern="\d{4}" required>
        </div>
        <div class="col-md-3">
            <label class="form-label">Term</label>
            <select class="form-select" name="term" required>
                <?php foreach (term_options() as $termOption): ?>
                    <option value="<?= h($termOption) ?>" <?= $filter['term'] === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Class</label>
            <select class="form-select" name="class_level" required>
                <option value="">Select class</option>
                <?php foreach ($classLevels as $level): ?>
                    <option value="<?= h($level) ?>" <?= $filter['class_level'] === $level ? 'selected' : '' ?>><?= h($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary w-100" type="submit">Load</button>
        </div>
    </form>
</section>

<?php if ($filter['class_level'] !== ''): ?>
    <div class="dashboard-stat-grid">
        <article class="metric-card stat-info">
            <span><?= h((string) count($rankings)) ?></span>
            <p>Total Students</p>
        </article>
        <article class="metric-card stat-success">
            <span><?= h((string) $processedCount) ?></span>
            <p>With Marks</p>
        </article>
        <article class="metric-card stat-warning">
            <span><?= h(number_format($classMeanPoints, 2)) ?></span>
            <p>Class Mean Points</p>
        </article>
        <article class="metric-card stat-info">
            <span><?= h(number_format($classTotalMarks, 0)) ?></span>
            <p>Total Marks</p>
        </article>
    </div>

    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2><?= h($filter['class_level']) ?> Results</h2>
                <p class="panel-subtitle"><?= h($filter['academic_year']) ?> <?= h($filter['term']) ?>. Results update when marks are edited.</p>
            </div>
            <form method="post" class="m-0">
                <input type="hidden" name="action" value="process_results">
                <input type="hidden" name="academic_year" value="<?= h($filter['academic_year']) ?>">
                <input type="hidden" name="term" value="<?= h($filter['term']) ?>">
                <input type="hidden" name="class_level" value="<?= h($filter['class_level']) ?>">
                <button class="btn btn-primary" type="submit"><i class="fa-solid fa-rotate me-2"></i>Process Results</button>
            </form>
        </div>
        <?php if ($rankings): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center">Position</th>
                            <th>Adm No</th>
                            <th>Student</th>
                            <th class="text-end">Student Total</th>
                            <th class="text-center">Total Points</th>
                            <th class="text-center">Mean Points</th>
                            <th class="text-center">Performance Level</th>
                            <th class="text-center">Subjects</th>
                            <th class="text-center">Marks</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rankings as $row): ?>
                            <tr>
                                <td class="text-center"><span class="rank-badge"><?= h((string) ($row['rank'] ?? '-')) ?></span></td>
                                <td><code><?= h($row['registration_no']) ?></code></td>
                                <td><strong><?= h($row['full_name']) ?></strong></td>
                                <td class="text-end"><?= h(number_format((float) $row['student_total'], 2)) ?></td>
                                <td class="text-center"><?= h((string) ($row['total_points'] ?? 0)) ?></td>
                                <td class="text-center"><?= h(number_format((float) ($row['mean_points'] ?? $row['average']), 2)) ?>/8</td>
                                <td class="text-center"><span class="status-pill status-info"><?= h(academic_grade_expectation_label($row['grade'])) ?></span></td>
                                <td class="text-center"><?= h((string) $row['subject_count']) ?></td>
                                <td class="text-center"><?= h((string) $row['marks_count']) ?></td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/report_cards.php?class_level=' . urlencode($filter['class_level']) . '&student_id=' . (int) $row['student_id'] . '&academic_year=' . urlencode($filter['academic_year']) . '&term=' . urlencode($filter['term'])) ?>">Report Card</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">No students found for this class.</div>
        <?php endif; ?>

        <?php if ($rankings): ?>
            <div class="grade-descriptor-card mt-4">
                <h3>Performance Legend</h3>
                <div class="table-responsive">
                    <table class="table grade-descriptor-table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Performance Level</th>
                                <th class="text-center">Points</th>
                                <th class="text-center">Score Range (%)</th>
                                <th>Remark</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (academic_grade_point_descriptors() as $descriptor): ?>
                                <tr>
                                    <td><?= h($descriptor['label']) ?></td>
                                    <td class="text-center"><?= h((string) $descriptor['points']) ?></td>
                                    <td class="text-center"><?= h($descriptor['range']) ?></td>
                                    <td><?= h($descriptor['remark']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </section>
<?php else: ?>
    <div class="alert alert-info">Select a class to process and review academic results.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
