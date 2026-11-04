<?php
$pageTitle = 'Subject Rankings';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.view_rankings')) {
    flash('error', 'You do not have permission to access Subject Rankings.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
$currentContext = current_academic_context($pdo);
$classLevels = class_level_options();
$filter = [
    'academic_year' => preg_match('/^\d{4}$/', $_GET['academic_year'] ?? '') ? $_GET['academic_year'] : $currentContext['academic_year'],
    'term' => in_array($_GET['term'] ?? '', term_options(), true) ? $_GET['term'] : $currentContext['term'],
    'class_level' => in_array($_GET['class_level'] ?? '', $classLevels, true) ? $_GET['class_level'] : '',
];

$bestStudents = academic_best_students_by_subject($pdo, $filter['academic_year'], $filter['term'], $filter['class_level'] ?: null);
$subjectAverages = academic_subject_term_rankings($pdo, $filter['academic_year'], $filter['term']);

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Subject Rankings</h1>
        <p class="mb-0 text-muted">Best students per subject and overall subject performance.</p>
    </div>
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
            <select class="form-select" name="class_level">
                <option value="">All classes</option>
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

<div class="row g-4">
    <div class="col-xl-7">
        <section class="panel h-100">
            <div class="panel-heading">
                <div>
                    <h2>Best Students Per Subject</h2>
                    <p class="panel-subtitle"><?= h(academic_report_filter_label($filter['academic_year'], $filter['term'], $filter['class_level'] ?: null)) ?></p>
                </div>
            </div>
            <?php if ($bestStudents): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th>Student</th>
                                <th>Class</th>
                                <th class="text-end">Total Marks</th>
                                <th class="text-center">Points</th>
                                <th class="text-center">Performance Level</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($bestStudents as $row): ?>
                                <tr>
                                    <td><strong><?= h($row['subject_name']) ?></strong></td>
                                    <td><?= h($row['full_name']) ?> <span class="text-muted">(<?= h($row['registration_no']) ?>)</span></td>
                                    <td><?= h($row['class_level']) ?></td>
                                    <td class="text-end"><?= h(number_format((float) $row['subject_total'], 2)) ?></td>
                                    <td class="text-center"><?= h((string) $row['grade']) ?>/8</td>
                                    <td class="text-center"><span class="status-pill status-info"><?= h(academic_grade_expectation_label($row['grade'])) ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">No subject ranking data available yet.</div>
            <?php endif; ?>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="panel h-100">
            <div class="panel-heading">
                <div>
                    <h2>Subject Performance</h2>
                    <p class="panel-subtitle">Ranked by mean points and total marks.</p>
                </div>
            </div>
            <?php if ($subjectAverages): ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th>Subject</th>
                                <th class="text-end">Total Marks</th>
                                <th class="text-center">Total Points</th>
                                <th class="text-center">Mean Points</th>
                                <th class="text-center">Students</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($subjectAverages as $row): ?>
                                <tr>
                                    <td><?= h($row['subject_name']) ?></td>
                                    <td class="text-end"><?= h(number_format((float) ($row['total_marks'] ?? 0), 2)) ?></td>
                                    <td class="text-center"><?= h((string) (int) ($row['total_points'] ?? 0)) ?></td>
                                    <td class="text-center"><?= h(number_format((float) ($row['mean_points'] ?? $row['average_score']), 2)) ?>/8</td>
                                    <td class="text-center"><?= h((string) $row['student_count']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-info mb-0">No subject performance data available.</div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
