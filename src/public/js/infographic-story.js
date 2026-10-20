// วาดกราฟของอินโฟกราฟิกแบบเล่าเรื่อง (วงกลม/แนวโน้ม) ด้วย ECharts — ใช้ window.MSC_IG2 จาก partial
(function () {
  function draw(opts) {
    var ig = window.MSC_IG2;
    if (!ig || !window.MscDashboard) { return; }
    ig.charts.forEach(function (c) {
      var box = document.getElementById(c.id);
      if (box) { MscDashboard.drawChart(box, { type: c.type, title: c.title }, c.data, ig.theme, { renderer: (opts && opts.renderer) || 'canvas' }); }
    });
  }
  window.MscStory = { draw: draw };
})();
