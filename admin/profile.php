<?php
$pageTitle = 'My Profile';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$adminId = (int) ($_SESSION['admin_id'] ?? 0);
$user = $adminId > 0 ? get_user_by_id($pdo, $adminId) : null;
if (!$user) {
    flash('error', 'Your profile could not be found. Please log in again.');
    redirect('admin/logout.php');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $username = trim($_POST['username'] ?? '');

    if ($name === '') {
        $errors[] = 'Name is required.';
    }
    if ($username === '') {
        $errors[] = 'Username is required.';
    }

    if (!$errors) {
        $stmt = $pdo->prepare('SELECT id FROM admin WHERE username = :username AND id <> :id LIMIT 1');
        $stmt->execute(['username' => $username, 'id' => $adminId]);
        if ($stmt->fetch()) {
            $errors[] = 'Username is already in use.';
        }
    }

    if (!$errors) {
        save_admin_user($pdo, [
            'id' => $adminId,
            'name' => $name,
            'username' => $username,
            'status' => $user['status'],
            'class_level' => $user['class_level'] ?? null,
        ]);
        $_SESSION['admin_name'] = $name;
        flash('success', 'Profile updated successfully.');
        redirect('admin/profile.php');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = trim($_POST['current_password'] ?? '');
    $newPassword = trim($_POST['new_password'] ?? '');
    $confirmPassword = trim($_POST['confirm_password'] ?? '');
    $record = get_user_by_username($pdo, $user['username']);

    if (!$record || !password_verify($currentPassword, $record['password_hash'])) {
        $errors[] = 'Current password is incorrect.';
    }
    if (strlen($newPassword) < 8) {
        $errors[] = 'New password must be at least 8 characters.';
    }
    if ($newPassword !== $confirmPassword) {
        $errors[] = 'New passwords do not match.';
    }

    if (!$errors) {
        save_admin_user($pdo, [
            'id' => $adminId,
            'name' => $user['name'],
            'username' => $user['username'],
            'status' => $user['status'],
            'class_level' => $user['class_level'] ?? null,
            'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
        ]);
        flash('success', 'Password changed successfully.');
        redirect('admin/profile.php#change-password');
    }
}

$roleName = get_user_role_template_name($pdo, $adminId);
$effectiveModules = [];
foreach (rbac_module_definitions() as $moduleKey => $module) {
    if (current_admin_can_access_module($pdo, $module['name'])) {
        $effectiveModules[] = $module['name'];
    }
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<div class="page-title">
    <div>
        <p class="eyebrow">Account</p>
        <h1>My Profile</h1>
        <p class="mb-0 text-muted">Manage your personal account details.</p>
    </div>
</div>

<?php if ($message = flash('success')): ?><div class="alert alert-success"><?= h($message) ?></div><?php endif; ?>
<?php if ($message = flash('error')): ?><div class="alert alert-danger"><?= h($message) ?></div><?php endif; ?>
<?php if ($errors): ?>
    <div class="alert alert-danger">
        <?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?>
    </div>
<?php endif; ?>

<div class="row g-4">
    <div class="col-lg-4">
        <section class="panel">
            <div class="panel-heading">
                <h2>User Information</h2>
            </div>
            <div class="mini-stat mb-3"><span>Name</span><strong><?= h($user['name']) ?></strong></div>
            <div class="mini-stat mb-3"><span>Username</span><strong><?= h($user['username']) ?></strong></div>
            <div class="mini-stat mb-3"><span>Role</span><strong><?= h($roleName) ?></strong></div>
            <div class="mini-stat"><span>Module Access</span><strong><?= count($effectiveModules) ?> module(s)</strong></div>
        </section>
    </div>
    <div class="col-lg-8">
        <section class="panel">
            <div class="panel-heading">
                <h2>Profile Details</h2>
            </div>
            <form method="post" class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="name">Name</label>
                    <input class="form-control" id="name" name="name" value="<?= h($user['name']) ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="username">Username</label>
                    <input class="form-control" id="username" name="username" value="<?= h($user['username']) ?>" required>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit" name="update_profile" value="1">Save Profile</button>
                </div>
            </form>
        </section>

        <section class="panel mt-4" id="change-password">
            <div class="panel-heading">
                <h2>Change My Password</h2>
            </div>
            <form method="post" class="row g-3">
                <div class="col-12">
                    <label class="form-label" for="current_password">Current Password</label>
                    <input class="form-control" type="password" id="current_password" name="current_password" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="new_password">New Password</label>
                    <input class="form-control" type="password" id="new_password" name="new_password" required minlength="8">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="confirm_password">Confirm New Password</label>
                    <input class="form-control" type="password" id="confirm_password" name="confirm_password" required minlength="8">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary" type="submit" name="change_password" value="1">Change Password</button>
                </div>
            </form>
        </section>
    </div>
</div>

<?php require_once __DIR__ . '/../includes/layout_end.php'; ?>
