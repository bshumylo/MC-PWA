/**
 * MC PWA – client bootstrap.
 *
 * Loaded on every page (login page included). Responsibilities:
 *  1. Inject the web-app manifest and meta tags (installability).
 *  2. Register the service worker (push + offline fallback).
 *  3. Subscribe the logged-in user to Web Push.
 *  4. Report anonymous installation statistics (if enabled by admin).
 */
(function () {
    'use strict';

    if (!('serviceWorker' in navigator)) {
        return;
    }

    var BASE = window.location.pathname.replace(/[^/]*$/, '');

    var entryUrl = function (name) {
        return BASE + '?entryPoint=' + name;
    };

    var LS = window.localStorage;

    var config = null;
    var pushDone = false;
    var subscribeInProgress = false;

    var isStandalone = function () {
        return window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true;
    };

    var postJson = function (path, data) {
        return fetch(BASE + 'api/v1/' + path, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(data),
        });
    };

    // ------------------------------------------------------------------
    // 1. Manifest + meta tags
    // ------------------------------------------------------------------

    var injectManifest = function () {
        if (document.querySelector('link[rel="manifest"]')) {
            return;
        }

        var link = document.createElement('link');
        link.rel = 'manifest';
        link.href = entryUrl('pwaManifest');
        document.head.appendChild(link);

        if (!document.querySelector('meta[name="theme-color"]')) {
            var meta = document.createElement('meta');
            meta.name = 'theme-color';
            meta.content = (config && config.themeColor) || '#337ab7';
            document.head.appendChild(meta);
        }

        if (!document.querySelector('link[rel="apple-touch-icon"]')) {
            var apple = document.createElement('link');
            apple.rel = 'apple-touch-icon';
            apple.href = entryUrl('pwaIcon') + '&size=192&maskable=1';
            document.head.appendChild(apple);
        }
    };

    // ------------------------------------------------------------------
    // 2. Service worker
    // ------------------------------------------------------------------

    var registerServiceWorker = function () {
        return navigator.serviceWorker
            .register(entryUrl('pwaServiceWorker'), {scope: BASE})
            .catch(function (e) {
                console.warn('MC PWA: SW registration failed', e);

                return null;
            });
    };

    // ------------------------------------------------------------------
    // 3. Push subscription
    // ------------------------------------------------------------------

    var urlBase64ToUint8Array = function (base64String) {
        var padding = '='.repeat((4 - (base64String.length % 4)) % 4);
        var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
        var rawData = window.atob(base64);
        var outputArray = new Uint8Array(rawData.length);

        for (var i = 0; i < rawData.length; i++) {
            outputArray[i] = rawData.charCodeAt(i);
        }

        return outputArray;
    };

    var getPlatform = function () {
        if (navigator.userAgentData && navigator.userAgentData.platform) {
            return navigator.userAgentData.platform;
        }

        return navigator.platform || 'unknown';
    };

    var subscribeToPush = function (registration) {
        if (pushDone || subscribeInProgress) {
            return;
        }

        if (!config || !config.pushEnabled || !config.vapidPublicKey) {
            return;
        }

        if (!('PushManager' in window) || Notification.permission !== 'granted') {
            return;
        }

        subscribeInProgress = true;

        registration.pushManager
            .subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(config.vapidPublicKey),
            })
            .then(function (subscription) {
                return postJson('McPwa/subscription', {
                    action: 'subscribe',
                    subscription: subscription.toJSON(),
                    platform: getPlatform(),
                    userAgent: navigator.userAgent.substring(0, 250),
                });
            })
            .then(function (response) {
                if (response && response.ok) {
                    pushDone = true;
                    LS.setItem('mcPwaSubscribedAt', String(Date.now()));
                }
            })
            .catch(function (e) {
                console.warn('MC PWA: push subscription failed', e);
            })
            .finally(function () {
                subscribeInProgress = false;
            });
    };

    var requestPermissionOnGesture = function (registration) {
        // Ask for the notification permission only inside the installed app
        // (standalone mode), on the first user gesture. Browsers require a
        // gesture; iOS enforces it strictly.
        if (!config || !config.pushEnabled) {
            return;
        }

        if (Notification.permission === 'granted') {
            subscribeToPush(registration);

            return;
        }

        if (Notification.permission === 'denied' || !isStandalone()) {
            return;
        }

        var handler = function () {
            document.removeEventListener('click', handler);

            Notification.requestPermission().then(function (permission) {
                if (permission === 'granted') {
                    subscribeToPush(registration);
                }
            });
        };

        document.addEventListener('click', handler, {once: true});
    };

    // ------------------------------------------------------------------
    // 4. Anonymous statistics
    // ------------------------------------------------------------------

    var getAnonymousId = function () {
        var id = LS.getItem('mcPwaAnonymousId');

        if (!id) {
            if (window.crypto && window.crypto.randomUUID) {
                id = window.crypto.randomUUID();
            } else {
                id = 'xxxxxxxx-xxxx-4xxx-8xxx-xxxxxxxxxxxx'.replace(/x/g, function () {
                    return Math.floor(Math.random() * 16).toString(16);
                });
            }

            LS.setItem('mcPwaAnonymousId', id);
        }

        return id;
    };

    var getDeviceType = function () {
        var ua = navigator.userAgent;

        if (/iPad|Android(?!.*Mobile)/i.test(ua)) {
            return 'tablet';
        }

        if (/Mobi|iPhone|Android/i.test(ua)) {
            return 'phone';
        }

        if (/Windows|Macintosh|Linux|CrOS/i.test(ua)) {
            return 'desktop';
        }

        return 'other';
    };

    var getOsVersion = function () {
        var ua = navigator.userAgent;

        var m = ua.match(/Android\s([\d.]+)/) ||
            ua.match(/OS\s([\d_]+)\slike\sMac/) ||
            ua.match(/Windows\sNT\s([\d.]+)/) ||
            ua.match(/Mac\sOS\sX\s([\d_.]+)/);

        return m ? m[1].replace(/_/g, '.') : '';
    };

    var sendStats = function () {
        if (!config || !config.statsEnabled) {
            return;
        }

        postJson('McPwa/stats', {
            anonymousId: getAnonymousId(),
            platform: getPlatform(),
            osVersion: getOsVersion(),
            deviceType: getDeviceType(),
            language: (navigator.language || '').substring(0, 10),
            userAgent: navigator.userAgent.substring(0, 250),
        }).catch(function () {});
    };

    var sendStatsThrottled = function () {
        var last = parseInt(LS.getItem('mcPwaStatsSentAt') || '0', 10);

        if (Date.now() - last < 20 * 3600 * 1000) {
            return;
        }

        LS.setItem('mcPwaStatsSentAt', String(Date.now()));
        sendStats();
    };

    // ------------------------------------------------------------------
    // Bootstrap
    // ------------------------------------------------------------------

    var start = function () {
        fetch(entryUrl('pwaConfig'), {credentials: 'same-origin'})
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('config unavailable');
                }

                return response.json();
            })
            .then(function (data) {
                config = data;

                if (!config.enabled) {
                    return;
                }

                injectManifest();

                return registerServiceWorker().then(function (registration) {
                    if (!registration) {
                        return;
                    }

                    requestPermissionOnGesture(registration);

                    // Retry the subscription periodically: the user may log in
                    // after page load, or permission may be granted later.
                    var attempts = 0;

                    var interval = window.setInterval(function () {
                        attempts++;

                        if (pushDone || attempts > 30) {
                            window.clearInterval(interval);

                            return;
                        }

                        subscribeToPush(registration);
                    }, 20000);

                    window.addEventListener('appinstalled', function () {
                        LS.removeItem('mcPwaStatsSentAt');
                        sendStatsThrottled();
                    });

                    if (isStandalone()) {
                        sendStatsThrottled();
                    }
                });
            })
            .catch(function () {});
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
