// bulletin-loader.js — Orrville C&MA Church
// Loads the weekly bulletin from bulletin-feed.php (server-side, sanitized copy of the
// published Google Doc) and shows it as normal page text so it uses the full width of
// any screen. If the feed is unavailable, falls back to the Google Doc iframe.
(function () {
  var box = document.getElementById('bulletin-doc');
  if (!box) return;

  function fallback() {
    var src = box.getAttribute('data-fallback-src') || '';
    if (src.indexOf('https://docs.google.com/') !== 0) return;
    var f = document.createElement('iframe');
    f.className = 'bulletin-document';
    f.src = src;
    f.title = 'Orrville C&MA Church weekly bulletin';
    box.textContent = '';
    box.classList.remove('is-loading');
    box.appendChild(f);
  }

  fetch('bulletin-feed.php', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
    .then(function (r) { if (!r.ok) throw new Error('bad status'); return r.json(); })
    .then(function (d) {
      if (!d || d.ok !== true || typeof d.html !== 'string' || !d.html) throw new Error('bad payload');
      box.innerHTML = d.html;   // already whitelisted/escaped server-side
      box.querySelectorAll('script,style,iframe,object,embed,form,link,meta').forEach(function (e) { e.remove(); });
      box.classList.remove('is-loading');
      box.classList.add('is-loaded');
      box.removeAttribute('aria-busy');
    })
    .catch(fallback);
})();
