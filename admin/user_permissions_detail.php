<?php
$pageTitle = 'User Permissions';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

require_action_permission($pdo, 'settings.manage_users', 'You do not have permission to access this page.');

$userId = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
if ($userId <= 0) {
    flash('error', 'Invalid user selected.');
    redirect('admin/users_permissions.php');
}

$user = get_user_by_id($pdo, $userId);
if (!$user) {
    flash('error', 'User not found.');
    redirect('admin/users_permissions.php');
}

if (is_parent_account_user($pdo, $userId)) {
    flash('error', 'Parent accounts are managed from Parent Accounts.');
    redirect('admin/users_permissions.php');
}

ensure_permissions_exist($pdo);

$templateLabels = get_permission_template_dropdown($pdo);
$classLevels = class_level_options();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_template'])) {
    $templateName = trim($_POST['template_name'] ?? '');
    $classLevel = trim($_POST['class_level'] ?? '');

    if ($templateName === '' || !in_array($templateName, $templateLabels, true)) {
        flash('error', 'Please select a valid role.');
        redirect('admin/user_permissions_detail.php?user_id=' . $userId);
    }

    if ($templateName === 'Teacher' && !in_array($classLevel, $classLevels, true)) {
        flash('error', 'Please select a valid class for Teacher role.');
        redirect('admin/user_permissions_detail.php?user_id=' . $userId);
    }

    try {
        clear_user_role_assignments($pdo, $userId);
        clear_custom_user_permissions($pdo, $userId);
        assign_user_role_by_name($pdo, $userId, $templateName);
        save_admin_user($pdo, [
            'id' => $userId,
            'name' => $user['name'],
            'username' => $user['username'],
            'status' => $user['status'],
            'class_level' => $templateName === 'Teacher' ? $classLevel : null,
        ]);

        flash('success', 'Role updated successfully.');
        redirect('admin/user_permissions_detail.php?user_id=' . $userId);
    } catch (Exception $e) {
        flash('error', 'Failed to update role: ' . $e->getMessage());
        redirect('admin/user_permissions_detail.php?user_id=' . $userId);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_custom_permissions'])) {
    try {
        $permissionKeys = sanitize_user_permissions($_POST['permission_keys'] ?? []);
        set_user_permissions($pdo, $userId, $permissionKeys);
        flash('success', 'User permission overrides saved successfully.');
        redirect('admin/user_permissions_detail.php?user_id=' . $userId);
    } catch (Exception $e) {
        flash('error', 'Failed to update permissions: ' . $e->getMessage());
        redirect('admin/user_permissions_detail.php?user_id=' . $userId);
    }
}

$user = get_user_by_id($pdo, $userId);
$permissionDefs = permission_definitions();
$currentPermissions = get_user_permission_keys($pdo, $userId);
$currentRole = get_user_role_template_name($pdo, $userId);
$currentClassLevel = $user['class_level'] ?? '';

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="page-title">
    <div>
        <p class="eyebrow">System Administration</p>
        <h1>User Permissions</h1>
        <p class="mb-0 text-muted">Assign a role and optional custom permission overrides for <?= h($user['name']) ?>.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/users_permissions.php') ?>">Back to Users & Permissions</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= h($msg) ?></div><?php endif; ?>

<section class="panel mb-4">
    <div class="panel-heading">
        <h2>User Information</h2>
    </div>
    <div class="row g-3 mb-4">
        <div class="col-md-4"><div class="mini-stat"><span>Name</span><strong><?= h($user['name']) ?></strong></div></div>
        <div class="col-md-4"><div class="mini-stat"><span>Username</span><strong><?= h($user['username']) ?></strong></div></div>
        <div class="col-md-4"><div class="mini-stat"><span>Role</span><strong><?= h($currentRole) ?></strong></div></div>
    </div>

    <form method="post" class="row g-3 align-items-end" id="role-assignment-form">
        <input type="hidden" name="user_id" value="<?= (int) $userId ?>">
        <div class="col-lg-5">
            <label class="form-label" for="template_name">Role</label>
            <select class="form-select" id="template_name" name="template_name" required>
                <option value="">Select Role</option>
                <?php foreach ($templateLabels as $template): ?>
                    <option value="<?= h($template) ?>" <?= $currentRole === $template ? 'selected' : '' ?>><?= h($template) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-4" id="class-level-group" style="display:none;">
            <label class="form-label" for="class_level">Class Level</label>
            <select class="form-select" id="class_level" name="class_level">
                <option value="">Select Class</option>
                <?php foreach ($classLevels as $level): ?>
                    <option value="<?= h($level) ?>" <?= $currentClassLevel === $level ? 'selected' : '' ?>><?= h($level) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-3">
            <button class="btn btn-outline-primary w-100" type="submit" name="assign_template" value="1">Save User Role</button>
        </div>
    </form>
</section>

<form method="post" id="rbac-permissions-form">
    <input type="hidden" name="user_id" value="<?= (int) $userId ?>">

    <section class="panel mb-4">
        <div class="panel-heading">
            <h2>Global Controls</h2>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <button class="btn btn-outline-secondary btn-sm" type="button" data-rbac-command="select-modules">Select All Modules</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-rbac-command="full">Grant Full Access</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-rbac-command="view-only">View Only Access</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-rbac-command="clear">Clear All Permissions</button>
            <button class="btn btn-outline-secondary btn-sm" type="button" data-rbac-command="apply-current">Apply Permissions To All Modules</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="view">Enable View For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="save">Enable Save For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="update">Enable Update For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="delete">Enable Delete For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="export">Enable Export For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="import">Enable Import For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="receipt">Enable Receipt Access For All</button>
        </div>
    </section>

    <section class="panel mb-4">
        <div class="panel-heading">
            <h2>Module Access</h2>
            <p class="text-muted mb-0">Module access and individual actions are separate. Choose View actions to see what you can grant.</p>
        </div>
        <div class="row g-3" id="module-grid">
            <?php foreach ($permissionDefs as $moduleName => $module): ?>
                <?php $moduleKey = $module['permission_key']; ?>
                <div class="col-sm-6 col-xl-4">
                    <div class="border rounded-3 p-3 h-100 rbac-module-tile" data-module="<?= h($moduleKey) ?>">
                        <label class="d-flex gap-3 align-items-start mb-2" for="module-access-<?= h($moduleKey) ?>">
                            <input id="module-access-<?= h($moduleKey) ?>" class="form-check-input module-checkbox mt-1" type="checkbox" name="permission_keys[]" value="<?= h($moduleKey) ?>" data-module="<?= h($moduleKey) ?>" <?= in_array($moduleKey, $currentPermissions, true) ? 'checked' : '' ?>>
                            <span>
                                <strong><i class="fa-solid <?= h($module['icon'] ?? 'fa-layer-group') ?>"></i> <?= h($module['label']) ?></strong>
                                <span class="d-block text-muted small"><?= count($module['actions']) ?> action permission(s)</span>
                            </span>
                        </label>
                        <?php if (!empty($module['actions'])): ?>
                            <button class="btn btn-sm btn-outline-primary" type="button" data-show-actions="<?= h($moduleKey) ?>">View actions</button>
                        <?php else: ?>
                            <span class="small text-muted">No separate actions</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel mb-4">
        <div class="panel-heading">
            <h2>Permissions Panel</h2>
        </div>
        <?php foreach ($permissionDefs as $moduleName => $module): ?>
            <?php $moduleKey = $module['permission_key']; ?>
            <div class="module-permission-panel" data-module-panel="<?= h($moduleKey) ?>" style="display:none;">
                <h3 class="h5 mb-3"><?= h($module['label']) ?></h3>
                <?php if (empty($module['actions'])): ?>
                    <p class="text-muted mb-0">This module is controlled by module access only.</p>
                <?php else: ?>
                    <div class="row g-2">
                        <?php foreach ($module['actions'] as $actionKey => $actionLabel): ?>
                            <div class="col-md-6 col-xl-4">
                                <label class="form-check border rounded-3 p-3 h-100">
                                    <input class="form-check-input action-checkbox" type="checkbox" name="permission_keys[]" value="<?= h($actionKey) ?>" data-module="<?= h($moduleKey) ?>" data-bulk="<?= h(rbac_permission_bulk_group($actionKey)) ?>" <?= in_array($actionKey, $currentPermissions, true) ? 'checked' : '' ?>>
                                    <span class="form-check-label"><?= h($actionLabel) ?></span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </section>

    <section class="panel">
        <div class="text-end">
            <button type="submit" name="assign_custom_permissions" value="1" class="btn btn-primary">Save User</button>
        </div>
    </section>
</form>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var roleSelect = document.getElementById('template_name');
    var classGroup = document.getElementById('class-level-group');
    var classSelect = document.getElementById('class_level');
    function toggleClassLevel() {
        if (!roleSelect || !classGroup || !classSelect) return;
        var teacher = roleSelect.value === 'Teacher';
        classGroup.style.display = teacher ? '' : 'none';
        classSelect.required = teacher;
        if (!teacher) classSelect.value = '';
    }
    if (roleSelect) {
        roleSelect.addEventListener('change', toggleClassLevel);
        toggleClassLevel();
    }

    var form = document.getElementById('rbac-permissions-form');
    if (!form) return;

    var moduleTiles = Array.from(form.querySelectorAll('.rbac-module-tile'));
    var moduleCheckboxes = Array.from(form.querySelectorAll('.module-checkbox'));
    var actionCheckboxes = Array.from(form.querySelectorAll('.action-checkbox'));
    var panels = Array.from(form.querySelectorAll('.module-permission-panel'));
    var actionButtons = Array.from(form.querySelectorAll('[data-show-actions]'));
    var activeAction = actionCheckboxes.find(function (cb) { return cb.checked; }) || actionCheckboxes[0];
    var activeModule = (activeAction || moduleCheckboxes.find(function (cb) { return cb.checked; }) || moduleCheckboxes[0] || {}).dataset?.module;

    function showPanel(moduleKey) {
        activeModule = moduleKey;
        panels.forEach(function (panel) {
            panel.style.display = panel.dataset.modulePanel === moduleKey ? '' : 'none';
        });
        moduleTiles.forEach(function (tile) {
            tile.classList.toggle('border-primary', tile.dataset.module === moduleKey);
        });
        actionButtons.forEach(function (button) {
            button.setAttribute('aria-pressed', button.dataset.showActions === moduleKey ? 'true' : 'false');
        });
    }

    function setAllActionsByBulk(group, checked) {
        actionCheckboxes.forEach(function (checkbox) {
            if (checkbox.dataset.bulk === group) {
                checkbox.checked = checked;
                var moduleCheckbox = form.querySelector('.module-checkbox[data-module="' + checkbox.dataset.module + '"]');
                if (checked && moduleCheckbox) moduleCheckbox.checked = true;
            }
        });
    }

    actionButtons.forEach(function (button) {
        button.addEventListener('click', function () { showPanel(button.dataset.showActions); });
    });

    moduleCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            if (!checkbox.checked) {
                actionCheckboxes.filter(function (action) { return action.dataset.module === checkbox.dataset.module; }).forEach(function (action) {
                    action.checked = false;
                });
            }
            showPanel(checkbox.dataset.module);
        });
    });

    actionCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            if (checkbox.checked) {
                var moduleCheckbox = form.querySelector('.module-checkbox[data-module="' + checkbox.dataset.module + '"]');
                if (moduleCheckbox) moduleCheckbox.checked = true;
            }
        });
    });

    form.querySelectorAll('[data-rbac-command]').forEach(function (button) {
        button.addEventListener('click', function () {
            var command = button.dataset.rbacCommand;
            if (command === 'select-modules') {
                moduleCheckboxes.forEach(function (checkbox) { checkbox.checked = true; });
            }
            if (command === 'full') {
                moduleCheckboxes.concat(actionCheckboxes).forEach(function (checkbox) { checkbox.checked = true; });
            }
            if (command === 'view-only') {
                moduleCheckboxes.forEach(function (checkbox) { checkbox.checked = true; });
                actionCheckboxes.forEach(function (checkbox) {
                    checkbox.checked = ['view', 'receipt'].indexOf(checkbox.dataset.bulk) !== -1;
                });
            }
            if (command === 'clear') {
                moduleCheckboxes.concat(actionCheckboxes).forEach(function (checkbox) { checkbox.checked = false; });
            }
            if (command === 'apply-current' && activeModule) {
                var checkedGroups = {};
                actionCheckboxes.filter(function (checkbox) { return checkbox.dataset.module === activeModule && checkbox.checked; }).forEach(function (checkbox) {
                    checkedGroups[checkbox.dataset.bulk] = true;
                });
                actionCheckboxes.forEach(function (checkbox) {
                    if (checkbox.dataset.bulk) {
                        checkbox.checked = !!checkedGroups[checkbox.dataset.bulk];
                        if (checkbox.checked) {
                            var moduleCheckbox = form.querySelector('.module-checkbox[data-module="' + checkbox.dataset.module + '"]');
                            if (moduleCheckbox) moduleCheckbox.checked = true;
                        }
                    }
                });
            }
        });
    });

    form.querySelectorAll('[data-rbac-bulk]').forEach(function (button) {
        button.addEventListener('click', function () {
            setAllActionsByBulk(button.dataset.rbacBulk, true);
        });
    });

    if (activeModule) showPanel(activeModule);
});
</script>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
