<?php
$pageTitle = 'Vehicles';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$canAddVehicle = current_admin_has_permission($pdo, 'school_van_fuel.add_vehicle');
$canEditVehicle = current_admin_has_permission($pdo, 'school_van_fuel.edit_vehicle');
$canDeleteVehicle = current_admin_has_permission($pdo, 'school_van_fuel.delete_vehicle');
$editingId = (int) ($_GET['edit'] ?? ($_POST['vehicle_id'] ?? 0));
$editingVehicle = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $editingId > 0 && !$canEditVehicle) {
    rbac_deny($pdo, 'You do not have permission to edit vehicles.');
}

if ($editingId) {
    $stmt = $pdo->prepare('SELECT * FROM vehicles WHERE id = :id');
    $stmt->execute(['id' => $editingId]);
    $editingVehicle = $stmt->fetch();
}

$vehicles = $pdo->query('SELECT * FROM vehicles ORDER BY vehicle_name ASC')->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save'])) {
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        require_action_permission($pdo, $vehicleId > 0 ? 'school_van_fuel.edit_vehicle' : 'school_van_fuel.add_vehicle');
        $name = trim($_POST['vehicle_name'] ?? '');
        $reg = trim($_POST['registration_no'] ?? '');
        $driver = trim($_POST['driver_name'] ?? '');
        $notes = trim($_POST['notes'] ?? '');

        if ($name === '') { flash('error','Vehicle name required'); redirect('admin/vehicles.php'); }
        
        if ($vehicleId) {
            $pdo->prepare('UPDATE vehicles SET vehicle_name = :name, registration_no = :reg, driver_name = :driver, notes = :notes WHERE id = :id')->execute(['name'=>$name,'reg'=>$reg,'driver'=>$driver,'notes'=>$notes,'id'=>$vehicleId]);
            flash('success','Vehicle updated');
        } else {
            $pdo->prepare('INSERT INTO vehicles (vehicle_name, registration_no, driver_name, notes) VALUES (:name,:reg,:driver,:notes)')->execute(['name'=>$name,'reg'=>$reg,'driver'=>$driver,'notes'=>$notes]);
            flash('success','Vehicle added');
        }
        redirect('admin/vehicles.php');
    } elseif (isset($_POST['delete'])) {
        require_action_permission($pdo, 'school_van_fuel.delete_vehicle');
        $vehicleId = (int) ($_POST['vehicle_id'] ?? 0);
        if ($vehicleId) {
            $pdo->prepare('DELETE FROM vehicles WHERE id = :id')->execute(['id' => $vehicleId]);
            flash('success','Vehicle deleted');
        }
        redirect('admin/vehicles.php');
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Transport</p>
        <h1>Vehicles</h1>
        <p class="mb-0 text-muted">Manage school van vehicles.</p>
    </div>
</div>

<?php $err = flash('error'); $succ = flash('success'); if ($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<?php if ($succ): ?><div class="alert alert-success"><?= h($succ) ?></div><?php endif; ?>

<?php if ($canAddVehicle || $canEditVehicle): ?><section class="panel">
    <div class="panel-heading">
        <h2><?= $editingVehicle ? 'Edit Vehicle' : 'Register Vehicle' ?></h2>
    </div>
    <form method="post" class="row g-4 p-4 mx-auto" style="max-width: 1000px;">
        <?php if ($editingVehicle): ?>
            <input type="hidden" name="vehicle_id" value="<?= h($editingVehicle['id']) ?>">
        <?php endif; ?>
        <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Vehicle Name</label>
            <input class="form-control form-control-lg" name="vehicle_name" value="<?= h($editingVehicle['vehicle_name'] ?? '') ?>" required>
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Registration No</label>
            <input class="form-control form-control-lg" name="registration_no" value="<?= h($editingVehicle['registration_no'] ?? '') ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Driver Name</label>
            <input class="form-control form-control-lg" name="driver_name" value="<?= h($editingVehicle['driver_name'] ?? '') ?>">
        </div>
        <div class="col-12 col-md-6">
            <label class="form-label fw-semibold">Notes</label>
            <input class="form-control form-control-lg" name="notes" value="<?= h($editingVehicle['notes'] ?? '') ?>" placeholder="Optional notes about the vehicle">
        </div>
        <div class="col-12">
            <?php if (($editingVehicle && $canEditVehicle) || (!$editingVehicle && $canAddVehicle)): ?><button class="btn btn-primary btn-lg" name="save"><?= $editingVehicle ? 'Update Vehicle' : 'Add Vehicle' ?></button><?php endif; ?>
            <?php if ($editingVehicle): ?>
                <a href="admin/vehicles.php" class="btn btn-outline-secondary btn-lg">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</section><?php endif; ?>

<section class="panel mt-5">
    <div class="panel-heading border-bottom">
        <h2 class="mb-0">Vehicle Records (<?= count($vehicles) ?>)</h2>
    </div>
    <div class="table-responsive" style="max-width: 1000px; margin: 0 auto;">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th style="width: 25%;" class="text-start"><strong>Vehicle Name</strong></th>
                    <th style="width: 25%;" class="text-start"><strong>Registration No</strong></th>
                    <th style="width: 25%;" class="text-start"><strong>Driver</strong></th>
                    <th style="width: 15%;" class="text-start"><strong>Notes</strong></th>
                    <th style="width: 10%;" class="text-center"><strong>Actions</strong></th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($vehicles) > 0): ?>
                    <?php foreach ($vehicles as $v): ?>
                        <tr>
                            <td style="width: 25%;" class="text-start"><strong><?= h($v['vehicle_name']) ?></strong></td>
                            <td style="width: 25%;" class="text-start"><span class="badge bg-info"><?= h($v['registration_no']) ?></span></td>
                            <td style="width: 25%;" class="text-start"><?= h($v['driver_name']) ?: '<em class="text-muted">Not assigned</em>' ?></td>
                            <td style="width: 15%;" class="text-start"><?= h($v['notes']) ?: '<em class="text-muted">No notes</em>' ?></td>
                            <td style="width: 10%;" class="text-center">
                                <?php if ($canEditVehicle): ?><a href="?edit=<?= h($v['id']) ?>" class="btn btn-sm btn-primary" title="Edit"><i class="fa-solid fa-pencil"></i> Edit</a><?php endif; ?>
                                <?php if ($canDeleteVehicle): ?><form method="post" style="display:inline;">
                                    <input type="hidden" name="vehicle_id" value="<?= h($v['id']) ?>">
                                    <button type="submit" name="delete" class="btn btn-sm btn-danger" title="Delete" onclick="return confirm('Are you sure you want to delete this vehicle?');"><i class="fa-solid fa-trash"></i> Delete</button>
                                </form><?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted py-4">
                            <p><em>No vehicles registered yet. Add one using the form above.</em></p>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
