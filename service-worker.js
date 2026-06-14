const CACHE_NAME = 'itmcd-shell-v14-push-grid-exports';
const ASSETS = [
  './',
  './login.php',
  './manifest.json',
  './offline.html',
  './assets/app-shell.css',
  './assets/app-modern.css',
  './assets/app-shell.js',
  './images/LOGO.png'
];

self.addEventListener('install', (event) => {
  event.waitUntil(caches.open(CACHE_NAME).then((cache) => cache.addAll(ASSETS)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) => Promise.all(names.filter((name) => name !== CACHE_NAME).map((name) => caches.delete(name))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', (event) => {
  const request = event.request;

  if (request.method !== 'GET') {
    event.respondWith(fetch(request));
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          return response;
        })
        .catch(() => caches.match(request).then((cached) => cached || caches.match('./offline.html')))
    );
    return;
  }

  event.respondWith(
    caches.match(request).then((cached) => {
      const fresh = fetch(request).then((response) => {
        if (response && response.ok) {
          const copy = response.clone();
          caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
        }
        return response;
      }).catch(() => cached);
      return cached || fresh;
    })
  );
});

self.addEventListener('push', (event) => {
  const show = async () => {
    let data = { title: 'ITMCD', body: 'Nouvelle notification.', url: './login.php', tag: 'itmcd-alert' };

    if (event.data) {
      try {
        data = { ...data, ...event.data.json() };
      } catch {
        data.body = event.data.text() || data.body;
      }
    } else {
      const candidates = [
        { endpoint: './admin/check_notifications.php?push=1', title: 'ITMCD - Administration', url: './admin/monitoring.php', tag: 'itmcd-admin-alert' },
        { endpoint: './enseignant/check_notifications.php?push=1', title: 'ITMCD - Enseignant', url: './enseignant/encodage_examen.php', tag: 'itmcd-teacher-alert' }
      ];

      for (const candidate of candidates) {
        try {
          const response = await fetch(candidate.endpoint, { credentials: 'same-origin', cache: 'no-store' });
          if (!response.ok) continue;
          const payload = await response.json();
          if (payload && payload.nouveau && payload.message) {
            data = { ...data, ...candidate, body: payload.message };
            break;
          }
        } catch {}
      }
    }

    return self.registration.showNotification(data.title || 'ITMCD', {
      body: data.body || 'Nouvelle notification.',
      icon: './images/LOGO.png',
      badge: './images/LOGO.png',
      tag: data.tag || 'itmcd-alert',
      renotify: true,
      data: { url: data.url || './enseignant/encodage_examen.php' }
    });
  };

  event.waitUntil(show());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || './enseignant/encodage_examen.php', self.location.origin).href;

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
