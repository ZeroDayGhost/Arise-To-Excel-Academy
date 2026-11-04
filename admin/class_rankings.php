<?php
$pageTitle = 'Class Rankings';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.view_rankings')) {
    flash('error', 'You do not have permission to access Class Rankings.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
$currentContext = current_academic_context($pdo);
$classLevels = class_level_options();
$filter = [
    'academic_year' => preg_match('/^\d{4}$/', $_GET['academic_year'] ?? '') ? $_GET['academic_year'] : $currentContext['academic_year'],
    'term' => in_array($_GET['term'] ?? '', term_options(), true) ? $_GET['term'] : $currentContext['term'],
    'class_level' => in_array($_GET['class_level'] ?? '', $classLevels, true) ? $_GET['class_level'] : ($classLevels[0] ?? ''),
];

$rankings = $filter['class_level'] !== '' ? academic_class_term_rankings($pdo, $filter['class_level'], $filter['academic_year'], $filter['term']) : [];

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Class Rankings</h1>
        <p class="mb-0 text-muted">Students ranked automatically by mean points and total marks.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/results.php?' . http_build_query($filter)) ?>"><i class="fa-solid fa-square-poll-vertical me-2"></i>Results</a>
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
                <?php foreach ($classLevels as $level): ?>
                    <option value="<?= h($level) ?>" <?= $filter['class_level'] === $level ? 'selected' : '' ?>><?= h($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <button class="btn btn-primary w-100" type="submit">Rank Class</button>
        </div>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h2><?= h($filter['class_level']) ?> Ranking</h2>
            <p class="panel-subtitle"><?= h($filter['academic_year']) ?> <?= h($filter['term']) ?></p>
        </div>
    </div>
    <?php if ($rankings): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th class="text-center">Position</th>
                        <th>Adm No</th>
                        <th>Student</th>
                        <th class="text-end">Total Marks</th>
                        <th class="text-center">Total Points</th>
                        <th class="text-center">Mean Points</th>
                        <th class="text-center">Performance Level</th>
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
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="alert alert-info mb-0">No ranking data available for this class.</div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
