<?php
require_once __DIR__ . '/../config/app.php';

$emailSubject = rawurlencode('School portal password assistance');
$emailBody = rawurlencode("Hello Arise To Excel Academy,\n\nI need help accessing my school portal account.\n\nMy username is: ");
$schoolPhone = preg_replace('/[^0-9+]/', '', SCHOOL_PHONE);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign-in Help | <?= h(SCHOOL_NAME) ?></title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/ui-refresh.css') ?>">
</head>
<body class="login-page login-help-page">
<main class="login-help-card">
    <a class="login-help-logo" href="<?= url('admin/login.php') ?>">
        <img src="<?= asset('images/school-logo.jpg') ?>" alt="Arise To Excel Academy logo">
    </a>
    <span class="login-card-icon"><i class="fa-solid fa-lock" aria-hidden="true"></i></span>
    <h1>Need help signing in?</h1>
    <p>For your security, password changes are handled by the school office. Contact the school and include your portal username. Never send your password.</p>
    <div class="login-help-actions">
        <a class="btn btn-primary" href="mailto:<?= h(SCHOOL_EMAIL) ?>?subject=<?= h($emailSubject) ?>&body=<?= h($emailBody) ?>"><i class="fa-regular fa-envelope" aria-hidden="true"></i> Email the school</a>
        <a class="btn btn-outline-primary" href="tel:<?= h($schoolPhone) ?>"><i class="fa-solid fa-phone" aria-hidden="true"></i> Call <?= h(SCHOOL_PHONE) ?></a>
    </div>
    <a class="login-help-back" href="<?= url('admin/login.php') ?>"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in</a>
</main>
</body>
</html>
