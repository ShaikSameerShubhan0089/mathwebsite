'use strict';
/**
 * Authentication and role-based access control.
 * SRS §11.1, §3.8, §4.2 — and specifically the validation rule that reads:
 *   "Permission checks are enforced server-side for every content action,
 *    not merely hidden in the UI."
 * `require()` below is the only gate that matters; the browser hiding a menu
 * item is a courtesy, not a control.
 */
const crypto = require('node:crypto');
const db = require('./db');

const SESSION_HOURS = 8;
const MAX_FAILED = 5;
const LOCK_MINUTES = 15;

/** SRS §4.2 permissions matrix, expressed once, server-side. */
const PERMISSIONS = {
  editor: { resources: true, activities: false, taxonomy: false, users: false, backup: false },
  author: { resources: false, activities: true, taxonomy: false, users: false, backup: false },
  admin: { resources: true, activities: true, taxonomy: true, users: true, backup: true }
};

class HttpError extends Error {
  constructor(status, message, extra) {
    super(message);
    this.status = status;
    if (extra) Object.assign(this, extra);
  }
}

function now() { return new Date().toISOString(); }
function plus(ms) { return new Date(Date.now() + ms).toISOString(); }

function login(email, password) {
  const h = db.handle();
  const user = h.prepare('SELECT * FROM users WHERE email = ?').get(String(email || '').toLowerCase().trim());

  // Uniform failure message: never reveal whether the address exists.
  const generic = () => new HttpError(401, 'Ríomhphost nó focal faire mícheart.');
  if (!user) throw generic();

  if (user.locked_until && user.locked_until > now()) {
    throw new HttpError(423, 'Tá an cuntas faoi ghlas go sealadach tar éis iarrachtaí teipthe.', {
      lockedUntil: user.locked_until
    });
  }

  if (!db.verifyPassword(String(password || ''), user.pass_hash, user.pass_salt)) {
    const failed = user.failed_count + 1;
    const lock = failed >= MAX_FAILED ? plus(LOCK_MINUTES * 60000) : null;
    h.prepare('UPDATE users SET failed_count = ?, locked_until = ? WHERE id = ?')
      .run(failed, lock, user.id);
    audit(null, 'anon', 'login_failed', 'user', user.id,
      `Iarracht theipthe ${failed}/${MAX_FAILED}` + (lock ? ' — cuntas faoi ghlas' : ''));
    if (lock) throw new HttpError(423, 'Cuireadh an cuntas faoi ghlas tar éis iarrachtaí teipthe.', { lockedUntil: lock });
    throw generic();
  }

  h.prepare('UPDATE users SET failed_count = 0, locked_until = NULL WHERE id = ?').run(user.id);

  const token = crypto.randomBytes(32).toString('hex');
  const csrf = crypto.randomBytes(24).toString('hex');
  h.prepare('INSERT INTO sessions (token,user_id,csrf,created_at,expires_at) VALUES (?,?,?,?,?)')
    .run(token, user.id, csrf, now(), plus(SESSION_HOURS * 3600000));

  audit(user, null, 'login', 'user', user.id, 'Logáil isteach');
  return { token, csrf, user: publicUser(user) };
}

function logout(token) {
  if (!token) return;
  const u = sessionUser(token);
  db.handle().prepare('DELETE FROM sessions WHERE token = ?').run(token);
  if (u) audit(u, null, 'logout', 'user', u.id, 'Logáil amach');
}

/** Resolve a session token to a live user, sweeping expired rows as we go. */
function sessionUser(token) {
  if (!token) return null;
  const h = db.handle();
  h.prepare('DELETE FROM sessions WHERE expires_at < ?').run(now());
  const row = h.prepare(
    `SELECT u.*, s.csrf FROM sessions s JOIN users u ON u.id = s.user_id
     WHERE s.token = ? AND s.expires_at > ?`).get(token, now());
  return row || null;
}

function publicUser(u) {
  return { id: u.id, name: u.name, email: u.email, role: u.role, mfa: !!u.mfa_enabled };
}

function can(user, permission) {
  if (!user) return false;
  const set = PERMISSIONS[user.role];
  return !!(set && set[permission]);
}

/** Throw unless the user holds the permission. Every mutating route calls this. */
function require_(user, permission) {
  if (!user) throw new HttpError(401, 'Teastaíonn logáil isteach.');
  if (!can(user, permission)) {
    audit(user, null, 'denied', 'permission', permission, `Diúltaíodh rochtain ar ${permission}`);
    throw new HttpError(403, 'Níl cead ag do ról an gníomh seo a dhéanamh.');
  }
}

function audit(user, fallbackName, action, entity, entityId, detail) {
  db.handle().prepare(
    `INSERT INTO audit (at,user_id,user_name,action,entity,entity_id,detail)
     VALUES (?,?,?,?,?,?,?)`
  ).run(now(), user ? user.id : null, user ? user.name : (fallbackName || 'anon'),
    action, entity || null, entityId || null, detail || '');
}

module.exports = {
  login, logout, sessionUser, publicUser, can,
  require: require_, audit, HttpError, PERMISSIONS,
  MAX_FAILED, LOCK_MINUTES, SESSION_HOURS
};
