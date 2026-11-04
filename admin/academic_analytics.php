<?php
$pageTitle = 'Academic Analytics';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.view_analytics')) {
    flash('error', 'You do not have permission to access Academic Analytics.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
$currentContext = current_academic_context($pdo);
$filter = [
    'academic_year' => preg_match('/^\d{4}$/', $_GET['academic_year'] ?? '') ? $_GET['academic_year'] : $currentContext['academic_year'],
    'term' => in_array($_GET['term'] ?? '', term_options(), true) ? $_GET['term'] : $currentContext['term'],
];

$classSummary = academic_class_summary_for_term($pdo, $filter['academic_year'], $filter['term']);
$subjectRankings = academic_subject_term_rankings($pdo, $filter['academic_year'], $filter['term']);
$gradeDistribution = academic_grade_distribution($pdo, $filter['academic_year'], $filter['term']);
$schoolMean = academic_school_mean_score($pdo, $filter['academic_year'], $filter['term']);
$bestClass = $classSummary[0] ?? null;
$weakestSubject = end($subjectRankings) ?: null;

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Academic Analytics</h1>
        <p class="mb-0 text-muted">Deep dive into term performance across classes, subjects and grades.</p>
    </div>
</div>

<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label class="form-label">Academic Year</label>
                <input class="form-control" type="text" name="academic_year" value="<?= h($filter['academic_year']) ?>" required pattern="\d{4}">
            </div>
            <div class="col-md-4">
                <label class="form-label">Term</label>
                <select class="form-select" name="term" required>
                    <?php foreach (term_options() as $termOption): ?>
                        <option value="<?= h($termOption) ?>" <?= $filter['term'] === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-4 text-end">
                <button class="btn btn-primary w-100" type="submit">Refresh Analytics</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted small mb-1">School Mean Score</p>
                <h2 class="h3 mb-0"><?= h(number_format($schoolMean, 2)) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted small mb-1">Best Class</p>
                <h2 class="h3 mb-0"><?= h($bestClass['class_level'] ?? '—') ?></h2>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted small mb-1">Best Class Average</p>
                <h2 class="h3 mb-0"><?= h(isset($bestClass['average']) ? number_format($bestClass['average'], 2) : '—') ?></h2>
            </div>
        </div>
    </div>
    <div class="col-lg-3 col-sm-6">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted small mb-1">Weakest Subject</p>
                <h2 class="h3 mb-0"><?= h($weakestSubject['subject_name'] ?? '—') ?></h2>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h5 mb-3">Grade Distribution</h2>
                <canvas id="gradeDistributionChart" height="220"></canvas>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm border-0">
            <div class="card-body">
                <h2 class="h5 mb-3">Class Averages</h2>
                <canvas id="classAverageChart" height="220"></canvas>
            </div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-body">
        <h2 class="h5 mb-3">Subject Rankings</h2>
        <?php if ($subjectRankings): ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Subject</th>
                            <th class="text-center">Average Score</th>
                            <th class="text-center">Grade</th>
                            <th class="text-center">Students</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjectRankings as $subject): ?>
                            <tr>
                                <td><?= h($subject['subject_name']) ?></td>
                                <td class="text-center"><?= h(number_format($subject['average_score'], 2)) ?></td>
                                <td class="text-center"><?= h(academic_grade_for_score((float) $subject['average_score'])) ?></td>
                                <td class="text-center"><?= h((int) $subject['student_count']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">No analytics data available for this term.</div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const gradeLabels = <?= json_encode(array_keys($gradeDistribution)) ?>;
        const gradeValues = <?= json_encode(array_values($gradeDistribution)) ?>;
        const classLabels = <?= json_encode(array_column($classSummary, 'class_level')) ?>;
        const classValues = <?= json_encode(array_map(fn($row) => (float)$row['average'], $classSummary)) ?>;

        const gradeCtx = document.getElementById('gradeDistributionChart');
        if (gradeCtx) {
            new Chart(gradeCtx, {
                type: 'doughnut',
                data: {
                    labels: gradeLabels,
                    datasets: [{
                        data: gradeValues,
                        backgroundColor: ['#16a34a', '#0ea5e9', '#f59e0b', '#fb923c', '#ef4444'],
                    }],
                },
                options: { responsive: true },
            });
        }

        const classCtx = document.getElementById('classAverageChart');
        if (classCtx) {
            new Chart(classCtx, {
                type: 'bar',
                data: {
                    labels: classLabels,
                    datasets: [{
                        label: 'Average Score',
                        data: classValues,
                        backgroundColor: '#229ed1',
                        borderRadius: 6,
                    }],
                },
                options: {
                    responsive: true,
                    scales: { y: { beginAtZero: true, max: 100 } },
                },
            });
        }
    });
</script>
