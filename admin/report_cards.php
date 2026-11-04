<?php
$pageTitle = 'Report Cards';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';
require_once __DIR__ . '/../includes/simple_pdf.php';

if (!current_admin_has_permission($pdo, 'academic.generate_report_cards')) {
    flash('error', 'You do not have permission to access Report Cards.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
ensure_playgroup_exists($pdo);
$classLevels = class_level_options();
$currentContext = current_academic_context($pdo);
$filter = [
    'academic_year' => preg_match('/^\d{4}$/', $_GET['academic_year'] ?? ($_POST['academic_year'] ?? '')) ? ($_GET['academic_year'] ?? $_POST['academic_year']) : $currentContext['academic_year'],
    'term' => in_array($_GET['term'] ?? ($_POST['term'] ?? ''), term_options(), true) ? ($_GET['term'] ?? $_POST['term']) : $currentContext['term'],
    'class_level' => in_array($_GET['class_level'] ?? ($_POST['class_level'] ?? ''), $classLevels, true) ? ($_GET['class_level'] ?? $_POST['class_level']) : '',
    'student_id' => is_numeric($_GET['student_id'] ?? ($_POST['student_id'] ?? '')) ? (int) ($_GET['student_id'] ?? $_POST['student_id']) : 0,
    'exam_type' => in_array($_GET['exam_type'] ?? ($_POST['exam_type'] ?? ''), ['all', 'Opening', 'Midterm', 'Closing'], true) ? ($_GET['exam_type'] ?? $_POST['exam_type']) : 'all',
];

if (($_GET['action'] ?? '') === 'pdf' && $filter['student_id'] > 0) {
    $student = academic_get_student($pdo, $filter['student_id']);
    if (!$student) {
        http_response_code(404);
        echo 'Student not found.';
        exit;
    }
    if (!get_academic_report_card($pdo, $filter['student_id'], $filter['academic_year'], $filter['term'])) {
        save_academic_report_card($pdo, $filter['student_id'], $filter['academic_year'], $filter['term'], null, null);
    }
    $pdf = academic_report_card_pdf($pdo, $filter['student_id'], $filter['academic_year'], $filter['term'], $filter['exam_type'] === 'all' ? null : $filter['exam_type']);
    $fileName = preg_replace('/[^A-Za-z0-9_-]+/', '_', $student['registration_no'] . '_' . $filter['academic_year'] . '_' . $filter['term'] . ($filter['exam_type'] !== 'all' ? '_' . $filter['exam_type'] : '')) . '_report_card.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_report_card') {
    $teacherComment = trim($_POST['teacher_comment'] ?? '');
    $headTeacherComment = trim($_POST['head_teacher_comment'] ?? '');

    try {
        save_academic_report_card($pdo, $filter['student_id'], $filter['academic_year'], $filter['term'], $teacherComment, $headTeacherComment);
        flash('success', 'Report card remarks saved.');
    } catch (Throwable $exception) {
        flash('error', 'Failed to save report card: ' . $exception->getMessage());
    }
    redirect('admin/report_cards.php?' . http_build_query($filter));
}

$students = $filter['class_level'] !== '' ? fetch_students_by_class($pdo, $filter['class_level']) : [];
$selectedStudent = $filter['student_id'] > 0 ? academic_get_student($pdo, $filter['student_id']) : null;
if ($selectedStudent && $filter['class_level'] === '') {
    $filter['class_level'] = $selectedStudent['class_level'];
    $students = fetch_students_by_class($pdo, $filter['class_level']);
}

// Load exam filter options early for form display
$examFilterOptions = academic_exam_filter_options($pdo, $filter['academic_year'], $filter['term']);

$marksSummary = [];
$studentResult = null;
$reportCard = null;
$gradeExpectationLabel = '';
$gradeExpectationRanges = [];
if ($selectedStudent) {
    $marksSummary = academic_student_marks_summary_for_exam($pdo, (int) $selectedStudent['id'], $filter['academic_year'], $filter['term'], $filter['exam_type'] === 'all' ? null : $filter['exam_type']);
    $studentResult = academic_student_result_for_exam($pdo, (int) $selectedStudent['id'], $filter['academic_year'], $filter['term'], $filter['exam_type'] === 'all' ? null : $filter['exam_type']);
    $reportCard = get_academic_report_card($pdo, (int) $selectedStudent['id'], $filter['academic_year'], $filter['term']);
    $gradeExpectationLabel = academic_grade_expectation_label($studentResult['grade'] ?? '');
    $gradeExpectationRanges = academic_grade_expectation_ranges();
    $gradeDescriptors = academic_grade_point_descriptors();
    $placeholderRows = max(0, 8 - count($gradeDescriptors));
    for ($i = 0; $i < $placeholderRows; $i++) {
        $gradeDescriptors[] = ['grade' => '', 'points' => '', 'range' => '', 'remark' => ''];
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Report Cards</h1>
        <p class="mb-0 text-muted">Generate professional PDF report cards from calculated academic results.</p>
    </div>
    <?php if ($selectedStudent): ?>
        <a class="btn btn-primary" target="_blank" href="<?= url('admin/report_cards.php?' . http_build_query(array_merge($filter, ['action' => 'pdf']))) ?>">
            <i class="fa-solid fa-file-pdf me-2"></i>Open PDF
        </a>
    <?php endif; ?>
</div>

<?php render_academic_module_nav(); ?>

<section class="panel mb-4 no-print">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-md-2">
            <label class="form-label">Academic Year</label>
            <input class="form-control" name="academic_year" value="<?= h($filter['academic_year']) ?>" pattern="\d{4}" required>
        </div>
        <div class="col-md-2">
            <label class="form-label">Term</label>
            <select class="form-select" name="term" required>
                <?php foreach (term_options() as $termOption): ?>
                    <option value="<?= h($termOption) ?>" <?= $filter['term'] === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Exam</label>
            <select class="form-select" name="exam_type">
                <?php
                if ($examFilterOptions) {
                    foreach ($examFilterOptions as $value => $label): ?>
                        <option value="<?= h($value) ?>" <?= $filter['exam_type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach;
                } else {
                    echo '<option value="all" selected>All Exams</option>';
                }
                ?>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Class</label>
            <select class="form-select" name="class_level" required onchange="this.form.submit()">
                <option value="">Select class</option>
                <?php foreach ($classLevels as $level): ?>
                    <option value="<?= h($level) ?>" <?= $filter['class_level'] === $level ? 'selected' : '' ?>><?= h($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label">Student</label>
            <select class="form-select" name="student_id" required>
                <option value="">Select student</option>
                <?php foreach ($students as $student): ?>
                    <option value="<?= (int) $student['id'] ?>" <?= $filter['student_id'] === (int) $student['id'] ? 'selected' : '' ?>>
                        <?= h($student['full_name']) ?> (<?= h($student['registration_no']) ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-1">
            <button class="btn btn-primary w-100" type="submit">Load</button>
        </div>
    </form>
</section>

<?php if ($selectedStudent): ?>
    <section class="academic-report-card panel mb-4">
        <div class="report-card-head">
            <img src="<?= asset('images/school-logo.jpg') ?>" alt="<?= h(SCHOOL_NAME) ?> logo">
            <div>
                <h2><?= h(SCHOOL_NAME) ?></h2>
                <p><?= h(SCHOOL_ADDRESS) ?> | <?= h(SCHOOL_PHONE) ?></p>
                <strong>Academic Report Card</strong>
            </div>
        </div>
        <div class="report-card-meta">
            <span><strong>Student:</strong> <?= h($selectedStudent['full_name']) ?></span>
            <span><strong>Admission No:</strong> <?= h($selectedStudent['registration_no']) ?></span>
            <span><strong>Class:</strong> <?= h($selectedStudent['class_level']) ?></span>
            <span><strong>Academic Year:</strong> <?= h($filter['academic_year']) ?></span>
            <span><strong>Term:</strong> <?= h($filter['term']) ?></span>
            <?php if ($filter['exam_type'] !== 'all' && $examFilterOptions): ?>
                <span><strong>Exam:</strong> <?= h($examFilterOptions[$filter['exam_type']] ?? $filter['exam_type']) ?></span>
            <?php endif; ?>
            <span><strong>Performance Level:</strong> <?= h($gradeExpectationLabel) ?></span>
            <span><strong>Total Marks:</strong> <?= h(number_format((float) ($studentResult['student_total'] ?? 0), 2)) ?></span>
            <span><strong>Average (%):</strong> <?= h(number_format((float) ($studentResult['average'] ?? 0), 2)) ?></span>
            <span><strong>Position:</strong> <?= h((string) ($studentResult['class_position'] ?? 'N/A')) ?></span>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0">
                <thead>
                    <tr>
                        <th>Subject</th>
                        <th>Teacher</th>
                        <?php if ($filter['exam_type'] === 'all'): ?>
                            <th class="text-center">Opening Exam</th>
                            <th class="text-center">Midterm</th>
                            <th class="text-center">Closing Exam</th>
                        <?php elseif ($filter['exam_type'] === 'Opening'): ?>
                            <th class="text-center">Opening Exam</th>
                        <?php elseif ($filter['exam_type'] === 'Midterm'): ?>
                            <th class="text-center">Midterm Exam</th>
                        <?php elseif ($filter['exam_type'] === 'Closing'): ?>
                            <th class="text-center">Closing Exam</th>
                        <?php endif; ?>
                        <th class="text-end">Subject Total</th>
                        <th class="text-center">Average (%)</th>
                        <th class="text-center">Grade</th>
                        <th class="text-center">Subject Position</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($marksSummary as $row): ?>
                        <tr>
                            <td><?= h($row['subject_name']) ?></td>
                            <td><?= h($row['teacher_name'] ?? '-') ?></td>
                            <?php if ($filter['exam_type'] === 'all'): ?>
                                <td class="text-center"><?= $row['opening'] !== null ? h(number_format((float) $row['opening'], 2)) : '-' ?></td>
                                <td class="text-center"><?= $row['midterm'] !== null ? h(number_format((float) $row['midterm'], 2)) : '-' ?></td>
                                <td class="text-center"><?= $row['closing'] !== null ? h(number_format((float) $row['closing'], 2)) : '-' ?></td>
                            <?php elseif ($filter['exam_type'] === 'Opening'): ?>
                                <td class="text-center"><?= $row['opening'] !== null ? h(number_format((float) $row['opening'], 2)) : '-' ?></td>
                            <?php elseif ($filter['exam_type'] === 'Midterm'): ?>
                                <td class="text-center"><?= $row['midterm'] !== null ? h(number_format((float) $row['midterm'], 2)) : '-' ?></td>
                            <?php elseif ($filter['exam_type'] === 'Closing'): ?>
                                <td class="text-center"><?= $row['closing'] !== null ? h(number_format((float) $row['closing'], 2)) : '-' ?></td>
                            <?php endif; ?>
                            <td class="text-end"><?= h(number_format((float) $row['subject_total'], 2)) ?></td>
                            <td class="text-center"><?= h(number_format((float) $row['average'], 2)) ?></td>
                            <td class="text-center"><?= h(academic_grade_expectation_label($row['grade'])) ?></td>
                            <td class="text-center"><?= h((string) ($row['subject_position'] ?? '-')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$marksSummary): ?>
                        <tr><td colspan="9" class="text-center text-muted py-4">No subject results available.</td></tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="2">Overall</th>
                        <?php if ($filter['exam_type'] !== 'all'): ?>
                            <th></th>
                        <?php else: ?>
                            <th></th>
                            <th></th>
                            <th></th>
                        <?php endif; ?>
                        <th class="text-end"><?= h(number_format((float) ($studentResult['student_total'] ?? 0), 2)) ?></th>
                        <th class="text-center"><?= h(number_format((float) ($studentResult['average'] ?? 0), 2)) ?></th>
                        <th class="text-center"><?= h($gradeExpectationLabel ?: ($studentResult['grade'] ?? 'N/A')) ?></th>
                        <th class="text-center"><?= h((string) ($studentResult['class_position'] ?? 'N/A')) ?></th>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="report-remarks">
            <div>
                <strong>Teacher Remarks</strong>
                <p><?= h($reportCard['teacher_comment'] ?? '-') ?></p>
            </div>
            <div>
                <strong>Head Teacher Remarks</strong>
                <p><?= h($reportCard['head_teacher_comment'] ?? '-') ?></p>
            </div>
        </div>

        <div class="grade-descriptor-card">
            <h3>Performance Descriptors</h3>
            <div class="table-responsive">
                <table class="table grade-descriptor-table mb-0">
                    <thead>
                        <tr>
                            <th>Performance Level</th>
                            <th class="text-center">Points</th>
                            <th class="text-center">Score Range (%)</th>
                            <th>Remark</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($gradeDescriptors as $descriptor): ?>
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
    </section>

    <section class="panel no-print">
        <div class="panel-heading">
            <div>
                <h2>Update Remarks</h2>
                <p class="panel-subtitle">Remarks are saved with the report card and included in the PDF.</p>
            </div>
        </div>
        <form method="post" class="row g-3">
            <input type="hidden" name="action" value="save_report_card">
            <input type="hidden" name="student_id" value="<?= (int) $selectedStudent['id'] ?>">
            <input type="hidden" name="academic_year" value="<?= h($filter['academic_year']) ?>">
            <input type="hidden" name="term" value="<?= h($filter['term']) ?>">
            <input type="hidden" name="exam_type" value="<?= h($filter['exam_type']) ?>">
            <input type="hidden" name="class_level" value="<?= h($filter['class_level']) ?>">
            <div class="col-md-6">
                <label class="form-label">Teacher Remarks</label>
                <textarea class="form-control" name="teacher_comment" rows="4"><?= h($reportCard['teacher_comment'] ?? '') ?></textarea>
            </div>
            <div class="col-md-6">
                <label class="form-label">Head Teacher Remarks</label>
                <textarea class="form-control" name="head_teacher_comment" rows="4"><?= h($reportCard['head_teacher_comment'] ?? '') ?></textarea>
            </div>
            <div class="col-12 action-row justify-content-start">
                <button class="btn btn-primary" type="submit">Save Remarks</button>
                <button class="btn btn-outline-secondary" type="button" onclick="window.print()">Print Preview</button>
            </div>
        </form>
    </section>
<?php else: ?>
    <div class="alert alert-info">Choose a class and student to generate a report card.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
