// The HTML shell every page is poured into.
//
// One layout, two shapes: the landing page (`variant: 'home'`, full width) and
// the documentation pages (`variant: 'docs'`, sidebar + article + "on this
// page"). All URLs are computed *relative to the page being rendered*, so the
// same output works when opened from a local server at `/` and when GitHub
// Pages serves it from `/quanta/` — no base path to configure.

import { site, topNav } from './config.mjs';

/** Minimal HTML-escaping for text interpolated into the shell. */
export function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

const isExternal = (href) => /^[a-z][a-z0-9+.-]*:/i.test(href) || href.startsWith('//');

/**
 * URL of `to` (an output path such as `docs/qdb/usage.html`) as seen from
 * the page at `from`. `index.html` targets collapse to a directory URL.
 */
export function relUrl(from, to) {
  if (isExternal(to)) return to;
  const [pathPart, hash = ''] = to.split('#');
  const segments = from.split('/').slice(0, -1);
  const target = pathPart.replace(/(^|\/)index\.html$/, '$1');
  const targetSegments = target.split('/').filter(Boolean);
  const isDir = target.endsWith('/') || target === '';

  let common = 0;
  while (
    common < segments.length &&
    common < targetSegments.length - (isDir ? 0 : 1) &&
    segments[common] === targetSegments[common]
  ) common++;

  const up = Array(segments.length - common).fill('..');
  const down = targetSegments.slice(common);
  let url = [...up, ...down].join('/');
  if (isDir && url !== '') url += '/';
  if (url === '') url = isDir ? './' : './';
  return url + (hash ? '#' + hash : '');
}

function renderTopNav(currentOut) {
  return topNav
    .map((link) => {
      const href = relUrl(currentOut, link.to);
      const prefixes = link.match || [link.to.replace(/index\.html$/, '')];
      const active = !isExternal(link.to) && prefixes.some((p) => currentOut.startsWith(p));
      const external = isExternal(link.to)
        ? ' target="_blank" rel="noopener"'
        : '';
      return `<a class="topnav__link${active ? ' is-active' : ''}" href="${href}"${external}>${escapeHtml(link.label)}</a>`;
    })
    .join('\n          ');
}

/** The inline logo mark — a nucleus with two orbits. */
export function logoMark(className = 'logo__mark') {
  return `<svg class="${className}" viewBox="0 0 40 40" aria-hidden="true" focusable="false">
      <circle cx="20" cy="20" r="4.2" fill="currentColor"/>
      <ellipse cx="20" cy="20" rx="17" ry="7.4" fill="none" stroke="currentColor" stroke-width="2" opacity=".85" transform="rotate(-30 20 20)"/>
      <ellipse cx="20" cy="20" rx="17" ry="7.4" fill="none" stroke="currentColor" stroke-width="2" opacity=".45" transform="rotate(30 20 20)"/>
    </svg>`;
}

/**
 * Render a full page.
 *
 * @param {object} o
 * @param {string} o.out          output path of this page (for relative URLs)
 * @param {string} o.title        <title> (the site name is appended)
 * @param {string} o.description  meta description
 * @param {'home'|'docs'} o.variant
 * @param {string} o.content      the page body HTML
 * @param {string} [o.sidebar]    docs sidebar HTML
 * @param {string} [o.toc]        "on this page" HTML
 * @param {string} [o.footerNote] extra footer line (e.g. the source file link)
 * @param {string[]} [o.assets]   extra files in `assets/` this page needs
 *                                (`.css` goes in the head, `.js` is deferred)
 */
export function renderPage(o) {
  const asset = (file) => relUrl(o.out, `assets/${file}`);
  const home = relUrl(o.out, 'index.html');
  const fullTitle = o.title === site.title ? site.title : `${o.title} — ${site.title}`;
  const extra = o.assets || [];
  const extraCss = extra
    .filter((f) => f.endsWith('.css'))
    .map((f) => `\n  <link rel="stylesheet" href="${asset(f)}">`)
    .join('');
  const extraJs = extra
    .filter((f) => f.endsWith('.js'))
    .map((f) => `\n  <script src="${asset(f)}" defer></script>`)
    .join('');

  return `<!doctype html>
<html lang="en" class="no-js">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(fullTitle)}</title>
  <meta name="description" content="${escapeHtml(o.description || site.description)}">
  <meta property="og:title" content="${escapeHtml(fullTitle)}">
  <meta property="og:description" content="${escapeHtml(o.description || site.description)}">
  <meta property="og:type" content="website">
  <link rel="icon" href="${asset('favicon.svg')}" type="image/svg+xml">
  <link rel="stylesheet" href="${asset('site.css')}">${extraCss}
  <script>
    // Applied before first paint so a dark-mode reader never sees a light flash.
    (function () {
      var root = document.documentElement;
      root.classList.remove('no-js');
      try {
        var saved = localStorage.getItem('quanta-theme');
        if (saved) root.setAttribute('data-theme', saved);
      } catch (e) {}
    })();
  </script>
</head>
<body class="page page--${o.variant}">
  <a class="skip-link" href="#main">Skip to content</a>
  <header class="topbar">
    <div class="topbar__inner">
      <a class="logo" href="${home}">
        ${logoMark()}
        <span class="logo__text">Quanta<span class="logo__accent">CMS</span></span>
      </a>
      <button class="topbar__burger" type="button" aria-expanded="false" aria-controls="site-nav" data-nav-toggle>
        <span class="topbar__burger-bar"></span><span class="sr-only">Menu</span>
      </button>
      <nav class="topnav" id="site-nav" aria-label="Main">
        ${renderTopNav(o.out)}
      </nav>
      <button class="theme-toggle" type="button" data-theme-toggle aria-label="Toggle dark mode">
        <svg viewBox="0 0 24 24" class="theme-toggle__sun" aria-hidden="true"><circle cx="12" cy="12" r="4.5" fill="currentColor"/><g stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><path d="M12 2.5v2.2M12 19.3v2.2M2.5 12h2.2M19.3 12h2.2M5.2 5.2l1.6 1.6M17.2 17.2l1.6 1.6M18.8 5.2l-1.6 1.6M6.8 17.2l-1.6 1.6"/></g></svg>
        <svg viewBox="0 0 24 24" class="theme-toggle__moon" aria-hidden="true"><path d="M20 14.2A8.2 8.2 0 0 1 9.8 4a8.4 8.4 0 1 0 10.2 10.2Z" fill="currentColor"/></svg>
      </button>
    </div>
  </header>
${o.variant === 'docs' ? renderDocsBody(o) : `  <main id="main">\n${o.content}\n  </main>`}
  <footer class="footer">
    <div class="footer__inner">
      <div>
        <a class="logo logo--sm" href="${home}">${logoMark()}<span class="logo__text">Quanta<span class="logo__accent">CMS</span></span></a>
        <p class="footer__tagline">${escapeHtml(site.tagline)}</p>
      </div>
      <div class="footer__links">
        <a href="${relUrl(o.out, 'overview.html')}">Documentation</a>
        <a href="${site.repo}" target="_blank" rel="noopener">GitHub</a>
        <a href="${site.website}" target="_blank" rel="noopener">quanta.org</a>
        <a href="${site.repo}/blob/${site.branch}/LICENSE.txt" target="_blank" rel="noopener">License</a>
      </div>
    </div>
    <p class="footer__note">${o.footerNote || 'Quanta is free and open source software.'}</p>
  </footer>
  <script src="${asset('site.js')}" defer></script>${extraJs}
</body>
</html>
`;
}

function renderDocsBody(o) {
  return `  <div class="docs">
    <input type="checkbox" id="docs-sidebar-toggle" class="docs__sidebar-checkbox">
    <aside class="docs__sidebar" aria-label="Documentation">
      <label class="docs__sidebar-close" for="docs-sidebar-toggle" aria-hidden="true">Close</label>
${o.sidebar || ''}
    </aside>
    <main class="docs__main" id="main">
      <label class="docs__sidebar-open" for="docs-sidebar-toggle">Documentation menu</label>
      <article class="prose">
${o.content}
      </article>
${o.pager || ''}
    </main>
    <nav class="docs__toc" aria-label="On this page">
${o.toc || ''}
    </nav>
  </div>`;
}
