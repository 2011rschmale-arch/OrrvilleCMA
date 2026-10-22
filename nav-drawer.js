// nav-drawer.js — Orrville C&MA Church
// Hand-rolled mobile nav drawer using plain CSS transform + class toggling,
// instead of Bootstrap's Offcanvas component (which had a Firefox-specific
// rendering bug where the drawer would only display its first item).
// The top-level links are normal links (no Bootstrap dropdown toggle), so they
// navigate straight to their page; submenus are always visible in the panel.
(function () {
  var toggleBtn = document.getElementById('navToggleBtn');
  var closeBtn = document.getElementById('navDrawerCloseBtn');
  var drawer = document.getElementById('navMenu');
  var backdrop = document.getElementById('navDrawerBackdrop');
  var lastFocused;
  if (!toggleBtn || !drawer || !backdrop) return;

  function openDrawer() {
    lastFocused = document.activeElement;
    drawer.classList.add('nav-drawer-open');
    backdrop.classList.add('show');
    document.body.style.overflow = 'hidden';
    toggleBtn.setAttribute('aria-expanded', 'true');
    var firstFocusable = drawer.querySelector('button, a, [tabindex]:not([tabindex="-1"])');
    if (firstFocusable) firstFocusable.focus();
  }

  function closeDrawer() {
    drawer.classList.remove('nav-drawer-open');
    backdrop.classList.remove('show');
    document.body.style.overflow = '';
    toggleBtn.setAttribute('aria-expanded', 'false');
    if (lastFocused && typeof lastFocused.focus === 'function') lastFocused.focus();
  }

  toggleBtn.addEventListener('click', function () {
    if (drawer.classList.contains('nav-drawer-open')) closeDrawer();
    else openDrawer();
  });

  if (closeBtn) closeBtn.addEventListener('click', closeDrawer);
  backdrop.addEventListener('click', closeDrawer);

  // Escape key also closes it (standard keyboard-accessibility expectation).
  // Tapping the dimmed backdrop intentionally does nothing — only the X
  // (or Escape) closes the drawer.
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && drawer.classList.contains('nav-drawer-open')) closeDrawer();
    if (e.key === 'Tab' && drawer.classList.contains('nav-drawer-open')) {
      var focusable = Array.prototype.slice.call(drawer.querySelectorAll('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])'));
      if (!focusable.length) return;
      var first = focusable[0];
      var last = focusable[focusable.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  // Top-level nav links (About, Ministries, Worship, ...) are plain links that
  // go to the top of their page. If the visitor is already on that page the
  // browser would do nothing, so close the drawer and scroll to the very top.
  // Sub-menu items (with #anchors) keep their normal jump-to-section behavior.
  function samePage(a) {
    var url = new URL(a.href, window.location.href);
    var here = window.location.pathname.replace(/index\.html$/, '');
    var there = url.pathname.replace(/index\.html$/, '');
    return url.origin === window.location.origin && here === there;
  }

  document.querySelectorAll('#navMenu .nav-link, #navMenu .dropdown-item')
    .forEach(function (l) {
      l.addEventListener('click', function (e) {
        var isTopLevel = l.classList.contains('nav-link');
        var hasHash = l.getAttribute('href').indexOf('#') !== -1;
        if (isTopLevel && !hasHash && samePage(l)) {
          e.preventDefault();
          closeDrawer();
          window.scrollTo({ top: 0, behavior: 'auto' });
        } else {
          closeDrawer();
        }
      });
    });

  // If the window is resized up past the drawer breakpoint (e.g. rotating
  // a tablet, or a desktop window being restored), reset drawer state so it
  // doesn't get stuck "open" once it's no longer the mobile layout.
  window.addEventListener('resize', function () {
    if (window.innerWidth >= 992) closeDrawer();
  });
})();
