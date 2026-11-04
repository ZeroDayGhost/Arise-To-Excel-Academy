<?php
require_once __DIR__ . '/../config/app.php';
$parentDisplayName = $parentAccount['name'] ?? $_SESSION['admin_name'] ?? 'Parent';
$pageTitle = $pageTitle ?? 'Fee Portal';
$currentParentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$parentHeaderContext = current_academic_context($pdo);
$parentHeaderYear = parent_portal_normalize_year($parentHeaderContext['academic_year'] ?? '') ?: date('Y');
$parentHeaderTerms = parent_portal_term_options();
$parentHeaderTerm = in_array($parentHeaderContext['term'] ?? '', $parentHeaderTerms, true) ? $parentHeaderContext['term'] : 'Term 1';
$parentHeaderChildren = parent_portal_children($pdo, $parentUserId, $parentHeaderYear, $parentHeaderTerm);
$parentHeaderDueChildren = array_values(array_filter($parentHeaderChildren, static fn (array $child): bool => (float) ($child['balance'] ?? 0) > 0));
$parentNavItems = [
    ['label' => 'Dashboard', 'href' => 'parent/dashboard.php', 'icon' => 'fa-house', 'matches' => ['dashboard.php']],
    ['label' => 'Fee Statement', 'href' => 'parent/fee_statement.php', 'icon' => 'fa-file-lines', 'matches' => ['fee_statement.php', 'statement.php']],
];
$parentServiceNav = [
    ['label' => 'Feeding', 'key' => 'feeding', 'icon' => 'fa-utensils'],
    ['label' => 'Transport', 'key' => 'transport', 'icon' => 'fa-bus'],
    ['label' => 'Activities & Trips', 'key' => 'activity', 'icon' => 'fa-person-hiking'],
    ['label' => 'School Uniform', 'key' => 'uniform', 'icon' => 'fa-shirt'],
];
foreach ($parentServiceNav as $serviceItem) {
    if (parent_portal_has_service_transactions($pdo, $parentUserId, $serviceItem['key'])) {
        $parentNavItems[] = [
            'label' => $serviceItem['label'],
            'href' => 'parent/service_statement.php?service=' . $serviceItem['key'],
            'icon' => $serviceItem['icon'],
            'service' => $serviceItem['key'],
            'matches' => ['service_statement.php'],
        ];
    }
}
$parentNavItems = array_merge($parentNavItems, [
    ['label' => 'Payments', 'href' => 'parent/payments.php', 'icon' => 'fa-credit-card', 'matches' => ['payments.php', 'receipt.php']],
    ['label' => 'Student Details', 'href' => 'parent/students.php', 'icon' => 'fa-people-group', 'matches' => ['students.php']],
    ['label' => 'Settings', 'href' => 'parent/settings.php', 'icon' => 'fa-gear', 'matches' => ['settings.php']],
]);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($pageTitle) ?> | <?= h(SCHOOL_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/parent-portal.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/ui-refresh.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/receipt-print.css') ?>">
</head>
<body class="parent-portal">
<div class="parent-shell">
    <div class="parent-sidebar-backdrop" data-parent-menu-close></div>
    <aside class="parent-sidebar" id="parent-sidebar" aria-label="Parent navigation">
        <a class="parent-sidebar-brand" href="<?= url('parent/dashboard.php') ?>">
            <span class="parent-sidebar-logo"><img src="<?= asset('images/school-logo.jpg') ?>" alt=""></span>
            <span class="parent-sidebar-brand-copy"><strong><?= h(SCHOOL_NAME) ?></strong><small><i aria-hidden="true"></i> Fee Portal</small></span>
        </a>
        <nav class="parent-nav">
            <?php foreach ($parentNavItems as $item): ?>
                <?php $isActive = in_array($currentParentPage, $item['matches'], true)
                    && (!isset($item['service']) || ($_GET['service'] ?? '') === $item['service']); ?>
                <a class="parent-nav-link <?= $isActive ? 'active' : '' ?>" href="<?= url($item['href']) ?>" aria-label="<?= h($item['label']) ?>" title="<?= h($item['label']) ?>" <?= $isActive ? 'aria-current="page"' : '' ?>>
                    <i class="fa-solid <?= h($item['icon']) ?>" aria-hidden="true"></i>
                    <span><?= h($item['label']) ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="parent-sidebar-footer">
            <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
            <span class="parent-sidebar-quote">Together we build<br>a brighter future.</span>
        </div>
    </aside>

    <div class="parent-workspace">
        <header class="parent-topbar">
            <button class="parent-menu-toggle" type="button" aria-label="Toggle navigation" aria-controls="parent-sidebar" aria-expanded="true" data-parent-menu-toggle>
                <i class="fa-solid fa-bars" aria-hidden="true"></i>
            </button>
            <a class="parent-brand-heading" href="<?= url('parent/dashboard.php') ?>">
                <strong><?= h(SCHOOL_NAME) ?></strong>
                <small>Fee Portal</small>
            </a>
            <?php if (!in_array($currentParentPage, ['settings.php', 'receipt.php'], true)): ?>
                <label class="parent-topbar-search">
                    <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                    <input type="search" placeholder="Search child or receipt..." aria-label="Search this page" data-parent-search>
                </label>
                <p class="parent-search-status" aria-live="polite" data-parent-search-status hidden></p>
            <?php endif; ?>
            <div class="parent-topbar-actions">
                <div class="parent-popover-wrap">
                    <button class="parent-icon-button parent-notification-toggle" type="button" aria-label="Fee balance alerts" aria-expanded="false" aria-controls="parent-notifications" data-parent-notification-toggle>
                        <i class="fa-regular fa-bell" aria-hidden="true"></i>
                        <?php if ($parentHeaderDueChildren): ?><span class="parent-notification-badge"><?= count($parentHeaderDueChildren) ?></span><?php endif; ?>
                    </button>
                    <div class="parent-popover parent-notification-popover" id="parent-notifications" hidden>
                        <strong>Fee balance alerts</strong>
                        <?php if ($parentHeaderDueChildren): ?>
                            <p><?= count($parentHeaderDueChildren) ?> <?= count($parentHeaderDueChildren) === 1 ? 'child has' : 'children have' ?> an outstanding balance for <?= h($parentHeaderYear . ' - ' . $parentHeaderTerm) ?>.</p>
                            <?php foreach (array_slice($parentHeaderDueChildren, 0, 3) as $alertChild): ?>
                                <div class="parent-alert-child"><span><?= h($alertChild['full_name']) ?></span><strong><?= h(money((float) $alertChild['balance'])) ?></strong></div>
                            <?php endforeach; ?>
                            <a href="<?= url('parent/fee_statement.php?year=' . rawurlencode($parentHeaderYear) . '&term=' . rawurlencode($parentHeaderTerm)) ?>">Review fee statements <i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
                        <?php else: ?>
                            <p>You're all caught up. No outstanding balance is showing for the current period.</p>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="parent-popover-wrap">
                    <button class="parent-account parent-account-toggle" type="button" aria-expanded="false" aria-controls="parent-account-menu" data-parent-account-toggle>
                        <span class="parent-account-avatar" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                        <span class="parent-account-copy"><strong><?= h($parentDisplayName) ?></strong><small>Parent / Guardian</small></span>
                        <i class="fa-solid fa-chevron-down parent-account-chevron" aria-hidden="true"></i>
                    </button>
                    <div class="parent-popover parent-account-popover" id="parent-account-menu" hidden>
                        <a href="<?= url('parent/settings.php') ?>"><i class="fa-solid fa-gear" aria-hidden="true"></i> Account settings</a>
                        <form method="post" action="<?= url('admin/logout.php') ?>">
                            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                            <button class="parent-signout" type="submit"><i class="fa-solid fa-arrow-right-from-bracket" aria-hidden="true"></i><span>Sign out</span></button>
                        </form>
                    </div>
                </div>
            </div>
        </header>
        <main class="parent-main">
            <?php if ($message = flash('success')): ?>
                <div class="alert alert-success"><?= h($message) ?></div>
            <?php endif; ?>
            <?php if ($message = flash('error')): ?>
                <div class="alert alert-danger"><?= h($message) ?></div>
            <?php endif; ?>
