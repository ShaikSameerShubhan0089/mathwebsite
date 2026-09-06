'use strict';
/**
 * Database layer — SQLite via node:sqlite (Node >= 22, zero npm dependencies).
 *
 * Data model per SRS §9.1. Note what is NOT here: there is no pupil table, no
 * teacher table, no session-per-learner, no progress or assignment table, and no
 * column anywhere capable of holding a pupil identifier. BR-01 and BR-05 are
 * enforced by the shape of the schema, not by a policy document.
 *
 * Resources and digital activities share one `items` table with a `kind`
 * discriminator so they carry the same metadata and land in the same search
 * index (SRS §3.10, §16.1).
 */
const { DatabaseSync } = require('node:sqlite');
const crypto = require('node:crypto');
const path = require('node:path');
const fs = require('node:fs');
const { searchBlob } = require('./search');

const DATA_DIR = process.env.MATA_DATA || path.join(__dirname, '..', 'data');
const UPLOAD_DIR = path.join(DATA_DIR, 'uploads');

let db = null;

function open(file) {
  fs.mkdirSync(DATA_DIR, { recursive: true });
  fs.mkdirSync(UPLOAD_DIR, { recursive: true });
  const target = file || path.join(DATA_DIR, 'mata.db');
  db = new DatabaseSync(target);
  db.exec('PRAGMA journal_mode = WAL');
  db.exec('PRAGMA foreign_keys = ON');
  migrate();
  if (count('users') === 0) seed();
  return db;
}
function handle() {
  if (!db) throw new Error('database not opened');
  return db;
}
function close() { if (db) { db.close(); db = null; } }
function count(table) {
  return handle().prepare(`SELECT COUNT(*) AS c FROM ${table}`).get().c;
}

/* ------------------------------------------------------------------ schema */
function migrate() {
  db.exec(`
    CREATE TABLE IF NOT EXISTS schema_version (version INTEGER NOT NULL);

    -- COGG staff only. The single place personal data exists (SRS §13.1).
    CREATE TABLE IF NOT EXISTS users (
      id            TEXT PRIMARY KEY,
      name          TEXT NOT NULL,
      email         TEXT NOT NULL UNIQUE,
      role          TEXT NOT NULL CHECK (role IN ('editor','author','admin')),
      pass_hash     TEXT NOT NULL,
      pass_salt     TEXT NOT NULL,
      mfa_enabled   INTEGER NOT NULL DEFAULT 0,
      failed_count  INTEGER NOT NULL DEFAULT 0,
      locked_until  TEXT,
      created_at    TEXT NOT NULL
    );

    CREATE TABLE IF NOT EXISTS sessions (
      token      TEXT PRIMARY KEY,
      user_id    TEXT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
      csrf       TEXT NOT NULL,
      created_at TEXT NOT NULL,
      expires_at TEXT NOT NULL
    );

    -- Controlled vocabularies, owned and editable by COGG (SRS §3.7, BR-03).
    CREATE TABLE IF NOT EXISTS vocab (
      id    INTEGER PRIMARY KEY AUTOINCREMENT,
      field TEXT NOT NULL,
      term  TEXT NOT NULL,
      UNIQUE (field, term)
    );

    CREATE TABLE IF NOT EXISTS items (
      id            TEXT PRIMARY KEY,
      kind          TEXT NOT NULL CHECK (kind IN ('resource','activity')),
      title         TEXT NOT NULL,
      description   TEXT NOT NULL DEFAULT '',
      class_level   TEXT NOT NULL DEFAULT '',
      strand        TEXT NOT NULL DEFAULT '',
      strand_unit   TEXT NOT NULL DEFAULT '',
      topic         TEXT NOT NULL DEFAULT '',
      resource_type TEXT NOT NULL DEFAULT '',
      format        TEXT NOT NULL DEFAULT '',
      status        TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','published')),
      featured      INTEGER NOT NULL DEFAULT 0,
      file_name     TEXT,
      file_size     INTEGER,
      kit           TEXT,
      cfg           TEXT,
      adaptable     INTEGER NOT NULL DEFAULT 0,
      downloads     INTEGER NOT NULL DEFAULT 0,
      search_norm   TEXT NOT NULL DEFAULT '',
      created_at    TEXT NOT NULL,
      updated_at    TEXT NOT NULL,
      created_by    TEXT
    );
    CREATE INDEX IF NOT EXISTS idx_items_status ON items(status, kind);
    CREATE INDEX IF NOT EXISTS idx_items_search ON items(search_norm);

    -- Revision history for rollback (SRS §9.4: last 10 retained per item).
    CREATE TABLE IF NOT EXISTS revisions (
      id       INTEGER PRIMARY KEY AUTOINCREMENT,
      item_id  TEXT NOT NULL REFERENCES items(id) ON DELETE CASCADE,
      snapshot TEXT NOT NULL,
      at       TEXT NOT NULL,
      by_name  TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_rev_item ON revisions(item_id, id DESC);

    -- Editorial accountability (SRS §14.5). Actor + timestamp on every action.
    CREATE TABLE IF NOT EXISTS audit (
      id        INTEGER PRIMARY KEY AUTOINCREMENT,
      at        TEXT NOT NULL,
      user_id   TEXT,
      user_name TEXT NOT NULL,
      action    TEXT NOT NULL,
      entity    TEXT,
      entity_id TEXT,
      detail    TEXT NOT NULL DEFAULT ''
    );
    CREATE INDEX IF NOT EXISTS idx_audit_at ON audit(id DESC);

    -- Aggregate download counts only. No visitor identity, no IP, no profile.
    CREATE TABLE IF NOT EXISTS download_stats (
      item_id TEXT NOT NULL REFERENCES items(id) ON DELETE CASCADE,
      day     TEXT NOT NULL,
      hits    INTEGER NOT NULL DEFAULT 0,
      PRIMARY KEY (item_id, day)
    );
  `);
  const row = db.prepare('SELECT version FROM schema_version').get();
  if (!row) db.prepare('INSERT INTO schema_version (version) VALUES (1)').run();
}

/* ------------------------------------------------------------- passwords */
function hashPassword(plain, salt) {
  const s = salt || crypto.randomBytes(16).toString('hex');
  const h = crypto.scryptSync(plain, s, 64).toString('hex');
  return { hash: h, salt: s };
}
function verifyPassword(plain, hash, salt) {
  const attempt = Buffer.from(crypto.scryptSync(plain, salt, 64).toString('hex'));
  const known = Buffer.from(hash);
  return attempt.length === known.length && crypto.timingSafeEqual(attempt, known);
}

/* ------------------------------------------------------------------ seed */
const VOCAB = {
  class_level: ['Naíonáin Bheaga', 'Naíonáin Mhóra', 'Rang 1', 'Rang 2', 'Rang 3', 'Rang 4', 'Rang 5', 'Rang 6'],
  strand: ['Uimhir', 'Ailgéabar', 'Cruth agus Spás', 'Tomhas', 'Sonraí agus Seans'],
  strand_unit: ['Luach ionaid', 'Suimiú agus dealú', 'Iolrú agus roinnt', 'Codáin', 'Patrúin',
    'Cruthanna 2T', 'Cruthanna 3T', 'Fad', 'Achar', 'Am', 'Airgead', 'Léaráidí'],
  topic: ['Luach ionaid', 'Codáin', 'Táblaí', 'Siméadracht', 'Tomhas faid', 'Am', 'Airgead', 'Patrúin', 'Sonraí'],
  resource_type: ['Plean ceachta', 'Tasc ranga', 'Treoir mhúinteora', 'Bileog oibre', 'Nasc seachtrach'],
  format: ['PDF', 'Idirghníomhach', 'Treoir mhúinteora']
};

const RESOURCES = [
  ['Luach ionaid go 99 — plean ceachta', 'Ceacht iomlán le gníomhaíochtaí praiticiúla ar aonaid agus deicheanna, le hábhair inphriontáilte.', 'Rang 1', 'Uimhir', 'Luach ionaid', 'Luach ionaid', 'Plean ceachta', 'PDF', 'published', '2025-09-12'],
  ['Codáin aonaid — tascanna ranga', 'Sraith tascanna chun codáin aonaid a aithint agus a chur i gcomparáid le chéile.', 'Rang 3', 'Uimhir', 'Codáin', 'Codáin', 'Tasc ranga', 'PDF', 'published', '2025-10-02'],
  ['Táblaí iolraithe 2, 5 agus 10', 'Bileoga oibre agus cluichí gearra chun na táblaí bunúsacha a dhaingniú.', 'Rang 2', 'Uimhir', 'Iolrú agus roinnt', 'Táblaí', 'Bileog oibre', 'PDF', 'published', '2025-08-21'],
  ['Siméadracht i gcruthanna 2T', 'Gníomhaíochtaí fillte agus scátháin chun aiseanna siméadrachta a fháil amach.', 'Rang 4', 'Cruth agus Spás', 'Cruthanna 2T', 'Siméadracht', 'Plean ceachta', 'PDF', 'published', '2025-11-05'],
  ['Tomhas faid le haonaid neamhchaighdeánacha', 'Ceacht tosaigh ar thomhas ag baint úsáide as spanla, coiscéimeanna agus slata.', 'Naíonáin Mhóra', 'Tomhas', 'Fad', 'Tomhas faid', 'Plean ceachta', 'PDF', 'published', '2025-09-30'],
  ['An t-am — an leathuair a léamh', 'Bileoga oibre le clog analógach agus digiteach, le treoir don mhúinteoir.', 'Rang 2', 'Tomhas', 'Am', 'Am', 'Bileog oibre', 'PDF', 'published', '2025-10-18'],
  ['Airgead — sóinseáil go €2', 'Tascanna praiticiúla siopadóireachta le buntáiste don obair bheirte.', 'Rang 3', 'Tomhas', 'Airgead', 'Airgead', 'Tasc ranga', 'PDF', 'published', '2025-07-14'],
  ['Patrúin agus seichimh a leanúint', 'Réamhobair ailgéabair: patrúin datha, cruth agus uimhreach a aithint agus a leanúint.', 'Rang 1', 'Ailgéabar', 'Patrúin', 'Patrúin', 'Plean ceachta', 'PDF', 'published', '2025-06-09'],
  ['Léaráidí barra a léamh agus a chruthú', 'Sonraí a bhailiú ón rang agus léaráid bharra a tharraingt le chéile.', 'Rang 4', 'Sonraí agus Seans', 'Léaráidí', 'Sonraí', 'Tasc ranga', 'PDF', 'published', '2025-11-22'],
  ['Cruthanna 3T a aithint sa seomra ranga', 'Sealgaireacht chruthanna le liosta seiceála inphriontáilte.', 'Naíonáin Bheaga', 'Cruth agus Spás', 'Cruthanna 3T', 'Siméadracht', 'Tasc ranga', 'PDF', 'published', '2025-05-28'],
  ['Treoir mhúinteora — teanga na matamaitice', 'Nótaí don mhúinteoir ar théarmaíocht chomhsheasmhach Ghaeilge ar fud na scoile.', 'Rang 5', 'Uimhir', 'Luach ionaid', 'Luach ionaid', 'Treoir mhúinteora', 'Treoir mhúinteora', 'published', '2025-12-01'],
  ['Achar dronuilleoga a ríomh', 'Ó chomhaireamh cearnóg go dtí an fhoirmle — céimnithe thar thrí cheacht.', 'Rang 5', 'Tomhas', 'Achar', 'Tomhas faid', 'Plean ceachta', 'PDF', 'published', '2026-01-15'],
  ['Codáin choibhéiseacha — bileoga oibre', 'Trí leibhéal deacrachta chun freastal ar an rang ar fad.', 'Rang 5', 'Uimhir', 'Codáin', 'Codáin', 'Bileog oibre', 'PDF', 'published', '2026-02-03'],
  ['Suimiú le hathghrúpáil go 999', 'Modheolaíocht chéim ar chéim le hábhair choincréiteacha.', 'Rang 3', 'Uimhir', 'Suimiú agus dealú', 'Luach ionaid', 'Plean ceachta', 'PDF', 'published', '2026-03-11'],
  ['Dul chun cinn i dtáblaí — dréacht', 'Doiciméad dréachta nach bhfuil foilsithe fós; níl an mheiteashonraí iomlán.', 'Rang 4', 'Uimhir', 'Iolrú agus roinnt', '', 'Treoir mhúinteora', '', 'draft', '2026-04-02']
];

const ACTIVITIES = [
  ['Líne uimhreach go 20', 'Cuir uimhreacha in iúl ar líne uimhreach. Oiriúnach do rang iomlán ar an gclár bán.', 'Rang 1', 'Uimhir', 'Luach ionaid', 'Luach ionaid', 'Tasc ranga', 'Idirghníomhach', 'published', '2026-01-20', 'linear', { min: 0, max: 20, step: 1, marks: 4, labels: true }, 1],
  ['Luach ionaid — trí cholún', 'Tóg uimhreacha le céadta, deicheanna agus aonaid.', 'Rang 3', 'Uimhir', 'Luach ionaid', 'Luach ionaid', 'Tasc ranga', 'Idirghníomhach', 'published', '2026-02-08', 'pv', { cols: 3, count: 6 }, 1],
  ['Codáin a dhathú', 'Dathaigh an codán a iarrtar ort — barra nó ciorcal.', 'Rang 3', 'Uimhir', 'Codáin', 'Codáin', 'Tasc ranga', 'Idirghníomhach', 'published', '2026-02-19', 'frac', { shape: 'barra', dmin: 2, dmax: 6, count: 6, mode: 'dath' }, 1],
  ['Táblaí 2, 5, 10', 'Cleachtadh tapa ar na táblaí bunúsacha.', 'Rang 2', 'Uimhir', 'Iolrú agus roinnt', 'Táblaí', 'Tasc ranga', 'Idirghníomhach', 'published', '2026-03-01', 'tab', { tables: [2, 5, 10], op: '×', count: 10 }, 1],
  ['Codáin a ainmniú (measúnú)', 'Leagan measúnaithe — tá na socruithe faoi ghlas d\'fhonn comhionannas a chinntiú.', 'Rang 4', 'Uimhir', 'Codáin', 'Codáin', 'Tasc ranga', 'Idirghníomhach', 'published', '2026-03-18', 'frac', { shape: 'ciorcal', dmin: 3, dmax: 8, count: 8, mode: 'ainm' }, 0],
  ['Líne uimhreach — deichiúlacha', 'Líne uimhreach ó 0 go 5 i gcéimeanna 0.5.', 'Rang 5', 'Uimhir', 'Codáin', 'Codáin', 'Tasc ranga', 'Idirghníomhach', 'published', '2026-04-05', 'linear', { min: 0, max: 5, step: 0.5, marks: 5, labels: true }, 1]
];

const DEMO_USERS = [
  ['u1', 'Síle Ní Mhurchú', 'sile@cogg.ie', 'editor', 0],
  ['u2', 'Dara Ó Briain', 'dara@cogg.ie', 'author', 0],
  ['u3', 'Aoife Nic Gearailt', 'aoife@cogg.ie', 'admin', 1]
];

function seed() {
  const now = new Date().toISOString();
  const pass = process.env.MATA_SEED_PASSWORD || 'cogg2026';

  const insUser = db.prepare(
    `INSERT INTO users (id,name,email,role,pass_hash,pass_salt,mfa_enabled,created_at)
     VALUES (?,?,?,?,?,?,?,?)`);
  for (const [id, name, email, role, mfa] of DEMO_USERS) {
    const { hash, salt } = hashPassword(pass);
    insUser.run(id, name, email, role, hash, salt, mfa, now);
  }

  const insVocab = db.prepare('INSERT OR IGNORE INTO vocab (field,term) VALUES (?,?)');
  for (const field of Object.keys(VOCAB)) for (const term of VOCAB[field]) insVocab.run(field, term);

  const insItem = db.prepare(
    `INSERT INTO items (id,kind,title,description,class_level,strand,strand_unit,topic,
       resource_type,format,status,featured,kit,cfg,adaptable,downloads,search_norm,
       created_at,updated_at,created_by)
     VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`);

  RESOURCES.forEach((r, i) => {
    const [title, description, class_level, strand, strand_unit, topic, resource_type, format, status, created] = r;
    const item = { title, description, class_level, strand, strand_unit, topic, resource_type, format };
    insItem.run(`r${i + 1}`, 'resource', title, description, class_level, strand, strand_unit,
      topic, resource_type, format, status, i < 2 ? 1 : 0, null, null, 0,
      10 + ((i * 37) % 170), searchBlob(item), created, created, 'u3');
  });

  ACTIVITIES.forEach((a, i) => {
    const [title, description, class_level, strand, strand_unit, topic, resource_type, format, status, created, kit, cfg, adaptable] = a;
    const item = { title, description, class_level, strand, strand_unit, topic, resource_type, format };
    insItem.run(`a${i + 1}`, 'activity', title, description, class_level, strand, strand_unit,
      topic, resource_type, format, status, 0, kit, JSON.stringify(cfg), adaptable, 0,
      searchBlob(item), created, created, 'u2');
  });

  db.prepare(`INSERT INTO audit (at,user_id,user_name,action,entity,detail)
              VALUES (?,?,?,?,?,?)`)
    .run(now, 'u3', 'Aoife Nic Gearailt', 'seed', 'system', 'Cuireadh tús leis an gcóras');
}

module.exports = {
  open, close, handle, count,
  hashPassword, verifyPassword,
  DATA_DIR, UPLOAD_DIR, VOCAB
};
