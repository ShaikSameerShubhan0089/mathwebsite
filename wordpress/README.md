# Áis Mhatamaitice — Primary Mathematics Hub

WordPress implementation of the COGG Primary Mathematics Hub (RFT: *Provision of
Website Design, Development, Hosting, Maintenance and Digital Resource Portal
Services*).

Stack: **WordPress 6.7 · PHP 8.2 · MySQL 8.0 (utf8mb4) · H5P (Phase 3, optional)**

---

## Run it

**Use [`../local/`](../local/README.md).** It runs on this machine with your
existing MySQL 8 plus a PHP download — no Docker, no Apache:

```powershell
cd local
.setup.ps1     # one-time, asks for your MySQL root password
.start.ps1     # then open http://localhost:8000
```

The Docker stack below is kept as an alternative for CI or a Linux host. It is
not the recommended path on Windows.

<details>
<summary>Docker alternative</summary>

```bash
cd wordpress
docker compose up -d
docker compose run --rm setup
```

Then open **http://localhost:8091**.

</details>

| Account | Password | Role | Can do |
|---|---|---|---|
| `riarthoir` | `cogg2026` | WordPress admin | everything |
| `sile` | `cogg2026` | COGG Content Editor | pages + PDF resources |
| `dara` | `cogg2026` | COGG Digital Author | digital activities only |
| `aoife` | `cogg2026` | COGG Administrator | the above + users + taxonomy |

To include the Phase 3 authoring platform: `INSTALL_H5P=1 docker compose run --rm setup`

Reset everything: `docker compose down -v`

---

## What to look at during an evaluation

**1. The save-and-share workflow — no login, no server (RFT §7.2.3)**
Go to `/uirlisi/`, configure an activity, press **Sábháil & roinn**. You get a
shareable link and the exact JSON that would be saved, shown in full. Open the
link in a private window: the activity reconstructs with no account and no
session. Open DevTools → Network while you do it — there are no requests. That
is the point: pupil data cannot reach the server because nothing is sent.

**2. Irish-language search (RFT §13, SRS §12.6)**
On `/acmhainni/`, search `codain` without fadas. It returns *Codáin aonaid* and
*Codáin choibhéiseacha*. Search `Codáin` with fadas — same results. Sorting is
Irish alphabetical: Achar, Airgead, An t-am.

**3. The metadata gate (BR-03)**
The seeded resource *"Dul chun cinn i dtáblaí — dréacht"* is deliberately
incomplete. Sign in as `sile`, open it, press **Publish**. It refuses and names
the missing fields. It refuses via the REST API and WP-CLI too — the gate is a
capability check, not a form validation.

**4. Role separation (SRS §4.2)**
Sign in as `sile` — no Activities menu. As `dara` — no Resources menu, no Pages.
As `aoife` — everything plus Taxonomy. Try to reach a forbidden URL directly;
WordPress returns 403 because the capability is absent, not because a menu is
hidden.

**5. The audit log (SRS §14.5)**
Acmhainní → Loga iniúchta. Every create, edit, publish, unpublish, login and
failed login, attributed and timestamped.

**6. COGG-owned taxonomy (SRS §3.7)**
As `aoife`, add a term under any taxonomy. It appears immediately as a filter
facet. No developer, no deployment.

---

## Layout

```
wordpress/
├── docker-compose.yml            WP + MySQL, utf8mb4 pinned at every layer
├── bin/
│   ├── setup.sh                  one-shot provisioner (WP-CLI)
│   └── seed.php                  sample content, incl. the deliberate failure case
└── wp-content/
    ├── plugins/cogg-mata/        all requirement logic lives here
    │   ├── cogg-mata.php         bootstrap, upload allow-list, security headers
    │   ├── includes/
    │   │   ├── class-cpt.php         post types + 8 curriculum taxonomies
    │   │   ├── class-roles.php       SRS §4.2 matrix + publish capability gate
    │   │   ├── class-metadata.php    BR-03 gate (3 layers) + config sanitiser
    │   │   ├── class-search.php      fada-insensitive search, Irish collation
    │   │   ├── class-audit.php       editorial accountability table
    │   │   ├── class-rest.php        public read API + admin endpoints
    │   │   ├── class-h5p.php         Phase 3 authoring integration
    │   │   └── class-shortcodes.php  server-rendered browse + mount points
    │   └── assets/toolkit.js     the toolkit + save-and-share (zero network)
    └── themes/mata/              layout and chrome only
```

The split is deliberate. Everything that encodes a *requirement* is in the
plugin; the theme owns only presentation. COGG can restyle or replace the theme
without losing the taxonomy, the gate, the roles or the toolkit — which is what
SRS §21.3 promises about no component restricting future modification.

---

## Requirement traceability

| RFT / SRS | Where |
|---|---|
| §7.2.1 hosting, SSL, backups | `docker-compose.yml`, host config |
| §7.2.2 toolkit integration | `assets/toolkit.js` |
| §7.2.3 no-login save-and-share | `assets/toolkit.js` — no network calls |
| §7.2.4 PDF library | `class-cpt.php`, `class-shortcodes.php` |
| §8.2.1 faceted browse & search | `class-search.php` |
| §8.2.3 controlled taxonomy | `class-cpt.php` |
| §8.2.4 COGG self-service CMS | `class-roles.php` |
| §9.2.1 authoring platform | `class-h5p.php` |
| §9.2.2 activity library | `class-cpt.php` |
| §9.2.3 teacher adaptation | `toolkit.js` → `mountToolkit` (`?adapt=`) |
| §9.2.4 editorial workflow | `class-metadata.php`, `class-audit.php` |
| §12.5 accessibility | `assets/mata.css`, theme templates |
| §12.6 data protection | no pupil schema anywhere; see below |
| §12.7 security | `cogg-mata.php`, `class-roles.php` |
| §13 Irish language | `class-search.php`, `docker-compose.yml` |
| BR-03 metadata gate | `class-metadata.php` |
| BR-05 no personal data in file | `class-metadata.php::sanitise_config` |

---

## Data protection, concretely

RFT §12.6 asks whether personal data is collected. The answer here is
structural rather than procedural:

- **No pupil post type, no pupil table, no pupil role.** There is nowhere to put
  pupil data even by mistake.
- **`subscriber` is removed and registration disabled** on activation, so the
  site cannot accumulate public accounts.
- **The activity file is client-side only.** `toolkit.js` contains no `fetch`,
  `XMLHttpRequest` or `sendBeacon` in the save, share or open path.
- **`sanitise_config()` strips any key** resembling a personal identifier before
  activity settings are written, so BR-05 survives a careless paste.
- **Download counting is a single integer** on the post — no IP, no user agent,
  no cookie, no row per visitor.
- **`/wp-json/cogg-mata/v1/health`** reports `pupil_accounts: 0` and
  `pupil_records: 0` so monitoring proves the claim continuously rather than at
  audit time.

The only personal data on the platform is COGG staff account details: name,
email, role.

---

## Known limitations

Stated plainly, because a tender should.

1. **Teacher adaptation works on the native kits, not on H5P content.** H5P's
   `.h5p` export is an authoring-level artefact, not the lightweight per-teacher
   file the Phase 1 workflow produces. Adaptation is therefore marked
   unsupported for H5P items rather than half-working. Resolving this properly
   is early-Phase-3 design work and is on the risk register.

2. **PostgreSQL is not supported.** WordPress core is MySQL/MariaDB only; every
   plugin, H5P included, writes MySQL SQL directly. The community shims are
   unmaintained and would be a poor bet on a four-year public-sector contract.
   This stack uses MySQL 8 with `utf8mb4_unicode_ci`.

3. **This compose file is a development stack, not a production deployment.**
   Production needs the managed Irish/EU host, TLS, off-site backups, the CDN
   and the staging environment described in SRS §8.5 and §17.

4. **The toolkit here is newly built.** RFT §4 says "create a toolkit" while
   §7.2.2 describes integrating an existing one. That ambiguity is still open
   with COGG and materially affects Phase 1 cost.

---

## Development

```bash
docker compose exec wordpress bash        # shell into WP
docker compose run --rm setup wp <cmd>    # any WP-CLI command
docker compose logs -f wordpress          # tail logs
docker compose exec db mysql -umata -pmata_dev_password mata   # SQL
```

Lint the plugin (needs Composer + PHPCS with the WordPress standard):

```bash
phpcs --standard=WordPress wp-content/plugins/cogg-mata
```
