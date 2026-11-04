<?php
$pageTitle = 'Roles Management';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Module access check
if (!current_admin_has_permission($pdo, 'users_permissions.access')) {
    flash('error', 'You do not have permission to access Roles Management.');
    redirect('admin/dashboard.php');
}

$editingId = (int) ($_GET['edit'] ?? 0);
$editingRole = null;
if ($editingId > 0) {
    $editStmt = $pdo->prepare("SELECT id, COALESCE(role_name, name) AS name, description
                               FROM roles
                               WHERE id = :id
                                 AND LOWER(TRIM(COALESCE(NULLIF(role_name, ''), name))) <> 'parent'
                               LIMIT 1");
    $editStmt->execute(['id' => $editingId]);
    $editingRole = $editStmt->fetch() ?: null;
    if (!$editingRole) {
        flash('error', 'Role not found.');
        redirect('admin/roles.php');
    }
}

// Handle role creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_role'])) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($name === '') {
        flash('error', 'Role name is required.');
        redirect('admin/roles.php');
    }

    if (strcasecmp($name, 'Parent') === 0) {
        flash('error', 'Parent is a system-managed role and cannot be created here.');
        redirect('admin/roles.php');
    }

    $stmt = $pdo->prepare('SELECT id FROM roles WHERE name = :legacy_name OR role_name = :role_name LIMIT 1');
    $stmt->execute(['legacy_name' => $name, 'role_name' => $name]);
    if ($stmt->fetch()) {
        flash('error', 'Role already exists.');
        redirect('admin/roles.php');
    }

    try {
        $insert = $pdo->prepare('INSERT INTO roles (role_name, name, description) VALUES (:role_name, :name, :description)');
        $insert->execute(['role_name' => $name, 'name' => $name, 'description' => $description ?: null]);
        flash('success', 'Role created successfully.');
        redirect('admin/roles.php');
    } catch (Exception $e) {
        flash('error', 'Failed to create role: ' . $e->getMessage());
        redirect('admin/roles.php');
    }
}

// Handle role update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_role'])) {
    $roleId = (int) ($_POST['role_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');

    if ($roleId <= 0) {
        flash('error', 'Invalid role selected.');
        redirect('admin/roles.php');
    }
    if ($name === '') {
        flash('error', 'Role name is required.');
        redirect('admin/roles.php?edit=' . $roleId);
    }

    if (strcasecmp($name, 'Parent') === 0) {
        flash('error', 'Parent is a system-managed role and cannot be assigned to a staff role.');
        redirect('admin/roles.php?edit=' . $roleId);
    }

    $currentRoleStmt = $pdo->prepare('SELECT COALESCE(role_name, name) AS name FROM roles WHERE id = :id LIMIT 1');
    $currentRoleStmt->execute(['id' => $roleId]);
    $currentRole = $currentRoleStmt->fetch();
    
    if (!$currentRole) {
        flash('error', 'Role not found.');
        redirect('admin/roles.php');
    }

    if (strcasecmp(trim((string) $currentRole['name']), 'Parent') === 0) {
        flash('error', 'Parent is a system-managed role and cannot be renamed here.');
        redirect('admin/roles.php');
    }

    $stmt = $pdo->prepare('SELECT id FROM roles WHERE (name = :legacy_name OR role_name = :role_name) AND id <> :id LIMIT 1');
    $stmt->execute(['legacy_name' => $name, 'role_name' => $name, 'id' => $roleId]);
    if ($stmt->fetch()) {
        flash('error', 'Role name already exists.');
        redirect('admin/roles.php?edit=' . $roleId);
    }

    try {
        $update = $pdo->prepare('UPDATE roles SET role_name = :role_name, name = :legacy_name, description = :description WHERE id = :id');
        $update->execute(['role_name' => $name, 'legacy_name' => $name, 'description' => $description ?: null, 'id' => $roleId]);
        flash('success', 'Role updated successfully.');
        redirect('admin/roles.php');
    } catch (Exception $e) {
        flash('error', 'Failed to update role: ' . $e->getMessage());
        redirect('admin/roles.php?edit=' . $roleId);
    }
}

// Handle role deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_role'])) {
    $roleId = (int) ($_POST['role_id'] ?? 0);

    if ($roleId <= 0) {
        flash('error', 'Invalid role selected.');
        redirect('admin/roles.php');
    }

    try {
        $roleStmt = $pdo->prepare('SELECT id, COALESCE(role_name, name) AS name FROM roles WHERE id = :id LIMIT 1');
        $roleStmt->execute(['id' => $roleId]);
        $role = $roleStmt->fetch();
        if (!$role) {
            flash('error', 'Role not found.');
            redirect('admin/roles.php');
        }

        if (strcasecmp(trim((string) $role['name']), 'Parent') === 0) {
            flash('error', 'Parent is a system-managed role and cannot be deleted here.');
            redirect('admin/roles.php');
        }

        $usersStmt = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id');
        $usersStmt->execute(['role_id' => $roleId]);
        $userCount = (int) $usersStmt->fetchColumn();

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
        $pdo->prepare('DELETE FROM module_access WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
        $pdo->prepare('DELETE FROM user_roles WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
        $pdo->prepare('DELETE FROM roles WHERE id = :id')->execute(['id' => $roleId]);
        $pdo->commit();

        $message = 'Role deleted successfully.';
        if ($userCount > 0) {
            $message .= ' ' . $userCount . ' user(s) were unassigned from it.';
        }
        flash('success', $message);
        redirect('admin/roles.php');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        flash('error', 'Failed to delete role: ' . $e->getMessage());
        redirect('admin/roles.php');
    }
}

// Fetch roles
$roles = $pdo->query("SELECT id, COALESCE(role_name, name) AS name, description, created_at
                      FROM roles
                      WHERE LOWER(TRIM(COALESCE(NULLIF(role_name, ''), name))) <> 'parent'
                      ORDER BY COALESCE(role_name, name)")->fetchAll();

require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">System Administration</p>
        <h1>Roles Management</h1>
    </div>
    <div class="action-row">
        <a class="btn btn-outline-primary" href="<?= url('admin/settings.php') ?>">Settings</a>
    </div>
</div>

<?php if ($message = flash('error')): ?>
    <div class="alert alert-danger"><?= h($message) ?></div>
<?php endif; ?>
<?php if ($message = flash('success')): ?>
    <div class="alert alert-success"><?= h($message) ?></div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-5">
        <section class="panel">
            <div class="panel-heading">
                <h2><?= $editingRole ? 'Edit Role' : 'Create New Role' ?></h2>
            </div>
            <form method="post" class="p-3">
                <?php if ($editingRole): ?>
                    <input type="hidden" name="role_id" value="<?= (int) $editingRole['id'] ?>">
                <?php endif; ?>
                <div class="mb-3">
                    <label class="form-label" for="name">Role Name</label>
                    <input class="form-control" type="text" id="name" name="name" required value="<?= h($editingRole['name'] ?? '') ?>">
                </div>
                <div class="mb-3">
                    <label class="form-label" for="description">Description</label>
                    <textarea class="form-control" id="description" name="description" rows="3"><?= h($editingRole['description'] ?? '') ?></textarea>
                </div>
                <div class="d-grid gap-2">
                    <?php if ($editingRole): ?>
                        <button class="btn btn-primary w-100" type="submit" name="update_role" value="1">Update Role</button>
                        <a class="btn btn-outline-secondary w-100" href="<?= url('admin/roles.php') ?>">Cancel</a>
                    <?php else: ?>
                        <button class="btn btn-primary w-100" type="submit" name="create_role" value="1">Create Role</button>
                    <?php endif; ?>
                </div>
            </form>
        </section>
    </div>

    <div class="col-lg-7">
        <section class="panel">
            <div class="panel-heading">
                <h2>Available Roles</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Role Name</th>
                            <th>Description</th>
                            <th>Created</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($roles as $role): ?>
                            <tr>
                                <td><strong><?= h($role['name']) ?></strong></td>
                                <td><?= h($role['description'] ?: 'No description') ?></td>
                                <td><?= h($role['created_at']) ?></td>
                                <td class="text-end">
                                    <div class="d-flex justify-content-end gap-1">
                                        <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/roles.php?edit=' . $role['id']) ?>">Edit</a>
                                        <form method="post" class="m-0" onsubmit="return confirm('Delete role ' + <?= json_encode($role['name']) ?> + '? Users assigned to this role will be unassigned. This action cannot be undone.');">
                                            <input type="hidden" name="role_id" value="<?= (int) $role['id'] ?>">
                                            <button class="btn btn-sm btn-outline-danger" type="submit" name="delete_role" value="1" title="Delete <?= h($role['name']) ?>">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$roles): ?><tr><td colspan="4">No roles configured yet.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
