<?php
$pageTitle = 'Role Permissions';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

require_action_permission($pdo, 'settings.manage_users', 'You do not have permission to manage roles.');

ensure_permissions_exist($pdo);

$roleId = (int) ($_GET['role_id'] ?? $_POST['role_id'] ?? 0);
$storedRoles = get_stored_role_templates($pdo);
$roleById = [];
foreach ($storedRoles as $storedRole) {
    $roleById[(int) $storedRole['id']] = $storedRole;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_role_permissions'])) {
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $permissionKeys = sanitize_user_permissions(rbac_permission_keys_with_module_access(
        $_POST['permission_keys'] ?? [],
        $_POST['module_access_keys'] ?? []
    ));

    if ($roleId <= 0 || !isset($roleById[$roleId])) {
        flash('error', 'Please select a valid role.');
        redirect('admin/role_permissions.php');
    }

    try {
        set_role_permissions($pdo, $roleId, $permissionKeys);
        flash('success', 'Role permissions updated successfully.');
        redirect('admin/role_permissions.php?role_id=' . $roleId);
    } catch (Exception $e) {
        flash('error', 'Failed to save role permissions: ' . $e->getMessage());
        redirect('admin/role_permissions.php?role_id=' . $roleId);
    }
}

$role = $roleId && isset($roleById[$roleId]) ? $roleById[$roleId] : null;
$rolePermissions = $role ? get_role_permission_keys($pdo, $roleId) : [];
$permissionDefs = permission_definitions();

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="page-title">
    <div>
        <p class="eyebrow">System Administration</p>
        <h1>Role Permissions</h1>
        <p class="mb-0 text-muted">Configure module access first, then action permissions for the selected module.</p>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/users_permissions.php') ?>">Back to Users & Permissions</a>
    </div>
</div>

<?php if ($msg = flash('success')): ?><div class="alert alert-success"><?= h($msg) ?></div><?php endif; ?>
<?php if ($msg = flash('error')): ?><div class="alert alert-danger"><?= h($msg) ?></div><?php endif; ?>

<section class="panel mb-4">
    <form method="get" class="row g-3 align-items-end">
        <div class="col-lg-8">
            <label class="form-label" for="role_id">Role</label>
            <select id="role_id" name="role_id" class="form-select" onchange="this.form.submit()">
                <option value="">Choose role</option>
                <?php foreach ($storedRoles as $storedRole): ?>
                    <option value="<?= (int) $storedRole['id'] ?>" <?= (int) $storedRole['id'] === $roleId ? 'selected' : '' ?>>
                        <?= h($storedRole['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-lg-4">
            <button class="btn btn-outline-primary w-100" type="submit">Load Role</button>
        </div>
    </form>
</section>

<?php if ($role): ?>
<form method="post" id="rbac-permissions-form">
    <input type="hidden" name="role_id" value="<?= (int) $roleId ?>">

    <section class="panel mb-4">
        <div class="panel-heading">
            <h2>User Information</h2>
        </div>
        <div class="row g-3">
            <div class="col-md-4"><div class="mini-stat"><span>Name</span><strong><?= h($role['name']) ?></strong></div></div>
            <div class="col-md-4"><div class="mini-stat"><span>Username</span><strong>Role Template</strong></div></div>
            <div class="col-md-4"><div class="mini-stat"><span>Role</span><strong><?= h($role['name']) ?></strong></div></div>
        </div>
        <?php if (!empty($role['description'])): ?>
            <p class="text-muted mt-3 mb-0"><?= h($role['description']) ?></p>
        <?php endif; ?>
    </section>

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
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="save">Enable Save For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="update">Enable Update For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="delete">Enable Delete For All</button>
            <button class="btn btn-outline-primary btn-sm" type="button" data-rbac-bulk="export">Enable Export For All</button>
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
                <?php $moduleSlug = rbac_module_access_keys()[$moduleKey] ?? $moduleKey; ?>
                <div class="col-sm-6 col-xl-4">
                    <div class="border rounded-3 p-3 h-100 rbac-module-tile" data-module="<?= h($moduleKey) ?>">
                        <label class="d-flex gap-3 align-items-start mb-2" for="module-access-<?= h($moduleKey) ?>">
                            <input id="module-access-<?= h($moduleKey) ?>" class="form-check-input module-checkbox mt-1" type="checkbox" name="module_access_keys[]" value="<?= h($moduleSlug) ?>" data-module="<?= h($moduleKey) ?>" <?= in_array($moduleKey, $rolePermissions, true) ? 'checked' : '' ?>>
                            <span>
                                <strong><i class="fa-solid <?= h($module['icon'] ?? 'fa-layer-group') ?>"></i> <?= h($module['label']) ?></strong>
                                <span class="d-block text-muted small"><?= $module['actions'] ? count($module['actions']) . ' action permission(s)' : 'Module access only' ?></span>
                            </span>
                        </label>
                        <?php if (!empty($module['actions'])): ?>
                            <button class="btn btn-sm btn-outline-primary" type="button" data-show-actions="<?= h($moduleKey) ?>" aria-expanded="false">View actions</button>
                            <div class="module-actions-inline mt-3 pt-3 border-top" data-module-panel="<?= h($moduleKey) ?>" hidden>
                                <div class="row g-2">
                                    <?php foreach ($module['actions'] as $actionKey => $actionLabel): ?>
                                        <div class="col-md-6">
                                            <label class="form-check border rounded-3 p-3 h-100">
                                                <input class="form-check-input action-checkbox" type="checkbox" name="permission_keys[]" value="<?= h($actionKey) ?>" data-module="<?= h($moduleKey) ?>" data-bulk="<?= h(rbac_permission_bulk_group($actionKey)) ?>" <?= in_array($actionKey, $rolePermissions, true) ? 'checked' : '' ?>>
                                                <span class="form-check-label"><?= h($actionLabel) ?></span>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <span class="small text-muted">No separate actions</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="panel">
        <div class="text-end">
            <button type="submit" name="save_role_permissions" value="1" class="btn btn-primary">Save Role</button>
        </div>
    </section>
</form>
<?php else: ?>
    <section class="panel">Select a role to edit its module access and permissions.</section>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('rbac-permissions-form');
    if (!form) return;

    var moduleTiles = Array.from(form.querySelectorAll('.rbac-module-tile'));
    var moduleCheckboxes = Array.from(form.querySelectorAll('.module-checkbox'));
    var actionCheckboxes = Array.from(form.querySelectorAll('.action-checkbox'));
    var panels = Array.from(form.querySelectorAll('.module-actions-inline'));
    var actionButtons = Array.from(form.querySelectorAll('[data-show-actions]'));
    var activeAction = actionCheckboxes.find(function (cb) { return cb.checked; });
    var activeModule = (activeAction || moduleCheckboxes.find(function (cb) { return cb.checked; }) || moduleCheckboxes[0] || {}).dataset?.module;

    function showPanel(moduleKey, forceOpen) {
        var panel = panels.find(function (item) { return item.dataset.modulePanel === moduleKey; });
        var button = actionButtons.find(function (item) { return item.dataset.showActions === moduleKey; });
        if (!panel || !button) return;
        var open = typeof forceOpen === 'boolean' ? forceOpen : panel.hidden;
        panel.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        button.textContent = open ? 'Hide actions' : 'View actions';
        moduleTiles.forEach(function (tile) {
            tile.classList.toggle('border-primary', tile.dataset.module === moduleKey && open);
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
        button.addEventListener('click', function () {
            showPanel(button.dataset.showActions);
        });
    });

    moduleCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', function () {
            if (!checkbox.checked) {
                actionCheckboxes.filter(function (action) { return action.dataset.module === checkbox.dataset.module; }).forEach(function (action) {
                    action.checked = false;
                });
            }
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

    if (activeModule) showPanel(activeModule, true);
});
</script>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
