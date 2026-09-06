'use strict';
/**
 * Irish-language text handling.
 * RFT §13 / SRS §6.4, §12.6 — fadas must display, store, sort and search correctly.
 *
 * Two distinct problems, two distinct solutions:
 *   1. SEARCH must be diacritic-insensitive in BOTH directions, so a teacher typing
 *      "codain" finds "Codáin" and a teacher typing "Codáin" finds it too. We do that
 *      by storing a normalised shadow column and querying against it.
 *   2. SORT must follow Irish alphabetical order, which is NOT byte order. SQLite has
 *      no `ga` collation, so ordering is applied in JS with Intl.Collator('ga').
 *      At the scale this library will ever reach (low thousands of rows) that is the
 *      correct trade — see SRS §8.4 on not introducing a search server.
 */

/** Strip combining marks and case-fold. "Codáin" -> "codain" */
function normalise(s) {
  return String(s == null ? '' : s)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .trim();
}

/** Build the haystack persisted in items.search_norm on every write. */
function searchBlob(item) {
  return normalise([
    item.title, item.description, item.class_level, item.strand,
    item.strand_unit, item.topic, item.resource_type, item.format
  ].filter(Boolean).join(' '));
}

let collator;
try {
  collator = new Intl.Collator('ga', { sensitivity: 'variant', numeric: true });
} catch (_) {
  collator = { compare: (a, b) => (normalise(a) < normalise(b) ? -1 : normalise(a) > normalise(b) ? 1 : 0) };
}

/** Irish alphabetical comparison. */
function compareIrish(a, b) {
  return collator.compare(String(a || ''), String(b || ''));
}

/** Split a query into normalised terms; every term must match (AND). */
function terms(q) {
  return normalise(q).split(/\s+/).filter(Boolean);
}

module.exports = { normalise, searchBlob, compareIrish, terms };
