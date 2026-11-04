        </main>
        <footer class="parent-footer">
            <span>Arise To Excel Academy <i aria-hidden="true">|</i> Fee Portal</span>
            <span class="parent-footer-trust"><i class="fa-solid fa-shield-heart" aria-hidden="true"></i> Secure &amp; private <i aria-hidden="true">|</i> <?= date('Y') ?></span>
            <?php $parentWhatsAppNumber = preg_replace('/\D+/', '', SCHOOL_PHONE); ?>
            <a class="parent-whatsapp-help" href="https://wa.me/<?= h($parentWhatsAppNumber) ?>?text=<?= rawurlencode('Hello, I need help with the Parent Fee Portal.') ?>" target="_blank" rel="noopener noreferrer" aria-label="Chat with Arise To Excel Academy on WhatsApp" title="Chat on WhatsApp: <?= h(SCHOOL_PHONE) ?>">
                <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
            </a>
        </footer>
    </div>
</div>
<script>
    (() => {
        const sidebar = document.getElementById('parent-sidebar');
        const toggle = document.querySelector('[data-parent-menu-toggle]');
        const closeItems = document.querySelectorAll('[data-parent-menu-close]');
        if (!sidebar || !toggle) return;
        const mobileQuery = window.matchMedia('(max-width: 991px)');
        const syncToggle = () => {
            const isExpanded = mobileQuery.matches
                ? document.body.classList.contains('parent-menu-open')
                : !document.body.classList.contains('parent-sidebar-collapsed');
            toggle.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
            toggle.setAttribute('aria-label', mobileQuery.matches
                ? (isExpanded ? 'Close navigation' : 'Open navigation')
                : (isExpanded ? 'Collapse sidebar' : 'Expand sidebar'));
            toggle.setAttribute('title', mobileQuery.matches
                ? (isExpanded ? 'Close navigation' : 'Open navigation')
                : (isExpanded ? 'Collapse sidebar' : 'Expand sidebar'));
        };
        const closeMenu = () => {
            document.body.classList.remove('parent-menu-open');
            syncToggle();
        };
        syncToggle();
        toggle.addEventListener('click', () => {
            if (mobileQuery.matches) {
                document.body.classList.toggle('parent-menu-open');
            } else {
                document.body.classList.toggle('parent-sidebar-collapsed');
            }
            syncToggle();
        });
        mobileQuery.addEventListener('change', syncToggle);
        closeItems.forEach((item) => item.addEventListener('click', closeMenu));
        sidebar.querySelectorAll('a').forEach((link) => link.addEventListener('click', closeMenu));
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeMenu();
        });
    })();
    (() => {
        const search = document.querySelector('[data-parent-search]');
        if (!search) return;
        const rows = Array.from(document.querySelectorAll('[data-parent-searchable]'));
        const status = document.querySelector('[data-parent-search-status]');
        search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            let visibleCount = 0;
            rows.forEach((row) => {
                row.hidden = query !== '' && !row.textContent.toLocaleLowerCase().includes(query);
                if (!row.hidden) visibleCount++;
            });
            if (status) {
                status.hidden = query === '' || visibleCount > 0;
                status.textContent = status.hidden ? '' : 'No matching records on this page.';
            }
        });
    })();
    (() => {
        const toggles = [
            document.querySelector('[data-parent-notification-toggle]'),
            document.querySelector('[data-parent-account-toggle]'),
        ].filter(Boolean);
        const closeAll = () => toggles.forEach((button) => {
            const panel = document.getElementById(button.getAttribute('aria-controls'));
            button.setAttribute('aria-expanded', 'false');
            if (panel) panel.hidden = true;
        });
        toggles.forEach((button) => button.addEventListener('click', (event) => {
            event.stopPropagation();
            const panel = document.getElementById(button.getAttribute('aria-controls'));
            const opening = button.getAttribute('aria-expanded') !== 'true';
            closeAll();
            if (panel && opening) {
                button.setAttribute('aria-expanded', 'true');
                panel.hidden = false;
            }
        }));
        document.addEventListener('click', (event) => {
            if (!event.target.closest('.parent-popover-wrap')) closeAll();
        });
        document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closeAll(); });
    })();
</script>
<?php require __DIR__ . '/session_timeout_prompt.php'; ?>
</body>
</html>
