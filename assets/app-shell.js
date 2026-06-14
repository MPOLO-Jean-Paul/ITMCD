(function () {
    const APP = 'itmcd-app-shell-v1';
    const queueKey = APP + ':offline-queue';
    const draftPrefix = APP + ':draft:';
    const themeKey = APP + ':theme';
    const teacherNotificationKey = APP + ':teacher-last-notification';
    const themes = ['light', 'dark'];
    const modernCssVersion = '20260612-dashboard-push-grid-2';

    document.addEventListener('DOMContentLoaded', () => {
        const isBulletinPage = !!document.querySelector('.bulletin-paper');
        if (!isBulletinPage) {
            applyPageIdentity();
            document.body.classList.add('itm-app-shell', 'itm-modern-ui', 'itm-page-transition');
            applyTheme(getSavedTheme());
            injectModernTheme();
            wireFixedHeader();
            wireFixedFooter();
            createThemeSwitcher();
        }
        const swReady = registerServiceWorker();
        wirePushSubscriptions(swReady);
        wireTeacherNotifications(swReady);
        wireNativeFeedback();
        wirePersistentForms();
        wireOnlineState();
        replayQueue();
        wireAdaptiveText();
    });

    function injectModernTheme() {
        if (document.querySelector('link[data-itm-modern-theme]')) return;
        const existing = Array.from(document.querySelectorAll('link[rel="stylesheet"]')).find((link) => {
            const href = link.getAttribute('href') || '';
            return href.includes('assets/app-modern.css');
        });
        if (existing) {
            existing.dataset.itmModernTheme = 'true';
            return;
        }
        const root = document.querySelector('meta[name="itm-root"]')?.content || '';
        const link = document.createElement('link');
        link.rel = 'stylesheet';
        link.href = root + 'assets/app-modern.css?v=' + modernCssVersion;
        link.dataset.itmModernTheme = 'true';
        document.head.appendChild(link);
    }

    function applyPageIdentity() {
        const parts = location.pathname.split('/').filter(Boolean);
        const file = (parts.pop() || 'index.php').replace(/\.php$/i, '').toLowerCase();
        const folder = (parts.pop() || 'root').toLowerCase();
        const role = folder === 'admin' || folder === 'enseignant' ? folder : 'public';
        const page = role + '-' + file.replace(/[^a-z0-9]+/g, '-');

        document.body.dataset.itmRole = role;
        document.body.dataset.itmPage = page;
        document.body.classList.add('itm-role-' + role, 'itm-page-' + page);
    }

    function getSavedTheme() {
        let saved = 'light';
        try {
            saved = localStorage.getItem(themeKey) || localStorage.getItem('itmcd-theme') || 'light';
        } catch {}

        return themes.includes(saved) ? saved : 'light';
    }

    function applyTheme(theme) {
        const selected = themes.includes(theme) ? theme : 'light';
        try {
            localStorage.setItem(themeKey, selected);
            localStorage.setItem('itmcd-theme', selected);
        } catch {}

        document.documentElement.setAttribute('data-theme', selected);
        document.body.dataset.itmTheme = selected;
        document.body.dataset.itmResolvedTheme = selected;
    }

    function createThemeSwitcher() {
        if (document.querySelector('.itm-theme-switch')) return;

        const labels = { light: 'Clair', dark: 'Sombre' };
        const icons = { light: '☀', dark: '◐' };
        const control = document.createElement('div');
        control.className = 'itm-theme-switch';
        control.setAttribute('role', 'group');
        control.setAttribute('aria-label', 'Theme');
        control.innerHTML = '<span class="itm-theme-anchor" aria-hidden="true">◐</span>';

        themes.forEach((theme) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.dataset.theme = theme;
            button.innerHTML = '<span class="itm-theme-option-icon" aria-hidden="true">' + icons[theme] + '</span><span class="itm-sr-only">' + labels[theme] + '</span>';
            button.title = 'Theme ' + labels[theme].toLowerCase();
            button.setAttribute('aria-label', 'Theme ' + labels[theme].toLowerCase());
            button.addEventListener('click', () => {
                applyTheme(theme);
                updateThemeSwitcher(control);
                setTimeout(refreshFixedChromeOffsets, 60);
            });
            control.appendChild(button);
        });

        mountThemeSwitcher(control);
        updateThemeSwitcher(control);
        requestAnimationFrame(refreshFixedChromeOffsets);
    }

    function updateThemeSwitcher(control) {
        const selected = getSavedTheme();
        control.querySelectorAll('button').forEach((button) => {
            const active = button.dataset.theme === selected;
            button.classList.toggle('active', active);
            button.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }

    function mountThemeSwitcher(control) {
        const header = document.querySelector('.itm-fixed-header');
        if (!header) {
            document.body.appendChild(control);
            return;
        }

        const mount = getThemeMount(header);
        let slot = Array.from(mount.children).find((child) => child.classList.contains('itm-theme-slot'));
        if (!slot) {
            slot = document.createElement('div');
            slot.className = 'itm-theme-slot';
            mount.appendChild(slot);
        }

        control.classList.add('itm-theme-switch--in-header');
        document.body.classList.add('itm-theme-in-header');
        slot.appendChild(control);
    }

    function getThemeMount(header) {
        const preferred = ['header-right', 'container', 'nav-content', 'header-content', 'toolbar-inner', 'topbar-content', 'nav-inner'];
        const directChildren = Array.from(header.children);
        return directChildren.find((child) => preferred.some((className) => child.classList.contains(className))) || header;
    }

    function refreshFixedChromeOffsets() {
        const header = document.querySelector('.itm-fixed-header');
        if (header) {
            document.body.style.setProperty('--itm-header-h', Math.ceil(header.getBoundingClientRect().height || 0) + 'px');
        }
        const footer = document.querySelector('.itm-fixed-footer');
        if (footer) {
            const rect = footer.getBoundingClientRect();
            const style = window.getComputedStyle(footer);
            const bottom = parseFloat(style.bottom) || 0;
            document.body.style.setProperty('--itm-footer-h', Math.ceil((rect.height || 0) + Math.max(bottom, 0) + 16) + 'px');
        }
    }

    function wireFixedHeader() {
        const header = findAppHeader();
        if (!header) return;

        header.classList.add('itm-fixed-header');
        document.body.classList.add('itm-has-fixed-header');

        const updateOffset = () => {
            const height = Math.ceil(header.getBoundingClientRect().height || 0);
            document.body.style.setProperty('--itm-header-h', height + 'px');
        };

        updateOffset();
        window.addEventListener('resize', updateOffset, { passive: true });
        window.addEventListener('orientationchange', updateOffset, { passive: true });
        window.addEventListener('load', updateOffset, { once: true, passive: true });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(updateOffset).catch(() => {});
        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(updateOffset);
            observer.observe(header);
            header._itmResizeObserver = observer;
        }
        setTimeout(updateOffset, 350);
    }

    function wireFixedFooter() {
        const footer = findAppFooter();
        if (!footer) return;

        footer.classList.add('itm-fixed-footer');
        document.body.classList.add('itm-has-fixed-footer');

        const updateOffset = () => {
            const rect = footer.getBoundingClientRect();
            const style = window.getComputedStyle(footer);
            const bottom = parseFloat(style.bottom) || 0;
            const height = Math.ceil((rect.height || 0) + Math.max(bottom, 0) + 16);
            document.body.style.setProperty('--itm-footer-h', height + 'px');
        };

        updateOffset();
        window.addEventListener('resize', updateOffset, { passive: true });
        window.addEventListener('orientationchange', updateOffset, { passive: true });
        window.addEventListener('load', updateOffset, { once: true, passive: true });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(updateOffset).catch(() => {});
        if ('ResizeObserver' in window) {
            const observer = new ResizeObserver(updateOffset);
            observer.observe(footer);
            footer._itmResizeObserver = observer;
        }
        setTimeout(updateOffset, 350);
    }

    function findAppHeader() {
        const selectors = [
            '.glass-nav',
            '.quantum-nav',
            '.top-nav',
            '.top-app-bar',
            '.sticky-header',
            '.mobile-header',
            '.nav-header',
            '.app-header',
            '.page-header',
            '.topbar',
            '.toolbar',
            '.navbar',
            'header'
        ];

        for (const selector of selectors) {
            const elements = Array.from(document.querySelectorAll(selector));
            const header = elements.find((el) => {
                if (el.closest('.login-card') || el.closest('.bulletin-paper')) return false;
                if (el.matches('#sidebar, .sidebar, .dock, .tab-bar')) return false;
                const style = window.getComputedStyle(el);
                return style.display !== 'none' && style.visibility !== 'hidden';
            });
            if (header) return header;
        }

        if (document.querySelector('.login-card')) return null;
        return createFallbackHeader();
    }

    function findAppFooter() {
        if (document.querySelector('.login-card') || document.querySelector('.bulletin-paper')) return null;

        const selectors = [
            '.dock-wrapper',
            '.tab-bar',
            '.bottom-nav',
            '.bottom-bar',
            '.app-footer',
            '.footer-bar',
            'footer'
        ];

        for (const selector of selectors) {
            const elements = Array.from(document.querySelectorAll(selector));
            const footer = elements.find((el) => {
                if (el.closest('.login-card') || el.closest('.bulletin-paper')) return false;
                if (el.closest('.side-drawer, #sidebar, .sidebar')) return false;
                const style = window.getComputedStyle(el);
                return style.display !== 'none' && style.visibility !== 'hidden';
            });
            if (footer) return footer;
        }

        return null;
    }

    function createFallbackHeader() {
        const title = getPageTitle();
        const header = document.createElement('header');
        header.className = 'itm-auto-header';
        header.innerHTML = '<a href="index.php" class="itm-auto-back">Retour</a><strong>' + escapeHtml(title) + '</strong>';
        document.body.insertBefore(header, document.body.firstChild);
        return header;
    }

    function getPageTitle() {
        const page = document.body.dataset.itmPage || '';
        const titles = {
            'admin-index': 'Tableau de bord',
            'admin-liste-globale': 'Apprenants',
            'admin-ajouter-apprenant': 'Inscription',
            'admin-gestion-cours': 'Cours & événements',
            'admin-gestion-enseignants': 'Enseignants',
            'admin-gestion-notes': 'Cotes apprenants',
            'admin-gestion-promotions': 'Promotions',
            'admin-gestion-attributions': 'Attributions',
            'admin-cloture-annee': 'Annee scolaire',
            'admin-deliberation': 'Deliberation',
            'admin-monitoring': 'Monitoring',
            'admin-parametres': 'Parametres',
            'admin-grille-inf1': 'Grille',
            'admin-modifier-note': 'Modifier note',
            'admin-edit-apprenant': 'Modifier apprenant',
            'enseignant-index': 'Espace enseignant',
            'enseignant-saisie-moyennes': 'Cotes',
            'enseignant-encodage-examen': 'Encodage des cotes',
            'enseignant-export-notes': 'Export notes',
            'enseignant-stats-enseignant': 'Statistiques',
            'enseignant-reglages': 'Reglages',
            'enseignant-grille-inf1': 'Grille',
            'enseignant-install': 'Installation',
            'public-login': 'Connexion'
        };

        return titles[page] || document.querySelector('h1,h2,h3')?.textContent?.trim() || 'ITMCD';
    }

    function escapeHtml(value) {
        return String(value).replace(/[&<>"']/g, (char) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[char]));
    }

    function registerServiceWorker() {
        if (!('serviceWorker' in navigator)) return Promise.resolve(null);
        const root = getAppRoot();
        return navigator.serviceWorker.register(root + 'service-worker.js').catch(() => null);
    }

    function getAppRoot() {
        return document.querySelector('meta[name="itm-root"]')?.content || '';
    }

    function urlBase64ToUint8Array(base64String) {
        const padding = '='.repeat((4 - base64String.length % 4) % 4);
        const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        const rawData = window.atob(base64);
        const outputArray = new Uint8Array(rawData.length);
        for (let i = 0; i < rawData.length; ++i) {
            outputArray[i] = rawData.charCodeAt(i);
        }
        return outputArray;
    }

    function wirePushSubscriptions(swReady) {
        const publicKey = document.querySelector('meta[name="itm-vapid-public-key"]')?.content || '';
        if (!publicKey || !('Notification' in window) || !('PushManager' in window)) return;

        const subscribe = async () => {
            if (Notification.permission !== 'granted') return;
            const registration = await swReady;
            if (!registration) return;

            let subscription = await registration.pushManager.getSubscription();
            if (!subscription) {
                subscription = await registration.pushManager.subscribe({
                    userVisibleOnly: true,
                    applicationServerKey: urlBase64ToUint8Array(publicKey)
                });
            }

            await fetch(getAppRoot() + 'save_subscription.php', {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(subscription)
            });
        };

        if (Notification.permission === 'granted') {
            subscribe().catch(() => {});
            return;
        }

        if (Notification.permission === 'default') {
            const askOnce = () => {
                document.removeEventListener('click', askOnce);
                Notification.requestPermission().then((permission) => {
                    if (permission === 'granted') subscribe().catch(() => {});
                });
            };
            document.addEventListener('click', askOnce, { once: true, passive: true });
        }
    }

    function wireTeacherNotifications(swReady) {
        if (document.body.dataset.itmRole !== 'enseignant') return;
        if (!('Notification' in window)) return;

        const check = async () => {
            try {
                const response = await fetch(getAppRoot() + 'enseignant/check_notifications.php?t=' + Date.now(), {
                    credentials: 'same-origin'
                });
                if (!response.ok) return;
                const data = await response.json();
                if (!data.nouveau || !data.latest_id) return;

                const lastSeen = Number(localStorage.getItem(teacherNotificationKey) || 0);
                if (Number(data.latest_id) <= lastSeen) return;

                localStorage.setItem(teacherNotificationKey, String(data.latest_id));
                notify(data.message || 'Nouvelle notification ITMCD.', 'warning');
                showSystemNotification(swReady, 'ITMCD - Encodage', data.message || 'Nouvelle notification ITMCD.');
                markTeacherNotifications(data.items || []);
            } catch {}
        };

        setTimeout(check, 1400);
        setInterval(check, 15000);
    }

    async function showSystemNotification(swReady, title, body) {
        if (!('Notification' in window) || Notification.permission !== 'granted') return;
        const registration = await swReady;
        if (registration && registration.showNotification) {
            registration.showNotification(title, {
                body,
                icon: getAppRoot() + 'images/LOGO.png',
                badge: getAppRoot() + 'images/LOGO.png',
                tag: 'itmcd-encoding',
                renotify: true,
                data: { url: getAppRoot() + 'enseignant/encodage_examen.php' }
            });
            return;
        }
        new Notification(title, { body, icon: getAppRoot() + 'images/LOGO.png' });
    }

    function markTeacherNotifications(items) {
        const ids = items.map((item) => Number(item.id)).filter(Boolean);
        if (!ids.length) return;
        const body = new URLSearchParams();
        body.set('ids', JSON.stringify(ids));
        fetch(getAppRoot() + 'enseignant/mark_notifications_read.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body
        }).catch(() => {});
    }

    function notify(message, kind = 'success') {
        let el = document.querySelector('.itm-native-status');
        if (!el) {
            el = document.createElement('div');
            el.className = 'itm-native-status';
            document.body.appendChild(el);
        }
        el.dataset.kind = kind;
        el.textContent = message;
        el.classList.add('show');
        clearTimeout(el._timer);
        el._timer = setTimeout(() => el.classList.remove('show'), 3200);
    }

    function wireNativeFeedback() {
        document.addEventListener('click', (event) => {
            const target = event.target.closest('button,a,.action,.drawer-item,.dock-link');
            if (target && navigator.vibrate) navigator.vibrate(8);
        }, { passive: true });
    }

    function formKey(form) {
        const path = location.pathname.replace(/[^\w-]+/g, '_');
        const marker = form.getAttribute('id') || form.getAttribute('action') || Array.from(document.forms).indexOf(form);
        return draftPrefix + path + ':' + marker;
    }

    function wirePersistentForms() {
        document.querySelectorAll('form').forEach((form) => {
            const key = formKey(form);
            restoreDraft(form, key);

            form.addEventListener('input', () => saveDraft(form, key), { passive: true });
            form.addEventListener('change', () => saveDraft(form, key), { passive: true });
            form.addEventListener('submit', (event) => {
                if (navigator.onLine) {
                    localStorage.removeItem(key);
                    return;
                }
                const method = (form.method || 'GET').toUpperCase();
                if (method !== 'POST') return;
                if (hasSensitiveFields(form)) {
                    event.preventDefault();
                    notify('Connexion absente: ce formulaire sensible ne sera pas garde hors ligne.', 'warning');
                    return;
                }
                event.preventDefault();
                queueForm(form);
                notify('Connexion absente: donnees gardees et synchronisation en attente.', 'warning');
            });
        });
    }

    function hasSensitiveFields(form) {
        return !!form.querySelector('input[type="password"], input[name*="password" i], input[name*="mot_de_passe" i]');
    }

    function shouldPersistField(field, name) {
        if (!field || !name || name === 'csrf_token') return false;
        const type = (field.type || '').toLowerCase();
        if (['password', 'hidden', 'file'].includes(type)) return false;
        if (/password|mot_de_passe|token/i.test(name)) return false;
        return true;
    }

    function saveDraft(form, key) {
        const data = {};
        new FormData(form).forEach((value, name) => {
            const field = form.elements[name];
            if (!shouldPersistField(field, name)) return;
            data[name] = value;
        });
        localStorage.setItem(key, JSON.stringify(data));
    }

    function restoreDraft(form, key) {
        let data;
        try { data = JSON.parse(localStorage.getItem(key) || '{}'); } catch { return; }
        Object.keys(data).forEach((name) => {
            const field = form.elements[name];
            if (!field || field.type === 'hidden' || field.type === 'password') return;
            field.value = data[name];
        });
    }

    function queueForm(form) {
        const queue = JSON.parse(localStorage.getItem(queueKey) || '[]');
        const data = {};
        new FormData(form).forEach((value, name) => { data[name] = value; });
        queue.push({
            url: form.action || location.href,
            method: (form.method || 'POST').toUpperCase(),
            data,
            createdAt: Date.now()
        });
        localStorage.setItem(queueKey, JSON.stringify(queue));
    }

    async function replayQueue() {
        if (!navigator.onLine) return;
        const queue = JSON.parse(localStorage.getItem(queueKey) || '[]');
        if (!queue.length) return;
        const remaining = [];
        for (const item of queue) {
            try {
                const body = new URLSearchParams(item.data);
                const res = await fetch(item.url, {
                    method: item.method,
                    body,
                    credentials: 'same-origin',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
                });
                if (!res.ok) remaining.push(item);
            } catch {
                remaining.push(item);
            }
        }
        localStorage.setItem(queueKey, JSON.stringify(remaining));
        if (queue.length !== remaining.length) notify('Synchronisation terminee.');
    }

    function wireOnlineState() {
        window.addEventListener('online', () => {
            notify('Connexion retablie.');
            replayQueue();
        });
        window.addEventListener('offline', () => notify('Mode hors ligne actif.', 'warning'));
    }

    function wireAdaptiveText() {
        const page = document.body.dataset.itmPage || '';
        if (page === 'admin-grille-inf1' || page === 'enseignant-grille-inf1') return;

        const selector = [
            'button',
            '.btn',
            '.btn-action',
            '.btn-filled',
            '.btn-glow',
            '.btn-submit',
            '.btn-accent',
            '.save-btn',
            '.nav-link',
            '.drawer-item',
            '.dock-link',
            '.tab-item',
            '.card-title',
            '.section-title',
            '.navbar-brand',
            '.headline span',
            'th',
            '.badge',
            '.status-pill'
        ].join(',');

        const apply = () => {
            document.querySelectorAll(selector).forEach((el) => {
                const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
                const length = text.length;
                el.classList.toggle('itm-fit-long', length >= 22);
                el.classList.toggle('itm-fit-xlong', length >= 38);

                if (el.clientWidth > 0) {
                    el.classList.toggle('itm-overflowing', el.scrollWidth > el.clientWidth + 2);
                }
            });
        };

        let timer = 0;
        const schedule = () => {
            clearTimeout(timer);
            timer = setTimeout(apply, 80);
        };

        apply();
        window.addEventListener('resize', schedule, { passive: true });
        window.addEventListener('orientationchange', schedule, { passive: true });
        if (document.fonts && document.fonts.ready) document.fonts.ready.then(apply).catch(() => {});
        if ('MutationObserver' in window) {
            new MutationObserver(schedule).observe(document.body, { childList: true, subtree: true, characterData: true });
        }
    }
})();
