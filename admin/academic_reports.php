<?php
$pageTitle = 'Academic Reports';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.view_analytics')) {
    flash('error', 'You do not have permission to access Academic Reports.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
$currentContext = current_academic_context($pdo);
$classLevels = class_level_options();
$subjects = get_academic_subjects_simple($pdo);
$reportTypes = [
    'student' => 'Student Performance Report',
    'class' => 'Class Performance Report',
    'subject' => 'Subject Performance Report',
    'exam' => 'Exam Analysis Report',
    'year' => 'Academic Year Summary',
];
$filter = [
    'report_type' => array_key_exists($_GET['report_type'] ?? '', $reportTypes) ? $_GET['report_type'] : 'class',
    'academic_year' => preg_match('/^\d{4}$/', $_GET['academic_year'] ?? '') ? $_GET['academic_year'] : $currentContext['academic_year'],
    'term' => in_array($_GET['term'] ?? '', term_options(), true) ? $_GET['term'] : $currentContext['term'],
    'class_level' => in_array($_GET['class_level'] ?? '', $classLevels, true) ? $_GET['class_level'] : '',
    'subject_id' => is_numeric($_GET['subject_id'] ?? '') ? (int) $_GET['subject_id'] : 0,
    'student_id' => is_numeric($_GET['student_id'] ?? '') ? (int) $_GET['student_id'] : 0,
];

$students = $filter['class_level'] !== '' ? fetch_students_by_class($pdo, $filter['class_level']) : [];
$selectedStudent = $filter['student_id'] > 0 ? academic_get_student($pdo, $filter['student_id']) : null;
$selectedSubject = $filter['subject_id'] > 0 ? get_academic_subject_by_id($pdo, $filter['subject_id']) : null;

$classSummary = academic_class_summary_for_term($pdo, $filter['academic_year'], $filter['term']);
$subjectRankings = academic_subject_term_rankings($pdo, $filter['academic_year'], $filter['term']);
$gradeDistribution = academic_grade_distribution($pdo, $filter['academic_year'], $filter['term'], $filter['class_level'] ?: null);
$studentSummary = $selectedStudent ? academic_student_marks_summary($pdo, (int) $selectedStudent['id'], $filter['academic_year'], $filter['term']) : [];
$studentResult = $selectedStudent ? academic_student_result($pdo, (int) $selectedStudent['id'], $filter['academic_year'], $filter['term']) : null;
$subjectBest = academic_best_students_by_subject($pdo, $filter['academic_year'], $filter['term'], $filter['class_level'] ?: null);

$examAnalysisStmt = $pdo->prepare(
    "SELECT
        e.exam_type,
        e.exam_name,
        st.class_level,
        sub.name AS subject_name,
        COUNT(m.id) AS entries,
        AVG(m.marks) AS average_score,
        MAX(m.marks) AS highest_score,
        MIN(m.marks) AS lowest_score
     FROM academic_exams e
     LEFT JOIN academic_marks m ON m.exam_id = e.id
     LEFT JOIN students st ON st.id = m.student_id
     LEFT JOIN academic_subjects sub ON sub.id = m.subject_id
     WHERE e.academic_year = :academic_year
       AND e.term = :term
       AND (:class_level = '' OR st.class_level = :class_level)
       AND (:subject_id = 0 OR sub.id = :subject_id)
     GROUP BY e.exam_type, e.exam_name, st.class_level, sub.name
     ORDER BY FIELD(e.exam_type, 'Opening', 'Midterm', 'Closing'), st.class_level ASC, sub.name ASC"
);
$examAnalysisStmt->execute([
    'academic_year' => $filter['academic_year'],
    'term' => $filter['term'],
    'class_level' => $filter['class_level'],
    'subject_id' => $filter['subject_id'],
]);
$examAnalysis = $examAnalysisStmt->fetchAll(PDO::FETCH_ASSOC);

$yearSummary = [];
foreach (term_options() as $termOption) {
    $yearSummary[] = [
        'term' => $termOption,
        'school_mean' => academic_school_mean_score($pdo, $filter['academic_year'], $termOption),
        'class_summary' => academic_class_summary_for_term($pdo, $filter['academic_year'], $termOption),
        'subjects' => academic_subject_term_rankings($pdo, $filter['academic_year'], $termOption),
    ];
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Academic Reports</h1>
        <p class="mb-0 text-muted">Generate performance, ranking, exam analysis, and academic-year reports.</p>
    </div>
    <button class="btn btn-outline-primary no-print" type="button" onclick="window.print()"><i class="fa-solid fa-print me-2"></i>Print</button>
</div>

<?php render_academic_module_nav(); ?>

<section class="panel mb-4 no-print">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-lg-3 col-md-6">
            <label class="form-label">Report</label>
            <select class="form-select" name="report_type">
                <?php foreach ($reportTypes as $key => $label): ?>
                    <option value="<?= h($key) ?>" <?= $filter['report_type'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label">Year</label>
            <input class="form-control" name="academic_year" value="<?= h($filter['academic_year']) ?>" pattern="\d{4}" required>
        </div>
        <div class="col-lg-2 col-md-3">
            <label class="form-label">Term</label>
            <select class="form-select" name="term">
                <?php foreach (term_options() as $termOption): ?>
                    <option value="<?= h($termOption) ?>" <?= $filter['term'] === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-4">
            <label class="form-label">Class</label>
            <select class="form-select" name="class_level" onchange="this.form.submit()">
                <option value="">All classes</option>
                <?php foreach ($classLevels as $level): ?>
                    <option value="<?= h($level) ?>" <?= $filter['class_level'] === $level ? 'selected' : '' ?>><?= h($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-4">
            <label class="form-label">Subject</label>
            <select class="form-select" name="subject_id">
                <option value="0">All subjects</option>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?= (int) $subject['id'] ?>" <?= $filter['subject_id'] === (int) $subject['id'] ? 'selected' : '' ?>><?= h($subject['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-4">
            <button class="btn btn-primary w-100" type="submit">Run</button>
        </div>
        <?php if ($filter['class_level'] !== ''): ?>
            <div class="col-md-6">
                <label class="form-label">Student</label>
                <select class="form-select" name="student_id">
                    <option value="0">Class overview</option>
                    <?php foreach ($students as $student): ?>
                        <option value="<?= (int) $student['id'] ?>" <?= $filter['student_id'] === (int) $student['id'] ? 'selected' : '' ?>><?= h($student['full_name']) ?> (<?= h($student['registration_no']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </form>
</section>

<section class="panel">
    <div class="panel-heading">
        <div>
            <h2><?= h($reportTypes[$filter['report_type']]) ?></h2>
            <p class="panel-subtitle"><?= h(academic_report_filter_label($filter['academic_year'], $filter['term'], $filter['class_level'] ?: null, $selectedSubject['name'] ?? null)) ?></p>
        </div>
    </div>

    <?php if ($filter['report_type'] === 'student'): ?>
        <?php if ($selectedStudent): ?>
            <div class="dashboard-stat-grid">
                <article class="metric-card"><span><?= h(number_format((float) ($studentResult['average'] ?? 0), 2)) ?></span><p>Average (%)</p></article>
                <article class="metric-card"><span><?= h($studentResult['grade'] ?? 'N/A') ?></span><p>Grade</p></article>
                <article class="metric-card"><span><?= h((string) ($studentResult['class_position'] ?? 'N/A')) ?></span><p>Class Position</p></article>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Subject</th><th class="text-center">Opening</th><th class="text-center">Midterm</th><th class="text-center">Closing</th><th class="text-center">Average (%)</th><th class="text-center">Grade</th></tr></thead>
                    <tbody>
                        <?php foreach ($studentSummary as $row): ?>
                            <tr>
                                <td><?= h($row['subject_name']) ?></td>
                                <td class="text-center"><?= $row['opening'] !== null ? h(number_format((float) $row['opening'], 2)) : '-' ?></td>
                                <td class="text-center"><?= $row['midterm'] !== null ? h(number_format((float) $row['midterm'], 2)) : '-' ?></td>
                                <td class="text-center"><?= $row['closing'] !== null ? h(number_format((float) $row['closing'], 2)) : '-' ?></td>
                                <td class="text-center"><?= h(number_format((float) $row['average'], 2)) ?></td>
                                <td class="text-center"><?= h(academic_grade_expectation_label($row['grade'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info mb-0">Select a class and student to generate a student performance report.</div>
        <?php endif; ?>
    <?php elseif ($filter['report_type'] === 'class'): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Class</th><th class="text-center">Average (%)</th><th class="text-center">Students</th><th>Top Student</th></tr></thead>
                <tbody>
                    <?php foreach ($classSummary as $row): ?>
                        <tr>
                            <td><strong><?= h($row['class_level']) ?></strong></td>
                            <td class="text-center"><?= h(number_format((float) $row['average'], 2)) ?></td>
                            <td class="text-center"><?= h((string) $row['student_count']) ?></td>
                            <td><?= h($row['top_student'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($filter['report_type'] === 'subject'): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Subject</th><th>Best Student</th><th>Class</th><th class="text-center">Average (%)</th><th class="text-center">Students</th></tr></thead>
                <tbody>
                    <?php foreach ($subjectRankings as $subject): ?>
                        <?php
                        $best = null;
                        foreach ($subjectBest as $candidate) {
                            if ((int) $candidate['subject_id'] === (int) $subject['subject_id']) {
                                $best = $candidate;
                                break;
                            }
                        }
                        ?>
                        <tr>
                            <td><strong><?= h($subject['subject_name']) ?></strong></td>
                            <td><?= h($best['full_name'] ?? '-') ?></td>
                            <td><?= h($best['class_level'] ?? '-') ?></td>
                            <td class="text-center"><?= h(number_format((float) $subject['average_score'], 2)) ?></td>
                            <td class="text-center"><?= h((string) $subject['student_count']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php elseif ($filter['report_type'] === 'exam'): ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Exam</th><th>Class</th><th>Subject</th><th class="text-center">Entries</th><th class="text-center">Average</th><th class="text-center">Highest</th><th class="text-center">Lowest</th></tr></thead>
                <tbody>
                    <?php foreach ($examAnalysis as $row): ?>
                        <tr>
                            <td><?= h($row['exam_name']) ?></td>
                            <td><?= h($row['class_level'] ?: '-') ?></td>
                            <td><?= h($row['subject_name'] ?: '-') ?></td>
                            <td class="text-center"><?= h((string) $row['entries']) ?></td>
                            <td class="text-center"><?= h(number_format((float) $row['average_score'], 2)) ?></td>
                            <td class="text-center"><?= h(number_format((float) $row['highest_score'], 2)) ?></td>
                            <td class="text-center"><?= h(number_format((float) $row['lowest_score'], 2)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead><tr><th>Term</th><th class="text-center">School Mean</th><th>Best Class</th><th>Best Subject</th></tr></thead>
                <tbody>
                    <?php foreach ($yearSummary as $row): ?>
                        <tr>
                            <td><strong><?= h($row['term']) ?></strong></td>
                            <td class="text-center"><?= h(number_format((float) $row['school_mean'], 2)) ?></td>
                            <td><?= h($row['class_summary'][0]['class_level'] ?? '-') ?></td>
                            <td><?= h($row['subjects'][0]['subject_name'] ?? '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
