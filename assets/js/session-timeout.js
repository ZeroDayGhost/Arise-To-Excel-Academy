(() => {
    const config = window.APP_SESSION_TIMEOUT;
    const root = document.getElementById('session-timeout');
    if (!config || !root) return;

    const dialog = root.querySelector('.session-timeout__dialog');
    const stayButton = document.getElementById('session-timeout-stay');
    const countdown = document.getElementById('session-timeout-countdown');
    const error = document.getElementById('session-timeout-error');
    const activityKey = `app-session-activity:${config.activityNonce}`;
    const logoutKey = `app-session-logout:${config.activityNonce}`;
    let lastActivity = Number(config.lastActivityAt) * 1000;
    let lastHeartbeat = 0;
    let warningOpen = false;
    let expiring = false;
    let previousFocus = null;

    try {
        const sharedActivity = Number(localStorage.getItem(activityKey) || 0);
        if (sharedActivity > lastActivity && Date.now() - sharedActivity < config.timeoutMs) {
            lastActivity = sharedActivity;
        }
    } catch (_) {}

    const setHidden = (hidden) => {
        root.hidden = hidden;
        root.setAttribute('aria-hidden', hidden ? 'true' : 'false');
    };

    const post = async (url) => {
        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-Token': config.csrfToken,
            },
        });
        if (!response.ok) {
            const requestError = new Error(`Session request failed (${response.status})`);
            requestError.status = response.status;
            throw requestError;
        }
        return response.json();
    };

    const closeWarning = () => {
        if (!warningOpen) return;
        warningOpen = false;
        setHidden(true);
        error.hidden = true;
        if (previousFocus && document.contains(previousFocus)) previousFocus.focus({ preventScroll: true });
    };

    const recordActivity = (broadcast = true) => {
        if (expiring) return;
        lastActivity = Date.now();
        if (warningOpen) closeWarning();
        if (broadcast) {
            try { localStorage.setItem(activityKey, String(lastActivity)); } catch (_) {}
        }
        if (lastActivity - lastHeartbeat >= 5000) sendHeartbeat();
    };

    const sendHeartbeat = async () => {
        if (expiring || Date.now() - lastHeartbeat < 5000) return;
        lastHeartbeat = Date.now();
        try {
            const result = await post(config.keepAliveUrl);
            if (!result.ok) throw new Error('Session no longer active');
        } catch (requestError) {
            if (requestError.status === 401 || requestError.status === 419) {
                expiring = true;
                window.location.replace(config.loginUrl);
                return;
            }
            if (Date.now() - lastActivity >= config.timeoutMs) expireSession();
        }
    };

    const showWarning = () => {
        if (warningOpen || expiring) return;
        warningOpen = true;
        previousFocus = document.activeElement;
        setHidden(false);
        error.hidden = true;
        stayButton.focus({ preventScroll: true });
    };

    const expireSession = async (broadcast = true) => {
        if (expiring) return;
        expiring = true;
        try { await post(config.expireUrl); } catch (_) {}
        if (broadcast) {
            try { localStorage.setItem(logoutKey, String(Date.now())); } catch (_) {}
        }
        window.location.replace(config.loginUrl);
    };

    stayButton.addEventListener('click', async () => {
        stayButton.disabled = true;
        error.hidden = true;
        try {
            const result = await post(config.keepAliveUrl);
            if (!result.ok) throw new Error('Session no longer active');
            lastHeartbeat = Date.now();
            recordActivity();
        } catch (requestError) {
            error.hidden = false;
            if (requestError.status === 401 || requestError.status === 419) {
                expiring = true;
                window.location.replace(config.loginUrl);
            } else if (Date.now() - lastActivity >= config.timeoutMs) {
                expireSession();
            }
        } finally {
            stayButton.disabled = false;
        }
    });

    dialog.addEventListener('keydown', (event) => {
        if (event.key !== 'Tab') return;
        const focusable = Array.from(dialog.querySelectorAll('button:not([disabled]), input:not([disabled])'))
            .filter((element) => element.getClientRects().length > 0);
        if (!focusable.length) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    const activityEvents = ['pointerdown', 'keydown', 'touchstart', 'wheel', 'input', 'change'];
    activityEvents.forEach((eventName) => document.addEventListener(eventName, (event) => {
        if (event.target instanceof Element && event.target.closest('#session-timeout')) return;
        recordActivity();
    }, { passive: true, capture: true }));

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) update();
    });

    window.addEventListener('storage', (event) => {
        if (event.key === activityKey && event.newValue) {
            const sharedActivity = Number(event.newValue);
            if (Number.isFinite(sharedActivity) && sharedActivity > lastActivity) {
                lastActivity = sharedActivity;
                closeWarning();
            }
        } else if (event.key === logoutKey && event.newValue) {
            expiring = true;
            window.location.replace(config.loginUrl);
        }
    });

    function update() {
        if (expiring) return;
        const remaining = config.timeoutMs - (Date.now() - lastActivity);
        if (remaining <= 0) {
            expireSession();
            return;
        }
        if (remaining <= config.warningMs) {
            showWarning();
            countdown.textContent = String(Math.max(1, Math.ceil(remaining / 1000)));
        }
    }

    window.setInterval(update, 1000);
    update();
})();
