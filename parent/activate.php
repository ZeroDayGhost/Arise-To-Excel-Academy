<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fee_helpers.php';
require_once __DIR__ . '/../includes/parent_registration_helpers.php';

if (!parent_portal_schema_ready($pdo) || !parent_registration_schema_ready($pdo)) {
    http_response_code(503);
    exit('Parent account activation is temporarily unavailable. Contact the school administration.');
}

$handoffRequested = ($_GET['handoff'] ?? '') === '1';
$handoff = $_SESSION['parent_activation_handoff'] ?? null;
$handoffActive = $handoffRequested
    && is_array($handoff)
    && (int) ($handoff['expires_at'] ?? 0) >= time()
    && preg_match('/^[A-Za-z0-9._-]{3,60}$/', (string) ($handoff['username'] ?? ''))
    && preg_match('/^[a-f0-9]{64}$/i', (string) ($handoff['activation_code'] ?? ''));
$handoffExpired = $handoffRequested && !$handoffActive;
if ($handoffExpired) {
    unset($_SESSION['parent_activation_handoff']);
}

$errors = [];
$username = trim((string) ($_POST['username'] ?? ''));
$activationCode = $handoffActive
    ? (string) $handoff['activation_code']
    : trim((string) ($_POST['activation_code'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid_request();
    $password = (string) ($_POST['password'] ?? '');
    $confirmation = (string) ($_POST['confirm_password'] ?? '');
    if ($handoffRequested && !$handoffActive) {
        $errors[] = 'This password setup has expired. Ask the school administrator for a new setup.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Choose a username using 3 to 60 letters, numbers, dots, underscores, or hyphens.';
    }
    if (!$handoffActive && !preg_match('/^[a-f0-9]{64}$/i', $activationCode)) {
        $errors[] = 'Enter the one-time activation code supplied by the school.';
    }
    if (strlen($password) < 12 || $password !== $confirmation) {
        $errors[] = 'Choose a password of at least 12 characters and enter it the same way in both fields.';
    }

    if (!$errors) {
        try {
            parent_registration_activate_account($pdo, $username, $activationCode, $password);
            $staffReturnTo = parent_registration_restore_staff_session();
            if ($staffReturnTo !== null) {
                flash('success', 'Parent account created successfully. You can continue managing parent access.');
                redirect($staffReturnTo);
            }
            unset($_SESSION['parent_activation_handoff']);
            session_regenerate_id(true);
            flash('success', 'Your parent account is active. Sign in with your username and new password.');
            redirect('admin/login.php');
        } catch (InvalidArgumentException $exception) {
            $errors[] = $exception->getMessage();
        } catch (Throwable $exception) {
            error_log('Parent activation failed with code ' . (string) $exception->getCode());
            $errors[] = 'Unable to activate the account right now. Contact the school and request a new code.';
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $handoffActive ? 'Create Parent Account' : 'Activate Parent Account' ?> | <?= h(SCHOOL_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/parent-portal.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/ui-refresh.css') ?>">
</head>
<body class="parent-portal parent-setup-screen">
<main class="parent-setup-layout">
    <section class="parent-setup-card-wrap">
        <article class="parent-setup-card">
            <header class="parent-setup-card-heading">
                <span class="parent-setup-card-icon"><i class="fa-solid fa-user-plus" aria-hidden="true"></i></span>
                <div><h2><?= $handoffActive ? 'Create Parent Account' : 'Activate Parent Account' ?></h2><p>Set up your account to access the parent portal.</p></div>
            </header>
            <div class="parent-setup-rule"><span></span></div>

            <?php if ($handoffExpired): ?>
                <div class="alert alert-warning" role="alert">This password setup has expired. Ask the school administrator for a new setup.</div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="alert alert-danger" role="alert"><?php foreach ($errors as $error): ?><div><?= h($error) ?></div><?php endforeach; ?></div>
            <?php endif; ?>

            <?php if (!$handoffExpired): ?>
                <form method="post" class="parent-setup-form">
                    <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                    <div class="parent-setup-field">
                        <label for="username">Create username</label>
                        <span class="parent-setup-input-icon"><i class="fa-solid fa-user" aria-hidden="true"></i><input id="username" name="username" value="<?= h($username) ?>" minlength="3" maxlength="60" pattern="[A-Za-z0-9._-]{3,60}" autocomplete="username" placeholder="Choose a username" required></span>
                        <small>Use 3 to 60 letters, numbers, dots, underscores, or hyphens.</small>
                    </div>
                    <?php if (!$handoffActive): ?>
                        <div class="parent-setup-field">
                            <label for="activation-code">One-time code</label>
                            <span class="parent-setup-input-icon"><i class="fa-solid fa-key" aria-hidden="true"></i><input id="activation-code" name="activation_code" value="<?= h($activationCode) ?>" inputmode="text" autocomplete="one-time-code" placeholder="Enter the code supplied by the school" required></span>
                        </div>
                    <?php endif; ?>
                    <div class="parent-setup-field">
                        <label for="password">New password</label>
                        <span class="parent-setup-input-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="password" name="password" type="password" minlength="12" autocomplete="new-password" placeholder="Enter your new password" required><button type="button" class="parent-password-toggle" data-password-toggle="password" aria-label="Show password"><i class="fa-regular fa-eye" aria-hidden="true"></i></button></span>
                    </div>
                    <div class="parent-password-hint"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>Use a password with at least 12 characters.</span></div>
                    <div class="parent-setup-field">
                        <label for="confirm-password">Confirm new password</label>
                        <span class="parent-setup-input-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i><input id="confirm-password" name="confirm_password" type="password" minlength="12" autocomplete="new-password" placeholder="Re-enter your new password" required><button type="button" class="parent-password-toggle" data-password-toggle="confirm-password" aria-label="Show password"><i class="fa-regular fa-eye" aria-hidden="true"></i></button></span>
                    </div>
                    <button class="parent-create-account" type="submit"><i class="fa-solid fa-user-plus" aria-hidden="true"></i><span><?= $handoffActive ? 'Create Account' : 'Activate Account' ?></span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
                    <div class="parent-setup-or"><span>OR</span></div>
                    <a class="parent-setup-back" href="<?= url('admin/login.php') ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to Sign In</a>
                    <span class="parent-setup-script" aria-hidden="true">Arise &amp; Excel</span>
                </form>
            <?php else: ?>
                <a class="parent-setup-back" href="<?= url('admin/login.php') ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to Sign In</a>
            <?php endif; ?>
        </article>
    </section>
</main>
<script>
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const input = document.getElementById(button.dataset.passwordToggle);
            if (!input) return;
            const showing = input.type === 'text';
            input.type = showing ? 'password' : 'text';
            button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
            button.innerHTML = `<i class="fa-regular ${showing ? 'fa-eye' : 'fa-eye-slash'}" aria-hidden="true"></i>`;
        });
    });
</script>
</body>
</html>
