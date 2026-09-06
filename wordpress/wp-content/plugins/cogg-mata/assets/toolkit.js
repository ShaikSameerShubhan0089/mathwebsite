/*!
 * COGG Mata — interactive toolkit and the no-login save-and-share workflow.
 *
 * RFT §7.2.3. Read the one rule that governs this whole file:
 *   nothing here ever contacts the server.
 *
 * The teacher configures an activity in the browser, the configuration is
 * serialised to a small JSON document, and that document is handed to the
 * teacher as a file or encoded into a link. The pupil's browser decodes it and
 * reconstructs the activity locally. There is no fetch(), no XHR, no beacon and
 * no cookie anywhere in the save, share or open path — which is why no pupil
 * data can reach the server even in principle (BR-01, BR-05).
 *
 * The only network call in this file is the optional download counter, which
 * fires on PDF links, sends nothing but a post ID, and is not part of the
 * activity workflow at all.
 */
(function () {
	'use strict';

	var CFG = window.MataConfig || {};
	var FILE_VERSION = 1;
	var GA = (CFG.locale || 'ga').indexOf('en') !== 0;

	function t(ga, en) { return GA ? ga : en; }

	/* --------------------------------------------------------------- utils */
	function el(tag, attrs, kids) {
		var n = document.createElement(tag);
		if (attrs) {
			for (var k in attrs) {
				if (!Object.prototype.hasOwnProperty.call(attrs, k)) continue;
				var v = attrs[k];
				if (k === 'class') n.className = v;
				else if (k === 'text') n.textContent = v;
				else if (k.slice(0, 2) === 'on') n.addEventListener(k.slice(2), v);
				else if (v !== null && v !== false && v !== undefined) n.setAttribute(k, v);
			}
		}
		if (kids) (Array.isArray(kids) ? kids : [kids]).forEach(function (c) {
			if (c === null || c === undefined || c === false) return;
			n.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return n;
	}
	function rnd(a, b) { return Math.floor(Math.random() * (b - a + 1)) + a; }
	function shuffle(a) {
		a = a.slice();
		for (var i = a.length - 1; i > 0; i--) { var j = rnd(0, i), x = a[i]; a[i] = a[j]; a[j] = x; }
		return a;
	}

	var uidSeq = 0;
	function uid() { return 'mata-f' + (++uidSeq); }

	/* ------------------------------------------------------- config fields */
	function fNum(label, val, min, max, step, on) {
		var id = uid();
		var inp = el('input', {
			id: id, type: 'number', value: val, min: min, max: max, step: step || 1,
			oninput: function () { on(parseFloat(inp.value)); }
		});
		return el('div', { class: 'mata-field' }, [el('label', { 'for': id }, label), inp]);
	}
	function fSeg(label, opts, val, on) {
		var wrap = el('div', { class: 'mata-seg', role: 'group' });
		opts.forEach(function (o) {
			var b = el('button', {
				type: 'button', 'aria-pressed': String(o.v === val),
				onclick: function () {
					Array.prototype.forEach.call(wrap.children, function (x) { x.setAttribute('aria-pressed', 'false'); });
					b.setAttribute('aria-pressed', 'true');
					on(o.v);
				}
			}, o.l);
			wrap.appendChild(b);
		});
		return el('div', { class: 'mata-field' }, [el('span', { class: 'mata-lbl' }, label), wrap]);
	}
	function fSwitch(label, val, on) {
		var inp = el('input', { type: 'checkbox', onchange: function () { on(inp.checked); } });
		inp.checked = !!val;
		return el('div', { class: 'mata-field' }, [el('label', { class: 'mata-switch' }, [inp, el('span', {}, label)])]);
	}

	/* ------------------------------------------------------- question loop */
	function Runner(host, total, make, onDone) {
		var i = 0, score = 0;
		var counter = el('span', { class: 'mata-qn' });
		var prog = el('div', { class: 'mata-prog' }, [el('i')]);
		var scoreEl = el('span', { class: 'mata-score' });
		var area = el('div', { class: 'mata-area' });
		var fb = el('div', { class: 'mata-fb', role: 'status', 'aria-live': 'polite' });

		host.innerHTML = '';
		host.className = 'mata-play';
		host.appendChild(el('div', { class: 'mata-qbar' }, [counter, prog, scoreEl]));
		host.appendChild(area);
		host.appendChild(fb);

		function paint() {
			prog.firstChild.style.width = (i / total * 100) + '%';
			scoreEl.textContent = score + ' / ' + total;
			counter.textContent = t('Ceist ', 'Question ') + Math.min(i + 1, total) + ' / ' + total;
		}
		function next(correct) {
			if (correct) score++;
			fb.textContent = correct ? t('Togha! ✓', 'Correct! ✓') : t('Ní hea — bain triail eile as.', 'Not quite — try again.');
			fb.className = 'mata-fb ' + (correct ? 'is-ok' : 'is-no');
			i++; paint();
			setTimeout(function () {
				fb.textContent = ''; fb.className = 'mata-fb';
				if (i >= total) finish(); else make(area, next);
			}, correct ? 750 : 1100);
		}
		function finish() {
			area.innerHTML = '';
			area.appendChild(el('div', { class: 'mata-done' }, [
				el('div', { class: 'mata-bignum' }, score + ' / ' + total),
				el('div', {}, t('Chríochnaigh tú é — ', 'Finished — ') + Math.round(score / total * 100) + '%')
			]));
			area.appendChild(el('div', { class: 'mata-center' }, [
				el('button', {
					class: 'mata-btn mata-btn--primary',
					onclick: function () { i = 0; score = 0; paint(); make(area, next); }
				}, t('Arís', 'Again'))
			]));
			if (onDone) onDone(score, total);
		}
		paint();
		make(area, next);
	}

	/* ---------------------------------------------------------------- kits */
	var KITS = {};

	KITS.linear = {
		icon: '📏',
		name: { ga: 'Líne uimhreach', en: 'Number line' },
		desc: { ga: 'Aithin uimhreacha ar líne. Iontach don chlár bán.', en: 'Identify numbers on a line. Great on the whiteboard.' },
		def: function () { return { min: 0, max: 20, step: 1, marks: 4, labels: true }; },
		config: function (c, ch) {
			return [
				fNum(t('Íosluach', 'Minimum'), c.min, -50, 500, 1, function (v) { c.min = v; ch(); }),
				fNum(t('Uasluach', 'Maximum'), c.max, 1, 500, 1, function (v) { c.max = v; ch(); }),
				fSeg(t('Céim', 'Step'), [{ l: '0.5', v: 0.5 }, { l: '1', v: 1 }, { l: '2', v: 2 }, { l: '5', v: 5 }, { l: '10', v: 10 }], c.step, function (v) { c.step = v; ch(); }),
				fNum(t('Líon marcanna', 'Markers'), c.marks, 1, 8, 1, function (v) { c.marks = v; ch(); }),
				fSwitch(t('Taispeáin lipéid', 'Show labels'), c.labels, function (v) { c.labels = v; ch(); })
			];
		},
		play: function (c, host) {
			var vals = [], v;
			for (v = c.min; v <= c.max + 1e-9; v += c.step) vals.push(Math.round(v * 100) / 100);
			var total = Math.min(c.marks, Math.max(1, vals.length - 2));
			Runner(host, total, function (area, next) {
				area.innerHTML = '';
				var pick = vals[rnd(1, vals.length - 2)];
				area.appendChild(el('p', { class: 'mata-q' }, t('Cén uimhir atá ag an marc?', 'What number is the marker on?')));

				var inner = el('div', { class: 'mata-nl-in' });
				vals.forEach(function (val) {
					var pc = ((val - c.min) / (c.max - c.min)) * 100;
					var major = (Math.abs(val % (c.step * 5)) < 1e-9) || val === c.min || val === c.max;
					inner.appendChild(el('span', { class: 'mata-tick' + (major ? ' is-major' : ''), style: 'left:' + pc + '%' }));
					if (c.labels && major) inner.appendChild(el('span', { class: 'mata-tlabel', style: 'left:' + pc + '%' }, String(val)));
				});
				inner.appendChild(el('span', { class: 'mata-axis' }));

				var pcp = ((pick - c.min) / (c.max - c.min)) * 100;
				var mark = el('button', { class: 'mata-mark', type: 'button', style: 'left:' + pcp + '%', 'aria-label': t('Marc', 'Marker') }, '?');
				inner.appendChild(mark);
				area.appendChild(el('div', { class: 'mata-nl' }, [inner]));

				var id = uid();
				var inp = el('input', { id: id, type: 'number', step: 'any' });
				var go = el('button', { class: 'mata-btn mata-btn--primary', type: 'button', onclick: check }, t('Seiceáil', 'Check'));
				inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') check(); });
				area.appendChild(el('div', { class: 'mata-ans' }, [
					el('label', { class: 'mata-sr', 'for': id }, t('Do fhreagra', 'Your answer')), inp, go
				]));
				setTimeout(function () { inp.focus(); }, 30);

				function check() {
					var ok = Math.abs(parseFloat(inp.value) - pick) < 1e-9;
					mark.textContent = String(pick);
					mark.className = 'mata-mark ' + (ok ? 'is-ok' : 'is-no');
					go.disabled = true; inp.disabled = true;
					next(ok);
				}
			});
		}
	};

	KITS.pv = {
		icon: '🔢',
		name: { ga: 'Luach ionaid', en: 'Place value' },
		desc: { ga: 'Tóg an uimhir sprice le colúin luach ionaid.', en: 'Build the target number with place-value columns.' },
		def: function () { return { cols: 3, count: 6 }; },
		config: function (c, ch) {
			return [
				fSeg(t('Colúin', 'Columns'), [{ l: '2', v: 2 }, { l: '3', v: 3 }, { l: '4', v: 4 }], c.cols, function (v) { c.cols = v; ch(); }),
				fNum(t('Líon ceisteanna', 'Questions'), c.count, 3, 20, 1, function (v) { c.count = v; ch(); })
			];
		},
		play: function (c, host) {
			var heads = GA ? ['Mílte', 'Céadta', 'Deicheanna', 'Aonaid'] : ['Thousands', 'Hundreds', 'Tens', 'Units'];
			var use = heads.slice(4 - c.cols);
			Runner(host, c.count, function (area, next) {
				area.innerHTML = '';
				var target = rnd(Math.pow(10, c.cols - 1), Math.pow(10, c.cols) - 1);
				var digits = []; for (var i = 0; i < c.cols; i++) digits.push(0);
				area.appendChild(el('p', { class: 'mata-q' }, t('Tóg an uimhir ', 'Build the number ') + target));

				var row = el('div', { class: 'mata-pv' });
				use.forEach(function (head, idx) {
					var val = el('div', { class: 'mata-pv-val' }, '0');
					var dots = el('div', { class: 'mata-pv-dots' });
					function paint() {
						val.textContent = String(digits[idx]);
						dots.innerHTML = '';
						for (var k = 0; k < digits[idx]; k++) dots.appendChild(el('span', { class: 'mata-dot' }));
					}
					var col = el('div', { class: 'mata-pv-col' }, [
						el('div', { class: 'mata-pv-head' }, head), val, dots,
						el('div', { class: 'mata-pv-btns' }, [
							el('button', { type: 'button', 'aria-label': head + ' −', onclick: function () { digits[idx] = (digits[idx] + 9) % 10; paint(); } }, '−'),
							el('button', { type: 'button', 'aria-label': head + ' +', onclick: function () { digits[idx] = (digits[idx] + 1) % 10; paint(); } }, '+')
						])
					]);
					paint();
					row.appendChild(col);
				});
				area.appendChild(row);

				var go = el('button', {
					class: 'mata-btn mata-btn--primary', type: 'button',
					onclick: function () { go.disabled = true; next(parseInt(digits.join(''), 10) === target); }
				}, t('Seiceáil', 'Check'));
				area.appendChild(el('div', { class: 'mata-ans' }, [go]));
			});
		}
	};

	KITS.frac = {
		icon: '🍕',
		name: { ga: 'Codáin', en: 'Fractions' },
		desc: { ga: 'Dathaigh nó ainmnigh codáin le barraí nó ciorcail.', en: 'Shade or name fractions with bars or circles.' },
		def: function () { return { shape: 'barra', dmin: 2, dmax: 6, count: 6, mode: 'dath' }; },
		config: function (c, ch) {
			return [
				fSeg(t('Cruth', 'Shape'), [{ l: t('Barra', 'Bar'), v: 'barra' }, { l: t('Ciorcal', 'Circle'), v: 'ciorcal' }], c.shape, function (v) { c.shape = v; ch(); }),
				fSeg(t('Modh', 'Mode'), [{ l: t('Dathaigh', 'Shade'), v: 'dath' }, { l: t('Ainmnigh', 'Name'), v: 'ainm' }], c.mode, function (v) { c.mode = v; ch(); }),
				fNum(t('Ainmneoir íosta', 'Min denominator'), c.dmin, 2, 12, 1, function (v) { c.dmin = v; ch(); }),
				fNum(t('Ainmneoir uasta', 'Max denominator'), c.dmax, 2, 12, 1, function (v) { c.dmax = v; ch(); }),
				fNum(t('Líon ceisteanna', 'Questions'), c.count, 3, 20, 1, function (v) { c.count = v; ch(); })
			];
		},
		play: function (c, host) {
			var dmin = Math.min(c.dmin, c.dmax), dmax = Math.max(c.dmin, c.dmax);
			Runner(host, c.count, function (area, next) {
				area.innerHTML = '';
				var d = rnd(dmin, dmax);
				var n = rnd(1, Math.max(1, d - 1));
				var on = []; for (var i = 0; i < d; i++) on.push(false);

				function frac(a, b) {
					return el('span', { class: 'mata-frac' }, [
						el('span', {}, String(a)), el('i', { class: 'mata-frac-bar' }), el('span', {}, String(b))
					]);
				}
				function bar(locked, preset) {
					var b = el('div', { class: 'mata-bar' + (locked ? ' is-locked' : '') });
					for (var i = 0; i < d; i++) {
						(function (i) {
							var seg = el('i', { 'data-on': (preset && preset[i]) ? '1' : '0' });
							if (!locked) seg.addEventListener('click', function () {
								on[i] = !on[i]; seg.setAttribute('data-on', on[i] ? '1' : '0');
							});
							b.appendChild(seg);
						})(i);
					}
					return b;
				}
				function circle(locked, preset) {
					var NS = 'http://www.w3.org/2000/svg', R = 70, C = 78;
					var svg = document.createElementNS(NS, 'svg');
					svg.setAttribute('viewBox', '0 0 156 156');
					svg.setAttribute('width', '156'); svg.setAttribute('height', '156');
					svg.setAttribute('class', 'mata-circ'); svg.setAttribute('role', 'img');
					for (var i = 0; i < d; i++) {
						(function (i) {
							var a0 = (i / d) * Math.PI * 2 - Math.PI / 2;
							var a1 = ((i + 1) / d) * Math.PI * 2 - Math.PI / 2;
							var x0 = C + R * Math.cos(a0), y0 = C + R * Math.sin(a0);
							var x1 = C + R * Math.cos(a1), y1 = C + R * Math.sin(a1);
							var p = document.createElementNS(NS, 'path');
							p.setAttribute('d', 'M ' + C + ' ' + C + ' L ' + x0 + ' ' + y0 +
								' A ' + R + ' ' + R + ' 0 ' + ((a1 - a0) > Math.PI ? 1 : 0) + ' 1 ' + x1 + ' ' + y1 + ' Z');
							p.setAttribute('fill', (preset && preset[i]) ? 'var(--mata-brand)' : 'var(--mata-surface)');
							p.setAttribute('stroke', 'var(--mata-ink-3)');
							p.setAttribute('stroke-width', '1.5');
							if (!locked) {
								p.style.cursor = 'pointer';
								p.addEventListener('click', function () {
									on[i] = !on[i];
									p.setAttribute('fill', on[i] ? 'var(--mata-brand)' : 'var(--mata-surface)');
								});
							}
							svg.appendChild(p);
						})(i);
					}
					return svg;
				}

				if (c.mode === 'dath') {
					area.appendChild(el('p', { class: 'mata-q' }, [document.createTextNode(t('Dathaigh ', 'Shade ')), frac(n, d)]));
					area.appendChild(el('div', { class: 'mata-shapes' }, [c.shape === 'barra' ? bar(false) : circle(false)]));
					var go = el('button', {
						class: 'mata-btn mata-btn--primary', type: 'button',
						onclick: function () { go.disabled = true; next(on.filter(Boolean).length === n); }
					}, t('Seiceáil', 'Check'));
					area.appendChild(el('div', { class: 'mata-ans' }, [go]));
				} else {
					var idxs = shuffle(on.map(function (_, i) { return i; })).slice(0, n);
					var preset = [];
					for (var k = 0; k < d; k++) preset.push(idxs.indexOf(k) >= 0);
					area.appendChild(el('p', { class: 'mata-q' }, t('Cén codán atá dathaithe?', 'Which fraction is shaded?')));
					area.appendChild(el('div', { class: 'mata-shapes' }, [c.shape === 'barra' ? bar(true, preset) : circle(true, preset)]));

					var choices = [[n, d]];
					var guard = 0;
					while (choices.length < 4 && guard++ < 60) {
						var cn = rnd(1, Math.max(1, d - 1)), cd = rnd(dmin, dmax);
						if (!choices.some(function (x) { return x[0] === cn && x[1] === cd; })) choices.push([cn, cd]);
					}
					var opts = el('div', { class: 'mata-opts' });
					shuffle(choices).forEach(function (ch2) {
						var b = el('button', {
							class: 'mata-opt', type: 'button',
							onclick: function () {
								var ok = (ch2[0] === n && ch2[1] === d);
								b.className = 'mata-opt ' + (ok ? 'is-ok' : 'is-no');
								Array.prototype.forEach.call(opts.children, function (x) { x.disabled = true; });
								next(ok);
							}
						}, [frac(ch2[0], ch2[1])]);
						opts.appendChild(b);
					});
					area.appendChild(opts);
				}
			});
		}
	};

	KITS.tab = {
		icon: '✳️',
		name: { ga: 'Fíricí uimhre', en: 'Number facts' },
		desc: { ga: 'Táblaí agus fíricí suimithe — cleachtadh tapa.', en: 'Tables and number facts — quick practice.' },
		def: function () { return { tables: [2, 5, 10], op: '×', count: 10 }; },
		config: function (c, ch) {
			var grid = el('div', { class: 'mata-tbl-grid' });
			for (var n = 1; n <= 12; n++) {
				(function (n) {
					var b = el('button', {
						type: 'button', 'aria-pressed': String(c.tables.indexOf(n) >= 0),
						onclick: function () {
							var i = c.tables.indexOf(n);
							if (i >= 0) { if (c.tables.length > 1) c.tables.splice(i, 1); }
							else c.tables.push(n);
							c.tables.sort(function (a, b2) { return a - b2; });
							b.setAttribute('aria-pressed', String(c.tables.indexOf(n) >= 0));
							ch();
						}
					}, String(n));
					grid.appendChild(b);
				})(n);
			}
			return [
				fSeg(t('Oibríocht', 'Operation'), [{ l: '×', v: '×' }, { l: '+', v: '+' }, { l: '−', v: '−' }], c.op, function (v) { c.op = v; ch(); }),
				fNum(t('Líon ceisteanna', 'Questions'), c.count, 3, 30, 1, function (v) { c.count = v; ch(); }),
				el('div', { class: 'mata-field mata-field--wide' }, [
					el('span', { class: 'mata-lbl' }, t('Táblaí / uimhreacha', 'Tables / numbers')), grid
				])
			];
		},
		play: function (c, host) {
			Runner(host, c.count, function (area, next) {
				area.innerHTML = '';
				var a = c.tables[rnd(0, c.tables.length - 1)], b = rnd(1, 12), q, ans;
				if (c.op === '×') { q = a + ' × ' + b; ans = a * b; }
				else if (c.op === '+') { q = a + ' + ' + b; ans = a + b; }
				else { var hi = Math.max(a, b), lo = Math.min(a, b); q = hi + ' − ' + lo; ans = hi - lo; }

				area.appendChild(el('p', { class: 'mata-q' }, q + ' = ?'));
				var set = [ans], guard = 0;
				while (set.length < 4 && guard++ < 60) {
					var cand = ans + rnd(-9, 9);
					if (cand >= 0 && set.indexOf(cand) < 0) set.push(cand);
				}
				var opts = el('div', { class: 'mata-opts' });
				shuffle(set).forEach(function (v) {
					var btn = el('button', {
						class: 'mata-opt', type: 'button',
						onclick: function () {
							var ok = (v === ans);
							btn.className = 'mata-opt ' + (ok ? 'is-ok' : 'is-no');
							Array.prototype.forEach.call(opts.children, function (x) {
								x.disabled = true;
								if (!ok && x.textContent === String(ans)) x.className = 'mata-opt is-ok';
							});
							next(ok);
						}
					}, String(v));
					opts.appendChild(btn);
				});
				area.appendChild(opts);
			});
		}
	};

	/* ------------------------------------------------- save / share / open */

	function buildFile(kit, cfg) {
		return { v: FILE_VERSION, kit: kit, cfg: cfg, created: new Date().toISOString() };
	}
	function encodePayload(obj) {
		var b64 = btoa(unescape(encodeURIComponent(JSON.stringify(obj))));
		return b64.replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
	function decodePayload(s) {
		s = s.replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) s += '=';
		return JSON.parse(decodeURIComponent(escape(atob(s))));
	}
	function shareLink(obj) {
		var base = CFG.openUrl || (location.origin + location.pathname);
		return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'a=' + encodePayload(obj);
	}
	function validateFile(o) {
		if (!o || typeof o !== 'object') return t('Níorbh fhéidir an comhad seo a léamh.', 'This file could not be read.');
		if (o.v !== FILE_VERSION) return t(
			'Rinneadh an comhad seo le leagan eile den uirlis. Iarr cóip nua ar do mhúinteoir.',
			'This file was made with a different version of the toolkit. Ask your teacher for a new copy.');
		if (!o.kit || !KITS[o.kit]) return t('Níl an cineál gníomhaíochta sin ar eolas againn.', 'That activity type is not recognised.');
		if (!o.cfg || typeof o.cfg !== 'object') return t('Tá socruithe na gníomhaíochta ar iarraidh.', 'The activity settings are missing.');
		return null;
	}

	/*
	 * Value-level guard for a file that is well formed but wrong.
	 *
	 * validateFile() above proves the file is the right shape: right version,
	 * known kit, a settings object present. It says nothing about the values
	 * inside. A .mata file is plain JSON a teacher can open in Notepad, and a
	 * share link is a query string anyone can edit, so a file can arrive with
	 * count: 100000, dmin: "abc" or tables: [] — every one of which produces a
	 * hung or blank activity in front of a class.
	 *
	 * The bounds below are exactly the ones the configuration controls enforce,
	 * so a file can never carry a setting a teacher could not have chosen. Keys
	 * are whitelisted against the kit's own defaults: anything unrecognised is
	 * dropped rather than passed through, and anything invalid falls back to the
	 * default instead of failing the open — a pupil gets a working activity, not
	 * an error screen.
	 */
	var SCHEMA = {
		linear: {
			min:    { t: 'int',  lo: -50, hi: 500 },
			max:    { t: 'int',  lo: 1,   hi: 500 },
			step:   { t: 'enum', of: [0.5, 1, 2, 5, 10] },
			marks:  { t: 'int',  lo: 1,   hi: 8 },
			labels: { t: 'bool' }
		},
		pv: {
			cols:  { t: 'enum', of: [2, 3, 4] },
			count: { t: 'int',  lo: 3, hi: 20 }
		},
		frac: {
			shape: { t: 'enum', of: ['barra', 'ciorcal'] },
			mode:  { t: 'enum', of: ['dath', 'ainm'] },
			dmin:  { t: 'int',  lo: 2, hi: 12 },
			dmax:  { t: 'int',  lo: 2, hi: 12 },
			count: { t: 'int',  lo: 3, hi: 20 }
		},
		tab: {
			op:     { t: 'enum', of: ['×', '+', '−'] },
			count:  { t: 'int',  lo: 3, hi: 30 },
			tables: { t: 'ints', lo: 1, hi: 12, cap: 12 }
		}
	};

	function sanitiseCfg(kitId, raw) {
		var def = KITS[kitId].def();
		var schema = SCHEMA[kitId] || {};
		var out = {};

		Object.keys(def).forEach(function (k) {
			var rule = schema[k];
			var v = (raw && Object.prototype.hasOwnProperty.call(raw, k)) ? raw[k] : def[k];

			if (!rule) { out[k] = def[k]; return; }

			if (rule.t === 'bool') {
				out[k] = (typeof v === 'boolean') ? v : def[k];
				return;
			}
			if (rule.t === 'enum') {
				out[k] = (rule.of.indexOf(v) !== -1) ? v : def[k];
				return;
			}
			if (rule.t === 'int') {
				var n = parseInt(v, 10);
				out[k] = isFinite(n) ? Math.min(rule.hi, Math.max(rule.lo, n)) : def[k];
				return;
			}
			if (rule.t === 'ints') {
				if (!Object.prototype.toString.call(v).match(/Array/)) { out[k] = def[k]; return; }
				var list = [];
				v.forEach(function (x) {
					var m = parseInt(x, 10);
					if (isFinite(m) && m >= rule.lo && m <= rule.hi && list.indexOf(m) === -1) list.push(m);
				});
				list.sort(function (a, b) { return a - b; });
				// An empty table list would make the runner pick from nothing.
				out[k] = list.length ? list.slice(0, rule.cap) : def[k];
				return;
			}
			out[k] = def[k];
		});

		return out;
	}

	function modal(title, bodyNodes, footNodes) {
		var scrim = el('div', { class: 'mata-scrim' });
		var box = el('div', { class: 'mata-modal', role: 'dialog', 'aria-modal': 'true', 'aria-label': title });
		var close = function () { scrim.remove(); document.body.style.overflow = ''; };
		box.appendChild(el('div', { class: 'mata-modal-head' }, [
			el('h2', {}, title),
			el('button', { class: 'mata-x', type: 'button', 'aria-label': t('Dún', 'Close'), onclick: close }, '×')
		]));
		box.appendChild(el('div', { class: 'mata-modal-body' }, bodyNodes));
		if (footNodes && footNodes.length) box.appendChild(el('div', { class: 'mata-modal-foot' }, footNodes));
		scrim.appendChild(box);
		scrim.addEventListener('click', function (e) { if (e.target === scrim) close(); });
		document.addEventListener('keydown', function esc(e) {
			if (e.key === 'Escape') { close(); document.removeEventListener('keydown', esc); }
		});
		document.body.appendChild(scrim);
		document.body.style.overflow = 'hidden';
		var first = box.querySelector('button,input,a[href]');
		if (first) first.focus();
		return close;
	}

	function saveAndShare(kit, cfg) {
		var payload = buildFile(kit, cfg);
		var json = JSON.stringify(payload, null, 2);
		var link = shareLink(payload);
		var bytes = new Blob([JSON.stringify(payload)]).size;

		var linkInput = el('input', { type: 'text', readonly: 'readonly', value: link, class: 'mata-linkinput' });
		var copyBtn = el('button', {
			class: 'mata-btn mata-btn--primary', type: 'button',
			onclick: function () {
				linkInput.select(); linkInput.setSelectionRange(0, 99999);
				var ok = false;
				try { ok = document.execCommand('copy'); } catch (e) { /* ignore */ }
				if (!ok && navigator.clipboard) navigator.clipboard.writeText(link);
			}
		}, t('Cóipeáil', 'Copy'));

		var body = [
			el('div', { class: 'mata-note' }, [
				el('strong', {}, t('An bealach is tapúla: ', 'Fastest route: ')),
				document.createTextNode(t(
					'greamaigh an nasc seo i Google Classroom nó i ríomhphost. Ní gá d\'aon dalta logáil isteach.',
					'paste this link into Google Classroom or an email. No pupil needs to log in.'))
			]),
			el('div', { class: 'mata-field' }, [
				el('span', { class: 'mata-lbl' }, t('Nasc inroinnte', 'Shareable link')),
				el('div', { class: 'mata-linkbox' }, [linkInput, copyBtn])
			]),
			el('div', { class: 'mata-field' }, [
				el('span', { class: 'mata-lbl' }, t('An comhad a shábhálfar (', 'The file that gets saved (') + bytes + ' bytes)'),
				el('pre', { class: 'mata-code' }, json)
			]),
			el('div', { class: 'mata-note mata-note--good' }, [
				el('strong', {}, t('Seiceáil é: ', 'Check it: ')),
				document.createTextNode(t(
					'níl ainm dalta, aitheantas scoile ná aon réimse pearsanta sa chomhad — ná níl aon réimse ann a d\'fhéadfadh ceann a iompar.',
					'there is no pupil name, school identifier or personal field in the file — nor any field capable of carrying one.'))
			])
		];

		var download = el('button', {
			class: 'mata-btn mata-btn--ghost', type: 'button',
			onclick: function () {
				try {
					var blob = new Blob([json], { type: 'application/json' });
					var url = URL.createObjectURL(blob);
					var a = el('a', { href: url, download: 'gniomhaiocht-' + kit + '.mata' });
					document.body.appendChild(a); a.click(); a.remove();
					setTimeout(function () { URL.revokeObjectURL(url); }, 1500);
				} catch (e) { /* sandboxed viewers block downloads; the link still works */ }
			}
		}, t('Sábháil comhad .mata', 'Save .mata file'));

		var close;
		close = modal(t('Sábháil agus roinn', 'Save and share'), body,
			[download, el('button', { class: 'mata-btn mata-btn--primary', type: 'button', onclick: function () { close(); } }, t('Déanta', 'Done'))]);
	}

	function playShared(obj) {
		var kit = KITS[obj.kit];
		var host = el('div');
		modal(kit.name[GA ? 'ga' : 'en'], [
			el('div', { class: 'mata-note mata-note--good' }, t(
				'Osclaíodh an ghníomhaíocht go háitiúil i do bhrabhsálaí. Níor seoladh dada chuig freastalaí.',
				'The activity opened locally in your browser. Nothing was sent to a server.')),
			host
		], []);
		// Clamp before playing: the payload came from a URL or a file on disk,
		// either of which a person can edit by hand.
		kit.play(sanitiseCfg(obj.kit, obj.cfg), host);
	}

	/* ------------------------------------------------------------- mounts */

	function mountToolkit(root) {
		var state = { kit: root.getAttribute('data-default-kit') || 'linear', cfg: null, source: null };
		if (!KITS[state.kit]) state.kit = 'linear';
		state.cfg = KITS[state.kit].def();

		root.innerHTML = '';
		var picker = el('div', { class: 'mata-picker', role: 'group', 'aria-label': t('Roghnaigh gníomhaíocht', 'Choose an activity') });
		var title = el('h2', { class: 'mata-stage-title' });
		/*
		 * Tabs follow the WAI-ARIA tabs pattern in full, not just by name.
		 *
		 * role="tab" and aria-selected alone are not enough: without
		 * aria-controls and matching role="tabpanel" elements a screen reader
		 * announces "tab" but cannot tell the user what it governs, and without
		 * a roving tabindex the arrow keys do nothing, so a keyboard user has to
		 * tab through every control in the settings pane to reach the second
		 * tab. WCAG 2.1 AA, 4.1.2 Name Role Value and 2.1.1 Keyboard.
		 */
		var tabCfgId = uid(), tabPlayId = uid(), cfgPaneId = uid(), playPaneId = uid();

		var cfgPane = el('div', {
			class: 'mata-cfg', id: cfgPaneId,
			role: 'tabpanel', 'aria-labelledby': tabCfgId, tabindex: '0'
		});
		var playPane = el('div', {
			hidden: 'hidden', id: playPaneId,
			role: 'tabpanel', 'aria-labelledby': tabPlayId, tabindex: '0'
		});
		var adaptNote = el('div', { class: 'mata-note mata-note--gold', hidden: 'hidden' });

		var tabCfg = el('button', {
			type: 'button', role: 'tab', id: tabCfgId,
			'aria-selected': 'true', 'aria-controls': cfgPaneId, tabindex: '0'
		}, t('Socrú', 'Set up'));
		var tabPlay = el('button', {
			type: 'button', role: 'tab', id: tabPlayId,
			'aria-selected': 'false', 'aria-controls': playPaneId, tabindex: '-1'
		}, t('Imir', 'Play'));

		function showTab(which, moveFocus) {
			var isCfg = which === 'cfg';
			tabCfg.setAttribute('aria-selected', String(isCfg));
			tabPlay.setAttribute('aria-selected', String(!isCfg));
			// Roving tabindex: only the selected tab is in the tab order, so Tab
			// moves out of the tablist rather than through every tab in it.
			tabCfg.tabIndex = isCfg ? 0 : -1;
			tabPlay.tabIndex = isCfg ? -1 : 0;
			cfgPane.hidden = !isCfg;
			playPane.hidden = isCfg;
			if (moveFocus) (isCfg ? tabCfg : tabPlay).focus();
			if (!isCfg) KITS[state.kit].play(state.cfg, playPane);
		}
		tabCfg.addEventListener('click', function () { showTab('cfg'); });
		tabPlay.addEventListener('click', function () { showTab('play'); });

		[tabCfg, tabPlay].forEach(function (tabEl) {
			tabEl.addEventListener('keydown', function (e) {
				var k = e.key;
				if (k === 'ArrowRight' || k === 'ArrowDown') { e.preventDefault(); showTab(tabEl === tabCfg ? 'play' : 'cfg', true); }
				else if (k === 'ArrowLeft' || k === 'ArrowUp') { e.preventDefault(); showTab(tabEl === tabPlay ? 'cfg' : 'play', true); }
				else if (k === 'Home') { e.preventDefault(); showTab('cfg', true); }
				else if (k === 'End') { e.preventDefault(); showTab('play', true); }
			});
		});

		function renderPicker() {
			picker.innerHTML = '';
			Object.keys(KITS).forEach(function (id) {
				var k = KITS[id];
				var b = el('button', {
					type: 'button', 'aria-pressed': String(id === state.kit),
					onclick: function () {
						state.kit = id; state.cfg = k.def(); state.source = null;
						adaptNote.hidden = true;
						renderPicker(); renderConfig(); showTab('cfg');
					}
				}, [
					el('span', { class: 'mata-picker-ic', 'aria-hidden': 'true' }, k.icon),
					el('span', { class: 'mata-picker-txt' }, [
						el('span', { class: 'mata-picker-nm' }, k.name[GA ? 'ga' : 'en']),
						el('span', { class: 'mata-picker-ds' }, k.desc[GA ? 'ga' : 'en'])
					])
				]);
				picker.appendChild(b);
			});
		}
		function renderConfig() {
			var k = KITS[state.kit];
			title.textContent = k.name[GA ? 'ga' : 'en'];
			cfgPane.innerHTML = '';
			var grid = el('div', { class: 'mata-cfg-grid' });
			k.config(state.cfg, function () {}).forEach(function (n) { grid.appendChild(n); });
			cfgPane.appendChild(grid);
			cfgPane.appendChild(el('div', { class: 'mata-cfg-actions' }, [
				el('button', { class: 'mata-btn mata-btn--primary', type: 'button', onclick: function () { showTab('play'); } }, t('Réamhamharc ▸', 'Preview ▸')),
				el('button', { class: 'mata-btn mata-btn--ghost', type: 'button', onclick: function () { saveAndShare(state.kit, state.cfg); } }, t('Sábháil & roinn', 'Save & share')),
				el('button', {
					class: 'mata-btn mata-btn--quiet', type: 'button',
					onclick: function () { state.cfg = KITS[state.kit].def(); state.source = null; adaptNote.hidden = true; renderConfig(); }
				}, t('Athshocraigh', 'Reset'))
			]));
			cfgPane.appendChild(el('p', { class: 'mata-hint' }, t(
				'Ní chuimsíonn an comhad a shábhálfar ach na socruithe thuas — dada eile.',
				'The saved file contains only the settings above — nothing else.')));
		}

		root.appendChild(el('div', { class: 'mata-tk' }, [
			el('div', { class: 'mata-tk-side' }, [picker, adaptNote]),
			el('div', { class: 'mata-stage' }, [
				el('div', { class: 'mata-stage-head' }, [title, el('div', { class: 'mata-tabs', role: 'tablist' }, [tabCfg, tabPlay])]),
				el('div', { class: 'mata-stage-body' }, [cfgPane, playPane])
			])
		]));

		renderPicker();
		renderConfig();

		// Teacher adaptation (RFT §9.2.3): ?adapt=<post id> preloads a published
		// activity's configuration into the toolkit, then saves via the same
		// Phase 1 file format — one workflow for teachers, not two.
		var adaptId = new URLSearchParams(location.search).get('adapt');
		if (adaptId && CFG.restUrl) {
			fetch(CFG.restUrl + 'items?kind=activity&per=100', { credentials: 'same-origin' })
				.then(function (r) { return r.json(); })
				.then(function (data) {
					var hit = (data.items || []).filter(function (x) { return String(x.id) === String(adaptId); })[0];
					if (!hit || !hit.adaptable || !hit.kit || !KITS[hit.kit]) return;
					state.kit = hit.kit;
					state.cfg = sanitiseCfg(hit.kit, hit.cfg);
					state.source = hit.title;
					adaptNote.hidden = false;
					adaptNote.textContent = t('Á cur in oiriúint: ', 'Adapting: ') + hit.title;
					renderPicker(); renderConfig();
				})
				.catch(function () { /* adaptation is an enhancement; the toolkit still works */ });
		}
	}

	function mountOpen(root) {
		root.innerHTML = '';
		var err = el('p', { class: 'mata-err', hidden: 'hidden', role: 'alert' });

		function attempt(text) {
			err.hidden = true;
			var s = String(text || '').trim();
			if (!s) { fail(t('Cuir nasc nó cód isteach ar dtús.', 'Enter a link or code first.')); return; }
			var obj = null;
			try {
				if (s.charAt(0) === '{') obj = JSON.parse(s);
				else {
					var m = s.match(/[?&]a=([A-Za-z0-9\-_]+)/);
					obj = decodePayload(m ? m[1] : s);
				}
			} catch (e) {
				fail(t('Níorbh fhéidir é seo a léamh. Iarr cóip nua ar do mhúinteoir.', 'This could not be read. Ask your teacher for a new copy.'));
				return;
			}
			var problem = validateFile(obj);
			if (problem) { fail(problem); return; }
			playShared(obj);
		}
		function fail(msg) { err.hidden = false; err.textContent = msg; }

		var fileInput = el('input', { type: 'file', accept: '.mata,.json,application/json', class: 'mata-sr', 'aria-label': t('Roghnaigh comhad', 'Choose a file') });
		fileInput.addEventListener('change', function (e) { readFile(e.target.files[0]); });

		function readFile(file) {
			if (!file) return;
			var fr = new FileReader();
			fr.onload = function () { attempt(fr.result); };
			fr.onerror = function () { fail(t('Níorbh fhéidir an comhad a léamh.', 'The file could not be read.')); };
			fr.readAsText(file);
		}

		var drop = el('div', { class: 'mata-drop' }, [
			el('div', { class: 'mata-drop-ic', 'aria-hidden': 'true' }, '📂'),
			el('p', {}, t('Tarraing comhad .mata anseo', 'Drag a .mata file here')),
			el('p', { class: 'mata-hint' }, t('nó', 'or')),
			el('button', { class: 'mata-btn mata-btn--primary', type: 'button', onclick: function () { fileInput.click(); } }, t('Roghnaigh comhad', 'Choose a file')),
			fileInput
		]);
		['dragenter', 'dragover'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
		});
		['dragleave', 'drop'].forEach(function (ev) {
			drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
		});
		drop.addEventListener('drop', function (e) {
			if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) readFile(e.dataTransfer.files[0]);
		});

		var pasteId = uid();
		var paste = el('input', { id: pasteId, type: 'text', placeholder: 'https://… ?a=…' });
		paste.addEventListener('keydown', function (e) { if (e.key === 'Enter') attempt(paste.value); });

		root.appendChild(drop);
		root.appendChild(el('div', { class: 'mata-card' }, [
			el('h3', {}, t('Greamaigh nasc nó cód', 'Paste a link or code')),
			el('p', { class: 'mata-hint' }, t('Má sheol do mhúinteoir nasc chugat, greamaigh anseo é.', 'If your teacher sent you a link, paste it here.')),
			el('div', { class: 'mata-linkbox' }, [
				el('label', { class: 'mata-sr', 'for': pasteId }, t('Nasc nó cód', 'Link or code')),
				paste,
				el('button', { class: 'mata-btn mata-btn--primary', type: 'button', onclick: function () { attempt(paste.value); } }, t('Oscail', 'Open'))
			]),
			err
		]));

		// Deep link: a pupil arriving from a shared URL opens straight into it.
		var incoming = new URLSearchParams(location.search).get('a');
		if (incoming) setTimeout(function () { attempt(incoming); }, 60);
	}

	function mountEmbedded(node) {
		var kit = node.getAttribute('data-kit');
		if (!kit || !KITS[kit]) return;
		var cfg;
		try { cfg = JSON.parse(node.getAttribute('data-cfg') || '{}'); } catch (e) { cfg = {}; }
		if (!Object.keys(cfg).length) cfg = KITS[kit].def();

		var host = el('div');
		node.innerHTML = '';
		node.appendChild(host);
		if (node.getAttribute('data-adaptable') === '1') {
			node.appendChild(el('div', { class: 'mata-center' }, [
				el('button', {
					class: 'mata-btn mata-btn--ghost', type: 'button',
					onclick: function () { saveAndShare(kit, cfg); }
				}, t('Cuir in oiriúint & roinn', 'Adapt & share'))
			]));
		}
		KITS[kit].play(cfg, host);
	}

	/** Optional, aggregate-only download counter (SRS §13.3). */
	function wireDownloadCounter() {
		if (!CFG.restUrl) return;
		document.addEventListener('click', function (e) {
			var a = e.target.closest ? e.target.closest('[data-download]') : null;
			if (!a) return;
			var id = a.getAttribute('data-download');
			try {
				fetch(CFG.restUrl + 'items/' + encodeURIComponent(id) + '/download', {
					method: 'POST',
					headers: { 'X-WP-Nonce': CFG.nonce || '' },
					keepalive: true
				});
			} catch (err) { /* counting is best-effort and never blocks the download */ }
		});
	}

	/* ---------------------------------------------------- authoring builder */

	/**
	 * The admin-side activity builder (RFT §9.2.1).
	 *
	 * Before this existed, authoring a digital activity meant typing raw JSON
	 * into a textarea — which is a data entry field, not an authoring tool, and
	 * it put the burden of knowing each kit's parameter names on the author.
	 *
	 * This mounts the same kit picker, the same configuration controls and the
	 * same renderer that teachers use on the public site, then writes the JSON
	 * back into the form fields WordPress already saves. Nothing new is stored
	 * and no new save path exists: the builder is a front end onto the two
	 * fields that were always there, so the metadata gate, the capability check
	 * and BR-05 config sanitising all still run exactly as before.
	 *
	 * The JSON stays visible under "advanced", because an author who has been
	 * handed a configuration by COGG needs somewhere to paste it.
	 */
	function mountBuilder(root) {
		var kitField = document.getElementById(root.getAttribute('data-kit-field'));
		var cfgField = document.getElementById(root.getAttribute('data-cfg-field'));
		if (!kitField || !cfgField) return;

		var state = { kit: kitField.value || '', cfg: null };

		// Seed from whatever is already saved on the post.
		try {
			var saved = JSON.parse(cfgField.value || '{}');
			if (saved && typeof saved === 'object') state.cfg = saved;
		} catch (e) { /* unparseable JSON just means we start from defaults */ }

		var picker = el('div', { class: 'mata-picker mata-picker--builder' });
		var cfgPane = el('div', { class: 'mata-cfg' });
		var stage = el('div', { class: 'mata-builder-stage' });
		var status = el('p', { class: 'mata-hint', role: 'status' });

		function sync() {
			kitField.value = state.kit;
			cfgField.value = state.kit ? JSON.stringify(state.cfg) : '';
			// Let WordPress notice the change so it prompts on unsaved navigation.
			[kitField, cfgField].forEach(function (f) {
				f.dispatchEvent(new Event('change', { bubbles: true }));
			});
		}

		function renderPreview() {
			stage.innerHTML = '';
			if (!state.kit || !KITS[state.kit]) {
				stage.appendChild(el('p', { class: 'mata-hint' },
					t('Roghnaigh cineál uirlise le réamhamharc a fheiceáil.',
					  'Choose an activity type to see a preview.')));
				return;
			}
			// play() takes ownership of its host element's className, so it gets
			// a fresh inner div rather than the stage itself — otherwise the
			// stage's own framing is overwritten the first time a kit renders.
			var host = el('div');
			stage.appendChild(host);
			KITS[state.kit].play(sanitiseCfg(state.kit, state.cfg), host);
		}

		function renderConfig() {
			cfgPane.innerHTML = '';
			if (!state.kit || !KITS[state.kit]) return;
			var grid = el('div', { class: 'mata-cfg-grid' });
			KITS[state.kit].config(state.cfg, function () {
				sync();
				renderPreview();
				status.textContent = t('Sábhálfar na socruithe seo leis an ngníomhaíocht.',
				                       'These settings will be saved with the activity.');
			}).forEach(function (n) { grid.appendChild(n); });
			cfgPane.appendChild(grid);
		}

		function choose(k) {
			state.kit = k;
			state.cfg = KITS[k] ? KITS[k].def() : {};
			sync(); renderPicker(); renderConfig(); renderPreview();
		}

		function renderPicker() {
			picker.innerHTML = '';
			Object.keys(KITS).forEach(function (k) {
				var on = k === state.kit;
				picker.appendChild(el('button', {
					type: 'button',
					class: 'mata-kit' + (on ? ' is-on' : ''),
					'aria-pressed': String(on),
					onclick: function () { choose(k); }
				}, [
					el('span', { class: 'mata-picker-ic', 'aria-hidden': 'true' }, KITS[k].icon),
					el('span', { class: 'mata-picker-nm' }, KITS[k].name[GA ? 'ga' : 'en'])
				]));
			});
		}

		// An activity saved as external H5P content has no local kit to build.
		if (state.kit && !KITS[state.kit]) {
			root.appendChild(el('p', { class: 'mata-hint' }, t(
				'Tá an ghníomhaíocht seo nasctha le hábhar seachtrach, mar sin níl aon tógálaí áitiúil ann.',
				'This activity is linked to external content, so there is no local builder.')));
			return;
		}
		if (state.kit && !state.cfg) state.cfg = KITS[state.kit].def();

		root.appendChild(el('div', { class: 'mata-builder' }, [
			el('div', { class: 'mata-builder-side' }, [
				el('p', { class: 'mata-lbl' }, t('Cineál uirlise', 'Activity type')),
				picker, cfgPane, status
			]),
			el('div', { class: 'mata-builder-main' }, [
				el('p', { class: 'mata-lbl' }, t('Réamhamharc beo', 'Live preview')),
				stage
			])
		]));

		renderPicker(); renderConfig(); renderPreview();
	}

	function boot() {
		document.querySelectorAll('.mata-toolkit').forEach(mountToolkit);
		document.querySelectorAll('.mata-open').forEach(mountOpen);
		document.querySelectorAll('.mata-activity-mount').forEach(mountEmbedded);
		document.querySelectorAll('.mata-builder-mount').forEach(mountBuilder);
		wireDownloadCounter();
	}

	if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
	else boot();

	// Exposed for the automated tests in /tests.
	window.MataToolkit = {
		KITS: KITS, buildFile: buildFile, validateFile: validateFile,
		encodePayload: encodePayload, decodePayload: decodePayload, shareLink: shareLink
	};
})();
