<?php
$pageTitle = 'Fee Structure';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Fee structure is a Settings action, not a Fees action.
if (!current_admin_has_permission($pdo, 'settings.fee_structure')) {
    flash('error', 'You do not have permission to access Fee Structures.');
    redirect('admin/dashboard.php');
}

$classLevels = class_level_options();
$errors = [];
$editingId = (int) ($_GET['edit'] ?? ($_POST['fee_structure_id'] ?? 0));
$currentContext = current_academic_context($pdo);
$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$currentAdminRole = $adminId ? get_user_role_template_name($pdo, $adminId) : '';
$currentAdminClass = $adminId ? current_admin_class_level($pdo) : null;
$canEditFees = current_admin_has_permission($pdo, 'settings.fee_structure');
$canViewFees = $canEditFees;
$form = [
    'id' => '',
    'academic_year' => $currentContext['academic_year'],
    'class_level' => '',
    'term1_total' => '',
    'term2_total' => '',
    'term3_total' => '',
];

function fee_structure_duplicate_exists(PDO $pdo, string $academicYear, string $classLevel, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*)
            FROM fee_structures
            WHERE academic_year = :academic_year
              AND class_level = :class_level";
    $params = [
        'academic_year' => $academicYear,
        'class_level' => $classLevel,
    ];

    if ($ignoreId > 0) {
        $sql .= " AND id <> :id";
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn() > 0;
}

function fetch_fee_structure_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT * FROM fee_structures WHERE id = :id");
    $statement->execute(['id' => $id]);
    $structure = $statement->fetch();

    return $structure ?: null;
}

if ($editingId > 0) {
    $editingStructure = fetch_fee_structure_by_id($pdo, $editingId);
    if ($editingStructure) {
        $form = array_merge($form, $editingStructure);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        flash('error', 'The selected fee structure was not found.');
        redirect('admin/fee_structures.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $feeStructureId = (int) ($_POST['fee_structure_id'] ?? 0);

    // Only users with fees.edit can perform modifying actions
    if (!$canEditFees) {
        flash('error', 'You do not have permission to modify fee structures.');
        redirect('admin/fee_structures.php');
    }

    if ($action === 'delete') {
        $structure = $feeStructureId > 0 ? fetch_fee_structure_by_id($pdo, $feeStructureId) : null;

        if (!$structure) {
            $errors[] = 'Choose a fee structure to delete.';
        } else {
            $paymentCheck = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM fees
                 WHERE year = :academic_year"
            );
            $paymentCheck->execute([
                'academic_year' => $structure['academic_year'],
            ]);

            if ((int) $paymentCheck->fetchColumn() > 0) {
                $errors[] = 'Fee structures for this year cannot be deleted while payment records exist.';
            } else {
                $balanceCheck = $pdo->prepare(
                    "SELECT COUNT(*)
                     FROM fee_balances
                     WHERE academic_year = :academic_year"
                );
                $balanceCheck->execute([
                    'academic_year' => $structure['academic_year'],
                ]);

                if ((int) $balanceCheck->fetchColumn() > 0) {
                    $errors[] = 'Fee structures for this year cannot be deleted while student balances exist.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $deleteStructure = $pdo->prepare("DELETE FROM fee_structures WHERE id = :id");
                        $deleteStructure->execute(['id' => $feeStructureId]);

                        $pdo->commit();
                        flash('success', 'Fee structure deleted successfully.');
                        redirect('admin/fee_structures.php');
                    } catch (Throwable $exception) {
                        if ($pdo->inTransaction()) {
                            $pdo->rollBack();
                        }
                        error_log('Fee structure delete failed with code ' . (string) $exception->getCode());
                        $errors[] = 'Unable to delete fee structure. Please try again or contact the system administrator.';
                    }
                }
            }
        }
    } else {
        $form = [
            'id' => $feeStructureId,
            'academic_year' => trim($_POST['academic_year'] ?? ''),
            'class_level' => trim($_POST['class_level'] ?? ''),
            'term1_total' => trim($_POST['term1_total'] ?? ''),
            'term2_total' => trim($_POST['term2_total'] ?? ''),
            'term3_total' => trim($_POST['term3_total'] ?? ''),
        ];

        if (!preg_match('/^\d{4}$/', $form['academic_year'])) {
            $errors[] = 'Academic year must be four digits.';
        }
        if (!in_array($form['class_level'], $classLevels, true)) {
            $errors[] = 'Please choose a valid class level.';
        }
        foreach (['term1_total' => 'Term 1', 'term2_total' => 'Term 2', 'term3_total' => 'Term 3'] as $field => $label) {
            if (!valid_money_value($form[$field])) {
                $errors[] = "{$label} total must be zero or more.";
            }
        }

        $originalStructure = null;
        if ($action === 'update' && $feeStructureId <= 0) {
            $errors[] = 'Choose a fee structure to update.';
        }
        if ($action === 'update' && $feeStructureId > 0) {
            $originalStructure = fetch_fee_structure_by_id($pdo, $feeStructureId);
            if (!$originalStructure) {
                $errors[] = 'The selected fee structure was not found.';
            }
        }
        if (!in_array($action, ['save', 'update'], true)) {
            $errors[] = 'Please choose a valid form action.';
        }
        if (!$errors && fee_structure_duplicate_exists($pdo, $form['academic_year'], $form['class_level'], $action === 'update' ? $feeStructureId : 0)) {
            $errors[] = 'A fee structure already exists for this class and academic year.';
        }
        if (!$errors && $originalStructure && ($originalStructure['academic_year'] !== $form['academic_year'] || $originalStructure['class_level'] !== $form['class_level'])) {
            $paymentCheck = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM fees
                 WHERE year = :academic_year"
            );
            $paymentCheck->execute([
                'academic_year' => $originalStructure['academic_year'],
            ]);

            if ((int) $paymentCheck->fetchColumn() > 0) {
                $errors[] = 'Class level and year cannot be changed after payments have been recorded for this academic year.';
            }

            $balanceCheck = $pdo->prepare(
                "SELECT COUNT(*)
                 FROM fee_balances
                 WHERE academic_year = :academic_year"
            );
            $balanceCheck->execute([
                'academic_year' => $originalStructure['academic_year'],
            ]);
            if ((int) $balanceCheck->fetchColumn() > 0) {
                $errors[] = 'Class level and year cannot be changed after student balances have been assessed for this academic year.';
            }
        }

        if (!$errors) {
            $params = [
                'academic_year' => $form['academic_year'],
                'class_level' => $form['class_level'],
                'term1_total' => (float) $form['term1_total'],
                'term2_total' => (float) $form['term2_total'],
                'term3_total' => (float) $form['term3_total'],
            ];

            $pdo->beginTransaction();
            try {
                if ($action === 'update') {
                    if ($originalStructure && ($originalStructure['academic_year'] !== $form['academic_year'] || $originalStructure['class_level'] !== $form['class_level'])) {
                        $deleteOldBalances = $pdo->prepare(
                            "DELETE fee_balances
                             FROM fee_balances
                             JOIN students ON students.id = fee_balances.student_id
                             WHERE fee_balances.academic_year = :academic_year
                               AND students.class_level = :class_level"
                        );
                        $deleteOldBalances->execute([
                            'academic_year' => $originalStructure['academic_year'],
                            'class_level' => $originalStructure['class_level'],
                        ]);
                    }

                    $params['id'] = $feeStructureId;
                    $statement = $pdo->prepare(
                        "UPDATE fee_structures
                         SET academic_year = :academic_year,
                             class_level = :class_level,
                             term1_total = :term1_total,
                             term2_total = :term2_total,
                             term3_total = :term3_total
                         WHERE id = :id"
                    );
                    $statement->execute($params);
                    $message = 'Fee structure updated. Existing assessed balances were preserved.';
                } else {
                    $statement = $pdo->prepare(
                        "INSERT INTO fee_structures
                            (academic_year, class_level, term1_total, term2_total, term3_total)
                         VALUES
                            (:academic_year, :class_level, :term1_total, :term2_total, :term3_total)"
                    );
                    $statement->execute($params);
                    $message = 'Fee structure saved. Existing assessed balances were preserved.';
                }

                $pdo->commit();

                flash('success', $message);
                redirect('admin/fee_structures.php');
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('Fee structure save failed with code ' . (string) $exception->getCode());
                $errors[] = 'Unable to save fee structure. Please try again or contact the system administrator.';
            }
        }
    }
}

$filterYear = trim($_GET['year'] ?? '');
$currentContext = current_academic_context($pdo);
if ($filterYear === '') {
    $filterYear = $currentContext['academic_year'];
}

// If the active academic context was just switched, ignore explicit year filter so pages show the new context
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
if (!empty($_SESSION['academic_context_switched'])) {
    $filterYear = '';
    unset($_SESSION['academic_context_switched']);
}
$filterClass = trim($_GET['class_level'] ?? '');
$search = trim($_GET['search'] ?? '');
$params = [];
$where = [];

if ($filterYear !== '') {
    $where[] = 'academic_year = :filter_year';
    $params['filter_year'] = $filterYear;
}
if ($filterClass !== '' && in_array($filterClass, $classLevels, true)) {
    $where[] = 'class_level = :filter_class';
    $params['filter_class'] = $filterClass;
}
if ($search !== '') {
    $where[] = '(academic_year LIKE :search_year OR class_level LIKE :search_class)';
    $params['search_year'] = '%' . $search . '%';
    $params['search_class'] = '%' . $search . '%';
}

$sql = "SELECT *
        FROM fee_structures";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY academic_year DESC, class_level ASC';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$feeStructures = $statement->fetchAll();
$years = $pdo->query("SELECT DISTINCT academic_year FROM fee_structures ORDER BY academic_year DESC")->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Term Setup</p>
        <h1>Fee Structure</h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/students.php') ?>">Manage Students</a>
        <a class="btn btn-outline-primary" href="<?= url('admin/class_levels.php') ?>">Manage Class Levels</a>
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
            <h2><?= (int) $form['id'] > 0 ? 'Edit Fee Structure' : 'New Fee Structure' ?></h2>
            <form class="row g-3" method="post">
                <input type="hidden" name="fee_structure_id" value="<?= (int) $form['id'] ?>">
                <div class="col-md-6">
                    <label class="form-label" for="academic_year">Academic Year</label>
                    <input class="form-control" id="academic_year" name="academic_year" inputmode="numeric" maxlength="4" value="<?= h((string) $form['academic_year']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="class_level">Class Level</label>
                    <select class="form-select" id="class_level" name="class_level" required>
                        <option value="">Choose class...</option>
                        <?php foreach ($classLevels as $classLevel): ?>
                            <option value="<?= h($classLevel) ?>" <?= ((string) $form['class_level'] === $classLevel) ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label" for="term1_total">Term 1 Total</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="term1_total" name="term1_total" value="<?= h((string) $form['term1_total']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="term2_total">Term 2 Total</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="term2_total" name="term2_total" value="<?= h((string) $form['term2_total']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="term3_total">Term 3 Total</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="term3_total" name="term3_total" value="<?= h((string) $form['term3_total']) ?>" required>
                </div>
                <div class="col-12">
                    <div class="action-row">
                        <?php if ($canEditFees): ?>
                            <button class="btn btn-primary" name="action" value="save" type="submit">Save</button>
                            <button class="btn btn-outline-primary" name="action" value="update" type="submit" <?= (int) $form['id'] > 0 ? '' : 'disabled' ?>>Update</button>
                            <button class="btn btn-outline-danger" name="action" value="delete" type="submit" <?= (int) $form['id'] > 0 ? '' : 'disabled' ?> onclick="return confirm('Delete this fee structure?');">Delete</button>
                        <?php else: ?>
                            <button class="btn btn-primary" disabled>Save</button>
                            <button class="btn btn-outline-primary" disabled>Update</button>
                            <button class="btn btn-outline-danger" disabled>Delete</button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <div class="panel-heading">
                <h2>All Fee Structures</h2>
            </div>
            <form class="row g-3 mb-4" method="get">
                <div class="col-md-4">
                    <input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search fee structures">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="year">
                        <option value="">All years</option>
                        <?php foreach ($years as $year): ?>
                            <option value="<?= h($year['academic_year']) ?>" <?= $filterYear === $year['academic_year'] ? 'selected' : '' ?>><?= h($year['academic_year']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="class_level">
                        <option value="">All classes</option>
                        <?php foreach ($classLevels as $classLevel): ?>
                            <option value="<?= h($classLevel) ?>" <?= $filterClass === $classLevel ? 'selected' : '' ?>><?= h($classLevel) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-primary w-100" type="submit">Filter</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Year</th>
                            <th>Class Level</th>
                            <th>Term 1</th>
                            <th>Term 2</th>
                            <th>Term 3</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($feeStructures as $structure): ?>
                            <tr>
                                <td><?= h($structure['academic_year']) ?></td>
                                <td><?= h($structure['class_level']) ?></td>
                                <td><?= money((float) $structure['term1_total']) ?></td>
                                <td><?= money((float) $structure['term2_total']) ?></td>
                                <td><?= money((float) $structure['term3_total']) ?></td>
                                <td>
                                    <div class="action-row">
                                        <?php if ($canEditFees): ?>
                                            <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/fee_structures.php?edit=' . $structure['id']) ?>">Edit</a>
                                            <form method="post" onsubmit="return confirm('Delete this fee structure?');">
                                                <input type="hidden" name="fee_structure_id" value="<?= (int) $structure['id'] ?>">
                                                <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit">Delete</button>
                                            </form>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-primary" disabled>Edit</button>
                                            <button class="btn btn-sm btn-outline-danger" disabled>Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$feeStructures): ?>
                            <tr><td colspan="6">No fee structures found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
