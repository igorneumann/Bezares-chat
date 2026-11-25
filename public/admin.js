/* Bezares Chat — panel de administración: botón "Mostrar contraseña" (sin dependencias). */
(function () {
  'use strict';
  document.documentElement.classList.remove('no-js');
  document.documentElement.classList.add('js');
  // Chats: el hilo se abre por el final (últimos mensajes).
  var end = document.querySelector('.tconv[data-end]');
  if (end && !location.hash) end.scrollTop = end.scrollHeight;
  document.addEventListener('click', function (e) {
    // Copiar el contenido de un campo (enlace de alta de agentes).
    var copy = e.target.closest && e.target.closest('[data-copy]');
    if (copy) {
      var src = document.getElementById(copy.getAttribute('data-copy')), label = copy.dataset.label || (copy.dataset.label = copy.textContent);
      var note = function (t) { copy.textContent = t; setTimeout(function () { copy.textContent = label; }, 2500); };
      var done = false;
      src.focus();
      src.select();
      src.setSelectionRange(0, src.value.length);
      // Primero la copia directa: la otra no está permitida dentro de un marco (Nextcloud) ni sin HTTPS.
      try { done = document.execCommand('copy'); } catch (err) { done = false; }
      if (done) note('✓ Copiado');
      else if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(src.value).then(function () { note('✓ Copiado'); }, function () { note('Pulsa Ctrl+C'); });
      else note('Pulsa Ctrl+C');
      return;
    }
    var btn = e.target.closest && e.target.closest('.pw-toggle');
    if (!btn) return;
    var input = document.getElementById(btn.getAttribute('data-target'));
    if (!input) return;
    var show = input.type === 'password';
    input.type = show ? 'text' : 'password';
    btn.textContent = show ? 'Ocultar' : 'Mostrar';
    btn.setAttribute('aria-pressed', show ? 'true' : 'false');
    btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    input.focus();
  });

  /* ---------- barra de progreso ---------- */
  function bar(form) {
    var b = form.querySelector('.up');
    if (!b) {
      b = document.createElement('div');
      b.className = 'up';
      b.innerHTML = '<div class="up-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100"><i></i></div><div class="up-row"><span role="status"></span><button type="button" class="btn btn-ghost btn-sm" hidden>Cancelar</button></div>';
      form.appendChild(b);
    }
    // p: porcentaje, o null mientras no se sabe cuánto falta.
    return {
      box: b,
      cancel: b.querySelector('button'),
      set: function (text, p, bad) {
        b.className = 'up' + (bad ? ' up-bad' : p === null ? ' up-wait' : '');
        b.querySelector('i').style.width = bad || p === null ? '' : p + '%';
        var r = b.firstChild;
        if (p === null) r.removeAttribute('aria-valuenow'); else r.setAttribute('aria-valuenow', Math.round(p));
        b.querySelector('span').textContent = text;
      }
    };
  }
  function buttons(form, off) { form.querySelectorAll('button:not(.up button)').forEach(function (x) { x.disabled = off; }); }
  function mb(n) { return (n / 1048576).toFixed(1).replace('.', ',') + ' MB'; }

  // Subida de archivos con porcentaje.
  var uploading = false;
  document.querySelectorAll('form[data-upload]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      var input = form.querySelector('input[type=file]'), file = input.files && input.files[0];
      if (!file || !window.XMLHttpRequest || !window.FormData) return;
      e.preventDefault();
      if (uploading) return;
      var b = bar(form), max = +form.dataset.max || 0;
      if (max && file.size > max) { b.set('El archivo ocupa ' + mb(file.size) + ' y el máximo son ' + mb(max) + '.', 0, true); return; }
      var x = new XMLHttpRequest(), data = new FormData(form);
      var stop = function (text) { uploading = false; input.disabled = false; buttons(form, false); b.cancel.hidden = true; b.set(text, 0, true); };
      uploading = true;
      input.disabled = true;
      buttons(form, true);
      b.cancel.hidden = false;
      b.cancel.onclick = function () { x.abort(); };
      b.set('Subiendo… 0 %', 0);
      x.upload.addEventListener('progress', function (p) {
        if (!p.lengthComputable) { b.set('Subiendo…', null); return; }
        var k = p.loaded / p.total * 100;
        b.set('Subiendo… ' + Math.floor(k) + ' % (' + mb(p.loaded) + ' de ' + mb(p.total) + ')', k);
      });
      x.upload.addEventListener('load', function () {
        b.cancel.hidden = true; // ya está en el servidor
        b.set('Archivo recibido. Enviándolo a OpenAI… puede tardar un minuto.', null);
      });
      x.addEventListener('load', function () {
        var ok = false;
        try { ok = JSON.parse(x.responseText).done === true; } catch (err) { ok = false; }
        if (ok || (x.status === 200 && !ok)) { // 200 sin datos: la sesión ha caducado y ha salido la página de acceso
          uploading = false;
          b.set('Listo.', 100);
          location.assign(form.getAttribute('action'));
        } else if (x.status === 504 || x.status === 502) stop('El servidor tarda más de lo normal. Espera un minuto y recarga la página: el archivo puede aparecer en la lista.');
        else if (x.status === 413) stop('El servidor no admite un archivo tan grande.');
        else stop(x.status === 400 && x.responseText.length < 200 ? x.responseText : 'No se ha podido subir (error ' + x.status + '). Inténtalo de nuevo.');
      });
      x.addEventListener('error', function () { stop('Se ha cortado la conexión. Inténtalo de nuevo.'); });
      x.addEventListener('abort', function () { stop('Subida cancelada.'); });
      x.open('POST', form.getAttribute('action'));
      x.setRequestHeader('X-Requested-With', 'bzc-upload');
      x.send(data);
    });
  });
  window.addEventListener('beforeunload', function (e) { if (uploading) { e.preventDefault(); e.returnValue = ''; } });

  // Otras acciones que tardan unos segundos: barra sin porcentaje.
  document.querySelectorAll('form[data-busy]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      if (form.dataset.sent) { e.preventDefault(); return; }
      form.dataset.sent = '1';
      bar(form).set(form.dataset.busy, null);
      setTimeout(function () { buttons(form, true); }, 0);
    });
  });
  window.addEventListener('pageshow', function (e) { // al volver atrás, la página vuelve a estar lista
    if (!e.persisted) return;
    document.querySelectorAll('form[data-busy]').forEach(function (f) { delete f.dataset.sent; buttons(f, false); var u = f.querySelector('.up'); if (u) u.remove(); });
  });

  // Archivos que OpenAI aún está preparando: se actualiza sola mientras no se esté escribiendo nada.
  if (document.querySelector('[data-wait]')) {
    var touched = false;
    document.addEventListener('input', function () { touched = true; });
    setTimeout(function () { if (!touched && !uploading && !document.querySelector('form[data-sent]')) location.reload(); }, 6000);
  }
})();
