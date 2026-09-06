# Deploying Áis Mhatamaitice

A working deployment takes about an hour on shared hosting. The order below
matters: the plugin must be active before the content import, or the import has
nowhere to put resources and activities.

Everything in this folder is what gets uploaded. Nothing from `local/` does —
that is the development harness.

---

## 0 · Before you start

**Pick MySQL, not PostgreSQL.** The development build runs on PostgreSQL through
the PG4WP drop-in. Shared hosts almost never offer PostgreSQL for WordPress, and
PG4WP is a dependency we do not control — see Technical Methodology §9. The
plugin and theme are tested on both, so this costs nothing but avoids a
maintenance liability.

**What you need**

| | |
|---|---|
| Hosting | Any host with PHP 8.1+, MySQL 5.7+/MariaDB 10.4+, and free TLS |
| Domain | The agreed `.ie` name, with DNS you control |
| Access | SFTP or a file manager, plus phpMyAdmin or equivalent |

Recommended: an Irish host (Blacknight, Register365) so the data-residency
commitment in the tender is demonstrably true rather than promised.

---

## 1 · Install WordPress

Use the host's one-click WordPress installer, or upload WordPress manually.
Install into the **web root**, not a subdirectory.

Note the database name, user and password the installer creates — you need them
only if you edit `wp-config.php` by hand.

Log in once at `/wp-admin` to confirm the base install works before adding
anything of ours. If this step is broken, everything after it is harder to
diagnose.

---

## 2 · Apply the production configuration

Open the `wp-config.php` the installer generated. Paste the contents of
`wp-config-production-snippet.php` **above** this line:

```php
require_once ABSPATH . 'wp-settings.php';
```

Replace every `REPLACE_ME` with the real domain.

Then check the salts. The installer usually generates them; if the
`AUTH_KEY` block still says `put your unique phrase here`, get fresh values from
<https://api.wordpress.org/secret-key/1.1/salt/> and paste them in. Without real
salts every session cookie on the site is forgeable.

---

## 3 · Upload the site files

Upload, preserving structure:

```
wp-content/plugins/cogg-mata/     →  wp-content/plugins/cogg-mata/
wp-content/themes/mata/           →  wp-content/themes/mata/
.htaccess                         →  the web root
```

`.htaccess` starts hidden — enable "show hidden files" in your SFTP client, and
if the host generated its own, merge rather than overwrite: keep the host's
rules and add ours.

**Do not upload** `local/`, `router.php`, `proxy.js`, `pg4wp/`, `db.php` or the
development `wp-config.php`. They are for the PHP built-in server and will break
an Apache host.

---

## 4 · Activate

In `/wp-admin`:

1. **Plugins → Áis Mhatamaitice → Activate.** This registers the content types,
   the eight taxonomies and the three COGG roles. It must happen before step 5.
2. **Appearance → Themes → Mata → Activate.**
3. **Settings → Permalinks → Post name → Save.** Saving flushes the rewrite
   rules; without it every page except the home page returns 404. Save even if
   "Post name" is already selected.

---

## 5 · Import the content

**Tools → Import → WordPress.** Install the importer if prompted, then upload
`mata-content.xml`.

On the import screen:
- Assign authors to an existing user, or create new ones — either is fine, you
  will replace the accounts in step 6.
- Tick **"Download and import file attachments."**

The import brings 7 pages, 15 resources, 6 activities, the four navigation
items and 47 curriculum terms across the eight vocabularies — 8 class levels,
5 strands, 12 strand units, 9 topics, 5 resource types, 4 learning focuses,
3 formats and 1 curriculum.

Then **Appearance → Menus** and tick **Príomhroghchlár** as the *Primary*
menu location. The import restores the menu but not its location assignment.

---

## 6 · Replace the demo accounts

The development build ships four accounts on a shared password. **None of them
should exist on a public site.**

1. **Users → Add New** for each real COGG member of staff, assigning:
   - *COGG — Riarthóir* for administrators
   - *COGG — Eagarthóir ábhair* for resource editors
   - *COGG — Údar digiteach* for activity authors
2. Have each person set their own password via the reset link. Do not choose
   passwords on their behalf.
3. **Delete** `aoife`, `dara` and `sile`, reassigning their content to a real
   account.
4. Keep exactly one WordPress administrator for maintenance. Rename it from
   `riarthoir` and give it a long unique password.

---

## 7 · Verify

Work through this list against the live domain. Each line has failed on a real
deployment at some point.

```
[ ] https://<domain>/                       200, TLS valid, no mixed-content warning
[ ] /uirlisi/  /acmhainni/                  200 — if 404, redo the permalink save
[ ] /gniomhaiochtai/  /oscail/              200
[ ] navigation links point at the domain    not localhost, not http
[ ] toolkit loads, configure and preview     works
[ ] save & share produces a link and a file
[ ] open the link in a private window        activity restores exactly
[ ] search "codain" and "Codáin"             same results — fadas fold correctly
[ ] a resource PDF downloads
[ ] language switch changes titles too       not only the navigation
[ ] /wp-admin redirects to login over https
[ ] log in, publish an untagged resource     refused, missing fields named in Irish
[ ] /wp-json/wp/v2/users  (logged out)       401, not 200
[ ] /?author=1                               404
[ ] /xmlrpc.php                              403
```

The last three confirm the hardening survived the move. If `wp/v2/users` returns
200, the plugin is not active.

---

## 8 · Operational setup

**Backups.** Configure the host's nightly backup, or a plugin, with off-site
retention. Then *test a restore* — an untested backup is a hope, not a control.

**Cron.** The production config disables WordPress's page-load cron. Add a real
scheduler entry, replacing the domain:

```
*/15 * * * * curl -s https://<domain>/wp-cron.php?doing_wp_cron > /dev/null
```

**Monitoring.** Point an uptime monitor at `/wp-json/cogg-mata/v1/health`, which
exists for exactly this and returns without touching content.

**HSTS.** Once the site has run on TLS for a week without trouble, uncomment the
`Strict-Transport-Security` line in `.htaccess` and raise `max-age` to a year.
Enable it too early and a TLS misconfiguration locks visitors out.

---

## If something goes wrong

| Symptom | Cause | Fix |
|---|---|---|
| Home page fine, everything else 404 | Rewrite rules not flushed | Settings → Permalinks → Save |
| Unstyled text, no CSS | Mixed content — page https, assets http | Confirm `WP_HOME` uses `https://` |
| Redirects to `localhost` | The development `wp-config.php` was uploaded | Replace with the host's, plus the production snippet |
| "Error establishing a database connection" | PG4WP files uploaded to a MySQL host | Delete `wp-content/db.php` and `wp-content/pg4wp/` |
| Resources import but have no metadata | Plugin activated after the import | Activate the plugin, delete the imports, import again |
| Cannot publish anything | Working as designed — the metadata gate | Complete the six vocabularies and the description |

---

## What this deployment is not

This gets the Hub running on a real domain, which is what a tender demonstration
needs. It is not the full Phase 1 production environment described in the
methodology document: staging alongside production, CI, monitored backups with
tested restores, WAF, and an incident process are all part of the contracted
build, not this checklist.
