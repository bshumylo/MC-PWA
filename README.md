# MC PWA — Progressive Web App

<img width="1200" height="630" alt="mc-pwa-cover" src="https://github.com/user-attachments/assets/ea78cb9f-c1a4-4b2c-b2ee-044246da819c" />

Turns your EspoCRM instance into an installable mobile/desktop app with Web Push notifications, a configurable bottom navigation bar, app shortcuts and optional anonymous install statistics.

## Features

- **Installable PWA** — users can add the EspoCRM to their home screen (Android, iOS 16.4+, desktop Chrome/Edge). Manifest, service worker and icons are generated dynamically from settings; maskable icon variants are provided for Android launchers.
- **Web Push notifications** — system notifications (assignments, emails, mentions, stream posts, messages, system) and popup reminders are delivered to devices even when the browser is closed. Implemented in pure PHP (RFC 8291 `aes128gcm` encryption + RFC 8292 VAPID `ES256`), **no Composer dependencies**.
- **Bottom navigation bar** (since 1.1) — a native-app-like button bar shown at the bottom of the screen in the installed app on mobile devices. Configured in the admin panel with the same editor as the core Tab List (User Interface): drag-and-drop ordering, entity tabs picked from a searchable list (their icon, color and label are taken from the system automatically), and custom URL buttons with a label, icon, icon color, ACL scope, admin-only and open-in-new-tab options. Labels under icons can be switched off globally (icons-only mode). Buttons are filtered by the user's access rights; the bar adapts to light/dark themes and to iPhone safe areas.
- **Reminder push** — an every-minute scheduled job delivers popup reminders (meetings, calls, tasks) as push messages.
- **Test push** — a "Send Test Push" button in the admin panel verifies end-to-end delivery per subscription (sent / expired / error).
- **Configurable appearance** — app name, short name, theme color, background color and icon are set in the admin panel.
- **App shortcuts** — quick actions from the app icon.
- **Anonymous install statistics** (opt-in, off by default) — random identifier, device type, platform and OS version only; no user data. The OS version is taken from User-Agent Client Hints where available (real Windows 10/11, Android and macOS versions); when the browser does not expose a reliable version, the field is left empty instead of showing a misleading value.
- **Separate data-collection controls** — installation statistics and push subscriptions are enabled/disabled independently in settings.

## Requirements

- EspoCRM ≥ 10.0 (built on the official extension template)
- PHP ≥ 8.1 with `openssl` and `gd`
- HTTPS (required by browsers for service workers and push)
- Running cron/daemon (for push notification delivery)

## Installation

1. Download `mc-pwa-<version>.zip` from [Releases](https://github.com/bshumylo/MC-PWA/releases).
2. In the EspoCRM: **Administration → Extensions → Upload**, select the zip and install.
3. Go to **Administration → PWA**, enable PWA and (optionally) push notifications and the bottom navigation bar.
4. Rebuild if prompted.

VAPID keys are generated automatically during installation. The private key is stored in the internal config and never leaves the server; the public key is shown read-only in the admin panel.

When upgrading from 1.0.x, bottom bar buttons saved in the old format are migrated automatically.

## Configuration (Administration → PWA)

| Setting | Description |
|---|---|
| PWA Enabled | Allows users to install the EspoCRM as an app |
| App Name / Short Name | Names shown on the device |
| Theme / Background Color | App UI and splash screen colors |
| App Icon | Square PNG, 512×512 recommended (falls back to company logo) |
| Push Notifications Enabled | Delivery of system notifications as push messages |
| Push Notification Types | Assignment, Email Received, Message, Stream Post, Mention, System |
| Bottom Navigation Bar Enabled | Shows the button bar in the installed app on mobile devices (off by default) |
| Show Button Labels | Text labels under icons; when off, only (slightly larger) icons are shown |
| Bottom Bar Buttons | Up to 8 buttons (3–5 recommended): entity tabs or custom URL buttons; reorder by drag and drop, edit URL buttons via the pencil icon |
| Installation Statistics Enabled | Anonymous install stats (off by default) |
| Push Subscriptions Enabled | Whether devices may register push subscriptions (on by default). When off, no new subscriptions are stored; existing ones are kept |

The **PWA** admin section also contains the **PWA Installations** (anonymous statistics) and **PWA Push Subscriptions** lists.

### Bottom bar notes

- The bar appears **only in the installed (standalone) app** on screens narrower than 768 px. For testing in a regular browser tab, run `localStorage.setItem('mcPwaBottomBarForce', '1')` in the console and reload (an authenticated session is still required).
- Entity buttons inherit the icon, color and plural label of the entity and are hidden for users without access to that entity. Custom URL buttons support an ACL scope, an admin-only flag and opening in a new tab.

## How it works

- Entry points (no auth, GET): `pwaManifest`, `pwaServiceWorker`, `pwaIcon`, `pwaConfig`.
- API routes (cookie auth): `GET /McPwa/bottomBar`; with `X-Requested-With` CSRF header: `POST /McPwa/subscription`, `POST /McPwa/stats`, `POST /McPwa/testPush` (admin only).
- The bottom bar configuration is served only to authenticated users; scope buttons are resolved server-side (label, icon, color, ACL) for the current user and language.
- A hook on `Notification` afterSave queues a job that encrypts and sends the push payload via `Classes/Push/WebPushSender`.
- The `McPwaSendReminderPush` scheduled job (created automatically, `* * * * *`) sends popup reminders as push messages.
- Expired/revoked subscriptions are cleaned up automatically on send failure.

## Development

Based on the official [extension template](https://github.com/espocrm/ext-template).

```bash
npm install
node build --all        # full site build for development
node build --extension  # build installable extension zip (in build/)
npm run sa              # static analysis (phpstan)
npm run unit-tests      # unit tests (phpunit)
```

A server-side self-test for the Web Push crypto stack is available at `tests/webpush-selftest.php`; a manual QA checklist is in `tests/manual-checklist.md`.

## Security

- No secrets are stored in the repository; VAPID keys are generated per-installation. The private key is kept in the internal config (`config-internal.php`) and is never exposed through the API.
- Push payload encryption follows RFC 8291; VAPID signing follows RFC 8292.
- All API endpoints require authentication; authenticated POST endpoints additionally require the `X-Requested-With` header (CSRF protection). `testPush` is limited to the current user's own subscriptions.
- **SSRF protection** — before delivering a push, the target endpoint host is resolved and rejected if it points to any private, loopback, link-local or otherwise reserved address; the validated public IP is pinned for the connection (guards against DNS-rebinding), and only HTTPS on port 443 is allowed.
- **Subscription ownership** — push subscriptions are scoped to their owner: a user can only remove their own subscription, and an endpoint bound to another account is never silently reassigned.
- The bottom bar endpoint requires authentication; all button values (URLs, icon classes, colors) are validated and sanitized server-side, and buttons are filtered by the user's access rights.
- Statistics are anonymous and disabled by default; push subscription collection can be disabled separately. Stored installation rows are capped to bound table growth.
- All user-supplied input (subscription keys, endpoints, identifiers, colors, icon classes, labels) is strictly validated and sanitized; the client renders bar items via DOM APIs (no `innerHTML`) with `rel="noopener noreferrer"` on external links.

## License

MIT — see [LICENSE](LICENSE).

Author: bshumylo
