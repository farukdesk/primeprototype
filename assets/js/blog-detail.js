/**
 * Blog detail page enhancements.
 * - Reading progress bar
 * - H2 anchor links + Table of Contents (desktop sidebar + mobile collapsible) with scroll-spy
 * - Responsive table wrapping ("At a glance" styling)
 * - "Where you learn it at Prime:" callout cards with course chips (linked to programme pages)
 * - FAQ accordion (button + aria-expanded) with FAQPage JSON-LD
 * - Closing bold CTA paragraph promoted into the CTA banner
 * - Share buttons (copy link + toast)
 * - Comment form: inline validation + loading state
 * - Lazy-loading for in-content images
 */
(function () {
   'use strict';

   var content = document.querySelector('.bd-content');

   /* ── Reading progress bar ─────────────────────────────────────────────── */
   var bar = document.querySelector('.bd-progress');
   var article = document.querySelector('.bd-article');
   if (bar && article) {
      var onScroll = function () {
         var rect = article.getBoundingClientRect();
         var total = rect.height - window.innerHeight;
         var done = Math.min(Math.max(-rect.top, 0), Math.max(total, 1));
         bar.style.width = (total > 0 ? (done / total) * 100 : 0) + '%';
      };
      window.addEventListener('scroll', onScroll, { passive: true });
      window.addEventListener('resize', onScroll);
      onScroll();
   }

   if (!content) return;

   var slugify = function (text) {
      return text.toLowerCase().trim()
         .replace(/['’]/g, '')
         .replace(/[^a-z0-9\u0980-\u09FF]+/g, '-')
         .replace(/^-+|-+$/g, '')
         .substring(0, 80) || 'section';
   };

   /* ── H2 ids + hover anchor links ─────────────────────────────────────── */
   var headings = Array.prototype.slice.call(content.querySelectorAll('h2'));
   var used = {};
   headings.forEach(function (h) {
      if (!h.id) {
         var id = slugify(h.textContent);
         while (used[id] || document.getElementById(id)) id += '-2';
         used[id] = true;
         h.id = id;
      }
      var a = document.createElement('a');
      a.className = 'bd-anchor';
      a.href = '#' + h.id;
      a.setAttribute('aria-label', 'Link to section: ' + h.textContent.trim());
      a.innerHTML = '<i class="fa-solid fa-link" aria-hidden="true"></i>';
      h.appendChild(a);
   });

   /* ── Table of Contents + scroll-spy ──────────────────────────────────── */
   var tocTargets = [document.getElementById('bd-toc-desktop'), document.getElementById('bd-toc-mobile')];
   if (headings.length >= 2) {
      tocTargets.forEach(function (t) {
         if (!t) return;
         var ol = document.createElement('ol');
         headings.forEach(function (h) {
            var li = document.createElement('li');
            var a = document.createElement('a');
            a.href = '#' + h.id;
            a.textContent = h.textContent.trim();
            a.setAttribute('data-toc', h.id);
            li.appendChild(a);
            ol.appendChild(li);
         });
         t.appendChild(ol);
      });
      // Scroll-spy
      if ('IntersectionObserver' in window) {
         var current = null;
         var spy = new IntersectionObserver(function (entries) {
            entries.forEach(function (en) {
               if (en.isIntersecting) current = en.target.id;
            });
            if (current) {
               document.querySelectorAll('.bd-toc a').forEach(function (a) {
                  a.classList.toggle('active', a.getAttribute('data-toc') === current);
               });
            }
         }, { rootMargin: '-100px 0px -65% 0px' });
         headings.forEach(function (h) { spy.observe(h); });
      }
   } else {
      document.querySelectorAll('.bd-toc-widget, .bd-toc-mobile').forEach(function (el) { el.remove(); });
   }

   /* ── Wrap tables for horizontal scroll + header row ──────────────────── */
   content.querySelectorAll('table').forEach(function (table) {
      if (table.closest('.bd-table-wrap')) return;
      var wrap = document.createElement('div');
      wrap.className = 'bd-table-wrap';
      wrap.setAttribute('tabindex', '0');
      wrap.setAttribute('role', 'region');
      wrap.setAttribute('aria-label', 'Table, scrolls horizontally');
      table.parentNode.insertBefore(wrap, table);
      wrap.appendChild(table);
      // Treat a bold-only first row as a header row.
      if (!table.querySelector('thead') && !table.querySelector('th')) {
         var first = table.querySelector('tr');
         if (first) {
            var cells = first.querySelectorAll('td');
            var allBold = cells.length > 0 && Array.prototype.every.call(cells, function (td) {
               var t = td.textContent.trim();
               if (t === '') return true;
               var b = td.querySelector('strong, b');
               return b && b.textContent.trim() === t;
            });
            if (allBold) first.classList.add('bd-head-row');
         }
      }
   });

   /* ── "Where you learn it at Prime:" callout cards ─────────────────────── */
   var programs = window.BD_PROGRAMS || []; // [{name, url}]
   var findProgramUrl = function (course) {
      var c = course.toLowerCase().replace(/\s+/g, ' ').trim();
      if (!c) return null;
      for (var i = 0; i < programs.length; i++) {
         var p = programs[i].name.toLowerCase();
         if (p === c || p.indexOf(c) !== -1 || c.indexOf(p) !== -1) return programs[i].url;
      }
      return null;
   };
   var LEARN_RX = /^\s*where\s+you\s+learn\s+(it|this)\s+at\s+prime\s*:?\s*/i;
   content.querySelectorAll('p').forEach(function (p) {
      var text = p.textContent;
      if (!LEARN_RX.test(text)) return;
      var rest = text.replace(LEARN_RX, '').trim();
      var courses = rest.split(/[;,•·|]|(?:\s+and\s+)/i)
         .map(function (s) { return s.replace(/\.+\s*$/, '').trim(); })
         .filter(Boolean);
      var card = document.createElement('aside');
      card.className = 'bd-callout';
      card.setAttribute('aria-label', 'Where you learn it at Prime');
      var chips = courses.map(function (cName) {
         var url = findProgramUrl(cName);
         var safe = document.createElement(url ? 'a' : 'span');
         safe.className = 'bd-course-chip';
         if (url) safe.href = url;
         safe.textContent = cName;
         return safe.outerHTML;
      }).join('');
      card.innerHTML =
         '<div class="bd-callout-icon" aria-hidden="true"><i class="fa-solid fa-graduation-cap"></i></div>' +
         '<div><div class="bd-callout-label">Where you learn it at Prime</div>' +
         '<div class="bd-callout-chips">' + chips + '</div></div>';
      p.replaceWith(card);
   });

   /* ── FAQ accordion + FAQPage JSON-LD ─────────────────────────────────── */
   (function buildFaq() {
      var faqHead = headings.concat(Array.prototype.slice.call(content.querySelectorAll('h3')))
         .find(function (h) { return /\bfaqs?\b|frequently\s+asked/i.test(h.textContent); });
      if (!faqHead) return;

      // Collect siblings after the FAQ heading until the next heading of same-or-higher level.
      var stopTags = faqHead.tagName === 'H2' ? ['H2'] : ['H2', 'H3'];
      var nodes = [];
      var n = faqHead.nextElementSibling;
      while (n && stopTags.indexOf(n.tagName) === -1) {
         nodes.push(n);
         n = n.nextElementSibling;
      }
      if (!nodes.length) return;

      var isQuestion = function (el) {
         if (/^H[3-6]$/.test(el.tagName)) return true;
         if (el.tagName === 'P') {
            var b = el.querySelector('strong, b');
            return !!(b && b.textContent.trim() === el.textContent.trim() && el.textContent.trim() !== '');
         }
         return false;
      };

      var items = [];
      var cur = null;
      var consumed = [];
      nodes.forEach(function (el) {
         if (isQuestion(el)) {
            cur = { q: el.textContent.trim(), qEl: el, answers: [] };
            items.push(cur);
         } else if (cur) {
            cur.answers.push(el);
         }
      });
      items = items.filter(function (it) { return it.answers.length; });
      if (!items.length) return;

      var acc = document.createElement('div');
      acc.className = 'bd-faq';
      var ld = [];
      items.forEach(function (it, i) {
         var item = document.createElement('div');
         item.className = 'bd-faq-item';
         var qId = 'bd-faq-q-' + i, aId = 'bd-faq-a-' + i;
         var btn = document.createElement('button');
         btn.type = 'button';
         btn.className = 'bd-faq-q';
         btn.id = qId;
         btn.setAttribute('aria-expanded', 'false');
         btn.setAttribute('aria-controls', aId);
         btn.innerHTML = '<span></span><i class="bd-faq-icon fa-solid fa-chevron-down" aria-hidden="true"></i>';
         btn.firstChild.textContent = it.q;
         var panel = document.createElement('div');
         panel.className = 'bd-faq-a';
         panel.id = aId;
         panel.setAttribute('role', 'region');
         panel.setAttribute('aria-labelledby', qId);
         panel.hidden = true;
         var answerText = '';
         it.answers.forEach(function (a) {
            answerText += ' ' + a.textContent;
            panel.appendChild(a);
         });
         btn.addEventListener('click', function () {
            var open = btn.getAttribute('aria-expanded') === 'true';
            btn.setAttribute('aria-expanded', String(!open));
            panel.hidden = open;
         });
         item.appendChild(btn);
         item.appendChild(panel);
         acc.appendChild(item);
         consumed.push(it.qEl);
         ld.push({
            '@type': 'Question',
            'name': it.q,
            'acceptedAnswer': { '@type': 'Answer', 'text': answerText.trim() }
         });
      });
      // Remove only the consumed question nodes (answers were moved into panels);
      // anything else (e.g. a trailing CTA paragraph) stays in the article.
      consumed.forEach(function (el) { if (el.parentNode) el.remove(); });
      faqHead.insertAdjacentElement('afterend', acc);

      var script = document.createElement('script');
      script.type = 'application/ld+json';
      script.textContent = JSON.stringify({
         '@context': 'https://schema.org',
         '@type': 'FAQPage',
         'mainEntity': ld
      });
      document.head.appendChild(script);
   })();

   /* ── Promote a trailing all-bold paragraph into the CTA headline ─────── */
   (function promoteCta() {
      var ctaHead = document.getElementById('bd-cta-headline');
      if (!ctaHead) return;
      var last = content.lastElementChild;
      while (last && last.textContent.trim() === '') last = last.previousElementSibling;
      if (!last || last.tagName !== 'P') return;
      var b = last.querySelector('strong, b');
      var t = last.textContent.trim();
      if (b && b.textContent.trim() === t && t.length > 0 && t.length <= 220) {
         ctaHead.textContent = t;
         last.remove();
      }
   })();

   /* ── Lazy-load in-content images ─────────────────────────────────────── */
   content.querySelectorAll('img').forEach(function (img) {
      if (!img.hasAttribute('loading')) img.setAttribute('loading', 'lazy');
      img.setAttribute('decoding', 'async');
   });

   /* ── Share: copy link + toast ────────────────────────────────────────── */
   var toast = document.querySelector('.bd-toast');
   var showToast = function (msg) {
      if (!toast) return;
      toast.textContent = msg;
      toast.classList.add('show');
      clearTimeout(showToast._t);
      showToast._t = setTimeout(function () { toast.classList.remove('show'); }, 2200);
   };
   document.querySelectorAll('.bd-copy-link').forEach(function (btn) {
      btn.addEventListener('click', function () {
         var url = btn.getAttribute('data-url') || window.location.href;
         var done = function () { showToast('Copied!'); };
         if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(done, done);
         } else {
            var ta = document.createElement('textarea');
            ta.value = url;
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); } catch (e) {}
            document.body.removeChild(ta);
            done();
         }
      });
   });

   /* ── Comment form: inline validation + loading state ─────────────────── */
   var form = document.getElementById('comment-form');
   if (form) {
      var fields = [
         { el: form.querySelector('[name="author_name"]'), msg: 'Please enter your name.',
           valid: function (v) { return v.trim() !== '' && v.length <= 190; } },
         { el: form.querySelector('[name="author_email"]'), msg: 'Please enter a valid email address (or leave it blank).',
           valid: function (v) { return v.trim() === '' || /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v.trim()); } },
         { el: form.querySelector('[name="comment"]'), msg: 'Please write a comment (max 3000 characters).',
           valid: function (v) { return v.trim() !== '' && v.length <= 3000; } }
      ].filter(function (f) { return f.el; });

      var check = function (f) {
         var ok = f.valid(f.el.value);
         var wrap = f.el.closest('.bd-field');
         if (wrap) {
            wrap.classList.toggle('bd-invalid', !ok);
            f.el.setAttribute('aria-invalid', String(!ok));
         }
         return ok;
      };
      fields.forEach(function (f) {
         f.el.addEventListener('blur', function () { check(f); });
         f.el.addEventListener('input', function () {
            var wrap = f.el.closest('.bd-field');
            if (wrap && wrap.classList.contains('bd-invalid')) check(f);
         });
      });
      form.addEventListener('submit', function (e) {
         var allOk = true;
         fields.forEach(function (f) { if (!check(f)) allOk = false; });
         if (!allOk) {
            e.preventDefault();
            var firstBad = form.querySelector('.bd-invalid input, .bd-invalid textarea');
            if (firstBad) firstBad.focus();
            return;
         }
         var btn = form.querySelector('.bd-submit-btn');
         if (btn) {
            btn.classList.add('loading');
            btn.setAttribute('aria-busy', 'true');
            var label = btn.querySelector('.bd-submit-label');
            if (label) label.textContent = 'Submitting…';
         }
      });
   }
})();
