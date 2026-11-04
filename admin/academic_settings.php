<?php
$pageTitle = 'Academic Settings';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.change_term')) {
    flash('error', 'You do not have permission to manage Academic Settings.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);
ensure_playgroup_exists($pdo);
migrate_to_percentage_based_grading($pdo);
$currentContext = active_academic_context($pdo);
$terms = term_options();
$errors = [];
$editingGradeId = (int) ($_GET['edit_grade'] ?? 0);
$editingGrade = null;

if ($editingGradeId > 0) {
    foreach (academic_grading_rows($pdo) as $row) {
        if ((int) $row['id'] === $editingGradeId) {
            $editingGrade = $row;
            break;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'set_active') {
            $year = trim($_POST['active_academic_year'] ?? '');
            $term = trim($_POST['active_term'] ?? '');
            if (!preg_match('/^\d{4}$/', $year)) {
                throw new InvalidArgumentException('Choose a valid academic year.');
            }
            if ((int) $year < (int) $currentContext['academic_year']) {
                set_academic_view_context($pdo, $year, $term);
                flash('success', "Viewing {$year} {$term}; the active school year remains {$currentContext['academic_year']}.");
                redirect('admin/academic_settings.php');
            }
            if ((int) $year > (int) $currentContext['academic_year']) {
                if ((int) $year !== (int) $currentContext['academic_year'] + 1) {
                    throw new InvalidArgumentException('Academic years can only be activated one year at a time.');
                }
                student_complete_year_switch($pdo, $currentContext['academic_year'], $year, (int) ($_SESSION['admin_id'] ?? 0));
                clear_academic_view_context();
                flash('success', "Promotion was already complete; {$year} Term 1 is now active. No students were promoted again.");
                redirect('admin/academic_settings.php');
            }
            set_current_academic_context($pdo, $year, $term);
            ensure_academic_exams_for_period($pdo, $year, $term);
            clear_academic_view_context();
            flash('success', "Active academic period set to {$year} {$term}.");
            redirect('admin/academic_settings.php');
        }

        if ($action === 'generate_year') {
            $year = trim($_POST['new_academic_year'] ?? '');
            if (!preg_match('/^\d{4}$/', $year)) {
                throw new InvalidArgumentException('Enter a valid four-digit academic year.');
            }
            ensure_academic_calendar_for_year($pdo, $year);
            foreach ($terms as $termOption) {
                ensure_academic_exams_for_period($pdo, $year, $termOption);
            }
            flash('success', "Academic year {$year} created with all three terms and exams.");
            redirect('admin/academic_settings.php');
        }

        if ($action === 'save_grade') {
            save_academic_grade(
                $pdo,
                trim($_POST['grade'] ?? ''),
                (float) ($_POST['min_score'] ?? 0),
                (float) ($_POST['max_score'] ?? 0),
                trim($_POST['remark'] ?? ''),
                (int) ($_POST['grade_id'] ?? 0)
            );
            academic_recalculate_term_results($pdo, $currentContext['academic_year'], $currentContext['term']);
            flash('success', 'Performance level saved successfully.');
            redirect('admin/academic_settings.php');
        }

        if ($action === 'delete_grade') {
            delete_academic_grade($pdo, (int) ($_POST['grade_id'] ?? 0));
            academic_recalculate_term_results($pdo, $currentContext['academic_year'], $currentContext['term']);
            flash('success', 'Performance level removed successfully.');
            redirect('admin/academic_settings.php');
        }
    } catch (Throwable $exception) {
        $errors[] = $exception->getMessage();
    }
}

$grades = academic_grading_rows($pdo);
$gradeDisplayRows = $grades;
$placeholderRows = max(0, 8 - count($gradeDisplayRows));
for ($i = 0; $i < $placeholderRows; $i++) {
    $gradeDisplayRows[] = [
        'id' => 0,
        'min_score' => '',
        'max_score' => '',
        'grade' => '',
        'remark' => '',
    ];
}
$years = $pdo->query("SELECT DISTINCT academic_year FROM academic_calendar ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_ASSOC);
$yearOptions = array_column($years, 'academic_year');
if (!in_array($currentContext['academic_year'], $yearOptions, true)) {
    $yearOptions[] = $currentContext['academic_year'];
    rsort($yearOptions);
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Academic Settings</h1>
        <p class="mb-0 text-muted">Configure performance levels and the active academic period.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/academic_calendar.php') ?>"><i class="fa-solid fa-calendar-days me-2"></i>Academic Calendar</a>
</div>

<?php render_academic_module_nav(); ?>

<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-xl-4">
        <section class="panel mb-4">
            <div class="panel-heading">
                <div>
                    <h2>Active Academic Period</h2>
                    <p class="panel-subtitle">Used as the default across the academic module.</p>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <div class="mini-stat">
                        <span>Year</span>
                        <strong><?= h($currentContext['academic_year']) ?></strong>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="mini-stat">
                        <span>Term</span>
                        <strong><?= h($currentContext['term']) ?></strong>
                    </div>
                </div>
            </div>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="set_active">
                <div class="col-sm-6">
                    <label class="form-label">Year</label>
                    <input type="hidden" name="active_academic_year" value="<?= h($currentContext['academic_year']) ?>">
                    <input class="form-control" value="<?= h($currentContext['academic_year']) ?>" readonly aria-label="Active academic year">
                </div>
                <div class="col-sm-6">
                    <label class="form-label">Term</label>
                    <select class="form-select" name="active_term" required>
                        <?php foreach ($terms as $term): ?>
                            <option value="<?= h($term) ?>" <?= $currentContext['term'] === $term ? 'selected' : '' ?>><?= h($term) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary w-100" type="submit">Set Active Period</button>
                </div>
            </form>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Create Academic Year</h2>
                    <p class="panel-subtitle">Creates Term 1, 2, 3 and their exams.</p>
                </div>
            </div>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="generate_year">
                <div class="col-12">
                    <label class="form-label">Academic Year</label>
                    <input class="form-control" name="new_academic_year" value="<?= h((string) ((int) $currentContext['academic_year'] + 1)) ?>" pattern="\d{4}" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-outline-primary w-100" type="submit">Create Year</button>
                </div>
            </form>
        </section>
    </div>
    <div class="col-xl-8">
        <section class="panel mb-4">
            <div class="panel-heading">
                <div>
                    <h2><?= $editingGrade ? 'Edit Performance Level' : 'Add Performance Level Range' ?></h2>
                    <p class="panel-subtitle">Example: 80 - 100 = A.</p>
                </div>
            </div>
            <form method="post" class="row g-3 align-items-end" id="performance-level-form">
                <input type="hidden" name="action" value="save_grade">
                <input type="hidden" name="grade_id" value="<?= (int) ($editingGrade['id'] ?? 0) ?>">
                <div class="col-md-2">
                    <label class="form-label">Performance Level</label>
                    <input class="form-control" name="grade" maxlength="5" value="<?= h($editingGrade['grade'] ?? '') ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Minimum</label>
                    <input class="form-control" type="number" min="0" max="100" step="0.01" name="min_score" value="<?= h(isset($editingGrade['min_score']) ? number_format((float) $editingGrade['min_score'], 2, '.', '') : '') ?>" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Maximum</label>
                    <input class="form-control" type="number" min="0" max="100" step="0.01" name="max_score" value="<?= h(isset($editingGrade['max_score']) ? number_format((float) $editingGrade['max_score'], 2, '.', '') : '') ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Remark</label>
                    <input class="form-control" name="remark" value="<?= h($editingGrade['remark'] ?? '') ?>">
                </div>
                <div class="col-md-2">
                    <button class="btn btn-primary w-100" type="submit">Save</button>
                </div>
                <?php if ($editingGrade): ?>
                    <div class="col-12">
                        <a class="btn btn-outline-secondary" href="<?= url('admin/academic_settings.php') ?>">Cancel Editing</a>
                    </div>
                <?php endif; ?>
            </form>
        </section>

        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Performance Levels</h2>
                    <p class="panel-subtitle">The performance level is recalculated automatically from student averages.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Range</th><th>Performance Level</th><th>Points</th><th>Remark</th><th class="text-end">Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($gradeDisplayRows as $grade): ?>
                            <tr>
                                <td>
                                    <?= $grade['min_score'] !== '' || $grade['max_score'] !== '' ? h(number_format((float) $grade['min_score'], 0)) . ' - ' . h(number_format((float) $grade['max_score'], 0)) : '&nbsp;' ?>
                                </td>
                                <td><span class="status-pill status-info"><?= $grade['grade'] !== '' ? h($grade['grade']) : '&nbsp;' ?></span></td>
                                <td class="text-center"><?= $grade['grade'] !== '' ? h((string) (int) $grade['grade']) : '&nbsp;' ?></td>
                                <td><?= h($grade['remark'] ?: '-') ?></td>
                                <td class="text-end">
                                    <div class="action-row">
                                        <?php if ($grade['id'] > 0): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/academic_settings.php?edit_grade=' . (int) $grade['id']) ?>">Edit</a>
                                            <form method="post" class="m-0" onsubmit="return confirm('Delete performance level <?= h($grade['grade']) ?>?');">
                                                <input type="hidden" name="action" value="delete_grade">
                                                <input type="hidden" name="grade_id" value="<?= (int) $grade['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-primary" type="button" onclick="preparePerformanceLevelForm()">Edit</button>
                                            <button class="btn btn-sm btn-outline-danger" type="button" disabled>Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<script>
function preparePerformanceLevelForm() {
    var form = document.getElementById('performance-level-form');
    if (!form) {
        return;
    }
    form.grade_id.value = 0;
    form.grade.value = '';
    form.min_score.value = '';
    form.max_score.value = '';
    form.remark.value = '';
    var gradeField = form.querySelector('[name="grade"]');
    if (gradeField) {
        gradeField.focus();
    }
}
</script>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
