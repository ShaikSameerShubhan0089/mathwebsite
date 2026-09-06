/**
 * Rebuild rend/figN.html — one render harness per figure in dossier.html.
 *
 * The dossier itself ships no mermaid runtime: the artifact host renders
 * <pre class="mermaid"> natively. To rasterise the same diagrams for the Word
 * document we need a page that does load mermaid, so each figure is lifted out
 * of its <figure> and dropped into a standalone harness carrying the dossier's
 * own fonts and plate colours.
 *
 * Figure order here IS the order build-docx.js expects — both walk the document
 * top to bottom, one figure at a time.
 */
'use strict';
const fs = require('fs');

const MERMAID = (process.env.APPDATA + '/npm/node_modules/@mermaid-js/mermaid-cli/node_modules/mermaid/dist/mermaid.min.js')
  .split('\\').join('/');
if (!fs.existsSync(MERMAID)) {
  console.error('mermaid bundle not found at ' + MERMAID);
  process.exit(1);
}

const doc = fs.readFileSync('dossier.html', 'utf8');

// Pull the inner HTML of every .plate, in document order. Plates never nest.
const plates = [];
const re = /<div class="plate">([\s\S]*?)\n      <\/div>/g;
let m;
while ((m = re.exec(doc)) !== null) plates.push(m[1]);
if (!plates.length) { console.error('no plates matched'); process.exit(1); }

fs.rmSync('rend', { recursive: true, force: true });
fs.mkdirSync('rend');

const head = `<!doctype html><html><head><meta charset="utf-8">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Literata:ital,opsz,wght@0,7..72,400;0,7..72,500;0,7..72,600;0,7..72,700;1,7..72,400&family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;1,400&family=IBM+Plex+Mono:wght@400;500&display=swap">
<style>
  html,body{margin:0;padding:0;background:#FBFCFC}
  body{font-family:"Public Sans",system-ui,sans-serif;color:#0B2B33}
  #slot{width:1258px;background:#FBFCFC;padding:26px 22px;box-sizing:border-box}
  #slot .mermaid{color:#0B2B33;display:flex;justify-content:center}
  /*
   * Mermaid sizes each label by laying it out in a throwaway container that is
   * attached to the document OUTSIDE our #slot wrapper, then draws the real one
   * inside it. A font override scoped to #slot therefore reached only the paint
   * and not the measure, and every box came out ~13% too narrow. These rules are
   * deliberately unscoped and keyed to mermaid's own label classes, so measure
   * and paint resolve to the same face — and the hand-authored svg.dia figures,
   * which never use these classes, keep their own IBM Plex Mono runs.
   */
  .label, .nodeLabel, .edgeLabel, .cluster-label, .titleText, .actor, .messageText,
  .label *, .nodeLabel *, .edgeLabel *, .cluster-label *,
  foreignObject div, foreignObject span, foreignObject p{
    font-family:"Public Sans",system-ui,sans-serif !important;
  }
  :root{--mermaid-font-family:"Public Sans",system-ui,sans-serif !important}
  /* A few px of residual measure/paint drift remains in mermaid's own padding
     maths. foreignObject clips by default, which turns a 4px shortfall into a
     truncated word; letting it overflow renders the label in full instead. */
  #slot .mermaid foreignObject{overflow:visible}
  #slot svg{max-width:100%;height:auto}
  svg.dia{max-width:100%;height:auto;display:block;margin:0 auto;color:#0B2B33}
</style></head><body><div id="slot">`;

const tail = `</div>
<script src="file:///${MERMAID}"></script>
<script>
  /*
   * Render each diagram explicitly rather than through mermaid.run().
   * run() has been seen to stamp data-processed on a block and then insert
   * nothing, leaving the raw source on the page while still resolving its
   * promise — which rasterises as a screenshot of mermaid source code.
   * Driving render() per block puts the SVG string in our hands, so a failure
   * is a real rejection we can surface rather than a silently blank figure.
   */
  const go = async () => {
    // Mermaid measures label widths with whatever font is resolved at the time
    // it runs. Starting before the webfonts land measures the fallback and then
    // paints Public Sans, which silently clips every label.
    try { await document.fonts.ready; } catch (e) {}
    await new Promise(r => setTimeout(r, 250));

    const nodes = Array.prototype.slice.call(document.querySelectorAll('pre.mermaid'));
    if (!window.mermaid || !nodes.length) { document.body.dataset.ready = '1'; return; }

    // Leave htmlLabels at its default: forcing it on switched the label
      // pipeline to one that strips <br/>, so labels ran on one line inside a
      // box measured for three and clipped.
      mermaid.initialize({ startOnLoad: false, securityLevel: 'loose', maxTextSize: 200000 });
    try {
      for (let i = 0; i < nodes.length; i++) {
        // innerHTML, not textContent: the HTML parser turns the <br/> and <b>
        // inside <pre class="mermaid"> into real elements, and textContent then
        // strips them — so every label arrived as one unbroken line while the
        // layout still expected the breaks. mermaid.run() reads innerHTML too.
        // innerHTML escapes text-node '>' to '&gt;', which breaks arrow syntax.
        // A textarea parses its content as RCDATA: entities decode, tags stay
        // literal — so <br/> survives and --> comes back. Same trick mermaid.run uses.
        const decode = (html) => { const t = document.createElement('textarea'); t.innerHTML = html; return t.value; };
        const src = nodes[i].getAttribute('data-src') || decode(nodes[i].innerHTML);
        nodes[i].setAttribute('data-src', src);
        const out = await mermaid.render('mm' + i + '_' + Date.now(), src);
        nodes[i].innerHTML = out.svg;
        if (!nodes[i].querySelector('svg')) throw new Error('block ' + i + ' produced no svg');
      }
      document.body.dataset.ready = '1';
    } catch (e) {
      document.body.dataset.ready = 'error';
      document.title = 'MERMAID ERROR: ' + (e.message || e.str || String(e));
    }
  };
  go();
</script></body></html>`;

plates.forEach((p, i) => fs.writeFileSync('rend/fig' + (i + 1) + '.html', head + p + tail, 'utf8'));

console.log('harnesses written : ' + plates.length);
console.log('  mermaid : ' + plates.filter(p => p.includes('class="mermaid"')).length);
console.log('  svg     : ' + plates.filter(p => !p.includes('class="mermaid"')).length);
