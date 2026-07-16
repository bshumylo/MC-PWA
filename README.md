# MC PWA — Progressive Web App

Turns your CRM instance into an installable mobile/desktop app with Web Push notifications, app shortcuts and optional anonymous install statistics.

## Features

- **Installable PWA** — users can add the CRM to their home screen (Android, iOS 16.4+, desktop Chrome/Edge). Manifest, service worker and icons are generated dynamically from settings; maskable icon variants are provided for Android launchers.
- **Web Push notifications** — system notifications (assignments, emails, mentions, stream posts, messages, system) and popup reminders are delivered to devices even when the browser is closed. Implemented in pure PHP (RFC 8291 `aes128gcm` encryption + RFC 8292 VAPID `ES256`), **no Composer dependencies**.
- **Reminder push** — an every-minute scheduled job delivers popup reminders (meetings, calls, tasks) as push messages.
- **Test push** — a "Send Test Push" button in the admin panel verifies end-to-end delivery per subscription (sent / expired / error).
- **Configurable appearance** — app name, short name, theme color, background color and icon are set in the admin panel.
- **App shortcuts** — quick actions from the app icon.
- **Anonymous install statistics** (opt-in, off by default) — random identifier, device type, platform and OS version only; no user data. The OS version is taken from User-Agent Client Hints where available (real Windows 10/11, Android and macOS versions); when the browser does not expose a reliable version, the field is left empty instead of showing a misleading value.
- **Separate data-collection controls** — installation statistics and push subscriptions are enabled/disabled independently in settings.

## Requirements

- CRM platform ≥ 10.0 (built on the official extension template)
- PHP ≥ 8.1 with `openssl` and `gd`
- HTTPS (required by browsers for service workers and push)
- Running cron/daemon (for push notification delivery)

## Installation

1. Download `mc-pwa-<version>.zip` from [Releases](https://github.com/bshumylo/MC-PWA/releases).
2. In the CRM: **Administration → Extensions → Upload**, select the zip and install.
3. Go to **Administration → PWA**, enable PWA and (optionally) push notifications.
4. Rebuild if prompted.

VAPID keys are generated automatically during installation. The private key is stored in the internal config and never leaves the server; the public key is shown read-only in the admin panel.

## Configuration (Administration → PWA)

| Setting | Description |
|---|---|
| PWA Enabled | Allows users to install the CRM as an app |
| App Name / Short Name | Names shown on the device |
| Theme / Background Color | App UI and splash screen colors |
| App Icon | Square PNG, 512×512 recommended (falls back to company logo) |
| Push Notifications Enabled | Delivery of system notifications as push messages |
| Push Notification Types | Assignment, Email Received, Message, Stream Post, Mention, System |
| Installation Statistics Enabled | Anonymous install stats (off by default) |
| Push Subscriptions Enabled | Whether devices may register push subscriptions (on by default). When off, no new subscriptions are stored; existing ones are kept |

The **PWA** admin section also contains the **PWA Installations** (anonymous statistics) and **PWA Push Subscriptions** lists.

## How it works

- Entry points (no auth, GET): `pwaManifest`, `pwaServiceWorker`, `pwaIcon`, `pwaConfig`.
- API routes (cookie auth + `X-Requested-With` CSRF header): `POST /McPwa/subscription`, `POST /McPwa/stats`, `POST /McPwa/testPush` (admin only).
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

- No secrets are stored in the repository; VAPID keys are generated per-installation.
- Push payload encryption follows RFC 8291; VAPID signing follows RFC 8292.
- Authenticated POST endpoints require the `X-Requested-With` header (CSRF protection).
- Statistics are anonymous and disabled by default; push subscription collection can be disabled separately.

## License

MIT — see [LICENSE](LICENSE).

Author: bshumylo
