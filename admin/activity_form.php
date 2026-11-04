<?php
$pageTitle = 'Create Activity';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/activities_helpers.php';
activities_ensure_schema($pdo);

$context = current_academic_context($pdo);
$terms = ['Term 1', 'Term 2', 'Term 3'];
$csrf = csrf_token();
$activityId = filter_var($_GET['edit'] ?? '', FILTER_VALIDATE_INT) ?: 0;
$editActivity = null;
$editParticipants = [];
$old = is_array($_SESSION['activity_form_old'] ?? null) ? $_SESSION['activity_form_old'] : [];
unset($_SESSION['activity_form_old']);

if ($activityId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM activities WHERE id = :id AND status = 'Active' LIMIT 1");
    $stmt->execute(['id' => $activityId]);
    $editActivity = $stmt->fetch() ?: null;
    if (!$editActivity) {
        flash('error', 'This activity could not be found or has already been cancelled.');
        redirect('admin/activities.php');
    }
    $stmt = $pdo->prepare("SELECT student_id FROM activity_participants WHERE activity_id = :id AND participation_status = 'Active'");
    $stmt->execute(['id' => $activityId]);
    $editParticipants = array_map('intval', array_column($stmt->fetchAll(), 'student_id'));
}

$yearRows = $pdo->query('SELECT academic_year FROM academic_calendar UNION SELECT academic_year FROM activities ORDER BY academic_year DESC')->fetchAll();
$years = array_values(array_unique(array_merge([(string) $context['academic_year']], array_column($yearRows, 'academic_year'))));
$requestedYear = (string) ($_GET['students_year'] ?? $old['academic_year'] ?? '');
$formYear = preg_match('/^\d{4}$/', $requestedYear) && in_array($requestedYear, array_map('strval', $years), true)
    ? $requestedYear
    : (string) ($editActivity['academic_year'] ?? $context['academic_year']);
$classExpr = student_class_sql('s', ':selected_year');
$studentStmt = $pdo->prepare("SELECT s.id, s.full_name, s.registration_no, {$classExpr} AS class_level FROM students s WHERE s.enrollment_status = 'Active' ORDER BY class_level, s.full_name");
$studentStmt->execute(['selected_year' => $formYear]);
$students = $studentStmt->fetchAll();
$selectedParticipants = isset($old['participant_ids']) ? array_map('intval', (array) $old['participant_ids']) : $editParticipants;
$value = static function (string $key, mixed $fallback = '') use ($old, $editActivity): mixed {
    return array_key_exists($key, $old) ? $old[$key] : ($editActivity[$key] ?? $fallback);
};
$pageTitle = $editActivity ? 'Edit Activity' : 'Create Activity';
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="activities-dashboard activity-form-page">
    <div class="page-title">
        <div><p class="eyebrow">Activities &amp; Trips</p><h1><?= $editActivity ? 'Edit Activity' : 'Create Activity' ?></h1><p class="text-muted mb-0">Set the activity details, choose participating students, and enter the amount due.</p></div>
        <a class="btn btn-outline-secondary" href="<?= url('admin/activities.php') ?>"><i class="fa-solid fa-arrow-left me-2"></i>Back to Activities</a>
    </div>
    <?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
    <?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>

    <section class="panel activity-panel">
        <div class="panel-heading"><div><h2>Activity Details</h2><p class="panel-subtitle">The expected collection is based on the selected students and per-student amount.</p></div></div>
        <form method="post" action="<?= url('admin/activities.php') ?>" class="row g-3" id="activity-setup-form">
            <input type="hidden" name="_csrf_token" value="<?= h($csrf) ?>">
            <input type="hidden" name="action" value="save_activity">
            <input type="hidden" name="activity_id" value="<?= (int) ($editActivity['id'] ?? 0) ?>">
            <input type="hidden" name="return_edit" value="<?= (int) ($editActivity['id'] ?? 0) ?>">
            <input type="hidden" name="return_form" value="1">

            <div class="col-lg-8"><label class="form-label" for="activity-name">Activity name</label><input class="form-control" id="activity-name" name="name" required maxlength="150" value="<?= h((string) $value('name')) ?>" placeholder="e.g. Swimming lessons"></div>
            <div class="col-md-6 col-lg-4"><label class="form-label" for="activity-date">Activity date</label><input class="form-control" type="date" id="activity-date" name="activity_date" required value="<?= h((string) $value('activity_date', date('Y-m-d'))) ?>"></div>
            <div class="col-md-6 col-lg-3"><label class="form-label" for="activity-year">Academic year</label><select class="form-select" id="activity-year" name="academic_year" required><?php foreach ($years as $year): ?><option value="<?= h((string) $year) ?>" <?= (string) ($old['academic_year'] ?? ($requestedYear !== '' && in_array($requestedYear, array_map('strval', $years), true) ? $requestedYear : ($editActivity['academic_year'] ?? $formYear))) === (string) $year ? 'selected' : '' ?>><?= h((string) $year) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6 col-lg-3"><label class="form-label" for="activity-term">Term</label><select class="form-select" id="activity-term" name="term" required><?php foreach ($terms as $term): ?><option value="<?= h($term) ?>" <?= (string) $value('term', $context['term']) === $term ? 'selected' : '' ?>><?= h($term) ?></option><?php endforeach; ?></select></div>
            <div class="col-md-6 col-lg-3"><label class="form-label" for="cost-per-student">Amount per student (KES)</label><input class="form-control" type="number" min="0" step="0.01" id="cost-per-student" name="cost_per_student" required value="<?= h((string) $value('cost_per_student', '0.00')) ?>"></div>

            <div class="col-lg-8"><label class="form-label" for="activity-participants-toggle">Participating students</label>
                <div class="dropdown activity-student-dropdown">
                    <button class="form-select d-flex justify-content-between align-items-center text-start" type="button" id="activity-participants-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" aria-controls="activity-participants-menu">
                        <span id="student-picker-label">Choose students</span>
                        <i class="fa-solid fa-chevron-down" aria-hidden="true"></i>
                    </button>
                    <div class="dropdown-menu activity-student-menu" id="activity-participants-menu" aria-labelledby="activity-participants-toggle">
                        <div class="activity-student-toolbar">
                            <input class="form-control form-control-sm" type="search" id="activity-student-search" placeholder="Search name, admission number, or class" aria-label="Search students">
                            <div class="d-flex justify-content-between align-items-center mt-2">
                                <small class="text-muted" id="student-picker-count">0 selected</small>
                                <div class="d-flex gap-2"><button class="btn btn-sm btn-link p-0" type="button" id="select-visible-students">Select visible</button><button class="btn btn-sm btn-link p-0" type="button" id="clear-selected-students">Clear</button></div>
                            </div>
                        </div>
                        <div class="activity-student-options" role="group" aria-label="Available students">
                            <?php foreach ($students as $student): $isSelected = in_array((int) $student['id'], $selectedParticipants, true); ?>
                                <label class="activity-student-option<?= $isSelected ? ' is-selected' : '' ?>" data-student-search="<?= h(mb_strtolower($student['full_name'] . ' ' . $student['registration_no'] . ' ' . $student['class_level'], 'UTF-8')) ?>">
                                    <input class="form-check-input activity-student-check" type="checkbox" name="participant_ids[]" value="<?= (int) $student['id'] ?>" data-student-name="<?= h($student['full_name']) ?>" <?= $isSelected ? 'checked' : '' ?>>
                                    <span><strong><?= h($student['full_name']) ?></strong><small><?= h($student['registration_no'] . ' · ' . $student['class_level']) ?></small></span>
                                </label>
                            <?php endforeach; ?>
                            <?php if (!$students): ?><p class="text-muted small mb-0 p-3">No active students are available for this academic year.</p><?php endif; ?>
                        </div>
                    </div>
                </div>
                <small class="form-text" id="student-help">Search and choose one or more students. Their classes are determined automatically.</small>
            </div>
            <div class="col-lg-4"><div class="activity-expected-preview"><span><i class="fa-solid fa-users me-2"></i>Students selected</span><strong id="selected-student-count">0</strong><span>Expected collection</span><strong id="expected-collection-preview">KES 0.00</strong></div></div>
            <div class="col-12"><label class="form-label" for="activity-notes">Notes <span class="text-muted">(optional)</span></label><textarea class="form-control" id="activity-notes" name="notes" rows="3" maxlength="5000" placeholder="Add useful activity details"><?= h((string) $value('notes')) ?></textarea></div>
            <div class="col-12 d-flex flex-wrap gap-2"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-floppy-disk me-2"></i><?= $editActivity ? 'Save Activity Changes' : 'Create Activity' ?></button><a class="btn btn-outline-secondary" href="<?= url('admin/activities.php') ?>">Cancel</a></div>
        </form>
    </section>
</div>
<script>
(function () {
    const yearSelect = document.getElementById('activity-year');
    const studentChecks = Array.from(document.querySelectorAll('.activity-student-check'));
    const studentOptions = Array.from(document.querySelectorAll('.activity-student-option'));
    const searchInput = document.getElementById('activity-student-search');
    const pickerLabel = document.getElementById('student-picker-label');
    const pickerCount = document.getElementById('student-picker-count');
    const selectVisibleButton = document.getElementById('select-visible-students');
    const clearButton = document.getElementById('clear-selected-students');
    const costInput = document.getElementById('cost-per-student');
    const countOutput = document.getElementById('selected-student-count');
    const totalOutput = document.getElementById('expected-collection-preview');
    const updatePreview = function () {
        const selectedCount = studentChecks.filter((input) => input.checked).length;
        const cost = Math.max(0, Number(costInput.value || 0));
        countOutput.textContent = String(selectedCount);
        pickerCount.textContent = selectedCount + ' selected';
        pickerLabel.textContent = selectedCount ? selectedCount + ' student' + (selectedCount === 1 ? '' : 's') + ' selected' : 'Choose students';
        totalOutput.textContent = 'KES ' + new Intl.NumberFormat('en-KE', {minimumFractionDigits: 2, maximumFractionDigits: 2}).format(selectedCount * cost);
        studentOptions.forEach((row) => {
            const checkbox = row.querySelector('.activity-student-check');
            row.classList.toggle('is-selected', Boolean(checkbox && checkbox.checked));
        });
    };

    const filterStudents = function () {
        const query = (searchInput.value || '').trim().toLocaleLowerCase();
        studentOptions.forEach((row) => {
            row.hidden = query !== '' && !row.dataset.studentSearch.includes(query);
        });
    };

    studentChecks.forEach((input) => input.addEventListener('change', updatePreview));
    searchInput.addEventListener('input', filterStudents);
    selectVisibleButton.addEventListener('click', function () {
        studentOptions.forEach((row) => {
            if (!row.hidden) row.querySelector('.activity-student-check').checked = true;
        });
        updatePreview();
    });
    clearButton.addEventListener('click', function () {
        studentChecks.forEach((input) => { input.checked = false; });
        updatePreview();
    });
    costInput.addEventListener('input', updatePreview);
    updatePreview();
    if (yearSelect) yearSelect.addEventListener('change', function () {
        const url = new URL(window.location.href);
        url.searchParams.set('students_year', yearSelect.value);
        window.location.href = url.pathname + url.search;
    });
})();
</script>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
