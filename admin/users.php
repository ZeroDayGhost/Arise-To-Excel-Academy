<?php
$pageTitle = 'Users Management';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Module access check
if (!current_admin_has_permission($pdo, 'users_permissions.access')) {
    flash('error', 'You do not have permission to access Users Management.');
    redirect('admin/dashboard.php');
}

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');

    if ($name === '' || $username === '' || $password === '') {
        flash('error', 'Please fill in all required fields.');
        redirect('admin/users.php');
    }

    if ($password !== $confirmPassword) {
        flash('error', 'Passwords do not match.');
        redirect('admin/users.php');
    }

    if (strlen($password) < 8) {
        flash('error', 'Password must be at least 8 characters.');
        redirect('admin/users.php');
    }

    $stmt = $pdo->prepare('SELECT id FROM admin WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    if ($stmt->fetch()) {
        flash('error', 'Username already exists.');
        redirect('admin/users.php');
    }

    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $insert = $pdo->prepare('INSERT INTO admin (name, username, password_hash) VALUES (:name, :username, :hash)');
        $insert->execute(['name' => $name, 'username' => $username, 'hash' => $hash]);
        flash('success', 'User created successfully.');
        redirect('admin/users.php');
    } catch (Exception $e) {
        flash('error', 'Failed to create user: ' . $e->getMessage());
        redirect('admin/users.php');
    }
}

// Handle role assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_role'])) {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $roleIds = $_POST['role_ids'] ?? [];

    if ($userId <= 0) {
        flash('error', 'Please select a user.');
        redirect('admin/users.php');
    }

    if (is_parent_account_user($pdo, $userId)) {
        flash('error', 'Parent accounts are managed from Parent Accounts.');
        redirect('admin/users.php');
    }

    $roleIds = is_array($roleIds) ? array_values(array_unique(array_map('intval', $roleIds))) : [];
    $availableRoleIds = array_map('intval', array_column(get_stored_role_templates($pdo), 'id'));
    foreach ($roleIds as $roleId) {
        if ($roleId <= 0 || !in_array($roleId, $availableRoleIds, true)) {
            flash('error', 'Select only valid staff roles. Parent is managed separately.');
            redirect('admin/users.php');
        }
    }

    try {
        // Remove existing roles
        $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id')->execute(['user_id' => $userId]);

        // Add new roles
        $stmt = $pdo->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)');
        foreach ($roleIds as $roleId) {
            $stmt->execute(['user_id' => $userId, 'role_id' => (int) $roleId]);
        }

        flash('success', 'Roles assigned successfully.');
        redirect('admin/users.php');
    } catch (Exception $e) {
        flash('error', 'Failed to assign roles: ' . $e->getMessage());
        redirect('admin/users.php');
    }
}


// Fetch users
$parentAccountCondition = parent_account_identity_condition($pdo, 'a');
$users = $pdo->query(
    "SELECT a.id, a.name, a.username, a.created_at, GROUP_CONCAT(COALESCE(r.role_name, r.name) SEPARATOR ', ') AS roles
     FROM admin a
     LEFT JOIN user_roles ur ON ur.user_id = a.id
     LEFT JOIN roles r ON r.id = ur.role_id
     WHERE NOT {$parentAccountCondition}
     GROUP BY a.id
     ORDER BY a.name"
)->fetchAll();

// Fetch available roles
$availableRoles = get_stored_role_templates($pdo);


require_once __DIR__ . '/../includes/admin_header.php';
?>
<div class="page-title">
    <div>
        <p class="eyebrow">System Administration</p>
        <h1>Users Management</h1>
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
                <h2>Create New User</h2>
            </div>
            <form method="post" class="p-3">
                <div class="mb-3">
                    <label class="form-label" for="name">Full Name</label>
                    <input class="form-control" type="text" id="name" name="name" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <input class="form-control" type="text" id="username" name="username" required>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <input class="form-control" type="password" id="password" name="password" required minlength="8">
                    <small class="form-text text-muted">Minimum 8 characters</small>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="confirm_password">Confirm Password</label>
                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>
                <button class="btn btn-primary w-100" type="submit" name="create_user" value="1">Create User</button>
            </form>
        </section>
    </div>

    <div class="col-lg-7">
        <section class="panel">
            <div class="panel-heading">
                <h2>Admin Users</h2>
            </div>
            <div class="table-responsive">
                <table class="table table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Username</th>
                            <th>Roles</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?= h($user['name']) ?></td>
                                <td><?= h($user['username']) ?></td>
                                <td><?= h($user['roles'] ?: 'No roles') ?></td>
                                <td><?= h($user['created_at']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$users): ?><tr><td colspan="4">No users found.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <div class="col-lg-5">
        <section class="panel">
            <div class="panel-heading">
                <h2>Assign Roles to User</h2>
            </div>
            <?php if ($availableRoles): ?>
                <form method="post" class="p-3">
                    <div class="mb-3">
                        <label class="form-label" for="user_id">Select User</label>
                        <select class="form-select" id="user_id" name="user_id" required>
                            <option value="">-- Choose a user --</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= h($user['id']) ?>"><?= h($user['name'] . ' (' . $user['username'] . ')') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Select Roles</label>
                        <div class="border rounded p-3" style="max-height: 200px; overflow-y: auto;">
                            <?php foreach ($availableRoles as $role): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="role_<?= h($role['id']) ?>" name="role_ids[]" value="<?= h($role['id']) ?>">
                                    <label class="form-check-label" for="role_<?= h($role['id']) ?>">
                                        <?= h($role['name']) ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <button class="btn btn-primary w-100" type="submit" name="assign_role" value="1">Assign Roles</button>
                </form>
            <?php else: ?>
                <div class="p-3">
                    <div class="alert alert-info">
                        No roles available. <a href="<?= url('admin/roles.php') ?>">Create roles first</a>.
                    </div>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
