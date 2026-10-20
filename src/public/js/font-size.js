// ปุ่มปรับขนาดตัวอักษร 3 ระดับ (18 / 20 / 22px) — จำค่าไว้ในเครื่องผู้ใช้
(function () {
  var root = document.documentElement;
  function apply(size) {
    if (size === 'md' || !size) { root.removeAttribute('data-size'); } else { root.setAttribute('data-size', size); }
    document.querySelectorAll('.font-size button').forEach(function (b) {
      b.setAttribute('aria-pressed', String(b.dataset.size === (size || 'md')));
    });
  }
  var saved = null;
  try { saved = localStorage.getItem('msc-font-size'); } catch (e) {}
  apply(saved);
  document.addEventListener('click', function (e) {
    var b = e.target.closest('.font-size button');
    if (!b) { return; }
    apply(b.dataset.size);
    try { localStorage.setItem('msc-font-size', b.dataset.size); } catch (err) {}
  });
})();
