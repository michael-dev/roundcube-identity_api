#!/usr/bin/env bash
# Integration test: runs Roundcube (SQLite or MySQL) with the identity_api
# plugin and exercises the REST API over HTTP.
#   WEB=php (default): PHP's built-in server with tests/router.php
#   WEB=nginx, WEB=apache: the web server with PHP-FPM and the rewrite rule
#   from the README (needs nginx or apache2 and php-fpm, see start_web below)
set -euo pipefail

RC_VERSION="${RC_VERSION:-1.6.19}"
PORT="${PORT:-8089}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$(mktemp -d)}"
mkdir -p "$WORK"
RC="$WORK/roundcubemail-$RC_VERSION"

if [ ! -d "$RC" ]; then
  curl -fsSL "https://github.com/roundcube/roundcubemail/releases/download/$RC_VERSION/roundcubemail-$RC_VERSION-complete.tar.gz" \
    | tar -xz -C "$WORK"
fi

ln -sfn "$ROOT/plugin" "$RC/plugins/identity_api"
mkdir -p "$RC/db" "$RC/logs"
cat > "$RC/config/config.inc.php" <<'PHP'
<?php
$config['db_dsnw'] = getenv('RC_DSN') ?: 'sqlite:///' . __DIR__ . '/../db/rc.db?mode=0646';
$config['imap_host'] = 'localhost:143';
$config['des_key'] = 'abcdefghijklmnopqrstuvwx';
$config['plugins'] = ['identity_api'];
$config['log_dir'] = __DIR__ . '/../logs/';
$config['identity_api_domains'] = ['example.org', 'shop.example.net'];
PHP

# database: SQLite (default) or MySQL/MariaDB (DB=mysql, see MYSQL_* below)
if [ "${DB:-sqlite}" = mysql ]; then
  MYSQL_HOST="${MYSQL_HOST:-127.0.0.1}" MYSQL_PORT="${MYSQL_PORT:-3306}"
  MYSQL_USER="${MYSQL_USER:-roundcube}" MYSQL_PASSWORD="${MYSQL_PASSWORD:-roundcube}" MYSQL_DATABASE="${MYSQL_DATABASE:-roundcube_test}"
  export PDO_DSN="mysql:host=$MYSQL_HOST;port=$MYSQL_PORT;dbname=$MYSQL_DATABASE;charset=utf8mb4" PDO_USER="$MYSQL_USER" PDO_PASS="$MYSQL_PASSWORD"
  export RC_DSN="mysql://$MYSQL_USER:$MYSQL_PASSWORD@$MYSQL_HOST:$MYSQL_PORT/$MYSQL_DATABASE"
  export SCHEMA=mysql
  php -r '
    $p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER"), getenv("PDO_PASS"));
    $p->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($p->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN) as $t) { $p->exec("DROP TABLE `$t`"); }
    $p->exec("SET FOREIGN_KEY_CHECKS = 1");
  '
else
  export PDO_DSN="sqlite:$RC/db/rc.db" PDO_USER="" PDO_PASS="" RC_DSN=""
  export SCHEMA=sqlite
  rm -f "$RC/db/rc.db"
fi
TOKEN=$(php -r '
  $p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null);
  $p->exec(file_get_contents($argv[1] . "/SQL/" . getenv("SCHEMA") . ".initial.sql"));
  $p->exec("INSERT INTO users (username, mail_host, created, language, preferences) VALUES (\"user@example.org\", \"localhost\", \"" . date("Y-m-d H:i:s") . "\", \"de_DE\", \"a:0:{}\")");
  $uid = $p->lastInsertId();
  $p->exec("INSERT INTO identities (user_id, changed, del, standard, name, email) VALUES ($uid, \"" . date("Y-m-d H:i:s") . "\", 0, 1, \"Test User\", \"user@example.org\")");
  $secret = rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
  $prefs = ["identity_api_tokens" => ["0123abcd" => ["label" => "test", "hash" => hash("sha256", $secret), "created" => time(), "last_used" => 0]]];
  $st = $p->prepare("UPDATE users SET preferences = ? WHERE user_id = ?");
  $st->execute([serialize($prefs), $uid]);
  echo "$uid.0123abcd.$secret";
' "$RC")

# serve public_html like a production setup (required by Roundcube >= 1.7)
DOCROOT="$RC"
[ -f "$RC/public_html/index.php" ] && DOCROOT="$RC/public_html"

# rewrite rule for the web server, taken from the README (```nginx / ```apache block)
readme_block() {
  awk -v lang="$1" '$0 == "```" lang {on = 1; next} on && $0 == "```" {exit} on' "$ROOT/README.md"
}

start_web() {
  local sock fpm
  case "${WEB:-php}" in
  php)
    php -d opcache.enable=0 -d opcache.enable_cli=0 -S "127.0.0.1:$PORT" -t "$DOCROOT" "$ROOT/tests/router.php" >"$WORK/server.log" 2>&1 &
    SERVER=$!
    return
    ;;
  esac

  # PHP-FPM, socket in a short path (length limit of unix sockets)
  SOCKDIR=$(mktemp -d /tmp/rcid.XXXXXX)
  chmod 755 "$SOCKDIR"
  sock="$SOCKDIR/fpm.sock"
  fpm="${FPM:-$(ls /usr/sbin/php-fpm* 2>/dev/null | head -1)}"
  cat > "$WORK/fpm.conf" <<CONF
[global]
error_log = $WORK/fpm.log
daemonize = no
[www]
listen = $sock
listen.mode = 0666
pm = static
pm.max_children = 4
clear_env = no
catch_workers_output = yes
php_admin_value[opcache.enable] = 0
CONF
  if [ "$(id -u)" = 0 ]; then "$fpm" -R -y "$WORK/fpm.conf" & else "$fpm" -y "$WORK/fpm.conf" & fi
  FPM_PID=$!

  case "$WEB" in
  nginx)
    mkdir -p "$WORK/nginx"
    cat > "$WORK/nginx.conf" <<CONF
$( [ "$(id -u)" = 0 ] && echo "user root;" )
worker_processes 1;
pid $WORK/nginx/nginx.pid;
error_log $WORK/nginx/error.log;
daemon off;
events {}
http {
  include /etc/nginx/mime.types;
  access_log $WORK/nginx/access.log;
  client_body_temp_path $WORK/nginx/body;
  fastcgi_temp_path $WORK/nginx/fastcgi;
  proxy_temp_path $WORK/nginx/proxy;
  uwsgi_temp_path $WORK/nginx/uwsgi;
  scgi_temp_path $WORK/nginx/scgi;
  server {
    listen 127.0.0.1:$PORT;
    root $DOCROOT;
    index index.php;

$(readme_block nginx)

    location ~ \.php\$ {
      include /etc/nginx/fastcgi_params;
      fastcgi_param SCRIPT_FILENAME \$document_root\$fastcgi_script_name;
      fastcgi_pass unix:$sock;
    }
  }
}
CONF
    nginx -e "$WORK/nginx/error.log" -c "$WORK/nginx.conf" &
    SERVER=$!
    ;;
  apache)
    mkdir -p "$WORK/apache"
    local user=""
    if [ "$(id -u)" = 0 ]; then user="User www-data
Group www-data"; chmod -R o+rX "$WORK"; fi
    cat > "$WORK/apache.conf" <<CONF
ServerRoot /etc/apache2
Listen 127.0.0.1:$PORT
ServerName localhost
PidFile $WORK/apache/httpd.pid
DefaultRuntimeDir $WORK/apache
ErrorLog $WORK/apache/error.log
$user
LoadModule mpm_event_module /usr/lib/apache2/modules/mod_mpm_event.so
LoadModule authz_core_module /usr/lib/apache2/modules/mod_authz_core.so
LoadModule dir_module /usr/lib/apache2/modules/mod_dir.so
LoadModule mime_module /usr/lib/apache2/modules/mod_mime.so
LoadModule rewrite_module /usr/lib/apache2/modules/mod_rewrite.so
LoadModule proxy_module /usr/lib/apache2/modules/mod_proxy.so
LoadModule proxy_fcgi_module /usr/lib/apache2/modules/mod_proxy_fcgi.so
TypesConfig /etc/mime.types
DocumentRoot $DOCROOT
DirectoryIndex index.php
<FilesMatch "\.php\$">
  SetHandler "proxy:unix:$sock|fcgi://localhost"
</FilesMatch>
<Directory />
  AllowOverride None
</Directory>
<Directory $DOCROOT>
  AllowOverride None
  Require all granted
$(readme_block apache)
</Directory>
CONF
    apache2 -f "$WORK/apache.conf" -DFOREGROUND &
    SERVER=$!
    ;;
  *)
    echo "unknown WEB=$WEB"; exit 2
    ;;
  esac
}

start_web
trap 'kill $SERVER ${FPM_PID:-} 2>/dev/null; rm -rf "${SOCKDIR:-/nonexistent}"' EXIT
sleep 2

FAIL=0
check() { # name expected-status expected-regex curl-args...
  local name="$1" status="$2" regex="$3"; shift 3
  local out code
  out=$(curl -s -w '\n%{http_code}' "$@")
  code=$(tail -n1 <<<"$out"); out=$(sed '$d' <<<"$out")
  if [ "$code" = "$status" ] && grep -Eq "$regex" <<<"$out"; then
    echo "ok   $name"
  else
    echo "FAIL $name: HTTP $code $out"; FAIL=1
  fi
}
V1="http://127.0.0.1:$PORT/api/identity"
B=(-H "Authorization: Bearer $TOKEN")
J=(-H 'Content-Type: application/json')
Y=$(date +%Y)
header_check() { # name header-regex curl-args...
  local name="$1" regex="$2"; shift 2
  if curl -s -D - -o /dev/null "$@" | tr -d '\r' | grep -Eiq "$regex"; then echo "ok   $name"; else echo "FAIL $name"; FAIL=1; fi
}

check "no token"          401 '"code":"unauthorized"' "${V1}/v1/me"
check "wrong token"       401 '"code":"unauthorized"' -H "Authorization: Bearer ${TOKEN%?}x" "${V1}/v1/me"
check "me"                200 '"prefix":"u".*"domains":\["example.org","shop.example.net"\]' "${B[@]}" "${V1}/v1/me"
check "token header auth" 200 '"user":"user@example.org"' -H "X-Identity-Api-Token: $TOKEN" "${V1}/v1/me"
check "create"            201 "\"email\":\"u-gaertnerei-gruen-$Y-[a-z0-9]{8}@example.org\"" "${B[@]}" "${J[@]}" -d '{"shop":"Gärtnerei Grün"}' "${V1}/v1/identities"
check "create 2nd domain" 201 "\"email\":\"u-bookshop-$Y-[a-z0-9]{8}@shop.example.net\"" "${B[@]}" "${J[@]}" -d '{"shop":"bookshop","domain":"shop.example.net"}' "${V1}/v1/identities"
check "create form data"  201 "\"email\":\"u-bookshop-$Y-[a-z0-9]{8}@example.org\"" "${B[@]}" -d 'shop=Bookshop' "${V1}/v1/identities"
check "foreign domain"    403 '"code":"domain_not_allowed"' "${B[@]}" "${J[@]}" -d '{"shop":"bookshop","domain":"evil.com"}' "${V1}/v1/identities"
check "empty shop"        400 '"code":"shop_missing"' "${B[@]}" "${J[@]}" -d '{"shop":"!!"}' "${V1}/v1/identities"
check "list"              200 '^\{"items":\[\{[^]]*"shop":"bookshop"[^]]*\},\{[^]]*"shop":"bookshop"[^]]*\}\]\}$' "${B[@]}" "${V1}/v1/identities?shop=Bookshop"
check "list other shop"   200 '^\{"items":\[\]\}$' "${B[@]}" "${V1}/v1/identities?shop=gardenshop"
check "no _path"          404 '"code":"not_found"' "${B[@]}" "http://127.0.0.1:$PORT/?_task=identity_api"
check "old _action API gone" 404 '"code":"not_found"' "${B[@]}" "http://127.0.0.1:$PORT/?_task=identity_api&_action=info"
check "login page intact" 200 'rcmloginuser' "http://127.0.0.1:$PORT/?_task=login"

# domains configured by the user in the settings UI take precedence
php -r '
  $p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null);
  $prefs = unserialize($p->query("SELECT preferences FROM users WHERE user_id = 1")->fetchColumn());
  $prefs["identity_api_user_domains"] = ["kunden.example.com", "example.org"];
  $prefs["identity_api_prefix"] = "fam";
  $st = $p->prepare("UPDATE users SET preferences = ? WHERE user_id = 1");
  $st->execute([serialize($prefs)]);
' "$RC"
check "user domains"      200 '"domains":\["kunden.example.com","example.org"\],"default_domain":"kunden.example.com"' "${B[@]}" "${V1}/v1/me"
check "user prefix"       200 '"prefix":"fam"' "${B[@]}" "${V1}/v1/me"
check "old prefix listed" 200 "\"email\":\"u-bookshop-$Y-" "${B[@]}" "${V1}/v1/identities?shop=bookshop"
check "user default"      201 "\"email\":\"fam-gardenshop-$Y-[a-z0-9]{8}@kunden.example.com\"" "${B[@]}" -d 'shop=gardenshop' "${V1}/v1/identities"
check "admin default off" 403 '"code":"domain_not_allowed"' "${B[@]}" -d 'shop=gardenshop' -d 'domain=shop.example.net' "${V1}/v1/identities"

# --- review fixes -----------------------------------------------------------
CONF="$RC/config/config.inc.php"
cp "$CONF" "$CONF.orig"
set_config() { cp "$CONF.orig" "$CONF"; for c in "$@"; do echo "$c" >> "$CONF"; done; }
set_prefs() { # php code working on $prefs
  php -r '
    $p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null);
    $prefs = unserialize($p->query("SELECT preferences FROM users WHERE user_id = 1")->fetchColumn());
    eval($argv[2]);
    $st = $p->prepare("UPDATE users SET preferences = ? WHERE user_id = 1");
    $st->execute([serialize($prefs)]);
  ' "$RC" "$1"
}

# --- REST API v1 details --------------------------------------------------------
check "v1 me"                 200 '"user":"user@example.org".*"pattern":"\{prefix\}-\{shop\}-\{year\}-\{random\}"' "${B[@]}" "${V1}/v1/me"
check "v1 no token"           401 '"status":401,.*"code":"unauthorized"' "${V1}/v1/me"
header_check "v1 401 WWW-Authenticate" '^WWW-Authenticate: Bearer' "${V1}/v1/me"
header_check "v1 problem+json"  '^Content-Type: application/problem\+json' "${V1}/v1/me"
header_check "v1 token headers" '^Identity-Api-Token-Rotate: false' "${B[@]}" "${V1}/v1/me"
check "v1 create"             201 '"email":"fam-v1-test-[0-9]{4}-[a-z0-9]{8}@kunden.example.com".*"shop":"v1-test"' "${B[@]}" -H 'Content-Type: application/json' -d '{"shop":"V1 Test"}' "${V1}/v1/identities"
header_check "v1 create Location" '^Location: identities/[0-9]+' "${B[@]}" -d 'shop=v1 test' "${V1}/v1/identities"
check "v1 list by shop"       200 '^\{"items":\[\{"id":[0-9]+,"email":"fam-v1-test-' "${B[@]}" "${V1}/v1/identities?shop=v1%20test"
check "v1 list, other query" 200 '"shop":"v1-test"' "${B[@]}" "${V1}/v1/identities?shop=v1-test"
check "v1 list all"           200 '"shop":"bookshop".*"shop":"gaertnerei-gruen"' "${B[@]}" "${V1}/v1/identities"
V1ID=$(curl -s "${B[@]}" "${V1}/v1/identities?shop=v1-test" | php -r 'echo json_decode(stream_get_contents(STDIN), true)["items"][0]["id"];')
check "v1 get"                200 "\"id\":$V1ID," "${B[@]}" "${V1}/v1/identities/$V1ID"
check "v1 delete"             204 '^$' -X DELETE "${B[@]}" "${V1}/v1/identities/$V1ID"
check "v1 deleted is gone"    404 '"code":"not_found"' "${B[@]}" "${V1}/v1/identities/$V1ID"
check "v1 main identity not deletable" 404 '"code":"not_found"' -X DELETE "${B[@]}" "${V1}/v1/identities/1"
check "v1 method not allowed" 405 '"code":"method_not_allowed"' -X PUT "${B[@]}" "${V1}/v1/identities"
header_check "v1 405 Allow"   '^Allow: GET, POST' -X PUT "${B[@]}" "${V1}/v1/identities"
check "v1 unknown resource"   404 '"code":"not_found"' "${B[@]}" "${V1}/v2/nothing"
check "v1 domain not allowed" 403 '"code":"domain_not_allowed"' "${B[@]}" -d 'shop=x' -d 'domain=evil.example' "${V1}/v1/identities"
check "v1 token"              200 '"id":"0123abcd".*"rotate":false' "${B[@]}" "${V1}/v1/token"

# user pattern: new addresses use it, listing still finds old ones
set_prefs '$prefs["identity_api_user_template"] = "web.{shop}.{random}";'
check "user pattern create"  201 '"email":"web\.gardenshop\.[a-z0-9]{8}@kunden\.example\.com"' "${B[@]}" -d 'shop=gardenshop' "${V1}/v1/identities"
check "list both patterns"   200 '"email":"web\.gardenshop\..*"email":"fam-gardenshop-' "${B[@]}" "${V1}/v1/identities?shop=gardenshop"
set_prefs 'unset($prefs["identity_api_user_template"]);'

# identities_level 1: core only allows the login address
set_config "\$config['identities_level'] = 1;"
check "identities_level 1" 403 '"code":"identities_disabled"' "${B[@]}" -d 'shop=gardenshop' "${V1}/v1/identities"
set_config

# rate limit (default 30 per hour)
set_prefs '$prefs["identity_api_recent"] = array_fill(0, 29, time());'
check "rate limit: 30th ok"   201 '"shop":"gardenshop"' "${B[@]}" -d 'shop=gardenshop' "${V1}/v1/identities"
check "rate limit: 31st"      429 '"code":"rate_limit_exceeded"' "${B[@]}" -d 'shop=gardenshop' "${V1}/v1/identities"
header_check "rate limit: Retry-After" '^Retry-After: [0-9]+' "${B[@]}" -d 'shop=gardenshop' "${V1}/v1/identities"
set_prefs '$prefs["identity_api_recent"] = [];'

# Host based placeholders need trusted_host_patterns
set_config "\$config['identity_api_allowed_domains'] = ['%d'];"
check "untrusted %d: fail closed" 200 '"domains":\["example.org","shop.example.net"\]' -H "Host: webmail.bank.example" "${B[@]}" "${V1}/v1/me"
set_config "\$config['identity_api_allowed_domains'] = ['%d'];" "\$config['identity_api_domains'] = ['%d'];" "\$config['trusted_host_patterns'] = ['127.0.0.1'];"
check "forged Host ignored"       200 '"domains":\["example.org"\]' -H "Host: webmail.bank.example" "${B[@]}" "${V1}/v1/me"
set_config

# website address as shop name (iOS share sheet)
check "v1 create from URL"    201 '"shop":"gardenshop"' "${B[@]}" -H 'Content-Type: application/json' -d '{"shop":"https://checkout.gardenshop.example/kasse?x=1"}' "${V1}/v1/identities"

# tokens without rotation (admin option): no rotation, no expiry, rotate refused
static_token() { # creates token 5a5a5a5a, issued 400 days ago, marked static
  php -r '
    $p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null);
    $prefs = unserialize($p->query("SELECT preferences FROM users WHERE user_id = 1")->fetchColumn());
    $secret = rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
    $prefs["identity_api_tokens"]["5a5a5a5a"] = ["label" => "shortcut", "hash" => hash("sha256", $secret),
      "created" => time() - 400 * 86400, "issued" => time() - 400 * 86400, "last_used" => 0, "static" => true];
    $st = $p->prepare("UPDATE users SET preferences = ? WHERE user_id = 1");
    $st->execute([serialize($prefs)]);
    echo "1.5a5a5a5a.$secret";
  ' "$RC"
}
S=(-H "Authorization: Bearer $(static_token)")
check "static token, option off: expired" 401 '"code":"unauthorized"' "${S[@]}" "${V1}/v1/token"
S=(-H "Authorization: Bearer $(static_token)")
set_config "\$config['identity_api_static_tokens'] = true;"
check "static token: valid after 400 days" 200 '"rotate":false,"static":true' "${S[@]}" "${V1}/v1/token"
header_check "static token: rotate header false" '^Identity-Api-Token-Rotate: false' "${S[@]}" "${V1}/v1/me"
check "static token: rotate refused" 409 '"code":"static_token"' -X POST "${S[@]}" "${V1}/v1/token/rotate"
set_config
set_prefs 'unset($prefs["identity_api_tokens"]["5a5a5a5a"]);'

# tokens from before rotation support (no "issued") keep working and start counting now
set_prefs 'unset($prefs["identity_api_tokens"]["0123abcd"]["issued"]); $prefs["identity_api_tokens"]["0123abcd"]["created"] = time() - 200 * 86400;'
header_check "token without issued works" '^Identity-Api-Token-Rotate: false' "${B[@]}" "${V1}/v1/me"
ISSUED=$(php -r '$p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null); echo unserialize($p->query("SELECT preferences FROM users WHERE user_id = 1")->fetchColumn())["identity_api_tokens"]["0123abcd"]["issued"] ?? "none";' "$RC")
if [ "$ISSUED" != "none" ] && [ $(( $(date +%s) - ISSUED )) -lt 60 ]; then echo "ok   token without issued migrated"; else echo "FAIL token issued=$ISSUED"; FAIL=1; fi

# token rotation (defaults: rotate after 30 days, expire after 90 days without rotation)
set_issued() { # token-id days-ago
  php -r '
    $p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null);
    $prefs = unserialize($p->query("SELECT preferences FROM users WHERE user_id = 1")->fetchColumn());
    $prefs["identity_api_tokens"][$argv[2]]["issued"] = time() - $argv[3] * 86400;
    $st = $p->prepare("UPDATE users SET preferences = ? WHERE user_id = 1");
    $st->execute([serialize($prefs)]);
  ' "$RC" "$1" "$2"
}
json_field() { php -r '$d = json_decode(stream_get_contents(STDIN), true); echo $d[$argv[1]] ?? "";' "$1"; }

header_check "fresh: no rotation"  '^Identity-Api-Token-Rotate: false' "${B[@]}" "${V1}/v1/me"
header_check "fresh: expiry"      '^Identity-Api-Token-Expires: [0-9]{4}-' "${B[@]}" "${V1}/v1/me"
set_issued 0123abcd 40
header_check "rotation due"       '^Identity-Api-Token-Rotate: true' "${B[@]}" "${V1}/v1/me"
check "rotate needs POST"   405 '"code":"method_not_allowed"' "${B[@]}" "${V1}/v1/token/rotate"
NEW1=$(curl -s -X POST "${B[@]}" "${V1}/v1/token/rotate" | json_field token)
NEW2=$(curl -s -X POST "${B[@]}" "${V1}/v1/token/rotate" | json_field token)
check "old valid until new used" 200 '"user":"user@example.org"' "${B[@]}" "${V1}/v1/me"
header_check "earlier pending valid" '^Identity-Api-Token-Rotate: false' -H "Authorization: Bearer $NEW1" "${V1}/v1/me"
check "old invalid after use"    401 '"code":"unauthorized"' "${B[@]}" "${V1}/v1/me"
check "other pending discarded"  401 '"code":"unauthorized"' -H "Authorization: Bearer $NEW2" "${V1}/v1/me"
TOKEN="$NEW1"; B=(-H "Authorization: Bearer $TOKEN")
set_issued 0123abcd 100
check "unrotated expired"   401 '"code":"unauthorized"' "${B[@]}" "${V1}/v1/me"
REMAINING=$(php -r '$p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null); echo count(unserialize($p->query("SELECT preferences FROM users WHERE user_id = 1")->fetchColumn())["identity_api_tokens"] ?? []);' "$RC")
if [ "$REMAINING" = "0" ]; then echo "ok   expired token removed"; else echo "FAIL tokens left: $REMAINING"; FAIL=1; fi

# the extension's API client rotates automatically
CLIENT_TOKEN=$(php -r '
  $p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null);
  $prefs = unserialize($p->query("SELECT preferences FROM users WHERE user_id = 1")->fetchColumn());
  $secret = rtrim(strtr(base64_encode(random_bytes(32)), "+/", "-_"), "=");
  $prefs["identity_api_tokens"]["c11e0001"] = ["label" => "client", "hash" => hash("sha256", $secret),
    "created" => time() - 50 * 86400, "issued" => time() - 40 * 86400, "last_used" => 0];
  $st = $p->prepare("UPDATE users SET preferences = ? WHERE user_id = 1");
  $st->execute([serialize($prefs)]);
  echo "1.c11e0001.$secret";
' "$RC")
if command -v node >/dev/null; then
  node "$ROOT/tests/rotation-client.js" "$V1/" "$CLIENT_TOKEN" || FAIL=1
else
  echo "skip client rotation test (node not installed)"
fi

# settings section (preferences hooks)
php "$ROOT/plugin/tests/settings_test.php" "$RC" >"$WORK/settings.out" 2>&1 || FAIL=1
grep -v 'Deprecated' "$WORK/settings.out"

SESSIONS=$(php -r '$p = new PDO(getenv("PDO_DSN"), getenv("PDO_USER") ?: null, getenv("PDO_PASS") ?: null); echo $p->query("SELECT count(*) FROM session")->fetchColumn();' "$RC")
# only the login page request may have created a session
if [ "$SESSIONS" -le 1 ]; then echo "ok   no API sessions left"; else echo "FAIL $SESSIONS sessions left"; FAIL=1; fi

# end-to-end test of the real Chromium extension (E2E=1; needs python3, Node.js
# with Playwright and its Chromium, e.g. npm install playwright && npx playwright install chromium)
if [ "${E2E:-0}" = 1 ]; then
  IMAP_PORT=$((PORT + 1000))
  SHOP_PORT=$((PORT + 2000))
  python3 "$ROOT/tests/e2e/fakeimap.py" "$IMAP_PORT" >"$WORK/imap.log" 2>&1 &
  IMAP=$!
  php -S "127.0.0.1:$SHOP_PORT" -t "$ROOT/tests/e2e" >"$WORK/shop.log" 2>&1 &
  SHOP=$!
  trap 'kill $SERVER ${FPM_PID:-} $IMAP $SHOP 2>/dev/null; rm -rf "${SOCKDIR:-/nonexistent}"' EXIT
  # imap_host: Roundcube >= 1.6, default_host/default_port: 1.5
  set_config "\$config['imap_host'] = 'localhost:$IMAP_PORT';" "\$config['default_host'] = 'localhost';" "\$config['default_port'] = $IMAP_PORT;"
  node "$ROOT/scripts/build-chrome.js" "$WORK/chrome" >/dev/null
  rm -rf "$WORK/chromium-profile"
  sleep 1
  node "$ROOT/tests/e2e/chromium-extension.test.js" "$WORK/chrome" "http://127.0.0.1:$PORT/" "$SHOP_PORT" user@example.org "$WORK" || FAIL=1
  set_config
fi

exit $FAIL
