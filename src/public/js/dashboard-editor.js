// หน้าแก้ไข Dashboard: ลากวาง/ปรับขนาด (Gridstack), แก้แต่ละส่วน, เพิ่ม/ลบ, ดูตัวอย่างจากเซิร์ฟเวอร์ แล้วบันทึกเป็นเวอร์ชันใหม่
(function () {
  var E = window.MSC_EDITOR;
  var spec = JSON.parse(JSON.stringify(E.spec));
  var computed = E.computed;
  var cols = E.columns;
  var colById = {}; cols.forEach(function (c) { colById[c.id] = c; });
  var renders = {};
  var dirty = false;
  var msg = document.getElementById('msg');

  var AGGS = [['sum', 'ผลรวม'], ['count', 'นับจำนวนรายการ'], ['avg', 'ค่าเฉลี่ย'], ['max', 'สูงสุด'], ['min', 'ต่ำสุด'], ['median', 'มัธยฐาน'],
              ['count_distinct', 'นับค่าไม่ซ้ำ'], ['ratio', 'ร้อยละ (ตัวตั้ง ÷ ตัวหาร)'], ['percent_where', 'ร้อยละของแถวที่ตรงเงื่อนไข']];

  function say(text, kind) { msg.textContent = text; msg.className = 'editor-msg ' + (kind || ''); }
  function markDirty() { dirty = true; say('มีการแก้ไขที่ยังไม่บันทึก', 'warn'); }
  function post(url, body, method) {
    return fetch(url, { method: method || 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': E.csrf }, body: JSON.stringify(body) })
      .then(function (r) { return r.json().then(function (j) { j.status = r.status; return j; }); });
  }

  // ---------- หัวรายงาน ----------
  var fTitle = document.getElementById('f-title'), fSub = document.getElementById('f-subtitle'), fTheme = document.getElementById('f-theme');
  fTitle.value = spec.title || ''; fSub.value = spec.subtitle || ''; fTheme.value = spec.theme || 'org';
  fTitle.addEventListener('input', function () { spec.title = fTitle.value; markDirty(); });
  fSub.addEventListener('input', function () { spec.subtitle = fSub.value; markDirty(); });
  fTheme.addEventListener('change', function () { spec.theme = fTheme.value; markDirty(); redrawAll(); });

  // ---------- กริด ----------
  var grid = GridStack.init({ column: 12, cellHeight: 90, margin: 8, float: false, handle: '.w-handle', resizable: { handles: 'se' } }, '#grid');

  function itemHtml(w) {
    return '<div class="grid-stack-item-content card dash-item"><div class="w-tools"><span class="w-handle" title="ลากเพื่อย้าย">⠿ ย้าย</span>' +
      '<button type="button" class="w-edit" aria-label="แก้ไขส่วนนี้">✎ แก้ไข</button></div><div class="w-body"></div></div>';
  }

  function addToGrid(w) {
    var el = grid.addWidget({ id: w.id, x: w.layout.x, y: w.layout.y, w: w.layout.w, h: w.layout.h, content: '' });
    el.querySelector('.grid-stack-item-content').outerHTML = itemHtml(w);
    el.querySelector('.w-edit').addEventListener('click', function () { openPanel(w.id); });
    drawOne(w.id);
  }

  function drawOne(id) {
    var w = spec.widgets.find(function (x) { return x.id === id; });
    var el = document.querySelector('.grid-stack-item[gs-id="' + id + '"] .w-body');
    if (!w || !el) { return; }
    if (renders[id] && renders[id].dispose) { renders[id].dispose(); }
    renders[id] = MscDashboard.renderWidget(el, w, computed[id], spec.theme);
  }
  function redrawAll() { spec.widgets.forEach(function (w) { drawOne(w.id); }); }

  grid.on('change', function (e, items) {
    (items || []).forEach(function (it) {
      var w = spec.widgets.find(function (x) { return x.id === it.id; });
      if (w) { w.layout = { x: it.x, y: it.y, w: it.w, h: it.h }; }
      if (renders[it.id]) { renders[it.id].resize(); }
    });
    markDirty();
  });
  grid.on('resizestop', function (e, el) { var id = el.getAttribute('gs-id'); if (renders[id]) { renders[id].resize(); } });

  // ---------- ดูตัวอย่างจากเซิร์ฟเวอร์ ----------
  function preview(changedId) {
    say('กำลังคำนวณ…');
    return post(E.urls.preview, { spec: spec }).then(function (j) {
      if (!j.ok) { say((j.errors || ['บันทึกไม่ได้']).join(' · '), 'error'); return false; }
      computed = j.computed;
      // คง layout ที่ผู้ใช้จัดไว้ แต่รับค่าที่ผ่านการตรวจจากเซิร์ฟเวอร์
      j.spec.widgets.forEach(function (w) { var old = spec.widgets.find(function (x) { return x.id === w.id; }); if (old) { w.layout = old.layout; } });
      spec = Object.assign(spec, { widgets: j.spec.widgets });
      if (changedId) { drawOne(changedId); } else { redrawAll(); }
      say('มีการแก้ไขที่ยังไม่บันทึก', 'warn');
      return true;
    });
  }

  // ---------- แผงแก้ไข ----------
  var panel = document.getElementById('panel'), form = document.getElementById('panel-form'), editing = null;
  var measuresBox = document.getElementById('measures');

  function colOptions(filter, selected) {
    return cols.filter(filter).map(function (c) { return '<option value="' + c.id + '"' + (selected.indexOf(c.id) >= 0 ? ' selected' : '') + '>' + MscDashboard.esc(c.name) + '</option>'; }).join('');
  }

  function measureRow(m) {
    var div = document.createElement('div'); div.className = 'measure-row';
    var agg = m.agg || 'sum', c = m.columns || [];
    div.innerHTML =
      '<label>คำนวณ<select class="m-agg">' + AGGS.map(function (a) { return '<option value="' + a[0] + '"' + (a[0] === agg ? ' selected' : '') + '>' + a[1] + '</option>'; }).join('') + '</select></label>' +
      '<label class="m-col">คอลัมน์ (เลือกหลายคอลัมน์ = รวมกัน)<select class="m-cols" multiple size="3">' + colOptions(function () { return true; }, c) + '</select></label>' +
      '<label class="m-den">ตัวหาร<select class="m-den-cols" multiple size="3">' + colOptions(function (x) { return x.numeric; }, (m.denominator || {}).columns || []) + '</select></label>' +
      '<label class="m-where">เงื่อนไข<span style="display:flex;gap:.3rem"><select class="m-op">' + ['=', '!=', '>', '<', '>=', '<='].map(function (o) { return '<option' + (((m.where || {}).op) === o ? ' selected' : '') + '>' + o + '</option>'; }).join('') + '</select>' +
      '<input type="text" class="m-val" value="' + MscDashboard.esc((m.where || {}).value === undefined ? '' : m.where.value) + '"></span></label>' +
      '<label>ชื่อที่แสดง (ไม่บังคับ)<input type="text" class="m-label" value="' + MscDashboard.esc(m.label || '') + '"></label>' +
      '<button type="button" class="btn btn-ghost m-del" aria-label="ลบตัวเลขนี้">✕</button>';
    function sync() {
      var a = div.querySelector('.m-agg').value;
      div.querySelector('.m-col').style.display = a === 'count' && !(m.columns || []).length ? '' : '';
      div.querySelector('.m-den').style.display = a === 'ratio' ? '' : 'none';
      div.querySelector('.m-where').style.display = a === 'percent_where' ? '' : 'none';
    }
    div.querySelector('.m-agg').addEventListener('change', sync);
    div.querySelector('.m-del').addEventListener('click', function () { div.remove(); });
    sync();
    return div;
  }

  function syncPanel() {
    var t = form.type.value;
    form.querySelector('[data-for="dimension"]').style.display = ['bar', 'line', 'pie', 'table'].indexOf(t) >= 0 ? '' : 'none';
    form.querySelector('[data-for="target"]').style.display = t === 'gauge' ? '' : 'none';
    document.getElementById('btn-add-measure').style.display = ['bar', 'line', 'table'].indexOf(t) >= 0 ? '' : 'none';
    var dimCol = colById[form.dim.value];
    form.querySelector('[data-for="grain"]').style.display = dimCol && dimCol.type === 'date' ? '' : 'none';
    form.querySelector('[data-for="groupby"]').style.display = dimCol && dimCol.groups ? '' : 'none';
  }

  function openPanel(id) {
    editing = spec.widgets.find(function (x) { return x.id === id; });
    document.getElementById('panel-err').textContent = '';
    form.title.value = editing.title;
    form.type.value = editing.type;
    form.target.value = editing.target || '';
    var d = editing.dimension || {};
    form.dim.innerHTML = colOptions(function (c) { return !c.numeric; }, [d.column]);
    form.grain.value = d.time_grain || 'month';
    form.groupby.value = d.group_by === 'group' ? 'group' : 'label';
    form.sort.value = editing.sort || (editing.type === 'line' ? 'none' : 'desc');
    form.limit.value = editing.limit || '';
    measuresBox.innerHTML = '';
    (editing.measures || (editing.measure ? [editing.measure] : [{ agg: 'count' }])).forEach(function (m) { measuresBox.appendChild(measureRow(m)); });
    syncPanel();
    panel.showModal();
  }

  form.type.addEventListener('change', syncPanel);
  form.dim.addEventListener('change', syncPanel);
  document.getElementById('btn-add-measure').addEventListener('click', function () { measuresBox.appendChild(measureRow({ agg: 'sum' })); });
  document.getElementById('btn-cancel').addEventListener('click', function () { panel.close(); });
  document.getElementById('btn-delete').addEventListener('click', function () {
    if (!confirm('ลบส่วนนี้ออกจากรายงาน?')) { return; }
    spec.widgets = spec.widgets.filter(function (w) { return w !== editing; });
    grid.removeWidget(document.querySelector('.grid-stack-item[gs-id="' + editing.id + '"]'));
    panel.close(); markDirty();
  });

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var t = form.type.value;
    var measures = Array.prototype.map.call(measuresBox.querySelectorAll('.measure-row'), function (row) {
      var agg = row.querySelector('.m-agg').value;
      var m = { agg: agg };
      var c = Array.prototype.map.call(row.querySelector('.m-cols').selectedOptions, function (o) { return o.value; });
      if (c.length) { m.columns = c; }
      if (agg === 'ratio') { m.denominator = { columns: Array.prototype.map.call(row.querySelector('.m-den-cols').selectedOptions, function (o) { return o.value; }) }; }
      if (agg === 'percent_where') { var v = row.querySelector('.m-val').value; m.where = { op: row.querySelector('.m-op').value, value: isNaN(Number(v)) || v === '' ? v : Number(v) }; }
      var label = row.querySelector('.m-label').value.trim(); if (label) { m.label = label; }
      return m;
    });
    var w = { id: editing.id, type: t, title: form.title.value.trim() || editing.title, layout: editing.layout };
    if (['kpi', 'pie', 'gauge'].indexOf(t) >= 0) { w.measure = measures[0] || { agg: 'count' }; } else { w.measures = measures.length ? measures : [{ agg: 'count' }]; }
    if (['bar', 'line', 'pie', 'table'].indexOf(t) >= 0) {
      w.dimension = { column: form.dim.value };
      if (colById[form.dim.value] && colById[form.dim.value].type === 'date') { w.dimension.time_grain = form.grain.value; }
      if (colById[form.dim.value] && colById[form.dim.value].groups) { w.dimension.group_by = form.groupby.value; }
      w.sort = form.sort.value;
      if (form.limit.value) { w.limit = parseInt(form.limit.value, 10); }
    }
    if (t === 'gauge' && form.target.value !== '') { w.target = Number(form.target.value); }
    if (editing.filters && editing.filters.length) { w.filters = editing.filters; }

    var backup = JSON.parse(JSON.stringify(spec.widgets));
    var idx = spec.widgets.indexOf(editing);
    spec.widgets[idx] = w;
    preview(w.id).then(function (ok) {
      if (ok) { panel.close(); } else { spec.widgets = backup; document.getElementById('panel-err').textContent = msg.textContent; }
    });
  });

  document.getElementById('btn-add').addEventListener('click', function () {
    var id = 'w' + Date.now().toString(36);
    var maxY = spec.widgets.reduce(function (m, w) { return Math.max(m, w.layout.y + w.layout.h); }, 0);
    var w = { id: id, type: 'kpi', title: 'ส่วนใหม่', measure: { agg: 'count' }, layout: { x: 0, y: maxY, w: 3, h: 2 } };
    spec.widgets.push(w);
    preview(null).then(function (ok) { if (ok) { addToGrid(spec.widgets.find(function (x) { return x.id === id; })); openPanel(id); } });
  });

  // ---------- บันทึก ----------
  document.getElementById('btn-save').addEventListener('click', function () {
    var btn = this; btn.disabled = true; say('กำลังบันทึก…');
    post(E.urls.save, { spec: spec }, 'PUT').then(function (j) {
      btn.disabled = false;
      if (!j.ok) { say((j.errors || ['บันทึกไม่สำเร็จ']).join(' · '), 'error'); return; }
      dirty = false; say('บันทึกแล้ว — เวอร์ชัน ' + j.version, 'ok');
      setTimeout(function () { window.location = E.urls.show; }, 600);
    }).catch(function () { btn.disabled = false; say('บันทึกไม่สำเร็จ (เครือข่าย)', 'error'); });
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  (document.fonts ? document.fonts.ready : Promise.resolve()).then(function () {
    grid.batchUpdate();
    spec.widgets.forEach(addToGrid);
    grid.batchUpdate(false);
    say('');
  });
})();
