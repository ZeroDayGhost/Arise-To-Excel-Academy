<?php
$pageTitle = 'Class Levels';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

if (!current_admin_has_permission($pdo, 'settings.manage_class_levels')) {
    flash('error', 'You do not have permission to access Class Levels.');
    redirect('admin/dashboard.php');
}

$errors = [];
$editingId = (int) ($_GET['edit'] ?? 0);
$form = [
    'id' => 0,
    'name' => '',
    'status' => 'Active',
];

if ($editingId > 0) {
    $editingClassLevel = get_class_level_by_id($pdo, $editingId);
    if ($editingClassLevel) {
        $form = array_merge($form, $editingClassLevel);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        flash('error', 'The selected class level was not found.');
        redirect('admin/class_levels.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $form = [
        'id' => (int) ($_POST['class_level_id'] ?? 0),
        'name' => trim((string) ($_POST['name'] ?? '')),
        'status' => trim((string) ($_POST['status'] ?? 'Active')),
    ];

    if ($form['name'] === '') {
        $errors[] = 'Class level name is required.';
    }

    if ($action === 'delete') {
        $classLevel = get_class_level_by_id($pdo, $form['id']);
        if (!$classLevel) {
            $errors[] = 'Choose a class level to delete.';
        } elseif (is_class_level_in_use($pdo, $classLevel['name'])) {
            $errors[] = 'This class level is in use and cannot be deleted.';
        } else {
            delete_class_level($pdo, $classLevel['id']);
            flash('success', 'Class level deleted successfully.');
            redirect('admin/class_levels.php');
        }
    } else {
        if (class_level_exists($pdo, $form['name'], $form['id'])) {
            $errors[] = 'A class level with this name already exists.';
        }

        if (!$errors) {
            if ($form['id'] > 0) {
                save_class_level($pdo, $form['name'], $form['id']);
                flash('success', 'Class level updated successfully.');
            } else {
                save_class_level($pdo, $form['name']);
                flash('success', 'Class level created successfully.');
            }
            redirect('admin/class_levels.php');
        }
    }
}

$classLevels = fetch_class_levels($pdo);
require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">School Settings</p>
        <h1>Class Levels</h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/fee_structures.php') ?>">Manage Fee Structures</a>
        <a class="btn btn-outline-primary" href="<?= url('admin/students.php') ?>">Manage Students</a>
    </div>
</div>

<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('error')): ?>
    <div class="alert alert-danger"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?>
            <div><?= h($error) ?></div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <h2><?= $form['id'] > 0 ? 'Edit Class Level' : 'New Class Level' ?></h2>
            <form class="row g-3" method="post">
                <input type="hidden" name="class_level_id" value="<?= (int) $form['id'] ?>">
                <div class="col-12">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="<?= h($form['name']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status">
                        <option value="Active" <?= $form['status'] === 'Active' ? 'selected' : '' ?>>Active</option>
                        <option value="Inactive" <?= $form['status'] === 'Inactive' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                <div class="col-12">
                    <div class="action-row">
                        <button class="btn btn-primary" name="action" value="save" type="submit">Save</button>
                        <?php if ($form['id'] > 0): ?>
                            <button class="btn btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this class level?');">Delete</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <div class="panel-heading">
                <h2>Class Levels</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($classLevels as $classLevel): ?>
                            <tr>
                                <td><?= h($classLevel['name']) ?></td>
                                <td><?= h($classLevel['status']) ?></td>
                                <td>
                                    <div class="action-row">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/class_levels.php?edit=' . $classLevel['id']) ?>">Edit</a>
                                        <?php if (!is_class_level_in_use($pdo, $classLevel['name'])): ?>
                                            <form method="post" onsubmit="return confirm('Delete this class level?');">
                                                <input type="hidden" name="class_level_id" value="<?= (int) $classLevel['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-secondary" disabled>In Use</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$classLevels): ?>
                            <tr>
                                <td colspan="3">No class levels defined yet.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php';
