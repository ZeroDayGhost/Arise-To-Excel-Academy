<?php
$pageTitle = 'Subjects';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/academic_helpers.php';

if (!current_admin_has_permission($pdo, 'academic.manage_subjects')) {
    flash('error', 'You do not have permission to manage subjects.');
    redirect('admin/dashboard.php');
}

ensure_academic_module_schema($pdo);

$classLevels = get_academic_class_levels($pdo);
$editingId = (int) ($_GET['edit'] ?? 0);
$editingSubject = $editingId > 0 ? get_academic_subject_by_id($pdo, $editingId) : null;
if ($editingId > 0 && !$editingSubject) {
    flash('error', 'Subject not found.');
    redirect('admin/subjects.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    $subjectId = (int) ($_POST['subject_id'] ?? 0);

    if ($action === 'delete') {
        try {
            delete_academic_subject($pdo, $subjectId);
            flash('success', 'Subject deleted successfully.');
        } catch (Throwable $exception) {
            flash('error', 'Subject could not be deleted because it already has academic records.');
        }
        redirect('admin/subjects.php');
    }

    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $teacherName = trim($_POST['teacher_name'] ?? '');
    $classIds = array_map('intval', $_POST['class_ids'] ?? []);

    if ($name === '') {
        flash('error', 'Subject name is required.');
        redirect($action === 'update' ? 'admin/subjects.php?edit=' . $subjectId : 'admin/subjects.php');
    }
    if (!$classIds) {
        flash('error', 'Assign the subject to at least one class.');
        redirect($action === 'update' ? 'admin/subjects.php?edit=' . $subjectId : 'admin/subjects.php');
    }
    if (academic_subject_exists($pdo, $name, $action === 'update' ? $subjectId : 0)) {
        flash('error', 'A subject with this name already exists.');
        redirect($action === 'update' ? 'admin/subjects.php?edit=' . $subjectId : 'admin/subjects.php');
    }

    foreach ($classIds as $classId) {
        if ($code !== '' && academic_subject_code_exists_in_class($pdo, $code, $classId, $action === 'update' ? $subjectId : 0)) {
            flash('error', 'This subject code is already used in one of the selected classes.');
            redirect($action === 'update' ? 'admin/subjects.php?edit=' . $subjectId : 'admin/subjects.php');
        }
    }

    try {
        save_academic_subject($pdo, $name, $code ?: null, $action === 'update' ? $subjectId : 0, null, $classIds, $teacherName);
        flash('success', $action === 'update' ? 'Subject updated successfully.' : 'Subject created successfully.');
        redirect('admin/subjects.php');
    } catch (Throwable $exception) {
        flash('error', 'Failed to save subject: ' . $exception->getMessage());
        redirect($action === 'update' ? 'admin/subjects.php?edit=' . $subjectId : 'admin/subjects.php');
    }
}

$subjects = get_academic_subjects($pdo);
$selectedClassIds = $editingSubject['class_id_list'] ?? [];

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Academic Module</p>
        <h1>Subjects</h1>
        <p class="mb-0 text-muted">Create subjects once, then assign them to the classes that take them.</p>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/marks_entry.php') ?>"><i class="fa-solid fa-pen-to-square me-2"></i>Marks Entry</a>
</div>

<?php render_academic_module_nav(); ?>

<div class="row g-4">
    <div class="col-lg-5">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2><?= $editingSubject ? 'Edit Subject' : 'Add Subject' ?></h2>
                    <p class="panel-subtitle">Assign the subject to every class that should receive marks for it.</p>
                </div>
            </div>
            <?php if (!$classLevels): ?>
                <div class="alert alert-warning">Create class levels before adding academic subjects.</div>
            <?php endif; ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="<?= $editingSubject ? 'update' : 'create' ?>">
                <?php if ($editingSubject): ?>
                    <input type="hidden" name="subject_id" value="<?= (int) $editingSubject['id'] ?>">
                <?php endif; ?>
                <div class="col-12">
                    <label class="form-label" for="name">Subject Name</label>
                    <input class="form-control" id="name" name="name" required value="<?= h($editingSubject['name'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="code">Subject Code</label>
                    <input class="form-control" id="code" name="code" value="<?= h($editingSubject['code'] ?? '') ?>">
                </div>
                <div class="col-12">
                    <label class="form-label" for="teacher_name">Teacher Name</label>
                    <input class="form-control" id="teacher_name" name="teacher_name" value="<?= h($editingSubject['teacher_name'] ?? '') ?>" placeholder="e.g., John Smith">
                </div>
                <div class="col-12">
                    <label class="form-label">Assign to Classes</label>
                    <div class="academic-checkbox-grid">
                        <?php foreach ($classLevels as $classLevel): ?>
                            <label class="academic-check">
                                <input type="checkbox" name="class_ids[]" value="<?= (int) $classLevel['id'] ?>" <?= in_array((int) $classLevel['id'], $selectedClassIds, true) ? 'checked' : '' ?>>
                                <span><?= h($classLevel['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="col-12 action-row justify-content-start">
                    <button class="btn btn-primary" type="submit"><?= $editingSubject ? 'Update Subject' : 'Create Subject' ?></button>
                    <?php if ($editingSubject): ?>
                        <a class="btn btn-outline-secondary" href="<?= url('admin/subjects.php') ?>">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </div>
    <div class="col-lg-7">
        <section class="panel">
            <div class="panel-heading">
                <div>
                    <h2>Subjects Offered</h2>
                    <p class="panel-subtitle">Subjects are separate from fees and financial records.</p>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th>Subject</th>
                            <th>Code</th>
                            <th>Teacher</th>
                            <th>Assigned Classes</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $subject): ?>
                            <tr>
                                <td><strong><?= h($subject['name']) ?></strong></td>
                                <td><?= h($subject['code'] ?: '-') ?></td>
                                <td><?= h($subject['teacher_name'] ?: '-') ?></td>
                                <td><?= h($subject['class_names'] ?: 'Not assigned') ?></td>
                                <td class="text-end">
                                    <div class="action-row">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/subjects.php?edit=' . $subject['id']) ?>">Edit</a>
                                        <form method="post" class="m-0" onsubmit="return confirm('Delete <?= h($subject['name']) ?>?');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="subject_id" value="<?= (int) $subject['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$subjects): ?>
                            <tr><td colspan="4" class="text-center text-muted py-4">No subjects have been added yet.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
