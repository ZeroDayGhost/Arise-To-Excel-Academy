        </main>
    </div>
</div>
<script>
window.APP_CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || (form.getAttribute('method') || 'get').toLowerCase() !== 'post') {
        return;
    }
    let token = form.querySelector('input[name="_csrf_token"]');
    if (!token) {
        token = document.createElement('input');
        token.type = 'hidden';
        token.name = '_csrf_token';
        form.appendChild(token);
    }
    token.value = window.APP_CSRF_TOKEN;
});
</script>
<script src="<?= asset('vendor/bootstrap/bootstrap.bundle.min.js') ?>"></script>
<script src="<?= asset('vendor/chartjs/chart.umd.min.js') ?>"></script>
<script src="<?= asset_version('js/main.js') ?>"></script>
<?php require __DIR__ . '/session_timeout_prompt.php'; ?>
</body>
</html>
