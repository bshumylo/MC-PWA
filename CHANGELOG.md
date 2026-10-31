# Changelog

## 1.3.0

### Compatibility
- Verified against EspoCRM 10.0.11 (PHPStan level 8 with no errors); PHP 8.3 – 8.5.
- PHP 8.5: removed `imagedestroy()` and `curl_close()` (deprecated, no-ops since PHP 8.0).
- Hooks implement the typed core interfaces (`AfterSave`, `AfterRemove`).
- The service worker entry point returns 404 instead of failing when the file is missing (`FileManager::getContents()` throws in v10).
- Minimum PHP is now 8.3 (EspoCRM 10 requirement).

### Added
- Diagnostics modal and `GET /McPwa/status` (administrators).
- VAPID key rotation (`POST /McPwa/rotateVapid`); the client replaces subscriptions made with an old key.
- Delivery log on subscriptions: last success, last error, failures in a row.
- Daily cleanup job (`McPwaCleanup`): failing subscriptions and old installation records; settings *Max Delivery Failures* and *Installation Retention*.
- Per-user push notification types and quiet hours (user menu → *Push Notification Settings*).
- CI on PHP 8.3 / 8.4 / 8.5 (lint, PHPStan, unit tests), Dependabot, pinned GitHub Actions, security policy.
- The release workflow also runs for version tags.

### Security
- The curl DNS pin for IPv6 endpoints is written in the bracketed form so the pin cannot be silently dropped.

## 1.2.0 and earlier

See the [GitHub releases](https://github.com/bshumylo/MC-PWA/releases).
