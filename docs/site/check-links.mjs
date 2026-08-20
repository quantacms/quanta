#!/usr/bin/env node
// Link checker for the built site.
//
//   node docs/site/check-links.mjs [--out docs]
//
// Walks every generated page and verifies that each internal link resolves to
// a file in the output, and that every `#fragment` matches an `id` on the page
// it points at. External links are listed, not fetched — the point is to catch
// a rewritten Markdown link that lost its target, which is the one failure the
// generator can introduce silently.

import { promises as fs } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const siteDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(siteDir, '../..');
const outFlag = process.argv.indexOf('--out');
const outDir = path.resolve(repoRoot, outFlag !== -1 ? process.argv[outFlag + 1] : 'docs');

async function htmlFiles(dir, base = dir) {
  const entries = await fs.readdir(dir, { withFileTypes: true });
  const found = [];
  for (const entry of entries) {
    const full = path.join(dir, entry.name);
    // The generator lives inside its own output directory; its `pages/` are
    // fragments, not pages.
    if (full === siteDir) continue;
    if (entry.isDirectory()) found.push(...(await htmlFiles(full, base)));
    else if (entry.name.endsWith('.html')) found.push(path.relative(base, full));
  }
  return found.sort();
}

const pages = await htmlFiles(outDir).catch(() => []);
if (!pages.length) {
  console.error(`No pages in ${outDir} — run \`npm run docs:build\` first.`);
  process.exit(1);
}

const idsOf = new Map();
const contents = new Map();
for (const page of pages) {
  const html = await fs.readFile(path.join(outDir, page), 'utf8');
  contents.set(page, html);
  idsOf.set(page, new Set([...html.matchAll(/\sid="([^"]+)"/g)].map((m) => m[1])));
}

const problems = [];
let checked = 0;
let external = 0;

for (const page of pages) {
  for (const match of contents.get(page).matchAll(/\shref="([^"]+)"/g)) {
    const href = match[1];
    if (/^(?:[a-z][a-z0-9+.-]*:|\/\/)/i.test(href)) { external++; continue; }
    checked++;

    const [target, fragment] = href.split('#');
    let file = page;
    if (target) {
      let resolved = path.posix.normalize(path.posix.join(path.posix.dirname(page), target));
      if (resolved.endsWith('/') || !path.posix.extname(resolved)) {
        resolved = path.posix.join(resolved, 'index.html');
      }
      try {
        await fs.access(path.join(outDir, resolved));
      } catch {
        problems.push(`${page}: missing target  ${href}`);
        continue;
      }
      file = resolved;
    }
    if (fragment && idsOf.has(file) && !idsOf.get(file).has(decodeURIComponent(fragment))) {
      problems.push(`${page}: missing anchor  ${href}`);
    }
  }
}

if (problems.length) {
  console.error(`\n${problems.length} broken link(s):\n`);
  for (const problem of problems) console.error('  ' + problem);
  process.exit(1);
}

console.log(`${pages.length} pages, ${checked} internal links, ${external} external links — all internal links resolve.`);
