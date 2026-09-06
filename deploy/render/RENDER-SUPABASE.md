# Deploying to Render with Supabase Postgres

This keeps you on PostgreSQL and costs nothing to try. Budget about 45 minutes.

Everything in this folder is the deployment. You need a GitHub repository, a
Render account and a Supabase account.

---

## Before you start — read this once

This path works, and it is genuinely more fragile than shared hosting. Three
things cause almost every failure, and all three are avoidable if you know them
in advance:

**Use the Session pooler, not the direct connection.** Supabase's direct host
(`db.<ref>.supabase.co`) resolves to IPv6 only on the free tier. Render dials
IPv4. The connection times out with no useful error, and it looks like a
password problem. The session pooler is IPv4.

**Not the transaction pooler either.** Supabase offers a transaction pooler on
port 6543. It discards session state between statements, which WordPress relies
on. Use the **session** pooler on port 5432.

**The container filesystem is temporary.** Anything uploaded through wp-admin —
PDFs, images — is gone on the next deploy unless you attach a disk. The
blueprint attaches one; that requires a paid instance.

If any of that sounds like more trouble than it is worth, the shared-hosting
path in `../DEPLOY.md` is genuinely simpler. This one is right if you want to
stay on PostgreSQL and keep everything in a git push.

---

## 1 · Create the Supabase database

1. **supabase.com → New project.** Choose an **EU region** (Frankfurt or
   Ireland) — the tender commits to EU data residency, and the region cannot be
   changed later.
2. Set a strong database password and save it. Supabase shows it once.
3. Wait for provisioning, then go to **Project Settings → Database →
   Connection string** and select the **Session pooler** tab.

You get something shaped like:

```
postgresql://postgres.abcdefghijklm:PASSWORD@aws-0-eu-central-1.pooler.supabase.com:5432/postgres
             └──────── user ──────┘          └──────────── host ────────────┘ └port┘ └─ db ─┘
```

Take four values out of it:

| Variable | From the string | Example |
|---|---|---|
| `DB_USER` | the user | `postgres.abcdefghijklm` |
| `DB_PASSWORD` | your password | |
| `DB_HOST` | host **and** port, colon-separated | `aws-0-eu-central-1.pooler.supabase.com:5432` |
| `DB_NAME` | the database | `postgres` |

`DB_HOST` carries the port. WordPress expects that, and PG4WP parses it.

---

## 2 · Put the code in a repository

From the project root:

```bash
git init
git add deploy/render wordpress
git commit -m "Áis Mhatamaitice — Render deployment"
git remote add origin https://github.com/<you>/ais-mhatamaitice.git
git push -u origin main
```

Nothing secret is committed — every credential is a Render environment variable.
To confirm, check that no value is assigned rather than read:

```bash
git grep -nE "DB_PASSWORD *=|AUTH_KEY *=" -- deploy/render | grep -v getenv
```

That should return nothing. Matches on `$password` inside `pg4wp/` are the
library's own function parameters, not values.

---

## 3 · Create the Render service

**Render → New → Blueprint**, point it at the repository. It reads
`deploy/render/render.yaml` and creates the service.

Or without a blueprint: **New → Web Service**, connect the repo, then set
Runtime **Docker**, Dockerfile path `./deploy/render/Dockerfile`, Docker context
`./deploy/render`, Region **Frankfurt**.

**Environment variables** — add the four from step 1:

```
DB_HOST      aws-0-eu-central-1.pooler.supabase.com:5432
DB_NAME      postgres
DB_USER      postgres.abcdefghijklm
DB_PASSWORD  <your password>
PGSSLMODE    require
```

Then the eight WordPress salts. Generate them at
<https://api.wordpress.org/secret-key/1.1/salt/> and add each as its own
variable: `AUTH_KEY`, `SECURE_AUTH_KEY`, `LOGGED_IN_KEY`, `NONCE_KEY`,
`AUTH_SALT`, `SECURE_AUTH_SALT`, `LOGGED_IN_SALT`, `NONCE_SALT`. The blueprint
generates these for you; if you created the service by hand, do it yourself.
Leaving them empty makes every session cookie on the site forgeable.

**Plan.** Free spins down after ~15 minutes idle and the next visitor waits
about 50 seconds. For a link you send to COGG, use Starter.

Deploy. The first build takes 3–5 minutes because it downloads WordPress and
compiles the PHP extensions.

---

## 4 · Install WordPress

Open the service URL. You should see the WordPress installer.

If you instead see **"Error establishing a database connection"**, it is the
database variables — go to §8 before continuing.

Run the installer. Site title *Áis Mhatamaitice*, and pick an admin username
that is **not** `admin` or `riarthoir`. This account is yours for maintenance,
not COGG's.

The installer is creating tables in Supabase through PG4WP. It is slower than
MySQL — give it a minute.

---

## 5 · Activate, then import

In `/wp-admin`, in this order — the order matters:

1. **Plugins → Áis Mhatamaitice → Activate.** This registers the content types,
   the eight taxonomies and the three COGG roles.
2. **Appearance → Themes → Mata → Activate.**
3. **Settings → Permalinks → Post name → Save.** This flushes the rewrite rules.
   Skip it and every page except the home page returns 404.
4. **Tools → Import → WordPress**, install the importer, and upload
   `mata-content.xml`. It is in the container at
   `wp-content/mata-content.xml`, or use your local copy from `deploy/`.
   Tick *Download and import file attachments*.
5. **Appearance → Menus** → tick **Príomhroghchlár** as the *Primary* location.
   The import restores the menu but not its location.

If you activate the plugin *after* importing, resources arrive with no content
type to land in. Delete them and import again — do not try to repair it.

Now uncomment `healthCheckPath` in `render.yaml`, push, and let it redeploy. The
endpoint exists from this point on.

---

## 6 · Replace the demo accounts

The import does not bring users, so the four demo accounts do not exist on
Render — good. Create real ones:

**Users → Add New**, assigning *COGG — Riarthóir*, *COGG — Eagarthóir ábhair* or
*COGG — Údar digiteach*. Have each person set their own password via the reset
link.

---

## 7 · Verify

Against the live Render URL:

```
[ ] /                                        200, TLS valid, no mixed content
[ ] /uirlisi/  /acmhainni/                   200 — if 404, redo the permalink save
[ ] /gniomhaiochtai/  /oscail/               200
[ ] navigation links point at the Render URL  not localhost, not http
[ ] toolkit configures and previews
[ ] save & share produces a link and a file
[ ] open that link in a private window        activity restores exactly
[ ] search "codain" and "Codáin"              same results
[ ] language switch changes titles too        not only the navigation
[ ] /wp-admin redirects to login over https
[ ] publish an untagged resource              refused, fields named in Irish
[ ] /wp-json/cogg-mata/v1/health              200
[ ] /wp-json/wp/v2/users  (logged out)        401, not 200
[ ] /?author=1                                404
[ ] /xmlrpc.php                               403
```

---

## 8 · When it does not work

**"Error establishing a database connection"**

In order of likelihood:

1. `DB_HOST` is the *direct* connection rather than the session pooler. It must
   contain `pooler.supabase.com`. This is the single most common cause.
2. The port is missing from `DB_HOST`. It must read `host:5432`.
3. `DB_USER` is `postgres` rather than `postgres.<project-ref>`. The pooler
   requires the project reference in the username.
4. `PGSSLMODE` is unset. Supabase refuses unencrypted connections.
5. The Supabase project is paused. Free projects pause after 7 days idle —
   open the dashboard to wake it.

Check the Render logs. The entrypoint prints the host and database it is using
before Apache starts, and fails with a named variable if one is missing.

**Every page except the home page is a 404** — the permalink save in step 5.3
was skipped, or `AllowOverride All` is not in effect. Re-save permalinks.

**Redirects to a `.onrender.com` address after adding a custom domain** — set
`SITE_URL` to the custom domain. `RENDER_EXTERNAL_URL` always holds the
onrender address, and without `SITE_URL` that is what WordPress uses.

**The site is slow on the first request** — free-tier cold start. Upgrade to
Starter or accept it.

**Uploaded PDFs vanished after a deploy** — no persistent disk. Attach one, or
put media in Supabase Storage.

---

## 9 · Operating it

**Cron.** `DISABLE_WP_CRON` is on, because a spun-down free instance runs
nothing. Use any free scheduler (cron-job.org, GitHub Actions) to call
`https://<your-domain>/wp-cron.php?doing_wp_cron` every 15 minutes. This also
keeps a free instance warm.

**Backups.** Supabase takes daily backups on paid plans; the free tier does not.
Take your own regularly:

```bash
pg_dump "postgresql://postgres.<ref>:<password>@aws-0-<region>.pooler.supabase.com:5432/postgres" \
  -f mata-backup-$(date +%F).sql
```

Then test a restore into a scratch schema before you rely on it. An untested
backup is a hope, not a control.

**Deploys.** Push to the branch and Render rebuilds. Uploads survive only if a
disk is attached; the database is external and always survives.

---

## What this is, and is not

This gets the Hub running on a real HTTPS URL, in the EU, on PostgreSQL, for
roughly the price of a coffee per month. That is a good tender demonstration.

It is not the Phase 1 production environment described in the methodology
document. That has staging alongside production, CI, monitored backups with
tested restores, a WAF and an incident process. It also, per §9 of that
document, recommends MySQL — because PG4WP is a dependency neither we nor COGG
control, and its last release was February 2024. Running the demo on PostgreSQL
does not change that recommendation for the four-year contract; it just proves
the code is genuinely database-agnostic, which is worth something on its own.
