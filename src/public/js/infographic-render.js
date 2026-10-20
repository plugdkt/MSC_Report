// วาดกราฟวงกลมของอินโฟกราฟิก (ใช้ window.MSC_IG จาก partial) — renderer: 'svg' สำหรับส่งออก
(function () {
  // ตัดชื่อเป็น 2 บรรทัดให้สมดุล: มีช่องว่างตัดที่ช่องว่างแรก, ภาษาไทยไม่มีช่องว่างใช้ Intl.Segmenter แบ่งตามคำ
  function wrap(name) {
    if (/\s/.test(name)) { return name.replace(/\s+/, '\n'); }
    if (name.length < 9 || !window.Intl || !Intl.Segmenter) { return name; }
    var words = Array.from(new Intl.Segmenter('th', { granularity: 'word' }).segment(name)).map(function (x) { return x.segment; });
    var out = '', half = name.length / 2, done = false;
    words.forEach(function (w) { if (!done && out.length >= half - 1) { out += '\n'; done = true; } out += w; });
    return out;
  }

  function draw(opts) {
    var ig = window.MSC_IG, pal = ig.palette;
    var chart = echarts.init(document.getElementById('ig-pie'), null, { renderer: (opts && opts.renderer) || 'canvas', devicePixelRatio: 2 });
    chart.setOption({
      animation: false,
      series: [
        { type: 'pie', radius: [0, '86%'], center: ['50%', '53%'], silent: true, label: { show: false }, data: [{ value: 1, itemStyle: { color: 'rgba(20,40,70,.16)' } }] },
        { type: 'pie', radius: [0, '84%'], center: ['50%', '50%'], startAngle: 90, clockwise: false, avoidLabelOverlap: false,
          itemStyle: { borderColor: '#fff', borderWidth: 4 },
          label: { show: true, position: 'inside', color: '#fff', fontFamily: 'Sarabun', fontWeight: 800,
                   formatter: function (p) { var s = p.data.pct < 25 ? 's' : ''; return '{n' + s + '|' + wrap(p.name) + '}\n{p' + s + '|' + p.data.pct + '%}'; },
                   rich: { n: { fontSize: 28, fontWeight: 700, lineHeight: 34, color: '#fff', align: 'center' }, p: { fontSize: 60, fontWeight: 800, lineHeight: 70, color: '#fff', align: 'center' },
                           ns: { fontSize: 22, fontWeight: 700, lineHeight: 28, color: '#fff', align: 'center' }, ps: { fontSize: 42, fontWeight: 800, lineHeight: 50, color: '#fff', align: 'center' } } },
          data: ig.parts.map(function (p) {
            return { name: p.label, value: Math.max(p.value, 0), pct: p.pct,
                     itemStyle: { color: new echarts.graphic.RadialGradient(0.42, 0.38, 0.9, [{ offset: 0, color: pal[p.color][3] }, { offset: 1, color: pal[p.color][0] }]) } };
          }) }
      ]
    });
    return chart;
  }
  window.MscInfographic = { draw: draw };
})();
