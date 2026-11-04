<?php
require_once __DIR__ . '/../includes/parent_auth.php';

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmation = (string) ($_POST['confirm_password'] ?? '');
    $passwordQuery = $pdo->prepare('SELECT password_hash FROM admin WHERE id = :id AND status = \'Active\' LIMIT 1');
    $passwordQuery->execute(['id' => $parentUserId]);
    $passwordHash = (string) ($passwordQuery->fetchColumn() ?: '');

    if ($currentPassword === '' || !password_verify($currentPassword, $passwordHash)) {
        $errors[] = 'Your current password is incorrect.';
    }
    if (strlen($newPassword) < 12) {
        $errors[] = 'Use a new password with at least 12 characters.';
    }
    if ($newPassword !== $confirmation) {
        $errors[] = 'The new password fields do not match.';
    }
    if ($currentPassword !== '' && $currentPassword === $newPassword) {
        $errors[] = 'Choose a password different from your current password.';
    }

    if (!$errors) {
        $pdo->beginTransaction();
        try {
            $update = $pdo->prepare('UPDATE admin SET password_hash = :hash WHERE id = :id AND status = \'Active\'');
            $update->execute([
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => $parentUserId,
            ]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The parent password could not be updated.');
            }
            parent_portal_audit($pdo, $parentUserId, $parentUserId, null, 'parent_password_changed');
            $pdo->commit();
            session_regenerate_id(true);
            flash('success', 'Your password has been updated.');
            redirect('parent/settings.php');
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('Parent password update failed with code ' . (string) $exception->getCode());
            $errors[] = 'Unable to update your password right now. Please try again.';
        }
    }
}

$pageTitle = 'Settings';
require __DIR__ . '/../includes/parent_header.php';
?>
<div class="parent-page-heading parent-simple-heading">
    <div><span class="parent-page-eyebrow">ACCOUNT &amp; SECURITY</span><h1>Account settings</h1><p>Keep your Fee Portal sign-in secure and up to date.</p></div>
</div>
<section class="parent-content-panel parent-settings-panel">
    <div class="parent-section-title"><h2><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>Change password</h2><span class="parent-section-count">Private account</span></div>
    <div class="parent-settings-intro"><span><i class="fa-solid fa-lock" aria-hidden="true"></i></span><div><strong>Choose a strong password</strong><p>Use at least 12 characters. Your password is stored securely and is never displayed in the portal.</p></div></div>
    <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div>
    <?php endif; ?>
    <form method="post" class="parent-settings-form">
        <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
        <div class="mb-3"><label class="form-label" for="current-password">Current password</label><input class="form-control" id="current-password" name="current_password" type="password" autocomplete="current-password" required></div>
        <div class="mb-3"><label class="form-label" for="new-password">New password</label><input class="form-control" id="new-password" name="new_password" type="password" minlength="12" autocomplete="new-password" required></div>
        <div class="mb-3"><label class="form-label" for="confirm-password">Confirm new password</label><input class="form-control" id="confirm-password" name="confirm_password" type="password" minlength="12" autocomplete="new-password" required></div>
        <button class="btn btn-primary" type="submit"><i class="fa-solid fa-check" aria-hidden="true"></i> Update password</button>
    </form>
</section>
<?php require __DIR__ . '/../includes/parent_footer.php'; ?>
