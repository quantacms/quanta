#!/usr/bin/env node
// Static site generator for Quanta's documentation site.
//
//   node docs/site/build.mjs [--out docs] [--quiet]
//
// It renders `docs/site/pages/*.html` (hand-written pages, landing page
// included) and every Markdown file listed in `docs/site/config.mjs` into
// `docs/`: no server, no runtime dependency, nothing to configure.
//
// The output shares `docs/` with its own sources — `docs/site/` (this
// generator) and `docs/README.md` (a page it renders) — so the build must not
// simply wipe its output directory. Instead it records everything it writes in
// `docs/.build-manifest` and deletes exactly that on the next run. Nothing
// else in `docs/` is ever touched.
//
// Two rules keep the site honest:
//   1. Documentation is rendered from the repository's own Markdown. There is
//      no second copy to keep in sync.
//   2. Links between those Markdown files are rewritten to site pages when the
//      target is published, and to GitHub otherwise — so nothing 404s.

import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { Marked, Renderer } from 'marked';
import { site, docsNav, docsPages } from './config.mjs';
import { renderPage, relUrl, escapeHtml } from './layout.mjs';

const siteDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(siteDir, '../..');

const args = process.argv.slice(2);
const quiet = args.includes('--quiet');
const outFlag = args.indexOf('--out');
const outDir = path.resolve(repoRoot, outFlag !== -1 ? args[outFlag + 1] : 'docs');

/** The list of generated files, so the next build can remove exactly them. */
const MANIFEST = '.build-manifest';

const log = (...a) => { if (!quiet) console.log(...a); };
const warn = (...a) => console.warn('!', ...a);

/* ------------------------------------------------------------------ *
 * Markdown → HTML
 * ------------------------------------------------------------------ */

/** GitHub-compatible heading slug, so `#getraw` style links keep working. */
function slugify(text) {
  return text
    .toLowerCase()
    .replace(/<[^>]*>/g, '')
    .replace(/[^\p{L}\p{N} _-]/gu, '')
    .trim()
    .replace(/\s+/g, '-');
}

/** Where a repo-relative Markdown path ends up in the site, if it does. */
const outputForSource = new Map(docsPages.map((p) => [p.src, p.out]));

/**
 * Build a `marked` instance bound to one page: it needs the page's source
 * directory (to resolve relative links) and its output path (to emit relative
 * URLs), and it collects headings for the "on this page" panel.
 */
function markedFor(page, headings) {
  const seen = new Map();
  const srcDir = path.posix.dirname(page.src);

  const uniqueSlug = (text) => {
    const base = slugify(text) || 'section';
    const n = seen.get(base) || 0;
    seen.set(base, n + 1);
    return n ? `${base}-${n}` : base;
  };

  const rewriteHref = (href) => {
    if (!href) return '#';
    if (/^[a-z][a-z0-9+.-]*:/i.test(href) || href.startsWith('//') || href.startsWith('#')) return href;
    const [rawPath, hash] = href.split('#');
    const resolved = path.posix.normalize(path.posix.join(srcDir, rawPath)).replace(/^\.\//, '');
    const target = outputForSource.get(resolved);
    if (target) return relUrl(page.out, target + (hash ? '#' + hash : ''));
    // Not published as a page — send the reader to the file on GitHub.
    return `${site.repo}/blob/${site.branch}/${resolved}${hash ? '#' + hash : ''}`;
  };

  const instance = new Marked({ gfm: true });

  instance.use({
    renderer: {
      heading({ tokens, depth }) {
        const text = this.parser.parseInline(tokens);
        const plain = this.parser.parseInline(tokens, this.parser.textRenderer);
        const id = uniqueSlug(plain);
        if (depth === 2 || depth === 3) headings.push({ id, depth, text: plain });
        return `<h${depth} id="${id}">${text}` +
          `<a class="prose__anchor" href="#${id}" aria-label="Link to this section">#</a></h${depth}>\n`;
      },
      link({ href, title, tokens }) {
        const url = rewriteHref(href);
        const external = /^https?:/i.test(url);
        const attrs = [
          `href="${escapeHtml(url)}"`,
          title ? `title="${escapeHtml(title)}"` : '',
          external ? 'target="_blank" rel="noopener"' : '',
        ].filter(Boolean).join(' ');
        return `<a ${attrs}>${this.parser.parseInline(tokens)}</a>`;
      },
      image({ href, title, text }) {
        const url = rewriteHref(href);
        return `<img src="${escapeHtml(url)}" alt="${escapeHtml(text || '')}"` +
          `${title ? ` title="${escapeHtml(title)}"` : ''} loading="lazy">`;
      },
      code({ text, lang }) {
        const language = (lang || '').split(/\s+/)[0];
        return `<div class="codeblock"${language ? ` data-lang="${escapeHtml(language)}"` : ''}>` +
          `<button class="codeblock__copy" type="button" data-copy>Copy</button>` +
          `<pre><code${language ? ` class="language-${escapeHtml(language)}"` : ''}>` +
          `${escapeHtml(text)}</code></pre></div>\n`;
      },
      table(token) {
        // Same markup marked emits, wrapped so wide tables scroll instead of
        // stretching the page.
        const html = Renderer.prototype.table.call(this, token);
        return `<div class="table-wrap">${html}</div>\n`;
      },
    },
  });

  return instance;
}

/**
 * The first H1 of a document — its real title — and the body without it, so
 * the page does not print the same heading twice. A README that opens with a
 * logo image before its title (as Quanta's does) still counts: only blank
 * lines and image-only lines may come first.
 */
function splitTitle(markdown) {
  const match = markdown.match(/^#[ \t]+(.+?)[ \t]*$/m);
  if (!match) return { title: null, body: markdown };

  const before = markdown.slice(0, match.index);
  const preceding = before.split('\n').filter((line) => line.trim() !== '');
  const onlyImages = preceding.every((line) => /^!\[[^\]]*\]\([^)]*\)$/.test(line.trim()));
  if (!onlyImages) return { title: null, body: markdown };

  return {
    title: match[1].replace(/\s*#+\s*$/, '').replace(/[`*_]/g, ''),
    body: before + markdown.slice(match.index + match[0].length),
  };
}

/** First real paragraph, flattened — used as the meta description. */
function firstParagraph(markdown) {
  const text = markdown
    .replace(/```[\s\S]*?```/g, '')
    .replace(/^#.*$/gm, '')
    .replace(/^\s*[|>-].*$/gm, '')
    .trim()
    .split(/\n\s*\n/)[0] || '';
  return text.replace(/\s+/g, ' ').replace(/[*_`\[\]]/g, '').slice(0, 180).trim();
}

/* ------------------------------------------------------------------ *
 * Navigation fragments
 * ------------------------------------------------------------------ */

function renderSidebar(current) {
  const sections = docsNav
    .map((section) => {
      const items = section.items
        .filter((item) => published.has(item.src))
        .map((item) => {
          const active = item.out === current.out;
          return `          <li><a class="docs__navlink${active ? ' is-active' : ''}"` +
            `${active ? ' aria-current="page"' : ''} href="${relUrl(current.out, item.out)}">` +
            `${escapeHtml(item.title)}</a></li>`;
        });
      if (!items.length) return '';
      return `        <p class="docs__navtitle">${escapeHtml(section.section)}</p>\n` +
        `        <ul class="docs__navlist">\n${items.join('\n')}\n        </ul>`;
    })
    .filter(Boolean);

  return `      <nav class="docs__nav">\n${sections.join('\n')}\n      </nav>`;
}

function renderToc(headings) {
  if (headings.length < 2) return '';
  const items = headings
    .map((h) => `        <li class="docs__toc-item docs__toc-item--h${h.depth}">` +
      `<a href="#${h.id}">${escapeHtml(h.text)}</a></li>`)
    .join('\n');
  return `      <p class="docs__toc-title">On this page</p>\n` +
    `      <ul class="docs__toc-list">\n${items}\n      </ul>`;
}

function renderPager(current, list) {
  const index = list.findIndex((p) => p.out === current.out);
  const prev = index > 0 ? list[index - 1] : null;
  const next = index >= 0 && index < list.length - 1 ? list[index + 1] : null;
  if (!prev && !next) return '';
  const link = (page, kind, label) =>
    page
      ? `<a class="pager__link pager__link--${kind}" href="${relUrl(current.out, page.out)}">` +
        `<span class="pager__kind">${label}</span>` +
        `<span class="pager__title">${escapeHtml(page.title)}</span></a>`
      : '<span></span>';
  return `      <nav class="pager" aria-label="Documentation">\n` +
    `        ${link(prev, 'prev', 'Previous')}\n        ${link(next, 'next', 'Next')}\n      </nav>`;
}

/* ------------------------------------------------------------------ *
 * Build
 * ------------------------------------------------------------------ */

const published = new Set();

async function readIfExists(file) {
  try {
    return await fs.readFile(path.join(repoRoot, file), 'utf8');
  } catch (error) {
    if (error.code === 'ENOENT') return null;
    throw error;
  }
}

const written = [];

async function writeOut(relative, contents) {
  const target = path.join(outDir, relative);
  await fs.mkdir(path.dirname(target), { recursive: true });
  await fs.writeFile(target, contents);
  written.push(relative);
}

/**
 * Delete what the previous build generated — and only that. A path that would
 * escape the output directory is ignored rather than followed.
 */
async function removePrevious() {
  const previous = await fs.readFile(path.join(outDir, MANIFEST), 'utf8').catch(() => '');
  const files = previous
    .split('\n')
    .map((line) => line.trim())
    .filter((line) => line && !line.startsWith('#'));

  const dirs = new Set();
  for (const relative of files) {
    const target = path.resolve(outDir, relative);
    if (target !== outDir && !target.startsWith(outDir + path.sep)) continue;
    await fs.rm(target, { force: true });
    dirs.add(path.dirname(target));
  }
  // Deepest first, so a directory emptied by the loop above can go too.
  for (const dir of [...dirs].sort((a, b) => b.length - a.length)) {
    if (dir === outDir) continue;
    await fs.rmdir(dir).catch(() => {});
  }
  return files.length;
}

async function buildDocs() {
  // Pages whose source is missing (a doc that was never committed, say) are
  // skipped everywhere — sidebar, pager and link map included.
  const sources = new Map();
  for (const page of docsPages) {
    const markdown = await readIfExists(page.src);
    if (markdown === null) {
      warn(`skipping ${page.out}: ${page.src} not found`);
      outputForSource.delete(page.src);
      continue;
    }
    sources.set(page.src, markdown);
    published.add(page.src);
  }

  const list = docsPages.filter((p) => published.has(p.src));

  for (const page of list) {
    const raw = sources.get(page.src);
    const { title, body } = splitTitle(raw);
    const headings = [];
    const md = markedFor(page, headings);
    const html = md.parse(body);
    const heading = title || page.title;

    const sourceUrl = `${site.repo}/blob/${site.branch}/${page.src}`;
    const content =
      `        <p class="prose__eyebrow">${escapeHtml(page.section)}</p>\n` +
      `        <h1>${escapeHtml(heading)}</h1>\n` +
      html;

    await writeOut(page.out, renderPage({
      out: page.out,
      title: heading,
      description: firstParagraph(body) || site.description,
      variant: 'docs',
      content,
      sidebar: renderSidebar(page),
      toc: renderToc(headings),
      pager: renderPager(page, list),
      footerNote: `This page is rendered from <a href="${sourceUrl}" target="_blank" rel="noopener">${escapeHtml(page.src)}</a> in the repository.`,
    }));
    log(`  docs   ${page.out}`);
  }

  return list.length;
}

async function buildStaticPages() {
  const pagesDir = path.join(siteDir, 'pages');
  const files = (await fs.readdir(pagesDir)).filter((f) => f.endsWith('.html'));
  for (const file of files) {
    const raw = await fs.readFile(path.join(pagesDir, file), 'utf8');
    // Front matter: `<!-- key: value -->` lines at the top of the file.
    const meta = {};
    const body = raw.replace(/^(?:<!--\s*([a-z]+):\s*([\s\S]*?)\s*-->\s*\n)+/, (block) => {
      for (const m of block.matchAll(/<!--\s*([a-z]+):\s*([\s\S]*?)\s*-->/g)) meta[m[1]] = m[2];
      return '';
    });
    const out = file;
    // `{{docs}}`-style placeholders resolve to page-relative URLs.
    const content = body.replace(/\{\{link:([^}]+)\}\}/g, (_, to) => relUrl(out, to.trim()));
    await writeOut(out, renderPage({
      out,
      title: meta.title || site.title,
      description: meta.description || site.description,
      variant: meta.variant || 'home',
      // `<!-- assets: qdb.css, qdb.js -->`
      assets: (meta.assets || '').split(',').map((f) => f.trim()).filter(Boolean),
      content,
    }));
    log(`  page   ${out}`);
  }
  return files.length;
}

// Copied file by file rather than with `fs.cp`, so every asset lands in the
// manifest and can be cleaned up again.
async function copyTree(from, toRelative) {
  for (const entry of await fs.readdir(from, { withFileTypes: true })) {
    const source = path.join(from, entry.name);
    const relative = path.posix.join(toRelative, entry.name);
    if (entry.isDirectory()) await copyTree(source, relative);
    else await writeOut(relative, await fs.readFile(source));
  }
}

async function copyAssets() {
  await copyTree(path.join(siteDir, 'assets'), 'assets');
  log('  assets assets/');
}

async function build() {
  const started = process.hrtime.bigint();
  await fs.mkdir(outDir, { recursive: true });
  const removed = await removePrevious();
  // Keeps a static host from running the output through Jekyll.
  await writeOut('.nojekyll', '');

  const docs = await buildDocs();
  const pages = await buildStaticPages();
  await copyAssets();

  const manifest = [...written, MANIFEST].sort();
  await fs.writeFile(
    path.join(outDir, MANIFEST),
    '# Written by docs/site/build.mjs — everything here is generated and is\n' +
    '# deleted and rewritten by the next build. Do not edit these by hand.\n' +
    manifest.join('\n') + '\n',
  );

  const ms = Number(process.hrtime.bigint() - started) / 1e6;
  log(`\nBuilt ${pages} page(s) and ${docs} doc(s) into ${path.relative(process.cwd(), outDir) || outDir} ` +
      `in ${ms.toFixed(0)}ms (${manifest.length} files, ${removed} replaced)`);
}

build().catch((error) => {
  console.error(error);
  process.exit(1);
});
