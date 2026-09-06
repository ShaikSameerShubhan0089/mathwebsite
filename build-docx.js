/**
 * dossier.html -> dossier-word.html, a flattened page pandoc can turn into .docx.
 *
 * The web dossier leans on CSS for its whole structure: sticky clause rails,
 * plate-backed figures, coloured callouts. None of that survives a Word
 * conversion, and pandoc will faithfully reproduce the empty <div>s as Divs
 * that Word renders as stray paragraphs. So this strips the presentation layer
 * and re-expresses each construct as something Word has a real equivalent for:
 *
 *   figure + .plate        -> <img src="figs/fig-N.png"> + italic caption
 *   .rail clause list      -> one italic reference line above the section
 *   .note callout          -> <blockquote>
 *   everything else's divs -> removed entirely (open and close, so it balances)
 *
 * Figure numbering walks the document top to bottom, which is the same order
 * build-figs.js writes the harnesses in — the two must not drift apart.
 */
'use strict';
const fs = require('fs');

let s = fs.readFileSync('dossier.html', 'utf8');

// ---- 1. drop everything that is purely presentational ----------------------
s = s.replace(/<style>[\s\S]*?<\/style>/g, '');
s = s.replace(/<link\b[^>]*>/g, '');
s = s.replace(/<nav class="toc"[\s\S]*?<\/nav>/g, '');   // pandoc builds its own

// ---- 2. figures become images ----------------------------------------------
let figN = 0;
const missing = [];
s = s.replace(/<figure>([\s\S]*?)<\/figure>/g, (whole, inner) => {
  figN++;
  const path = 'figs/fig-' + figN + '.png';
  if (!fs.existsSync(path)) missing.push(path);

  const cap = inner.match(/<figcaption>([\s\S]*?)<\/figcaption>/);
  const caption = cap
    ? cap[1].replace(/\s+/g, ' ').trim()
    : '';

  return '<p><img src="' + path + '" alt="Figure ' + figN + '" /></p>' +
         (caption ? '<p><em>' + caption + '</em></p>' : '');
});

// ---- 3. the clause rail becomes one reference line --------------------------
let rails = 0;
s = s.replace(/<div class="rail">([\s\S]*?)<\/div>/g, (whole, inner) => {
  rails++;
  const clause = inner
    .replace(/<span class="num">[\s\S]*?<\/span>/, '')
    .replace(/<br\s*\/?>/g, ' · ')
    .replace(/<[^>]+>/g, '')
    .replace(/\s+/g, ' ')
    .trim();
  return clause ? '<p><em>' + clause + '</em></p>' : '';
});

// ---- 4. callouts become blockquotes ----------------------------------------
let notes = 0;
s = s.replace(/<div class="note[^"]*">([\s\S]*?)<\/div>/g, (whole, inner) => {
  notes++;
  const body = inner.replace(
    /<span class="tag">([\s\S]*?)<\/span>/,
    (m, tag) => '<p><strong>' + tag.replace(/\s+/g, ' ').trim() + '</strong></p>'
  );
  return '<blockquote>' + body + '</blockquote>';
});

// ---- 5. remove the remaining layout wrappers --------------------------------
// Both the opening and closing tag go, so nesting stays balanced.
s = s.replace(/<\/?(?:div|section|header|footer|nav|main)\b[^>]*>/g, '');

// ---- 6. give Word a document title -----------------------------------------
s = s.replace(/<title>[\s\S]*?<\/title>/, '');

const out = 'dossier-word.html';
fs.writeFileSync(out, s, 'utf8');

console.log(out + ' written');
console.log('  figures referenced : ' + figN);
console.log('  clause rails       : ' + rails);
console.log('  callouts           : ' + notes);
console.log('  tables             : ' + (s.match(/<table>/g) || []).length);
console.log('  size               : ' + Math.round(s.length / 1024) + ' KB');
if (missing.length) {
  console.error('  MISSING IMAGES     : ' + missing.join(', '));
  process.exit(1);
}
