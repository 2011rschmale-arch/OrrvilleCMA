// core-values.js — Orrville C&MA Church homepage
// Turns the 4 "Core Values" cards into an accordion of expandable buttons.
//
// Interaction model:
//  - Click / tap / keyboard Enter-Space: toggles the card open or closed,
//    and stays open until the user closes it (click again, or opens
//    another card).
//  - Hovering a card with the mouse for 3+ seconds also opens it, as a
//    "hidden function" a visitor can discover — but since nothing was
//    explicitly clicked, moving the mouse away closes it again.
//  - Only one card is ever open at a time (opening one closes the others),
//    regardless of which trigger opened them.
(function () {
  var HOVER_DELAY_MS = 3000;
  var cards = Array.prototype.slice.call(document.querySelectorAll('[data-cv-card]'));
  if (!cards.length) return;

  // Per-card state: whether it's open, and whether that open state came
  // from a click (persists) or a hover-hold (should collapse on mouseleave).
  var state = new WeakMap();
  cards.forEach(function (card) {
    state.set(card, { open: false, openedByHover: false, hoverTimer: null });
  });

  function setExpandFocusable(card, focusable) {
    // While a panel is visually collapsed (max-height:0), keep its links out
    // of the tab order so keyboard users can't tab into invisible content.
    var links = card.querySelectorAll('.val-expand a');
    links.forEach(function (a) {
      if (focusable) a.removeAttribute('tabindex');
      else a.setAttribute('tabindex', '-1');
    });
  }

  function closeCard(card) {
    var s = state.get(card);
    if (!s.open) return;
    s.open = false;
    s.openedByHover = false;
    card.classList.remove('cv-open');
    card.querySelector('.val-toggle').setAttribute('aria-expanded', 'false');
    setExpandFocusable(card, false);
  }

  function openCard(card, viaHover) {
    // Accordion: close every other open card first.
    cards.forEach(function (other) {
      if (other !== card) closeCard(other);
    });
    var s = state.get(card);
    s.open = true;
    s.openedByHover = !!viaHover;
    card.classList.add('cv-open');
    card.querySelector('.val-toggle').setAttribute('aria-expanded', 'true');
    setExpandFocusable(card, true);
  }

  cards.forEach(function (card) {
    var toggle = card.querySelector('.val-toggle');
    var s = state.get(card);

    // Click / tap / keyboard activation: toggle, and always persists (does
    // not auto-collapse on mouseleave even if the mouse happens to leave).
    toggle.addEventListener('click', function () {
      if (s.open) {
        closeCard(card);
      } else {
        openCard(card, false);
      }
    });

    // 3-second hover-hold reveal (mouse users only — touch devices don't
    // fire sustained hover, but they already get instant reveal via click).
    card.addEventListener('mouseenter', function () {
      if (s.open) return; // already open, nothing to do
      s.hoverTimer = setTimeout(function () {
        openCard(card, true);
      }, HOVER_DELAY_MS);
    });

    card.addEventListener('mouseleave', function () {
      if (s.hoverTimer) {
        clearTimeout(s.hoverTimer);
        s.hoverTimer = null;
      }
      // Only auto-collapse if THIS card's open state came from hovering,
      // not from a click.
      if (s.open && s.openedByHover) closeCard(card);
    });

    // Start with expand panels non-focusable since they start closed.
    setExpandFocusable(card, false);
  });
})();
