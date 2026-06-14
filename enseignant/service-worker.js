const CACHE_NAME = 'itmcd-cache-v15-push-grid-exports';
const ASSETS = [
    'index.php',
    'install.php',
    '../images/LOGO.png',
    '../assets/app-modern.css',
    '../assets/app-shell.css',
    '../assets/app-shell.js',
    'manifest.json'
];

// Installation du Service Worker et mise en cache des fichiers de base
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME)
            .then((cache) => {
                return cache.addAll(ASSETS);
            })
            .then(() => self.skipWaiting())
    );
});

// Activation et nettoyage des anciens caches
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames.map((cache) => {
                    if (cache !== CACHE_NAME) {
                        return caches.delete(cache);
                    }
                })
            );
        })
    );
});

// Stratégie : Réseau d'abord, sinon Cache
self.addEventListener('fetch', (event) => {
    event.respondWith(
        fetch(event.request).catch(() => {
            return caches.match(event.request);
        })
    );
});

self.addEventListener('push', (event) => {
    const show = async () => {
        let data = { title: 'ITMCD - Enseignant', body: 'Nouvelle notification ITMCD.', url: './encodage_examen.php' };

        if (event.data) {
            try {
                data = { ...data, ...event.data.json() };
            } catch {
                data.body = event.data.text() || data.body;
            }
        } else {
            try {
                const response = await fetch('./check_notifications.php?push=1', { credentials: 'same-origin', cache: 'no-store' });
                if (response.ok) {
                    const payload = await response.json();
                    if (payload && payload.message) {
                        data.body = payload.message;
                    }
                }
            } catch {}
        }

        return self.registration.showNotification(data.title || 'ITMCD', {
            body: data.body || 'Nouvelle notification ITMCD.',
            icon: '../images/LOGO.png',
            badge: '../images/LOGO.png',
            tag: 'itmcd-teacher-alert',
            renotify: true,
            data: { url: data.url || './encodage_examen.php' }
        });
    };

    event.waitUntil(show());
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const targetUrl = new URL(event.notification.data?.url || './encodage_examen.php', self.location.href).href;
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
            for (const client of clientList) {
                if ('focus' in client) {
                    client.navigate(targetUrl);
                    return client.focus();
                }
            }
            return clients.openWindow(targetUrl);
        })
    );
});
