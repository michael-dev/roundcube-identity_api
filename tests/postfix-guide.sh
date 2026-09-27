#!/usr/bin/env bash
# Tests the queries and triggers of docs/postfix.md with Postfix's postmap
# against Roundcube's MySQL schema. Needs: postmap with MySQL support
# (postfix-mysql), mariadb/mysql client, a MySQL/MariaDB server.
#   MYSQL_HOST, MYSQL_PORT, MYSQL_ROOT_PASSWORD (or MYSQL_SOCKET), RC_VERSION
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
WORK="${WORK:-$(mktemp -d)}"
mkdir -p "$WORK"
RC_VERSION="${RC_VERSION:-1.6.19}"
DBNAME=roundcube_postfix_test

if [ -n "${MYSQL_SOCKET:-}" ]; then
  CONN=(--socket="$MYSQL_SOCKET" -uroot); PF_HOST="unix:$MYSQL_SOCKET"
else
  CONN=(-h "${MYSQL_HOST:-127.0.0.1}" -P "${MYSQL_PORT:-3306}" -uroot "-p${MYSQL_ROOT_PASSWORD:-root}")
  PF_HOST="inet:${MYSQL_HOST:-127.0.0.1}:${MYSQL_PORT:-3306}"
fi
M() { mariadb "${CONN[@]}" "$@"; }

# Roundcube's MySQL schema
SCHEMA="$WORK/mysql.initial.sql"
[ -f "$SCHEMA" ] || curl -fsSL "https://github.com/roundcube/roundcubemail/releases/download/$RC_VERSION/roundcubemail-$RC_VERSION-complete.tar.gz" \
  | tar -xzO "roundcubemail-$RC_VERSION/SQL/mysql.initial.sql" > "$SCHEMA"
M -e "DROP DATABASE IF EXISTS $DBNAME; CREATE DATABASE $DBNAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
M "$DBNAME" < "$SCHEMA"
M -e "CREATE USER IF NOT EXISTS 'postfix_test'@'%' IDENTIFIED BY 'pfpass';
      GRANT SELECT ON $DBNAME.identities TO 'postfix_test'@'%';
      GRANT SELECT ON $DBNAME.users TO 'postfix_test'@'%';
      CREATE USER IF NOT EXISTS 'postfix_test'@'localhost' IDENTIFIED BY 'pfpass';
      GRANT SELECT ON $DBNAME.identities TO 'postfix_test'@'localhost';
      GRANT SELECT ON $DBNAME.users TO 'postfix_test'@'localhost';"
M "$DBNAME" -e "
INSERT INTO users (user_id, username, mail_host, created) VALUES (1,'michael@example.org','localhost',NOW()),(2,'anna@example.org','localhost',NOW());
INSERT INTO identities (user_id, changed, del, standard, name, email) VALUES
 (1,NOW(),0,1,'Michael','michael@example.org'),
 (1,NOW(),0,0,'Michael','m-bookshop-2026-k3x9q2ab@example.org'),
 (1,NOW(),1,0,'Michael','m-gardenshop-2026-aaaaaaaa@example.org'),
 (1,NOW(),0,0,'Michael','m-shoeshop-2026-dddddddd@shop.example.org'),
 (2,NOW(),0,1,'Anna','anna@example.org'),
 (2,NOW(),0,0,'Anna','boss@example.org'),
 (2,NOW(),0,0,'Anna','a-shoeshop-2026-bbbbbbbb@example.org'),
 (1,NOW(),0,0,'Michael','x-dup-2026-cccccccc@example.org'),
 (2,NOW(),0,0,'Anna','x-dup-2026-cccccccc@example.org');"

# lookup tables and SQL exactly as in the guide
python3 - "$ROOT/docs/postfix.md" "$WORK" "$PF_HOST" "$DBNAME" <<'PY'
import re, sys
doc, work, host, db = sys.argv[1:5]
text = open(doc).read()
fix = lambda cf: (cf.replace('unix:/run/mysqld/mysqld.sock', host).replace('a-long-random-password', 'pfpass')
                    .replace('user = postfix\n', 'user = postfix_test\n').replace('dbname = roundcube\n', 'dbname = %s\n' % db))
cfs = re.findall(r"```\n(hosts = .*?)```", text, re.S)
open(work + '/identities.cf', 'w').write(fix(cfs[0]))
regex_line = "      AND i.email REGEXP '^[a-z0-9]{1,16}-[a-z0-9-]+-[0-9]{4}-[a-z0-9]{8}@'"
assert regex_line in cfs[0]
open(work + '/shopdomain.cf', 'w').write(fix(cfs[0]).replace(regex_line, "      AND i.email LIKE '%%@shop.example.org'"))
open(work + '/senders.cf', 'w').write(fix(cfs[1]))
open(work + '/triggers.sql', 'w').write(re.search(r"```sql\n(DELIMITER //.*?DELIMITER ;)\n```", text, re.S).group(1))
open(work + '/conflicts.sql', 'w').write(re.search(r"```sql\n(SELECT email, GROUP_CONCAT.*?)```", text, re.S).group(1))
PY

# own minimal Postfix configuration, independent of the system's (CI installs
# Postfix without configuration); MySQL map support comes from meta_directory
PFCONF="$WORK/postfix-conf"
mkdir -p "$PFCONF"
printf 'meta_directory = /etc/postfix\ncompatibility_level = 3.6\ninet_protocols = ipv4\n' > "$PFCONF/main.cf"

FAIL=0
t() { local r; r=$(postmap -c "$PFCONF" -q "$2" "mysql:$WORK/$1" 2>"$WORK/postmap.err" || true)
  if [ "$r" = "$3" ]; then echo "ok   postfix: $4"
  else echo "FAIL postfix: $4: got '$r' expected '$3' $(cat "$WORK/postmap.err")"; FAIL=1; fi; }
t identities.cf m-bookshop-2026-k3x9q2ab@example.org michael@example.org "generated address -> owner"
t identities.cf M-Bookshop-2026-K3X9Q2AB@Example.ORG michael@example.org "case-insensitive"
t identities.cf a-shoeshop-2026-bbbbbbbb@example.org anna@example.org "other user's address -> other user"
t identities.cf boss@example.org "" "hand-entered address not delivered"
t identities.cf michael@example.org "" "login address not via this map"
t identities.cf m-gardenshop-2026-aaaaaaaa@example.org "" "deleted identity not delivered"
t identities.cf x-dup-2026-cccccccc@example.org "" "duplicate across users not delivered"
t identities.cf "x' OR '1'='1" "" "injection attempt"
t shopdomain.cf m-shoeshop-2026-dddddddd@shop.example.org michael@example.org "shop domain variant"
t shopdomain.cf m-bookshop-2026-k3x9q2ab@example.org "" "shop domain variant ignores other domains"
t senders.cf m-bookshop-2026-k3x9q2ab@example.org michael@example.org "sender login map"

if M "$DBNAME" < "$WORK/conflicts.sql" | grep -q '^x-dup-2026-cccccccc@example.org'; then echo "ok   sql: conflicts query"; else echo "FAIL sql: conflicts query"; FAIL=1; fi
M "$DBNAME" < "$WORK/triggers.sql"
q() { local out; out=$(M "$DBNAME" -e "$2" 2>&1 || true)
  if [ "$1" = refused ]; then echo "$out" | grep -q "belongs to another user" && echo "ok   trigger: refused $3" || { echo "FAIL trigger: $3: $out"; FAIL=1; }
  else [ -z "$out" ] && echo "ok   trigger: allowed $3" || { echo "FAIL trigger: $3: $out"; FAIL=1; }; fi; }
q refused "INSERT INTO identities (user_id, changed, name, email) VALUES (2, NOW(), 'x', 'm-bookshop-2026-k3x9q2ab@example.org')" "other user's address"
q refused "INSERT INTO identities (user_id, changed, name, email) VALUES (2, NOW(), 'x', 'M-BOOKSHOP-2026-K3X9Q2AB@example.org')" "other user's address in other case"
q refused "INSERT INTO identities (user_id, changed, name, email) VALUES (2, NOW(), 'x', 'm-gardenshop-2026-aaaaaaaa@example.org')" "other user's deleted address"
q refused "UPDATE identities SET email = 'michael@example.org' WHERE user_id = 2 AND email = 'boss@example.org'" "change to other user's address"
q allowed "INSERT INTO identities (user_id, changed, name, email) VALUES (1, NOW(), 'Michael 2', 'm-bookshop-2026-k3x9q2ab@example.org')" "same address twice for one user"
q allowed "UPDATE identities SET name = 'Anna B' WHERE user_id = 2 AND email = 'x-dup-2026-cccccccc@example.org'" "rename"
q allowed "INSERT INTO identities (user_id, changed, name, email) VALUES (2, NOW(), 'x', 'a-new-2026-eeeeeeee@example.org')" "new unique address"

M -e "DROP DATABASE $DBNAME"
exit $FAIL
