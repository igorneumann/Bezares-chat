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
    es: { placeholder: 'Escribe un mensaje…', send: 'Enviar', attach: 'Adjuntar imagen, audio, vídeo o documento', record: 'Grabar audio', stop: 'Detener y enviar',
      online: 'Normalmente respondemos en pocos minutos', offline: 'Fuera de horario: te responderemos lo antes posible',
      error: 'No se pudo enviar. Inténtalo de nuevo.', tooBig: 'El archivo es demasiado grande', close: 'Cerrar', open: 'Abrir chat',
      hint: 'Escríbenos tu consulta y te atenderemos enseguida.' },
    en: { placeholder: 'Type a message…', send: 'Send', attach: 'Attach image, audio, video or document', record: 'Record audio', stop: 'Stop and send',
      online: 'We usually reply in a few minutes', offline: 'Outside business hours: we will reply as soon as possible',
      error: 'Could not send. Please try again.', tooBig: 'The file is too large', close: 'Close', open: 'Open chat',
      hint: 'Write us your question and we will help you right away.' }
  };

  // Hoja de estilos y configuración (color, textos) desde el servidor de chat.
  var css = document.createElement('link');
  css.rel = 'stylesheet';
  css.href = base + '/widget.css?v=3';
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
        color: /^#[0-9a-fA-F]{6}$/.test(cfg.color) ? cfg.color : '#F5333F',
        privacyUrl: /^https:\/\//i.test(cfg.privacyUrl || '') ? cfg.privacyUrl : '',
        maxUpload: cfg.maxUpload,
        resetMinutes: Math.max(0, parseInt(cfg.resetMinutes, 10) || 0),
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
  /*
   * Continuidad del chat: al recargar la página se sigue en la misma conversación, pero si el visitante
   * cerró la página y vuelve pasados C.resetMinutes minutos, empieza un chat nuevo (token nuevo).
   * bzc_last_active se actualiza mientras la página está abierta y al cerrarla/recargarla.
   */
  var token = store('bzc_token');
  var lastActive = parseInt(store('bzc_last_active') || '0', 10);
  var expired = C.resetMinutes > 0 && lastActive > 0 && (Date.now() - lastActive) > C.resetMinutes * 60000;
  if (!token || !/^[a-zA-Z0-9\-]{20,64}$/.test(token) || expired) {
    token = genToken();
    store('bzc_token', token);
    store('bzc_has_conv', '0');
    store('bzc_last_seen', '0');
    try { sessionStorage.removeItem('bzc_open'); } catch (e) {}
  }
  function touch() { store('bzc_last_active', String(Date.now())); }
  touch();
  setInterval(touch, 30000);
  window.addEventListener('pagehide', touch);
  document.addEventListener('visibilitychange', function () { if (document.hidden) touch(); });
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
      '<div class="bzc-head"><span class="bzc-logo">' + svgBz(30) + '</span><div class="bzc-head-txt"><div class="bzc-title">' + esc(T.title) + '</div><div class="bzc-sub"></div></div>' +
      '<button type="button" class="bzc-x" aria-label="' + esc(T.close) + '">&times;</button></div>' +
      '<div class="bzc-msgs" aria-live="polite"></div>' +
      '<div class="bzc-err" hidden></div>' +
      (T.privacy ? '<div class="bzc-privacy">' + (C.privacyUrl ? '<a href="' + esc(C.privacyUrl) + '" target="_blank" rel="noopener">' + esc(T.privacy) + '</a>' : esc(T.privacy)) + '</div>' : '') +
      '<form class="bzc-form">' +
        '<button type="button" class="bzc-ico bzc-att" title="' + esc(T.attach) + '" aria-label="' + esc(T.attach) + '">' + svgClip() + '</button>' +
        '<input type="file" class="bzc-file" accept="image/*,audio/*,video/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.rtf,.txt,.csv" hidden>' +
        '<textarea class="bzc-input" rows="1" placeholder="' + esc(T.placeholder) + '" maxlength="4000"></textarea>' +
        '<button type="button" class="bzc-ico bzc-mic" title="' + esc(T.record) + '" aria-label="' + esc(T.record) + '">' + svgMic() + '</button>' +
        '<button type="submit" class="bzc-ico bzc-send" title="' + esc(T.send) + '" aria-label="' + esc(T.send) + '">' + svgSend() + '</button>' +
      '</form>' +
    '</div>' +
    '<button type="button" class="bzc-fab" aria-label="' + esc(T.open) + '">' + svgBz(36) + '<span class="bzc-badge" hidden>0</span></button>';
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
      else html += '<a href="' + u + '" target="_blank" rel="noopener" class="bzc-doc">📄 ' + esc(m.media_name || 'documento') + '</a>';
    }
    if (m.body) html += '<div class="bzc-text">' + linkify(m.body) + '</div>';
    html += '<div class="bzc-time">' + time(m.created_at) + '</div>';
    el.innerHTML = html;
    return el;
  }
  // Aviso inicial (no es un mensaje): el bot pedirá el nombre como respuesta al primer mensaje del visitante.
  function renderWelcome() {
    if (msgs.childElementCount) return;
    var hint = document.createElement('div');
    hint.className = 'bzc-hint bzc-local';
    hint.textContent = T.hint;
    msgs.appendChild(hint);
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
      if (!hasConv) getPow().catch(function () {}); // resuelve el reto anti-bot mientras el visitante escribe
      setTimeout(function () { input.focus(); msgs.scrollTop = msgs.scrollHeight; }, 50);
      poll();
    } else schedule();
  }
  fab.addEventListener('click', function () { setOpen(!isOpen); });
  $('.bzc-x').addEventListener('click', function () { setOpen(false); });

  /* ---------- Anti-bot: prueba de trabajo para abrir un chat nuevo ---------- */
  // SHA-256 (devuelve 8 enteros de 32 bits). Basta para el reto; texto ASCII.
  var K256 = [0x428a2f98,0x71374491,0xb5c0fbcf,0xe9b5dba5,0x3956c25b,0x59f111f1,0x923f82a4,0xab1c5ed5,0xd807aa98,0x12835b01,0x243185be,0x550c7dc3,0x72be5d74,0x80deb1fe,0x9bdc06a7,0xc19bf174,
    0xe49b69c1,0xefbe4786,0x0fc19dc6,0x240ca1cc,0x2de92c6f,0x4a7484aa,0x5cb0a9dc,0x76f988da,0x983e5152,0xa831c66d,0xb00327c8,0xbf597fc7,0xc6e00bf3,0xd5a79147,0x06ca6351,0x14292967,
    0x27b70a85,0x2e1b2138,0x4d2c6dfc,0x53380d13,0x650a7354,0x766a0abb,0x81c2c92e,0x92722c85,0xa2bfe8a1,0xa81a664b,0xc24b8b70,0xc76c51a3,0xd192e819,0xd6990624,0xf40e3585,0x106aa070,
    0x19a4c116,0x1e376c08,0x2748774c,0x34b0bcb5,0x391c0cb3,0x4ed8aa4a,0x5b9cca4f,0x682e6ff3,0x748f82ee,0x78a5636f,0x84c87814,0x8cc70208,0x90befffa,0xa4506ceb,0xbef9a3f7,0xc67178f2];
  var W256 = new Array(64);
  function sha256(str) {
    var i, t, n = str.length, bytes = [];
    for (i = 0; i < n; i++) bytes.push(str.charCodeAt(i) & 0xff);
    bytes.push(0x80);
    while (bytes.length % 64 !== 56) bytes.push(0);
    var bits = n * 8;
    bytes.push(0, 0, 0, 0, (bits >>> 24) & 0xff, (bits >>> 16) & 0xff, (bits >>> 8) & 0xff, bits & 0xff);
    var H = [0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19];
    for (var off = 0; off < bytes.length; off += 64) {
      for (t = 0; t < 16; t++) W256[t] = (bytes[off + 4 * t] << 24) | (bytes[off + 4 * t + 1] << 16) | (bytes[off + 4 * t + 2] << 8) | bytes[off + 4 * t + 3];
      for (t = 16; t < 64; t++) {
        var x = W256[t - 15], y = W256[t - 2];
        var s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
        var s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
        W256[t] = (W256[t - 16] + s0 + W256[t - 7] + s1) | 0;
      }
      var a = H[0], b = H[1], c = H[2], d = H[3], e = H[4], f = H[5], g = H[6], h = H[7];
      for (t = 0; t < 64; t++) {
        var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
        var t1 = (h + S1 + ((e & f) ^ (~e & g)) + K256[t] + W256[t]) | 0;
        var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
        var t2 = (S0 + ((a & b) ^ (a & c) ^ (b & c))) | 0;
        h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      H[0] = (H[0] + a) | 0; H[1] = (H[1] + b) | 0; H[2] = (H[2] + c) | 0; H[3] = (H[3] + d) | 0;
      H[4] = (H[4] + e) | 0; H[5] = (H[5] + f) | 0; H[6] = (H[6] + g) | 0; H[7] = (H[7] + h) | 0;
    }
    return H;
  }
  // Busca n tal que SHA-256(reto:n) empiece por "bits" ceros, en bloques para no bloquear la página.
  var powPromise = null;
  function solvePow() {
    return api('GET', '/visitor/challenge').then(function (ch) {
      var bits = ch.bits, mask = bits >= 32 ? 0xffffffff : (0xffffffff << (32 - bits)) >>> 0, nonce = 0;
      return new Promise(function (resolve) {
        (function step() {
          for (var k = 0; k < 4000; k++, nonce++) {
            var H = sha256(ch.c + ':' + nonce);
            if (((H[0] >>> 0) & mask) === 0 && (bits <= 32)) { resolve({ c: ch.c, s: ch.s, n: String(nonce), exp: Date.now() + 8 * 60000 }); return; }
          }
          setTimeout(step, 0);
        })();
      });
    });
  }
  function getPow() {
    if (!powPromise) powPromise = solvePow().catch(function (e) { powPromise = null; throw e; });
    return powPromise.then(function (p) {
      if (p.exp < Date.now()) { powPromise = null; return getPow(); }
      return p;
    });
  }
  function usePow() { var p = powPromise; powPromise = null; return p; } // cada reto vale una vez

  /* ---------- Envío ---------- */
  function afterSend() {
    if (!hasConv) { hasConv = true; store('bzc_has_conv', '1'); }
    touch();
    poll();
  }
  // Zona horaria del navegador (p. ej. "Europe/Madrid"): el servidor deduce el país sin geolocalizar la IP.
  function tzName() {
    try { return Intl.DateTimeFormat().resolvedOptions().timeZone || ''; } catch (e) { return ''; }
  }
  // Añade la prueba de trabajo solo cuando el mensaje abre un chat nuevo.
  function withPow(fields) {
    if (hasConv) return Promise.resolve(fields);
    return getPow().then(function (p) {
      usePow();
      fields.pow_c = p.c; fields.pow_s = p.s; fields.pow_n = p.n;
      return fields;
    });
  }
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var text = input.value.trim();
    if (!text || sending) return;
    sending = true;
    root.classList.add('bzc-busy');
    withPow({ token: token, text: text, lang: browserLang, locale: (navigator.languages || [navigator.language || '']).join(','), tz: tzName(), page: location.href })
      .then(function (body) { return api('POST', '/visitor/messages', body); })
      .then(function () { input.value = ''; autosize(); afterSend(); })
      .catch(function (ex) { showErr(ex.message || T.error); })
      .then(function () { sending = false; root.classList.remove('bzc-busy'); });
  });
  input.addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); form.requestSubmit ? form.requestSubmit() : form.dispatchEvent(new Event('submit')); }
  });
  function autosize() { input.style.height = 'auto'; input.style.height = Math.min(input.scrollHeight, 110) + 'px'; }
  input.addEventListener('input', autosize);

  function upload(blob, name) {
    if (blob.size > (C.maxUpload || 16) * 1048576) { showErr(T.tooBig); return; }
    root.classList.add('bzc-busy');
    withPow({ token: token, lang: browserLang, locale: (navigator.languages || [navigator.language || '']).join(','), tz: tzName(), page: location.href })
      .then(function (fields) {
        var fd = new FormData();
        Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
        fd.append('file', blob, name);
        return api('POST', '/visitor/upload', fd, true);
      })
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
  // Icono de Bezares Chat: burbuja con el anagrama BZ recortado (toma el color del texto: blanco sobre el botón).
  var bzN = 0;
  function svgBz(size) {
    var id = 'bzc-bzcut-' + (++bzN);
    return '<svg viewBox="0 0 256 256" width="' + size + '" height="' + size + '" aria-hidden="true" focusable="false">' +
      '<defs><mask id="' + id + '" maskUnits="userSpaceOnUse" x="0" y="0" width="256" height="256"><rect width="256" height="256" fill="#fff"/>' +
      '<g fill="#000" transform="translate(33 64.05) scale(0.6703)">' +
      '<polygon points="31.99,97.77 47.8,70.23 15.95,70.17 0,97.74"/>' +
      '<path d="M170.13,97.87h16.96l-31.79,55.24h128.16l-16.13-27.56h-64.03l32.07-55.62l-65.11,0.12c0,0-4.21,10.27-7.68,13.86C165.93,87.51,170.13,97.87,170.13,97.87"/>' +
      '<path d="M98.83,42.27h2.81h25.73c5.28,0,14.3,4.39,14.3,13.96c0,7.3-6.61,13.96-15.04,13.96H82.36L98.83,42.27z M127.56,153.69c9.54,0,41.62-9.62,41.62-41.86c0-11.46-4.45-22.18-10.56-27.96c7.68-10.2,10.81-19.72,10.81-28.28c0-24.94-25.58-42.32-40.78-42.32H90.72c0,0-4.12-0.03-5.3,0.36c-2.08,0.69-3.27,2.4-4.49,4.56L5.92,147.62c-1.58,2.71-0.99,6.08,2.83,6.08H127.56z M126.75,125.64H50.42l16.47-27.92h2.81h59.12c3.64,0,12.96,3.45,12.96,14.54C141.79,119.05,135.81,125.64,126.75,125.64"/>' +
      '</g></mask></defs>' +
      '<path fill="currentColor" mask="url(#' + id + ')" d="M48 36h160a32 32 0 0 1 32 32v104a32 32 0 0 1-32 32h-86l-46 38v-38H48a32 32 0 0 1-32-32V68a32 32 0 0 1 32-32z"/></svg>';
  }
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
