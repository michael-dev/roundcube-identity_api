# Changelog

## 2.2 (unreleased)

Fixes from a review of code and documentation.

* **Extension, "Connect":** any website can show such a button, so the extension no longer stores
  anything after the page's confirmation dialog. It checks the token and asks in its own page, which
  shows the server prominently and warns if the server or account changes.
* **Plugin:**
  * The address generator in the settings only accepts POST requests with Roundcube's request token
    (no cross-site requests).
  * Limits (identities per hour and per user) and token changes hold under parallel requests (lock
    per user).
  * Only identities created by the plugin can be listed and deleted through the API, no addresses
    entered by hand that happen to match the pattern (existing ones are taken over once).
  * Deleting the last identity on Roundcube 1.7 answers 409 instead of 204.
  * Saving the settings no longer overwrites token rotations that happened meanwhile.
  * The settings page also warns if the web server drops the `Authorization` header.
  * Hosting platforms (`*.myshopify.com`, `*.github.io`, …) give the shop's own name.
* **Apache:** the rewrite rule goes into Roundcube's `.htaccess` directly after `RewriteEngine On`,
  before Roundcube's own rules (which answered 403). Tested in CI with Roundcube's `.htaccess` (1.6, 1.7).
* **Extension:**
  * The popup no longer fills fields of embedded third-party frames the focus has left.
  * A rotated token is only used for its own server.
  * Saving the options keeps a pending rotated token.
  * Error handling when the extension was updated meanwhile, request timeout also covers the response.
  * Manifest description short enough for the Chrome Web Store.
* **Release workflow:** draft release first, signed XPI attached right away, re-runs continue the
  draft, secrets only in the steps that need them, reproducible plugin archives, Composer repository
  before publishing, Chrome Web Store upload after it.

## 2.1

* **The REST API is only used through its own URL** (`<roundcube-url>/api/identity/v1/…`, setting
  `identity_api_url`). The web server needs the rewrite rule from the README, tested in CI with
  nginx and Apache (PHP-FPM); Apache needs `CGIPassAuth On` for the `Authorization` header.
* Settings page: shows the API URL and warns if the API doesn't answer there.
* Extension: the options take the API URL (e.g. `https://webmail.example.org/api/identity/`) instead
  of the webmail URL; "Connect" takes it over from the settings page. After updating the extension
  from 2.0, replace the webmail URL in its options with the API URL shown in Roundcube (the token stays
  valid), or create a new token and click *Connect browser extension*. Admins: set up the rewrite rule
  before updating; 2.0 clients keep working in the meantime.
* iOS Shortcuts: use `<API URL>v1/identities` (docs/ios.md).
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
