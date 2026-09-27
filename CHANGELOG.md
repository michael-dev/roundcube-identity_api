# Changelog

## 2.1

* **The REST API is only used through its own URL** (`<roundcube-url>/api/identity/v1/…`, setting
  `identity_api_url`). The web server needs the rewrite rule from the README, tested in CI with
  nginx and Apache (PHP-FPM); Apache needs `CGIPassAuth On` for the `Authorization` header.
* Settings page: shows the API URL and warns if the API doesn't answer there.
* Extension: the options take the API URL (e.g. `https://webmail.example.org/api/identity/`) instead
  of the webmail URL; "Connect" takes it over from the settings page. After updating from 2.0, click
  "Connect" once more or enter the API URL in the options.
* `Location` of a new identity is relative to the request URL (`identities/42`).

## 2.0

First public release.

* **Roundcube plugin `identity_api`** (Roundcube 1.5–1.7, PHP 7.3+):
  * REST API v1 (`…?_task=identity_api&_path=/v1/…`, optional pretty URLs via rewrite) to create,
    list and delete generated identities; RFC 9457 problem details with error codes; OpenAPI 3.1
    description in `docs/openapi.yaml`.
  * Personal access tokens with automatic rotation (interval and expiry set by the admin), optional
    tokens without rotation for scripts and iOS Shortcuts.
  * Address pattern (`{prefix}-{shop}-{year}-{random}` by default), prefix and domains configurable by
    the admin and, unless locked, by each user.
  * Address generator in the Roundcube settings (for browsers without extensions, e.g. on iOS).
  * Unique addresses across users, limits (identities per user and hour), `identities_level` respected.
  * Install from the release archive or with Composer (Composer repository in the releases).
* **Browser extension “Shop-Adressen”** for Firefox (desktop and Android) and Chromium based browsers
  (Chrome, Edge, Brave, Vivaldi): detects e-mail fields (also without `type="email"`, fields can be
  taught), suggests the shop name from the website, creates or reuses an address and fills it in;
  one-click connection from the Roundcube settings.
* **Docs:** Postfix with Roundcube's MySQL database (`docs/postfix.md`), iPhone/iPad (`docs/ios.md`).
* **Tests:** integration tests against Roundcube 1.5, 1.6 and 1.7 (SQLite, MariaDB), end-to-end test of
  the Chrome extension, Composer install and the Postfix guide in CI.
