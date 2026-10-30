/* Per-panel inbound picker for product forms.
 * For every selected x-ui_single panel it renders its own section and loads that panel's inbounds from
 * product_inbounds.php (panel chosen by id on the server). Fields: inb[<panelId>][], inb_loaded[<panelId>],
 * inb_remove[<panelId>]. A section whose list failed to load submits no inb_loaded flag, so the server
 * leaves that panel's saved mapping untouched. All text is set via textContent (remarks come from panels). */
(function () {
  var instances = {};

  function el(tag, props, text) {
    var node = document.createElement(tag);
    Object.keys(props || {}).forEach(function (k) { node.setAttribute(k, props[k]); });
    if (text !== undefined) node.textContent = text;
    return node;
  }

  function Picker(cfg) {
    this.cfg = cfg;
    this.initial = cfg.initial || {};
    this.sections = {};
    var self = this;
    cfg.picker.addEventListener('click', function () { setTimeout(function () { self.render(); }, 0); });
    this.render();
  }

  Picker.prototype.selectedNames = function () {
    return String(this.cfg.hidden.value || '').split(',').map(function (v) { return v.trim(); }).filter(Boolean);
  };

  Picker.prototype.setInitial = function (map) {
    this.initial = map || {};
    Object.keys(this.sections).forEach(function (id) { this.sections[id].box.remove(); }, this);
    this.sections = {};
    this.render();
  };

  Picker.prototype.render = function () {
    var root = this.cfg.root, meta = this.cfg.meta, names = this.selectedNames(), self = this;
    var isAll = names.indexOf('/all') !== -1;
    var wanted = {};
    if (!isAll) {
      names.forEach(function (name) {
        var m = meta[name];
        if (m && m.type === 'x-ui_single') wanted[m.id] = name;
      });
    }
    Object.keys(this.sections).forEach(function (id) {
      if (!wanted[id]) { self.sections[id].box.remove(); delete self.sections[id]; }
    });
    var note = root.querySelector('[data-pi-note]');
    if (isAll && Object.keys(this.initial).length) {
      if (!note) {
        note = el('div', { 'data-pi-note': '1', style: 'color:var(--text-muted);font-size:12px;margin:6px 0;' },
          'برای «تمامی پنل‌ها» انتخاب اینباند برای هر پنل غیرفعال است؛ نگاشت‌های ذخیره‌شده نگه داشته می‌شوند اما تا زمانی که محصول روی پنل‌های مشخص نباشد استفاده نمی‌شوند.');
        root.appendChild(note);
      }
    } else if (note) {
      note.remove();
    }
    Object.keys(wanted).forEach(function (id) {
      if (!self.sections[id]) self.addSection(parseInt(id, 10), wanted[id]);
    });
  };

  Picker.prototype.addSection = function (panelId, name) {
    var self = this, m = this.cfg.meta[name];
    var box = el('div', { 'data-pi-section': String(panelId), style: 'border:1px solid var(--border, #ddd);border-radius:8px;padding:10px;margin:8px 0;' });
    box.appendChild(el('div', { style: 'font-weight:600;margin-bottom:6px;' }, 'اینباندهای پنل «' + name + '»'));
    var body = el('div');
    box.appendChild(body);
    this.cfg.root.appendChild(box);
    this.sections[panelId] = { box: box, body: body };

    function load() {
      body.textContent = 'در حال دریافت اینباندها…';
      fetch(self.cfg.endpoint + '?panel_id=' + encodeURIComponent(panelId), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) {
          if (!self.sections[panelId]) return;
          if (!j.ok) return fail(j.message || 'خطا');
          show(j);
        })
        .catch(function () { if (self.sections[panelId]) fail('ارتباط با سرور برقرار نشد'); });
    }

    function fail(message) {
      body.textContent = '';
      body.appendChild(el('div', { style: 'color:#c0392b;' }, 'دریافت اینباندها ممکن نیست: ' + message + ' — تنظیمات ذخیره‌شده این پنل بدون تغییر می‌ماند.'));
      var retry = el('button', { type: 'button', class: 'btn btn-sm btn-soft-info' }, 'تلاش مجدد');
      retry.addEventListener('click', load);
      body.appendChild(retry);
    }

    function show(j) {
      body.textContent = '';
      var single = !j.token_mode;
      var saved = (self.initial[panelId] || []).map(Number);
      var live = {};
      j.inbounds.forEach(function (ib) { live[ib.id] = true; });
      if (single) {
        body.appendChild(el('div', { style: 'color:#b9770e;font-size:12px;margin-bottom:6px;' },
          'این پنل با روش ورود قدیمی (کوکی) کار می‌کند: فقط یک اینباند قابل انتخاب است و مشتریان فعلی به‌صورت خودکار جابه‌جا نمی‌شوند. برای همگام‌سازی خودکار، پنل را به حالت توکن ببرید.'));
      }
      var rows = j.inbounds.slice();
      saved.forEach(function (id) { if (!live[id]) rows.push({ id: id, remark: '', protocol: '', port: 0, enable: false, missing: true }); });
      rows.forEach(function (ib) {
        var label = el('label', { style: 'display:block;margin:3px 0;' });
        var input = el('input', { type: single ? 'radio' : 'checkbox', name: 'inb[' + panelId + '][]', value: String(ib.id) });
        if (saved.indexOf(ib.id) !== -1) input.checked = true;
        label.appendChild(input);
        var text = ' #' + ib.id + (ib.remark ? ' — ' + ib.remark : '') + (ib.protocol ? ' (' + ib.protocol + (ib.port ? ':' + ib.port : '') + ')' : '');
        if (ib.missing) text += ' — روی پنل پیدا نشد';
        else if (!ib.enable) text += ' — غیرفعال';
        label.appendChild(document.createTextNode(text));
        body.appendChild(label);
      });
      body.appendChild(el('input', { type: 'hidden', name: 'inb_loaded[' + panelId + ']', value: '1' }));
      if (self.cfg.allowRemove && saved.length) {
        var rm = el('label', { style: 'display:block;margin-top:8px;color:#c0392b;' });
        rm.appendChild(el('input', { type: 'checkbox', name: 'inb_remove[' + panelId + ']', value: '1' }));
        rm.appendChild(document.createTextNode(' حذف نگاشت صریح این پنل (خریدهای بعدی به منطق قدیمی برمی‌گردند؛ مشتریان فعلی به‌صورت خودکار برنمی‌گردند)'));
        body.appendChild(rm);
      }
    }
    load();
  };

  window.FxProductInbounds = {
    init: function (cfg) {
      cfg.endpoint = cfg.endpoint || 'product_inbounds.php';
      instances[cfg.id] = new Picker(cfg);
      return instances[cfg.id];
    },
    get: function (id) { return instances[id]; }
  };
})();
