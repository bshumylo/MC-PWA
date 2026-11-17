# MC PWA

**Turn EspoCRM into an app your users can install on a phone or computer, with push notifications and a bottom navigation bar.**

English · [Українська](README.uk.md)

Version **1.3.2** · EspoCRM **≥ 10.0.0** · PHP **≥ 8.3** · AGPL-3.0

<img width="1200" height="630" alt="mc-pwa-cover" src="https://github.com/user-attachments/assets/ea78cb9f-c1a4-4b2c-b2ee-044246da819c" />

## What it does

- **Installable app.** Users can add EspoCRM to the home screen (Android, iPhone with iOS 16.4 or newer, Chrome or Edge on a computer). You set the app name, short name, colors and icon in the admin panel.
- **Push notifications.** Assignments, received emails, mentions, stream posts, messages and system notifications reach the device even when the browser is closed. Popup reminders for meetings, calls and tasks are sent as push messages too. No Composer libraries are needed.
- **Bottom navigation bar.** A button bar at the bottom of the screen, like in a native app. You choose the buttons: entity tabs or your own links. Users see only the buttons they have access to.
- **Personal push settings.** Each user picks which notifications to receive and can set quiet hours (user menu → *Push Notification Settings*).
- **Easy checks.** *Send Test Push* tests delivery. *Diagnostics* shows the health of push at a glance. *Rotate VAPID Keys* creates new keys.
- **Automatic cleanup.** A daily job removes broken push subscriptions and old install records.
- **App shortcuts.** Quick actions from the app icon.
- **Optional install statistics.** Off by default. Anonymous only: a random ID, device type, platform and OS version. No user data.

## Requirements

- EspoCRM 10.0.0 or newer.
- PHP 8.3 – 8.5 with the `openssl`, `curl` and `gd` extensions.
- HTTPS. Browsers require it for service workers and push.
- Cron (or the daemon) running for EspoCRM. It is needed to deliver push notifications.

## Installation

1. Download `mc-pwa-1.3.2.zip` from [Releases](../../releases/latest). Do not use the automatic "Source code (zip)" file: it cannot be installed.
2. In EspoCRM open **Administration → Extensions → Upload**, choose the ZIP, then click **Install**. The installer rebuilds the system itself. Run **Administration → Rebuild** only if something looks wrong.
3. Open **Administration → PWA → PWA Settings**. Turn on PWA and, if you want them, push notifications and the bottom navigation bar.

The push keys (VAPID) are created automatically during installation. The private key stays on your server. The public key is shown read-only in the admin panel.

## Upgrading

1. Make a backup of the database and files.
2. Install the new ZIP over the old one (**Administration → Extensions → Upload → Install**).
3. Version notes:
   - From **1.0.x**: bottom bar buttons saved in the old format are converted automatically.
   - **1.3.2**: no manual steps. The installer rebuilds the system itself. Since this version, when another user opens the app on a shared device, the old subscription is replaced with one for the new user (see Security).

## Settings

Open **Administration → PWA → PWA Settings**.

| Setting | What it does |
|---|---|
| PWA Enabled | Lets users install EspoCRM as an app. |
| App Name / Short Name | Names shown on the device. |
| Theme / Background Color | Colors of the app and its start screen. |
| App Icon | Square PNG, 512×512 recommended. If empty, the company logo is used. |
| Push Notifications Enabled | Sends system notifications as push messages. |
| Push Notification Types | Assignment, Email Received, Message, Stream Post, Mention, System. |
| Bottom Navigation Bar Enabled | Shows the button bar in the installed app on mobile devices. Off by default. |
| Show Button Labels | Text under the icons. When off, only slightly larger icons are shown. |
| Bottom Bar Buttons | Up to 8 buttons (3–5 recommended): entity tabs or custom links. Drag to reorder; edit link buttons with the pencil icon. |
| Installation Statistics Enabled | Anonymous install statistics. Off by default. |
| Installation Retention (days) | Install records not seen for this long are deleted by the daily cleanup. 0 keeps them forever. Default 365. |
| Push Subscriptions Enabled | Lets devices register for push. On by default. When off, no new subscriptions are saved; existing ones stay. |
| Max Delivery Failures | A subscription is removed after this many failed deliveries in a row. 0 turns the removal off. Default 10. |

The **PWA** section of the Administration page also has two lists: **PWA Installations** (the anonymous statistics) and **PWA Push Subscriptions**. Each subscription shows its last successful delivery, last error and number of failures in a row.

The buttons on the settings page are *Send Test Push*, *Diagnostics* and *Rotate VAPID Keys*. Rotating the keys removes all subscriptions; devices subscribe again automatically the next time the app is opened. *Diagnostics* shows the keys, subscription counts (and how many fail), the last run of the reminder job, PHP `openssl`/`curl`/`gd` checks and the latest delivery errors.

**Config file parameter.** `pwaPushExtraHosts` (in `data/config.php` only) is a list of extra push service host names. It is empty by default. Leave it empty unless you really need another push service.

**About the bottom bar.**

- It appears only in the installed app, on screens narrower than 768 px. To test it in a normal browser tab, run `localStorage.setItem('mcPwaBottomBarForce', '1')` in the browser console and reload. You still need to be logged in.
- Entity buttons take their icon, color and name from the entity. They are hidden from users who cannot access that entity. Link buttons can have an access scope, an admin-only flag and an "open in new tab" option.

**Scheduled jobs.** Both are created automatically: *McPwaSendReminderPush* (every minute) and *McPwaCleanup* (daily at 03:30).

## Security

- **Shared devices.** A push subscription belongs to the browser, not to the login session. The app unsubscribes the device when a user logs out. If a session just expires, the device keeps receiving the old user's notifications until someone logs out there, another user opens the app there, or an admin deletes the subscription. On shared computers always use *Log Out*.
- **What a notification shows.** A push message can contain record names, email subjects and a short part of a stream post (only what the user may read). The operating system may show it on the lock screen. If a device is lost, delete the user's rows in *PWA Push Subscriptions*.
- **Public files.** The web manifest and the client config are public, because browsers fetch them without a login. They contain the app name, colors, language, enabled PWA features, the public push key and your *Quick Create* entity types (as shortcuts). Do not give confidential names to those entities.
- **Keys.** The private push key is kept in the internal config and is never sent through the API. It is created per installation; no secrets are stored in the repository.
- **Privacy.** Install statistics are anonymous and off by default. Push subscriptions can be switched off separately. At most 5000 install records are kept.
- **Contact address.** As the Web Push standard requires, the push token contains a contact address: the system outbound email address (or the site URL if it is empty).

To report a vulnerability, see [SECURITY.md](SECURITY.md).

## For developers

**Build the ZIP locally.** The extension uses the official template.

```bash
npm install
node build --all        # full development site
node build --extension  # installable ZIP in build/ (mc-pwa-<version>.zip)
npm run sa              # static analysis (phpstan)
npm run unit-tests      # unit tests (phpunit)
```

A server self-test of the Web Push encryption is in `tests/webpush-selftest.php`. A manual QA checklist is in `tests/manual-checklist.md`.

**Releases.** Raise the version in `extension.json` and `package.json` and merge it into `main`. The workflow [.github/workflows/release.yml](.github/workflows/release.yml) creates the release `vX.Y.Z` with `mc-pwa-X.Y.Z.zip` automatically. See [CHANGELOG.md](CHANGELOG.md) for the history.

**How it works.**

- Public entry points (GET): `pwaManifest`, `pwaServiceWorker`, `pwaIcon`, `pwaConfig`. The manifest, service worker and icons are generated from the settings.
- API (login required): `GET /McPwa/bottomBar`. Admin only: `GET /McPwa/status`, `POST /McPwa/rotateVapid`. With the `X-Requested-With` header (CSRF protection): `POST /McPwa/subscription`, `POST /McPwa/stats`, `POST /McPwa/testPush`.
- A hook on `Notification` (after save) queues a job that encrypts and sends the push through `Classes/Push/WebPushSender` (RFC 8291 `aes128gcm`, VAPID RFC 8292 `ES256`, pure PHP). All deliveries go through `Classes/Push/Dispatcher`, which records the result and removes expired (404/410) subscriptions.
- The bottom bar config is served only to logged-in users. Buttons are resolved on the server (label, icon, color, access) for the current user and language, and every value (URL, icon class, color) is validated and cleaned. The browser draws the bar with DOM APIs (no `innerHTML`) and `rel="noopener noreferrer"` on external links.

**Security details.**

- `testPush` works only on the user's own subscriptions, once per 30 seconds (a new subscription can be tested 30 seconds after it was created).
- SSRF protection: a push endpoint must be a plain `https://host[:443]/path` URL (no user-info, backslash, fragment, IP address or other port) on a known push service (Google, Mozilla, Microsoft, Apple). Before sending, the host is resolved and rejected if it points to a private, loopback, link-local or reserved address. The connection is pinned to the checked public IP on port 443 (protects against DNS rebinding). `pwaPushExtraHosts` widens the allowed list.
- Ownership: a user can remove only their own subscription, and an endpoint that belongs to another account is never silently reassigned.
- All input (keys, endpoints, identifiers, colors, icon classes, labels) is strictly validated.

## Author and license

Developed by [bshumylo](https://github.com/bshumylo). Built on the official [EspoCRM extension template](https://github.com/espocrm/ext-template).

Licensed under the [GNU AGPL v3](LICENSE). Use it at your own risk and test new versions on a copy of your CRM first.

Found a bug or have an idea? Open an [issue](../../issues).
