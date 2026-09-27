# Postfix: deliver mail to Roundcube identities (MySQL/MariaDB)

This guide makes Postfix accept and deliver mail for the addresses stored as Roundcube identities,
using Roundcube's MySQL/MariaDB database directly. Every generated shop address then works as soon
as it is created, and deleting the identity in Roundcube shuts the address down.

It assumes Roundcube's database is called `roundcube` and Postfix delivers to virtual mailboxes
(`virtual_mailbox_domains`, e.g. with Dovecot). Adjust names to your setup.

## How it works

Postfix looks up every recipient in `virtual_alias_maps`. For a shop address, the query returns the
mailbox of the Roundcube user who owns the identity:

```
m-bookshop-2026-k3x9q2ab@example.org  →  identities.email → users.username  →  michael@example.org
```

Three rules keep this safe. The queries below implement all of them:

1. **Only generated addresses.** Users can add any address as an identity in Roundcube. Without a
   restriction, a user could add someone else's address, e.g. `boss@example.org`, and receive a
   copy of their mail. So only addresses in the format of the shop addresses (or on a domain used only
   for them) are looked up.
2. **Not deleted.** `del = 0`. Deleting an identity in Roundcube stops delivery.
3. **Unique.** If two users have the same address, the lookup returns nothing and Postfix rejects the
   mail, instead of delivering it to both. The plugin and a database trigger (see below) prevent
   such duplicates in the first place.

## 1. Database user for Postfix

Postfix only needs read access to two tables:

```sql
CREATE USER 'postfix'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT SELECT ON roundcube.identities TO 'postfix'@'localhost';
GRANT SELECT ON roundcube.users TO 'postfix'@'localhost';
```

## 2. Lookup table

`/etc/postfix/mysql-roundcube-identities.cf` (readable only by root and Postfix, it contains the
password: `chmod 640`, `chgrp postfix`):

```
hosts = unix:/run/mysqld/mysqld.sock
user = postfix
password = a-long-random-password
dbname = roundcube
query = SELECT MIN(u.username) FROM identities i JOIN users u ON u.user_id = i.user_id
    WHERE i.email = '%s' AND i.del = 0
      AND i.email REGEXP '^[a-z0-9]{1,16}-[a-z0-9-]+-[0-9]{4}-[a-z0-9]{8}@'
    GROUP BY i.email
    HAVING COUNT(DISTINCT i.user_id) = 1
```

* `MIN(u.username)` returns the Roundcube login of the owner. It must be the address of the user's
  mailbox. If users log in with a plain name (`michael`), use
  `SELECT CONCAT(MIN(u.username), '@example.org') …` instead.
* The `REGEXP` matches the default pattern `{prefix}-{shop}-{year}-{random}`. If you changed
  `identity_api_template` or allow users to set their own pattern, adjust it. Better still,
  **use a domain only for shop addresses** (e.g. `shop.example.org`, configured in
  `identity_api_allowed_domains`). Then replace the `REGEXP` line with
  `AND i.email LIKE '%%@shop.example.org'` (`%%` is a literal `%` in Postfix queries), and any
  pattern works.
* With the default collation (`utf8mb4_unicode_ci`), comparisons are case-insensitive.

Test it (the first address is a generated one, the second a normal one):

```bash
postmap -q m-bookshop-2026-k3x9q2ab@example.org mysql:/etc/postfix/mysql-roundcube-identities.cf
# michael@example.org
postmap -q boss@example.org mysql:/etc/postfix/mysql-roundcube-identities.cf
# (no output)
```

## 3. main.cf

Add the table to your existing alias maps, **before** a catch-all if you have one:

```
virtual_alias_maps = mysql:/etc/postfix/mysql-roundcube-identities.cf, …your existing maps…
```

The shop domain must be one Postfix accepts mail for. If it's already in `virtual_mailbox_domains`,
nothing else is needed. For a domain used only for shop addresses, add it to
`virtual_alias_domains` instead (never to both lists):

```
virtual_alias_domains = shop.example.org
```

Then run `postfix reload`.

### Optional: send from the shop addresses

If you use `reject_sender_login_mismatch`, allow users to send from their identities as well:

`/etc/postfix/mysql-roundcube-senders.cf`:

```
hosts = unix:/run/mysqld/mysqld.sock
user = postfix
password = a-long-random-password
dbname = roundcube
query = SELECT MIN(u.username) FROM identities i JOIN users u ON u.user_id = i.user_id
    WHERE i.email = '%s' AND i.del = 0
      AND i.email REGEXP '^[a-z0-9]{1,16}-[a-z0-9-]+-[0-9]{4}-[a-z0-9]{8}@'
    GROUP BY i.email
    HAVING COUNT(DISTINCT i.user_id) = 1
```

```
smtpd_sender_login_maps = mysql:/etc/postfix/mysql-roundcube-senders.cf, …your existing maps…
```

The lookup key there is the sender address, and the result must be the SASL login name. That is
usually the same as the Roundcube username, otherwise adjust the `SELECT` like above.

## 4. Unique addresses

An address must belong to one user only, otherwise the lookup above refuses to deliver it.

**In the plugin** (default): `$config['identity_api_unique_identities'] = true;` refuses identities
whose address another user has or had (deleted identities included), both in the API and in the
Roundcube settings.

**In the database** (catches every other way, e.g. other plugins or scripts): two triggers that reject
such inserts and updates:

```sql
DELIMITER //
CREATE TRIGGER identities_unique_email_insert BEFORE INSERT ON identities
FOR EACH ROW
BEGIN
  IF EXISTS (SELECT 1 FROM identities WHERE email = NEW.email AND user_id <> NEW.user_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'e-mail address belongs to another user';
  END IF;
END//
CREATE TRIGGER identities_unique_email_update BEFORE UPDATE ON identities
FOR EACH ROW
BEGIN
  IF NEW.email <> OLD.email
     AND EXISTS (SELECT 1 FROM identities WHERE email = NEW.email AND user_id <> NEW.user_id) THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'e-mail address belongs to another user';
  END IF;
END//
DELIMITER ;
```

Roundcube then shows "An error occurred while saving". Addresses in deleted identities stay reserved
for their user. This is intended: a shut-down shop address must not be taken over by someone else,
who would otherwise receive the shop's password-reset mails.

A unique index isn't an option: Roundcube keeps deleted identities, and one user may have the same
address in several identities (e.g. with different names).

Find existing conflicts before adding the triggers:

```sql
SELECT email, GROUP_CONCAT(DISTINCT user_id) AS users
FROM identities GROUP BY email HAVING COUNT(DISTINCT user_id) > 1;
```

## PostgreSQL

The same approach works with `pgsql:` tables (not tested). In the queries, replace `REGEXP` with `~*` and
`MIN(u.username)` stays. Write the triggers as a PL/pgSQL function that raises an exception.
