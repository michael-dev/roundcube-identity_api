# identity_api – per-shop e-mail addresses for Roundcube

Roundcube plugin that gives every online shop its own e-mail address, created on the fly:

```
<prefix>-<shop>-<year>-<random>@your-domain        e.g. m-bookshop-2026-k3x9q2ab@example.org
```

The addresses are stored as Roundcube identities. The plugin offers

* the **shop address REST API** (token-authenticated, OpenAPI description in the project
  repository) used by the browser extension “Shop-Adressen” for Firefox and Chrome, iOS Shortcuts
  and other clients,
* an **address generator** in *Settings → Preferences → Shop address API* (works in any browser,
  e.g. on an iPhone),
* **settings** for users: access tokens (renewed automatically), pattern, prefix and domains.

Supported: Roundcube 1.5, 1.6 and 1.7, PHP 7.3 or later.

Full documentation, the browser extension and guides (Postfix, iOS):
<https://github.com/michael-dev/ff-rc-identity>

## Installation

From the Roundcube directory:

```bash
composer require michael-dev/identity_api
```

The Composer installer offers to enable the plugin and creates `plugins/identity_api/config.inc.php`
from `config.inc.php.dist`. Without Composer, extract the release archive into `plugins/` and add
`identity_api` to `$config['plugins']`.

Then set up the rewrite rule for the API URL (`<roundcube-url>/api/identity/`), for Roundcube at the
root of its host:

```nginx
# nginx, in the server block of Roundcube, before the location for PHP
location ^~ /api/identity/ {
    rewrite ^/api/identity(/.*)$ /index.php?_task=identity_api&_path=$1 last;
}
```

```apache
# Apache: in Roundcube's .htaccess, directly after "RewriteEngine On"
RewriteRule ^api/identity(/.*)$ index.php?_task=identity_api&_path=$1 [QSA,L]
CGIPassAuth On
```

The settings page shows the API URL and warns if the API doesn't answer there.

**Your mail server has to accept mail for the identities**, e.g. through an SQL lookup on the
`identities` table (guide for Postfix in the project repository). The plugin only manages the
identities.

## Configuration

All options with their defaults are in `config.inc.php.dist`, for example the default domains
(`identity_api_domains`), the domains users may choose (`identity_api_allowed_domains`), the
address pattern, limits (`identity_api_max_identities`, default 5000; `identity_api_rate_limit`,
default 30 per hour) and the token rotation.

## License

GPL-3.0-or-later
