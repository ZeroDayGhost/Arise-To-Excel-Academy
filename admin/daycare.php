<?php
$pageTitle = 'Daycare';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/daycare_helpers.php';

$views = ['overview', 'children', 'payments', 'fee-plans', 'parents', 'reports', 'settings'];
$view = (string) ($_GET['view'] ?? 'overview');
if (!in_array($view, $views, true)) {
    $view = 'overview';
}
$viewPermissions = [
    'children' => 'daycare.children.view', 'payments' => 'daycare.payments.view',
    'fee-plans' => 'daycare.fees.manage', 'parents' => 'daycare.guardians.manage',
    'reports' => 'daycare.reports.view', 'settings' => 'daycare.settings.manage',
];
if (!empty($viewPermissions[$view])) {
    daycare_require($pdo, $viewPermissions[$view]);
}

$can = static fn(string $permission): bool => current_admin_has_permission($pdo, $permission);
$daycareUrl = static function (string $target, array $query = []): string {
    return url('admin/daycare.php') . '?' . http_build_query(['view' => $target] + $query);
};
$pageUrl = static fn(array $query = []): string => url('admin/daycare.php') . ($query ? '?' . http_build_query($query) : '');
$nav = [
    ['overview', 'Overview', 'fa-chart-pie', ''],
    ['children', 'Children', 'fa-child', 'daycare.children.view'],
    ['payments', 'Payments', 'fa-wallet', 'daycare.payments.view'],
    ['fee-plans', 'Fee Plans', 'fa-file-invoice-dollar', 'daycare.fees.manage'],
    ['parents', 'Parents & Guardians', 'fa-people-roof', 'daycare.guardians.manage'],
    ['reports', 'Child Report', 'fa-child', 'daycare.reports.view'],
    ['settings', 'Settings', 'fa-gear', 'daycare.settings.manage'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'register_child':
                daycare_require($pdo, 'daycare.children.manage');
                $childId = daycare_register_child($pdo, $_POST);
                flash('success', 'Child registered successfully.');
                redirect($daycareUrl('children', ['child' => $childId]));
            case 'update_child':
                daycare_require($pdo, 'daycare.children.manage');
                $childId = (int) $_POST['child_id'];
                daycare_update_child($pdo, $childId, $_POST);
                flash('success', 'Child profile updated.');
                redirect($daycareUrl('children', ['child' => $childId]));
            case 'add_guardian_link':
                daycare_require($pdo, 'daycare.guardians.manage');
                $childId = (int) $_POST['child_id'];
                $name = trim((string) $_POST['full_name']);
                $phone = trim((string) $_POST['phone']);
                if ($name === '' || $phone === '') {
                    throw new InvalidArgumentException('Parent name and phone number are required.');
                }
                $pdo->beginTransaction();
                $find = $pdo->prepare('SELECT id FROM daycare_guardians WHERE phone=:phone LIMIT 1 FOR UPDATE');
                $find->execute(['phone' => $phone]);
                $guardianId = (int) $find->fetchColumn();
                if ($guardianId) {
                    $pdo->prepare('UPDATE daycare_guardians SET full_name=:name, alternate_phone=:alt, email=:email, relationship=:relationship WHERE id=:id')->execute(['name' => $name, 'alt' => trim((string) ($_POST['alternate_phone'] ?? '')) ?: null, 'email' => trim((string) ($_POST['email'] ?? '')) ?: null, 'relationship' => trim((string) ($_POST['relationship'] ?? '')) ?: null, 'id' => $guardianId]);
                } else {
                    $pdo->prepare('INSERT INTO daycare_guardians (full_name,phone,alternate_phone,email,relationship) VALUES (:name,:phone,:alt,:email,:relationship)')->execute(['name' => $name, 'phone' => $phone, 'alt' => trim((string) ($_POST['alternate_phone'] ?? '')) ?: null, 'email' => trim((string) ($_POST['email'] ?? '')) ?: null, 'relationship' => trim((string) ($_POST['relationship'] ?? '')) ?: null]);
                    $guardianId = (int) $pdo->lastInsertId();
                }
                $pdo->prepare('INSERT INTO daycare_child_guardians (child_id,guardian_id,relationship,is_primary) VALUES (:child,:guardian,:relationship,0) ON DUPLICATE KEY UPDATE relationship=VALUES(relationship)')->execute(['child' => $childId, 'guardian' => $guardianId, 'relationship' => trim((string) ($_POST['relationship'] ?? '')) ?: null]);
                daycare_audit($pdo, 'GUARDIAN_LINKED', 'guardian', $guardianId, ['child_id' => $childId]);
                $pdo->commit();
                flash('success', 'Parent or guardian linked to the child.');
                redirect($daycareUrl('parents'));
            case 'record_payment':
                daycare_require($pdo, 'daycare.payments.record');
                $paymentId = daycare_record_payment($pdo, $_POST);
                flash('success', 'Payment recorded. The receipt number was generated automatically.');
                redirect(url('admin/daycare_receipt.php?id=' . $paymentId));
            case 'add_group':
                daycare_require($pdo, 'daycare.settings.manage');
                $name = trim((string) ($_POST['name'] ?? ''));
                if ($name === '') throw new InvalidArgumentException('Enter a group name.');
                $pdo->prepare('INSERT INTO daycare_groups (name,age_range,capacity) VALUES (:name,:age,:capacity)')->execute(['name' => $name, 'age' => trim((string) ($_POST['age_range'] ?? '')) ?: null, 'capacity' => (int) ($_POST['capacity'] ?? 0) ?: null]);
                daycare_audit($pdo, 'GROUP_CREATED', 'group', (int) $pdo->lastInsertId());
                flash('success', 'Daycare group created.');
                redirect($daycareUrl('settings'));
            case 'update_group':
                daycare_require($pdo, 'daycare.settings.manage');
                $groupId = (int) ($_POST['group_id'] ?? 0);
                $groupName = trim((string) ($_POST['name'] ?? ''));
                if ($groupId <= 0 || $groupName === '') throw new InvalidArgumentException('Enter a group name.');
                $pdo->prepare("UPDATE daycare_groups SET name=:name,age_range=:age,capacity=:capacity,status=:status WHERE id=:id")->execute(['name' => $groupName, 'age' => trim((string)($_POST['age_range'] ?? '')) ?: null, 'capacity' => (int)($_POST['capacity'] ?? 0) ?: null, 'status' => ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active', 'id' => $groupId]);
                daycare_audit($pdo, 'GROUP_UPDATED', 'group', $groupId);
                flash('success', 'Daycare group updated.');
                redirect($daycareUrl('settings'));
            case 'add_plan':
                daycare_require($pdo, 'daycare.fees.manage');
                $name = trim((string) ($_POST['name'] ?? ''));
                $amount = round((float) ($_POST['amount'] ?? 0), 2);
                $period = (string) ($_POST['billing_period'] ?? 'Monthly');
                if ($name === '' || $amount < 0 || !in_array($period, ['Daily','Weekly','Monthly','Half-day','Custom'], true)) throw new InvalidArgumentException('Enter a valid fee plan name, billing period, and amount.');
                $pdo->prepare('INSERT INTO daycare_fee_plans (name,billing_period,amount,late_pickup_fee,charge_absent_days,optional_services) VALUES (:name,:period,:amount,0,0,:services)')->execute(['name' => $name, 'period' => $period, 'amount' => $amount, 'services' => trim((string) ($_POST['optional_services'] ?? '')) ?: null]);
                daycare_audit($pdo, 'FEE_PLAN_CREATED', 'fee_plan', (int) $pdo->lastInsertId());
                flash('success', 'Daycare fee plan created.');
                redirect($daycareUrl('fee-plans'));
            case 'update_plan':
                daycare_require($pdo, 'daycare.fees.manage');
                $planId = (int) ($_POST['plan_id'] ?? 0);
                $planName = trim((string) ($_POST['name'] ?? ''));
                $planPeriod = (string) ($_POST['billing_period'] ?? 'Monthly');
                $planAmount = round((float) ($_POST['amount'] ?? -1), 2);
                if ($planId <= 0 || $planName === '' || $planAmount < 0 || !in_array($planPeriod, ['Daily','Weekly','Monthly','Half-day','Custom'], true)) throw new InvalidArgumentException('Enter a valid fee plan.');
                $pdo->prepare('UPDATE daycare_fee_plans SET name=:name,billing_period=:period,amount=:amount,optional_services=:services,status=:status WHERE id=:id')->execute(['name' => $planName, 'period' => $planPeriod, 'amount' => $planAmount, 'services' => trim((string)($_POST['optional_services'] ?? '')) ?: null, 'status' => ($_POST['status'] ?? 'Active') === 'Inactive' ? 'Inactive' : 'Active', 'id' => $planId]);
                daycare_audit($pdo, 'FEE_PLAN_UPDATED', 'fee_plan', $planId);
                flash('success', 'Daycare fee plan updated.');
                redirect($daycareUrl('fee-plans'));
            default:
                throw new InvalidArgumentException('That daycare action is not available.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash('error', $exception->getMessage());
        redirect($daycareUrl($view));
    }
}

$groups = $pdo->query("SELECT id,name,age_range,capacity FROM daycare_groups WHERE status='Active' ORDER BY name")->fetchAll();
foreach ($groups as &$group) {
    $ageRange = trim((string) ($group['age_range'] ?? ''));
    if ($ageRange !== '') {
        $group['name'] .= ' (' . $ageRange . ')';
    }
}
unset($group);
$plans = $pdo->query("SELECT id,name,billing_period,amount,optional_services FROM daycare_fee_plans WHERE status='Active' ORDER BY name")->fetchAll();
$activeChildren = $pdo->query("SELECT id,admission_no,full_name,primary_guardian_id FROM daycare_children WHERE enrollment_status='Active' ORDER BY full_name")->fetchAll();
$today = date('Y-m-d');
$search = trim((string) ($_GET['q'] ?? ''));
$childrenPage = max(1, (int) ($_GET['page'] ?? 1));
$childrenPerPage = 50;
$childrenTotal = 0;
$children = [];
$selectedChild = null;
$guardians = [];
$payments = [];
$groupRows = [];
$planRows = [];
$reportRows = [];

if ($view === 'overview') {
    $from = (string) ($_GET['from'] ?? date('Y-m-d', strtotime('-6 days')));
    $to = (string) ($_GET['to'] ?? $today);
    $grain = (string) ($_GET['grain'] ?? 'daily');
    if (!in_array($grain, ['daily','weekly','monthly'], true)) $grain = 'daily';
    if (!daycare_valid_date($from) || !daycare_valid_date($to) || $from > $to) { $from = date('Y-m-d', strtotime('-6 days')); $to = $today; }
    $dashboard = daycare_dashboard_data($pdo, $from, $to, $grain);
} elseif ($view === 'children') {
    $selectedId = (int) ($_GET['child'] ?? 0);
    if ($selectedId) {
        $stmt = $pdo->prepare("SELECT c.*,g.name AS group_name,p.name AS fee_plan_name,p.billing_period,p.amount AS plan_amount, d.full_name AS guardian_name,d.phone AS guardian_phone,d.alternate_phone,d.email AS guardian_email,d.relationship AS guardian_relationship, TIMESTAMPDIFF(YEAR,c.date_of_birth,CURDATE()) AS age FROM daycare_children c LEFT JOIN daycare_groups g ON g.id=c.group_id LEFT JOIN daycare_fee_plans p ON p.id=c.fee_plan_id LEFT JOIN daycare_guardians d ON d.id=c.primary_guardian_id WHERE c.id=:id");
        $stmt->execute(['id' => $selectedId]);
        $selectedChild = $stmt->fetch() ?: null;
        if ($selectedChild) {
            $stmt = $pdo->prepare('SELECT g.*,cg.relationship,cg.is_primary FROM daycare_child_guardians cg JOIN daycare_guardians g ON g.id=cg.guardian_id WHERE cg.child_id=:child ORDER BY cg.is_primary DESC,g.full_name');
            $stmt->execute(['child' => $selectedId]); $childGuardians = $stmt->fetchAll();
            $stmt = $pdo->prepare("SELECT * FROM daycare_payments WHERE child_id=:child ORDER BY payment_date DESC LIMIT 20");
            $stmt->execute(['child' => $selectedId]); $childPayments = $stmt->fetchAll();
        }
    }
    $sql = "SELECT c.id,c.admission_no,c.full_name,c.date_of_birth,c.enrollment_status,g.name AS group_name,d.full_name AS guardian_name,d.phone AS guardian_phone,TIMESTAMPDIFF(YEAR,c.date_of_birth,CURDATE()) AS age
        FROM daycare_children c LEFT JOIN daycare_groups g ON g.id=c.group_id LEFT JOIN daycare_guardians d ON d.id=c.primary_guardian_id";
    $countSql = 'SELECT COUNT(*) FROM daycare_children c LEFT JOIN daycare_guardians d ON d.id=c.primary_guardian_id';
    $params = [];
    if ($search !== '') {
        $where = ' WHERE c.full_name LIKE :q_child_name OR c.admission_no LIKE :q_admission OR d.full_name LIKE :q_guardian_name OR d.phone LIKE :q_phone';
        $sql .= $where;
        $countSql .= $where;
        $searchLike = '%' . $search . '%';
        $params = ['q_child_name' => $searchLike, 'q_admission' => $searchLike, 'q_guardian_name' => $searchLike, 'q_phone' => $searchLike];
    }
    $countStmt = $pdo->prepare($countSql); $countStmt->execute($params); $childrenTotal = (int) $countStmt->fetchColumn();
    $childrenPage = min($childrenPage, max(1, (int) ceil($childrenTotal / $childrenPerPage)));
    $offset = ($childrenPage - 1) * $childrenPerPage;
    $sql .= ' ORDER BY c.full_name LIMIT ' . $childrenPerPage . ' OFFSET ' . $offset;
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $children = $stmt->fetchAll();
} elseif ($view === 'attendance') {
    $stmt = $pdo->prepare("SELECT c.id,c.full_name,c.admission_no,g.name AS group_name,a.id AS attendance_id,a.status,a.check_in_at,a.check_out_at,a.brought_by,a.collected_by FROM daycare_children c LEFT JOIN daycare_groups g ON g.id=c.group_id LEFT JOIN daycare_attendance a ON a.child_id=c.id AND a.attendance_date=:today WHERE c.enrollment_status='Active' ORDER BY g.name,c.full_name");
    $stmt->execute(['today' => $today]); $attendanceRows = $stmt->fetchAll();
    $pickupsForAttendance = $pdo->query("SELECT id,child_id,full_name,relationship,phone FROM daycare_authorized_pickups WHERE authorization_status='Authorized' ORDER BY full_name")->fetchAll();
} elseif ($view === 'payments') {
    $payments = $pdo->query("SELECT p.*,c.full_name,c.admission_no,g.full_name AS guardian_name FROM daycare_payments p JOIN daycare_children c ON c.id=p.child_id LEFT JOIN daycare_guardians g ON g.id=p.guardian_id ORDER BY p.payment_date DESC LIMIT 100")->fetchAll();
} elseif ($view === 'parents') {
    $guardians = $pdo->query("SELECT g.*,GROUP_CONCAT(DISTINCT CONCAT(c.full_name,' (',c.admission_no,')') ORDER BY c.full_name SEPARATOR ', ') AS children,COUNT(DISTINCT c.id) AS child_count
        FROM daycare_guardians g LEFT JOIN daycare_child_guardians cg ON cg.guardian_id=g.id LEFT JOIN daycare_children c ON c.id=cg.child_id
        GROUP BY g.id ORDER BY g.full_name LIMIT 200")->fetchAll();
} elseif ($view === 'pickups') {
    $stmt = $pdo->prepare("SELECT ap.*,c.full_name AS child_name,c.admission_no FROM daycare_authorized_pickups ap JOIN daycare_children c ON c.id=ap.child_id ORDER BY ap.authorization_status,c.full_name,ap.full_name LIMIT 200");
    $stmt->execute(); $pickups = $stmt->fetchAll();
} elseif ($view === 'care' || $view === 'feeding') {
    $filter = $view === 'feeding' ? "AND r.record_type='Meal'" : "AND r.record_type<>'Meal'";
    $carePrivacy = $can('daycare.health.view') ? '' : " AND r.record_type NOT IN ('Health','Incident')";
    $careRows = $pdo->query("SELECT r.*,c.full_name,c.admission_no,a.name AS staff_name FROM daycare_care_records r JOIN daycare_children c ON c.id=r.child_id LEFT JOIN admin a ON a.id=r.recorded_by WHERE r.record_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY) {$filter} {$carePrivacy} ORDER BY r.record_date DESC,r.created_at DESC LIMIT 150")->fetchAll();
} elseif ($view === 'expenses') {
    $expenseRows = $pdo->query("SELECT e.*,a.name AS staff_name FROM daycare_expenses e LEFT JOIN admin a ON a.id=e.recorded_by ORDER BY e.expense_date DESC,e.id DESC LIMIT 150")->fetchAll();
    $expenseTotal = (float) $pdo->query('SELECT COALESCE(SUM(amount),0) FROM daycare_expenses WHERE expense_date >= DATE_SUB(CURDATE(),INTERVAL 30 DAY)')->fetchColumn();
} elseif ($view === 'staff') {
    $staffRows = $pdo->query("SELECT s.*,g.name AS group_name,a.check_in_at,a.check_out_at FROM daycare_staff s LEFT JOIN daycare_groups g ON g.id=s.group_id LEFT JOIN daycare_staff_attendance a ON a.staff_id=s.id AND a.attendance_date=CURDATE() ORDER BY s.status,s.full_name")->fetchAll();
} elseif ($view === 'fee-plans') {
    $planRows = $pdo->query("SELECT p.*,(SELECT COUNT(*) FROM daycare_children c WHERE c.fee_plan_id=p.id AND c.enrollment_status='Active') AS child_count FROM daycare_fee_plans p ORDER BY p.status,p.name")->fetchAll();
} elseif ($view === 'settings') {
    $groupRows = $pdo->query("SELECT g.*,(SELECT COUNT(*) FROM daycare_children c WHERE c.group_id=g.id AND c.enrollment_status='Active') AS child_count FROM daycare_groups g ORDER BY g.status,g.name")->fetchAll();
    $planRows = $pdo->query("SELECT p.*,(SELECT COUNT(*) FROM daycare_children c WHERE c.fee_plan_id=p.id AND c.enrollment_status='Active') AS child_count FROM daycare_fee_plans p ORDER BY p.status,p.name")->fetchAll();
} elseif ($view === 'reports') {
    $reportType = 'children';
    $from = (string) ($_GET['from'] ?? date('Y-m-01'));
    $to = (string) ($_GET['to'] ?? $today);
    if (!daycare_valid_date($from) || !daycare_valid_date($to) || $from > $to) { $from = date('Y-m-01'); $to = $today; }
    $stmt = $pdo->prepare("SELECT c.admission_no,c.full_name,c.date_of_birth,TIMESTAMPDIFF(YEAR,c.date_of_birth,CURDATE()) AS age,g.name AS group_name,d.full_name AS guardian_name,d.phone AS guardian_phone,c.enrollment_status FROM daycare_children c LEFT JOIN daycare_groups g ON g.id=c.group_id LEFT JOIN daycare_guardians d ON d.id=c.primary_guardian_id WHERE c.admission_date BETWEEN :from_date AND :to_date ORDER BY c.full_name");
    $stmt->execute(['from_date' => $from, 'to_date' => $to]);
    $reportRows = $stmt->fetchAll();
    if (($_GET['export'] ?? '') === 'csv') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="daycare-' . $reportType . '-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'w');
        $csvSafe = static function ($value): string {
            $value = (string) ($value ?? '');
            return preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value;
        };
        if ($reportRows) {
            fputcsv($out, array_keys($reportRows[0]));
            foreach ($reportRows as $row) fputcsv($out, array_map($csvSafe, array_values($row)));
        } else {
            fputcsv($out, ['No records for selected date range']);
        }
        fclose($out);
        exit;
    }
}

$pageTitle = 'Daycare';
require __DIR__ . '/../includes/admin_header.php';
?>
<div class="daycare-page">
    <div class="daycare-heading">
        <div class="daycare-title-wrap"><span class="daycare-mark"><i class="fa-solid fa-baby" aria-hidden="true"></i></span><div><span class="daycare-kicker">Daycare fee collection</span><h1><?= h(ucwords(str_replace('-', ' ', $view)) === 'Overview' ? 'Daycare' : ucwords(str_replace('-', ' ', $view))) ?></h1><p>Manage child accounts, fee plans, payments, and receipts.</p></div></div>
        <div class="daycare-heading-actions d-flex gap-2 flex-wrap">
            <?php if ($can('daycare.children.manage')): ?><a class="btn btn-primary" href="<?= h($daycareUrl('children')) ?>#register-child"><i class="fa-solid fa-plus me-1"></i> Register child</a><?php endif; ?>
            <?php if ($can('daycare.payments.record')): ?><a class="btn btn-outline-primary" href="<?= h(url('admin/payment_allocation.php')) ?>"><i class="fa-solid fa-people-arrows-left-right me-1"></i> Combined payment</a><?php endif; ?>
        </div>
    </div>
    <nav class="daycare-nav" aria-label="Daycare pages">
        <?php foreach ($nav as [$target,$label,$icon,$permission]): ?>
            <?php if ($permission === '' || $can($permission)): ?><a class="<?= $view === $target ? 'active' : '' ?>" href="<?= h($daycareUrl($target)) ?>" <?= $view === $target ? 'aria-current="page"' : '' ?>><i class="fa-solid <?= h($icon) ?>" aria-hidden="true"></i><?= h($label) ?></a><?php endif; ?>
        <?php endforeach; ?>
    </nav>

    <?php if ($view === 'overview'): ?>
        <?php $summary = $dashboard['summary']; ?>
        <div class="daycare-panel mb-3">
            <form class="daycare-date-filter" method="get">
                <input type="hidden" name="view" value="overview">
                <div><label class="form-label" for="dc-from">Revenue from</label><input class="form-control" type="date" id="dc-from" name="from" value="<?= h($from) ?>"></div>
                <div><label class="form-label" for="dc-to">Through</label><input class="form-control" type="date" id="dc-to" name="to" value="<?= h($to) ?>"></div>
                <div><label class="form-label" for="dc-grain">Chart interval</label><select class="form-select" id="dc-grain" name="grain"><option value="daily" <?= $grain==='daily'?'selected':'' ?>>Daily</option><option value="weekly" <?= $grain==='weekly'?'selected':'' ?>>Weekly</option><option value="monthly" <?= $grain==='monthly'?'selected':'' ?>>Monthly</option></select></div>
                <button class="btn btn-outline-primary" type="submit">Update dashboard</button>
            </form>
        </div>
        <div class="daycare-overview-top">
            <section class="daycare-kpis daycare-overview-kpis" aria-label="Daycare summary">
                <?php $kpis = [['fa-child','Total Children',(string) (int) $summary['children'],'Active accounts']]; if ($can('daycare.payments.view')) { $kpis[] = ['fa-wallet','Payments Collected',money((float) $summary['collected']),$from . ' to ' . $to]; } foreach ($kpis as [$icon,$label,$value,$note]): ?>
                    <article class="daycare-kpi"><span class="daycare-kpi-icon"><i class="fa-solid <?= h($icon) ?>"></i></span><span class="daycare-kpi-label"><?= h($label) ?></span><strong class="daycare-kpi-value"><?= h($value) ?></strong><span class="daycare-kpi-note"><?= h($note) ?></span></article>
                <?php endforeach; ?>
            </section>
            <section class="daycare-panel daycare-overview-quick">
                <div class="daycare-panel-head"><h2><i class="fa-solid fa-bolt"></i> Quick Actions</h2></div>
                <div class="daycare-quick-grid">
                    <?php if ($can('daycare.children.manage')): ?><a class="daycare-quick" href="<?= h($daycareUrl('children')) ?>#register-child"><i class="fa-solid fa-user-plus"></i>Register Child</a><?php endif; ?>
                    <?php if ($can('daycare.payments.record')): ?><a class="daycare-quick" href="<?= h($daycareUrl('payments')) ?>#record-payment"><i class="fa-solid fa-credit-card"></i>Record Payment</a><?php endif; ?>
                    <?php if ($can('daycare.payments.record')): ?><a class="daycare-quick" href="<?= h(url('admin/payment_allocation.php')) ?>"><i class="fa-solid fa-people-arrows-left-right"></i>Combined Payment</a><?php endif; ?>
                    <?php if ($can('reports.access')): ?><a class="daycare-quick" href="<?= h(url('admin/reports.php')) ?>"><i class="fa-solid fa-chart-line"></i>Reports</a><?php endif; ?>
                </div>
            </section>
        </div>
        <div class="daycare-overview-content">
            <div class="daycare-overview-primary">
                <?php if ($can('daycare.payments.view')): ?><section class="daycare-panel daycare-overview-revenue"><div class="daycare-panel-head"><h2><i class="fa-solid fa-chart-column"></i> Daycare Revenue</h2><small><?= h($from) ?> – <?= h($to) ?></small></div><div class="daycare-chart"><canvas id="daycareRevenueChart" aria-label="Daycare revenue by date" role="img"></canvas></div></section><?php endif; ?>
                <section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid fa-receipt"></i> Recent Payments</h2><a href="<?= h($daycareUrl('payments')) ?>">View all</a></div>
                    <?php if (!$can('daycare.payments.view')): ?><div class="daycare-empty">Payment details are restricted to authorized finance staff.</div><?php elseif (!$dashboard['payments']): ?><div class="daycare-empty">No daycare payments recorded in this date range.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Date</th><th>Child</th><th>Method</th><th>Receipt</th><th>Amount</th></tr></thead><tbody><?php foreach ($dashboard['payments'] as $p): ?><tr><td><?= h(date('d M Y',strtotime($p['payment_date']))) ?></td><td><?= h($p['full_name']) ?></td><td><?= h($p['payment_method']) ?></td><td><a href="<?= h(url('admin/daycare_receipt.php?id='.(int)$p['id'])) ?>"><?= h($p['receipt_no']) ?></a></td><td class="daycare-number"><?= h(money((float)$p['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
                </section>
            </div>
            <div class="daycare-overview-secondary">
                <?php if ($can('daycare.payments.view')): ?><section class="daycare-panel"><div class="daycare-panel-head"><h3><i class="fa-solid fa-wallet"></i> Payment Methods</h3></div>
                    <?php $methodTotal = array_sum(array_map(static fn($r)=>(float)$r['total'],$dashboard['methods'])); if (!$dashboard['methods']): ?><div class="daycare-empty">No payments in this date range.</div><?php else: foreach ($dashboard['methods'] as $m): ?><div class="daycare-mini-row"><span><strong><?= h($m['payment_method']) ?></strong><small><?= $methodTotal > 0 ? number_format(((float)$m['total']/$methodTotal)*100,1) : '0.0' ?>% of collected</small></span><b class="daycare-number"><?= h(money((float)$m['total'])) ?></b></div><?php endforeach; endif; ?>
                </section><?php endif; ?>
                <section class="daycare-panel"><div class="daycare-panel-head"><h3><i class="fa-solid fa-people-group"></i> Daycare Groups</h3><a href="<?= h($daycareUrl('settings')) ?>">Manage</a></div>
                    <?php if (!$dashboard['groups']): ?><div class="daycare-empty">Create a daycare group to get started.</div><?php else: foreach ($dashboard['groups'] as $g): ?><div class="daycare-mini-row"><span><strong><?= h($g['name']) ?></strong><small><?= (int)$g['children'] ?> enrolled</small></span></div><?php endforeach; endif; ?>
                </section>
                <section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</h2></div>
                    <?php if (!$dashboard['activity']): ?><div class="daycare-empty">Daycare activity will appear here as records are added.</div><?php else: ?><div class="daycare-mini-list"><?php foreach ($dashboard['activity'] as $a): $details=json_decode((string)$a['details'],true) ?: []; ?><div class="daycare-mini-row"><span><strong><?= h(ucwords(strtolower(str_replace('_',' ',$a['action'])))) ?></strong><small><?= h($a['entity_type']) ?><?= isset($details['receipt_no']) ? ' · '.h($details['receipt_no']) : '' ?></small></span><time><?= h(date('M j, g:i A',strtotime($a['created_at']))) ?></time></div><?php endforeach; ?></div><?php endif; ?>
                </section>
            </div>
        </div>
        <script>
        document.addEventListener('DOMContentLoaded',function(){
            const canvas=document.getElementById('daycareRevenueChart');
            if(!canvas||!window.Chart)return;
            const rows=<?= json_encode($dashboard['chart'], JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT) ?>;
            const chart=new Chart(canvas,{type:'bar',data:{labels:rows.map(r=>r.day),datasets:[{label:'Payments received',data:rows.map(r=>Number(r.total)),backgroundColor:'#4ca8df',borderRadius:5,maxBarThickness:34}]},options:{maintainAspectRatio:false,responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:c=>'KES '+Number(c.raw).toLocaleString('en-KE',{minimumFractionDigits:2})}}},scales:{x:{grid:{display:false},ticks:{maxRotation:0}},y:{beginAtZero:true,ticks:{callback:v=>'KES '+Number(v).toLocaleString()},grid:{color:'#edf2f6'}}}}});
            const syncChartTheme=()=>{
                const dark=document.documentElement.dataset.theme==='dark';
                const tickColor=dark?'#aaa79f':'#76869a';
                chart.options.scales.x.ticks.color=tickColor;
                chart.options.scales.y.ticks.color=tickColor;
                chart.options.scales.y.grid.color=dark?'#302f2b':'#edf2f6';
                chart.update('none');
            };
            syncChartTheme();
            new MutationObserver(syncChartTheme).observe(document.documentElement,{attributes:true,attributeFilter:['data-theme']});
        });
        </script>
    <?php elseif ($view === 'children'): ?>
        <?php if ($selectedChild): ?>
            <section class="daycare-panel mb-3" id="profile"><div class="daycare-panel-head"><h2><i class="fa-solid fa-child"></i> <?= h($selectedChild['full_name']) ?></h2><a class="btn btn-sm btn-outline-primary" href="<?= h($daycareUrl('children')) ?>">Back to children</a></div>
                <div class="daycare-child-profile">
                    <div class="daycare-detail"><span>Admission number</span><strong><?= h($selectedChild['admission_no']) ?></strong></div>
                    <div class="daycare-detail"><span>Age / birthday</span><strong><?= (int)$selectedChild['age'] ?> years · <?= h($selectedChild['date_of_birth']) ?></strong></div>
                    <div class="daycare-detail"><span>Group / status</span><strong><?= h($selectedChild['group_name'] ?? 'Unassigned') ?> · <?= h($selectedChild['enrollment_status']) ?></strong></div>
                    <div class="daycare-detail"><span>Primary guardian</span><strong><?= h($selectedChild['guardian_name'] ?? 'Not recorded') ?><small class="subtle"><?= h($selectedChild['guardian_phone'] ?? '') ?></small></strong></div>
                    <?php if ($can('daycare.payments.view')): ?><div class="daycare-detail"><span>Fee plan</span><strong><?= h($selectedChild['fee_plan_name'] ?? 'No plan assigned') ?><small class="subtle"><?= $selectedChild['plan_amount'] !== null ? h(money((float)$selectedChild['plan_amount'])) . ' / ' . h($selectedChild['billing_period']) : '' ?></small></strong></div><?php endif; ?>
                    <div class="daycare-detail"><span>Emergency contact</span><strong><?= h($selectedChild['emergency_contact_name'] ?? 'Not recorded') ?><small class="subtle"><?= h($selectedChild['emergency_contact_phone'] ?? '') ?></small></strong></div>
                    <?php if ($can('daycare.health.view')): ?>
                        <div class="daycare-detail"><span>Allergies</span><strong><?= h($selectedChild['allergies'] ?: 'None recorded') ?></strong></div>
                        <div class="daycare-detail"><span>Medical conditions</span><strong><?= h($selectedChild['medical_conditions'] ?: 'None recorded') ?></strong></div>
                        <div class="daycare-detail"><span>Special care</span><strong><?= h($selectedChild['special_care_instructions'] ?: 'None recorded') ?></strong></div>
                    <?php endif; ?>
                </div>
                <?php if ($can('daycare.children.manage')): ?><details class="mt-3"><summary class="fw-bold">Edit child and primary guardian</summary><form method="post" class="daycare-form-grid mt-3"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="update_child"><input type="hidden" name="child_id" value="<?= (int)$selectedChild['id'] ?>"><div><label class="form-label">Child name</label><input class="form-control" name="full_name" value="<?= h($selectedChild['full_name']) ?>" required></div><div><label class="form-label">Admission number</label><input class="form-control" name="admission_no" value="<?= h($selectedChild['admission_no']) ?>" required></div><div><label class="form-label">Date of birth</label><input class="form-control" type="date" name="date_of_birth" value="<?= h($selectedChild['date_of_birth']) ?>" required></div><div><label class="form-label">Gender</label><select class="form-select" name="gender"><option value="">Not specified</option><option <?= $selectedChild['gender']==='Female'?'selected':'' ?>>Female</option><option <?= $selectedChild['gender']==='Male'?'selected':'' ?>>Male</option><option <?= $selectedChild['gender']==='Other'?'selected':'' ?>>Other</option></select></div><div><label class="form-label">Admission date</label><input class="form-control" type="date" name="admission_date" value="<?= h($selectedChild['admission_date']) ?>" required></div><div><label class="form-label">Group</label><select class="form-select" name="group_id"><option value="">Unassigned</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>" <?= (int)$selectedChild['group_id']===(int)$g['id']?'selected':'' ?>><?= h($g['name']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Guardian name</label><input class="form-control" name="guardian_name" value="<?= h($selectedChild['guardian_name'] ?? '') ?>" required></div><div><label class="form-label">Guardian phone</label><input class="form-control" name="guardian_phone" value="<?= h($selectedChild['guardian_phone'] ?? '') ?>" required></div><div><label class="form-label">Alternative phone</label><input class="form-control" name="guardian_alternate_phone" value="<?= h($selectedChild['alternate_phone'] ?? '') ?>"></div><div><label class="form-label">Guardian email</label><input class="form-control" name="guardian_email" value="<?= h($selectedChild['guardian_email'] ?? '') ?>" type="email"></div><div><label class="form-label">Relationship</label><input class="form-control" name="guardian_relationship" value="<?= h($selectedChild['guardian_relationship'] ?? '') ?>"></div><div><label class="form-label">Fee plan</label><select class="form-select" name="fee_plan_id"><option value="">No plan</option><?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$selectedChild['fee_plan_id']===(int)$p['id']?'selected':'' ?>><?= h($p['name']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Emergency contact name</label><input class="form-control" name="emergency_contact_name" value="<?= h($selectedChild['emergency_contact_name'] ?? '') ?>"></div><div><label class="form-label">Emergency contact phone</label><input class="form-control" name="emergency_contact_phone" value="<?= h($selectedChild['emergency_contact_phone'] ?? '') ?>"></div><div class="wide"><label class="form-label">Address</label><input class="form-control" name="address" value="<?= h($selectedChild['address'] ?? '') ?>"></div><div><label class="form-label">Allergies</label><textarea class="form-control" name="allergies"><?= h($selectedChild['allergies'] ?? '') ?></textarea></div><div><label class="form-label">Medical conditions</label><textarea class="form-control" name="medical_conditions"><?= h($selectedChild['medical_conditions'] ?? '') ?></textarea></div><div><label class="form-label">Special care instructions</label><textarea class="form-control" name="special_care_instructions"><?= h($selectedChild['special_care_instructions'] ?? '') ?></textarea></div><div><label class="form-label">Enrollment status</label><select class="form-select" name="enrollment_status"><option <?= $selectedChild['enrollment_status']==='Active'?'selected':'' ?>>Active</option><option <?= $selectedChild['enrollment_status']==='Inactive'?'selected':'' ?>>Inactive</option><option <?= $selectedChild['enrollment_status']==='Left'?'selected':'' ?>>Left</option></select></div><div class="wide"><button class="btn btn-primary">Save profile changes</button></div></form></details><?php endif; ?>
            </section>
            <div class="daycare-grid">
                <section class="daycare-panel"><div class="daycare-panel-head"><h3>Parents & Guardians</h3></div><?php if (!$childGuardians): ?><div class="daycare-empty">No linked guardians.</div><?php else: foreach ($childGuardians as $g): ?><div class="daycare-mini-row"><span><strong><?= h($g['full_name']) ?><?= $g['is_primary'] ? ' · Primary' : '' ?></strong><small><?= h($g['relationship'] ?? '') ?> · <?= h($g['phone']) ?></small></span></div><?php endforeach; endif; ?></section>
                <?php if ($can('daycare.payments.view')): ?><section class="daycare-panel"><div class="daycare-panel-head"><h3>Payment History</h3></div><?php if (!$childPayments): ?><div class="daycare-empty">No payments recorded.</div><?php else: foreach (array_slice($childPayments,0,5) as $p): ?><div class="daycare-mini-row"><span><strong><?= h(money((float)$p['amount'])) ?> · <?= h($p['receipt_no']) ?></strong><small><?= h(date('d M Y',strtotime($p['payment_date']))) ?> · <?= h($p['payment_method']) ?></small></span><span class="daycare-status <?= $p['payment_status']==='Reversed'?'off':'' ?>"><?= h($p['payment_status']) ?></span></div><?php endforeach; endif; ?></section><?php endif; ?>
            </div>
        <?php else: ?>
            <?php if ($can('daycare.children.manage')): ?><section class="daycare-panel mb-3" id="register-child"><details><summary class="fw-bold"><i class="fa-solid fa-user-plus me-2"></i>Register a child</summary><form method="post" class="mt-3"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="register_child"><div class="daycare-form-grid">
                <div><label class="form-label">Full name *</label><input class="form-control" name="full_name" required maxlength="150"></div><div><label class="form-label">Admission number</label><input class="form-control" name="admission_no" placeholder="Generated automatically if blank"></div>
                <div><label class="form-label">Date of birth *</label><input class="form-control" type="date" name="date_of_birth" required max="<?= h($today) ?>"></div><div><label class="form-label">Gender</label><select class="form-select" name="gender"><option value="">Not specified</option><option>Female</option><option>Male</option><option>Other</option></select></div>
                <div><label class="form-label">Admission date *</label><input class="form-control" type="date" name="admission_date" value="<?= h($today) ?>" required max="<?= h($today) ?>"></div><div><label class="form-label">Daycare group</label><select class="form-select" name="group_id"><option value="">Unassigned</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?></select></div>
                <div><label class="form-label">Primary parent / guardian *</label><input class="form-control" name="guardian_name" required maxlength="150"></div><div><label class="form-label">Guardian phone *</label><input class="form-control" name="guardian_phone" required maxlength="40" inputmode="tel"></div>
                <div><label class="form-label">Alternative contact</label><input class="form-control" name="guardian_alternate_phone" maxlength="40" inputmode="tel"></div><div><label class="form-label">Guardian email</label><input class="form-control" type="email" name="guardian_email" maxlength="190"></div>
                <div><label class="form-label">Relationship</label><input class="form-control" name="guardian_relationship" placeholder="Parent, aunt, guardian"></div><div><label class="form-label">Fee plan</label><select class="form-select" name="fee_plan_id"><option value="">No plan assigned</option><?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>"><?= h($p['name']) ?> · <?= h(money((float)$p['amount'])) ?> / <?= h($p['billing_period']) ?></option><?php endforeach; ?></select></div>
                <div><label class="form-label">Emergency contact name</label><input class="form-control" name="emergency_contact_name"></div><div><label class="form-label">Emergency contact phone</label><input class="form-control" name="emergency_contact_phone" inputmode="tel"></div>
                <div class="wide"><label class="form-label">Address</label><input class="form-control" name="address" maxlength="255"></div><div><label class="form-label">Allergies</label><textarea class="form-control" name="allergies"></textarea></div><div><label class="form-label">Medical conditions</label><textarea class="form-control" name="medical_conditions"></textarea></div><div class="wide"><label class="form-label">Special care instructions</label><textarea class="form-control" name="special_care_instructions"></textarea></div>
                <div><label class="form-label">Enrollment status</label><select class="form-select" name="enrollment_status"><option>Active</option><option>Inactive</option></select></div><div class="d-flex align-items-end"><button class="btn btn-primary" type="submit">Save child record</button></div>
            </div></form></details></section><?php endif; ?>
            <section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid fa-children"></i> Children</h2><small><?= $childrenTotal ?> record<?= $childrenTotal===1?'':'s' ?> · page <?= $childrenPage ?> of <?= max(1,(int)ceil($childrenTotal/$childrenPerPage)) ?></small></div><form method="get" class="d-flex gap-2 mb-3"><input type="hidden" name="view" value="children"><input class="form-control" name="q" value="<?= h($search) ?>" placeholder="Search child, admission number, guardian, or phone"><button class="btn btn-outline-primary">Search</button></form>
                <?php if (!$children): ?><div class="daycare-empty">No children found. Register the first child above.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Admission</th><th>Child</th><th>Age</th><th>Group</th><th>Parent / Guardian</th><th>Status</th><th></th></tr></thead><tbody><?php foreach ($children as $c): ?><tr><td><?= h($c['admission_no']) ?></td><td><strong><?= h($c['full_name']) ?></strong></td><td><?= (int)$c['age'] ?></td><td><?= h($c['group_name'] ?? 'Unassigned') ?></td><td><?= h($c['guardian_name'] ?? '—') ?><span class="subtle"><?= h($c['guardian_phone'] ?? '') ?></span></td><td><span class="daycare-status <?= $c['enrollment_status']==='Active'?'':'off' ?>"><?= h($c['enrollment_status']) ?></span></td><td><a href="<?= h($daycareUrl('children',['child'=>(int)$c['id']])) ?>">Profile</a></td></tr><?php endforeach; ?></tbody></table></div><nav class="d-flex justify-content-between align-items-center pt-3" aria-label="Children pages"><span class="small text-muted">Showing <?= $childrenTotal ? ($offset+1) : 0 ?>–<?= $offset+count($children) ?> of <?= $childrenTotal ?></span><div class="daycare-actions"><?php if ($childrenPage>1): ?><a class="btn btn-sm btn-outline-primary" href="<?= h($daycareUrl('children',['q'=>$search,'page'=>$childrenPage-1])) ?>">Previous</a><?php endif; ?><?php if ($childrenPage<(int)ceil($childrenTotal/$childrenPerPage)): ?><a class="btn btn-sm btn-outline-primary" href="<?= h($daycareUrl('children',['q'=>$search,'page'=>$childrenPage+1])) ?>">Next</a><?php endif; ?></div></nav><?php endif; ?>
            </section>
        <?php endif; ?>
    <?php elseif ($view === 'attendance'): ?>
        <section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid fa-calendar-check"></i> Today's Check In & Check Out</h2><span class="daycare-status"><?= h(date('D, d M Y')) ?></span></div><p class="muted">For safety, check-out requires an active authorized pickup person linked to that child. Attendance entries are unique per child and date.</p>
            <?php if (!$attendanceRows): ?><div class="daycare-empty">No active children are registered.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Child</th><th>Group</th><th>Status</th><th>Arrival</th><th>Departure</th><th>Arrival action</th><th>Pickup authorization</th></tr></thead><tbody>
            <?php foreach ($attendanceRows as $c): ?><tr><td><strong><?= h($c['full_name']) ?></strong><span class="subtle"><?= h($c['admission_no']) ?></span></td><td><?= h($c['group_name'] ?? 'Unassigned') ?></td><td><?php if (!$c['attendance_id']): ?><span class="daycare-status waiting">Not recorded</span><?php else: ?><span class="daycare-status <?= $c['status']==='Absent'?'off':'' ?>"><?= h($c['status']) ?></span><?php endif; ?></td><td><?= $c['check_in_at'] ? h(date('g:i A',strtotime($c['check_in_at']))) : '—' ?></td><td><?= $c['check_out_at'] ? h(date('g:i A',strtotime($c['check_out_at']))) : '—' ?></td><td><?php if (!$c['attendance_id']): ?><div class="daycare-actions"><form method="post"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="attendance"><input type="hidden" name="attendance_action" value="check_in"><input type="hidden" name="child_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="attendance_date" value="<?= h($today) ?>"><input class="form-control form-control-sm mb-1" name="brought_by" placeholder="Brought by" aria-label="Person bringing child"><button class="btn btn-sm btn-primary">Check in</button></form><form method="post"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="attendance"><input type="hidden" name="attendance_action" value="mark_absent"><input type="hidden" name="child_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="attendance_date" value="<?= h($today) ?>"><button class="btn btn-sm btn-outline-secondary">Mark absent</button></form></div><?php endif; ?></td><td><?php if ($c['check_in_at'] && !$c['check_out_at']): ?><form method="post" class="d-flex gap-1"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="attendance"><input type="hidden" name="attendance_action" value="check_out"><input type="hidden" name="child_id" value="<?= (int)$c['id'] ?>"><input type="hidden" name="attendance_date" value="<?= h($today) ?>"><select class="form-select form-select-sm" name="pickup_person_id" required aria-label="Verified pickup person"><option value="">Authorized person</option><?php foreach ($pickupsForAttendance as $person): if ((int)$person['child_id'] === (int)$c['id']): ?><option value="<?= (int)$person['id'] ?>"><?= h($person['full_name']) ?> · <?= h($person['phone']) ?></option><?php endif; endforeach; ?></select><button class="btn btn-sm btn-outline-primary">Release</button></form><?php elseif ($c['check_out_at']): ?><span class="subtle">Collected by <?= h($c['collected_by'] ?? 'authorized person') ?></span><?php else: ?>—<?php endif; ?></td></tr><?php endforeach; ?>
            </tbody></table></div><?php endif; ?>
        </section>
    <?php elseif ($view === 'payments'): ?>
        <div class="daycare-forms">
            <?php if ($can('daycare.payments.record')): ?><section class="daycare-panel" id="record-payment"><div class="daycare-panel-head"><h2><i class="fa-solid fa-credit-card"></i> Record Payment</h2></div><form method="post"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="record_payment"><div class="daycare-form-grid"><div class="wide"><label class="form-label">Child *</label><select class="form-select" name="child_id" required><option value="">Select child</option><?php foreach ($activeChildren as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['full_name']) ?> · <?= h($c['admission_no']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Date *</label><input class="form-control" type="date" name="payment_date" value="<?= h($today) ?>" required></div><div><label class="form-label">Amount received (KES) *</label><input class="form-control" type="number" name="amount" min="0.01" step="0.01" required></div><div><label class="form-label">Payment method *</label><select class="form-select" name="payment_method" required><option value="">Select method</option><option>M-PESA</option><option>Cash</option><option>Bank Transfer</option><option>Cheque</option><option>Other</option></select></div><div><label class="form-label">M-PESA / payment reference</label><input class="form-control" name="payment_reference" maxlength="100"></div><div><label class="form-label">Fee category</label><input class="form-control" name="category" value="Daycare fees"></div><div><label class="form-label">Billing period</label><input class="form-control" name="billing_period" placeholder="e.g. November 2026"></div><div class="wide"><label class="form-label">Notes</label><input class="form-control" name="notes" maxlength="500"></div><div class="wide"><button class="btn btn-primary">Record payment & issue receipt</button></div></div></form></section><?php endif; ?>
        </div>
        <?php if ($can('daycare.payments.view')): ?><section class="daycare-panel mb-3"><div class="daycare-panel-head"><h2>Recent Daycare Payments</h2><small>Latest 100 records</small></div><?php if (!$payments): ?><div class="daycare-empty">No daycare payments have been recorded.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Date</th><th>Child</th><th>Category / Period</th><th>Method / Reference</th><th>Receipt</th><th>Amount</th><th>Status</th></tr></thead><tbody><?php foreach ($payments as $p): ?><tr><td><?= h(date('d M Y',strtotime($p['payment_date']))) ?></td><td><?= h($p['full_name']) ?><span class="subtle"><?= h($p['admission_no']) ?></span></td><td><?= h($p['category']) ?><span class="subtle"><?= h($p['billing_period'] ?? '') ?></span></td><td><?= h($p['payment_method']) ?><span class="subtle"><?= h($p['payment_reference'] ?? '') ?></span></td><td><a href="<?= h(url('admin/daycare_receipt.php?id='.(int)$p['id'])) ?>"><?= h($p['receipt_no']) ?></a></td><td class="daycare-number"><?= h(money((float)$p['amount'])) ?></td><td><span class="daycare-status <?= $p['payment_status']==='Reversed'?'off':'' ?>"><?= h($p['payment_status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section><?php endif; ?>
    <?php elseif ($view === 'parents'): ?>
        <section class="daycare-panel mb-3"><div class="daycare-panel-head"><h2><i class="fa-solid fa-people-roof"></i> Parents & Guardians</h2><small>Guardian phone is the duplicate check for sibling registration</small></div><?php if (!$guardians): ?><div class="daycare-empty">Parent and guardian records will appear when children are registered.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Name</th><th>Relationship</th><th>Phone</th><th>Alternative</th><th>Email</th><th>Linked children</th></tr></thead><tbody><?php foreach ($guardians as $g): ?><tr><td><strong><?= h($g['full_name']) ?></strong></td><td><?= h($g['relationship'] ?? '—') ?></td><td><?= h($g['phone']) ?></td><td><?= h($g['alternate_phone'] ?? '—') ?></td><td><?= h($g['email'] ?? '—') ?></td><td><?= h($g['children'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        <?php if ($can('daycare.guardians.manage')): ?><section class="daycare-panel"><div class="daycare-panel-head"><h2>Link Another Guardian</h2></div><p class="muted">A guardian can be linked to more than one child. Existing phone numbers reuse the same guardian record.</p><form method="post" class="daycare-form-grid"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_guardian_link"><div><label class="form-label">Child</label><select class="form-select" name="child_id" required><option value="">Select child</option><?php foreach ($activeChildren as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['full_name']) ?> · <?= h($c['admission_no']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Guardian name</label><input class="form-control" name="full_name" required></div><div><label class="form-label">Phone</label><input class="form-control" name="phone" required inputmode="tel"></div><div><label class="form-label">Alternative phone</label><input class="form-control" name="alternate_phone" inputmode="tel"></div><div><label class="form-label">Email</label><input class="form-control" name="email" type="email"></div><div><label class="form-label">Relationship</label><input class="form-control" name="relationship"></div><div class="wide"><button class="btn btn-primary">Link guardian</button></div></form></section><?php endif; ?>
    <?php elseif ($view === 'pickups'): ?>
        <?php if ($can('daycare.pickups.manage')): ?><section class="daycare-panel mb-3"><div class="daycare-panel-head"><h2><i class="fa-solid fa-person-circle-check"></i> Add Authorized Pickup</h2></div><form method="post" class="daycare-form-grid"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_pickup"><div><label class="form-label">Child</label><select class="form-select" name="child_id" required><option value="">Select child</option><?php foreach ($activeChildren as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)($_GET['child'] ?? 0)===(int)$c['id']?'selected':'' ?>><?= h($c['full_name']) ?> · <?= h($c['admission_no']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Full name</label><input class="form-control" name="full_name" required></div><div><label class="form-label">Relationship</label><input class="form-control" name="relationship"></div><div><label class="form-label">Phone</label><input class="form-control" name="phone" required inputmode="tel"></div><div><label class="form-label">ID / verification reference</label><input class="form-control" name="id_reference"></div><div><label class="form-label">Restrictions / notes</label><input class="form-control" name="restrictions"></div><div class="wide"><button class="btn btn-primary">Authorize pickup</button></div></form></section><?php endif; ?>
        <section class="daycare-panel"><div class="daycare-panel-head"><h2>Authorized Pickup Register</h2></div><?php if (!$pickups): ?><div class="daycare-empty">No authorized pickups registered.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Child</th><th>Pickup person</th><th>Relationship</th><th>Phone</th><th>ID reference</th><th>Status</th><th>Restrictions</th><th></th></tr></thead><tbody><?php foreach ($pickups as $p): ?><tr><td><?= h($p['child_name']) ?><span class="subtle"><?= h($p['admission_no']) ?></span></td><td><?= h($p['full_name']) ?></td><td><?= h($p['relationship'] ?? '—') ?></td><td><?= h($p['phone']) ?></td><td><?= h($p['id_reference'] ?? '—') ?></td><td><span class="daycare-status <?= $p['authorization_status']==='Revoked'?'off':'' ?>"><?= h($p['authorization_status']) ?></span></td><td><?= h($p['restrictions'] ?? '—') ?></td><td><?php if ($p['authorization_status']==='Authorized' && $can('daycare.pickups.manage')): ?><form method="post" onsubmit="return confirm('Revoke this pickup permission?');"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="revoke_pickup"><input type="hidden" name="pickup_id" value="<?= (int)$p['id'] ?>"><button class="btn btn-sm btn-outline-danger">Revoke</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
    <?php elseif ($view === 'care' || $view === 'feeding'): ?>
        <div class="daycare-grid">
            <?php if ($can('daycare.care.manage')): ?><section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid <?= $view==='feeding'?'fa-utensils':'fa-heart-pulse' ?>"></i> Record <?= $view==='feeding'?'Meal':'Daily Care' ?></h2></div><form method="post" class="daycare-form-grid"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_care"><div><label class="form-label">Child</label><select class="form-select" name="child_id" required><option value="">Select child</option><?php foreach ($activeChildren as $c): ?><option value="<?= (int)$c['id'] ?>"><?= h($c['full_name']) ?> · <?= h($c['admission_no']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Date</label><input class="form-control" type="date" name="record_date" value="<?= h($today) ?>" max="<?= h($today) ?>" required></div><div><label class="form-label">Record type</label><select class="form-select" name="record_type" required><?php if ($view==='feeding'): ?><option value="Meal">Meal / feeding</option><?php else: ?><option>Observation</option><option>Nap</option><option>Activity</option><option>Toileting</option><option>Health</option><option>Incident</option><option>Other</option><?php endif; ?></select></div><div class="wide"><label class="form-label"><?= $view==='feeding'?'Meal / amount / notes':'Care notes / incident details' ?></label><textarea class="form-control" name="details" required maxlength="4000"></textarea></div><div class="wide"><button class="btn btn-primary">Save record</button></div></form></section><?php endif; ?>
            <section class="daycare-panel"><div class="daycare-panel-head"><h2><?= $view==='feeding'?'Feeding Records':'Recent Care Records' ?></h2><small>Last 30 days · 150 maximum</small></div><?php if (!$careRows): ?><div class="daycare-empty">No <?= $view==='feeding'?'feeding':'care' ?> records found.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Date</th><th>Child</th><th>Type</th><th>Details</th><th>Recorded by</th></tr></thead><tbody><?php foreach ($careRows as $r): ?><tr><td><?= h($r['record_date']) ?></td><td><?= h($r['full_name']) ?><span class="subtle"><?= h($r['admission_no']) ?></span></td><td><?= h($r['record_type']) ?></td><td><?= h($r['details']) ?></td><td><?= h($r['staff_name'] ?? '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        </div>
    <?php elseif ($view === 'expenses'): ?>
        <div class="daycare-grid">
            <?php if ($can('daycare.expenses.manage')): ?><section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid fa-file-invoice-dollar"></i> Record Daycare Expense</h2></div><form method="post" class="daycare-form-grid"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_expense"><div><label class="form-label">Date</label><input class="form-control" type="date" name="expense_date" value="<?= h($today) ?>" required></div><div><label class="form-label">Category</label><select class="form-select" name="category"><option>Food and milk</option><option>Diapers and hygiene</option><option>Toys and learning</option><option>Caregiver salaries</option><option>Utilities</option><option>Cleaning supplies</option><option>Other</option></select></div><div class="wide"><label class="form-label">Description</label><input class="form-control" name="description" required></div><div><label class="form-label">Amount (KES)</label><input class="form-control" type="number" min="0.01" step="0.01" name="amount" required></div><div><label class="form-label">Payment method</label><select class="form-select" name="payment_method"><option value="">Not specified</option><option>Cash</option><option>M-PESA</option><option>Bank Transfer</option><option>Other</option></select></div><div class="wide"><label class="form-label">Reference</label><input class="form-control" name="reference"></div><div class="wide"><button class="btn btn-primary">Save expense</button></div></form><div class="daycare-alert mt-3 mb-0">Daycare expenses are tracked in the daycare ledger. They are not copied into the school's general expense module, preventing duplicate accounting entries.</div></section><?php endif; ?>
            <section class="daycare-panel"><div class="daycare-panel-head"><h2>Recent Expenses</h2><span class="daycare-number">Last 30 days: <?= h(money((float)($expenseTotal ?? 0))) ?></span></div><?php if (!$expenseRows): ?><div class="daycare-empty">No daycare expenses recorded.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Date</th><th>Category</th><th>Description</th><th>Method / Reference</th><th>Amount</th></tr></thead><tbody><?php foreach ($expenseRows as $e): ?><tr><td><?= h($e['expense_date']) ?></td><td><?= h($e['category']) ?></td><td><?= h($e['description']) ?></td><td><?= h($e['payment_method'] ?? '—') ?><span class="subtle"><?= h($e['reference'] ?? '') ?></span></td><td class="daycare-number"><?= h(money((float)$e['amount'])) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        </div>
    <?php elseif ($view === 'staff'): ?>
        <div class="daycare-grid">
            <section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid fa-user-nurse"></i> Add Staff Profile</h2></div><form method="post" class="daycare-form-grid"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_staff"><div><label class="form-label">Full name</label><input class="form-control" name="full_name" required></div><div><label class="form-label">Phone</label><input class="form-control" name="phone"></div><div><label class="form-label">Role</label><input class="form-control" name="job_title" value="Caregiver"></div><div><label class="form-label">Assigned group</label><select class="form-select" name="group_id"><option value="">Unassigned</option><?php foreach ($groups as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['name']) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Shift</label><input class="form-control" name="shift_label"></div><div><label class="form-label">Responsibilities</label><input class="form-control" name="responsibilities"></div><div class="wide"><button class="btn btn-primary">Add staff</button></div></form><div class="daycare-alert mt-3 mb-0">Staff access to the application remains controlled in the existing Users & Permissions section. A caregiver profile does not grant finance permissions.</div></section>
            <section class="daycare-panel"><div class="daycare-panel-head"><h2>Daycare Team</h2></div><?php if (!$staffRows): ?><div class="daycare-empty">No daycare staff profiles yet.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Staff member</th><th>Role / group</th><th>Shift</th><th>Today</th><th>Action</th></tr></thead><tbody><?php foreach ($staffRows as $s): ?><tr><td><strong><?= h($s['full_name']) ?></strong><span class="subtle"><?= h($s['phone'] ?? '') ?></span></td><td><?= h($s['job_title']) ?><span class="subtle"><?= h($s['group_name'] ?? 'Unassigned') ?></span></td><td><?= h($s['shift_label'] ?? '—') ?></td><td><?= $s['check_in_at'] ? h(date('g:i A',strtotime($s['check_in_at']))) : '—' ?><?= $s['check_out_at'] ? ' – '.h(date('g:i A',strtotime($s['check_out_at']))) : '' ?></td><td><?php if ($s['status']==='Active'): ?><form method="post" class="daycare-actions"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="staff_attendance"><input type="hidden" name="staff_id" value="<?= (int)$s['id'] ?>"><?php if (!$s['check_in_at']): ?><input type="hidden" name="staff_action" value="check_in"><button class="btn btn-sm btn-outline-primary">Clock in</button><?php elseif (!$s['check_out_at']): ?><input type="hidden" name="staff_action" value="check_out"><button class="btn btn-sm btn-outline-secondary">Clock out</button><?php else: ?><span class="daycare-status">Complete</span><?php endif; ?></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>
        </div>
    <?php elseif ($view === 'fee-plans'): ?>
        <section class="daycare-panel mb-3">
            <div class="daycare-panel-head"><h2><i class="fa-solid fa-file-invoice-dollar"></i> Fee Plans</h2><a class="btn btn-sm btn-outline-primary" href="<?= h($daycareUrl('settings')) ?>">Manage groups</a></div>
            <?php if (!$planRows): ?><div class="daycare-empty">No fee plans yet. Add a plan below.</div><?php else: ?>
                <div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><th>Plan</th><th>Period / fee</th><th>Services</th><th>Status</th><th>Children</th><th></th></tr></thead><tbody>
                <?php foreach ($planRows as $p): ?><tr><td><?= h($p['name']) ?></td><td><?= h($p['billing_period']) ?><span class="subtle"><?= h(money((float)$p['amount'])) ?></span></td><td><?= h($p['optional_services'] ?? '—') ?></td><td><?= h($p['status']) ?></td><td><?= (int)$p['child_count'] ?></td><td><details><summary>Edit</summary><form method="post" class="daycare-form-grid mt-2"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="update_plan"><input type="hidden" name="plan_id" value="<?= (int)$p['id'] ?>"><div><label class="form-label">Name</label><input class="form-control" name="name" value="<?= h($p['name']) ?>" required></div><div><label class="form-label">Billing period</label><select class="form-select" name="billing_period"><?php foreach (['Daily','Weekly','Monthly','Half-day','Custom'] as $period): ?><option <?= $p['billing_period']===$period?'selected':'' ?>><?= h($period) ?></option><?php endforeach; ?></select></div><div><label class="form-label">Amount</label><input class="form-control" type="number" min="0" step="0.01" name="amount" value="<?= h((string)$p['amount']) ?>" required></div><div><label class="form-label">Services</label><input class="form-control" name="optional_services" value="<?= h($p['optional_services'] ?? '') ?>"></div><div><label class="form-label">Status</label><select class="form-select" name="status"><option <?= $p['status']==='Active'?'selected':'' ?>>Active</option><option <?= $p['status']==='Inactive'?'selected':'' ?>>Inactive</option></select></div><div class="wide"><button class="btn btn-sm btn-primary">Save plan</button></div></form></details></td></tr><?php endforeach; ?>
                </tbody></table></div><?php endif; ?>
        </section>
        <section class="daycare-panel"><div class="daycare-panel-head"><h2>Create Fee Plan</h2></div><form method="post" class="daycare-form-grid"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_plan"><div><label class="form-label">Plan name</label><input class="form-control" name="name" required></div><div><label class="form-label">Billing period</label><select class="form-select" name="billing_period"><option>Daily</option><option>Weekly</option><option selected>Monthly</option><option>Half-day</option><option>Custom</option></select></div><div><label class="form-label">Amount (KES)</label><input class="form-control" name="amount" type="number" min="0" step="0.01" required></div><div><label class="form-label">Services included / optional</label><input class="form-control" name="optional_services"></div><div class="wide"><button class="btn btn-primary">Create fee plan</button></div></form></section>
    <?php elseif ($view === 'settings'): ?>
        <div class="daycare-forms">
            <section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid fa-people-group"></i> Daycare Groups</h2></div><form method="post" class="daycare-form-grid"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="add_group"><div><label class="form-label">Group name</label><input class="form-control" name="name" required placeholder="e.g. Baby Class"></div><div><label class="form-label">Age range</label><input class="form-control" name="age_range" placeholder="e.g. 6 months – 2 years"></div><div><label class="form-label">Capacity</label><input class="form-control" name="capacity" type="number" min="1"></div><div class="d-flex align-items-end"><button class="btn btn-primary">Add group</button></div></form><div class="daycare-mini-list mt-3"><?php if (!$groupRows): ?><div class="daycare-empty">No groups configured.</div><?php else: foreach ($groupRows as $g): ?><div class="daycare-mini-row"><span><strong><?= h($g['name']) ?></strong><small><?= h($g['age_range'] ?? 'Age range not set') ?><?= $g['capacity'] ? ' · capacity '.(int)$g['capacity'] : '' ?></small></span><span class="daycare-status"><?= (int)$g['child_count'] ?> children</span><details><summary>Edit</summary><form method="post" class="daycare-form-grid mt-2"><input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>"><input type="hidden" name="action" value="update_group"><input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>"><div><label class="form-label">Name</label><input class="form-control" name="name" value="<?= h($g['name']) ?>" required></div><div><label class="form-label">Age range</label><input class="form-control" name="age_range" value="<?= h($g['age_range'] ?? '') ?>"></div><div><label class="form-label">Capacity</label><input class="form-control" type="number" min="1" name="capacity" value="<?= h((string)($g['capacity'] ?? '')) ?>"></div><div><label class="form-label">Status</label><select class="form-select" name="status"><option <?= $g['status']==='Active'?'selected':'' ?>>Active</option><option <?= $g['status']==='Inactive'?'selected':'' ?>>Inactive</option></select></div><div class="wide"><button class="btn btn-sm btn-primary">Save group</button></div></form></details></div><?php endforeach; endif; ?></div></section>
        </div>
    <?php elseif ($view === 'reports'): ?>
        <?php $reportType = $reportType ?? 'attendance'; ?>
        <section class="daycare-panel"><div class="daycare-panel-head"><h2><i class="fa-solid fa-child"></i> Children Registered</h2><a class="btn btn-sm btn-outline-primary" href="<?= h($pageUrl(['view'=>'reports','type'=>$reportType,'from'=>$from,'to'=>$to,'export'=>'csv'])) ?>"><i class="fa-solid fa-download me-1"></i>Export CSV</a></div><form class="daycare-date-filter mb-3" method="get"><input type="hidden" name="view" value="reports"><div><label class="form-label">From</label><input class="form-control" type="date" name="from" value="<?= h($from) ?>"></div><div><label class="form-label">To</label><input class="form-control" type="date" name="to" value="<?= h($to) ?>"></div><button class="btn btn-outline-primary">Run report</button></form>
            <?php if (!$reportRows): ?><div class="daycare-empty">No records found for the selected report and date range.</div><?php else: ?><div class="daycare-table-wrap"><table class="daycare-table"><thead><tr><?php foreach (array_keys($reportRows[0]) as $column): ?><th><?= h(ucwords(str_replace('_',' ',$column))) ?></th><?php endforeach; ?></tr></thead><tbody><?php foreach ($reportRows as $row): ?><tr><?php foreach ($row as $key=>$value): ?><td><?= h((string)($value ?? '—')) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><p class="muted mt-2 mb-0"><?= count($reportRows) ?> records · <?= h($from) ?> through <?= h($to) ?></p><?php endif; ?>
        </section>
    <?php endif; ?>
</div>
<?php if (!$groups): ?>
<script>
document.querySelectorAll('select[name="group_id"]').forEach((select) => {
    const hint = document.createElement('small');
    hint.className = 'form-text text-muted d-block mt-1';
    <?php if ($can('daycare.settings.manage')): ?>
    const link = document.createElement('a');
    link.href = <?= json_encode($daycareUrl('settings'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    link.textContent = 'Create a daycare group';
    hint.append('No active groups yet. ', link);
    <?php else: ?>
    hint.textContent = 'No active daycare groups are available. Ask an administrator to add one.';
    <?php endif; ?>
    select.parentElement.append(hint);
});
</script>
<?php endif; ?>
<?php if (!$plans): ?>
<script>
document.querySelectorAll('select[name="fee_plan_id"]').forEach((select) => {
    if (select.options.length === 1) {
        select.options[0].textContent = 'No fee plans configured';
    }
    const hint = document.createElement('small');
    hint.className = 'form-text text-muted d-block mt-1';
    <?php if ($can('daycare.fees.manage')): ?>
    const link = document.createElement('a');
    link.href = <?= json_encode($daycareUrl('fee-plans'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    link.textContent = 'Create a fee plan';
    hint.append('No active fee plans. ', link);
    <?php else: ?>
    hint.textContent = 'No active fee plans are available. Ask an administrator to create one.';
    <?php endif; ?>
    select.parentElement.append(hint);
});
</script>
<?php endif; ?>
<?php require __DIR__ . '/../includes/layout_end.php'; ?>
