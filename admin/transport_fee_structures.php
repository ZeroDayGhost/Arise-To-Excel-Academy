<?php
$pageTitle = 'Transport Fee Structure';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'transport_fee_structure.access')) {
    flash('error', 'You do not have permission to access Transport Fee Structure.');
    redirect('admin/dashboard.php');
}
$canSaveStructure = current_admin_has_permission($pdo, 'transport_fee_structure.save');
$canUpdateStructure = current_admin_has_permission($pdo, 'transport_fee_structure.update');
$canDeleteStructure = current_admin_has_permission($pdo, 'transport_fee_structure.delete');

$errors = [];
$editingId = (int) ($_GET['edit'] ?? ($_POST['transport_fee_structure_id'] ?? 0));
$currentContext = current_academic_context($pdo);
$statusOptions = ['Active', 'Inactive'];
$form = [
    'id' => '',
    'location_name' => '',
    'fee_amount' => '',
    'academic_year' => $currentContext['academic_year'],
    'status' => 'Active',
];

function transport_fee_structure_duplicate_exists(PDO $pdo, string $locationName, string $academicYear, int $ignoreId = 0): bool
{
    $sql = "SELECT COUNT(*) FROM transport_fee_structures WHERE location_name = :location_name AND academic_year = :academic_year";
    $params = ['location_name' => $locationName, 'academic_year' => $academicYear];

    if ($ignoreId > 0) {
        $sql .= ' AND id <> :id';
        $params['id'] = $ignoreId;
    }

    $statement = $pdo->prepare($sql);
    $statement->execute($params);
    return (int) $statement->fetchColumn() > 0;
}

function fetch_transport_fee_structure_by_id(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare("SELECT * FROM transport_fee_structures WHERE id = :id");
    $statement->execute(['id' => $id]);
    $structure = $statement->fetch();
    return $structure ?: null;
}

if ($editingId > 0) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !$canUpdateStructure) {
        rbac_deny($pdo, 'You do not have permission to update transport fee structures.');
    }
    $editingStructure = fetch_transport_fee_structure_by_id($pdo, $editingId);
    if ($editingStructure) {
        $form = array_merge($form, $editingStructure);
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        flash('error', 'The selected transport fee structure was not found.');
        redirect('admin/transport_fee_structures.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';
    $requiredPermission = match ($action) {
        'delete' => 'transport_fee_structure.delete',
        'update' => 'transport_fee_structure.update',
        default => 'transport_fee_structure.save',
    };
    require_action_permission($pdo, $requiredPermission);
    $structureId = (int) ($_POST['transport_fee_structure_id'] ?? 0);

    if ($action === 'delete') {
        $structure = $structureId > 0 ? fetch_transport_fee_structure_by_id($pdo, $structureId) : null;
        if (!$structure) {
            $errors[] = 'Choose a transport fee structure to delete.';
        } else {
            $linkedStudents = $pdo->prepare(
                "SELECT COUNT(*) FROM transport_students WHERE pickup_location = :pickup_location"
            );
            $linkedStudents->execute(['pickup_location' => $structure['location_name']]);
            if ((int) $linkedStudents->fetchColumn() > 0) {
                $errors[] = 'This location cannot be deleted because it is assigned to transport students. Deactivate it instead.';
            } else {
                $delete = $pdo->prepare("DELETE FROM transport_fee_structures WHERE id = :id");
                $delete->execute(['id' => $structureId]);
                flash('success', 'Transport fee structure deleted successfully.');
                redirect('admin/transport_fee_structures.php');
            }
        }
    } else {
        $form = [
            'id' => $structureId,
            'location_name' => trim($_POST['location_name'] ?? ''),
            'fee_amount' => trim($_POST['fee_amount'] ?? ''),
            'academic_year' => trim($_POST['academic_year'] ?? $currentContext['academic_year']),
            'status' => trim($_POST['status'] ?? 'Active'),
        ];

        if ($form['location_name'] === '') {
            $errors[] = 'Location name is required.';
        }
        if (!valid_money_value($form['fee_amount']) || (float) $form['fee_amount'] < 0) {
            $errors[] = 'Fee amount must be zero or greater.';
        }
        if ($form['academic_year'] === '' || !preg_match('/^\d{4}$/', $form['academic_year'])) {
            $errors[] = 'Academic year is required and must be YYYY.';
        }
        if (!in_array($form['status'], $statusOptions, true)) {
            $errors[] = 'Choose a valid status.';
        }
        if ($form['location_name'] !== '' && transport_fee_structure_duplicate_exists($pdo, $form['location_name'], $form['academic_year'], $action === 'update' ? $structureId : 0)) {
            $errors[] = 'A transport fee structure already exists for this location for the selected year.';
        }

        if (!$errors && $action === 'update' && $structureId > 0 && $editingStructure) {
            if ($editingStructure['location_name'] !== $form['location_name']) {
                $linkedStudents = $pdo->prepare(
                    "SELECT COUNT(*) FROM transport_students WHERE pickup_location = :pickup_location"
                );
                $linkedStudents->execute(['pickup_location' => $editingStructure['location_name']]);
                if ((int) $linkedStudents->fetchColumn() > 0) {
                    $errors[] = 'Location name cannot be changed after students have been assigned to this route.';
                }
            }
        }

        if (!$errors) {
            $params = [
                'location_name' => $form['location_name'],
                'fee_amount' => (float) $form['fee_amount'],
                'academic_year' => $form['academic_year'],
                'status' => $form['status'],
            ];

            if ($action === 'update' && $structureId > 0) {
                $params['id'] = $structureId;
                $statement = $pdo->prepare(
                    "UPDATE transport_fee_structures
                     SET location_name = :location_name,
                         fee_amount = :fee_amount,
                         academic_year = :academic_year,
                         status = :status
                     WHERE id = :id"
                );
                $statement->execute($params);
                flash('success', 'Transport fee structure updated successfully.');
            } else {
                $statement = $pdo->prepare(
                    "INSERT INTO transport_fee_structures (location_name, fee_amount, academic_year, status)
                     VALUES (:location_name, :fee_amount, :academic_year, :status)"
                );
                $statement->execute($params);
                flash('success', 'Transport fee structure saved successfully.');
            }

            redirect('admin/transport_fee_structures.php');
        }
    }
}

$search = trim($_GET['search'] ?? '');
$filterStatus = in_array(($_GET['status'] ?? ''), $statusOptions, true) ? $_GET['status'] : '';
$where = [];
$params = [];

if ($search !== '') {
    $where[] = 'location_name LIKE :search';
    $params['search'] = '%' . $search . '%';
}
if ($filterStatus !== '') {
    $where[] = 'status = :status';
    $params['status'] = $filterStatus;
}

$sql = "SELECT * FROM transport_fee_structures";
if ($where) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
$sql .= ' ORDER BY location_name ASC';

$statement = $pdo->prepare($sql);
$statement->execute($params);
$structures = $statement->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Transport Routes</p>
        <h1>Transport Fee Structure</h1>
        <p class="mb-0 text-muted"><?= h($currentContext['academic_year'] . ' - ' . $currentContext['term']) ?></p>
    </div>
    <a class="btn btn-outline-primary" href="<?= url('admin/transport.php') ?>">Manage Transport</a>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?><div class="alert alert-danger"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div><?php endif; ?>

<div class="row g-4">
    <?php if ($canSaveStructure || $canUpdateStructure): ?><div class="col-lg-4">
        <section class="panel">
            <h2><?= (int) $form['id'] > 0 ? 'Edit Location' : 'New Location' ?></h2>
            <form class="row g-3" method="post">
                <input type="hidden" name="transport_fee_structure_id" value="<?= (int) $form['id'] ?>">
                <div class="col-12">
                    <label class="form-label" for="location_name">Location Name</label>
                    <input class="form-control" id="location_name" name="location_name" value="<?= h((string) $form['location_name']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="fee_amount">Transport Fee Amount</label>
                    <input class="form-control" type="number" min="0" step="0.01" id="fee_amount" name="fee_amount" value="<?= h((string) $form['fee_amount']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="academic_year">Academic Year</label>
                    <input class="form-control" id="academic_year" name="academic_year" value="<?= h((string) $form['academic_year']) ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label" for="status">Status</label>
                    <select class="form-select" id="status" name="status" required>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?= h($status) ?>" <?= ((string) $form['status'] === $status) ? 'selected' : '' ?>><?= h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <div class="action-row">
                        <?php if ($canSaveStructure && (int) $form['id'] === 0): ?><button class="btn btn-primary" name="action" value="save" type="submit">Save</button><?php endif; ?>
                        <?php if ($canUpdateStructure && (int) $form['id'] > 0): ?><button class="btn btn-outline-primary" name="action" value="update" type="submit">Update</button><?php endif; ?>
                        <?php if ($canDeleteStructure && (int) $form['id'] > 0): ?><button class="btn btn-outline-danger" name="action" value="delete" type="submit" onclick="return confirm('Delete this transport fee location?');">Delete</button><?php endif; ?>
                    </div>
                </div>
            </form>
        </section>
    </div><?php endif; ?>
    <div class="<?= ($canSaveStructure || $canUpdateStructure) ? 'col-lg-8' : 'col-12' ?>">
        <section class="panel">
            <div class="panel-heading">
                <h2>All Transport Locations</h2>
            </div>
            <form class="row g-3 mb-4" method="get">
                <div class="col-md-6">
                    <input class="form-control" name="search" value="<?= h($search) ?>" placeholder="Search locations">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="status">
                        <option value="">All statuses</option>
                        <?php foreach ($statusOptions as $status): ?>
                            <option value="<?= h($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>><?= h($status) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <button class="btn btn-outline-primary w-100" type="submit">Filter</button>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr><th>Location</th><th>Year</th><th>Amount</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($structures as $structure): ?>
                            <tr>
                                <td><?= h($structure['location_name']) ?></td>
                                <td><?= h($structure['academic_year'] ?? '') ?></td>
                                <td><?= money((float) $structure['fee_amount']) ?></td>
                                <td><?= h($structure['status']) ?></td>
                                <td>
                                    <div class="action-row">
                                        <?php if ($canUpdateStructure): ?><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/transport_fee_structures.php?edit=' . $structure['id']) ?>">Edit</a><?php endif; ?>
                                        <?php if ($canDeleteStructure): ?><form method="post" onsubmit="return confirm('Delete this transport fee location?');">
                                            <input type="hidden" name="transport_fee_structure_id" value="<?= (int) $structure['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" name="action" value="delete" type="submit">Delete</button>
                                        </form><?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$structures): ?>
                            <tr><td colspan="4">No transport locations found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>
<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
