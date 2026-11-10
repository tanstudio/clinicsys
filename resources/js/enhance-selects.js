/* ============================================================
   Enhanced selects — custom branded listbox over native <select>.
   The real <select> stays in the DOM as the accessible, form- and
   test-compatible control; mouse interaction opens a styled popup
   instead of the OS one. Touch devices keep the native picker.
   No dependencies; progressive enhancement only.
   ============================================================ */
(function () {
  'use strict';

  var popup = null;
  var current = null;
  var options = [];
  var active = -1;

  function coarsePointer() {
    return window.matchMedia && window.matchMedia('(hover: none), (pointer: coarse)').matches;
  }

  function eligible(sel) {
    return sel && sel.tagName === 'SELECT' && !sel.disabled && !sel.multiple && sel.size <= 1;
  }

  function close() {
    if (popup && popup.parentNode) popup.parentNode.removeChild(popup);
    popup = null;
    if (current) {
      current.setAttribute('aria-expanded', 'false');
      if (current.getAttribute('aria-haspopup') === 'listbox') current.removeAttribute('aria-haspopup');
      current.removeAttribute('aria-activedescendant');
    }
    current = null;
    options = [];
    active = -1;
  }

  function itemEl(i) {
    return popup ? popup.querySelector('[data-i="' + i + '"]') : null;
  }

  function setActive(i, scroll) {
    if (!popup) return;
    active = i;
    for (var k = 0; k < options.length; k++) {
      var el = itemEl(k);
      if (el) {
        el.classList.toggle('is-active', k === i);
        el.id = 'select-menu-opt-' + k;
      }
    }
    if (current && i >= 0) current.setAttribute('aria-activedescendant', 'select-menu-opt-' + i);
    if (scroll) {
      var target = itemEl(i);
      if (target && target.scrollIntoView) target.scrollIntoView({ block: 'nearest' });
    }
  }

  function choose(i) {
    var sel = current;
    if (!sel || !options[i] || options[i].disabled) return;
    sel.selectedIndex = options[i].index;
    close();
    try { sel.focus({ preventScroll: true }); } catch (err) { try { sel.focus(); } catch (err2) {} }
    sel.dispatchEvent(new Event('input', { bubbles: true }));
    sel.dispatchEvent(new Event('change', { bubbles: true }));
  }

  function nextEnabled(from, step) {
    var n = options.length;
    if (!n) return -1;
    var i = from;
    for (var guard = 0; guard < n; guard++) {
      i = (i + step + n) % n;
      if (!options[i].disabled) return i;
    }
    return -1;
  }

  function open(sel) {
    close();
    current = sel;

    options = [];
    for (var i = 0; i < sel.options.length; i++) {
      options.push({
        index: i,
        text: sel.options[i].text,
        disabled: sel.options[i].disabled,
        selected: sel.options[i].selected
      });
    }

    popup = document.createElement('div');
    popup.className = 'select-menu';
    popup.setAttribute('role', 'listbox');

    for (var k = 0; k < options.length; k++) {
      var o = options[k];
      var item = document.createElement('div');
      item.className = 'select-option' + (o.disabled ? ' is-disabled' : '');
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', o.selected ? 'true' : 'false');
      if (o.disabled) item.setAttribute('aria-disabled', 'true');
      item.setAttribute('data-i', String(k));
      var text = document.createElement('span');
      text.className = 'select-option-text';
      text.textContent = o.text;
      var check = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      check.setAttribute('class', 'select-option-check');
      check.setAttribute('viewBox', '0 0 20 20');
      check.setAttribute('aria-hidden', 'true');
      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', 'm4 10.5 4 4 8-9');
      check.appendChild(path);
      item.appendChild(text);
      item.appendChild(check);
      popup.appendChild(item);
    }
    document.body.appendChild(popup);

    var rect = sel.getBoundingClientRect();
    var vw = window.innerWidth;
    var vh = window.innerHeight;
    popup.style.minWidth = Math.round(rect.width) + 'px';
    var ph = Math.min(popup.offsetHeight || 280, 280);
    var opensBelow = rect.bottom + 6 + ph <= vh - 8 || rect.top - 6 - ph < 8;
    popup.style.maxHeight = '280px';
    if (opensBelow) {
      popup.style.top = Math.round(rect.bottom + 6) + 'px';
      popup.style.bottom = 'auto';
    } else {
      popup.style.bottom = Math.round(vh - rect.top + 6) + 'px';
      popup.style.top = 'auto';
    }
    popup.style.left = Math.round(Math.max(8, Math.min(rect.left, vw - popup.offsetWidth - 8))) + 'px';

    sel.setAttribute('aria-expanded', 'true');
    sel.setAttribute('aria-haspopup', 'listbox');
    setActive(sel.selectedIndex >= 0 ? sel.selectedIndex : nextEnabled(-1, 1), true);
  }

  document.addEventListener('mousedown', function (e) {
    if (coarsePointer()) return;
    if (popup && popup.contains(e.target)) { e.preventDefault(); return; }
    var sel = e.target && e.target.closest ? e.target.closest('select') : null;
    if (eligible(sel)) {
      e.preventDefault();
      try { sel.focus({ preventScroll: true }); } catch (err) {}
      if (sel === current) { close(); return; }
      open(sel);
      return;
    }
    if (popup) close();
  }, true);

  popupEvents();
  function popupEvents() {
    document.addEventListener('click', function (e) {
      if (!popup) return;
      var item = e.target && e.target.closest ? e.target.closest('.select-option') : null;
      if (item) choose(Number(item.getAttribute('data-i')));
    });
    window.addEventListener('scroll', function () { if (popup) close(); }, true);
    window.addEventListener('resize', function () { if (popup) close(); });
    document.addEventListener('focusin', function (e) {
      if (popup && current && e.target !== current) close();
    });
  }

  document.addEventListener('keydown', function (e) {
    if (popup && current) {
      var i = active;
      var n = options.length;
      switch (e.key) {
        case 'ArrowDown': e.preventDefault(); setActive(nextEnabled(i, 1), true); return;
        case 'ArrowUp': e.preventDefault(); setActive(nextEnabled(i, -1), true); return;
        case 'Home': e.preventDefault(); setActive(nextEnabled(-1, 1), true); return;
        case 'End': e.preventDefault(); setActive(nextEnabled(n, -1), true); return;
        case 'Enter': case 'NumpadEnter': case ' ': e.preventDefault(); if (active >= 0) choose(active); return;
        case 'Escape': e.preventDefault(); var sel = current; close(); try { sel.focus({ preventScroll: true }); } catch (err) {} return;
        case 'Tab': close(); return;
      }
      return;
    }
    var target = e.target;
    if (!eligible(target)) return;
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp' || e.key === ' ') {
      e.preventDefault();
      open(target);
    }
  });
})();
