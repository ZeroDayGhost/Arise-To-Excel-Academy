<?php if (is_admin_logged_in()): ?>
<link rel="stylesheet" href="<?= asset_version('css/session-timeout.css') ?>">
<div class="session-timeout" id="session-timeout" hidden>
    <div class="session-timeout__backdrop" aria-hidden="true"></div>
    <section class="session-timeout__dialog" role="alertdialog" aria-modal="true" aria-labelledby="session-timeout-title" aria-describedby="session-timeout-copy" tabindex="-1">
        <div class="session-timeout__icon" aria-hidden="true"><i class="fa-solid fa-shield-halved"></i></div>
        <h2 id="session-timeout-title">Still there?</h2>
        <p id="session-timeout-copy">For payment security, you will be signed out soon after inactivity. Choose to stay signed in or sign out now.</p>
        <p class="session-timeout__countdown">Signing out in <strong id="session-timeout-countdown">30</strong> seconds</p>
        <div class="session-timeout__actions">
            <button class="btn btn-primary" type="button" id="session-timeout-stay">Stay logged in</button>
            <form method="post" action="<?= h(url('admin/logout.php')) ?>" id="session-timeout-logout-form">
                <input type="hidden" name="_csrf_token" value="<?= h(csrf_token()) ?>">
                <button class="btn btn-outline-secondary" type="submit">Log out now</button>
            </form>
        </div>
        <p class="session-timeout__error" id="session-timeout-error" role="status" hidden>Could not refresh your session. Please try again.</p>
    </section>
</div>
<script>
window.APP_SESSION_TIMEOUT = <?= json_encode([
    'timeoutMs' => APP_SESSION_IDLE_TIMEOUT * 1000,
    'warningMs' => 30000,
    'keepAliveUrl' => url('admin/session_keepalive.php'),
    'expireUrl' => url('admin/session_expire.php'),
    'loginUrl' => url('admin/login.php?session_expired=1'),
    'csrfToken' => csrf_token(),
    'lastActivityAt' => (int) ($_SESSION['last_activity_at'] ?? time()),
    'activityNonce' => (string) ($_SESSION['_activity_nonce'] ?? ''),
]) ?>;
</script>
<script src="<?= asset_version('js/session-timeout.js') ?>"></script>
<?php endif; ?>
