// Small progressive enhancements. Everything here is optional: without JS the
// site is still fully navigable (the docs sidebar uses a checkbox, the theme
// follows the system setting).

(function () {
  'use strict';

  /* Theme toggle — persisted, and it flips relative to what is on screen. */
  var root = document.documentElement;
  var toggle = document.querySelector('[data-theme-toggle]');
  if (toggle) {
    toggle.addEventListener('click', function () {
      var current = root.getAttribute('data-theme');
      if (!current) {
        current = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      }
      var next = current === 'dark' ? 'light' : 'dark';
      root.setAttribute('data-theme', next);
      try { localStorage.setItem('quanta-theme', next); } catch (e) {}
    });
  }

  /* Mobile top navigation. */
  var burger = document.querySelector('[data-nav-toggle]');
  var nav = document.getElementById('site-nav');
  if (burger && nav) {
    burger.addEventListener('click', function () {
      var open = nav.classList.toggle('is-open');
      burger.setAttribute('aria-expanded', String(open));
    });
  }

  /* Copy buttons on code blocks. */
  document.querySelectorAll('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var code = button.parentElement.querySelector('code');
      if (!code || !navigator.clipboard) return;
      navigator.clipboard.writeText(code.textContent).then(function () {
        var label = button.textContent;
        button.textContent = 'Copied';
        setTimeout(function () { button.textContent = label; }, 1400);
      });
    });
  });

  /* Highlight the "on this page" entry for the section being read. */
  var tocLinks = Array.prototype.slice.call(document.querySelectorAll('.docs__toc-list a'));
  if (tocLinks.length && 'IntersectionObserver' in window) {
    var byId = {};
    var targets = [];
    tocLinks.forEach(function (link) {
      var id = decodeURIComponent(link.getAttribute('href').slice(1));
      var heading = document.getElementById(id);
      if (heading) { byId[id] = link; targets.push(heading); }
    });

    var visible = new Set();
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) visible.add(entry.target.id);
        else visible.delete(entry.target.id);
      });
      var active = targets.filter(function (t) { return visible.has(t.id); })[0];
      if (!active) return;
      tocLinks.forEach(function (l) { l.classList.remove('is-active'); });
      if (byId[active.id]) byId[active.id].classList.add('is-active');
    }, { rootMargin: '-70px 0px -70% 0px', threshold: 0 });

    targets.forEach(function (t) { observer.observe(t); });
  }
})();
