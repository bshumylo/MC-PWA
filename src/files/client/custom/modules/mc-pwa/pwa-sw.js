/**
 * MC PWA – service worker.
 * Served via ?entryPoint=pwaServiceWorker so its scope covers the whole app.
 */
'use strict';

self.addEventListener('install', function () {
    self.skipWaiting();
});

self.addEventListener('activate', function (event) {
    event.waitUntil(self.clients.claim());
});

self.addEventListener('push', function (event) {
    var data = {};

    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = {title: 'CRM', body: event.data ? event.data.text() : ''};
    }

    var title = data.title || 'CRM';

    var options = {
        body: data.body || '',
        tag: data.tag || undefined,
        data: {url: data.url || ''},
        icon: new URL('?entryPoint=pwaIcon&size=192', self.registration.scope).href,
    };

    event.waitUntil(self.registration.showNotification(title, options));
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    var scope = self.registration.scope;
    var url = (event.notification.data && event.notification.data.url) || '';
    var targetUrl = url ? new URL(url, scope).href : scope;

    event.waitUntil(
        self.clients.matchAll({type: 'window', includeUncontrolled: true})
            .then(function (clientList) {
                for (var i = 0; i < clientList.length; i++) {
                    var client = clientList[i];

                    if (client.url.indexOf(scope) === 0 && 'focus' in client) {
                        if ('navigate' in client && url) {
                            client.navigate(targetUrl).catch(function () {});
                        }

                        return client.focus();
                    }
                }

                return self.clients.openWindow(targetUrl);
            })
    );
});

self.addEventListener('pushsubscriptionchange', function (event) {
    var subscription = event.oldSubscription || event.subscription;

    if (!subscription || !subscription.options) {
        return;
    }

    event.waitUntil(
        self.registration.pushManager
            .subscribe(subscription.options)
            .then(function (newSubscription) {
                return fetch(new URL('api/v1/McPwa/subscription', self.registration.scope).href, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        action: 'subscribe',
                        subscription: newSubscription.toJSON(),
                    }),
                });
            })
            .catch(function () {})
    );
});

// Network-first navigation with a minimal offline fallback page.
self.addEventListener('fetch', function (event) {
    if (event.request.mode !== 'navigate') {
        return;
    }

    event.respondWith(
        fetch(event.request).catch(function () {
            return new Response(
                '<!DOCTYPE html><html><head><meta charset="utf-8">' +
                '<meta name="viewport" content="width=device-width, initial-scale=1">' +
                '<title>Offline</title></head>' +
                '<body style="font-family:sans-serif;text-align:center;padding-top:30vh;color:#555">' +
                '<h2>&#128268; Offline</h2>' +
                '<p>No connection. Try again later.</p>' +
                '</body></html>',
                {headers: {'Content-Type': 'text/html; charset=utf-8'}}
            );
        })
    );
});
