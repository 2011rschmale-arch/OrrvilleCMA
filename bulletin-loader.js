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
    box.classList.add('is-iframe');
    box.removeAttribute('aria-busy');
    // Google lays a published Doc out with wide built-in page margins, which looks
    // narrow on a phone. Offer the full-screen version first on small screens.
    if (window.matchMedia && window.matchMedia('(max-width: 767px)').matches) {
      var p = document.createElement('p');
      p.className = 'bulletin-readfull';
      var a = document.createElement('a');
      a.className = 'btn-navy';
      a.href = src.replace('?embedded=true', '');
      a.target = '_blank';
      a.rel = 'noopener';
      a.textContent = 'Read the Bulletin Full-Screen';
      p.appendChild(a);
      box.appendChild(p);
    }
    box.appendChild(f);
  }

  fetch('bulletin-feed.php', { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' })
    .then(function (r) {
      var ct = r.headers.get('content-type') || '';
      if (!r.ok || ct.indexOf('json') === -1) throw new Error('feed unavailable');
      return r.json();
    })
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
