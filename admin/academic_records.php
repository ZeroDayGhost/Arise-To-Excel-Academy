<?php
$pageTitle = 'Academic Records';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.view')) {
    flash('error', 'You do not have permission to access Academic Records.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
ensure_playgroup_exists($pdo);
$currentContext = current_academic_context($pdo);
$academicYear = $currentContext['academic_year'];
$term = $currentContext['term'];

$subjects = get_academic_subjects($pdo);
$classLevels = class_level_options();
$examTypes = academic_exam_types();
$examLabels = academic_exam_type_labels();

$examCounts = [];
foreach ($examTypes as $examType) {
    $statement = $pdo->prepare(
        "SELECT COUNT(*) FROM academic_marks
         WHERE academic_year = :academic_year
           AND term = :term
           AND exam_type = :exam_type"
    );
    $statement->execute([
        'academic_year' => $academicYear,
        'term' => $term,
        'exam_type' => $examType,
    ]);
    $examCounts[$examType] = (int) $statement->fetchColumn();
}

$totalStudents = (int) $pdo->query("SELECT COUNT(*) FROM students WHERE enrollment_status = 'Active'")->fetchColumn();
$subjectsOffered = count($subjects);
$reportCardsGenerated = academic_report_cards_generated($pdo, $academicYear, $term);
$schoolMeanScore = academic_school_mean_score($pdo, $academicYear, $term);
$classSummary = academic_class_summary_for_term($pdo, $academicYear, $term);
$bestClass = $classSummary[0]['class_level'] ?? '—';
$bestClassAverage = $classSummary[0]['average'] ?? 0.0;
$analytics = academic_class_analytics($pdo, $academicYear, $term);
$gradeDistribution = $analytics['grade_distribution'];
$subjectsRankings = academic_subject_term_rankings($pdo, $academicYear, $term);
$weakestSubject = $analytics['weakest_subject'] ?? '—';

$studentsAbove70 = 0;
$studentRows = $pdo->query("SELECT id FROM students WHERE enrollment_status = 'Active' ORDER BY full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
foreach ($studentRows as $studentRow) {
    $average = academic_student_term_average($pdo, (int) $studentRow['id'], $academicYear, $term);
    if ($average >= 70) {
        $studentsAbove70++;
    }
}

$recentActions = [];
$marksStmt = $pdo->prepare(
    "SELECT am.student_id, am.exam_type, am.subject_id, am.marks, am.updated_at, s.full_name
     FROM academic_marks am
     JOIN students s ON s.id = am.student_id
     WHERE am.academic_year = :academic_year
       AND am.term = :term
     ORDER BY am.updated_at DESC
     LIMIT 4"
);
$marksStmt->execute(['academic_year' => $academicYear, 'term' => $term]);
foreach ($marksStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $recentActions[] = [
        'date' => $row['updated_at'],
        'activity' => sprintf('Updated %s marks for %s', $row['exam_type'], $row['full_name']),
        'details' => sprintf('%s pts', $row['marks']),
    ];
}

$reportsStmt = $pdo->prepare(
    "SELECT rc.student_id, rc.generated_at, s.full_name
     FROM academic_report_cards rc
     JOIN students s ON s.id = rc.student_id
     WHERE rc.academic_year = :academic_year
       AND rc.term = :term
     ORDER BY rc.generated_at DESC
     LIMIT 4"
);
$reportsStmt->execute(['academic_year' => $academicYear, 'term' => $term]);
foreach ($reportsStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $recentActions[] = [
        'date' => $row['generated_at'],
        'activity' => 'Generated report card',
        'details' => $row['full_name'],
    ];
}

usort($recentActions, fn($a, $b) => strcmp($b['date'], $a['date']));
$recentActions = array_slice($recentActions, 0, 4);

$topStudents = [];
$totalMarksRecorded = $examCounts['Opening'] + $examCounts['Midterm'] + $examCounts['Closing'];
if ($totalMarksRecorded > 0) {
    foreach ($classLevels as $classLevel) {
        $rankings = academic_class_term_rankings($pdo, $classLevel, $academicYear, $term);
        if (!empty($rankings)) {
            $topStudents[] = $rankings[0];
        }
    }
    usort($topStudents, fn($a, $b) => $b['average'] <=> $a['average']);
    $topStudents = array_slice($topStudents, 0, 3);
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Dashboard</p>
        <h1>Academic Records</h1>
        <p class="mb-0 text-muted">Manage exams, marks, results and academic performance in one unified view.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-primary" href="<?= url('admin/marks_entry.php') ?>">Enter Marks</a>
        <a class="btn btn-outline-primary" href="<?= url('admin/subjects.php') ?>">Add Subject</a>
    </div>
</div>

<div class="dashboard-stat-grid">
    <article class="metric-card stat-students">
        <div class="stat-icon"><i class="fa-solid fa-users"></i></div>
        <div class="stat-content">
            <span><?= h($totalStudents) ?></span>
            <p class="stat-label">Total Students</p>
        </div>
    </article>
    <article class="metric-card stat-boys">
        <div class="stat-icon"><i class="fa-solid fa-book-open"></i></div>
        <div class="stat-content">
            <span><?= h($subjectsOffered) ?></span>
            <p class="stat-label">Subjects Offered</p>
        </div>
    </article>
    <article class="metric-card stat-money">
        <div class="stat-icon"><i class="fa-solid fa-calendar-check"></i></div>
        <div class="stat-content">
            <span><?= h($examCounts['Opening']) ?></span>
            <p class="stat-label">Opening Exam Entries</p>
        </div>
    </article>
    <article class="metric-card stat-success">
        <div class="stat-icon"><i class="fa-solid fa-chart-line"></i></div>
        <div class="stat-content">
            <span><?= h($examCounts['Midterm']) ?></span>
            <p class="stat-label">Midterm Exam Entries</p>
        </div>
    </article>
    <article class="metric-card stat-warning">
        <div class="stat-icon"><i class="fa-solid fa-flag-checkered"></i></div>
        <div class="stat-content">
            <span><?= h($examCounts['Closing']) ?></span>
            <p class="stat-label">Closing Exam Entries</p>
        </div>
    </article>
    <article class="metric-card stat-info">
        <div class="stat-icon"><i class="fa-solid fa-file-lines"></i></div>
        <div class="stat-content">
            <span><?= h($reportCardsGenerated) ?></span>
            <p class="stat-label">Report Cards Generated</p>
        </div>
    </article>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Performance Overview</h2>
                    <p class="panel-subtitle">Key metrics for the current academic year and term.</p>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-6 col-sm-6 col-lg-3">
                    <div class="metric-card" style="--stat-gradient: linear-gradient(135deg, #22c55e, #16a34a); --stat-shadow: rgba(22,163,74,0.22); --stat-soft: rgba(22,163,74,0.1); --stat-text: #15803d;">
                        <span><?= h(number_format($schoolMeanScore, 2)) ?></span>
                        <p class="stat-label">Overall School Mean</p>
                    </div>
                </div>
                <div class="col-6 col-sm-6 col-lg-3">
                    <div class="metric-card" style="--stat-gradient: linear-gradient(135deg, #229ed1, #1179a8); --stat-shadow: rgba(34,158,209,0.22); --stat-soft: rgba(34,158,209,0.1); --stat-text: #229ed1;">
                        <span><?= h($bestClass) ?></span>
                        <p class="stat-label">Best Performing Class</p>
                    </div>
                </div>
                <div class="col-6 col-sm-6 col-lg-3">
                    <div class="metric-card" style="--stat-gradient: linear-gradient(135deg, #fb923c, #f97316); --stat-shadow: rgba(249,115,22,0.22); --stat-soft: rgba(249,115,22,0.1); --stat-text: #c2410c;">
                        <span><?= h($weakestSubject) ?></span>
                        <p class="stat-label">Lowest Performing Subject</p>
                    </div>
                </div>
                <div class="col-6 col-sm-6 col-lg-3">
                    <div class="metric-card" style="--stat-gradient: linear-gradient(135deg, #0ea5e9, #0284c7); --stat-shadow: rgba(14,165,233,0.22); --stat-soft: rgba(14,165,233,0.1); --stat-text: #0369a1;">
                        <span><?= h($studentsAbove70) ?></span>
                        <p class="stat-label">Students Above 70%</p>
                    </div>
                </div>
            </div>
        </section>
    </div>
    <div class="col-xl-4">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Quick Actions</h2>
                    <p class="panel-subtitle">Jump directly to the most-used academic workflows.</p>
                </div>
            </div>
            <div class="quick-actions-list">
                <a class="quick-action" href="<?= url('admin/subjects.php') ?>"><i class="fa-solid fa-plus"></i> Add Subject</a>
                <a class="quick-action" href="<?= url('admin/exams.php') ?>"><i class="fa-solid fa-calendar-days"></i> Create Exam</a>
                <a class="quick-action" href="<?= url('admin/marks_entry.php') ?>"><i class="fa-solid fa-pen"></i> Enter Marks</a>
                <a class="quick-action" href="<?= url('admin/results.php') ?>"><i class="fa-solid fa-chart-simple"></i> Generate Results</a>
                <a class="quick-action" href="<?= url('admin/report_cards.php') ?>"><i class="fa-solid fa-file-invoice"></i> Generate Report Cards</a>
                <a class="quick-action" href="<?= url('admin/class_rankings.php') ?>"><i class="fa-solid fa-award"></i> Class Rankings</a>
                <a class="quick-action" href="<?= url('admin/subject_rankings.php') ?>"><i class="fa-solid fa-book"></i> Subject Rankings</a>
            </div>
        </section>
    </div>
</div>

<div class="row g-4 mb-5">
    <div class="col-xl-7">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Exams This Term</h2>
                    <p class="panel-subtitle">Exam status by type for the active academic term.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 academic-list-table">
                    <thead class="table-light">
                        <tr>
                            <th>Exam Name</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($examTypes as $examType): ?>
                            <tr>
                                <td><?= h($examLabels[$examType]) ?></td>
                                <td><?= h($examType) ?></td>
                                <td>
                                    <?php if ($examCounts[$examType] > 0): ?>
                                        <span class="status-pill status-success">In Progress</span>
                                    <?php else: ?>
                                        <span class="status-pill status-warning">Not Started</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/marks_entry.php?exam_type=' . urlencode($examType)) ?>">Enter Marks</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
    <div class="col-xl-5">
        <section class="panel mb-4">
            <div class="panel-heading">
                <div>
                    <h2>Top Performing Students</h2>
                    <p class="panel-subtitle">Best averages across all classes this term.</p>
                </div>
            </div>
            <div class="top-performers-list">
                <?php if ($topStudents): ?>
                    <?php foreach ($topStudents as $index => $student): ?>
                        <div class="top-performer-item">
                            <span class="rank-badge"><?= $index + 1 ?></span>
                            <div>
                                <strong><?= h($student['full_name']) ?></strong>
                                <p class="text-muted mb-0"><?= h($student['class_level']) ?> • Average <?= h(number_format($student['average'], 2)) ?>%</p>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No top performers available yet.</div>
                <?php endif; ?>
            </div>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Recent Academic Activity</h2>
                    <p class="panel-subtitle">Latest updates and report card generation.</p>
                </div>
            </div>
            <div class="activity-list">
                <?php if ($recentActions): ?>
                    <?php foreach ($recentActions as $action): ?>
                        <article class="activity-item">
                            <span class="activity-time"><?= h(date('d M Y', strtotime($action['date']))) ?></span>
                            <p class="mb-1"><strong><?= h($action['activity']) ?></strong></p>
                            <p class="text-muted mb-0"><?= h($action['details']) ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="empty-state">No recent academic activity found.</div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const classLabels = <?= json_encode(array_column($classSummary, 'class_level')) ?>;
        const classValues = <?= json_encode(array_map(fn($row) => (float)$row['average'], $classSummary)) ?>;
        const subjectLabels = <?= json_encode(array_column($subjectsRankings, 'subject_name')) ?>;
        const subjectValues = <?= json_encode(array_map(fn($row) => round((float)$row['average_score'], 2), $subjectsRankings)) ?>;
        const gradeLabels = <?= json_encode(array_keys($gradeDistribution)) ?>;
        const gradeValues = <?= json_encode(array_values($gradeDistribution)) ?>;

        const classCtx = document.getElementById('classPerformanceChart');
        if (classCtx) {
            new Chart(classCtx, {
                type: 'bar',
                data: {
                    labels: classLabels,
                    datasets: [{
                        label: 'Class Average',
                        data: classValues,
                        backgroundColor: '#229ed1',
                        borderRadius: 10,
                    }],
                },
                options: {
                    responsive: true,
                    scales: { y: { beginAtZero: true, max: 100 } },
                },
            });
        }

        const subjectCtx = document.getElementById('subjectPerformanceChart');
        if (subjectCtx) {
            new Chart(subjectCtx, {
                type: 'line',
                data: {
                    labels: subjectLabels,
                    datasets: [{
                        label: 'Subject Average',
                        data: subjectValues,
                        borderColor: '#14b8a6',
                        backgroundColor: 'rgba(20,184,166,0.18)',
                        fill: true,
                        tension: 0.32,
                    }],
                },
                options: {
                    responsive: true,
                    scales: { y: { beginAtZero: true, max: 100 } },
                },
            });
        }

        const gradeCtx = document.getElementById('gradeDistributionChart');
        if (gradeCtx) {
            new Chart(gradeCtx, {
                type: 'doughnut',
                data: {
                    labels: gradeLabels,
                    datasets: [{
                        data: gradeValues,
                        backgroundColor: ['#22c55e', '#0ea5e9', '#f59e0b', '#f97316', '#ef4444'],
                    }],
                },
                options: { responsive: true, cutout: '70%' },
            });
        }
    });
</script>
