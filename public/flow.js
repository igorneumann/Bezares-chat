/*
 * Bezares Chat — panel → Robot: lienzo con cajas y líneas, editor, avisos y chat de prueba (sin dependencias).
 * El chat de prueba repite la lógica de app/flow.php (go, answer, valid, branch, problems): si cambia allí, hay que cambiarla aquí.
 */
(function () {
  'use strict';
  var form = document.getElementById('flow');
  if (!form) return;

  var W = 190, NS = 'http://www.w3.org/2000/svg';
  var store = form.elements.graph, G = JSON.parse(store.value), saved = store.value;
  var wrap = form.querySelector('.fl-canvas'), size = form.querySelector('.fl-size'), world = form.querySelector('.fl-world'), svg = form.querySelector('.fl-lines');
  var edit = form.querySelector('.fl-edit'), sim = form.querySelector('.fl-sim'), status = form.querySelector('.fl-status');
  var sel = null, selLine = null, zoom = 1, drag = null, saving = false, hist = [], cur = '';
  var D = form.dataset;

  var TYPE = { start: 'Inicio', say: 'Mensaje', ask: 'Pregunta', cond: 'Bifurcación', end: 'Final', timer: 'Pasa un tiempo', ai: 'AI' };
  var ICON = { start: '▶', say: '💬', ask: '❓', cond: '🔀', end: '👤', timer: '⏱', ai: '✨' };
  var CHAN = { all: 'Todos', web: 'Web', whatsapp: 'WhatsApp', facebook: 'Facebook' }, TRIG = { no_agent: 'Nadie contesta al cliente', no_client: 'El cliente no responde al agente' };
  var WHEN = { always: 'Siempre', open: 'Solo en horario', closed: 'Solo fuera de horario' };
  function root(n) { return n.type === 'start' || n.type === 'timer'; } // cajas a las que no llegan líneas
  function span(m) { return m % 60 === 0 ? (m / 60) + (m === 60 ? ' hora' : ' horas') : m + (m === 1 ? ' minuto' : ' minutos'); }
  var KIND = { text: 'Cualquier texto', name: 'Su nombre', email: 'Su correo', number: 'Un número', phone: 'Un teléfono', options: 'Una de varias opciones' };
  var COND = { hours: 'Según el horario', channel: 'Según el canal', email: 'Según si tenemos su correo', name: 'Según si sabemos su nombre' };

  function h(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text !== undefined) n.textContent = text; return n; }
  function node(id) { return G.nodes.find(function (n) { return n.id === +id; }); }
  function edge(f, p) { return G.edges.find(function (e) { return e.f === f && e.p === String(p); }); }
  function labels(n) {
    if (n.type === 'end') return [];
    if (n.type === 'ai') return ['Más preguntas', 'No contesta'];
    if (n.type === 'cond') return n.cond === 'channel' ? ['Web', 'WhatsApp', 'Facebook'] : n.cond === 'hours' ? ['En horario', 'Cerrado'] : ['Sí', 'No'];
    if (n.type === 'ask' && n.kind === 'options') return n.opts_es.slice();
    return [''];
  }
  function ports(n) { // [[salida, texto], …]
    var l = labels(n).map(function (t, i) { return [String(i), t]; });
    if (n.type === 'ask' && n.kind !== 'text') l.push(['no', 'No responde bien']);
    return l;
  }
  function el(id) { return world.querySelector('.fl-n[data-id="' + id + '"]'); }

  /* ---------- historial y guardado ---------- */
  function snap() { return JSON.stringify(G); }
  function commit() {
    var s = snap();
    if (s === cur) return;
    if (cur) { hist.push(cur); if (hist.length > 80) hist.shift(); }
    cur = s;
    store.value = s;
    form.querySelector('.fl-dirty').hidden = s === saved;
    form.querySelector('[data-a="undo"]').disabled = !hist.length;
  }
  function undo() {
    if (!hist.length) return;
    cur = hist.pop();
    G = JSON.parse(cur);
    store.value = cur;
    if (sel && !node(sel)) sel = null;
    selLine = null;
    form.querySelector('.fl-dirty').hidden = cur === saved;
    form.querySelector('[data-a="undo"]').disabled = !hist.length;
    draw();
    panel();
  }
  var later = null;
  function typed() { clearTimeout(later); later = setTimeout(commit, 500); }

  /* ---------- avisos (los mismos que Flow::problems) ---------- */
  function problems() {
    var bad = [], start = G.nodes.find(function (n) { return n.type === 'start'; }), seen = {}, todo, end = false;
    if (!start) return [{ t: 'Falta la caja de inicio' }];
    todo = G.nodes.filter(root).map(function (n) { return n.id; });
    while (todo.length) {
      var id = todo.pop();
      if (seen[id]) continue;
      seen[id] = true;
      G.edges.forEach(function (e) { if (e.f === id) todo.push(e.t); });
    }
    G.nodes.forEach(function (n) {
      if (!seen[n.id]) { bad.push({ id: n.id, t: '«' + n.title + '»: no llega ninguna línea', warn: true }); return; }
      if (n.type === 'end') end = true;
      if ((n.type === 'say' || n.type === 'ask') && !n.es.trim()) bad.push({ id: n.id, t: '«' + n.title + '»: falta el texto' });
      if (n.type === 'ask' && n.kind === 'options' && n.opts_es.length < 2) bad.push({ id: n.id, t: '«' + n.title + '»: necesita al menos dos opciones' });
      if (labels(n).some(function (_, i) { return !edge(n.id, i); })) bad.push({ id: n.id, t: '«' + n.title + '»: tiene una salida sin línea' });
    });
    if (!end) bad.push({ t: 'Ningún camino llega a «Pasa a una persona»' });
    var color = {};
    function visit(id) {
      color[id] = 1;
      var loop = G.edges.some(function (e) {
        if (e.f !== id || node(e.t).type === 'ask') return false;
        return color[e.t] === 1 || (!color[e.t] && visit(e.t));
      });
      if (!loop) color[id] = 2;
      return loop;
    }
    if (Object.keys(seen).some(function (id) { return !color[id] && visit(+id); })) bad.push({ t: 'Hay un bucle sin ninguna pregunta: el robot no pararía de hablar' });
    return bad;
  }
  function check() {
    var bad = problems(), box = form.querySelector('.fl-warn');
    world.querySelectorAll('.fl-n').forEach(function (n) { n.classList.toggle('fl-bad', bad.some(function (b) { return b.id === +n.dataset.id && !b.warn; })); n.classList.toggle('fl-lost', bad.some(function (b) { return b.id === +n.dataset.id && b.warn; })); });
    box.textContent = '';
    bad.forEach(function (b) {
      var x = h('button', 'fl-w' + (b.warn ? ' fl-soft' : ''), (b.warn ? '○ ' : '⚠ ') + b.t);
      x.type = 'button';
      if (b.id) x.dataset.go = b.id;
      box.appendChild(x);
    });
    box.hidden = !bad.length;
    return bad;
  }

  /* ---------- dibujo ---------- */
  function sub(n) {
    if (n.type === 'say') return n.es || 'Sin texto';
    if (n.type === 'ask') return n.es || 'Sin texto';
    if (n.type === 'cond') return COND[n.cond];
    if (n.type === 'timer') return (n.trigger === 'no_agent' ? 'Nadie contesta en ' : 'El cliente no responde en ') + span(n.minutes) + (n.channel === 'all' ? '' : ' · ' + CHAN[n.channel]);
    if (n.type === 'ai') return 'Contesta y resuelve · hasta ' + n.turns + (n.turns > 1 ? ' respuestas' : ' respuesta');
    return n.type === 'start' ? 'Web, WhatsApp o Facebook' : 'El robot se calla';
  }
  function draw() {
    world.querySelectorAll('.fl-n').forEach(function (n) { n.remove(); });
    G.nodes.forEach(function (n) {
      var b = h('div', 'fl-n fl-' + n.type + (sel === n.id ? ' fl-sel' : ''));
      b.dataset.id = n.id;
      b.tabIndex = 0;
      b.setAttribute('role', 'button');
      b.setAttribute('aria-label', TYPE[n.type] + ': ' + n.title);
      b.style.left = n.x + 'px';
      b.style.top = n.y + 'px';
      b.appendChild(h('b', '', ICON[n.type] + ' ' + n.title));
      b.appendChild(h('span', '', sub(n)));
      var main = ports(n).filter(function (p) { return p[0] !== 'no'; });
      var row = h('div', 'fl-ports');
      main.forEach(function (p) {
        var c = h('div', 'fl-port');
        if (p[1]) c.appendChild(h('em', '', p[1]));
        var dot = h('i', edge(n.id, p[0]) ? '' : 'fl-free');
        dot.dataset.p = p[0];
        dot.title = 'Arrastra hasta otra caja';
        c.appendChild(dot);
        row.appendChild(c);
      });
      if (main.length) b.appendChild(row);
      if (ports(n).length > main.length) {
        var no = h('i', 'fl-no' + (edge(n.id, 'no') ? '' : ' fl-opt'));
        no.dataset.p = 'no';
        no.title = 'Si no responde bien (opcional)';
        b.appendChild(no);
      }
      world.appendChild(b);
    });
    lines();
    check();
  }
  // Punto (en coordenadas del lienzo) de una salida o de la entrada de una caja.
  function out(n, p) {
    var b = el(n.id), d = b.querySelector('i[data-p="' + p + '"]');
    if (!d) return null;
    var r = d.getBoundingClientRect(), w = world.getBoundingClientRect();
    return [(r.left + r.width / 2 - w.left) / zoom, (r.top + r.height / 2 - w.top) / zoom];
  }
  function curve(a, b, side) {
    if (side) { // sale por el lado (no responde bien)
      var s = Math.max(40, Math.abs(b[0] - a[0]) / 2);
      return 'M' + a + ' C' + [a[0] + s, a[1]] + ' ' + [b[0], b[1] - 50] + ' ' + b;
    }
    if (b[1] > a[1] + 10) {
      var k = Math.max(28, (b[1] - a[1]) / 2);
      return 'M' + a + ' C' + [a[0], a[1] + k] + ' ' + [b[0], b[1] - k] + ' ' + b;
    }
    var x = Math.max(a[0], b[0]) + W * 0.85, m = (a[1] + b[1]) / 2; // vuelve hacia arriba: rodea por la derecha
    return 'M' + a + ' C' + [a[0], a[1] + 60] + ' ' + [x, a[1] + 60] + ' ' + [x, m] + ' S' + [b[0], b[1] - 60] + ' ' + b;
  }
  function path(d, cls) { var p = document.createElementNS(NS, 'path'); p.setAttribute('d', d); p.setAttribute('class', cls); return p; }
  function lines(tmp) {
    var mx = 600, my = 400;
    G.nodes.forEach(function (n) { mx = Math.max(mx, n.x + W + 220); my = Math.max(my, n.y + 260); });
    svg.setAttribute('width', mx);
    svg.setAttribute('height', my);
    world.style.width = mx + 'px';
    world.style.height = my + 'px';
    size.style.width = mx * zoom + 'px';
    size.style.height = my * zoom + 'px';
    svg.querySelectorAll('g,.fl-tmp').forEach(function (x) { x.remove(); });
    G.edges.forEach(function (e) {
      var a = node(e.f), b = node(e.t), from = a && b && out(a, e.p);
      if (!from) return;
      var d = curve(from, [b.x + W / 2, b.y], e.p === 'no'), g = document.createElementNS(NS, 'g');
      g.setAttribute('class', 'fl-line' + (selLine === e ? ' fl-lsel' : '') + (e.p === 'no' ? ' fl-dash' : ''));
      g.appendChild(path(d, 'fl-vis'));
      g.appendChild(path(d, 'fl-hit'));
      g.edge = e;
      svg.appendChild(g);
    });
    if (tmp) svg.appendChild(path(tmp, 'fl-tmp'));
  }
  // Coloca las cajas: el camino principal y, a su derecha, el de cada caja de tiempo.
  function arrange() {
    var done = {}, x = 24, start = G.nodes.find(function (n) { return n.type === 'start'; });
    [start].concat(G.nodes.filter(function (n) { return n.type === 'timer'; })).forEach(function (r) {
      var ids = {}, todo = [r.id];
      while (todo.length) {
        var id = todo.pop();
        if (ids[id] || done[id]) continue;
        ids[id] = true;
        G.edges.forEach(function (e) { if (e.f === id) todo.push(e.t); });
      }
      Object.keys(ids).forEach(function (k) { done[k] = true; });
      x = column(r, G.nodes.filter(function (n) { return ids[n.id]; }), x) + 70;
    });
    var lost = G.nodes.filter(function (n) { return !done[n.id]; }), y = 24; // sueltas
    lost.forEach(function (n) { n.x = x; n.y = y; y += (el(n.id) ? el(n.id).offsetHeight : 80) + 40; });
  }
  // Coloca por niveles, de arriba abajo, las cajas $nodes que cuelgan de $first. Devuelve hasta dónde llega por la derecha.
  function column(first, nodes, x0) {
    var level = {}, kids = {}, back = {}, state = {}, inn = {}, edges;
    nodes.forEach(function (n) { kids[n.id] = []; inn[n.id] = true; });
    edges = G.edges.filter(function (e) { return inn[e.f] && inn[e.t]; });
    edges.forEach(function (e) { kids[e.f].push(e); });
    (function mark(id) { // líneas que vuelven hacia atrás: no cuentan para los niveles
      state[id] = 1;
      kids[id].forEach(function (e) { if (state[e.t] === 1) back[e.f + ':' + e.p] = true; else if (!state[e.t]) mark(e.t); });
      state[id] = 2;
    })(first.id);
    level[first.id] = 0;
    for (var i = 0; i < nodes.length; i++) {
      edges.forEach(function (e) { if (level[e.f] !== undefined && !back[e.f + ':' + e.p]) level[e.t] = Math.max(level[e.t] || 0, level[e.f] + 1); });
    }
    var rows = [], pos = {};
    nodes.forEach(function (n) { (rows[level[n.id]] = rows[level[n.id]] || []).push(n); });
    var gap = W + 40, y = 24, lo = 0, hi = 0;
    rows.forEach(function (r) {
      if (!r) return;
      r.forEach(function (n) { // centro de las cajas que llevan a ella, según la salida de la que viene
        var from = G.edges.filter(function (e) { return e.t === n.id && pos[e.f] !== undefined; });
        n.k = from.length ? from.reduce(function (s, e) { var c = labels(node(e.f)).length; return s + pos[e.f] + (e.p === 'no' ? 0.6 : (+e.p + 1) / (c + 1) - 0.5); }, 0) / from.length : 0;
      });
      r.sort(function (a, b) { return a.k - b.k; });
      // Cada caja, debajo de las que llevan a ella; si no caben, se separan y se centra la fila.
      var tall = 0, prev = -1e9, off = 0;
      r.forEach(function (n) { pos[n.id] = prev = Math.max(prev + 1, n.k); off += (n.k - prev) / r.length; });
      r.forEach(function (n) {
        pos[n.id] += off;
        delete n.k;
        lo = Math.min(lo, pos[n.id]);
        hi = Math.max(hi, pos[n.id]);
        n.y = y;
        var b = el(n.id);
        tall = Math.max(tall, b ? b.offsetHeight : 80);
      });
      y += tall + 48;
    });
    nodes.forEach(function (n) { n.x = Math.round(x0 + (pos[n.id] - lo) * gap); });
    return x0 + (hi - lo) * gap + W;
  }

  /* ---------- crear, unir y borrar ---------- */
  function newId() { return G.nodes.reduce(function (m, n) { return Math.max(m, n.id); }, 0) + 1; }
  function create(type, x, y) {
    if (G.nodes.length >= 80) { alert('Como máximo 80 cajas.'); return null; }
    var n = { id: newId(), type: type, title: { say: 'Nuevo mensaje', ask: 'Nueva pregunta', cond: '¿En horario?', end: 'Pasa a una persona', timer: 'Pasa un tiempo', ai: 'AI' }[type], x: Math.max(0, Math.round(x)), y: Math.max(0, Math.round(y)) };
    if (type === 'say' || type === 'ask') { n.es = ''; n.en = ''; }
    if (type === 'ask') { n.kind = 'text'; n.tries = 1; n.retry_es = n.retry_en = n.need_es = n.need_en = ''; n.opts_es = []; n.opts_en = []; }
    if (type === 'ai') n.turns = 5;
    if (type === 'cond') n.cond = 'hours';
    if (type === 'timer') { n.trigger = 'no_agent'; n.minutes = 30; n.channel = 'all'; n.when = 'always'; n.stop = 0; n.busy = 0; }
    G.nodes.push(n);
    return n;
  }
  function link(f, p, t) {
    if (root(node(t))) return;
    G.edges = G.edges.filter(function (e) { return !(e.f === f && e.p === String(p)); });
    G.edges.push({ f: f, p: String(p), t: t });
  }
  function unlink(e) { G.edges = G.edges.filter(function (x) { return x !== e; }); selLine = null; }
  function tidy(n) { // salidas que ya no existen
    var ok = ports(n).map(function (p) { return p[0]; });
    G.edges = G.edges.filter(function (e) { return e.f !== n.id || ok.indexOf(e.p) >= 0; });
  }
  function remove(n) {
    if (n.type === 'start') { alert('La caja de inicio no se puede borrar.'); return; }
    // Si tiene una sola salida, lo que llegaba a ella pasa a la siguiente: el camino no se corta.
    var outs = G.edges.filter(function (e) { return e.f === n.id && e.p !== 'no'; });
    var next = outs.length && outs[0].t !== n.id && outs.every(function (e) { return e.t === outs[0].t; }) ? outs[0].t : 0; // o varias que van a la misma caja
    G.edges.forEach(function (e) { if (e.t === n.id && e.f !== n.id && next) e.t = next; });
    G.edges = G.edges.filter(function (e) { return e.f !== n.id && e.t !== n.id; });
    G.nodes = G.nodes.filter(function (x) { return x !== n; });
    sel = null;
    after();
  }
  function copy(n) {
    if (G.nodes.length >= 80) { alert('Como máximo 80 cajas.'); return; }
    var c = JSON.parse(JSON.stringify(n));
    c.id = newId();
    c.title = (c.title + ' (copia)').slice(0, 60);
    c.x += 30;
    c.y += 30;
    G.nodes.push(c);
    choose(c.id);
    after();
  }
  function after() { commit(); draw(); panel(); }
  function choose(id) { sel = id; selLine = null; showTab('edit'); }
  function reveal(n) {
    var b = el(n.id);
    if (!b) return;
    wrap.scrollTo({ left: n.x * zoom - wrap.clientWidth / 2 + W / 2, top: n.y * zoom - wrap.clientHeight / 3, behavior: 'smooth' });
  }
  function menu(x, y, from) { // al soltar una línea en un hueco: qué caja crear
    closeMenu();
    var m = h('div', 'fl-menu');
    m.style.left = x + 'px';
    m.style.top = y + 'px';
    ['say', 'ask', 'cond', 'ai', 'end'].forEach(function (t) {
      var b = h('button', 'btn btn-secondary btn-sm', ICON[t] + ' ' + TYPE[t]);
      b.type = 'button';
      b.onclick = function () {
        var n = create(t, x - W / 2, y);
        closeMenu();
        if (!n) return;
        link(from.n, from.p, n.id);
        choose(n.id);
        after();
      };
      m.appendChild(b);
    });
    world.appendChild(m);
    m.firstChild.focus();
  }
  function closeMenu() { var m = world.querySelector('.fl-menu'); if (m) m.remove(); }

  /* ---------- ratón y teclado ---------- */
  function at(e) { var r = world.getBoundingClientRect(); return [(e.clientX - r.left) / zoom, (e.clientY - r.top) / zoom]; }
  function under(x, y) {
    return G.nodes.slice().reverse().find(function (n) { var b = el(n.id); return x >= n.x - 6 && x <= n.x + W + 6 && y >= n.y - 14 && y <= n.y + b.offsetHeight; });
  }
  wrap.addEventListener('pointerdown', function (e) {
    if (e.button || e.target.closest('.fl-menu')) return;
    closeMenu();
    var b = e.target.closest('.fl-n'), p = e.target.dataset && e.target.dataset.p, g = e.target.closest('g.fl-line'), xy = at(e);
    if (b && p !== undefined) drag = { k: 'line', n: +b.dataset.id, p: p };
    else if (b) { var n = node(b.dataset.id); drag = { k: 'node', n: n.id, dx: xy[0] - n.x, dy: xy[1] - n.y, x: e.clientX, y: e.clientY }; }
    else if (g) { selLine = g.edge; sel = null; showTab('edit'); draw(); panel(); return; }
    else { drag = { k: 'pan', x: e.clientX, y: e.clientY, l: wrap.scrollLeft, t: wrap.scrollTop }; }
    wrap.setPointerCapture(e.pointerId);
  });
  wrap.addEventListener('pointermove', function (e) {
    if (!drag) return;
    var xy = at(e);
    if (drag.k === 'pan') { wrap.scrollLeft = drag.l - (e.clientX - drag.x); wrap.scrollTop = drag.t - (e.clientY - drag.y); return; }
    if (drag.k === 'node') {
      if (!drag.moved && Math.abs(e.clientX - drag.x) + Math.abs(e.clientY - drag.y) < 5) return;
      drag.moved = true;
      var n = node(drag.n), b = el(n.id);
      n.x = Math.max(0, Math.round((xy[0] - drag.dx) / 8) * 8);
      n.y = Math.max(0, Math.round((xy[1] - drag.dy) / 8) * 8);
      b.style.left = n.x + 'px';
      b.style.top = n.y + 'px';
      lines();
      return;
    }
    var t = under(xy[0], xy[1]), src = node(drag.n);
    world.querySelectorAll('.fl-over').forEach(function (x) { x.classList.remove('fl-over'); });
    if (t && !root(t)) el(t.id).classList.add('fl-over');
    lines(curve(out(src, drag.p), xy, drag.p === 'no'));
  });
  wrap.addEventListener('pointerup', function (e) {
    var g = drag, xy = at(e);
    drag = null;
    if (!g) return;
    if (g.k === 'node') { choose(g.n); if (g.moved) after(); else { draw(); panel(); } return; }
    if (g.k === 'pan') { if (Math.abs(e.clientX - g.x) + Math.abs(e.clientY - g.y) < 4 && (sel || selLine)) { sel = selLine = null; draw(); panel(); } return; }
    var t = under(xy[0], xy[1]);
    if (t && !root(t)) { link(g.n, g.p, t.id); after(); } else { lines(); if (!t) menu(xy[0], xy[1], g); }
    world.querySelectorAll('.fl-over').forEach(function (x) { x.classList.remove('fl-over'); });
  });
  wrap.addEventListener('pointercancel', function () { drag = null; draw(); });
  wrap.addEventListener('keydown', function (e) {
    var b = e.target.closest('.fl-n');
    if (b && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); choose(+b.dataset.id); draw(); panel(); var f = edit.querySelector('input,textarea,select'); if (f) f.focus(); }
  });
  document.addEventListener('keydown', function (e) {
    var typing = /INPUT|TEXTAREA|SELECT/.test(e.target.tagName);
    if (e.key === 'Escape') { closeMenu(); return; }
    if (typing) return;
    if ((e.ctrlKey || e.metaKey) && e.key === 'z') { e.preventDefault(); undo(); }
    if (e.key === 'Delete' && selLine) { unlink(selLine); after(); } else if (e.key === 'Delete' && sel) remove(node(sel));
  });

  /* ---------- editor ---------- */
  function field(label, ctl, help) {
    var d = h('label', 'field fl-f');
    d.appendChild(h('span', 'label', label));
    d.appendChild(ctl);
    if (help) d.appendChild(h('small', '', help));
    return d;
  }
  function input(n, k, area, rows) {
    var i = h(area ? 'textarea' : 'input');
    if (area) { i.rows = rows || 3; i.maxLength = 1500; } else { i.type = 'text'; i.maxLength = 60; }
    i.value = n[k] || '';
    i.dataset.k = k;
    i.addEventListener('input', function () { n[k] = i.value; live(n); typed(); });
    return i;
  }
  function choice(value, opts, fn) {
    var s = h('select');
    Object.keys(opts).forEach(function (k) { var o = h('option', '', opts[k]); o.value = k; if (String(value) === k) o.selected = true; s.appendChild(o); });
    s.addEventListener('change', function () { fn(s.value); });
    return s;
  }
  function list(n, k, ph) {
    var t = h('textarea');
    t.rows = 4;
    t.placeholder = ph;
    t.value = n[k].join('\n');
    t.addEventListener('input', function () {
      n[k] = t.value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 12);
      if (k === 'opts_es') { tidy(n); targets(n); }
      live(n);
      typed();
    });
    return t;
  }
  // Refresca la caja y la vista previa sin rehacer el editor (para no perder el cursor).
  function live(n) { draw(); preview(n); }
  function vars() {
    var d = h('div', 'rb-vars'), last = null;
    edit.addEventListener('focusin', function (e) { if (e.target.tagName === 'TEXTAREA') last = e.target; });
    ['{name}', '{email}', '{company}'].forEach(function (v) {
      var b = h('button', 'btn btn-secondary btn-sm', '+ ' + v);
      b.type = 'button';
      b.onclick = function () { var a = last && edit.contains(last) ? last : edit.querySelector('textarea'); a.setRangeText(v, a.selectionStart, a.selectionEnd, 'end'); a.focus(); a.dispatchEvent(new Event('input')); };
      d.appendChild(b);
    });
    return d;
  }
  function full(n, x) { // texto tal como lo recibe el cliente (en español)
    var t = n.es || '';
    if (n.type === 'ask' && n.kind === 'options') n.opts_es.forEach(function (o, i) { t += '\n' + (i + 1) + '. ' + o; });
    return fill(t, x);
  }
  function fill(t, x) {
    var v = { name: x.name || '', email: x.email || '', company: D.company };
    return t.replace(/\{(name|email|company)\}/g, function (m, k) { return v[k]; }).replace(/[ \t]+([,.!?])/g, '$1').replace(/ {2,}/g, ' ').trim();
  }
  function preview(n) { var p = edit.querySelector('.rb-prev div'); if (p) p.textContent = full(n, { name: 'Marta', email: 'marta@empresa.es' }); }
  function targets(n) { // «Va a…» de cada salida: otra forma de unir, también con teclado
    var box = edit.querySelector('.fl-targets');
    if (!box) return;
    box.textContent = '';
    ports(n).forEach(function (p) {
      var opts = { '': '— sin línea —' };
      G.nodes.forEach(function (x) { if (!root(x)) opts[x.id] = ICON[x.type] + ' ' + x.title; });
      var e = edge(n.id, p[0]);
      box.appendChild(field(p[0] === 'no' ? 'Si no responde bien (opcional)' : p[1] || 'Después', choice(e ? e.t : '', opts, function (v) {
        if (v) link(n.id, p[0], +v); else { var o = edge(n.id, p[0]); if (o) unlink(o); }
        commit();
        draw();
      })));
    });
  }
  function act(label, cls, fn) { var b = h('button', 'btn btn-sm ' + cls, label); b.type = 'button'; b.onclick = fn; return b; }
  function panel() {
    edit.textContent = '';
    if (selLine) {
      edit.appendChild(h('h2', '', 'Línea'));
      edit.appendChild(h('p', 'muted', 'De «' + node(selLine.f).title + '» a «' + node(selLine.t).title + '».'));
      edit.appendChild(act('🗑 Borrar línea', 'btn-danger', function () { unlink(selLine); after(); }));
      return;
    }
    var n = sel && node(sel);
    if (!n) {
      edit.appendChild(h('h2', '', 'Cómo se dibuja'));
      var ul = h('ul', 'fl-help');
      ['Las cajas ⏱ empiezan solas cuando pasa un tiempo, por ejemplo el aviso de 23 horas de WhatsApp.', 'Pulsa una caja para editarla y arrástrala para moverla.', 'Arrastra desde un punto azul hasta otra caja para unirlas. Si lo sueltas en un hueco, creas una caja nueva.', 'Una pregunta con opciones tiene una salida por opción: así se bifurca.', 'Para unir caminos, lleva varias líneas a la misma caja.', 'Pulsa una línea para borrarla (o Supr).', 'El punto naranja es opcional: adónde va si no responde bien.', 'Si contesta un agente, el robot se calla.'].forEach(function (t) { ul.appendChild(h('li', '', t)); });
      edit.appendChild(ul);
      return;
    }
    edit.appendChild(h('h2', '', ICON[n.type] + ' ' + TYPE[n.type]));
    if (n.type === 'start') {
      edit.appendChild(h('p', 'muted', 'Empieza con el primer mensaje del cliente. En WhatsApp y Facebook el nombre se toma del perfil.'));
    } else {
      var acts = h('div', 'rb-acts');
      acts.appendChild(act('Duplicar', 'btn-secondary', function () { copy(n); }));
      acts.appendChild(act('🗑 Borrar', 'btn-danger', function () { remove(n); }));
      edit.appendChild(acts);
      edit.appendChild(field('Nombre', input(n, 'title'), n.type === 'ask' && n.kind !== 'name' && n.kind !== 'email' ? 'Es también el nombre del dato en la ficha del cliente.' : ''));
    }
    if (n.type === 'timer') {
      edit.appendChild(h('p', 'muted', 'Empieza sola cuando pasa el tiempo, una vez por cada espera. Necesita el cron del servidor o algún programa de Windows conectado.'));
      edit.appendChild(field('Cuándo', choice(n.trigger, TRIG, function (v) { n.trigger = v; after(); })));
      var tm = h('div', 'rb-time'), am = h('input'), un;
      am.type = 'number';
      am.min = 1;
      am.value = n.minutes % 60 === 0 ? n.minutes / 60 : n.minutes;
      var setTime = function () { n.minutes = Math.max(1, Math.min(1380, (parseInt(am.value, 10) || 1) * +un.value)); draw(); typed(); };
      un = choice(n.minutes % 60 === 0 ? '60' : '1', { 1: 'minutos', 60: 'horas' }, setTime);
      am.addEventListener('input', setTime);
      am.setAttribute('aria-label', 'Cantidad');
      un.setAttribute('aria-label', 'Unidad');
      tm.appendChild(am);
      tm.appendChild(un);
      var tf = h('div', 'field fl-f');
      tf.appendChild(h('span', 'label', 'Tiempo'));
      tf.appendChild(tm);
      tf.appendChild(h('small', '', 'Como máximo 23 horas: a las 24 h WhatsApp y Facebook ya no dejan escribir.'));
      edit.appendChild(tf);
      var tg = h('div', 'grid2');
      tg.appendChild(field('Canal', choice(n.channel, CHAN, function (v) { n.channel = v; after(); })));
      tg.appendChild(field('Horario', choice(n.when, WHEN, function (v) { n.when = v; after(); })));
      edit.appendChild(tg);
      [['stop', 'Deja de contar el tiempo de respuesta', 'Si el mensaje se entrega. Para avisar de que se contestará por correo.'], ['busy', 'También si el robot espera una respuesta', 'Desactivado: solo cuando el robot ya ha terminado.']].forEach(function (o) {
        var l = h('label', 'switch'), c = h('input'), t = h('span');
        c.type = 'checkbox';
        c.setAttribute('role', 'switch');
        c.checked = !!n[o[0]];
        c.addEventListener('change', function () { n[o[0]] = c.checked ? 1 : 0; commit(); });
        t.appendChild(h('b', '', o[1]));
        t.appendChild(h('small', '', o[2]));
        l.appendChild(c);
        l.appendChild(t);
        edit.appendChild(l);
      });
    }
    if (n.type === 'ai') {
      edit.appendChild(h('p', 'muted', 'La AI contesta con la instrucción y los recursos de la página AI. Al contestar pregunta si puede ayudar en algo más y marca el chat como resuelto. Si el cliente vuelve a escribir, se reabre y contesta otra vez; si solo se despide, queda resuelto de nuevo. Si no debe o no sabe contestar, no dice nada y sigue por «No contesta».'));
      var tn = h('input');
      tn.type = 'number';
      tn.min = 1;
      tn.max = 10;
      tn.value = n.turns;
      tn.addEventListener('input', function () { n.turns = Math.max(1, Math.min(10, parseInt(tn.value, 10) || 1)); draw(); typed(); });
      edit.appendChild(field('Respuestas como máximo', tn, 'Si el cliente sigue preguntando después de la última, va por «Más preguntas».'));
    }
    if (n.type === 'cond') edit.appendChild(field('Cómo se bifurca', choice(n.cond, COND, function (v) { n.cond = v; tidy(n); after(); })));
    if (n.type === 'ask') {
      edit.appendChild(field('Qué espera', choice(n.kind, KIND, function (v) { n.kind = v; if (v === 'email' && n.tries < 2) n.tries = 6; tidy(n); after(); }),
        { name: 'Si ya lo sabemos, se salta.', email: 'Si ya lo tenemos, se salta.' }[n.kind] || ''));
    }
    if (n.type === 'ask' && n.kind === 'options') {
      var g = h('div', 'grid2');
      g.appendChild(field('Opciones (español)', list(n, 'opts_es', 'Una por línea')));
      g.appendChild(field('Opciones (inglés)', list(n, 'opts_en', 'Opcional')));
      edit.appendChild(g);
    }
    if (n.type === 'say' || n.type === 'ask') {
      edit.appendChild(field('Español', input(n, 'es', true)));
      edit.appendChild(field('Inglés (opcional)', input(n, 'en', true), 'Si lo dejas vacío, se traduce solo desde el español.'));
      edit.appendChild(vars());
      var p = h('div', 'rb-prev');
      p.appendChild(h('span', '', 'Así lo ve el cliente'));
      p.appendChild(h('div'));
      edit.appendChild(p);
      preview(n);
    }
    if (n.type === 'ask' && n.kind !== 'text') {
      var d = h('details', 'fl-more'), s = h('summary', '', 'Si no responde bien');
      d.appendChild(s);
      var num = h('input');
      num.type = 'number';
      num.min = 1;
      num.max = 20;
      num.value = n.tries;
      num.addEventListener('input', function () { n.tries = Math.max(1, Math.min(20, parseInt(num.value, 10) || 1)); typed(); });
      d.appendChild(field('Veces que insiste', num, n.kind === 'email' ? 'Después sigue por el punto naranja o, si no tiene línea, se calla y espera.' : 'Después sigue por el punto naranja o, si no tiene línea, acepta lo que diga.'));
      d.appendChild(field(n.kind === 'email' ? 'Correo mal escrito (español)' : 'Qué le dice (español)', input(n, 'retry_es', true, 2), 'Vacío: repite la pregunta.'));
      d.appendChild(field('Inglés (opcional)', input(n, 'retry_en', true, 2)));
      if (n.kind === 'email') {
        d.appendChild(field('Si contesta otra cosa (español)', input(n, 'need_es', true, 2)));
        d.appendChild(field('Inglés (opcional)', input(n, 'need_en', true, 2)));
      }
      edit.appendChild(d);
    }
    if (n.type !== 'end') {
      edit.appendChild(h('h3', 'fl-h3', 'Adónde va después'));
      edit.appendChild(h('div', 'fl-targets'));
      targets(n);
    } else edit.appendChild(h('p', 'muted', 'El robot termina y espera a que conteste un agente. Puede haber varios finales.'));
  }

  /* ---------- chat de prueba ---------- */
  var S = null;
  function simLine(cls, t) {
    var b = h('div', 'fl-b ' + cls, t), box = sim.querySelector('.fl-log');
    box.appendChild(b);
    box.scrollTop = box.scrollHeight;
  }
  function mark(id) { world.querySelectorAll('.fl-live').forEach(function (x) { x.classList.remove('fl-live'); }); var b = id && el(id); if (b) { b.classList.add('fl-live'); reveal(node(id)); } }
  function simReset() {
    sim.querySelector('.fl-log').textContent = '';
    var ch = sim.querySelector('[data-s="channel"]').value;
    S = { channel: ch, open: sim.querySelector('[data-s="hours"]').value === '1', name: ch === 'web' ? '' : 'Marta', email: '', node: 0, tries: 0, done: false, fresh: true };
    simLine('fl-note', 'Escribe como si fueras el cliente.');
    mark(0);
    // Un botón por cada caja de tiempo del canal elegido.
    var box = sim.querySelector('.fl-timers');
    box.textContent = '';
    G.nodes.forEach(function (n) {
      if (n.type !== 'timer' || (n.channel !== 'all' && n.channel !== ch)) return;
      box.appendChild(act('⏱ ' + (n.trigger === 'no_agent' ? 'Nadie contesta en ' : 'No responde en ') + span(n.minutes), 'btn-secondary', function () { simTimer(n); }));
    });
  }
  function simTimer(n) {
    if (S.fresh) { simLine('fl-note', 'Primero escribe un mensaje como cliente.'); return; }
    simLine('fl-note', '⏱ Pasan ' + span(n.minutes) + '.');
    if (n.when !== 'always' && (n.when === 'open') !== S.open) { simLine('fl-note', 'No se aplica: ' + WHEN[n.when].toLowerCase() + '.'); return; }
    if (!n.busy && !S.done) { simLine('fl-note', 'No se aplica: el robot todavía espera una respuesta.'); return; }
    var was = { node: S.node, tries: S.tries, done: S.done }, e = edge(n.id, 0);
    S.done = false;
    S.node = 0;
    simGo(e ? e.t : 0);
    if (n.stop) simLine('fl-note', 'Se deja de contar el tiempo de respuesta.');
    if (S.done) { S.node = was.node; S.tries = was.tries; S.done = was.done; } // si esperaba una respuesta, la sigue esperando
  }
  function simGo(id) {
    for (var i = 0; i < 60; i++) {
      var n = node(id), p = '0';
      if (!n) break;
      if (n.type === 'say') simLine('fl-bot', full(n, S));
      else if (n.type === 'ask') {
        if (!(n.kind === 'email' && S.email) && !(n.kind === 'name' && S.name)) { S.node = n.id; S.tries = 0; simLine('fl-bot', full(n, S)); mark(n.id); return; }
      } else if (n.type === 'cond') {
        p = n.cond === 'channel' ? String(['web', 'whatsapp', 'facebook'].indexOf(S.channel)) : n.cond === 'hours' ? (S.open ? '0' : '1') : n.cond === 'email' ? (S.email ? '0' : '1') : (S.name ? '0' : '1');
      } else if (n.type === 'end') { simEnd(n.id); return; }
      else if (n.type === 'ai') { simAi(n); return; }
      var e = edge(n.id, p);
      id = e ? e.t : 0;
    }
    simEnd(0);
  }
  // Aquí no se consulta a OpenAI: se elige qué pasaría (la prueba de verdad está en la página AI).
  function simAi(n) {
    if (S.node !== n.id) { S.node = n.id; S.tries = 0; }
    if (S.tries) simLine('fl-note', 'El chat se reabre.');
    S.ai = true;
    mark(n.id);
    simLine('fl-note', '✨ La AI lee la conversación. ¿Qué pasa?');
    var box = sim.querySelector('.fl-log'), row = h('div', 'fl-timers fl-aiq'), over = S.tries >= n.turns;
    (S.tries ? [over ? 'Sigue preguntando' : 'Contesta', 'No contesta', 'El cliente se despide'] : ['Contesta', 'No contesta']).forEach(function (t, p) {
      row.appendChild(act(t, 'btn-secondary', function () {
        row.remove();
        S.ai = false;
        if (p === 2 || (!p && !over)) {
          if (!p) { S.tries++; simLine('fl-bot', '(respuesta de la AI)\n\n¿Puedo ayudarle en algo más?'); }
          simLine('fl-note', 'Se marca como resuelta. Si el cliente escribe, se reabre.');
          return; // espera otro mensaje
        }
        var e = edge(n.id, p);
        S.node = 0;
        simGo(e ? e.t : 0);
      }));
    });
    box.appendChild(row);
    box.scrollTop = box.scrollHeight;
  }
  function simEnd(id) { S.done = true; S.node = 0; simLine('fl-note', id ? '«' + node(id).title + '»: el robot termina y espera a un agente.' : 'El camino termina aquí (salida sin línea).'); mark(id); }
  function mailIn(t) { var m = /[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i.exec(t); return m ? m[0].toLowerCase() : ''; }
  function simName(t, mail) {
    if (mail) t = t.replace(new RegExp(mail.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'), 'ig'), ' ').replace(/(y\s+)?(mi|my)\s+(correo|email|e-mail)(\s+(es|is))?\s*:?/ig, ' ');
    return t.replace(/\s+/g, ' ').replace(/^[ ,;:\-<>()]+|[ ,;:\-<>()]+$/g, '').replace(/^(me llamo|soy|mi nombre es|my name is|i am|i'm)\s+/i, '');
  }
  function option(n, t) {
    var m = /^\D{0,3}(\d{1,2})\D{0,3}$/.exec(t);
    if (m && n.opts_es[+m[1] - 1] !== undefined) return +m[1] - 1;
    var i = n.opts_es.findIndex(function (o) { return o.toLowerCase() === t.toLowerCase(); });
    return i >= 0 ? i : n.opts_en.findIndex(function (o, k) { return o.toLowerCase() === t.toLowerCase() && n.opts_es[k] !== undefined; });
  }
  function valid(n, t, mail) {
    t = t.trim().replace(/\s+/g, ' ');
    if (n.kind === 'email') return [!!mail, mail, '0'];
    if (n.kind === 'name') { var nm = simName(t, mail); return [!!(nm || mail), nm, '0']; }
    if (n.kind === 'number') return [/\d/.test(t), t, '0'];
    if (n.kind === 'phone') return [t.replace(/\D/g, '').length >= 6, t, '0'];
    if (n.kind === 'options') { var i = option(n, t); return [i >= 0, i >= 0 ? n.opts_es[i] : '', String(Math.max(0, i))]; }
    return [true, t, '0'];
  }
  function simSay(t) {
    simLine('fl-me', t);
    var mail = mailIn(t);
    if (S.fresh) {
      S.fresh = false;
      if (mail) S.email = mail;
      var st = G.nodes.find(function (n) { return n.type === 'start'; }), e0 = edge(st.id, 0);
      simGo(e0 ? e0.t : 0);
      return;
    }
    if (S.done) { simLine('fl-note', 'El robot ya ha terminado: contestaría un agente.'); return; }
    var n = node(S.node);
    if (n && n.type === 'ai') {
      if (mail && !S.email) S.email = mail;
      if (!S.ai) simAi(n); // si aún está pensando, no se la interrumpe
      return;
    }
    if (!n || n.type !== 'ask') { simEnd(0); return; }
    if (mail && (!S.email || n.kind === 'email')) S.email = mail;
    var v = valid(n, t, mail);
    if (!v[0]) {
      var bad = t.indexOf('@') >= 0;
      if (S.tries < n.tries) {
        S.tries++;
        simLine('fl-bot', n.kind === 'email' && !bad && n.need_es ? fill(n.need_es, S) : n.retry_es ? fill(n.retry_es, S) : full(n, S));
        return;
      }
      var no = edge(n.id, 'no');
      if (no) { simGo(no.t); return; }
      if (n.kind === 'email') { simLine('fl-note', 'El robot se calla hasta que dé un correo válido.'); return; }
      v = [true, t, '0'];
    }
    if (n.kind === 'name') { if (v[1]) S.name = v[1]; } else if (n.kind !== 'email') simLine('fl-note', 'Se guarda en la ficha: ' + n.title + ' = ' + v[1]);
    var e = edge(n.id, v[2]);
    simGo(e ? e.t : 0);
  }
  sim.querySelectorAll('select').forEach(function (s) { s.addEventListener('change', simReset); });
  sim.querySelector('[data-a="simreset"]').addEventListener('click', simReset);
  function simSend() { var i = sim.querySelector('.fl-in'), t = i.value.trim(); if (!t) return; i.value = ''; simSay(t); }
  sim.querySelector('[data-a="simsend"]').addEventListener('click', simSend);
  sim.querySelector('.fl-in').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); simSend(); } });

  /* ---------- barra ---------- */
  function showTab(t) {
    edit.hidden = t !== 'edit';
    sim.hidden = t !== 'sim';
    form.querySelector('[data-a="test"]').classList.toggle('on', t === 'sim');
    if (t !== 'sim') mark(0);
  }
  function setZoom(z) { zoom = Math.max(0.5, Math.min(1.2, Math.round(z * 10) / 10)); world.style.transform = 'scale(' + zoom + ')'; lines(); }
  form.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.go) { var t = node(b.dataset.go); choose(t.id); draw(); panel(); reveal(t); return; }
    if (b.dataset.add) {
      var n = create(b.dataset.add, (wrap.scrollLeft + wrap.clientWidth / 2) / zoom - W / 2, (wrap.scrollTop + wrap.clientHeight / 3) / zoom);
      if (n) { choose(n.id); after(); var f = edit.querySelector('input'); if (f) { f.focus(); f.select(); } }
      return;
    }
    switch (b.dataset.a) {
      case 'undo': undo(); break;
      case 'arrange': arrange(); after(); wrap.scrollTo(0, 0); break;
      case 'zin': setZoom(zoom + 0.1); break;
      case 'zout': setZoom(zoom - 0.1); break;
      case 'test': if (sim.hidden) { showTab('sim'); simReset(); sim.querySelector('.fl-in').focus(); } else showTab('edit'); break;
    }
  });
  // Intro en un campo no debe enviar el formulario.
  form.addEventListener('keydown', function (e) { if (e.key === 'Enter' && e.target.tagName === 'INPUT') e.preventDefault(); });
  form.addEventListener('submit', function (e) {
    clearTimeout(later);
    commit();
    var a = e.submitter && e.submitter.value;
    if (a === 'publish') {
      var bad = check().filter(function (b) { return !b.warn; });
      if (bad.length) { e.preventDefault(); alert('No se puede publicar todavía:\n\n' + bad.slice(0, 6).map(function (b) { return '· ' + b.t; }).join('\n')); return; }
      if (!confirm('Se publicará este diagrama para los chats nuevos. Los que están a medias terminan con el robot con el que empezaron.\n\n¿Publicar?')) { e.preventDefault(); return; }
    }
    saving = true;
  });
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); else saving = true; });
  });
  window.addEventListener('beforeunload', function (e) { if (!saving && snap() !== saved) { e.preventDefault(); e.returnValue = ''; } });

  draw();
  if (G.nodes.every(function (n) { return !n.x && !n.y; })) { arrange(); draw(); }
  cur = snap();
  saved = cur;
  store.value = cur;
  panel();
  // Todo el ancho a la vista al abrir.
  var mx = G.nodes.reduce(function (m, n) { return Math.max(m, n.x + W); }, 0);
  if (wrap.clientWidth > 100 && mx + 30 > wrap.clientWidth) setZoom(Math.max(0.7, wrap.clientWidth / (mx + 30)));
})();
