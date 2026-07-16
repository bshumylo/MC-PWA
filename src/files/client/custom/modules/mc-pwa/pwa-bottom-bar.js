/**
 * MC PWA – bottom navigation bar.
 *
 * Rendered only inside the installed app (standalone display mode) on
 * mobile-size screens. The button set is configured by the administrator
 * (Administration → PWA Settings → Bottom Navigation Bar) and is fetched
 * from an authenticated endpoint, so nothing is exposed before login.
 *
 * Debug: localStorage.setItem('mcPwaBottomBarForce', '1') forces the bar
 * in a regular browser tab (still requires an authenticated session).
 */
(function () {
    'use strict';

    var BASE = window.location.pathname.replace(/[^/]*$/, '');

    var BAR_ID = 'mc-pwa-bottom-bar';
    var BODY_CLASS = 'mc-pwa-has-bottom-bar';

    var isStandalone = function () {
        return window.matchMedia('(display-mode: standalone)').matches ||
            window.navigator.standalone === true;
    };

    var isForced = function () {
        try {
            return window.localStorage.getItem('mcPwaBottomBarForce') === '1';
        } catch (e) {
            return false;
        }
    };

    if (!isStandalone() && !isForced()) {
        return;
    }

    var barConfig = null;

    // ------------------------------------------------------------------
    // Styles
    // ------------------------------------------------------------------

    var injectStyles = function () {
        if (document.getElementById(BAR_ID + '-style')) {
            return;
        }

        var css = '' +
            '#' + BAR_ID + '{' +
                'position:fixed;left:0;right:0;bottom:0;z-index:1049;' +
                'display:flex;align-items:stretch;' +
                'height:calc(52px + env(safe-area-inset-bottom, 0px));' +
                'padding:0 2px env(safe-area-inset-bottom, 0px);' +
                'background:var(--mc-pwa-bar-bg, #ffffff);' +
                'border-top:1px solid rgba(0,0,0,.12);' +
                'box-shadow:0 -1px 4px rgba(0,0,0,.06);' +
            '}' +
            '#' + BAR_ID + ' a.mc-pwa-bb-item{' +
                'flex:1 1 0;min-width:0;' +
                'display:flex;flex-direction:column;align-items:center;justify-content:center;' +
                'gap:2px;padding:4px 2px;' +
                'color:var(--mc-pwa-bar-color, #55606e);' +
                'text-decoration:none;-webkit-tap-highlight-color:transparent;' +
            '}' +
            '#' + BAR_ID + ' a.mc-pwa-bb-item .mc-pwa-bb-icon{' +
                'font-size:20px;line-height:24px;height:24px;' +
            '}' +
            '#' + BAR_ID + '.mc-pwa-bb-no-labels a.mc-pwa-bb-item .mc-pwa-bb-icon{' +
                'font-size:23px;line-height:28px;height:28px;' +
            '}' +
            '#' + BAR_ID + ' a.mc-pwa-bb-item .mc-pwa-bb-label{' +
                'font-size:10px;line-height:12px;max-width:100%;' +
                'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;' +
            '}' +
            '#' + BAR_ID + ' a.mc-pwa-bb-item.active{' +
                'color:var(--mc-pwa-bar-active, #337ab7);' +
            '}' +
            '#' + BAR_ID + ' a.mc-pwa-bb-item.active .mc-pwa-bb-icon i{' +
                'color:var(--mc-pwa-bar-active, #337ab7) !important;' +
            '}' +
            'body.' + BODY_CLASS +
                '{padding-bottom:calc(52px + env(safe-area-inset-bottom, 0px)) !important;}' +
            '@media (min-width: 768px){' +
                '#' + BAR_ID + '{display:none;}' +
                'body.' + BODY_CLASS + '{padding-bottom:0 !important;}' +
            '}';

        var style = document.createElement('style');
        style.id = BAR_ID + '-style';
        style.textContent = css;
        document.head.appendChild(style);
    };

    // ------------------------------------------------------------------
    // Rendering
    // ------------------------------------------------------------------

    var isExternal = function (url) {
        return /^https?:\/\//i.test(url);
    };

    /** First hash segment: '#Contact/view/1' -> 'Contact'. */
    var hashRoot = function (hash) {
        return (hash || '').replace(/^#/, '').split('/')[0];
    };

    var updateActive = function () {
        var bar = document.getElementById(BAR_ID);

        if (!bar) {
            return;
        }

        var current = hashRoot(window.location.hash);

        Array.prototype.forEach.call(
            bar.querySelectorAll('a.mc-pwa-bb-item'),
            function (a) {
                var url = a.getAttribute('data-url') || '';

                var active = !isExternal(url) &&
                    url.charAt(0) === '#' &&
                    hashRoot(url) === current;

                a.classList.toggle('active', active);
            }
        );
    };

    var applyThemeColors = function (bar) {
        var themeColor = (barConfig && barConfig.themeColor) || '';

        if (/^#[0-9a-fA-F]{3,8}$/.test(themeColor)) {
            bar.style.setProperty('--mc-pwa-bar-active', themeColor);
        }

        try {
            var bg = window.getComputedStyle(document.body).backgroundColor;

            if (!bg || bg === 'rgba(0, 0, 0, 0)' || bg === 'transparent') {
                return;
            }

            bar.style.setProperty('--mc-pwa-bar-bg', bg);

            var m = bg.match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/);

            if (m) {
                var luma = 0.299 * m[1] + 0.587 * m[2] + 0.114 * m[3];

                if (luma < 128) {
                    // Dark theme.
                    bar.style.setProperty('--mc-pwa-bar-color', '#9aa4b1');
                    bar.style.borderTopColor = 'rgba(255,255,255,.14)';
                    bar.style.boxShadow = 'none';
                } else {
                    bar.style.setProperty('--mc-pwa-bar-color', '#55606e');
                    bar.style.borderTopColor = 'rgba(0,0,0,.12)';
                }
            }
        } catch (e) {}
    };

    var render = function () {
        if (!barConfig || !barConfig.enabled ||
            !barConfig.items || !barConfig.items.length
        ) {
            return;
        }

        if (document.getElementById(BAR_ID)) {
            return;
        }

        injectStyles();

        var bar = document.createElement('nav');
        bar.id = BAR_ID;

        if (!barConfig.showLabels) {
            bar.className = 'mc-pwa-bb-no-labels';
        }

        barConfig.items.forEach(function (item) {
            if (!item || !item.url) {
                return;
            }

            var a = document.createElement('a');
            a.className = 'mc-pwa-bb-item';
            a.href = item.url;
            a.setAttribute('data-url', item.url);

            if (isExternal(item.url) || item.newTab) {
                a.target = '_blank';
                a.rel = 'noopener noreferrer';
            }

            var iconWrap = document.createElement('span');
            iconWrap.className = 'mc-pwa-bb-icon';

            var icon = document.createElement('i');
            icon.className = item.iconClass || 'far fa-circle';

            if (item.iconColor) {
                icon.style.color = item.iconColor;
            }

            iconWrap.appendChild(icon);
            a.appendChild(iconWrap);

            if (barConfig.showLabels && item.label) {
                var label = document.createElement('span');
                label.className = 'mc-pwa-bb-label';
                label.textContent = item.label;
                a.appendChild(label);
            } else if (item.label) {
                a.title = item.label;
                a.setAttribute('aria-label', item.label);
            }

            bar.appendChild(a);
        });

        document.body.appendChild(bar);
        document.body.classList.add(BODY_CLASS);

        applyThemeColors(bar);
        updateActive();

        window.addEventListener('hashchange', updateActive);

        // The SPA may re-render the body content on startup and remove the
        // bar. Re-append it whenever it gets detached.
        var observer = new MutationObserver(function () {
            if (!bar.isConnected) {
                document.body.appendChild(bar);
                document.body.classList.add(BODY_CLASS);
                applyThemeColors(bar);
                updateActive();
            }
        });

        observer.observe(document.body, {childList: true});

        // Theme stylesheets may finish loading after the initial render.
        window.setTimeout(function () {
            applyThemeColors(bar);
        }, 3000);
    };

    // ------------------------------------------------------------------
    // Config loading (retries until the user is authenticated)
    // ------------------------------------------------------------------

    var attempts = 0;

    var load = function () {
        attempts++;

        fetch(BASE + 'api/v1/McPwa/bottomBar', {credentials: 'same-origin'})
            .then(function (response) {
                if (response.status === 401 || response.status === 403) {
                    // Not logged in yet – retry later.
                    if (attempts < 40) {
                        window.setTimeout(load, 15000);
                    }

                    return null;
                }

                if (!response.ok) {
                    return null;
                }

                return response.json();
            })
            .then(function (data) {
                if (!data) {
                    return;
                }

                barConfig = data;
                render();
            })
            .catch(function () {
                if (attempts < 5) {
                    window.setTimeout(load, 15000);
                }
            });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', load);
    } else {
        load();
    }
})();
