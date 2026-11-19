/*
 * Bezares Chat — widget web (servido desde chat.bezares.com)
 * Uso: <script src="https://chat.bezares.com/widget.js" data-position="bottom-right" data-offset="20" data-devices="desktop,tablet,mobile" defer></script>
 */
(function () {
  'use strict';
  if (window.__bzcLoaded) return;
  window.__bzcLoaded = true;

  var script = document.currentScript || document.querySelector('script[src*="widget.js"]');
  var ds = (script && script.dataset) || {};
  var base = (ds.server || (script ? new URL(script.src).origin : '')).replace(/\/+$/, '');

  /* ---------- Dispositivo ---------- */
  function deviceType() {
    var ua = navigator.userAgent || '';
    var iPadOS = /Macintosh/.test(ua) && navigator.maxTouchPoints > 1;
    if (/iPad|Tablet|PlayBook|Silk/i.test(ua) || iPadOS || (/Android/i.test(ua) && !/Mobile/i.test(ua))) return 'tablet';
    if (/Mobi|iPhone|iPod|Android.*Mobile|Windows Phone|BlackBerry|Opera Mini/i.test(ua)) return 'mobile';
    return 'desktop';
  }
  var devices = (ds.devices || 'desktop,tablet,mobile').split(',');
  if (devices.indexOf(deviceType()) === -1) return;

  var browserLang = (navigator.language || 'es').slice(0, 2).toLowerCase();
  var UI = {
    es: { placeholder: 'Escribe un mensaje…', send: 'Enviar', attach: 'Adjuntar imagen, audio o vídeo', record: 'Grabar audio', stop: 'Detener y enviar',
      online: 'Normalmente respondemos en pocos minutos', offline: 'Fuera de horario: te responderemos lo antes posible',
      error: 'No se pudo enviar. Inténtalo de nuevo.', tooBig: 'El archivo es demasiado grande', close: 'Cerrar', open: 'Abrir chat' },
    en: { placeholder: 'Type a message…', send: 'Send', attach: 'Attach image, audio or video', record: 'Record audio', stop: 'Stop and send',
      online: 'We usually reply in a few minutes', offline: 'Outside business hours: we will reply as soon as possible',
      error: 'Could not send. Please try again.', tooBig: 'The file is too large', close: 'Close', open: 'Open chat' }
  };

  // Hoja de estilos y configuración (color, textos) desde el servidor de chat.
  var css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = base + '/widget.css?v=1';
  document.head.appendChild(css);

  fetch(base + '/api/widget/config', { credentials: 'omit' })
    .then(function (r) { return r.json(); })
    .then(function (cfg) {
      var lang = browserLang === 'es' ? 'es' : 'en'; // interfaz en español o, si no, en inglés
      var T = UI[lang];
      T.title = cfg[lang].title;
      T.welcome = cfg[lang].welcome;
      T.privacy = cfg[lang].privacy || '';
      var positions = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];
      main({
        api: base + '/api',
        position: positions.indexOf(ds.position) >= 0 ? ds.position : 'bottom-right',
        offset: Math.max(0, Math.min(200, parseInt(ds.offset || '20', 10) || 20)),
        color: /^#[0-9a-fA-F]{6}$/.test(cfg.color) ? cfg.color : '#0057a8',
        privacyUrl: /^https:\/\//i.test(cfg.privacyUrl || '') ? cfg.privacyUrl : '',
        maxUpload: cfg.maxUpload,
        open: cfg.open
      }, T);
    })
    .catch(function () { /* servidor de chat no disponible: no se muestra el widget */ });

  function main(C, T) {

  function store(k, v) {
    try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; }
  }
  // Identificador secreto del visitante: siempre con generador criptográfico.
  function genToken() {
    if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
    var a = new Uint8Array(24);
    window.crypto.getRandomValues(a);
    return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
  }
  var token = store('bzc_token');
  if (!token || !/^[a-zA-Z0-9\-]{20,64}$/.test(token)) { token = genToken(); store('bzc_token', token); }
  var hasConv = store('bzc_has_conv') === '1';
  var lastId = 0, seen = {}, unread = 0, isOpen = false, pollTimer = null, sending = false, businessOpen = C.open !== false;
  var lastSeenId = parseInt(store('bzc_last_seen') || '0', 10);

  /* ---------- DOM ---------- */
  var pos = C.position || 'bottom-right';
  var root = document.createElement('div');
  root.className = 'bzc-root bzc-' + pos;
  root.style.setProperty('--bzc-color', C.color);
  root.style.setProperty('--bzc-offset', (C.offset || 20) + 'px');
  root.innerHTML =
    '<div class="bzc-panel" role="dialog" aria-label="' + esc(T.title) + '" hidden>' +
      '<div class="bzc-head"><div><div class="bzc-title">' + esc(T.title) + '</div><div class="bzc-sub"></div></div>' +
      '<button type="button" class="bzc-x" aria-label="' + esc(T.close) + '">&times;</button></div>' +
      '<div class="bzc-msgs" aria-live="polite"></div>' +
      '<div class="bzc-err" hidden></div>' +
      (T.privacy ? '<div class="bzc-privacy">' + (C.privacyUrl ? '<a href="' + esc(C.privacyUrl) + '" target="_blank" rel="noopener">' + esc(T.privacy) + '</a>' : esc(T.privacy)) + '</div>' : '') +
      '<form class="bzc-form">' +
        '<button type="button" class="bzc-ico bzc-att" title="' + esc(T.attach) + '" aria-label="' + esc(T.attach) + '">' + svgClip() + '</button>' +
        '<input type="file" class="bzc-file" accept="image/*,audio/*,video/*" hidden>' +
        '<textarea class="bzc-input" rows="1" placeholder="' + esc(T.placeholder) + '" maxlength="4000"></textarea>' +
        '<button type="button" class="bzc-ico bzc-mic" title="' + esc(T.record) + '" aria-label="' + esc(T.record) + '">' + svgMic() + '</button>' +
        '<button type="submit" class="bzc-ico bzc-send" title="' + esc(T.send) + '" aria-label="' + esc(T.send) + '">' + svgSend() + '</button>' +
      '</form>' +
    '</div>' +
    '<button type="button" class="bzc-fab" aria-label="' + esc(T.open) + '">' + svgChat() + '<span class="bzc-badge" hidden>0</span></button>';
  document.body.appendChild(root);

  var $ = function (s) { return root.querySelector(s); };
  var panel = $('.bzc-panel'), msgs = $('.bzc-msgs'), input = $('.bzc-input'), form = $('.bzc-form'),
      fab = $('.bzc-fab'), badge = $('.bzc-badge'), fileIn = $('.bzc-file'), err = $('.bzc-err'),
      sub = $('.bzc-sub'), mic = $('.bzc-mic');

  /* ---------- Utilidades ---------- */
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function linkify(s) {
    return esc(s).replace(/(https?:\/\/[^\s<]+)/g, '<a href="$1" target="_blank" rel="noopener nofollow">$1</a>').replace(/\n/g, '<br>');
  }
  function time(ms) {
    var d = new Date(ms); return ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
  }
  function showErr(t) { err.textContent = t; err.hidden = false; setTimeout(function () { err.hidden = true; }, 5000); }
  function api(method, path, body, isForm) {
    var url = C.api + path;
    var opts = { method: method, credentials: 'omit', headers: {} };
    if (body && isForm) opts.body = body;
    else if (body) { opts.body = JSON.stringify(body); opts.headers['Content-Type'] = 'application/json'; }
    return fetch(url, opts).then(function (r) {
      return r.json().then(function (j) { if (!r.ok) throw new Error(j && j.message || ('HTTP ' + r.status)); return j; });
    });
  }

  /* ---------- Render ---------- */
  function bubble(m) {
    var mine = m.direction === 'in';
    var el = document.createElement('div');
    el.className = 'bzc-msg ' + (mine ? 'bzc-me' : 'bzc-them') + (m.direction === 'bot' ? ' bzc-bot' : '');
    var html = '';
    if (!mine && m.agent_name) html += '<div class="bzc-who">' + esc(m.agent_name) + '</div>';
    if (m.media_url) {
      var u = esc(m.media_url);
      if (m.media_type === 'image') html += '<a href="' + u + '" target="_blank" rel="noopener"><img src="' + u + '" alt="" loading="lazy"></a>';
      else if (m.media_type === 'audio') html += '<audio controls preload="none" src="' + u + '"></audio>';
      else if (m.media_type === 'video') html += '<video controls preload="metadata" src="' + u + '"></video>';
      else html += '<a href="' + u + '" target="_blank" rel="noopener">📎 ' + esc(m.media_name || 'archivo') + '</a>';
    }
    if (m.body) html += '<div class="bzc-text">' + linkify(m.body) + '</div>';
    html += '<div class="bzc-time">' + time(m.created_at) + '</div>';
    el.innerHTML = html;
    return el;
  }
  function renderWelcome() {
    if (msgs.childElementCount) return;
    msgs.appendChild(bubble({ direction: 'bot', body: T.welcome, created_at: Date.now() }));
    msgs.firstChild.classList.add('bzc-local');
  }
  function addMessages(list) {
    var added = false;
    list.forEach(function (m) {
      if (seen[m.id]) return;
      seen[m.id] = 1;
      lastId = Math.max(lastId, m.id);
      var local = msgs.querySelector('.bzc-local');
      if (local) local.remove();
      msgs.appendChild(bubble(m));
      added = true;
      if (m.direction !== 'in' && m.id > lastSeenId && !isOpen) unread++;
    });
    if (added) {
      msgs.scrollTop = msgs.scrollHeight;
      if (isOpen) markSeen();
      updateBadge();
    }
  }
  function markSeen() { lastSeenId = lastId; store('bzc_last_seen', String(lastId)); unread = 0; updateBadge(); }
  function updateBadge() { badge.hidden = unread === 0; badge.textContent = unread > 9 ? '9+' : String(unread); }
  function updateSub() { sub.textContent = businessOpen ? T.online : T.offline; }

  /* ---------- Polling ---------- */
  function poll() {
    clearTimeout(pollTimer);
    if (!hasConv) { schedule(); return; }
    api('GET', '/visitor/messages?token=' + encodeURIComponent(token) + '&after=' + lastId)
      .then(function (r) {
        businessOpen = r.open; updateSub();
        addMessages(r.messages || []);
      })
      .catch(function () {})
      .then(schedule);
  }
  function schedule() {
    clearTimeout(pollTimer);
    if (!hasConv) return;
    pollTimer = setTimeout(poll, document.hidden ? 30000 : (isOpen ? 3000 : 15000));
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden) poll(); });

  /* ---------- Abrir / cerrar ---------- */
  function setOpen(v) {
    isOpen = v;
    panel.hidden = !v;
    root.classList.toggle('bzc-open', v);
    try { sessionStorage.setItem('bzc_open', v ? '1' : '0'); } catch (e) {}
    if (v) {
      renderWelcome(); updateSub(); markSeen();
      setTimeout(function () { input.focus(); msgs.scrollTop = msgs.scrollHeight; }, 50);
      poll();
    } else schedule();
  }
  fab.addEventListener('click', function () { setOpen(!isOpen); });
  $('.bzc-x').addEventListener('click', function () { setOpen(false); });

  /* ---------- Envío ---------- */
  function afterSend() {
    if (!hasConv) { hasConv = true; store('bzc_has_conv', '1'); }
    poll();
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || sending) return;
    sending = true;
    api('POST', '/visitor/messages', { token: token, text: text, lang: browserLang, page: location.href })
      .then(function () { input.value = ''; autosize(); afterSend(); })
      .catch(function (ex) { showErr(ex.message || T.error); })
      .then(function () { sending = false; });
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); }
  });
  function autosize() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 110) + 'px'; }
  input.addEventListener('input', autosize);

  function upload(blob, name) {
    if (blob.size > (C.maxUpload || 16) * 1048576) { showErr(T.tooBig); return; }
    var fd = new FormData();
    fd.append('file', blob, name);
    fd.append('token', token);
    fd.append('lang', browserLang);
    fd.append('page', location.href);
    root.classList.add('bzc-busy');
    api('POST', '/visitor/upload', fd, true)
      .then(afterSend)
      .catch(function (ex) { showErr(ex.message || T.error); })
      .then(function () { root.classList.remove('bzc-busy'); });
  }
  $('.bzc-att').addEventListener('click', function () { fileIn.click(); });
  fileIn.addEventListener('change', function () {
    var f = fileIn.files && fileIn.files[0];
    if (f) upload(f, f.name);
    fileIn.value = '';
  });

  /* ---------- Grabación de audio ---------- */
  var rec = null, chunks = [];
  if (!window.MediaRecorder || !navigator.mediaDevices) mic.hidden = true;
  mic.addEventListener('click', function () {
    if (rec && rec.state === 'recording') { rec.stop(); return; }
    navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
      var type = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : (MediaRecorder.isTypeSupported('audio/mp4') ? 'audio/mp4' : '');
      rec = type ? new MediaRecorder(stream, { mimeType: type }) : new MediaRecorder(stream);
      chunks = [];
      rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
      rec.onstop = function () {
        stream.getTracks().forEach(function (t) { t.stop(); });
        root.classList.remove('bzc-recording');
        mic.title = T.record;
        var mime = rec.mimeType || type || 'audio/webm';
        var blob = new Blob(chunks, { type: mime });
        if (blob.size > 0) upload(blob, /mp4/.test(mime) ? 'audio.m4a' : 'audio.weba');
      };
      rec.start();
      root.classList.add('bzc-recording');
      mic.title = T.stop;
    }).catch(function () { showErr(T.error); });
  });

  /* ---------- Iconos ---------- */
  function svgChat() { return '<svg viewBox="0 0 24 24" width="28" height="28" aria-hidden="true"><path fill="currentColor" d="M12 3C6.5 3 2 6.6 2 11c0 2.2 1.1 4.2 2.9 5.6L4 21l4.6-2.4c1.1.3 2.2.4 3.4.4 5.5 0 10-3.6 10-8s-4.5-8-10-8z"/></svg>'; }
  function svgSend() { return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M2 21l21-9L2 3v7l15 2-15 2z"/></svg>'; }
  function svgClip() { return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M16.5 6.5v10a4.5 4.5 0 0 1-9 0V5a3 3 0 0 1 6 0v10.5a1.5 1.5 0 0 1-3 0V6.5H9v9a3 3 0 0 0 6 0V5a4.5 4.5 0 0 0-9 0v11.5a6 6 0 0 0 12 0v-10z"/></svg>'; }
  function svgMic() { return '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true"><path fill="currentColor" d="M12 14a3 3 0 0 0 3-3V5a3 3 0 0 0-6 0v6a3 3 0 0 0 3 3zm5-3a5 5 0 0 1-10 0H5a7 7 0 0 0 6 6.9V21h2v-3.1A7 7 0 0 0 19 11z"/></svg>'; }

  /* ---------- Inicio ---------- */
  updateSub();
  var wasOpen = false;
  try { wasOpen = sessionStorage.getItem('bzc_open') === '1'; } catch (e) {}
  if (wasOpen) setOpen(true); else poll();
  }
})();
