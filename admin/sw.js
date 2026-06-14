self.addEventListener('push', function(event) {
    let data = { title: 'ITMCD - Administration', body: 'Nouvelle notification', url: 'monitoring.php' };
    
    if (event.data) {
        try {
            data = { ...data, ...event.data.json() };
        } catch {
            data.body = event.data.text() || data.body;
        }
    }

    const options = {
        body: data.body,
        icon: '/images/LOGO.png',
        badge: '/images/LOGO.png',
        vibrate: [200, 100, 200],
        tag: 'itmcd-admin-alert',
        renotify: true,
        data: { url: data.url || 'monitoring.php' }
    };

    event.waitUntil(self.registration.showNotification(data.title, options));
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    const targetUrl = new URL(event.notification.data?.url || 'monitoring.php', self.location.href).href;
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
