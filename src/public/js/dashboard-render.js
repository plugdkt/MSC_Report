// แสดงผล widget ของ Dashboard ด้วย ECharts — ใช้ร่วมกันทั้งหน้าดู หน้าแก้ไข และหน้าส่งออก
(function () {
  var THEMES = {
    org: ['#1f3b6e', '#b7822b', '#4f7cc4', '#1e7a4c', '#8fa7d1', '#b42318', '#6b5b95', '#5f9ea0'],
    formal: ['#2f4858', '#5b7083', '#8aa1b4', '#33658a', '#86bbd8', '#758e4f', '#f6ae2d', '#999999'],
    bright: ['#2f80ed', '#f2994a', '#27ae60', '#eb5757', '#9b51e0', '#f2c94c', '#56ccf2', '#219653'],
    green: ['#1b5e20', '#43a047', '#9ccc65', '#00897b', '#c0ca33', '#6d4c41', '#26a69a', '#7cb342']
  };

  function esc(s) { return String(s === null || s === undefined ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

  function fmt(v, unit) {
    if (v === null || v === undefined) { return '—'; }
    var s = Number(v).toLocaleString('th-TH', { minimumFractionDigits: unit === 'บาท' ? 2 : 0, maximumFractionDigits: unit === '%' ? 1 : 2 });
    return unit === '%' ? s + '%' : s;
  }

  function baseFont() { return Math.round(parseFloat(getComputedStyle(document.documentElement).fontSize) * 0.85); }

  function option(w, d, theme) {
    var fs = baseFont();
    var palette = THEMES[theme] || THEMES.org;
    var base = {
      textStyle: { fontFamily: 'Sarabun, Tahoma, sans-serif', fontSize: fs, color: '#445064' }, color: palette, animation: false,
      tooltip: { trigger: w.type === 'pie' || w.type === 'gauge' ? 'item' : 'axis', textStyle: { fontFamily: 'Sarabun', fontSize: fs } }
    };
    if (w.type === 'gauge') {
      return Object.assign(base, { series: [{
        type: 'gauge', min: 0, max: 100, startAngle: 200, endAngle: -20, center: ['50%', '60%'], radius: '95%',
        progress: { show: true, width: 18, itemStyle: { color: palette[0] } }, axisLine: { lineStyle: { width: 18, color: [[1, '#e6eaf0']] } },
        axisTick: { show: false }, splitLine: { show: false }, pointer: { show: false }, axisLabel: { show: false },
        anchor: { show: false }, title: { show: false },
        detail: { valueAnimation: false, offsetCenter: [0, '0%'], formatter: function (v) { return Number(v).toLocaleString('th-TH', { maximumFractionDigits: 1 }) + '%'; }, fontSize: fs * 2.2, fontWeight: 700, color: '#1b2330' },
        markPoint: undefined,
        data: [{ value: d.value }]
      }].concat(d.target ? [{
        type: 'gauge', min: 0, max: 100, startAngle: 200, endAngle: -20, center: ['50%', '60%'], radius: '95%',
        axisLine: { show: false }, axisTick: { show: false }, splitLine: { show: false }, axisLabel: { show: false }, detail: { show: false }, title: { show: false },
        pointer: { show: true, length: '22%', width: 5, offsetCenter: [0, '-78%'], itemStyle: { color: palette[1] } },
        data: [{ value: d.target }]
      }] : []) });
    }
    if (w.type === 'pie') {
      return Object.assign(base, { legend: { bottom: 0, type: 'scroll', textStyle: { fontSize: fs } },
        series: [{ type: 'pie', radius: ['38%', '68%'], center: ['50%', '45%'], label: { formatter: '{d}%', fontSize: fs, fontWeight: 700 },
          tooltip: { valueFormatter: function (v) { return fmt(v, d.series[0].unit) + (d.series[0].unit && d.series[0].unit !== '%' ? ' ' + d.series[0].unit : ''); } },
          data: d.categories.map(function (c, i) { return { name: c, value: d.series[0].data[i] }; }) }] });
    }
    var horizontal = w.type === 'bar' && d.categories.length > 6;
    var cat = { type: 'category', data: d.categories, axisLabel: { fontSize: fs, color: '#1b2330', width: horizontal ? 200 : 90, overflow: 'break', interval: 0 }, axisTick: { show: false } };
    var val = { type: 'value', axisLabel: { fontSize: fs, formatter: function (v) { return Math.abs(v) >= 1e6 ? (v / 1e6).toLocaleString('th-TH', { maximumFractionDigits: 1 }) + ' ลบ.' : Number(v).toLocaleString('th-TH'); } }, splitLine: { lineStyle: { color: '#e6eaf0' } } };
    return Object.assign(base, {
      legend: d.series.length > 1 ? { top: 0, textStyle: { fontSize: fs } } : undefined,
      grid: { left: 8, right: 24, top: d.series.length > 1 ? 36 : 12, bottom: 8, containLabel: true },
      xAxis: horizontal ? val : cat, yAxis: horizontal ? Object.assign(cat, { inverse: true }) : val,
      series: d.series.map(function (s) {
        return { name: s.name, type: w.type, data: s.data, symbolSize: 8, barMaxWidth: 36,
                 itemStyle: w.type === 'bar' ? { borderRadius: horizontal ? [0, 4, 4, 0] : [4, 4, 0, 0] } : undefined,
                 tooltip: { valueFormatter: function (v) { return fmt(v, s.unit) + (s.unit && s.unit !== '%' ? ' ' + s.unit : ''); } } };
      })
    });
  }

  // วาด widget หนึ่งชิ้นลงใน element — คืน object ที่มี resize()
  function renderWidget(el, w, d, theme) {
    el.innerHTML = '';
    var h = document.createElement('h2'); h.className = 'w-title'; h.textContent = w.title; el.appendChild(h);
    if (!d) { var e = document.createElement('p'); e.className = 'muted'; e.textContent = 'ไม่มีข้อมูล'; el.appendChild(e); return null; }

    if (w.type === 'kpi') {
      var big = d.unit === 'บาท' && Math.abs(d.value || 0) >= 1e6;
      var v = document.createElement('div'); v.className = 'kpi-value';
      v.textContent = big ? (d.value / 1e6).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : fmt(d.value, d.unit);
      var u = document.createElement('div'); u.className = 'muted'; u.textContent = big ? 'ล้านบาท' : (d.unit && d.unit !== '%' ? d.unit : '');
      el.appendChild(v); el.appendChild(u);
      if (big) { var s = document.createElement('div'); s.className = 'kpi-sub'; s.textContent = fmt(d.value, 'บาท') + ' บาท'; el.appendChild(s); }
      return null;
    }
    if (w.type === 'table') {
      var wrap = document.createElement('div'); wrap.className = 'table-wrap w-fill';
      var head = '<thead><tr>' + d.columns.map(function (c) { return '<th>' + esc(c.label) + (c.unit ? ' (' + esc(c.unit) + ')' : '') + '</th>'; }).join('') + '</tr></thead>';
      var body = d.rows.map(function (r, i) {
        var total = d.has_total && i === d.rows.length - 1;
        return '<tr' + (total ? ' class="total"' : '') + '>' + r.map(function (c, j) {
          return j === 0 && d.columns[0].unit === null ? '<td>' + esc(c) + '</td>' : '<td class="n">' + fmt(c, d.columns[j].unit) + '</td>';
        }).join('') + '</tr>';
      }).join('');
      wrap.innerHTML = '<table class="num-table">' + head + '<tbody>' + body + '</tbody></table>';
      el.appendChild(wrap);
      return null;
    }
    var box = document.createElement('div'); box.className = 'chart-box w-fill'; el.appendChild(box);
    var chart = echarts.init(box);
    chart.setOption(option(w, d, theme), true);
    return { resize: function () { chart.resize(); }, rerender: function () { chart.setOption(option(w, d, theme), true); chart.resize(); }, dispose: function () { chart.dispose(); } };
  }

  window.MscDashboard = { renderWidget: renderWidget, fmt: fmt, themes: THEMES, esc: esc };
})();
