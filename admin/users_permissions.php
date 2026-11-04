<?php
$pageTitle = 'Users & Permissions';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

// Verify access to this page
if (!current_admin_has_permission($pdo, 'users_permissions.access')) {
    flash('error', 'You do not have permission to access Users & Permissions.');
    redirect('admin/dashboard.php');
}

// Ensure permission records exist without recreating role templates that an admin deleted.
ensure_permissions_exist($pdo);

$templateLabels = get_permission_template_dropdown($pdo);
$statusOptions = get_user_status_options();
$classLevels = class_level_options();
$storedRoles = get_stored_role_templates($pdo);
$availableRoles = $storedRoles;

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');
    $status = trim($_POST['status'] ?? 'Active');
    $templateName = trim($_POST['template_name'] ?? '');
    $classLevel = trim($_POST['class_level'] ?? '');
    $permissionKeys = $_POST['permission_keys'] ?? [];

    if ($name === '' || $username === '' || $password === '') {
        flash('error', 'Name, username, and password are required.');
        redirect('admin/users_permissions.php');
    }

    if ($password !== $confirmPassword) {
        flash('error', 'Passwords do not match.');
        redirect('admin/users_permissions.php');
    }

    if (strlen($password) < 8) {
        flash('error', 'Password must be at least 8 characters.');
        redirect('admin/users_permissions.php');
    }

    if (!in_array($status, get_user_status_options(), true)) {
        flash('error', 'Invalid status.');
        redirect('admin/users_permissions.php');
    }

    if (strcasecmp($templateName, 'Parent') === 0) {
        flash('error', 'Parent accounts are created and managed from Parent Accounts.');
        redirect('admin/users_permissions.php');
    }

    if ($templateName === '' || !in_array($templateName, $templateLabels, true)) {
        flash('error', 'Please select a valid role.');
        redirect('admin/users_permissions.php');
    }

    if ($templateName === 'Teacher' && !in_array($classLevel, $classLevels, true)) {
        flash('error', 'Please select a valid class for Teacher role.');
        redirect('admin/users_permissions.php');
    }

    $stmt = $pdo->prepare('SELECT id FROM admin WHERE username = :username LIMIT 1');
    $stmt->execute(['username' => $username]);
    if ($stmt->fetch()) {
        flash('error', 'Username already exists.');
        redirect('admin/users_permissions.php');
    }

    try {
        $userId = save_admin_user($pdo, [
            'name' => $name,
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => $status,
            'class_level' => $templateName === 'Teacher' ? $classLevel : null,
        ]);

        // Clear previous role/permissions to ensure a clean initial state
        clear_user_role_assignments($pdo, $userId);
        clear_custom_user_permissions($pdo, $userId);

        // Always assign the selected template as a role (no 'Custom' option)
        assign_user_role_by_name($pdo, $userId, $templateName);

        flash('success', "User '{$name}' created successfully.");
        redirect('admin/user_permissions_detail.php?user_id=' . $userId);
    } catch (Exception $e) {
        flash('error', 'Failed to create user: ' . $e->getMessage());
        redirect('admin/users_permissions.php');
    }
}

// Handle user role template assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_template'])) {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $templateName = trim($_POST['template_name'] ?? '');
    $classLevel = trim($_POST['class_level'] ?? '');

    if ($userId <= 0 || $templateName === '') {
        flash('error', 'Please select a user and template.');
        redirect('admin/users_permissions.php');
    }

    if (strcasecmp($templateName, 'Parent') === 0 || !in_array($templateName, $templateLabels, true)) {
        flash('error', 'Parent accounts are managed from Parent Accounts. Select a valid staff role.');
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

    if ($templateName === 'Teacher' && !in_array($classLevel, $classLevels, true)) {
        flash('error', 'Please select a valid class for Teacher role.');
        redirect('admin/users_permissions.php');
    }

    try {
        clear_user_role_assignments($pdo, $userId);
        clear_custom_user_permissions($pdo, $userId);

        if ($templateName !== 'Custom') {
            assign_user_role_by_name($pdo, $userId, $templateName);
        }

        save_admin_user($pdo, [
            'id' => $userId,
            'name' => $user['name'],
            'username' => $user['username'],
            'status' => $user['status'],
            'class_level' => $templateName === 'Teacher' ? $classLevel : null,
        ]);

        flash('success', "Template '{$templateName}' assigned successfully.");
        redirect('admin/users_permissions.php?user_id=' . $userId);
    } catch (Exception $e) {
        flash('error', 'Failed to assign template: ' . $e->getMessage());
        redirect('admin/users_permissions.php');
    }
}

// Handle custom permission assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_custom_permissions'])) {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $permissionKeys = $_POST['permission_keys'] ?? [];

    if ($userId <= 0) {
        flash('error', 'Please select a user.');
        redirect('admin/users_permissions.php');
    }

    if (is_parent_account_user($pdo, $userId)) {
        flash('error', 'Parent accounts are managed from Parent Accounts.');
        redirect('admin/users_permissions.php');
    }

    try {
        // Do not clear the user's assigned role when saving custom permissions here.
        // This preserves the Role label (e.g., Teacher) while allowing explicit
        // user permissions to be stored alongside role permissions.
        $permissionKeys = sanitize_user_permissions($permissionKeys);
        set_user_permissions($pdo, $userId, $permissionKeys);

        flash('success', 'Custom permissions assigned successfully.');
        redirect('admin/users_permissions.php?user_id=' . $userId);
    } catch (Exception $e) {
        flash('error', 'Failed to assign permissions: ' . $e->getMessage());
        redirect('admin/users_permissions.php');
    }
}

// Handle user status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $status = trim($_POST['status'] ?? '');

    if ($userId <= 0 || !in_array($status, get_user_status_options(), true)) {
        flash('error', 'Invalid user or status.');
        redirect('admin/users_permissions.php');
    }

    if (is_parent_account_user($pdo, $userId)) {
        flash('error', 'Parent accounts are managed from Parent Accounts.');
        redirect('admin/users_permissions.php');
    }

    try {
        $user = get_user_by_id($pdo, $userId);
        if (!$user) {
            flash('error', 'User not found.');
            redirect('admin/users_permissions.php');
        }

        save_admin_user($pdo, [
            'id' => $userId,
            'name' => $user['name'],
            'username' => $user['username'],
            'status' => $status,
        ]);

        flash('success', 'User status updated successfully.');
        redirect('admin/users_permissions.php?user_id=' . $userId);
    } catch (Exception $e) {
        flash('error', 'Failed to update status: ' . $e->getMessage());
        redirect('admin/users_permissions.php');
    }
}

// Handle user deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_user']) || ($_POST['action'] ?? '') === 'delete_user')) {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $currentAdminId = (int) ($_SESSION['admin_id'] ?? 0);

    if ($userId <= 0) {
        flash('error', 'Invalid user selected.');
        redirect('admin/users_permissions.php');
    }

    if ($userId === $currentAdminId) {
        flash('error', 'You cannot delete your own account while logged in.');
        redirect('admin/users_permissions.php');
    }

    try {
        $user = get_user_by_id($pdo, $userId);
        if (!$user) {
            flash('error', 'User not found.');
            redirect('admin/users_permissions.php');
        }

        if (is_parent_account_user($pdo, $userId)) {
            flash('error', 'Parent accounts are managed from Parent Accounts.');
            redirect('admin/users_permissions.php');
        }

        if (in_array(strtolower($user['username']), ['admin'], true) || strtolower(trim($user['name'])) === 'system administrator') {
            flash('error', 'You cannot delete the System Administrator account.');
            redirect('admin/users_permissions.php');
        }

        delete_admin_user($pdo, $userId);
        flash('success', 'User deleted successfully.');
        redirect('admin/users_permissions.php');
    } catch (Exception $e) {
        flash('error', 'Failed to delete user: ' . $e->getMessage());
        redirect('admin/users_permissions.php');
    }
}

// Handle role creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_role'])) {
    $roleName = trim($_POST['role_name'] ?? '');
    $roleDescription = trim($_POST['role_description'] ?? '');

    if ($roleName === '') {
        flash('error', 'Role name is required.');
        redirect('admin/users_permissions.php');
    }

    if (strcasecmp($roleName, 'Parent') === 0) {
        flash('error', 'Parent is a system-managed role and cannot be created here.');
        redirect('admin/users_permissions.php');
    }

    $existingRole = get_role_by_name($pdo, $roleName);
    if ($existingRole) {
        flash('error', 'Role name already exists.');
        redirect('admin/users_permissions.php');
    }

    try {
        find_or_create_role($pdo, $roleName, $roleDescription);
        flash('success', 'Role created successfully.');
        redirect('admin/users_permissions.php');
    } catch (Exception $e) {
        flash('error', 'Failed to create role: ' . $e->getMessage());
        redirect('admin/users_permissions.php');
    }
}

// Handle role deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (isset($_POST['delete_role']) || ($_POST['action'] ?? '') === 'delete_role')) {
    // API-level permission check (must match page-level access check)
    rbac_require_permission($pdo, 'users_permissions.access', 'You do not have permission to delete roles.');
    
    $roleId = (int) ($_POST['role_id'] ?? 0);

    if ($roleId <= 0) {
        flash('error', 'Invalid role selected.');
        redirect('admin/users_permissions.php');
    }

    try {
        $roleStmt = $pdo->prepare('SELECT id, COALESCE(role_name, name) AS name FROM roles WHERE id = :id LIMIT 1');
        $roleStmt->execute(['id' => $roleId]);
        $role = $roleStmt->fetch();
        if (!$role) {
            flash('error', 'Role not found.');
            redirect('admin/users_permissions.php');
        }

        if (strcasecmp(trim((string) $role['name']), 'Parent') === 0) {
            flash('error', 'Parent is a system-managed role and cannot be deleted here.');
            redirect('admin/users_permissions.php');
        }

        $usersStmt = $pdo->prepare('SELECT COUNT(*) FROM user_roles WHERE role_id = :role_id');
        $usersStmt->execute(['role_id' => $roleId]);
        $userCount = (int) $usersStmt->fetchColumn();

        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM role_permissions WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
        $pdo->prepare('DELETE FROM module_access WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
        $pdo->prepare('DELETE FROM user_roles WHERE role_id = :role_id')->execute(['role_id' => $roleId]);
        $deleteStmt = $pdo->prepare('DELETE FROM roles WHERE id = :id');
        $deleteStmt->execute(['id' => $roleId]);
        $pdo->commit();
        
        // Audit log the deletion
        audit_permission_check($pdo, (int) ($_SESSION['admin_id'] ?? 0), 'role.delete', 'Role: ' . $role['name'], true, 'INFO', [
            'role_id' => $roleId,
            'role_name' => $role['name'],
            'users_unassigned' => $userCount,
        ]);
        
        $message = 'Role deleted successfully.';
        if ($userCount > 0) {
            $message .= ' ' . $userCount . ' user(s) were unassigned from it.';
        }
        flash('success', $message);
        redirect('admin/users_permissions.php');
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        audit_permission_check($pdo, (int) ($_SESSION['admin_id'] ?? 0), 'role.delete', 'Role ID: ' . $roleId, false, 'ERROR', [
            'error' => $e->getMessage(),
        ]);
        flash('error', 'Failed to delete role: ' . $e->getMessage());
        redirect('admin/users_permissions.php');
    }
}

// Fetch data
$adminUsers = get_admin_users($pdo);

// If any user doesn't have an explicit role_name (older records or permissions-based),
// try to infer a template name so the UI shows a meaningful Role value.
foreach ($adminUsers as &$u) {
    if (empty($u['role_name'])) {
        $u['role_name'] = get_user_role_template_name($pdo, (int) $u['id']);
    }
}
unset($u);
$templateLabels = get_permission_template_dropdown($pdo);
$storedRoles = get_stored_role_templates($pdo);
$statusOptions = get_user_status_options();
$availableRoles = $storedRoles;

// Calculate summary statistics
$allPermissions = permission_definitions();

// Flatten all permissions (module level + action level)
$flattenedPermissions = [];
foreach ($allPermissions as $module) {
    if (isset($module['permission_key'])) {
        $flattenedPermissions[] = $module['permission_key'];
    }
    if (isset($module['actions']) && is_array($module['actions'])) {
        foreach ($module['actions'] as $actionKey => $actionLabel) {
            $flattenedPermissions[] = $actionKey;
        }
    }
}
$totalPermissions = count($flattenedPermissions);

// Count modules (each module definition)
$totalModules = count($allPermissions);

// Count active users
$activeUsersCount = count(array_filter($adminUsers, static function (array $user): bool {
    return ($user['status'] ?? '') === 'Active';
}));

// Get role statistics (count users per role)
$roleStats = [];
$parentAccountCondition = parent_account_identity_condition($pdo, 'a');
foreach ($storedRoles as $role) {
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS count
         FROM user_roles ur
         JOIN admin a ON a.id = ur.user_id
         WHERE ur.role_id = :role_id
           AND NOT {$parentAccountCondition}"
    );
    $stmt->execute(['role_id' => $role['id']]);
    $userCount = (int) $stmt->fetch()['count'];
    $roleStats[$role['id']] = [
        'role' => $role,
        'users_count' => $userCount,
    ];
}

// Get role permissions and module access for each role
foreach ($roleStats as &$stat) {
    $roleId = $stat['role']['id'];
    $rolePermKeys = get_role_permission_keys($pdo, $roleId);
    $accessMap = rbac_module_access_keys();

    $roleModules = [];
    foreach ($rolePermKeys as $permissionKey) {
        if (isset($accessMap[$permissionKey])) {
            $moduleName = rbac_module_name($accessMap[$permissionKey]);
            if (!in_array($moduleName, $roleModules, true)) {
                $roleModules[] = $moduleName;
            }
        }
    }
    
    $stat['modules'] = $roleModules;
    $stat['permissions'] = $rolePermKeys;
    $stat['permissions_count'] = count($rolePermKeys);
}
unset($stat);

// Active tab and context
$activeTab = $_GET['tab'] ?? 'users';
$selectedUserId = (int) ($_GET['user_id'] ?? 0);
$selectedUser = $selectedUserId ? get_user_by_id($pdo, $selectedUserId) : null;

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="users-permissions-page">
<div class="page-title">
    <div>
        <p class="eyebrow">System Administration</p>
        <h1>Users & Permissions</h1>
        <p class="mb-0 text-muted">Manage administrator users, assign roles, and configure permissions.</p>
    </div>
</div>

<?php if ($msg = flash('success')): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= h($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($msg = flash('error')): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= h($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Summary Cards -->
<div class="row g-4 mb-4">
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted small mb-1">Total Users</p>
                <h2 class="h3 mb-0"><?= count($adminUsers) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted small mb-1">Active Users</p>
                <h2 class="h3 mb-0"><?= $activeUsersCount ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted small mb-1">Total Roles</p>
                <h2 class="h3 mb-0"><?= count($storedRoles) ?></h2>
            </div>
        </div>
    </div>
    <div class="col-md-6 col-lg-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body text-center">
                <p class="text-muted small mb-1">Total Permissions</p>
                <h2 class="h3 mb-0"><?= $totalPermissions ?></h2>
            </div>
        </div>
    </div>
</div>

<!-- Split Screen Layout -->
<div class="row g-4 users-permissions-main-row">
    <!-- Left Column: User Creation Form -->
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 sticky-top users-permissions-card" style="top: 80px;">
            <div class="card-body">
                <div class="mb-4">
                    <h2 class="h5 mb-1">Create New User</h2>
                    <p class="text-muted mb-0 small">Add a new administrator account.</p>
                </div>

                <form method="post" id="create-user-form">
                    <div class="mb-3">
                        <label class="form-label" for="name">Full Name <span class="text-danger">*</span></label>
                        <input class="form-control" type="text" id="name" name="name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="username">Username <span class="text-danger">*</span></label>
                        <input class="form-control" type="text" id="username" name="username" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="password">Password <span class="text-danger">*</span></label>
                        <input class="form-control" type="password" id="password" name="password" required minlength="8">
                        <div class="form-text">Minimum 8 characters</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="confirm_password">Confirm Password <span class="text-danger">*</span></label>
                        <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="8">
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="template_name">Assign Role <span class="text-danger">*</span></label>
                        <select class="form-select" id="template_name" name="template_name" required>
                            <option value="">Select Role</option>
                            <?php foreach ($templateLabels as $template): ?>
                                <option value="<?= h($template) ?>"><?= h($template) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3" id="class-level-group" style="display: none;">
                        <label class="form-label" for="class_level">Class Level</label>
                        <select class="form-select" id="class_level" name="class_level">
                            <option value="">Select Class</option>
                            <?php foreach ($classLevels as $level): ?>
                                <option value="<?= h($level) ?>"><?= h($level) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">Required for Teacher role</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="status">Status <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <?php foreach ($statusOptions as $option): ?>
                                <option value="<?= h($option) ?>" <?= $option === 'Active' ? 'selected' : '' ?>><?= h($option) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn btn-primary w-100" type="submit" name="create_user" value="1">Create User</button>
                </form>
            </div>
        </div>

        <div class="card shadow-sm border-0 mt-4 users-permissions-card">
            <div class="card-body">
                <div class="mb-4">
                    <h2 class="h5 mb-1">Create New Role</h2>
                    <p class="text-muted mb-0 small">Add a new role and make it available in the Assign Role dropdown.</p>
                </div>
                <form method="post" id="create-role-form" class="permission-role-form">
                    <div>
                        <label class="form-label" for="new-role-name">Role name</label>
                        <input class="form-control" type="text" id="new-role-name" name="role_name" required placeholder="Enter role name">
                    </div>
                    <div>
                        <label class="form-label" for="new-role-description">Description</label>
                        <input class="form-control" type="text" id="new-role-description" name="role_description" placeholder="Optional description">
                    </div>
                    <button class="btn btn-primary w-100" type="submit" name="create_role" value="1">Create Role</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Roles & Permissions Summary -->
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 users-permissions-card">
            <div class="card-body">
                <div class="permissions-section-heading">
                    <div class="permissions-section-copy">
                        <h2 class="h5 mb-1">Roles & Permissions Summary</h2>
                        <p class="text-muted mb-0 small">Manage and view all role configurations.</p>
                    </div>
                    <a href="<?= url('admin/role_permissions.php') ?>" class="btn btn-sm btn-outline-primary">Manage All Roles</a>
                </div>

                <?php if (empty($storedRoles)): ?>
                    <div class="alert alert-info">
                        <p class="mb-0">No roles created yet. <a href="<?= url('admin/role_permissions.php') ?>">Create your first role</a> to get started.</p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>Role Name</th>
                                    <th class="text-center">Users Assigned</th>
                                    <th class="text-center">Modules Accessible</th>
                                    <th class="text-center">Permissions Count</th>
                                    <th class="text-end">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roleStats as $stat): ?>
                                    <tr>
                                        <td>
                                            <strong><?= h($stat['role']['name']) ?></strong>
                                            <?php if (!empty($stat['role']['description'])): ?>
                                                <br><small class="text-muted"><?= h($stat['role']['description']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-primary"><?= $stat['users_count'] ?></span>
                                        </td>
                                        <td class="text-center">
                                            <?php if (empty($stat['modules'])): ?>
                                                <span class="text-muted">—</span>
                                            <?php else: ?>
                                                <button type="button" class="btn btn-sm btn-outline-info" data-bs-toggle="tooltip" title="<?= h(implode(', ', $stat['modules'])) ?>">
                                                    <?= count($stat['modules']) ?> modules
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-secondary"><?= $stat['permissions_count'] ?></span>
                                        </td>
                                        <td class="text-end">
                                            <div class="d-flex justify-content-end gap-1">
                                                <a href="<?= url('admin/role_permissions.php?role_id=' . $stat['role']['id']) ?>" class="btn btn-sm btn-outline-primary">
                                                    View / Edit
                                                </a>
                                                <a href="<?= url('admin/roles.php?edit=' . $stat['role']['id']) ?>" class="btn btn-sm btn-outline-secondary">
                                                    Rename
                                                </a>
                                                <form method="post" action="<?= url('admin/users_permissions.php') ?>" class="m-0" onsubmit="return confirm('Delete role ' + <?= json_encode($stat['role']['name']) ?> + '? Users assigned to this role will be unassigned. This action cannot be undone.');">
                                                    <input type="hidden" name="action" value="delete_role">
                                                    <input type="hidden" name="role_id" value="<?= (int) $stat['role']['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete <?= h($stat['role']['name']) ?>">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Users Table -->
        <div class="card shadow-sm border-0 mt-4 users-permissions-card">
            <div class="card-body">
                <h2 class="h5 mb-3">Administrator Users</h2>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Name</th>
                                <th>Username</th>
                                <th class="text-center">Status</th>
                                <th>Role</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($adminUsers as $user): ?>
                                <?php 
                                    $userStatus = get_active_user_status($pdo, $user['id']);
                                    $userTemplate = !empty($user['role_name']) ? $user['role_name'] : get_user_role_template_name($pdo, $user['id']);
                                    $statusBadge = $userStatus === 'Active' ? 'success' : 'secondary';
                                ?>
                                <tr>
                                    <td><strong><?= h($user['name']) ?></strong></td>
                                    <td><code><?= h($user['username']) ?></code></td>
                                    <td class="text-center"><span class="badge bg-<?= $statusBadge ?>"><?= h($userStatus) ?></span></td>
                                    <td><?= h($userTemplate) ?></td>
                                    <td class="text-end">
                                        <div class="d-flex justify-content-end gap-1">
                                            <a href="user_permissions_detail.php?user_id=<?= $user['id'] ?>" class="btn btn-sm btn-outline-primary" title="Manage permissions">
                                                <i class="fa-solid fa-shield-halved"></i> Permissions
                                            </a>
                                            <?php if (!in_array(strtolower($userTemplate), ['administrator', 'admin', 'system administrator'], true)
                                                      && !in_array(strtolower($user['username']), ['admin'], true)
                                                      && strtolower(trim($user['name'])) !== 'system administrator'): ?>
                                                <form method="post" class="m-0" onsubmit="return confirm('Delete ' + <?= json_encode($user['name']) ?> + '?');">
                                                    <input type="hidden" name="action" value="delete_user">
                                                    <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete user">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$adminUsers): ?>
                                <tr><td colspan="5" class="text-center text-muted py-4">No users found.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        var createRoleSelect = document.getElementById('template_name');
        var createClassLevelGroup = document.getElementById('class-level-group');
        var createClassLevelSelect = document.getElementById('class_level');

        function toggleCreateClassLevel() {
            if (!createRoleSelect || !createClassLevelGroup || !createClassLevelSelect) {
                return;
            }

            if (createRoleSelect.value === 'Teacher') {
                createClassLevelGroup.style.display = '';
                createClassLevelSelect.required = true;
            } else {
                createClassLevelGroup.style.display = 'none';
                createClassLevelSelect.required = false;
                createClassLevelSelect.value = '';
            }
        }

        if (createRoleSelect) {
            createRoleSelect.addEventListener('change', toggleCreateClassLevel);
            toggleCreateClassLevel();
        }

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    });

</script>
