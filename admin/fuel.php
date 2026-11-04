<?php
$pageTitle = 'Fuel Transactions';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Check permission
if (!current_admin_has_permission($pdo, 'school_van_fuel.access')) {
    flash('error', 'You do not have permission to access School Van Fuel.');
    redirect('admin/dashboard.php');
}
$canAddFuel = current_admin_has_permission($pdo, 'school_van_fuel.add_fuel');
$canViewVehicles = current_admin_has_permission($pdo, 'school_van_fuel.view_vehicles');

$ctx = current_academic_context($pdo);
$filterYear = preg_match('/^\d{4}$/', (string) ($_GET['year'] ?? '')) ? (string) $_GET['year'] : '';
$filterTerm = in_array((string) ($_GET['term'] ?? ''), term_options(), true) ? (string) $_GET['term'] : '';
$search = trim((string) ($_GET['search'] ?? ''));
$dateFrom = trim((string) ($_GET['date_from'] ?? ''));
$dateTo = trim((string) ($_GET['date_to'] ?? ''));
if (!valid_date_value($dateFrom)) $dateFrom = '';
if (!valid_date_value($dateTo)) $dateTo = '';
if ($dateFrom !== '' && $dateTo !== '' && $dateFrom > $dateTo) {
    [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
}

$where = [];
$params = [];
if ($filterTerm !== '') {
    $periodYear = $filterYear !== '' ? $filterYear : (string) $ctx['academic_year'];
    $period = $pdo->prepare('SELECT start_date, end_date FROM academic_calendar WHERE academic_year = :year AND term_name = :term LIMIT 1');
    $period->execute(['year' => $periodYear, 'term' => $filterTerm]);
    $periodDates = $period->fetch();
    if ($periodDates) {
        $where[] = 'ft.fuel_date BETWEEN :term_start AND :term_end';
        $params['term_start'] = $periodDates['start_date'];
        $params['term_end'] = $periodDates['end_date'];
    } else {
        $where[] = '1 = 0';
    }
    $filterYear = $periodYear;
} elseif ($filterYear !== '') {
    $where[] = 'YEAR(ft.fuel_date) = :fuel_year';
    $params['fuel_year'] = $filterYear;
}
if ($dateFrom !== '') {
    $where[] = 'ft.fuel_date >= :fuel_date_from';
    $params['fuel_date_from'] = $dateFrom;
}
if ($dateTo !== '') {
    $where[] = 'ft.fuel_date <= :fuel_date_to';
    $params['fuel_date_to'] = $dateTo;
}
if ($search !== '') {
    $where[] = '(v.vehicle_name LIKE :fuel_vehicle OR ft.fuel_type LIKE :fuel_type OR ft.fuel_station LIKE :fuel_station OR ft.receipt_no LIKE :fuel_receipt OR ft.notes LIKE :fuel_notes)';
    $like = '%' . $search . '%';
    foreach (['fuel_vehicle', 'fuel_type', 'fuel_station', 'fuel_receipt', 'fuel_notes'] as $key) {
        $params[$key] = $like;
    }
}
$sql = 'SELECT ft.*, v.vehicle_name FROM fuel_transactions ft JOIN vehicles v ON v.id = ft.vehicle_id';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY ft.fuel_date DESC, ft.id DESC';
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();
$fuelTotal = array_sum(array_map(static fn(array $transaction): float => (float) $transaction['total_amount'], $transactions));
$fuelYears = array_map('strval', $pdo->query('SELECT DISTINCT YEAR(fuel_date) FROM fuel_transactions ORDER BY YEAR(fuel_date) DESC')->fetchAll(PDO::FETCH_COLUMN));
if (!in_array((string) $ctx['academic_year'], $fuelYears, true)) $fuelYears[] = (string) $ctx['academic_year'];
rsort($fuelYears, SORT_NUMERIC);

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">Transport</p>
        <h1>Fuel Transactions</h1>
        <p class="mb-0 text-muted">Record fueling and view history.</p>
    </div>
    <div class="action-row d-flex flex-wrap gap-2">
        <?php if ($canAddFuel): ?><a class="btn btn-primary" href="<?= url('admin/fuel_form.php') ?>">Add Fuel</a><?php endif; ?>
        <?php if ($canViewVehicles): ?><a class="btn btn-outline-primary" href="<?= url('admin/vehicles.php') ?>">Vehicles</a><?php endif; ?>
        <a class="btn btn-outline-primary" href="<?= url('admin/fuel_reports.php') ?>">Fuel Reports</a>
    </div>
</div>

<section class="panel">
    <div class="panel-heading"><div><h2>Fuel History</h2><p class="mb-0 text-muted">Search all recorded fuel purchases or filter by academic period and date.</p></div><strong><?= money($fuelTotal) ?></strong></div>
    <form method="get" class="row g-3 align-items-end mb-4">
        <div class="col-md-2"><label class="form-label" for="fuel-year">Year</label><select class="form-select" name="year" id="fuel-year"><option value="">All years</option><?php foreach ($fuelYears as $year): ?><option value="<?= h($year) ?>" <?= $filterYear === $year ? 'selected' : '' ?>><?= h($year) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="fuel-term">Term</label><select class="form-select" name="term" id="fuel-term"><option value="">All terms</option><?php foreach (term_options() as $term): ?><option value="<?= h($term) ?>" <?= $filterTerm === $term ? 'selected' : '' ?>><?= h($term) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-2"><label class="form-label" for="fuel-search">Search</label><input class="form-control" id="fuel-search" name="search" value="<?= h($search) ?>" placeholder="Vehicle, type, station"></div>
        <div class="col-md-2"><label class="form-label" for="fuel-date-from">From</label><input class="form-control" type="date" id="fuel-date-from" name="date_from" value="<?= h($dateFrom) ?>"></div>
        <div class="col-md-2"><label class="form-label" for="fuel-date-to">To</label><input class="form-control" type="date" id="fuel-date-to" name="date_to" value="<?= h($dateTo) ?>"></div>
        <div class="col-md-2 d-flex gap-2"><button class="btn btn-outline-primary flex-grow-1" type="submit">Filter</button><a class="btn btn-outline-secondary" href="<?= url('admin/fuel.php') ?>">Reset</a></div>
    </form>
    <div class="table-responsive">
        <table class="table table-striped align-middle">
            <thead><tr><th>Date</th><th>Vehicle</th><th>Fuel Type</th><th>Amount</th><th>Station</th><th>Receipt</th></tr></thead>
            <tbody>
                <?php foreach ($transactions as $t): ?>
                    <tr>
                        <td><?= h($t['fuel_date']) ?></td>
                        <td><?= h($t['vehicle_name']) ?></td>
                        <td><?= h($t['fuel_type']) ?></td>
                        <td><?= money((float)$t['total_amount']) ?></td>
                        <td><?= h($t['fuel_station']) ?></td>
                        <td><?= h($t['receipt_no']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$transactions): ?><tr><td colspan="6" class="text-center text-muted py-4">No fuel records match these filters.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
