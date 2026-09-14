/* SS Portal – progressive enhancements. No framework, no API calls from the browser. */
(function () {
  'use strict';

  var $ = function (sel, root) { return (root || document).querySelector(sel); };
  var $$ = function (sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); };

  // ── Theme toggle (persisted) ──
  var themeBtn = $('#theme-toggle');
  if (themeBtn) {
    themeBtn.addEventListener('click', function () {
      var html = document.documentElement;
      var next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
      html.setAttribute('data-theme', next);
      try { localStorage.setItem('ssp_theme', next); } catch (e) { /* private mode */ }
    });
  }

  // ── Alerts: dismiss button, success messages fade out by themselves ──
  function dismiss(alert) {
    alert.classList.add('is-leaving');
    setTimeout(function () { if (alert.parentNode) { alert.parentNode.removeChild(alert); } }, 320);
  }
  $$('.alert-close').forEach(function (btn) {
    btn.addEventListener('click', function () { dismiss(btn.closest('.alert')); });
  });
  $$('.alerts .alert-success').forEach(function (a) { setTimeout(function () { dismiss(a); }, 8000); });

  // ── Confirm buttons that talk to the university ──
  $$('[data-confirm]').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      if (!window.confirm(btn.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  });

  // ── Clickable table rows ──
  $$('tr[data-href]').forEach(function (row) {
    row.addEventListener('click', function (ev) {
      if (ev.target.closest('a, button, input, select, label')) { return; }
      window.location.href = row.getAttribute('data-href');
    });
  });

  // ── "/" focuses the search box ──
  var search = $('#q');
  if (search) {
    document.addEventListener('keydown', function (ev) {
      if (ev.key === '/' && !ev.ctrlKey && !ev.metaKey && !ev.altKey && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName)) {
        ev.preventDefault();
        search.focus();
        search.select();
      }
    });
  }

  // ── Show / hide password ──
  $$('[data-toggle-password]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.getAttribute('data-toggle-password'));
      if (!input) { return; }
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('title', show ? 'Hide password' : 'Show password');
      input.focus();
    });
  });

  // ── Tabs (student page). Panels stay visible without JS. ──
  $$('[role="tablist"]').forEach(function (list) {
    var tabs = $$('[role="tab"]', list);
    function activate(tab, updateHash) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', on ? 'true' : 'false');
        t.tabIndex = on ? 0 : -1;
        var panel = document.getElementById(t.getAttribute('aria-controls'));
        if (panel) { panel.hidden = !on; }
      });
      if (updateHash && window.history.replaceState) {
        window.history.replaceState(null, '', '#' + tab.getAttribute('aria-controls'));
      }
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { activate(t, true); });
      t.addEventListener('keydown', function (ev) {
        var j = ev.key === 'ArrowRight' ? i + 1 : ev.key === 'ArrowLeft' ? i - 1 : -1;
        if (j < 0 || j >= tabs.length) { return; }
        ev.preventDefault();
        tabs[j].focus();
        activate(tabs[j], true);
      });
    });
    var initial = null;
    if (window.location.hash) {
      tabs.forEach(function (t) { if ('#' + t.getAttribute('aria-controls') === window.location.hash) { initial = t; } });
    }
    activate(initial || $('[aria-selected="true"]', list) || tabs[0], false);
  });

  // ── Student form ──
  var form = $('#student-form');
  if (!form) { return; }

  // Department → program filtering
  var dept = $('[data-role="department"]', form);
  var prog = $('[data-role="program"]', form);
  function filterPrograms() {
    if (!dept || !prog) { return; }
    var d = dept.value;
    var keepSelected = false;
    Array.prototype.forEach.call(prog.options, function (opt) {
      var od = opt.getAttribute('data-dept');
      var show = !od || od === d || d === '';
      opt.hidden = !show;
      opt.disabled = !show;
      if (opt.selected && show) { keepSelected = true; }
    });
    if (!keepSelected) { prog.value = ''; }
  }
  if (dept) { dept.addEventListener('change', filterPrograms); filterPrograms(); }

  // Academic qualification rows
  var rows = $('#qual-rows');
  var addBtn = $('#add-qual');
  var tpl = $('#qual-template');
  function bindRemove(row) {
    var btn = $('.btn-remove-row', row);
    if (btn) {
      btn.addEventListener('click', function () {
        if (rows.children.length > 1) { row.remove(); } else { $$('input', row).forEach(function (i) { i.value = ''; }); }
        markDirty();
      });
    }
  }
  if (rows) { Array.prototype.forEach.call(rows.children, bindRemove); }
  if (addBtn && tpl && rows) {
    addBtn.addEventListener('click', function () {
      var max = parseInt(addBtn.getAttribute('data-max') || '10', 10);
      if (rows.children.length >= max) { window.alert('At most ' + max + ' qualifications can be sent.'); return; }
      var next = 0;
      $$('input[name^="academic_qualifications["]', rows).forEach(function (i) {
        var m = /academic_qualifications\[(\d+)\]/.exec(i.name);
        if (m) { next = Math.max(next, parseInt(m[1], 10) + 1); }
      });
      var wrap = document.createElement('div');
      wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, String(next));
      var row = wrap.firstElementChild;
      rows.appendChild(row);
      bindRemove(row);
      var first = $('input', row);
      if (first) { first.focus(); }
    });
  }

  // Optional result block
  var resultToggle = $('#result-enabled');
  var resultFields = $('#result-fields');
  if (resultToggle && resultFields) {
    resultToggle.addEventListener('change', function () { resultFields.disabled = !resultToggle.checked; });
  }

  // Photo preview
  var photo = $('input[type="file"][name="photo"]', form);
  var preview = $('#photo-preview');
  if (photo && preview) {
    photo.addEventListener('change', function () {
      var f = photo.files && photo.files[0];
      if (!f) { preview.hidden = true; return; }
      if (f.size > 5 * 1024 * 1024) { window.alert('The photo is larger than 5 MB; the university will reject it.'); }
      preview.src = URL.createObjectURL(f);
      preview.hidden = false;
    });
  }

  // Internal documents: show the chosen file names under each slot
  $$('.file-slot input[type="file"]', form).forEach(function (input) {
    input.addEventListener('change', function () {
      var out = $('.file-chosen', input.closest('.file-slot'));
      if (!out) { return; }
      var names = Array.prototype.map.call(input.files || [], function (f) { return f.name; });
      out.textContent = names.length ? names.length + ' file' + (names.length > 1 ? 's' : '') + ' selected: ' + names.join(', ') : '';
    });
  });

  // Section navigation: highlight the section currently in view
  var navLinks = $$('.form-nav a[data-target]');
  if (navLinks.length && 'IntersectionObserver' in window) {
    var ratios = {};
    var io = new IntersectionObserver(function (entries) {
      entries.forEach(function (en) { ratios[en.target.id] = en.isIntersecting ? en.intersectionRatio : 0; });
      var best = null, bestRatio = 0;
      Object.keys(ratios).forEach(function (id) { if (ratios[id] > bestRatio) { bestRatio = ratios[id]; best = id; } });
      if (best) { navLinks.forEach(function (a) { a.classList.toggle('current', a.getAttribute('data-target') === best); }); }
    }, { rootMargin: '-15% 0px -55% 0px', threshold: [0, 0.25, 0.5, 0.75, 1] });
    navLinks.forEach(function (a) {
      var sec = document.getElementById(a.getAttribute('data-target'));
      if (sec) { io.observe(sec); }
    });
  }

  // Unsaved-changes indicator and leave warning
  var dirty = false, submitting = false;
  var dirtyBadge = $('#form-dirty');
  function markDirty() { dirty = true; if (dirtyBadge) { dirtyBadge.hidden = false; } }
  form.addEventListener('input', markDirty);
  form.addEventListener('change', markDirty);
  window.addEventListener('beforeunload', function (ev) {
    if (dirty && !submitting) { ev.preventDefault(); ev.returnValue = ''; }
  });

  // After a server-side validation error, bring the first problem into view
  var firstError = $('.field.has-error', form);
  if (firstError && !window.location.hash) {
    firstError.scrollIntoView({ block: 'center' });
  }

  // Avoid double submits
  form.addEventListener('submit', function () {
    submitting = true;
    $$('button[type="submit"]', form).forEach(function (b) { setTimeout(function () { b.disabled = true; }, 0); });
  });
})();
