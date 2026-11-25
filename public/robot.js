/* Bezares Chat — panel → Robot: diagrama de flujo, editor, pasos propios y vista previa (sin dependencias). */
(function () {
  'use strict';
  var robot = document.getElementById('robot');
  if (!robot) return;

  var store = robot.elements.robot_steps;      // JSON de los pasos propios que se envía al guardar
  var data = JSON.parse(store.value), initial = store.value;
  var FLOW = ['before', 'data', 'end'];
  var dirty = false, saving = false, lang = {}, current = robot.elements.step.value;
  var custom = robot.querySelector('#step-custom'), first = robot.querySelector('.rb-step').dataset.key;

  function el(name) { return robot.elements[name]; }
  function node(tag, cls, text) { var n = document.createElement(tag); if (cls) n.className = cls; if (text) n.textContent = text; return n; }
  function timed(slot) { return FLOW.indexOf(slot) < 0; }
  function byKey(key) { var m = /^c(\d+)$/.exec(key || ''); return m ? data.steps.find(function (s) { return s.id === +m[1]; }) : null; }
  function inSlot(slot) {
    var l = data.steps.filter(function (s) { return s.slot === slot; });
    return timed(slot) ? l.sort(function (a, b) { return a.minutes - b.minutes; }) : l;
  }
  function span(min) { return min % 60 === 0 ? (min / 60) + (min === 60 ? ' hora' : ' horas') : min + (min === 1 ? ' minuto' : ' minutos'); }
  function setDirty(v) { dirty = v; robot.querySelector('.rb-dirty').hidden = !v; }
  function save() { store.value = JSON.stringify(data); setDirty(true); }

  /* ---------- diagrama: pasos propios ---------- */
  var CH = { web: 'solo web', whatsapp: 'solo WhatsApp', facebook: 'solo Facebook' }, WH = { open: 'en horario', closed: 'fuera de horario' };
  function detail(s) {
    var d = [];
    d.push(timed(s.slot) ? 'Tras ' + span(s.minutes) : s.type === 'question' ? 'Pregunta' : 'Mensaje');
    if (CH[s.channel] && s.slot !== 'before') d.push(CH[s.channel]);
    if (WH[s.when]) d.push(WH[s.when]);
    return d.join(', ');
  }
  function stepNode(s) {
    var b = node('button', 'rb-n rb-own' + (s.on && s.es ? '' : ' rb-off') + (current === 'c' + s.id ? ' rb-sel' : ''));
    b.type = 'button';
    b.dataset.step = 'c' + s.id;
    b.draggable = true;
    b.appendChild(node('b', '', (s.type === 'question' ? '❓ ' : '💬 ') + s.title));
    b.appendChild(node('small', '', detail(s)));
    b.appendChild(node('em', '', s.es ? 'Desactivado' : 'Falta el texto'));
    return b;
  }
  function plus(slot, index) {
    var w = node('div', 'rb-add'), b = node('button', 'rb-plus', '+');
    b.type = 'button';
    b.title = 'Añadir un paso aquí';
    b.setAttribute('aria-label', 'Añadir un paso aquí');
    b.dataset.slot = slot;
    b.dataset.index = index;
    w.dataset.slot = slot;
    w.dataset.index = index;
    w.appendChild(b);
    return w;
  }
  function draw() {
    robot.querySelectorAll('.rb-slot').forEach(function (box) {
      var slot = box.dataset.slot, list = inSlot(slot);
      box.textContent = '';
      if (timed(slot)) {
        list.forEach(function (s) { box.appendChild(node('div', 'rb-v')); box.appendChild(stepNode(s)); });
        var p = plus(slot, list.length);
        p.classList.add('rb-last');
        box.appendChild(p);
        return;
      }
      list.forEach(function (s, i) { box.appendChild(plus(slot, i)); box.appendChild(stepNode(s)); });
      box.appendChild(plus(slot, list.length));
    });
  }
  // Coloca un paso en la posición $index de un hueco (los pasos se guardan en una sola lista ordenada).
  function place(s, slot, index) {
    var rest = data.steps.filter(function (x) { return x !== s; }), same = rest.filter(function (x) { return x.slot === slot; });
    s.slot = slot;
    if (timed(slot)) { s.type = 'message'; if (!s.minutes) s.minutes = 30; } else if (slot === 'before') s.channel = 'web';
    if (index >= same.length) rest.push(s); else rest.splice(rest.indexOf(same[index]), 0, s);
    data.steps = rest;
  }
  function add(slot, index, type) {
    if (data.steps.length >= 40) { alert('Como máximo 40 pasos propios.'); return; }
    var s = { id: data.next++, slot: slot, type: type, title: type === 'question' ? 'Nueva pregunta' : 'Nuevo mensaje', on: 1, channel: slot === 'before' ? 'web' : 'all', when: 'always',
      es: '', en: '', kind: 'text', opts_es: [], opts_en: [], minutes: timed(slot) ? 30 : 0, since: 0 };
    place(s, slot, index);
    save();
    show('c' + s.id, true);
    var t = field('title');
    t.focus();
    t.select();
  }
  function closeMenu() { var m = robot.querySelector('.rb-menu'); if (m) m.remove(); }
  function openMenu(btn) {
    var had = btn.parentNode.querySelector('.rb-menu');
    closeMenu();
    if (had) return;
    if (timed(btn.dataset.slot)) { add(btn.dataset.slot, +btn.dataset.index, 'message'); return; }
    var m = node('div', 'rb-menu');
    [['message', '💬 Mensaje'], ['question', '❓ Pregunta']].forEach(function (o) {
      var b = node('button', 'btn btn-secondary btn-sm', o[1]);
      b.type = 'button';
      b.dataset.add = o[0];
      m.appendChild(b);
    });
    btn.parentNode.appendChild(m);
    m.firstChild.focus();
  }

  /* ---------- editor ---------- */
  function field(f) { return custom.querySelector('[data-f="' + f + '"]'); }
  function fill(s) {
    ['title', 'slot', 'type', 'kind', 'channel', 'when', 'es', 'en'].forEach(function (f) { field(f).value = s[f]; });
    field('on').checked = !!s.on;
    field('opts_es').value = (s.opts_es || []).join('\n');
    field('opts_en').value = (s.opts_en || []).join('\n');
    var h = s.minutes && s.minutes % 60 === 0;
    field('unit').value = h ? '60' : '1';
    field('amount').value = h ? s.minutes / 60 : s.minutes || 30;
    rules(s);
  }
  // Qué campos tienen sentido para ese paso.
  function rules(s) {
    var t = timed(s.slot), q = s.type === 'question';
    var vis = { type: !t, kind: q, opts: q && s.kind === 'options', time: t, channel: s.slot !== 'before' };
    Object.keys(vis).forEach(function (k) { custom.querySelector('[data-row="' + k + '"]').hidden = !vis[k]; });
    custom.querySelector('.rb-when').textContent = { before: 'Solo en la web: después de dar su nombre y antes de pedirle el correo.', data: 'Al recibir un correo válido, antes de «Gracias» o «Fuera de horario».',
      end: 'Después de «Gracias» o «Fuera de horario».', no_agent: 'Si nadie le contesta en este tiempo. Una vez por cada espera.', no_client: 'Si el cliente no responde al agente en este tiempo. Una vez por cada espera.' }[s.slot]
      + (q ? ' Espera la respuesta, la guarda en la ficha del cliente y la anota en el chat. Si contesta un agente, el robot se calla.' : '');
    var l = inSlot(s.slot), i = l.indexOf(s);
    custom.querySelector('[data-do="up"]').disabled = t || i === 0;
    custom.querySelector('[data-do="down"]').disabled = t || i === l.length - 1;
    field('amount').max = field('unit').value === '60' ? 23 : 1380;
  }
  function read(s) {
    ['title', 'type', 'kind', 'channel', 'when', 'es', 'en'].forEach(function (f) { s[f] = field(f).value; });
    s.on = field('on').checked ? 1 : 0;
    var lines = function (f) { return field(f).value.split('\n').map(function (x) { return x.trim(); }).filter(Boolean).slice(0, 12); };
    s.opts_es = lines('opts_es');
    s.opts_en = lines('opts_en');
    if (field('slot').value !== s.slot) { place(s, field('slot').value, 999); fill(s); return; } // se ha cambiado de sitio
    if (timed(s.slot)) s.minutes = Math.max(1, Math.min(1380, (parseInt(field('amount').value, 10) || 1) * +field('unit').value));
    rules(s);
  }
  function show(key, scroll) {
    var own = byKey(key);
    if (/^c\d+$/.test(key) && !own || !own && !robot.querySelector('.rb-step[data-key="' + key + '"]')) { key = first; own = null; }
    current = key;
    closeMenu();
    if (own) { lang.own = 0; fill(own); }
    robot.querySelectorAll('.rb-step').forEach(function (s) { s.classList.toggle('rb-show', own ? s === custom : s.dataset.key === key); });
    draw();
    robot.querySelectorAll('button[data-step]').forEach(function (b) {
      var on = b.dataset.step === key;
      b.classList.toggle('rb-sel', on);
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
    });
    el('step').value = key;
    var edit = robot.querySelector('.rb-edit');
    edit.scrollTop = 0;
    refresh();
    if (scroll && edit.getBoundingClientRect().top > window.innerHeight * 0.6) edit.scrollIntoView({ block: 'start' }); // pantalla estrecha: el editor está debajo
  }
  function refresh() {
    robot.querySelectorAll('button[data-on]').forEach(function (b) { b.classList.toggle('rb-off', !el(b.dataset.on).checked); });
    robot.querySelectorAll('small[data-tpl]').forEach(function (s) {
      s.textContent = s.dataset.tpl.replace(/\{(\w+)\}/g, function (m, k) { return el(k) ? el(k).value : m; });
    });
    robot.querySelectorAll('.rb-msg').forEach(function (box) {
      var areas = box.querySelectorAll('textarea.rb-t'), id = areas[0].id || 'own', en = lang[id] === 1, d = robot.dataset, own = id === 'own' ? byKey(current) : null;
      var vars = { name: 'Marta', email: 'marta@empresa.es', company: d.company, hours: en ? d.hoursEn : d.hours };
      var text = areas[en ? 1 : 0].value.trim();
      if (own && own.type === 'question' && own.kind === 'options') (en && own.opts_en.length === own.opts_es.length ? own.opts_en : own.opts_es).forEach(function (o, i) { text += '\n' + (i + 1) + '. ' + o; });
      box.querySelector('.rb-prev div').textContent = text.replace(/\{(name|email|company|hours)\}/g, function (m, k) { return vars[k]; }).replace(/[ \t]+([,.!?])/g, '$1');
    });
  }

  /* ---------- sucesos ---------- */
  robot.addEventListener('click', function (e) {
    var b = e.target.closest('button');
    if (!b) { closeMenu(); return; }
    if (b.classList.contains('rb-plus')) { openMenu(b); return; }
    if (b.dataset.add) { var p = b.closest('.rb-add'); add(p.dataset.slot, +p.dataset.index, b.dataset.add); return; }
    if (b.dataset.step) { show(b.dataset.step, true); return; }
    if (b.dataset.insert) {
      var box = b.closest('.rb-msg'), areas = box.querySelectorAll('textarea.rb-t'), id = areas[0].id || 'own', a = areas[lang[id] === 1 ? 1 : 0];
      a.setRangeText(b.dataset.insert, a.selectionStart, a.selectionEnd, 'end');
      a.focus();
      a.dispatchEvent(new Event('input', { bubbles: true }));
      return;
    }
    var s = b.dataset.do && byKey(current);
    if (!s) return;
    var l = inSlot(s.slot), i = l.indexOf(s);
    if (b.dataset.do === 'up') place(s, s.slot, i - 1);
    else if (b.dataset.do === 'down') place(s, s.slot, i + 1);
    else if (b.dataset.do === 'copy') {
      if (data.steps.length >= 40) return;
      var c = JSON.parse(JSON.stringify(s));
      c.id = data.next++;
      c.title = (c.title + ' (copia)').slice(0, 60);
      place(c, s.slot, i + 1);
      save();
      show('c' + c.id, false);
      return;
    } else if (b.dataset.do === 'del') {
      if (!confirm('¿Eliminar «' + s.title + '» del diagrama?\n\nSe aplica al guardar.')) return;
      data.steps = data.steps.filter(function (x) { return x !== s; });
      save();
      show(first, false);
      return;
    }
    save();
    show(current, false);
  });
  robot.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeMenu();
    if (e.key === 'Enter' && e.target.tagName === 'INPUT' && custom.contains(e.target)) e.preventDefault(); // no guardar por accidente
  });
  robot.addEventListener('focusin', function (e) {
    var t = e.target;
    if (!t.classList.contains('rb-t')) return;
    var areas = t.closest('.rb-msg').querySelectorAll('textarea.rb-t');
    lang[areas[0].id || 'own'] = t === areas[1] ? 1 : 0;
    refresh();
  });
  robot.addEventListener('input', function (e) {
    var s = custom.contains(e.target) && byKey(current);
    if (s) { read(s); save(); draw(); } else setDirty(true);
    refresh();
  });
  // Arrastrar un paso propio hasta cualquier «+» compatible.
  var dragged = null;
  function target(e) {
    var a = e.target.closest && e.target.closest('.rb-add');
    return a && dragged && !(timed(a.dataset.slot) && dragged.type === 'question') ? a : null;
  }
  robot.addEventListener('dragstart', function (e) {
    var b = e.target.closest && e.target.closest('.rb-own');
    if (!b) return;
    dragged = byKey(b.dataset.step);
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', dragged.title);
    closeMenu();
    robot.classList.add('rb-drag');
  });
  robot.addEventListener('dragover', function (e) {
    var a = target(e);
    robot.querySelectorAll('.rb-over').forEach(function (x) { if (x !== a) x.classList.remove('rb-over'); });
    if (a) { e.preventDefault(); a.classList.add('rb-over'); }
  });
  robot.addEventListener('drop', function (e) {
    var a = target(e);
    if (!a) return;
    e.preventDefault();
    var slot = a.dataset.slot, i = +a.dataset.index, from = inSlot(slot).indexOf(dragged);
    place(dragged, slot, from >= 0 && from < i ? i - 1 : i);
    save();
    show('c' + dragged.id, false);
  });
  robot.addEventListener('dragend', function () {
    dragged = null;
    robot.classList.remove('rb-drag');
    robot.querySelectorAll('.rb-over').forEach(function (x) { x.classList.remove('rb-over'); });
  });
  robot.addEventListener('reset', function () {
    setTimeout(function () {
      store.value = initial; // los campos ocultos no se restauran solos
      data = JSON.parse(initial);
      setDirty(false);
      show(current, false);
    }, 0);
  });
  robot.addEventListener('submit', function (e) {
    var bad = data.steps.find(function (s) { return s.type === 'question' && s.kind === 'options' && s.opts_es.length < 2; });
    if (bad) {
      e.preventDefault();
      show('c' + bad.id, true);
      field('opts_es').focus();
      alert('«' + bad.title + '»: escribe al menos dos opciones, una por línea.');
      return;
    }
    store.value = JSON.stringify(data);
    saving = true;
  });
  // Un valor no válido en un paso que no se está viendo: se abre ese paso para poder corregirlo.
  robot.addEventListener('invalid', function (e) { var s = e.target.closest('.rb-step'); if (s && s !== custom && !s.classList.contains('rb-show')) show(s.dataset.key, false); }, true);
  window.addEventListener('beforeunload', function (e) { if (dirty && !saving) { e.preventDefault(); e.returnValue = ''; } });

  show(current, false);
})();
