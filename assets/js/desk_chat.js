
(function (global) {
  'use strict';

  var ALLOWED = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'csv', 'zip', 'mp4', 'mov'];
  var MAX_FILES = 5;
  var MAX_BYTES = 8 * 1024 * 1024;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function fmtSize(b) {
    if (b >= 1048576) return (b / 1048576).toFixed(1).replace('.0', '') + ' MB';
    if (b >= 1024) return Math.round(b / 1024) + ' KB';
    return b + ' B';
  }
  function ext(name) { var m = /\.([^.]+)$/.exec(name || ''); return m ? m[1].toLowerCase() : ''; }
  function md(escapedHtml) {   // el servidor ya escapó el HTML; aquí solo negrita y saltos de línea
    return escapedHtml.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>').replace(/\n/g, '<br>');
  }
  function icon(name) {
    var e = ext(name);
    if (['jpg', 'jpeg', 'png', 'gif', 'webp'].indexOf(e) >= 0) return 'fa-file-image';
    if (e === 'pdf') return 'fa-file-pdf';
    if (['doc', 'docx'].indexOf(e) >= 0) return 'fa-file-word';
    if (['xls', 'xlsx', 'csv'].indexOf(e) >= 0) return 'fa-file-excel';
    if (['zip'].indexOf(e) >= 0) return 'fa-file-zipper';
    if (['mp4', 'mov'].indexOf(e) >= 0) return 'fa-file-video';
    return 'fa-file-lines';
  }

  function DeskChat(cfg) {
    this.cfg = cfg;
    this.files = [];
    this.busy = false;
    this.loaded = false;
    this.convId = 0;
    var self = this;

    if (cfg.form) {
      cfg.form.addEventListener('submit', function (e) { e.preventDefault(); self.send(cfg.input.value); });
    }
    if (cfg.attachBtn && cfg.fileInput) {
      cfg.attachBtn.addEventListener('click', function (e) { e.preventDefault(); cfg.fileInput.click(); });
      cfg.fileInput.setAttribute('accept', ALLOWED.map(function (x) { return '.' + x; }).join(','));
      cfg.fileInput.setAttribute('multiple', 'multiple');
      cfg.fileInput.addEventListener('change', function () { self.addFiles(cfg.fileInput.files); cfg.fileInput.value = ''; });
    }
    if (cfg.input) {
      cfg.input.addEventListener('paste', function (e) {
        var items = (e.clipboardData && e.clipboardData.files) || [];
        if (items.length) { e.preventDefault(); self.addFiles(items); }
      });
    }
    if (cfg.container) {
      ['dragenter', 'dragover'].forEach(function (ev) {
        cfg.container.addEventListener(ev, function (e) { e.preventDefault(); cfg.container.classList.add('dc-drop'); });
      });
      ['dragleave', 'drop'].forEach(function (ev) {
        cfg.container.addEventListener(ev, function (e) { e.preventDefault(); cfg.container.classList.remove('dc-drop'); });
      });
      cfg.container.addEventListener('drop', function (e) { if (e.dataTransfer && e.dataTransfer.files.length) self.addFiles(e.dataTransfer.files); });
    }
    if (cfg.newBtn) cfg.newBtn.addEventListener('click', function (e) { e.preventDefault(); self.newChat(); });
    this.injectStyle();
  }

  DeskChat.prototype.injectStyle = function () {
    if (document.getElementById('dc-style')) return;
    var st = document.createElement('style');
    st.id = 'dc-style';
    st.textContent =
      '.dc-drop{outline:2px dashed #0d6efd;outline-offset:-6px;background:rgba(13,110,253,.04)!important}' +
      '.dc-chip{display:inline-flex;align-items:center;gap:6px;max-width:100%;background:#fff;border:1px solid #dee2e6;border-radius:10px;padding:3px 6px 3px 6px;font-size:.78rem;margin:0 4px 4px 0}' +
      '.dc-chip img{width:26px;height:26px;object-fit:cover;border-radius:4px}' +
      '.dc-chip .dc-name{max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}' +
      '.dc-chip button{border:0;background:transparent;color:#6c757d;padding:0 2px;line-height:1}' +
      '.dc-chip button:hover{color:#dc3545}' +
      '.dc-chip.dc-up{opacity:.7}' +
      '.dc-err{font-size:.78rem;color:#dc3545;margin:0 0 4px 0}' +
      '.dc-quick{display:flex;flex-wrap:wrap;gap:6px;margin:0 0 12px 0}' +
      '.dc-typing span{display:inline-block;width:6px;height:6px;margin-right:3px;background:#adb5bd;border-radius:50%;animation:dcb 1s infinite}' +
      '.dc-typing span:nth-child(2){animation-delay:.15s}.dc-typing span:nth-child(3){animation-delay:.3s}' +
      '@keyframes dcb{0%,80%,100%{transform:translateY(0)}40%{transform:translateY(-4px)}}';
    document.head.appendChild(st);
  };

  /* ---------------------------------------------------------------- burbujas */

  DeskChat.prototype.scroll = function () { var c = this.cfg.container; c.scrollTop = c.scrollHeight; };

  DeskChat.prototype.bubble = function (role, innerHtml, extraClass) {
    var wrap = document.createElement('div');
    var bot = role !== 'user';
    wrap.className = 'd-flex mb-3 dc-msg' + (bot ? '' : ' justify-content-end');
    var cls = bot ? (this.cfg.botBubble || 'bg-light border text-dark') : 'bg-primary text-white';
    wrap.innerHTML = '<div class="' + cls + ' px-3 py-2 rounded-3 shadow-sm ' + (extraClass || '') + '" style="max-width:88%;font-size:.9rem;word-break:break-word;">' +
      (bot ? '<i class="fas fa-robot text-primary me-2"></i>' : '') + innerHtml + '</div>';
    this.cfg.container.appendChild(wrap);
    this.scroll();
    return wrap;
  };

  DeskChat.prototype.filesHtml = function (files) {
    if (!files || !files.length) return '';
    var h = '<div class="mt-1" style="font-size:.78rem;">';
    files.forEach(function (f) {
      var ok = f.ok !== false;
      h += '<div class="' + (ok ? '' : 'text-warning-emphasis') + '"><i class="fas ' + (ok ? icon(f.name) : 'fa-triangle-exclamation') + ' me-1"></i>' + esc(f.name) +
        (f.size ? ' <span style="opacity:.75">(' + fmtSize(f.size) + ')</span>' : '') + (ok ? '' : ' — ' + esc(f.error || 'no se pudo adjuntar')) + '</div>';
    });
    return h + '</div>';
  };

  DeskChat.prototype.addUser = function (text, files) {
    var list = (files || []).map(function (f) { return { name: f.name, size: f.size, ok: true }; });
    this.bubble('user', (text ? esc(text).replace(/\n/g, '<br>') : '') + this.filesHtml(list));
  };

  DeskChat.prototype.addBot = function (escapedHtml, extra) {
    var w = this.bubble('bot', md(escapedHtml || ''), extra && extra.cls);
    return w;
  };

  DeskChat.prototype.addTyping = function (label) {
    var w = this.bubble('bot', '<span class="dc-typing"><span></span><span></span><span></span></span> <small class="text-muted dc-typing-label">' + esc(label || '') + '</small>');
    return w;
  };

  DeskChat.prototype.clearQuick = function () {
    this.cfg.container.querySelectorAll('.dc-quick').forEach(function (n) { n.remove(); });
  };

  DeskChat.prototype.showQuick = function (list) {
    this.clearQuick();
    if (!list || !list.length) return;
    var self = this, box = document.createElement('div');
    box.className = 'dc-quick';
    list.forEach(function (label) {
      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'btn btn-sm btn-outline-primary rounded-pill';
      b.textContent = label;
      b.addEventListener('click', function () { self.send(label); });
      box.appendChild(b);
    });
    this.cfg.container.appendChild(box);
    this.scroll();
  };

  DeskChat.prototype.showGreeting = function () {
    this.cfg.container.innerHTML = '';
    this.addBot(esc(this.cfg.greeting || '¡Hola! Soy tu asistente de soporte. Cuéntame qué está pasando (qué equipo o aplicación y qué error ves) y lo revisamos juntos.'));
  };

  /* ---------------------------------------------------------------- adjuntos */

  DeskChat.prototype.flashError = function (msgs) {
    var box = this.cfg.chipsBox; if (!box || !msgs.length) return;
    var d = document.createElement('div');
    d.className = 'dc-err';
    d.innerHTML = msgs.map(esc).join('<br>');
    box.insertBefore(d, box.firstChild);
    setTimeout(function () { d.remove(); }, 7000);
  };

  DeskChat.prototype.addFiles = function (fileList) {
    var errs = [], self = this;
    Array.prototype.forEach.call(fileList, function (f) {
      var name = f.name || ('captura-' + Date.now() + '.png');
      if (!f.name) { try { f = new File([f], name, { type: f.type || 'image/png' }); } catch (e) { /* navegador antiguo */ } }
      if (self.files.length >= MAX_FILES) { errs.push(name + ': máximo ' + MAX_FILES + ' archivos por mensaje.'); return; }
      if (ALLOWED.indexOf(ext(name)) < 0) { errs.push(name + ': formato no permitido (.' + (ext(name) || '?') + ').'); return; }
      if (f.size > MAX_BYTES) { errs.push(name + ': pesa ' + fmtSize(f.size) + ' y el máximo es ' + fmtSize(MAX_BYTES) + '.'); return; }
      if (f.size === 0) { errs.push(name + ': el archivo está vacío.'); return; }
      if (self.files.some(function (x) { return x.name === name && x.size === f.size; })) { errs.push(name + ': ya está en la lista.'); return; }
      self.files.push(f);
    });
    this.renderChips();
    this.flashError(errs);
  };

  DeskChat.prototype.removeFile = function (i) {
    var f = this.files[i];
    if (f && f._url) URL.revokeObjectURL(f._url);
    this.files.splice(i, 1);
    this.renderChips();
  };

  DeskChat.prototype.renderChips = function (uploadPct) {
    var box = this.cfg.chipsBox, self = this;
    if (!box) return;
    box.querySelectorAll('.dc-chip').forEach(function (n) { n.remove(); });
    this.files.forEach(function (f, i) {
      var chip = document.createElement('span');
      chip.className = 'dc-chip' + (typeof uploadPct === 'number' ? ' dc-up' : '');
      var thumb;
      if (/^image\//.test(f.type)) { f._url = f._url || URL.createObjectURL(f); thumb = '<img src="' + f._url + '" alt="">'; }
      else thumb = '<i class="fas ' + icon(f.name) + ' text-primary"></i>';
      chip.innerHTML = thumb + '<span class="dc-name" title="' + esc(f.name) + '">' + esc(f.name) + '</span><span class="text-muted">' + fmtSize(f.size) + '</span>' +
        (typeof uploadPct === 'number' ? '<span class="text-primary">' + uploadPct + '%</span>' : '<button type="button" title="Quitar" aria-label="Quitar ' + esc(f.name) + '">&times;</button>');
      var btn = chip.querySelector('button');
      if (btn) btn.addEventListener('click', function () { self.removeFile(i); });
      box.appendChild(chip);
    });
    box.style.display = (this.files.length || box.querySelector('.dc-err')) ? '' : 'none';
    if (this.cfg.attachBtn) {
      var n = this.files.length;
      this.cfg.attachBtn.title = n ? n + ' archivo(s) listo(s) para enviar' : 'Adjuntar archivos (imágenes, PDF, documentos)';
      this.cfg.attachBtn.classList.toggle('text-primary', n > 0);
    }
  };

  /* ---------------------------------------------------------------- red */

  DeskChat.prototype.post = function (formData, onProgress) {
    var url = this.cfg.endpoint;
    return new Promise(function (resolve, reject) {
      var xhr = new XMLHttpRequest();
      xhr.open('POST', url);
      xhr.timeout = 150000;
      xhr.responseType = 'text';
      if (xhr.upload && onProgress) xhr.upload.onprogress = function (e) { if (e.lengthComputable) onProgress(Math.round(e.loaded / e.total * 100)); };
      xhr.onload = function () {
        var json;
        try { json = JSON.parse(xhr.responseText); }
        catch (e) { reject(new Error('El servidor respondió algo inesperado (HTTP ' + xhr.status + '). Si el problema persiste, avisa a soporte.')); return; }
        resolve(json);
      };
      xhr.onerror = function () { reject(new Error('No hay conexión con el servidor. Tus datos y archivos siguen aquí: inténtalo de nuevo.')); };
      xhr.ontimeout = function () { reject(new Error('El servidor tardó demasiado en responder. Tus datos y archivos siguen aquí: inténtalo de nuevo.')); };
      xhr.send(formData);
    });
  };

  DeskChat.prototype.fd = function (obj) {
    var f = new FormData();
    f.append('csrf_token', this.cfg.csrf);
    Object.keys(obj || {}).forEach(function (k) { f.append(k, obj[k]); });
    return f;
  };

  DeskChat.prototype.setBusy = function (b) {
    this.busy = b;
    var c = this.cfg;
    if (c.sendBtn) c.sendBtn.disabled = b;
    if (c.input) c.input.disabled = b;
    if (c.attachBtn) c.attachBtn.disabled = b;
    if (!b && c.input) { try { c.input.focus(); } catch (e) { } }
  };

  /* ---------------------------------------------------------------- enviar */

  DeskChat.prototype.send = async function (rawText) {
    if (this.busy) return;
    var text = (rawText || '').trim();
    var files = this.files.slice();
    if (!text && !files.length) return;

    var self = this, c = this.cfg;
    this.clearQuick();
    this.addUser(text, files);
    if (c.input) c.input.value = '';
    this.setBusy(true);
    var typing = this.addTyping(files.length ? 'Subiendo ' + files.length + ' archivo(s)…' : '');
    var label = typing.querySelector('.dc-typing-label');
    if (files.length) this.renderChips(0);

    var fd = this.fd({ message: text });
    files.forEach(function (f) { fd.append('attachments[]', f, f.name); });

    var res;
    try {
      res = await this.post(fd, files.length ? function (p) {
        if (label) label.textContent = p < 100 ? 'Subiendo archivos… ' + p + ' %' : 'Archivos recibidos, analizando…';
        self.renderChips(p);
      } : null);
    } catch (err) {
      typing.remove();
      this.addBot(esc('⚠️ ' + err.message), { cls: 'border-warning' });
      if (c.input) c.input.value = text;          // no se pierde lo escrito
      this.renderChips();                         // ni los archivos
      this.setBusy(false);
      return;
    }
    typing.remove();

    if (res.status === 'success') {
      this.files.forEach(function (f) { if (f._url) URL.revokeObjectURL(f._url); });
      this.files = [];
      this.renderChips();
      if (res.conversation_id) this.convId = res.conversation_id;
      this.addBot(res.reply || '');
      if (res.ticket_id) this.addTicketLink(res.ticket_id, res.local_id);
      this.showQuick(res.quick);
      if (res.ticket_id || !this.listLoaded) this.refreshList();
    } else {
      this.addBot(esc('⚠️ ' + (res.message || 'No se pudo enviar el mensaje.')), { cls: 'border-warning' });
      if (c.input && text) c.input.value = text;
      this.renderChips();
      if (res.code === 'csrf') this.addBot(esc('Recarga la página para continuar.'));
    }
    this.setBusy(false);
  };

  DeskChat.prototype.addTicketLink = function (id, localId) {
    var a = document.createElement('div');
    a.className = 'mb-3';
    a.innerHTML = '<a class="btn btn-sm btn-success" href="ticket_details.php?id=' + encodeURIComponent(id) + '"><i class="fas fa-ticket me-1"></i>Ver ticket #' + esc(localId || id) + '</a>';
    this.cfg.container.appendChild(a);
    this.scroll();
  };

  /* ---------------------------------------------------------------- historial */

  DeskChat.prototype.renderMessages = function (res) {
    var self = this;
    this.cfg.container.innerHTML = '';
    if (!res.messages || !res.messages.length) { this.showGreeting(); }
    else {
      res.messages.forEach(function (m) {
        if (m.role === 'user') self.bubble('user', String(m.content).replace(/\n/g, '<br>') + self.filesHtml(m.files));
        else self.bubble('bot', md(m.content));
      });
    }
    var pend = res.attachments_pending || [];
    if (pend.length) {
      this.bubble('bot', '📎 Tienes ' + pend.length + ' archivo(s) listo(s) para tu ticket: ' + pend.map(function (p) { return esc(p.name); }).join(', ') + '.');
    }
    this.showQuick(res.quick);
    this.scroll();
  };

  DeskChat.prototype.renderList = function (list, currentId) {
    var menu = this.cfg.historyMenu, self = this;
    if (!menu) return;
    this.listLoaded = true;
    menu.innerHTML = '';
    if (!list || !list.length) { menu.innerHTML = '<li><span class="dropdown-item-text text-muted small">Aún no hay conversaciones.</span></li>'; return; }
    list.forEach(function (c) {
      var li = document.createElement('li');
      var when = (c.updated_at || '').replace(/^\d{4}-(\d\d)-(\d\d) (\d\d:\d\d).*$/, '$2/$1 $3');
      li.innerHTML = '<div class="dropdown-item d-flex align-items-center justify-content-between gap-2' + (Number(c.id) === Number(currentId || self.convId) ? ' active' : '') + '" style="cursor:pointer;">' +
        '<span style="min-width:0"><span class="d-block text-truncate small" style="max-width:210px">' + (c.ticket_id ? '🎫 ' : '') + esc(c.title) + '</span><span class="d-block" style="font-size:.68rem;opacity:.7">' + esc(when) + '</span></span>' +
        '<button type="button" class="btn btn-sm btn-link text-danger p-0" title="Eliminar conversación"><i class="fas fa-trash-can"></i></button></div>';
      li.querySelector('.dropdown-item').addEventListener('click', function (e) {
        if (e.target.closest('button')) return;
        self.loadConversation(c.id);
      });
      li.querySelector('button').addEventListener('click', function (e) { e.stopPropagation(); self.deleteConversation(c.id); });
      menu.appendChild(li);
    });
  };

  DeskChat.prototype.refreshList = async function () {
    try {
      var res = await this.post(this.fd({ action: 'conversations' }));
      if (res.status === 'success') this.renderList(res.conversations, res.conversation_id);
    } catch (e) { /* el historial es accesorio */ }
  };

  DeskChat.prototype.loadCurrent = async function () {
    if (this.loaded) return;
    this.loaded = true;
    try {
      var res = await this.post(this.fd({ action: 'current' }));
      if (res.status === 'success') { this.convId = res.conversation_id; this.renderMessages(res); this.renderList(res.conversations, res.conversation_id); return; }
    } catch (e) { /* si falla se queda el saludo */ }
    this.showGreeting();
  };

  DeskChat.prototype.loadConversation = async function (id) {
    if (this.busy) return;
    try {
      var res = await this.post(this.fd({ action: 'load', id: id }));
      if (res.status === 'success') { this.convId = res.conversation_id; this.renderMessages(res); this.renderList(res.conversations, res.conversation_id); }
      else this.addBot(esc('⚠️ ' + (res.message || 'No pude abrir esa conversación.')));
    } catch (e) { this.addBot(esc('⚠️ ' + e.message)); }
  };

  DeskChat.prototype.newChat = async function () {
    if (this.busy) return;
    try {
      var res = await this.post(this.fd({ action: 'new' }));
      if (res.status === 'success') { this.convId = res.conversation_id; this.files = []; this.renderChips(); this.showGreeting(); this.renderList(res.conversations, res.conversation_id); }
    } catch (e) { this.addBot(esc('⚠️ ' + e.message)); }
  };

  DeskChat.prototype.deleteConversation = async function (id) {
    if (!confirm('¿Eliminar esta conversación del historial?')) return;
    try {
      var res = await this.post(this.fd({ action: 'delete', id: id }));
      if (res.status === 'success') {
        if (res.was_current) { this.convId = res.conversation_id; this.showGreeting(); }
        this.renderList(res.conversations, res.conversation_id);
      }
    } catch (e) { this.addBot(esc('⚠️ ' + e.message)); }
  };

  global.DeskChat = DeskChat;
})(window);
