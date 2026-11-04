<?php
$pageTitle = 'Marks Entry';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.record_marks')) {
    flash('error', 'You do not have permission to access Marks Entry.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
ensure_playgroup_exists($pdo);
$currentContext = current_academic_context($pdo);
$classLevels = class_level_options();
$examTypes = academic_exam_types();

$filter = [
    'academic_year' => preg_match('/^\d{4}$/', $_GET['academic_year'] ?? ($_POST['academic_year'] ?? '')) ? ($_GET['academic_year'] ?? $_POST['academic_year']) : $currentContext['academic_year'],
    'term' => in_array($_GET['term'] ?? ($_POST['term'] ?? ''), term_options(), true) ? ($_GET['term'] ?? $_POST['term']) : $currentContext['term'],
    'exam_type' => in_array($_GET['exam_type'] ?? ($_POST['exam_type'] ?? ''), $examTypes, true) ? ($_GET['exam_type'] ?? $_POST['exam_type']) : 'Opening',
    'class_level' => in_array($_GET['class_level'] ?? ($_POST['class_level'] ?? ''), $classLevels, true) ? ($_GET['class_level'] ?? $_POST['class_level']) : '',
    'subject_id' => is_numeric($_GET['subject_id'] ?? ($_POST['subject_id'] ?? '')) ? (int) ($_GET['subject_id'] ?? $_POST['subject_id']) : 0,
];

ensure_academic_exams_for_period($pdo, $filter['academic_year'], $filter['term']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_mark') {
    rbac_require_permission($pdo, 'academic.record_marks', 'You do not have permission to record marks.');

    $studentId = (int) ($_POST['student_id'] ?? 0);
    $subjectId = (int) ($_POST['subject_id'] ?? 0);
    $marksValue = trim($_POST['marks'] ?? '');
    $remarks = trim($_POST['remarks'] ?? '');

    try {
        $marks = $marksValue === '' ? 0.0 : (float) $marksValue;
        save_academic_mark(
            $pdo,
            $studentId,
            $subjectId,
            $filter['academic_year'],
            $filter['term'],
            $filter['exam_type'],
            $marks,
            $remarks ?: null
        );

        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => true, 'message' => 'Saved']);
            exit;
        }

        flash('success', 'Marks saved successfully.');
        redirect('admin/marks_entry.php?' . http_build_query($filter));
    } catch (Throwable $exception) {
        error_log('Academic mark save failed with code ' . (string) $exception->getCode());
        if (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
            header('Content-Type: application/json; charset=UTF-8', true, 400);
            echo json_encode(['success' => false, 'message' => 'Unable to save the mark. Please try again.']);
            exit;
        }

        flash('error', 'Unable to save the mark. Please try again or contact the system administrator.');
        redirect('admin/marks_entry.php?' . http_build_query($filter));
    }
}

$subjects = $filter['class_level'] !== '' ? academic_subjects_for_class($pdo, $filter['class_level']) : get_academic_subjects_simple($pdo);
$subjectName = '';
foreach ($subjects as $subject) {
    if ((int) $subject['id'] === $filter['subject_id']) {
        $subjectName = $subject['name'];
        break;
    }
}

$students = [];
$marksByStudent = [];
if ($filter['class_level'] !== '' && $filter['subject_id'] > 0) {
    $students = fetch_students_by_class($pdo, $filter['class_level']);
    $marks = fetch_academic_marks_for_term($pdo, $filter['academic_year'], $filter['term'], $filter['subject_id'], $filter['class_level'], $filter['exam_type']);
    foreach ($marks as $mark) {
        $marksByStudent[(int) $mark['student_id']] = $mark;
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Marks Entry</h1>
        <p class="mb-0 text-muted">Enter marks by academic year, term, exam, class, and subject.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/results.php?academic_year=' . urlencode($filter['academic_year']) . '&term=' . urlencode($filter['term']) . '&class_level=' . urlencode($filter['class_level'])) ?>">
        <i class="fa-solid fa-square-poll-vertical me-2"></i>View Results
    </a>
</div>

<?php render_academic_module_nav(); ?>

<section class="panel mb-4">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-lg-2 col-md-4">
            <label class="form-label">Academic Year</label>
            <input class="form-control" name="academic_year" value="<?= h($filter['academic_year']) ?>" pattern="\d{4}" required>
        </div>
        <div class="col-lg-2 col-md-4">
            <label class="form-label">Term</label>
            <select class="form-select" name="term" required>
                <?php foreach (term_options() as $termOption): ?>
                    <option value="<?= h($termOption) ?>" <?= $filter['term'] === $termOption ? 'selected' : '' ?>><?= h($termOption) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-4">
            <label class="form-label">Exam</label>
            <select class="form-select" name="exam_type" required>
                <?php foreach (academic_exam_type_labels() as $examType => $label): ?>
                    <option value="<?= h($examType) ?>" <?= $filter['exam_type'] === $examType ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-2 col-md-4">
            <label class="form-label">Class</label>
            <select class="form-select" name="class_level" required onchange="this.form.submit()">
                <option value="">Select class</option>
                <?php foreach ($classLevels as $level): ?>
                    <option value="<?= h($level) ?>" <?= $filter['class_level'] === $level ? 'selected' : '' ?>><?= h($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3 col-md-5">
            <label class="form-label">Subject</label>
            <select class="form-select" name="subject_id" required>
                <option value="">Select subject</option>
                <?php foreach ($subjects as $subject): ?>
                    <option value="<?= (int) $subject['id'] ?>" <?= $filter['subject_id'] === (int) $subject['id'] ? 'selected' : '' ?>><?= h($subject['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-1 col-md-3">
            <button class="btn btn-primary w-100" type="submit">Load</button>
        </div>
    </form>
</section>

<?php if ($filter['class_level'] !== '' && $filter['subject_id'] > 0): ?>
    <section class="panel">
        <div class="panel-heading">
            <div>
                <h2><?= h($filter['class_level']) ?> | <?= h($subjectName) ?> | <?= h(academic_exam_label($filter['exam_type'])) ?></h2>
                <p class="panel-subtitle"><?= h($filter['academic_year']) ?> <?= h($filter['term']) ?>. Students are loaded automatically from the selected class.</p>
            </div>
            <button class="btn btn-outline-secondary" id="refreshMarksBtn" type="button"><i class="fa-solid fa-rotate me-2"></i>Refresh</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead>
                    <tr>
                        <th>Adm No</th>
                        <th>Student</th>
                        <th style="width: 130px;">Marks</th>
                        <th style="width: 220px;">Performance</th>
                        <th style="width: 260px;">Remarks</th>
                        <th style="width: 140px;">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student): ?>
                        <?php $existing = $marksByStudent[(int) $student['id']] ?? null; ?>
                        <tr class="mark-row" data-student-id="<?= (int) $student['id'] ?>">
                            <td><code><?= h($student['registration_no']) ?></code></td>
                            <td><strong><?= h($student['full_name']) ?></strong></td>
                            <td>
                                <input class="form-control form-control-sm mark-input" type="number" min="0" max="100" step="0.01" value="<?= $existing ? h((string) $existing['marks']) : '' ?>">
                            </td>
                            <td class="performance-cell">
                                <?= $existing ? h(academic_grade_expectation_label(academic_grade_for_score((float) $existing['marks'], $pdo))) : 'N/A' ?>
                            </td>
                            <td>
                                <input class="form-control form-control-sm remarks-input" type="text" value="<?= $existing ? h($existing['remarks']) : '' ?>">
                            </td>
                            <td class="status-cell text-muted"><?= $existing ? 'Saved' : 'Not saved' ?></td>
                            <input type="hidden" class="subject-id" value="<?= (int) $filter['subject_id'] ?>">
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$students): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">No students found in this class.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        <div class="action-row mt-3">
            <button class="btn btn-primary" id="saveAllMarksBtn" type="button"><i class="fa-solid fa-floppy-disk me-2"></i>Save All</button>
        </div>
    </section>
<?php elseif ($filter['class_level'] !== ''): ?>
    <div class="alert alert-info">Select a subject assigned to <?= h($filter['class_level']) ?> to load students.</div>
<?php else: ?>
    <div class="alert alert-info">Select an academic period, class, and subject to begin marks entry.</div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const apiUrl = '<?= url('admin/marks_entry.php?' . http_build_query($filter)) ?>';
    const gradeScales = <?= json_encode(array_map(function ($row) {
        return [
            'min_score' => (float) $row['min_score'],
            'grade' => (string) $row['grade'],
        ];
    }, academic_grade_scale_rows($pdo))) ?>;

    function getExpectationLabel(score) {
        const numeric = Number(score);
        if (Number.isNaN(numeric)) {
            return 'N/A';
        }

        const scale = gradeScales.find(function (row) {
            return numeric >= row.min_score;
        });

        if (!scale) {
            return 'Below Expectations';
        }

        const grade = parseInt(scale.grade) || 1;
        
        if (grade >= 7 && grade <= 8) {
            return 'Exceeding Expectations';
        }
        if (grade >= 5 && grade <= 6) {
            return 'Meeting Expectations';
        }
        if (grade >= 3 && grade <= 4) {
            return 'Approaching Expectations';
        }
        return 'Below Expectations';
    }

    function parseJsonResponse(response) {
        return response.text().then(function (text) {
            try {
                return JSON.parse(text || '{}');
            } catch (error) {
                return { success: false, message: text || 'Invalid response from server.' };
            }
        });
    }

    function updateRowPerformance(row) {
        const input = row.querySelector('.mark-input');
        const cell = row.querySelector('.performance-cell');
        const value = input ? input.value.trim() : '';
        cell.textContent = value === '' ? 'N/A' : getExpectationLabel(value);
    }

    function saveMark(row) {
        updateRowPerformance(row);
        const statusCell = row.querySelector('.status-cell');
        const formData = new FormData();
        formData.append('action', 'save_mark');
        formData.append('student_id', row.dataset.studentId);
        formData.append('subject_id', row.querySelector('.subject-id').value);
        formData.append('marks', row.querySelector('.mark-input').value);
        formData.append('remarks', row.querySelector('.remarks-input').value);
        formData.append('academic_year', '<?= h($filter['academic_year']) ?>');
        formData.append('term', '<?= h($filter['term']) ?>');
        formData.append('exam_type', '<?= h($filter['exam_type']) ?>');
        formData.append('class_level', '<?= h($filter['class_level']) ?>');

        statusCell.textContent = 'Saving...';

        return fetch(apiUrl, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
                'X-CSRF-Token': window.APP_CSRF_TOKEN
            }
        })
            .then(function (response) {
                return parseJsonResponse(response).then(function (data) {
                    return { ok: response.ok, data: data };
                });
            })
            .then(function (result) {
                statusCell.textContent = result.ok && result.data.success ? 'Saved' : (result.data.message || 'Failed');
            })
            .catch(function () {
                statusCell.textContent = 'Failed';
            });
    }

    document.querySelectorAll('.mark-row').forEach(function (row) {
        updateRowPerformance(row);
        row.querySelectorAll('.mark-input, .remarks-input').forEach(function (input) {
            input.addEventListener('change', function () { saveMark(row); });
        });
    });

    const saveAllBtn = document.getElementById('saveAllMarksBtn');
    if (saveAllBtn) {
        saveAllBtn.addEventListener('click', function () {
            const rows = Array.from(document.querySelectorAll('.mark-row'));
            saveAllBtn.disabled = true;
            saveAllBtn.textContent = 'Saving...';
            Promise.all(rows.map(saveMark)).finally(function () {
                saveAllBtn.disabled = false;
                saveAllBtn.innerHTML = '<i class="fa-solid fa-floppy-disk me-2"></i>Save All';
            });
        });
    }

    const refreshBtn = document.getElementById('refreshMarksBtn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function () { window.location.reload(); });
    }
});
</script>
