#!/usr/bin/env bash
# Installs the plugin with Composer into Roundcube, from an archive of the
# repository like the one Packagist offers (git archive, see make archive),
# through a local Composer repository. Usage: tests/composer.sh <identity_api-*.zip>
set -euo pipefail

ARCHIVE="$(cd "$(dirname "$1")" && pwd)/$(basename "$1")"
RC_VERSION="${RC_VERSION:-1.6.19}"
PORT="${PORT:-8091}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$(mktemp -d)}"
RC="$WORK/composer-rc-$RC_VERSION"

rm -rf "$RC" "$WORK/composer-repo"
mkdir -p "$RC" "$WORK/composer-repo"
curl -fsSL "https://github.com/roundcube/roundcubemail/releases/download/$RC_VERSION/roundcubemail-$RC_VERSION-complete.tar.gz" \
  | tar -xz -C "$RC" --strip-components=1

cp "$ARCHIVE" "$WORK/composer-repo/"
php -r '$j = json_decode(file_get_contents($argv[1]), true);
  $j["version"] = "9.9.9";
  $j["dist"] = ["type" => "zip", "url" => $argv[2], "shasum" => sha1_file($argv[3])];
  echo json_encode(["packages" => [$j["name"] => ["9.9.9" => $j]]], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);' \
  "$ROOT/composer.json" "http://127.0.0.1:$PORT/$(basename "$ARCHIVE")" "$ARCHIVE" > "$WORK/composer-repo/packages.json"
php -S "127.0.0.1:$PORT" -t "$WORK/composer-repo" >"$WORK/composer-repo.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null' EXIT
sleep 1

cd "$RC"
[ -f composer.json ] || cp composer.json-dist composer.json
export COMPOSER_NO_INTERACTION=1 COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_ROOT_VERSION="$RC_VERSION"
# plugins.roundcube.net (in composer.json of Roundcube < 1.7) isn't needed and not always reachable
php -r '$j = json_decode(file_get_contents("composer.json"), true);
  $j["repositories"] = array_values(array_filter($j["repositories"] ?? [], function ($r) { return strpos($r["url"] ?? "", "plugins.roundcube.net") === false; }));
  if (!$j["repositories"]) { unset($j["repositories"]); }
  file_put_contents("composer.json", json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");'
composer config secure-http false
composer config repositories.identity_api composer "http://127.0.0.1:$PORT"
# --ignore-platform-reqs: Roundcube itself requires extensions like ext-ldap
composer require --no-audit --update-no-dev --ignore-platform-reqs michael-dev/identity_api:9.9.9

FAIL=0
for f in identity_api.php identity_api.js lib/identity_api_generator.php localization/en_US.inc config.inc.php; do
  if [ -f "plugins/identity_api/$f" ]; then echo "ok   plugins/identity_api/$f"; else echo "FAIL plugins/identity_api/$f missing"; FAIL=1; fi
done
# development files are not part of the package (export-ignore in .gitattributes)
for f in tests docs Makefile .github; do
  if [ -e "plugins/identity_api/$f" ]; then echo "FAIL plugins/identity_api/$f installed"; FAIL=1; fi
done
php -l plugins/identity_api/identity_api.php >/dev/null && echo "ok   php -l" || FAIL=1

[ "$FAIL" = 0 ] && echo "Composer install OK" || echo "Composer install FAILED"
exit "$FAIL"
