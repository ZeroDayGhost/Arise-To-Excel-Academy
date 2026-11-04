<?php
$pageTitle = 'Login';
require_once __DIR__ . '/../config/app.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_require_valid_request();
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/fee_helpers.php';

$loginAreas = [
    'fees' => 'admin/fees.php',
    'students' => 'admin/students.php',
    'reports' => 'admin/reports.php',
    'administration' => 'admin/settings.php',
];
$requestedArea = (string) ($_POST['next'] ?? $_GET['next'] ?? '');
if (!array_key_exists($requestedArea, $loginAreas)) {
    $requestedArea = '';
}

if (is_admin_logged_in()) {
    $roles = get_user_role_names($pdo, (int) $_SESSION['admin_id']);
    if (in_array('Parent', $roles, true)) {
        redirect('parent/dashboard.php');
    }
    redirect($requestedArea !== '' ? $loginAreas[$requestedArea] : 'admin/dashboard.php');
}

$error = flash('error');
if (!$error && (($_GET['session_expired'] ?? '') === '1' || !empty($_SESSION['_session_expired']))) {
    $error = 'You were signed out after 5 minutes of inactivity.';
    unset($_SESSION['_session_expired']);
}
$success = flash('success');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    $statement = $pdo->prepare("SELECT * FROM admin WHERE username = :username LIMIT 1");
    $statement->execute(['username' => $username]);
    $admin = $statement->fetch();

    if ($admin && ($admin['status'] ?? 'Active') === 'Active' && password_verify($password, $admin['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['last_activity_at'] = time();
        $_SESSION['_activity_nonce'] = bin2hex(random_bytes(16));
        if (($_POST['remember_me'] ?? '') === '1') {
            $cookie = session_get_cookie_params();
            $options = [
                'expires' => time() + (14 * 24 * 60 * 60),
                'path' => $cookie['path'] ?: '/',
                'secure' => (bool) ($cookie['secure'] ?? false),
                'httponly' => true,
                'samesite' => $cookie['samesite'] ?? 'Lax',
            ];
            if (!empty($cookie['domain'])) {
                $options['domain'] = $cookie['domain'];
            }
            setcookie(session_name(), session_id(), $options);
        }
        $roles = get_user_role_names($pdo, (int) $admin['id']);
        if (in_array('Parent', $roles, true)) {
            redirect('parent/dashboard.php');
        }
        redirect($requestedArea !== '' ? $loginAreas[$requestedArea] : 'admin/dashboard.php');
    }

    $error = 'Invalid username or password.';
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In | <?= h(SCHOOL_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Onest:wght@400;500;600;700;800&display=swap">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="<?= asset('vendor/bootstrap/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/dashboard.css') ?>">
    <link rel="stylesheet" href="<?= asset_version('css/ui-refresh.css') ?>">
</head>
<body class="login-page login-showcase-page">
<main class="login-experience">
    <section class="login-showcase" aria-labelledby="showcase-heading">
        <div class="login-showcase-inner">
            <div class="login-showcase-brand">
                <img src="<?= asset('images/school-logo.jpg') ?>" alt="">
                <span><strong><?= h(SCHOOL_NAME) ?></strong><small>School Command Center</small></span>
            </div>

            <div class="login-showcase-copy">
                <p class="login-showcase-eyebrow"><span></span> A clearer view of school life</p>
                <h1 id="showcase-heading">Every school operation.<br><span>One command center.</span></h1>
                <p>Manage students, track payments, monitor expenses, and understand your school's finances from one powerful platform.</p>
            </div>

            <section class="login-preview" aria-label="School Command Center dashboard">
                <div class="login-preview-topbar">
                    <div class="login-preview-product"><span class="login-preview-mark"><i class="fa-solid fa-graduation-cap" aria-hidden="true"></i></span><span><b>School Command Center</b><small>School operations and finance</small></span></div>
                    <span class="login-preview-period"><i class="fa-regular fa-calendar" aria-hidden="true"></i> 2026 <span>·</span> Term 3</span>
                </div>
                <div class="login-preview-body">
                    <nav class="login-preview-nav" aria-label="Preview sections">
                        <span class="is-selected"><i class="fa-solid fa-chart-pie" aria-hidden="true"></i> Overview</span>
                        <span><i class="fa-solid fa-users" aria-hidden="true"></i> Students</span>
                        <span><i class="fa-solid fa-wallet" aria-hidden="true"></i> Fees</span>
                        <span><i class="fa-solid fa-chart-column" aria-hidden="true"></i> Reports</span>
                    </nav>
                    <div class="login-preview-content">
                        <div class="login-preview-caption"><div><small>YOUR SCHOOL AT A GLANCE</small><h2>One connected workspace</h2></div></div>

                        <article class="login-preview-scene is-active" data-login-scene="0" aria-hidden="false">
                            <div class="login-preview-stats">
                                <div class="login-preview-stat stat-blue"><span><i class="fa-solid fa-user-group" aria-hidden="true"></i> Total students</span><strong>61</strong><small>Across all classes</small></div>
                                <div class="login-preview-stat stat-green"><span><i class="fa-solid fa-money-bill-wave" aria-hidden="true"></i> Fees collected</span><strong>KES 20,850</strong><small>This term</small></div>
                                <div class="login-preview-stat stat-amber"><span><i class="fa-solid fa-scale-balanced" aria-hidden="true"></i> Fee balance</span><strong>KES 320,800</strong><small>Across the school</small></div>
                            </div>
                            <div class="login-preview-lower">
                                <div class="login-preview-chart"><div class="login-preview-block-heading"><span>Fee collection overview</span></div><div class="login-chart-bars" aria-hidden="true"><i style="--bar:35%"></i><i style="--bar:52%"></i><i style="--bar:45%"></i><i style="--bar:70%"></i><i style="--bar:58%"></i><i style="--bar:88%"></i><i style="--bar:76%"></i><i style="--bar:100%"></i></div><div class="login-chart-months"><span>Aug</span><span>Sep</span><span>Oct</span><span>Nov</span><span>Dec</span><span>Jan</span><span>Feb</span><span>Mar</span></div></div>
                                <div class="login-preview-enrolment"><span class="login-preview-enrolment-icon"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i></span><small>ENROLMENT</small><strong>61 <span>students</span></strong><div><i></i><i></i><i></i><i></i><i></i><i></i><i></i><i></i></div><small>Records organized by class</small></div>
                            </div>
                        </article>

                        <article class="login-preview-scene" data-login-scene="1" aria-hidden="true">
                            <div class="login-payment-layout">
                                <div class="login-payment-card"><div class="login-payment-heading"><span class="login-payment-icon"><i class="fa-solid fa-mobile-screen-button" aria-hidden="true"></i></span><span><small>PAYMENT WORKFLOW</small><b>Fees, recorded clearly</b></span><span class="login-payment-check"><i class="fa-solid fa-check" aria-hidden="true"></i></span></div><div class="login-payment-amount"><small>M-PESA payment</small><strong>KES 4,000.00</strong><span><i class="fa-solid fa-circle-check" aria-hidden="true"></i> Successfully recorded</span></div><div class="login-payment-meta"><span>Receipt number</span><b>ATXR-2026-00001</b></div><div class="login-payment-meta"><span>Payment method</span><b>M-PESA</b></div></div>
                                <div class="login-receipt-preview"><span class="login-receipt-icon"><i class="fa-solid fa-receipt" aria-hidden="true"></i></span><small>RECEIPT</small><strong>Payment received</strong><p>A clear record, ready to print or share with a family.</p><div><span>Amount</span><b>KES 4,000</b></div><div><span>Status</span><b class="is-paid">Paid</b></div><span class="login-receipt-lines" aria-hidden="true"></span></div>
                            </div>
                        </article>

                        <article class="login-preview-scene" data-login-scene="2" aria-hidden="true">
                            <div class="login-finance-summary"><div><small>MONTHLY FINANCIAL SUMMARY</small><h3>Understand the full picture</h3></div><span><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Reports</span></div>
                            <div class="login-finance-totals"><div><span class="login-finance-dot dot-revenue"></span><small>Revenue</small><strong>KES 84,500</strong></div><div><span class="login-finance-dot dot-expense"></span><small>Expenses</small><strong>KES 31,200</strong></div><div><span class="login-finance-dot dot-balance"></span><small>Outstanding</small><strong>KES 320,800</strong></div></div>
                            <div class="login-finance-chart"><div class="login-preview-block-heading"><span>Income &amp; expenses</span><small>Monthly view</small></div><div class="login-finance-bars" aria-hidden="true"><div><i style="--income:60%;--expense:32%"></i><small>Aug</small></div><div><i style="--income:78%;--expense:44%"></i><small>Sep</small></div><div><i style="--income:66%;--expense:38%"></i><small>Oct</small></div><div><i style="--income:92%;--expense:51%"></i><small>Nov</small></div><div><i style="--income:74%;--expense:42%"></i><small>Dec</small></div><div><i style="--income:100%;--expense:56%"></i><small>Jan</small></div></div></div>
                        </article>

                        <article class="login-preview-scene" data-login-scene="3" aria-hidden="true">
                            <div class="login-ops-heading"><div><small>EVERYDAY OPERATIONS</small><h3>People, services, and records</h3></div><span class="login-ops-status"><i></i> Connected</span></div>
                            <div class="login-ops-grid"><div><span class="ops-blue"><i class="fa-solid fa-user-graduate" aria-hidden="true"></i></span><small>STUDENTS</small><b>Class records</b><em>Enrollment at a glance</em></div><div><span class="ops-violet"><i class="fa-solid fa-bus-simple" aria-hidden="true"></i></span><small>TRANSPORT</small><b>Routes &amp; fees</b><em>Transport operations</em></div><div><span class="ops-green"><i class="fa-solid fa-utensils" aria-hidden="true"></i></span><small>FEEDING</small><b>Meal records</b><em>Daily school meals</em></div><div><span class="ops-amber"><i class="fa-solid fa-boxes-stacked" aria-hidden="true"></i></span><small>INVENTORY</small><b>Kitchen supplies</b><em>Purchases &amp; stock</em></div></div>
                            <div class="login-ops-activity"><span class="login-ops-activity-icon"><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i></span><span><small>RECENT ACTIVITY</small><b>Daily school activity, organized in one place</b></span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></div>
                        </article>

                        <div class="login-preview-controls" aria-label="Preview scenes">
                            <div class="login-preview-dots"><button type="button" class="is-active" data-login-scene-button="0" aria-label="Show school overview preview" aria-pressed="true"></button><button type="button" data-login-scene-button="1" aria-label="Show fee collection preview" aria-pressed="false"></button><button type="button" data-login-scene-button="2" aria-label="Show financial management preview" aria-pressed="false"></button><button type="button" data-login-scene-button="3" aria-label="Show school operations preview" aria-pressed="false"></button></div>
                            <span data-login-scene-label>School overview</span>
                        </div>
                    </div>
                </div>
            </section>
            <p class="login-showcase-foot"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Designed for the people who help every student thrive.</p>
        </div>
    </section>

    <section class="login-auth-panel" aria-labelledby="login-heading">
        <div class="login-auth-inner">
            <div class="login-mobile-intro">
                <div class="login-mobile-brand">
                    <img src="<?= asset('images/school-logo.jpg') ?>" alt="">
                    <span><b><?= h(SCHOOL_NAME) ?></b><small>School Command Center</small></span>
                </div>
                <div class="login-mobile-welcome">
                    <p class="login-mobile-eyebrow"><span></span>Get started</p>
                    <h2 id="login-heading">Welcome.</h2>
                    <p>Sign in to manage school fees, students, and daily operations.</p>
                </div>
                <div class="login-mobile-security"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Secure access for staff and families</div>
                <div class="login-mobile-tools" aria-label="School workspace tools">
                    <span><i class="fa-solid fa-coins" aria-hidden="true"></i>Fees</span>
                    <span><i class="fa-solid fa-users" aria-hidden="true"></i>Students</span>
                    <span><i class="fa-solid fa-chart-column" aria-hidden="true"></i>Reports</span>
                </div>
            </div>
            <div class="login-card" aria-labelledby="login-heading">
        <?php if ($success): ?>
            <div class="alert alert-success" role="status"><?= h($success) ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="alert alert-danger" role="alert"><?= h($error) ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="next" value="<?= h($requestedArea) ?>">
            <div class="mb-3 login-input-group">
                <label class="form-label" for="username">Username</label>
                <div class="login-input-control">
                    <i class="login-field-icon fa-regular fa-user" aria-hidden="true"></i>
                    <input class="form-control" id="username" name="username" value="<?= h($_POST['username'] ?? '') ?>" placeholder="Enter your username" autocomplete="username" required>
                </div>
            </div>
            <div class="mb-3 login-input-group">
                <label class="form-label" for="password">Password</label>
                <div class="login-input-control">
                    <i class="login-field-icon fa-solid fa-lock" aria-hidden="true"></i>
                    <input class="form-control" type="password" id="password" name="password" placeholder="Enter your password" autocomplete="current-password" required>
                    <button class="login-password-toggle" type="button" data-login-password-toggle aria-label="Show password" title="Show password"><i class="fa-regular fa-eye" aria-hidden="true"></i></button>
                </div>
            </div>
            <div class="login-options">
                <label class="form-check login-remember"><input class="form-check-input" type="checkbox" id="remember-me" name="remember_me" value="1"><span class="form-check-label">Remember me</span></label>
                <a href="<?= url('admin/forgot_password.php') ?>">Forgot password?</a>
            </div>
            <button class="btn btn-primary w-100 login-submit" type="submit"><span>Sign In</span><i class="fa-solid fa-arrow-right" aria-hidden="true"></i></button>
        </form>
                <p class="login-card-footer"><i class="fa-solid fa-lock" aria-hidden="true"></i> Secure sign in <span aria-hidden="true">·</span> <?= date('Y') ?> <?= h(SCHOOL_NAME) ?></p>
            </div>
            <p class="login-auth-note">Authorized staff and families only.</p>
        </div>
    </section>
</main>
<script>
    (() => {
        const password = document.getElementById('password');
        const toggle = document.querySelector('[data-login-password-toggle]');
        if (password && toggle) {
            toggle.addEventListener('click', () => {
                const show = password.type === 'password';
                password.type = show ? 'text' : 'password';
                toggle.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
                toggle.setAttribute('title', show ? 'Hide password' : 'Show password');
                toggle.innerHTML = `<i class="fa-regular ${show ? 'fa-eye-slash' : 'fa-eye'}" aria-hidden="true"></i>`;
            });
        }
        const form = document.querySelector('.login-card form');
        const username = document.getElementById('username');
        const remember = document.getElementById('remember-me');
        if (form && username && remember) {
            const key = 'arise-to-excel-remembered-username';
            try {
                const saved = localStorage.getItem(key);
                if (!username.value && saved) username.value = saved;
                remember.checked = Boolean(saved);
                form.addEventListener('submit', () => {
                    if (remember.checked) localStorage.setItem(key, username.value.trim());
                    else localStorage.removeItem(key);
                });
            } catch (error) {
                // Login remains usable when browser storage is disabled.
            }
        }

        const preview = document.querySelector('.login-preview');
        const scenes = Array.from(document.querySelectorAll('[data-login-scene]'));
        const sceneButtons = Array.from(document.querySelectorAll('[data-login-scene-button]'));
        const sceneLabel = document.querySelector('[data-login-scene-label]');
        const sceneNames = ['School overview', 'Fee collection', 'Financial management', 'School operations'];
        const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
        let activeScene = 0;
        let sceneTimer;
        let hovered = false;
        let focused = false;

        const showScene = (index) => {
            activeScene = index;
            scenes.forEach((scene, sceneIndex) => {
                const active = sceneIndex === index;
                scene.classList.toggle('is-active', active);
                scene.setAttribute('aria-hidden', active ? 'false' : 'true');
            });
            sceneButtons.forEach((button, buttonIndex) => {
                const active = buttonIndex === index;
                button.classList.toggle('is-active', active);
                button.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            if (sceneLabel) sceneLabel.textContent = sceneNames[index];
        };
        const stopSceneTimer = () => window.clearInterval(sceneTimer);
        const startSceneTimer = () => {
            stopSceneTimer();
            if (reducedMotion.matches || hovered || focused || document.hidden || scenes.length < 2) return;
            sceneTimer = window.setInterval(() => showScene((activeScene + 1) % scenes.length), 6000);
        };
        sceneButtons.forEach((button) => button.addEventListener('click', () => {
            showScene(Number(button.dataset.loginSceneButton));
            startSceneTimer();
        }));
        if (preview) {
            preview.addEventListener('mouseenter', () => { hovered = true; stopSceneTimer(); });
            preview.addEventListener('mouseleave', () => { hovered = false; startSceneTimer(); });
            preview.addEventListener('focusin', () => { focused = true; stopSceneTimer(); });
            preview.addEventListener('focusout', (event) => {
                if (!preview.contains(event.relatedTarget)) { focused = false; startSceneTimer(); }
            });
        }
        document.addEventListener('visibilitychange', startSceneTimer);
        reducedMotion.addEventListener?.('change', startSceneTimer);
        startSceneTimer();
    })();
</script>
</body>
</html>
