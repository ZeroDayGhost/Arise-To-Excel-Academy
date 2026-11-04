<?php
require_once __DIR__ . '/../config/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    if (!is_admin_logged_in()) {
        redirect('admin/login.php');
    }
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Sign Out | <?= h(APP_NAME) ?></title>
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap">
        <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
        <link rel="stylesheet" href="<?= asset_version('css/dashboard.css') ?>">
        <link rel="stylesheet" href="<?= asset_version('css/ui-refresh.css') ?>">
    </head>
    <body class="admin-body signout-page">
    <main class="container signout-card">
        <span class="login-card-icon" aria-hidden="true"><i class="fa-solid fa-arrow-right-from-bracket"></i></span>
        <h1 class="h3">Sign out</h1>
        <p class="text-muted">Are you sure you want to sign out?</p>
        <form method="post" action="<?= h(url('admin/logout.php')) ?>">
            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
            <button class="btn btn-primary" type="submit">Sign out</button>
            <a class="btn btn-outline-secondary" href="<?= h(url('admin/dashboard.php')) ?>">Cancel</a>
        </form>
    </main>
    </body>
    </html>
    <?php
    exit;
}

csrf_require_valid_request();
app_destroy_session();
redirect('admin/login.php');
