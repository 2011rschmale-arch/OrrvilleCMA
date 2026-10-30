/* Orrville C&MA - optional "install as an app" prompt + offline support.
   - Visitors can ignore it or tap "Not now"; the site always works normally in the browser.
   - Android/Chrome: shows an Install button that uses the browser's own install dialog.
   - iPhone/iPad: Apple provides no install button, so we show a short Share > Add to Home Screen hint.
   - Once the app is installed (or the visitor says they added it) the prompt stops appearing in the browser.
   - Never shown inside the installed app or on wide (desktop) screens.
   For testing: add ?pwa=reset to any page address to clear the saved choices. */
(function () {
  'use strict';

  var SNOOZE_KEY = 'cmaInstallDismissed';   // "Not now"   -> hidden for 30 days
  var DONE_KEY   = 'cmaInstalled';          // installed    -> hidden for 180 days
  var SESSION_KEY = 'cmaInstallShown';      // shown once per browsing session
  var SNOOZE_MS = 30 * 24 * 60 * 60 * 1000;
  var DONE_MS   = 180 * 24 * 60 * 60 * 1000;
  var deferredPrompt = null;
  var bar = null;

  function stamp(key) { try { localStorage.setItem(key, String(Date.now())); } catch (e) {} }
  function within(key, ms) {
    try { var t = parseInt(localStorage.getItem(key), 10); return !!t && (Date.now() - t) < ms; }
    catch (e) { return false; }
  }
  function unstamp(key) { try { localStorage.removeItem(key); } catch (e) {} }

  function isStandalone() {
    return (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) ||
           window.navigator.standalone === true;
  }
  function isSmallScreen() { return window.matchMedia && window.matchMedia('(max-width: 900px)').matches; }
  function isIOS() {
    var ua = window.navigator.userAgent || '';
    return /iPad|iPhone|iPod/.test(ua) || (ua.indexOf('Macintosh') > -1 && window.navigator.maxTouchPoints > 1);
  }
  function shownThisSession() { try { return sessionStorage.getItem(SESSION_KEY) === '1'; } catch (e) { return false; } }
  function markShown() { try { sessionStorage.setItem(SESSION_KEY, '1'); } catch (e) {} }

  function suppressed() {
    return isStandalone() || within(DONE_KEY, DONE_MS) || within(SNOOZE_KEY, SNOOZE_MS) ||
           shownThisSession() || !isSmallScreen();
  }
  function hide() { if (bar && bar.parentNode) bar.parentNode.removeChild(bar); bar = null; }
  function markInstalled() { stamp(DONE_KEY); deferredPrompt = null; hide(); }

  /* ---- Test helper ---- */
  if (/[?&]pwa=reset(&|$)/.test(window.location.search)) {
    unstamp(SNOOZE_KEY); unstamp(DONE_KEY);
    try { sessionStorage.removeItem(SESSION_KEY); } catch (e) {}
  }

  /* ---- Remember installs ---- */
  // Opened as the installed app: remember it. (On Android the app and the browser share this storage,
  // so the browser stops asking. iPhone keeps them separate, which is why iPhone has an "I added it" button.)
  if (isStandalone()) { stamp(DONE_KEY); }

  // Android Chrome can also tell us directly whether this site's app is already installed.
  if (!isStandalone() && navigator.getInstalledRelatedApps) {
    navigator.getInstalledRelatedApps().then(function (apps) {
      if (apps && apps.length) { markInstalled(); }
    }).catch(function () {});
  }
  window.addEventListener('appinstalled', markInstalled);

  /* ---- The prompt bar ---- */
  function button(label, solid, handler) {
    var b = document.createElement('button');
    b.type = 'button';
    b.textContent = label;
    b.style.cssText = solid
      ? 'background:#faf6ef;color:#253a40;border:0;border-radius:8px;padding:9px 14px;font:600 15px system-ui,-apple-system,sans-serif;cursor:pointer;min-height:40px;'
      : 'background:transparent;color:#faf6ef;border:1px solid rgba(250,246,239,.55);border-radius:8px;padding:9px 12px;font:500 15px system-ui,-apple-system,sans-serif;cursor:pointer;min-height:40px;';
    b.addEventListener('click', handler);
    return b;
  }

  function build(message, buttons) {
    if (bar || suppressed()) return;
    markShown();

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
    buttons.forEach(function (b) { bar.appendChild(b); });
    document.body.appendChild(bar);
  }

  function notNow() { return button('Not now', false, function () { stamp(SNOOZE_KEY); hide(); }); }

  // Android / Chrome / Edge: the browser tells us when the site can be installed.
  window.addEventListener('beforeinstallprompt', function (e) {
    e.preventDefault();               // we show our own bar instead of the browser's mini-banner
    deferredPrompt = e;
    setTimeout(function () {
      if (!deferredPrompt) return;
      build('Add Orrville C&MA to your home screen for quick access.', [
        button('Install', true, function () {
          if (!deferredPrompt) return;
          deferredPrompt.prompt();
          deferredPrompt.userChoice.then(function (choice) {
            if (choice && choice.outcome === 'accepted') { markInstalled(); } else { stamp(SNOOZE_KEY); hide(); }
            deferredPrompt = null;
          });
        }),
        notNow()
      ]);
    }, 4000);
  });

  // iPhone / iPad: there is no install event, so show a how-to hint (at most once per session / 30 days).
  if (isIOS() && !isStandalone()) {
    setTimeout(function () {
      build('To add this site to your home screen: tap the Share button, then \u201cAdd to Home Screen.\u201d', [
        button('I added it', true, markInstalled),
        notNow()
      ]);
      if (bar) { stamp(SNOOZE_KEY); }   // it is only instructions, so show it once and leave the visitor alone for 30 days
    }, 4000);
  }

  /* ---- Offline support ---- */
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js').catch(function () { /* optional feature; ignore failures */ });
    });
  }
})();
