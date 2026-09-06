'use strict';
/**
 * REST API.
 *
 * Public routes are read-only and anonymous — no account is required to browse,
 * search, or download anything (RFT §8.2.1, SRS §3.5). There is deliberately no
 * public endpoint that ACCEPTS data: the save-and-share workflow never posts to
 * this server, so there is no route here through which pupil data could arrive
 * even by accident (BR-01, BR-05, SRS §7.2).
 *
 * Every /api/admin route calls auth.require() before touching the database.
 */
const crypto = require('node:crypto');
const path = require('node:path');
const fs = require('node:fs');
const db = require('./db');
const auth = require('./auth');
const { searchBlob, compareIrish, terms, normalise } = require('./search');

const { HttpError } = auth;

/** BR-03: every published item must carry the full metadata set. */
const REQUIRED = ['title', 'description', 'class_level', 'strand', 'strand_unit',
  'resource_type', 'format', 'topic'];

const FIELD_LABEL = {
  title: 'Teideal', description: 'Cur síos gearr', class_level: 'Rangleibhéal',
  strand: 'Snáithe', strand_unit: 'Aonad snáithe', topic: 'Topaic',
  resource_type: 'Cineál acmhainne', format: 'Formáid'
};

const FACET_FIELDS = ['class_level', 'strand', 'strand_unit', 'topic', 'resource_type', 'format'];
const MAX_PDF_BYTES = 20 * 1024 * 1024;

function nowISO() { return new Date().toISOString(); }
function missingFields(item) {
  return REQUIRED.filter(f => !item[f] || String(item[f]).trim() === '');
}
function permFor(kind) { return kind === 'activity' ? 'activities' : 'resources'; }

function rowToItem(r) {
  return {
    id: r.id, kind: r.kind, title: r.title, description: r.description,
    class_level: r.class_level, strand: r.strand, strand_unit: r.strand_unit,
    topic: r.topic, resource_type: r.resource_type, format: r.format,
    status: r.status, featured: !!r.featured,
    file_name: r.file_name, file_size: r.file_size,
    kit: r.kit, cfg: r.cfg ? JSON.parse(r.cfg) : null, adaptable: !!r.adaptable,
    downloads: r.downloads, created_at: r.created_at, updated_at: r.updated_at,
    missing: missingFields(r)
  };
}

/** Validate a submitted term against the COGG-owned controlled vocabulary. */
function assertVocab(field, value) {
  if (!value) return;
  const ok = db.handle().prepare('SELECT 1 FROM vocab WHERE field = ? AND term = ?').get(field, value);
  if (!ok) throw new HttpError(422, `Níl "${value}" san fhoclóir rialaithe do ${FIELD_LABEL[field] || field}.`);
}

function snapshot(itemId, byName) {
  const h = db.handle();
  const cur = h.prepare('SELECT * FROM items WHERE id = ?').get(itemId);
  if (!cur) return;
  h.prepare('INSERT INTO revisions (item_id,snapshot,at,by_name) VALUES (?,?,?,?)')
    .run(itemId, JSON.stringify(cur), nowISO(), byName);
  // SRS §9.4 — retain the last 10 revisions per item.
  h.prepare(`DELETE FROM revisions WHERE item_id = ? AND id NOT IN
             (SELECT id FROM revisions WHERE item_id = ? ORDER BY id DESC LIMIT 10)`)
    .run(itemId, itemId);
}

/* ------------------------------------------------------------ public read */

function listItems(query, includeDrafts) {
  const h = db.handle();
  const kind = query.kind === 'activity' ? 'activity' : query.kind === 'resource' ? 'resource' : null;

  const where = [];
  const args = [];
  if (!includeDrafts) where.push("status = 'published'");
  if (kind) { where.push('kind = ?'); args.push(kind); }
  for (const f of FACET_FIELDS) {
    const raw = query[f];
    if (!raw) continue;
    const vals = Array.isArray(raw) ? raw : String(raw).split('|').filter(Boolean);
    if (!vals.length) continue;
    where.push(`${f} IN (${vals.map(() => '?').join(',')})`);
    args.push(...vals);
  }
  for (const term of terms(query.q || '')) {
    where.push('search_norm LIKE ?');
    args.push('%' + term + '%');
  }

  const sql = 'SELECT * FROM items' + (where.length ? ' WHERE ' + where.join(' AND ') : '');
  let rows = h.prepare(sql).all(...args);

  const sort = query.sort || 'az';
  if (sort === 'az') rows.sort((a, b) => compareIrish(a.title, b.title));
  else if (sort === 'za') rows.sort((a, b) => compareIrish(b.title, a.title));
  else if (sort === 'new') rows.sort((a, b) => (a.created_at < b.created_at ? 1 : -1));
  else if (sort === 'popular') rows.sort((a, b) => b.downloads - a.downloads);

  const total = rows.length;
  const page = Math.max(1, parseInt(query.page || '1', 10) || 1);
  const per = Math.min(100, Math.max(1, parseInt(query.per || '50', 10) || 50));
  const slice = rows.slice((page - 1) * per, page * per);

  return { total, page, per, items: slice.map(rowToItem) };
}

/**
 * Facet counts. Each group counts against every OTHER active filter, so the
 * numbers tell you what would happen if you ticked that box — not what is
 * already selected.
 */
function facets(query, includeDrafts) {
  const h = db.handle();
  const out = {};
  for (const field of FACET_FIELDS) {
    const sub = Object.assign({}, query);
    delete sub[field];
    delete sub.page;
    const rows = listItems(sub, includeDrafts).items;
    const counts = {};
    for (const r of rows) if (r[field]) counts[r[field]] = (counts[r[field]] || 0) + 1;
    const terms_ = h.prepare('SELECT term FROM vocab WHERE field = ?').all(field).map(r => r.term);
    out[field] = terms_
      .map(term => ({ term, count: counts[term] || 0 }))
      .filter(x => x.count > 0 || (query[field] || '').split('|').includes(x.term));
  }
  return out;
}

/* ------------------------------------------------------------------ routes */

const routes = [];
function route(method, pattern, handler, opts) {
  const keys = [];
  const rx = new RegExp('^' + pattern.replace(/:[a-zA-Z_]+/g, m => {
    keys.push(m.slice(1)); return '([^/]+)';
  }) + '$');
  routes.push({ method, rx, keys, handler, opts: opts || {} });
}

/* ---- health & meta ---- */
route('GET', '/api/health', () => ({
  ok: true, service: 'mata-api', time: nowISO(),
  items: db.count('items'), users: db.count('users'),
  pupilRecords: 0, pupilAccounts: 0
}));

route('GET', '/api/vocab', () => {
  const rows = db.handle().prepare('SELECT field, term FROM vocab').all();
  const out = {};
  for (const r of rows) (out[r.field] = out[r.field] || []).push(r.term);
  for (const k of Object.keys(out)) out[k].sort(compareIrish);
  return out;
});

/* ---- public catalogue ---- */
route('GET', '/api/items', ({ query }) => ({
  ...listItems(query, false),
  facets: facets(query, false)
}));

route('GET', '/api/items/:id', ({ params }) => {
  const r = db.handle().prepare("SELECT * FROM items WHERE id = ? AND status = 'published'").get(params.id);
  if (!r) throw new HttpError(404, 'Níor aimsíodh an acmhainn sin.');
  return rowToItem(r);
});

/** Aggregate download counting. Records a day bucket and a total — nothing else. */
route('POST', '/api/items/:id/download', ({ params }) => {
  const h = db.handle();
  const r = h.prepare("SELECT id FROM items WHERE id = ? AND status = 'published'").get(params.id);
  if (!r) throw new HttpError(404, 'Níor aimsíodh an acmhainn sin.');
  const day = nowISO().slice(0, 10);
  h.prepare('UPDATE items SET downloads = downloads + 1 WHERE id = ?').run(params.id);
  h.prepare(`INSERT INTO download_stats (item_id,day,hits) VALUES (?,?,1)
             ON CONFLICT(item_id,day) DO UPDATE SET hits = hits + 1`).run(params.id, day);
  return { ok: true };
});

/* ---- auth ---- */
route('POST', '/api/auth/login', ({ body, res }) => {
  const { token, csrf, user } = auth.login(body.email, body.password);
  res.setHeader('Set-Cookie',
    `mata_session=${token}; HttpOnly; Path=/; SameSite=Strict; Max-Age=${auth.SESSION_HOURS * 3600}`);
  return { user, csrf };
}, { public: true });

route('POST', '/api/auth/logout', ({ req, res }) => {
  auth.logout(cookie(req, 'mata_session'));
  res.setHeader('Set-Cookie', 'mata_session=; HttpOnly; Path=/; SameSite=Strict; Max-Age=0');
  return { ok: true };
}, { public: true });

route('GET', '/api/auth/me', ({ user }) => {
  if (!user) throw new HttpError(401, 'Níl tú logáilte isteach.');
  return { user: auth.publicUser(user), permissions: auth.PERMISSIONS[user.role] };
});

/* ---- admin: catalogue ---- */
route('GET', '/api/admin/items', ({ user, query }) => {
  auth.require(user, permFor(query.kind));
  return listItems(query, true);
});

route('POST', '/api/admin/items', ({ user, body }) => {
  const kind = body.kind === 'activity' ? 'activity' : 'resource';
  auth.require(user, permFor(kind));
  for (const f of FACET_FIELDS) assertVocab(f, body[f]);
  if (!body.title || !String(body.title).trim()) throw new HttpError(422, 'Teastaíonn teideal.');

  const id = (kind === 'activity' ? 'a' : 'r') + crypto.randomBytes(6).toString('hex');
  const now = nowISO();
  const item = {
    title: String(body.title).trim(), description: body.description || '',
    class_level: body.class_level || '', strand: body.strand || '',
    strand_unit: body.strand_unit || '', topic: body.topic || '',
    resource_type: body.resource_type || '', format: body.format || ''
  };
  db.handle().prepare(
    `INSERT INTO items (id,kind,title,description,class_level,strand,strand_unit,topic,
       resource_type,format,status,kit,cfg,adaptable,search_norm,created_at,updated_at,created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,'draft',?,?,?,?,?,?,?)`
  ).run(id, kind, item.title, item.description, item.class_level, item.strand,
    item.strand_unit, item.topic, item.resource_type, item.format,
    body.kit || null, body.cfg ? JSON.stringify(body.cfg) : null,
    body.adaptable ? 1 : 0, searchBlob(item), now, now, user.id);

  auth.audit(user, null, 'create', kind, id, item.title);
  return rowToItem(db.handle().prepare('SELECT * FROM items WHERE id = ?').get(id));
});

route('PUT', '/api/admin/items/:id', ({ user, params, body }) => {
  const h = db.handle();
  const cur = h.prepare('SELECT * FROM items WHERE id = ?').get(params.id);
  if (!cur) throw new HttpError(404, 'Níor aimsíodh é.');
  auth.require(user, permFor(cur.kind));
  for (const f of FACET_FIELDS) assertVocab(f, body[f]);

  snapshot(cur.id, user.name);
  const merged = Object.assign({}, cur, {
    title: body.title !== undefined ? String(body.title).trim() : cur.title,
    description: body.description !== undefined ? body.description : cur.description,
    class_level: body.class_level !== undefined ? body.class_level : cur.class_level,
    strand: body.strand !== undefined ? body.strand : cur.strand,
    strand_unit: body.strand_unit !== undefined ? body.strand_unit : cur.strand_unit,
    topic: body.topic !== undefined ? body.topic : cur.topic,
    resource_type: body.resource_type !== undefined ? body.resource_type : cur.resource_type,
    format: body.format !== undefined ? body.format : cur.format,
    featured: body.featured !== undefined ? (body.featured ? 1 : 0) : cur.featured,
    cfg: body.cfg !== undefined ? JSON.stringify(body.cfg) : cur.cfg,
    adaptable: body.adaptable !== undefined ? (body.adaptable ? 1 : 0) : cur.adaptable
  });

  h.prepare(`UPDATE items SET title=?,description=?,class_level=?,strand=?,strand_unit=?,
              topic=?,resource_type=?,format=?,featured=?,cfg=?,adaptable=?,
              search_norm=?,updated_at=? WHERE id=?`)
    .run(merged.title, merged.description, merged.class_level, merged.strand,
      merged.strand_unit, merged.topic, merged.resource_type, merged.format,
      merged.featured, merged.cfg, merged.adaptable,
      searchBlob(merged), nowISO(), cur.id);

  auth.audit(user, null, 'edit', cur.kind, cur.id, merged.title);
  return rowToItem(h.prepare('SELECT * FROM items WHERE id = ?').get(cur.id));
});

/** The metadata-completeness gate (BR-03, SRS §3.7). Enforced here, not in the UI. */
route('POST', '/api/admin/items/:id/publish', ({ user, params }) => {
  const h = db.handle();
  const cur = h.prepare('SELECT * FROM items WHERE id = ?').get(params.id);
  if (!cur) throw new HttpError(404, 'Níor aimsíodh é.');
  auth.require(user, permFor(cur.kind));

  const missing = missingFields(cur);
  if (missing.length) {
    auth.audit(user, null, 'publish_blocked', cur.kind, cur.id,
      'Réimsí ar iarraidh: ' + missing.map(f => FIELD_LABEL[f] || f).join(', '));
    throw new HttpError(422, 'Tá réimsí meiteashonraí riachtanacha ar iarraidh.', {
      missing, missingLabels: missing.map(f => FIELD_LABEL[f] || f)
    });
  }
  h.prepare("UPDATE items SET status='published', updated_at=? WHERE id=?").run(nowISO(), cur.id);
  auth.audit(user, null, 'publish', cur.kind, cur.id, cur.title);
  return rowToItem(h.prepare('SELECT * FROM items WHERE id = ?').get(cur.id));
});

route('POST', '/api/admin/items/:id/unpublish', ({ user, params }) => {
  const h = db.handle();
  const cur = h.prepare('SELECT * FROM items WHERE id = ?').get(params.id);
  if (!cur) throw new HttpError(404, 'Níor aimsíodh é.');
  auth.require(user, permFor(cur.kind));
  h.prepare("UPDATE items SET status='draft', updated_at=? WHERE id=?").run(nowISO(), cur.id);
  auth.audit(user, null, 'unpublish', cur.kind, cur.id, cur.title);
  return rowToItem(h.prepare('SELECT * FROM items WHERE id = ?').get(cur.id));
});

route('DELETE', '/api/admin/items/:id', ({ user, params }) => {
  const h = db.handle();
  const cur = h.prepare('SELECT * FROM items WHERE id = ?').get(params.id);
  if (!cur) throw new HttpError(404, 'Níor aimsíodh é.');
  auth.require(user, permFor(cur.kind));
  h.prepare('DELETE FROM items WHERE id = ?').run(cur.id);
  auth.audit(user, null, 'delete', cur.kind, cur.id, cur.title);
  return { ok: true };
});

route('GET', '/api/admin/items/:id/revisions', ({ user, params }) => {
  const h = db.handle();
  const cur = h.prepare('SELECT * FROM items WHERE id = ?').get(params.id);
  if (!cur) throw new HttpError(404, 'Níor aimsíodh é.');
  auth.require(user, permFor(cur.kind));
  return h.prepare('SELECT id, at, by_name FROM revisions WHERE item_id = ? ORDER BY id DESC')
    .all(cur.id);
});

route('POST', '/api/admin/items/:id/revert/:rev', ({ user, params }) => {
  const h = db.handle();
  const cur = h.prepare('SELECT * FROM items WHERE id = ?').get(params.id);
  if (!cur) throw new HttpError(404, 'Níor aimsíodh é.');
  auth.require(user, permFor(cur.kind));
  const rev = h.prepare('SELECT * FROM revisions WHERE id = ? AND item_id = ?')
    .get(Number(params.rev), cur.id);
  if (!rev) throw new HttpError(404, 'Níor aimsíodh an leagan sin.');

  snapshot(cur.id, user.name);
  const snap = JSON.parse(rev.snapshot);
  h.prepare(`UPDATE items SET title=?,description=?,class_level=?,strand=?,strand_unit=?,
              topic=?,resource_type=?,format=?,cfg=?,adaptable=?,search_norm=?,updated_at=?
             WHERE id=?`)
    .run(snap.title, snap.description, snap.class_level, snap.strand, snap.strand_unit,
      snap.topic, snap.resource_type, snap.format, snap.cfg, snap.adaptable,
      searchBlob(snap), nowISO(), cur.id);
  auth.audit(user, null, 'revert', cur.kind, cur.id, `Cuireadh ar ais go leagan #${rev.id}`);
  return rowToItem(h.prepare('SELECT * FROM items WHERE id = ?').get(cur.id));
});

/* ---- admin: taxonomy ---- */
route('POST', '/api/admin/vocab', ({ user, body }) => {
  auth.require(user, 'taxonomy');
  const field = String(body.field || '');
  const term = String(body.term || '').trim();
  if (!FACET_FIELDS.includes(field)) throw new HttpError(422, 'Réimse anaithnid.');
  if (!term) throw new HttpError(422, 'Teastaíonn téarma.');
  const exists = db.handle().prepare('SELECT 1 FROM vocab WHERE field=? AND term=?').get(field, term);
  if (exists) throw new HttpError(409, 'Tá an téarma sin ann cheana.');
  db.handle().prepare('INSERT INTO vocab (field,term) VALUES (?,?)').run(field, term);
  auth.audit(user, null, 'taxonomy_add', 'vocab', field, term);
  return { ok: true, field, term };
});

route('DELETE', '/api/admin/vocab', ({ user, query }) => {
  auth.require(user, 'taxonomy');
  const field = String(query.field || '');
  const term = String(query.term || '');
  if (!FACET_FIELDS.includes(field)) throw new HttpError(422, 'Réimse anaithnid.');
  const inUse = db.handle().prepare(`SELECT COUNT(*) c FROM items WHERE ${field} = ?`).get(term).c;
  if (inUse > 0) {
    throw new HttpError(409, `Tá an téarma in úsáid ag ${inUse} mír — ní féidir é a bhaint.`, { inUse });
  }
  db.handle().prepare('DELETE FROM vocab WHERE field=? AND term=?').run(field, term);
  auth.audit(user, null, 'taxonomy_remove', 'vocab', field, term);
  return { ok: true };
});

/* ---- admin: users, audit, stats, backup ---- */
route('GET', '/api/admin/users', ({ user }) => {
  auth.require(user, 'users');
  return db.handle().prepare('SELECT id,name,email,role,mfa_enabled,created_at FROM users').all()
    .map(u => ({ ...u, mfa_enabled: !!u.mfa_enabled }));
});

route('GET', '/api/admin/audit', ({ user, query }) => {
  if (!user) throw new HttpError(401, 'Teastaíonn logáil isteach.');
  const limit = Math.min(500, Math.max(1, parseInt(query.limit || '100', 10) || 100));
  return db.handle().prepare('SELECT * FROM audit ORDER BY id DESC LIMIT ?').all(limit);
});

route('GET', '/api/admin/stats', ({ user }) => {
  if (!user) throw new HttpError(401, 'Teastaíonn logáil isteach.');
  const h = db.handle();
  return {
    resources: h.prepare("SELECT COUNT(*) c FROM items WHERE kind='resource'").get().c,
    activities: h.prepare("SELECT COUNT(*) c FROM items WHERE kind='activity'").get().c,
    published: h.prepare("SELECT COUNT(*) c FROM items WHERE status='published'").get().c,
    drafts: h.prepare("SELECT COUNT(*) c FROM items WHERE status='draft'").get().c,
    downloads: h.prepare('SELECT COALESCE(SUM(downloads),0) c FROM items').get().c,
    users: db.count('users'),
    pupilAccounts: 0,
    pupilDataRecords: 0
  };
});

/** SRS §11.2 / §17.3 — an operator-triggered logical backup of the whole store. */
route('GET', '/api/admin/backup', ({ user }) => {
  auth.require(user, 'backup');
  const h = db.handle();
  auth.audit(user, null, 'backup', 'system', null, 'Easpórtáil iomlán');
  return {
    generated_at: nowISO(),
    schema_version: h.prepare('SELECT version FROM schema_version').get().version,
    vocab: h.prepare('SELECT field,term FROM vocab').all(),
    items: h.prepare('SELECT * FROM items').all(),
    revisions: h.prepare('SELECT * FROM revisions').all(),
    audit: h.prepare('SELECT * FROM audit').all(),
    users: h.prepare('SELECT id,name,email,role,mfa_enabled,created_at FROM users').all()
  };
});

/* ---- helpers exported to the http layer ---- */
function cookie(req, name) {
  const raw = req.headers.cookie || '';
  for (const part of raw.split(';')) {
    const [k, ...v] = part.trim().split('=');
    if (k === name) return decodeURIComponent(v.join('='));
  }
  return null;
}

module.exports = {
  routes, cookie, REQUIRED, FIELD_LABEL, FACET_FIELDS,
  missingFields, listItems, facets, MAX_PDF_BYTES, snapshot
};
