# identity_api – per-shop e-mail addresses for Roundcube

Roundcube plugin that gives every online shop its own e-mail address, created on the fly:

```
<prefix>-<shop>-<year>-<random>@your-domain        e.g. m-bookshop-2026-k3x9q2ab@example.org
```

The addresses are stored as Roundcube identities. The plugin offers

* the **shop address REST API** ([docs/openapi.yaml](docs/openapi.yaml)), token-authenticated, used by
  the browser extension [Shop Addresses](https://github.com/michael-dev/browser-identity_api) (Shop-Adressen) for
  Firefox and Chrome, iOS Shortcuts and other clients,
* an **address generator** in *Settings → Preferences → Shop address API* (works in any browser,
  e.g. on an iPhone, see [docs/ios.md](docs/ios.md)),
* **settings** for users: access tokens (renewed automatically), pattern, prefix and domains.

Why? You can tell which shop leaked or sold your address, and you can shut down a single address by
deleting its identity.

```
browser extension ─┐                   Shop address API
iOS Shortcut ──────┼─HTTPS + token─▶ identity_api plugin ──▶ Roundcube "identities" table
other clients ─────┘◀──new address──                                     │
                                                          your mail server delivers per identity
```

> **Requirement:** your mail server has to accept mail for the addresses stored as Roundcube
> identities, e.g. through an SQL lookup on the `identities` table, see
> [docs/postfix.md](docs/postfix.md) for Postfix. The plugin only manages the identities. A
> catch-all for the domain works too, but then deleting an identity doesn't stop its mail.

Supported: Roundcube 1.5, 1.6 and 1.7 (tested with 1.5.15, 1.6.19 and 1.7.4), PHP 7.3 or later.
Tested with SQLite and MySQL/MariaDB. PostgreSQL uses only standard SQL but isn't tested. The PHP
extension `intl` is recommended; without it, shop names are transliterated with `iconv`, which
handles fewer characters.

## Installation

With Composer, from the Roundcube directory:

```bash
composer require michael-dev/identity_api
```

Or manually: extract `identity_api-<version>.tar.gz` (or `.zip`) from the
[releases](https://github.com/michael-dev/roundcube-identity_api/releases) into `plugins/`, so that
`plugins/identity_api/identity_api.php` exists, and enable it in `config/config.inc.php`:

```php
$config['plugins'][] = 'identity_api';
```

Optional: copy `plugins/identity_api/config.inc.php.dist` to `config.inc.php` and adjust it (the
Composer installer creates it automatically).

Set up the **rewrite rule** for the API URL in the web server, see [REST API](#rest-api).

List `identity_api` **after** plugins that restrict access in their `startup` hook (IP filters,
maintenance mode), because API requests end in this plugin's `startup` hook.

## Upgrading from 2.0

2.1 uses the API only through its own URL (`<roundcube-url>/api/identity/`):

1. Set up the rewrite rule (see [REST API](#rest-api)) and check the API URL in the Roundcube settings
   (no warning below it).
2. Update the plugin. Extensions and Shortcuts of 2.0 keep working.
3. Update the [browser extension](https://github.com/michael-dev/browser-identity_api), open its options and replace the webmail URL with the API URL shown in
   Roundcube (the token stays valid), or create a new token and click *Connect browser extension*.
4. Change iOS Shortcuts to `<API URL>v1/identities`, see [docs/ios.md](docs/ios.md).

## Configuration

| Option | Default | Description |
|---|---|---|
| `identity_api_template` | `{prefix}-{shop}-{year}-{random}` | Default pattern for new addresses (users can set their own). Placeholders: `{prefix}`, `{shop}`, `{year}`, `{random}` (`{shop}` and `{random}` are required). |
| `identity_api_default_prefix` | `''` | Default for `{prefix}`. Empty: first letter of the username (`manuel@…` → `m`). |
| `identity_api_url` | `'api/identity/'` | URL of the REST API, relative to Roundcube's URL or absolute. The web server maps it to Roundcube with a rewrite rule (see [REST API](#rest-api)). |
| `identity_api_domains` | `[]` | Default domains if a user hasn't configured any. The first one is the default. The placeholders `%n`, `%t` and `%d` (e.g. `%d`: `webmail.example.org` → `example.org`) come from the Host header and are only used if Roundcube's `trusted_host_patterns` is set. Empty: domain of the user's default identity. |
| `identity_api_allowed_domains` | `[]` | Restricts the domains users may configure themselves, e.g. `['example.org', '*.example.org']`. Empty: any domain (like `identities_level` 0). It doesn't apply to `identity_api_domains` or the fallback. |
| `identity_api_random_length` | `8` | Length of `{random}`, at least 4 |
| `identity_api_random_chars` | `'abcdefghijklmnopqrstuvwxyz0123456789'` | Characters used for `{random}` (listed one by one, no ranges) |
| `identity_api_shop_maxlength` | `30` | Maximum length of `{shop}` |
| `identity_api_token_rotation` | `30` | Clients are asked to rotate their token after this many days (0 = no rotation), see below. |
| `identity_api_token_lifetime` | `90` | A token that was not rotated for this many days expires, e.g. on a device that is no longer used (0 = never). Values not larger than the rotation interval are raised to twice the interval. |
| `identity_api_static_tokens` | `false` | Allow tokens without rotation and expiry, for clients that can't rotate (scripts, iOS Shortcuts) |
| `identity_api_max_tokens` | `10` | Maximum number of tokens per user |
| `identity_api_max_identities` | `5000` | Maximum number of identities per user, all identities counted (0 = unlimited) |
| `identity_api_rate_limit` | `30` | Maximum number of identities a user can create per hour through the API or the address generator in the settings (0 = unlimited) |
| `identity_api_max_login_age` | `0` | Reject tokens of users who haven't logged in to the webmail for this many days (0 = off) |
| `identity_api_unique_identities` | `true` | Refuse identities, also in the Roundcube settings, whose address another user has or had (deleted identities included). Set to `false` for deliberately shared addresses. |
| `identity_api_ignore_identities_level` | `false` | Also create identities if `identities_level` is 1 or higher (users may not choose addresses, or single identity mode) |

Changing the pattern, `identity_api_random_length` or `identity_api_random_chars` later hides older
addresses from listing and deleting when they no longer match, and the Postfix lookup
([docs/postfix.md](docs/postfix.md)) has to match the new format.

To stop users from changing a setting, add its user preference to Roundcube's `dont_override`:
`identity_api_user_template`, `identity_api_prefix`, `identity_api_user_domains`. The admin default
then applies.

## User settings

**Settings → Preferences → Shop address API**:

* **New shop address:** create an address for a shop name or website (`gardenshop.example` → `gardenshop`) and copy
  it, or list the existing ones. This works in any browser, e.g. on an iPhone.
* **Connection:** the API URL to use in the extension. After creating a token (see *Create
  token*), it is shown here **once**, together with a **Connect browser extension** button.
* **Addresses:** the **pattern** (e.g. `{prefix}.{shop}.{random}` without the year; empty = admin
  pattern), a personal **prefix** (letters and digits; empty = default) and **domains**, one per
  line. The first domain is the default, the others can be chosen in the extension. An example of the
  resulting address is shown.
* **Access tokens:** one token per device. Tokens can be revoked individually and are shown with
  their creation, last-use, renewal and expiry dates, together with the server's rotation policy.
* **Create token:** enter a device name and save. If the admin allows it, a token can be created
  **without automatic renewal** (for scripts and iOS Shortcuts).

Deleting an identity (in the Roundcube settings or through the API) marks it as deleted
(`del = 1`). If your mail server's lookup filters on `del = 0`, this shuts down the address.

## REST API

The plugin offers a versioned REST API (v1) that other systems can use too. The complete description
is in [`docs/openapi.yaml`](docs/openapi.yaml) (OpenAPI 3.1), so you can generate clients from it.

**Base URL.** The API lives below `<roundcube-url>/api/identity/`, e.g.

```
https://webmail.example.org/api/identity/v1/identities
https://webmail.example.org/api/identity/v1/identities?shop=gardenshop
```

**Rewrite rule (required).** Roundcube has no URL routing, so the web server has to pass these URLs
to Roundcube. For Roundcube at the root of its host:

```nginx
# nginx, in the server block of Roundcube, before the location for PHP
location ^~ /api/identity/ {
    rewrite ^/api/identity(/.*)$ /index.php?_task=identity_api&_path=$1 last;
}
```

```apache
# Apache: in Roundcube's .htaccess, directly after "RewriteEngine On"
# (before Roundcube's own rules, which would answer 403)
RewriteRule ^api/identity(/.*)$ index.php?_task=identity_api&_path=$1 [QSA,L]
# pass the "Authorization" header to PHP (PHP-FPM / FastCGI)
CGIPassAuth On
```

Both rules are tested in CI with PHP-FPM, the Apache rule in Roundcube's own `.htaccess` (1.6 and
1.7). `CGIPassAuth` in `.htaccess` needs `AllowOverride AuthConfig` (or `All`). If `.htaccess` files
are disabled (`AllowOverride None`), put the lines with `RewriteEngine On` into the `<Directory>` block
of Roundcube's document root instead.

For Roundcube in a subdirectory, e.g. `/roundcube/` (not tested): nginx
`location ^~ /roundcube/api/identity/ { rewrite ^/roundcube/api/identity(/.*)$ /roundcube/index.php?_task=identity_api&_path=$1 last; }`,
Apache the same lines in that directory's `.htaccess`. For another location of the API, set
`identity_api_url`.

The settings page shows the API URL and warns if the API doesn't answer there or the web server
drops the `Authorization` header. The browser extension sends the token only in `Authorization`.

**Authentication:** `Authorization: Bearer <token>`, or `X-Identity-Api-Token: <token>` for servers
that drop the `Authorization` header.

| Method and path | Description | Success |
|---|---|---|
| `GET /v1/me` | User, name, prefix, pattern, allowed domains, default domain, limits | 200 |
| `GET /v1/identities[?shop=…]` | Generated identities, newest first, optionally for one shop | 200 `{"items": [...]}` |
| `POST /v1/identities` | Create an address. JSON or form: `shop` (name or website address), optional `url` (website address, if `shop` is empty), `domain`, `name` | 201 + `Location` |
| `GET /v1/identities/{id}` | One generated identity | 200 |
| `DELETE /v1/identities/{id}` | Delete a generated identity (Roundcube marks it deleted) | 204 |
| `GET /v1/token` | Status of the token in use: created, renewed, last use, expiry, rotation due | 200 |
| `POST /v1/token/rotate` | New token (see rotation) | 200 `{"token": ...}` |

An identity looks like
`{"id": 42, "email": "m-bookshop-2026-k3x9q2ab@example.org", "name": "…", "shop": "bookshop", "prefix": "m", "year": 2026, "changed": "2026-09-27T20:21:12Z"}`.
Only identities created by the plugin (their ids are recorded) that still match the user's or the
admin's pattern can be listed or deleted, never addresses the user entered by hand.

**Errors** are [RFC 9457](https://www.rfc-editor.org/rfc/rfc9457) problem details
(`application/problem+json`) with a machine readable `code`, e.g.
`{"status": 403, "code": "domain_not_allowed", "detail": "domain not allowed", …}`. 401 comes with
`WWW-Authenticate`, 405 with `Allow`, and 429 (`rate_limit_exceeded`) with `Retry-After`.

```bash
curl -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"shop":"Gärtnerei Grün"}' 'https://webmail.example.org/api/identity/v1/identities'
# 201 {"id":42,"email":"m-gaertnerei-gruen-2026-2zw1w368@example.org","shop":"gaertnerei-gruen",...}
```

The server normalizes shop names: lower case, transliteration (`ä→ae`, `ß→ss`, `Ł→l`, …), other
characters become `-`, at most `identity_api_shop_maxlength` characters. A website address
(`https://checkout.gardenshop.example/kasse`) is accepted as shop too; the shop name is then derived from its
host (`gardenshop`).

**Token rotation.** Every authenticated response carries `Identity-Api-Token-Rotate: true|false` and,
unless the token never expires (`identity_api_token_lifetime` = 0, or a token without rotation),
`Identity-Api-Token-Expires: <time>`. On `true`, call `POST /v1/token/rotate` and switch to the
returned token. The old token stays valid until the new one is used for the first time, so a lost
response doesn't lock the client out; of several unused new tokens, the first one used wins. A token
that isn't rotated before its expiry stops working (`identity_api_token_lifetime`). For clients that
can't rotate (scripts, iOS Shortcuts), the admin can allow tokens without rotation
(`identity_api_static_tokens`); they get no `Identity-Api-Token-Expires` header.

## Security

* Tokens have the form `<user_id>.<token_id>.<256 random bits>`. Only the SHA-256 hash is stored in
  the user's preferences, and tokens are compared in constant time.
* Tokens are rotated regularly and expire if they are not rotated, so a leaked token of a
  device that is no longer used stops working on its own. Tokens without rotation (if allowed) are
  the exception: they stay valid until revoked.
* A token can **only** list, create and delete the user's generated identities (new ones only on the
  configured domains) and rotate itself. It grants no access to mail or other settings.
* New addresses are checked against the identities of all users, deleted ones included.
* API requests don't create Roundcube sessions. They still run the standard `identity_create`,
  `identity_create_after` and `identity_delete` hooks, and every created or deleted address is
  logged to `logs/identity_api`.
* Tokens don't depend on the IMAP login. To lock out a user, revoke their tokens (or delete the
  Roundcube user). Optionally, `identity_api_max_login_age` requires regular webmail logins, and a
  plugin can veto tokens through the `identity_api_authenticate` hook
  (`['user' => rcube_user, 'token_id' => …, 'valid' => true]`). Tokens of users locked by
  `login_rate_limit` are rejected.
* Use one token per device: with rotation, a token copied to a second device stops working there
  after the first rotation.
* Use HTTPS. The extension refuses plain HTTP (except `localhost`) and doesn't follow redirects.

## Development

```
identity_api.php, identity_api.js   the plugin
lib/, localization/
docs/        OpenAPI description, Postfix guide, iOS guide
tests/       unit tests, settings UI test, integration tests (Roundcube + API, Composer install,
             Postfix guide)
```

```bash
make test               # unit tests (address generator)
make integration-test   # downloads Roundcube (RC_VERSION, default 1.6.19) and runs it with SQLite;
                        # tests the API over HTTP and the settings hooks
WEB=nginx make integration-test  # through nginx or Apache (WEB=apache) with PHP-FPM and the rewrite rule of this README
DB=mysql make integration-test   # on MySQL/MariaDB instead (MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE)
CLIENT_TEST=../browser-identity_api/tests/client.sh make integration-test
                        # also the tests of the browser extension against this server
                        # (E2E=1: the real Chrome extension in Chromium, needs python3 and Playwright)
make composer-test      # installs the plugin with Composer into Roundcube
make postfix-test       # checks docs/postfix.md with postmap and the triggers (MYSQL_HOST, MYSQL_PORT, MYSQL_ROOT_PASSWORD)
make archive            # release archives in dist/
```

CI runs the integration test with SQLite on Roundcube 1.5/PHP 7.3, 1.6/PHP 8.1 and 1.7/PHP 8.4
(each with the Composer install and the end-to-end test of the browser extension from its
repository's `main`), through nginx and Apache with the rewrite rules from this README, on MariaDB,
and the Postfix guide with a real `postmap`.

### Releases

*Actions → Release → Run workflow* with a new version (e.g. `2.3`), or pushing such a tag, runs the
tests and publishes a GitHub release with `identity_api-<version>.tar.gz` and `.zip`. Packagist
reads the tags of this repository (`composer.json` at its root) and installs GitHub's archive of the
tag; files marked `export-ignore` in `.gitattributes` (tests, docs, CI) are not part of it.

Packagist setup (once): submit `https://github.com/michael-dev/roundcube-identity_api` on
<https://packagist.org/packages/submit>. For automatic updates, either

* let Packagist set up its GitHub hook: log in to Packagist via GitHub (grant access to the
  repository) and, if the package page still warns that it is not auto-updated, use *Sync* on your
  profile, or add the webhook yourself (*Settings → Webhooks*, payload URL
  `https://packagist.org/api/github?username=<packagist user>`, content type `application/json`,
  secret: your Packagist API token, push events), or
* set the repository variable `PACKAGIST_USERNAME` and the secret `PACKAGIST_TOKEN` (the **safe** API
  token from your Packagist profile, which can only trigger package updates): the workflow *Packagist* then notifies Packagist on every push to `main` and after every
  release.

Until 2.2 the plugin was developed together with the browser extension in
[browser-identity_api](https://github.com/michael-dev/browser-identity_api) (folder `plugin/`); this
repository continues its history.

## License

GPL-3.0-or-later, see [LICENSE](LICENSE).
