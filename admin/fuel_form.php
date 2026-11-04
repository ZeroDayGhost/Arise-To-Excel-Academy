<?php
$pageTitle = 'Add Fuel Transaction';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Module access check
if (!current_admin_has_permission($pdo, 'school_van_fuel.access')) {
    flash('error', 'You do not have permission to access Fuel transactions.');
    redirect('admin/dashboard.php');
}

$canAddFuel = current_admin_has_permission($pdo, 'school_van_fuel.add_fuel');

$vehicles = $pdo->query('SELECT * FROM vehicles ORDER BY vehicle_name ASC')->fetchAll();
$error = flash('error'); $success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $vehicleId = (int)($_POST['vehicle_id'] ?? 0);
    $fuelDate = trim($_POST['fuel_date'] ?? date('Y-m-d'));
    $fuelType = trim($_POST['fuel_type'] ?? 'Diesel');
    $total = (float)($_POST['amount'] ?? 0);
    $station = trim($_POST['fuel_station'] ?? '');
    $receipt = trim($_POST['receipt_no'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($vehicleId <= 0) { flash('error','Select vehicle'); redirect('admin/fuel_form.php'); }
    if ($total <= 0) { flash('error','Amount must be greater than zero'); redirect('admin/fuel_form.php'); }

    // store litres and cost_per_litre as zero for compatibility
    if (!$canAddFuel) {
        flash('error', 'You do not have permission to add fuel transactions.');
        redirect('admin/fuel.php');
    }

    $ins = $pdo->prepare('INSERT INTO fuel_transactions (vehicle_id, fuel_date, fuel_type, litres, cost_per_litre, total_amount, fuel_station, receipt_no, notes, recorded_by) VALUES (:vehicle_id, :fuel_date, :fuel_type, :litres, :cost_per_litre, :total_amount, :fuel_station, :receipt_no, :notes, :recorded_by)');
    $ins->execute(['vehicle_id'=>$vehicleId,'fuel_date'=>$fuelDate,'fuel_type'=>$fuelType,'litres'=>0,'cost_per_litre'=>0,'total_amount'=>$total,'fuel_station'=>$station,'receipt_no'=>$receipt,'notes'=>$notes,'recorded_by'=>$_SESSION['admin_id'] ?? null]);
    flash('success','Fuel transaction recorded');
    redirect('admin/fuel.php');
}

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Transport</p>
        <h1>Add Fuel Transaction</h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/fuel.php') ?>">History</a>
    </div>
</div>

<?php if ($error): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
<?php if ($success): ?><div class="alert alert-success"><?= h($success) ?></div><?php endif; ?>

<section class="panel">
    <form method="post" class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Vehicle</label>
            <select class="form-select" name="vehicle_id" required>
                <option value="">Choose vehicle...</option>
                <?php foreach ($vehicles as $v): ?>
                    <option value="<?= (int)$v['id'] ?>"><?= h($v['vehicle_name'] . ($v['registration_no'] ? ' | ' . $v['registration_no'] : '')) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-6"><label class="form-label">Date</label><input class="form-control" type="date" name="fuel_date" value="<?= date('Y-m-d') ?>"></div>
        <div class="col-md-4"><label class="form-label">Fuel Type</label><input class="form-control" name="fuel_type" value="Diesel"></div>
        <div class="col-md-4"><label class="form-label">Amount</label><input class="form-control" name="amount" type="number" step="0.01" required></div>
        <div class="col-md-6"><label class="form-label">Fuel Station</label><input class="form-control" name="fuel_station"></div>
        <div class="col-md-6"><label class="form-label">Receipt No</label><input class="form-control" name="receipt_no"></div>
        <div class="col-12"><label class="form-label">Notes</label><input class="form-control" name="notes"></div>
        <div class="col-12">
            <?php if ($canAddFuel): ?>
                <button class="btn btn-primary" type="submit">Save Fuel Transaction</button>
            <?php else: ?>
                <button class="btn btn-primary" type="button" disabled>Save Fuel Transaction</button>
            <?php endif; ?>
        </div>
    </form>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
