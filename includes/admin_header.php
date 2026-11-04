<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/fee_helpers.php';

$pageTitle = $pageTitle ?? 'Admin';
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$adminName = $_SESSION['admin_name'] ?? 'Admin';
$headerContext = null;
$headerActiveContext = null;
$academicViewYears = [];
$academicViewTerms = [];

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $headerContext = current_academic_context($pdo);
        $headerActiveContext = active_academic_context($pdo);
        $academicViewYears = academic_view_year_options($pdo);
        $academicViewTerms = academic_view_terms($pdo, $headerContext['academic_year']);
        $lowStockUniforms = low_stock_uniforms($pdo);
        $lowStock = $lowStockUniforms ?: [];
    }
} catch (Throwable $exception) {
    $headerContext = null;
}

$currentTermLabel = $headerContext
    ? (($headerContext['academic_year'] === $headerActiveContext['academic_year'] && $headerContext['term'] === $headerActiveContext['term']) ? 'Active ' : 'Viewing ') . $headerContext['academic_year'] . ' - ' . $headerContext['term']
    : date('Y') . ' - Term';

$navItems = [
    ['label' => 'Dashboard', 'href' => 'admin/dashboard.php', 'icon' => 'fa-gauge-high', 'matches' => ['dashboard.php'], 'module' => 'Dashboard'],
    ['label' => 'Students', 'href' => 'admin/students.php', 'icon' => 'fa-user-graduate', 'matches' => ['students.php', 'student_form.php', 'import_students.php', 'student_status.php', 'student_history.php'], 'module' => 'Students'],
    ['label' => 'Fee Structure', 'href' => 'admin/fee_structures.php', 'icon' => 'fa-file-invoice-dollar', 'matches' => ['fee_structures.php'], 'module' => 'Settings', 'permission' => 'settings.fee_structure'],
    ['label' => 'Fees', 'href' => 'admin/fees.php', 'icon' => 'fa-wallet', 'matches' => ['fees.php', 'receipt.php', 'import_fee_payments.php', 'fee_payment_import_detail.php', 'reverse_fee.php', 'payment_allocation.php', 'payment_transaction_receipt.php'], 'module' => 'Fees'],
    ['label' => 'Transport Fee Structure', 'href' => 'admin/transport_fee_structures.php', 'icon' => 'fa-route', 'matches' => ['transport_fee_structures.php'], 'module' => 'Transport Fee Structure'],
    ['label' => 'Transport', 'href' => 'admin/transport.php', 'icon' => 'fa-bus', 'matches' => ['transport.php', 'transport_receipt.php'], 'module' => 'Transport'],
    ['label' => 'Feeding', 'href' => 'admin/feeding.php', 'icon' => 'fa-utensils', 'matches' => ['feeding.php', 'feeding_receipt.php'], 'module' => 'Feeding'],
    ['label' => 'Daycare', 'href' => 'admin/daycare.php', 'icon' => 'fa-baby', 'matches' => ['daycare.php', 'daycare_receipt.php'], 'module' => 'Daycare'],
    ['label' => 'Activities & Trips', 'href' => 'admin/activities.php', 'icon' => 'fa-person-hiking', 'matches' => ['activities.php', 'activity_form.php', 'activity_receipt.php'], 'module' => 'Activities & Trips'],
    ['label' => 'School Uniform', 'href' => 'admin/uniforms.php', 'icon' => 'fa-shirt', 'matches' => ['uniforms.php', 'uniform_form.php', 'uniform_sales.php', 'uniform_reports.php', 'uniform_stock.php', 'uniform_receipt.php'], 'module' => 'School Uniform'],
    ['label' => 'School Van Fuel', 'href' => 'admin/fuel.php', 'icon' => 'fa-gas-pump', 'matches' => ['fuel.php', 'fuel_form.php', 'fuel_reports.php', 'vehicles.php'], 'module' => 'School Van Fuel'],
    ['label' => 'Kitchen', 'href' => 'admin/kitchen.php', 'icon' => 'fa-kitchen-set', 'matches' => ['kitchen.php', 'kitchen_stock_taking.php', 'kitchen_expenditure.php', 'kitchen_inventory.php', 'kitchen_usage.php', 'kitchen_reports.php', 'kitchen_consumption_report.php', 'kitchen_weekly_shopping.php', 'kitchen_daily_purchase.php', 'kitchen_items.php'], 'module' => 'Kitchen'],
    ['label' => 'Expenses', 'href' => 'admin/school_expenses.php', 'icon' => 'fa-coins', 'matches' => ['school_expenses.php'], 'module' => 'Expenses'],
    ['label' => 'Reports', 'href' => 'admin/reports.php', 'icon' => 'fa-chart-line', 'matches' => ['reports.php', 'reports_export.php', 'activity_reports.php', 'activities_export.php'], 'module' => 'Reports'],
    ['label' => 'Parent Accounts', 'href' => 'admin/parent_accounts.php', 'icon' => 'fa-people-roof', 'matches' => ['parent_accounts.php'], 'module' => 'Settings', 'permission' => 'settings.manage_users'],
    ['label' => 'Settings', 'href' => 'admin/settings.php', 'icon' => 'fa-gear', 'matches' => ['settings.php', 'users_permissions.php', 'role_permissions.php', 'user_permissions.php', 'user_permissions_detail.php'], 'module' => 'Settings'],
];
$navItems[] = [
    'label' => 'Payment Audit',
    'href' => 'admin/fee_corrections.php',
    'icon' => 'fa-file-circle-check',
    'matches' => ['fee_payment_audit.php', 'fee_correction_request.php', 'fee_corrections.php', 'fee_correct.php'],
    'module' => 'Payment Audit',
];
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <script>
        try {
            if (window.localStorage.getItem('ariseAdminTheme') === 'dark') {
                document.documentElement.dataset.theme = 'dark';
            }
        } catch (error) {}
    </script>
    <title><?= h($pageTitle) ?> | <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/dashboard.css') ?>">
    <?php foreach (($pageStyles ?? []) as $pageStyle): ?><link rel="stylesheet" href="<?= asset_version((string) $pageStyle) ?>"><?php endforeach; ?>
    <link rel="stylesheet" href="<?= asset_version('css/receipt.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/ui-refresh.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/daycare.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/activities.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/receipt-print.css') ?>">
    <link href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="admin-body">
<div class="sidebar-backdrop" data-sidebar-close></div>
<div class="admin-layout">
    <aside class="admin-sidebar" aria-label="Admin navigation">
        <a class="sidebar-brand" href="<?= url('admin/dashboard.php') ?>">
            <span class="sidebar-logo-wrap">
                <img class="sidebar-logo" src="<?= asset('images/school-logo.jpg') ?>" alt="Arise To Excel Academy logo">
            </span>
            <span>
                <strong>Arise To Excel Academy</strong>
                <small>School Command Center</small>
            </span>
        </a>

        <nav class="sidebar-nav">
            <?php foreach ($navItems as $item): ?>
                <?php 
                    $isActive = in_array($currentScript, $item['matches'], true);
                    $hasPermission = !isset($item['module']) || (isset($pdo) && current_admin_can_access_module($pdo, $item['module']));
                    if (isset($item['permission'])) {
                        $hasPermission = $hasPermission && isset($pdo) && current_admin_has_permission($pdo, $item['permission']);
                    }
                ?>
                <?php if ($hasPermission): ?>
                    <a class="sidebar-link <?= $isActive ? 'active' : '' ?>" href="<?= url($item['href']) ?>" title="<?= h($item['label']) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                        <i class="fa-solid <?= h($item['icon']) ?>"></i>
                        <span><?= h($item['label']) ?></span>
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-admin-card">
                <div class="sidebar-avatar" role="img" aria-label="Administrator avatar"><i class="fa-solid fa-user" aria-hidden="true"></i></div>
                <div>
                    <strong><?= h($adminName) ?></strong>
                    <span><i class="status-dot"></i> Online</span>
                </div>
            </div>
            <form method="post" action="<?= url('admin/logout.php') ?>">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <button class="sidebar-link sidebar-logout" type="submit" style="width:100%;border:0;background:transparent;text-align:left">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <span>Logout</span>
                </button>
            </form>
        </div>
    </aside>

    <div class="dashboard-main">
        <header class="dashboard-header">
            <button class="sidebar-toggle" type="button" aria-label="Toggle sidebar" data-sidebar-toggle>
                <i class="fa-solid fa-bars"></i>
            </button>

            <div class="admin-page-context" aria-label="Current page">
                <span>School Command Center</span>
                <strong><?= h($pageTitle) ?></strong>
            </div>

            <?php if (isset($pdo) && current_admin_can_access_module($pdo, 'Students')): ?>
                <form class="dashboard-search" action="<?= url('admin/students.php') ?>" method="get" role="search">
                    <button class="dashboard-search-button" type="submit" aria-label="Search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                    </button>
                    <input type="search" name="search" placeholder="Search students, payments, classes..." aria-label="Search students">
                </form>
            <?php else: ?>
                <div class="dashboard-search" aria-hidden="true"></div>
            <?php endif; ?>

            <?php if (is_array($headerActiveContext) && !empty($headerActiveContext['academic_year']) && !empty($headerActiveContext['term'])): ?>
                <div class="header-academic-period" role="status" aria-label="Active academic period">
                    <span class="header-academic-period-icon" aria-hidden="true"><i class="fa-regular fa-calendar-days"></i></span>
                    <span class="header-academic-period-copy">
                        <small>Active period</small>
                        <strong><?= h((string) $headerActiveContext['academic_year']) ?> - <?= h((string) $headerActiveContext['term']) ?></strong>
                    </span>
                </div>
            <?php endif; ?>

            <div class="header-actions">
                <?php
                    $currentAdminRoles = [];
                    if (isset($pdo) && function_exists('current_admin_roles')) {
                        $currentAdminRoles = current_admin_roles($pdo);
                    }
                    $headerIsDirector = false;
                    foreach ($currentAdminRoles as $roleName) {
                        $lowerRole = mb_strtolower(trim($roleName));
                        if ($lowerRole === 'director') {
                            $headerIsDirector = true;
                        }
                    }
                    $showNotifications = !empty($_SESSION['admin_id']);
                    $canViewUniformNotifications = isset($pdo)
                        && current_admin_can_access_module($pdo, 'School Uniform');
                    $showPaymentReviewNotification = $headerIsDirector
                        && isset($pdo)
                        && current_admin_can_access_module($pdo, 'Payment Audit');
                    $pendingPaymentReviewCount = $showPaymentReviewNotification
                        ? fee_pending_correction_count($pdo)
                        : 0;
                    $visibleLowStock = $canViewUniformNotifications && isset($lowStock) ? $lowStock : [];
                    $notificationCount = count($visibleLowStock) + $pendingPaymentReviewCount;
                ?>
                <?php if ($showNotifications): ?>
                    <div class="dropdown header-notifications" data-header-dropdown>
                        <button class="header-icon-button" type="button" aria-label="Notifications<?= $notificationCount > 0 ? ' (' . $notificationCount . ')' : '' ?>" title="Notifications" aria-haspopup="true" aria-expanded="false" data-header-dropdown-toggle>
                            <i class="fa-regular fa-bell"></i>
                            <?php if ($notificationCount > 0): ?><span class="notification-badge"><?= $notificationCount ?></span><?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end modern-dropdown notification-menu">
                            <?php if ($pendingPaymentReviewCount > 0): ?>
                                <a class="dropdown-item text-danger" href="<?= url('admin/fee_corrections.php#pending-payment-reviews') ?>">
                                    <i class="fa-solid fa-file-circle-exclamation me-2" aria-hidden="true"></i>
                                    <?= $pendingPaymentReviewCount ?> payment review<?= $pendingPaymentReviewCount === 1 ? '' : 's' ?> awaiting your decision
                                </a>
                            <?php endif; ?>
                            <?php if (count($visibleLowStock) > 0): ?>
                                <div class="dropdown-divider"></div>
                                <?php foreach ($visibleLowStock as $ls): ?>
                                    <?php
                                        $isCritical = false;
                                        if (isset($ls['item_name'])) {
                                            $isCritical = (float)$ls['remaining_stock'] <= 0;
                                        } elseif (isset($ls['uniform_name'])) {
                                            $isCritical = (int)$ls['available_stock'] <= 0;
                                        }
                                        $alertClass = $isCritical ? 'text-danger' : 'text-warning';
                                        $alertPrefix = $isCritical ? 'CRITICAL: Out of stock - ' : 'Low stock: ';
                                    ?>
                                    <?php if (isset($ls['uniform_name'])): ?>
                                        <a class="dropdown-item <?= $alertClass ?>" href="<?= url('admin/uniforms.php?low_stock=1') ?>"><?= $alertPrefix ?><?= h($ls['uniform_name'] . ' · ' . ($ls['size'] ?? '')) ?> (<?= (int)$ls['available_stock'] ?> left)</a>
                                    <?php elseif (isset($ls['item_name'])): ?>
                                        <a class="dropdown-item <?= $alertClass ?>" href="<?= url('admin/kitchen_inventory.php?search=' . urlencode($ls['item_name'])) ?>"><?= $alertPrefix ?><?= h($ls['item_name']) ?> (<?= h((string)$ls['remaining_stock']) ?> left)</a>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php elseif ($pendingPaymentReviewCount === 0): ?>
                                <div class="dropdown-item text-muted">No notifications</div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (isset($pdo) && current_admin_can_access_module($pdo, 'Settings')): ?>
                    <a class="header-icon-button" href="<?= url('admin/settings.php') ?>" aria-label="Settings">
                        <i class="fa-solid fa-gear"></i>
                    </a>
                <?php endif; ?>

                <button class="header-icon-button theme-toggle" type="button" data-theme-toggle aria-label="Switch to dark theme" aria-pressed="false" title="Switch to dark theme">
                    <i class="fa-solid fa-moon" aria-hidden="true"></i>
                </button>

                <div class="dropdown admin-profile" data-header-dropdown>
                    <button class="profile-button" type="button" aria-haspopup="true" aria-expanded="false" data-header-dropdown-toggle>
                        <span class="profile-avatar" role="img" aria-label="Administrator avatar"><i class="fa-solid fa-user" aria-hidden="true"></i></span>
                        <span class="profile-name"><?= h($adminName) ?></span>
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                    <div class="dropdown-menu dropdown-menu-end modern-dropdown">
                        <a class="dropdown-item" href="<?= url('admin/profile.php') ?>">My Profile</a>
                        <a class="dropdown-item" href="<?= url('admin/profile.php#change-password') ?>">Change My Password</a>
                        <?php if (isset($pdo) && current_admin_can_access_module($pdo, 'Settings')): ?>
                            <a class="dropdown-item" href="<?= url('admin/settings.php') ?>">Settings</a>
                        <?php endif; ?>
                        <form method="post" action="<?= url('admin/logout.php') ?>">
                            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                            <button class="dropdown-item" type="submit" style="border:0;background:transparent;text-align:left">Logout</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="container-fluid admin-shell">
            <?php if ($msg = flash('success')): ?>
                <div class="alert alert-success" style="margin:12px 0"><?= h($msg) ?></div>
            <?php endif; ?>
            <?php if ($msg = flash('error')): ?>
                <div class="alert alert-danger" style="margin:12px 0"><?= h($msg) ?></div>
            <?php endif; ?>
            <?php if (isset($pdo) && academic_view_is_historical($pdo)): ?>
                <div class="alert alert-warning academic-readonly-notice" role="status">
                    <i class="fa-solid fa-clock-rotate-left me-2" aria-hidden="true"></i>
                    Viewing <?= h($headerContext['academic_year']) ?> - <?= h($headerContext['term']) ?>. This historical period is read-only; the active period is <?= h($headerActiveContext['academic_year']) ?> - <?= h($headerActiveContext['term']) ?>.
                </div>
            <?php endif; ?>
            <?php if (!empty($_SESSION['parent_activation_notices']) && is_array($_SESSION['parent_activation_notices'])): ?>
                <?php $activationNotices = $_SESSION['parent_activation_notices']; unset($_SESSION['parent_activation_notices']); ?>
                <section class="panel mb-3 border-warning">
                    <h2 class="h5">Parent account activation</h2>
                    <p class="mb-2">Share these details directly with each verified parent. One-time codes are shown only once.</p>
                    <?php foreach ($activationNotices as $activationNotice): ?>
                        <dl class="row mb-2">
                            <dt class="col-sm-3">Username</dt><dd class="col-sm-9"><code><?= h((string) ($activationNotice['username'] ?? '')) ?></code></dd>
                            <dt class="col-sm-3">Activation code</dt><dd class="col-sm-9"><code class="user-select-all"><?= h((string) ($activationNotice['activation_code'] ?? '')) ?></code></dd>
                            <dt class="col-sm-3">Activation page</dt><dd class="col-sm-9"><a href="<?= h((string) ($activationNotice['activation_url'] ?? url('parent/activate.php'))) ?>"><?= h((string) ($activationNotice['activation_url'] ?? url('parent/activate.php'))) ?></a></dd>
                        </dl>
                        <?php if (count($activationNotices) > 1): ?><hr><?php endif; ?>
                    <?php endforeach; ?>
                    <p class="small text-muted mt-2 mb-0">The code expires in seven days and cannot be used again after activation.</p>
                </section>
            <?php endif; ?>
