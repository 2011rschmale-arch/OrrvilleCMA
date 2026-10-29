/* Orrville C&MA — optional "install as an app" prompt.
   - Visitors can ignore it or tap "Not now"; the site always works normally in the browser.
   - Android/Chrome: shows an Install button that uses the browser's own install dialog.
   - iPhone/iPad: Apple provides no install button, so we show a short Share > Add to Home Screen hint.
   - Never shown inside the installed app, on wide (desktop) screens, or for 30 days after "Not now". */
(function () {
  'use strict';

  var KEY = 'cmaInstallDismissed';
  var SNOOZE_MS = 30 * 24 * 60 * 60 * 1000;
  var deferredPrompt = null;
  var bar = null;

  function isInstalled() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
           window.navigator.standalone === true;
  }
  function isSmallScreen() {
    return window.matchMedia && window.matchMedia('(max-width: 900px)').matches;
  }
  function snoozed() {
    try {
      var t = parseInt(localStorage.getItem(KEY), 10);
      return t && (Date.now() - t) < SNOOZE_MS;
    } catch (e) { return false; }
  }
  function snooze() {
    try { localStorage.setItem(KEY, String(Date.now())); } catch (e) {}
  }
  function isIOS() {
    var ua = window.navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) ||
           (ua.indexOf('Macintosh') > -1 && window.navigator.maxTouchPoints > 1);
  }

  function hide() {
    if (bar && bar.parentNode) bar.parentNode.removeChild(bar);
    bar = null;
  }

  function build(message, actionLabel, onAction) {
    if (bar || isInstalled() || snoozed() || !isSmallScreen()) return;

    bar = document.createElement('div');
    bar.setAttribute('role', 'region');
    bar.setAttribute('aria-label', 'Install the Orrville C&MA app');
    bar.style.cssText =
      'position:fixed;left:12px;right:12px;bottom:calc(12px + env(safe-area-inset-bottom,0px));' +
      'z-index:900;display:flex;align-items:center;gap:10px;flex-wrap:wrap;' +
      'background:#253a40;color:#faf6ef;border-radius:12px;padding:12px 14px;' +
      'box-shadow:0 6px 24px rgba(0,0,0,.28);font:500 15px/1.35 system-ui,-apple-system,Segoe UI,sans-serif;' +
      'max-width:520px;margin:0 auto;';

    var text = document.createElement('span');
    text.style.cssText = 'flex:1 1 180px;';
    text.textContent = message;
    bar.appendChild(text);

    if (actionLabel) {
      var go = document.createElement('button');
      go.type = 'button';
      go.textContent = actionLabel;
      go.style.cssText =
        'background:#faf6ef;color:#253a40;border:0;border-radius:8px;padding:9px 14px;' +
        'font:600 15px system-ui,-apple-system,sans-serif;cursor:pointer;min-height:40px;';
      go.addEventListener('click', onAction);
      bar.appendChild(go);
    }

    var no = document.createElement('button');
    no.type = 'button';
    no.textContent = 'Not now';
    no.style.cssText =
      'background:transparent;color:#faf6ef;border:1px solid rgba(250,246,239,.55);border-radius:8px;' +
      'padding:9px 12px;font:500 15px system-ui,-apple-system,sans-serif;cursor:pointer;min-height:40px;';
    no.addEventListener('click', function () { snooze(); hide(); });
    bar.appendChild(no);

    document.body.appendChild(bar);
  }

  // Android / Chrome / Edge: the browser tells us when the site can be installed.
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();
    deferredPrompt = e;
    setTimeout(function () {
      build('Add Orrville C&MA to your home screen for quick access.', 'Install', function () {
        if (!deferredPrompt) return;
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then(function () { deferredPrompt = null; hide(); });
      });
    }, 4000);
  });

  window.addEventListener('appinstalled', function () { deferredPrompt = null; hide(); });

  // iPhone / iPad: no install event exists, so show a how-to hint instead.
  if (isIOS() && !isInstalled()) {
    setTimeout(function () {
      build('To add this site to your home screen: tap the Share button, then “Add to Home Screen.”', null, null);
    }, 4000);
  }
})();
