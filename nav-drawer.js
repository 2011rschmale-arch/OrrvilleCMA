// nav-drawer.js — Orrville C&MA Church
// Hand-rolled mobile nav drawer using plain CSS transform + class toggling,
// instead of Bootstrap's Offcanvas component (which had a Firefox-specific
// rendering bug where the drawer would only display its first item).
// Bootstrap's Dropdown component is still used for the About/Ministries
// submenus — this file only controls the slide-in drawer itself.
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

  // Close the drawer when a real nav link or submenu item is clicked — but
  // NOT when a dropdown toggle (About / Ministries) is clicked, since that
  // should only expand its submenu in place, not close the whole drawer.
  document.querySelectorAll('#navMenu .nav-link:not(.dropdown-toggle), #navMenu .dropdown-item')
    .forEach(function (l) { l.addEventListener('click', closeDrawer); });

  // If the window is resized up past the drawer breakpoint (e.g. rotating
  // a tablet, or a desktop window being restored), reset drawer state so it
  // doesn't get stuck "open" once it's no longer the mobile layout.
  window.addEventListener('resize', function () {
    if (window.innerWidth >= 992) closeDrawer();
  });
})();
