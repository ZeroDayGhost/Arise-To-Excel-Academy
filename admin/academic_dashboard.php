<?php
$pageTitle = 'Academic Dashboard';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.view')) {
    flash('error', 'You do not have permission to access the Academic Dashboard.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
$currentContext = current_academic_context($pdo);
$academicYear = preg_match('/^\d{4}$/', $_GET['academic_year'] ?? '') ? $_GET['academic_year'] : $currentContext['academic_year'];
$term = in_array($_GET['term'] ?? '', term_options(), true) ? $_GET['term'] : $currentContext['term'];
ensure_academic_exams_for_period($pdo, $academicYear, $term);

$totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE enrollment_status = 'Active'")->fetchColumn();
$subjectsOffered = count(get_academic_subjects_simple($pdo));
$examsCreated = academic_exams_created_count($pdo, $academicYear, $term);
$resultsPublished = academic_results_published($pdo, $academicYear, $term);
$schoolMeanScore = academic_school_mean_score($pdo, $academicYear, $term);
$classSummary = academic_class_summary_for_term($pdo, $academicYear, $term);
$subjectRankings = academic_subject_term_rankings($pdo, $academicYear, $term);
$gradeDistribution = academic_grade_distribution($pdo, $academicYear, $term);
$bestClass = $classSummary[0]['class_level'] ?? 'N/A';
$lowestClass = $classSummary ? $classSummary[count($classSummary) - 1]['class_level'] : 'N/A';

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div class="welcome-copy">
        <p class="dashboard-greeting"><?= h(ucwords(time_of_day_greeting())) ?></p>
        <h1><?= h($_SESSION['admin_name'] ?? 'System Administrator') ?></h1>
        <p class="mb-0 text-muted">Here's your <?= h($academicYear) ?> <?= h($term) ?> academic performance overview.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-primary" href="<?= url('admin/marks_entry.php?academic_year=' . urlencode($academicYear) . '&term=' . urlencode($term)) ?>">
            <i class="fa-solid fa-pen-to-square me-2"></i>Enter Marks
        </a>
        <a class="btn btn-outline-primary" href="<?= url('admin/results.php?academic_year=' . urlencode($academicYear) . '&term=' . urlencode($term)) ?>">
            <i class="fa-solid fa-rotate me-2"></i>Process Results
        </a>
    </div>
</div>

<?php render_academic_module_nav(); ?>

<section class="panel mb-4">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-4">
            <label class="form-label">Academic Year</label>
            <input class="form-control" name="academic_year" value="<?= h($academicYear) ?>" pattern="\d{4}" required>
        </div>
        <div class="col-md-4">
            <label class="form-label">Term</label>
            <select class="form-select" name="term" required>
                <?php foreach (term_options() as $termOption): ?>
                    <option value="<?= h($termOption) ?>" <?= $term === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4">
            <button class="btn btn-primary w-100" type="submit">Load Academic Period</button>
        </div>
    </form>
</section>

<div class="dashboard-stat-grid">
    <article class="metric-card stat-students">
        <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
        <div class="stat-content">
            <span><?= h((string) $totalStudents) ?></span>
            <p class="stat-label">Total Students</p>
        </div>
    </article>
    <article class="metric-card stat-boys">
        <div class="stat-icon"><i class="fa-solid fa-book"></i></div>
        <div class="stat-content">
            <span><?= h((string) $subjectsOffered) ?></span>
            <p class="stat-label">Subjects Offered</p>
        </div>
    </article>
    <article class="metric-card stat-money">
        <div class="stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="stat-content">
            <span><?= h((string) $examsCreated) ?></span>
            <p class="stat-label">Exams Created</p>
        </div>
    </article>
    <article class="metric-card stat-success">
        <div class="stat-icon"><i class="fa-solid fa-square-poll-vertical"></i></div>
        <div class="stat-content">
            <span><?= h((string) $resultsPublished) ?></span>
            <p class="stat-label">Results Published</p>
        </div>
    </article>
    <article class="metric-card stat-warning">
        <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
        <div class="stat-content">
            <span><?= h(number_format($schoolMeanScore, 2)) ?></span>
            <p class="stat-label">Average School Mean</p>
        </div>
    </article>
    <article class="metric-card stat-info">
        <div class="stat-icon"><i class="fa-solid fa-ranking-star"></i></div>
        <div class="stat-content">
            <span><?= h($bestClass) ?></span>
            <p class="stat-label">Best Performing Class</p>
        </div>
    </article>
    <article class="metric-card stat-warning">
        <div class="stat-icon"><i class="fa-solid fa-arrow-trend-down"></i></div>
        <div class="stat-content">
            <span><?= h($lowestClass) ?></span>
            <p class="stat-label">Lowest Performing Class</p>
        </div>
    </article>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-4">
        <section class="panel h-100">
            <div class="panel-heading">
                <div>
                    <h2>Academic Workflow</h2>
                    <p class="panel-subtitle">Follow the term sequence from setup to reports.</p>
                </div>
            </div>
            <div class="quick-actions-list">
                <a class="quick-action" href="<?= url('admin/subjects.php') ?>"><i class="fa-solid fa-book"></i>Subjects</a>
                <a class="quick-action" href="<?= url('admin/exams.php?academic_year=' . urlencode($academicYear) . '&term=' . urlencode($term)) ?>"><i class="fa-solid fa-calendar-check"></i>Exams</a>
                <a class="quick-action" href="<?= url('admin/marks_entry.php?academic_year=' . urlencode($academicYear) . '&term=' . urlencode($term)) ?>"><i class="fa-solid fa-pen"></i>Marks Entry</a>
                <a class="quick-action" href="<?= url('admin/results.php?academic_year=' . urlencode($academicYear) . '&term=' . urlencode($term)) ?>"><i class="fa-solid fa-rotate"></i>Results Processing</a>
                <a class="quick-action" href="<?= url('admin/report_cards.php?academic_year=' . urlencode($academicYear) . '&term=' . urlencode($term)) ?>"><i class="fa-solid fa-file-lines"></i>Report Cards</a>
            </div>
        </section>
    </div>
    <div class="col-xl-8">
        <section class="panel h-100">
            <div class="panel-heading">
                <div>
                    <h2>Class Performance</h2>
                    <p class="panel-subtitle">Average score by class.</p>
                </div>
            </div>
            <canvas id="classPerformanceChart" height="165"></canvas>
        </section>
    </div>
</div>

<div class="row g-4 mb-5">
    <div class="col-xl-7">
        <section class="panel h-100">
            <div class="panel-heading">
                <div>
                    <h2>Subject Performance</h2>
                    <p class="panel-subtitle">Average score by subject across the selected term.</p>
                </div>
            </div>
            <canvas id="subjectPerformanceChart" height="220"></canvas>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="panel h-100">
            <div class="panel-heading">
                <div>
                    <h2>Grade Distribution</h2>
                    <p class="panel-subtitle">Students grouped by calculated final grade.</p>
                </div>
            </div>
            <canvas id="gradeDistributionChart" height="220"></canvas>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const classLabels = <?= json_encode(array_column($classSummary, 'class_level')) ?>;
    const classValues = <?= json_encode(array_map(fn($row) => round((float) $row['average'], 2), $classSummary)) ?>;
    const subjectLabels = <?= json_encode(array_column($subjectRankings, 'subject_name')) ?>;
    const subjectValues = <?= json_encode(array_map(fn($row) => round((float) $row['average_score'], 2), $subjectRankings)) ?>;
    const gradeLabels = <?= json_encode(array_keys($gradeDistribution)) ?>;
    const gradeValues = <?= json_encode(array_values($gradeDistribution)) ?>;

    const commonScale = { y: { beginAtZero: true, max: 100 } };
    const classCtx = document.getElementById('classPerformanceChart');
    if (classCtx) {
        new Chart(classCtx, {
            type: 'bar',
            data: { labels: classLabels, datasets: [{ label: 'Class Average', data: classValues, backgroundColor: '#229ed1', borderRadius: 8 }] },
            options: { responsive: true, scales: commonScale }
        });
    }

    const subjectCtx = document.getElementById('subjectPerformanceChart');
    if (subjectCtx) {
        new Chart(subjectCtx, {
            type: 'line',
            data: { labels: subjectLabels, datasets: [{ label: 'Subject Average', data: subjectValues, borderColor: '#14b8a6', backgroundColor: 'rgba(20,184,166,0.16)', fill: true, tension: 0.35 }] },
            options: { responsive: true, scales: commonScale }
        });
    }

    const gradeCtx = document.getElementById('gradeDistributionChart');
    if (gradeCtx) {
        new Chart(gradeCtx, {
            type: 'doughnut',
            data: { labels: gradeLabels, datasets: [{ data: gradeValues, backgroundColor: ['#16a34a', '#0ea5e9', '#f59e0b', '#f97316', '#df2333'] }] },
            options: { responsive: true, cutout: '66%' }
        });
    }
});
</script>
