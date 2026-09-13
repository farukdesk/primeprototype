/* SS Portal – small progressive enhancements for the student form. No framework, no API calls from the browser. */
(function () {
  'use strict';

  // Confirm buttons that talk to the university
  document.querySelectorAll('[data-confirm]').forEach(function (btn) {
    btn.addEventListener('click', function (ev) {
      if (!window.confirm(btn.getAttribute('data-confirm'))) { ev.preventDefault(); }
    });
  });

  var form = document.getElementById('student-form');
  if (!form) { return; }

  // Department → program filtering
  var dept = form.querySelector('[data-role="department"]');
  var prog = form.querySelector('[data-role="program"]');
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
  var rows = document.getElementById('qual-rows');
  var addBtn = document.getElementById('add-qual');
  var tpl = document.getElementById('qual-template');
  function bindRemove(row) {
    var btn = row.querySelector('.btn-remove-row');
    if (btn) {
      btn.addEventListener('click', function () {
        if (rows.children.length > 1) { row.remove(); } else { row.querySelectorAll('input').forEach(function (i) { i.value = ''; }); }
      });
    }
  }
  if (rows) { Array.prototype.forEach.call(rows.children, bindRemove); }
  if (addBtn && tpl && rows) {
    addBtn.addEventListener('click', function () {
      var max = parseInt(addBtn.getAttribute('data-max') || '10', 10);
      if (rows.children.length >= max) { window.alert('At most ' + max + ' qualifications can be sent.'); return; }
      var next = 0;
      Array.prototype.forEach.call(rows.querySelectorAll('input[name^="academic_qualifications["]'), function (i) {
        var m = /academic_qualifications\[(\d+)\]/.exec(i.name);
        if (m) { next = Math.max(next, parseInt(m[1], 10) + 1); }
      });
      var wrap = document.createElement('div');
      wrap.innerHTML = tpl.innerHTML.replace(/__i__/g, String(next));
      var row = wrap.firstElementChild;
      rows.appendChild(row);
      bindRemove(row);
      var first = row.querySelector('input');
      if (first) { first.focus(); }
    });
  }

  // Optional result block
  var resultToggle = document.getElementById('result-enabled');
  var resultFields = document.getElementById('result-fields');
  if (resultToggle && resultFields) {
    resultToggle.addEventListener('change', function () { resultFields.disabled = !resultToggle.checked; });
  }

  // Photo preview
  var photo = form.querySelector('input[type="file"][name="photo"]');
  var preview = document.getElementById('photo-preview');
  if (photo && preview) {
    photo.addEventListener('change', function () {
      var f = photo.files && photo.files[0];
      if (!f) { preview.hidden = true; return; }
      if (f.size > 5 * 1024 * 1024) { window.alert('The photo is larger than 5 MB; the university will reject it.'); }
      preview.src = URL.createObjectURL(f);
      preview.hidden = false;
    });
  }

  // Avoid double submits
  form.addEventListener('submit', function () {
    form.querySelectorAll('button[type="submit"]').forEach(function (b) { setTimeout(function () { b.disabled = true; }, 0); });
  });
})();
