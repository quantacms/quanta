#!/usr/bin/env node
// Local preview server for the documentation site.
//
//   node docs/site/serve.mjs [--port 4000] [--no-watch]
//
// Builds the site, serves `docs/` over HTTP (directory indexes and the 404
// page included, the way a static host does), and rebuilds whenever a source
// file or a documented Markdown file changes. Zero dependencies beyond the
// build itself — nothing is installed globally, nothing is published.

import { createServer } from 'node:http';
import { promises as fs, watch } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { spawn } from 'node:child_process';
import { docsPages } from './config.mjs';

const siteDir = path.dirname(fileURLToPath(import.meta.url));
const repoRoot = path.resolve(siteDir, '../..');
const outDir = path.join(repoRoot, 'docs');

const args = process.argv.slice(2);
const flag = (name) => args.includes(name);
const value = (name, fallback) => {
  const i = args.indexOf(name);
  return i === -1 ? fallback : args[i + 1];
};

const port = Number(value('--port', process.env.PORT || 4000));
const shouldWatch = !flag('--no-watch');

const MIME = {
  '.html': 'text/html; charset=utf-8',
  '.css': 'text/css; charset=utf-8',
  '.js': 'text/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8',
  '.svg': 'image/svg+xml',
  '.png': 'image/png',
  '.jpg': 'image/jpeg',
  '.jpeg': 'image/jpeg',
  '.gif': 'image/gif',
  '.webp': 'image/webp',
  '.ico': 'image/x-icon',
  '.woff2': 'font/woff2',
  '.txt': 'text/plain; charset=utf-8',
};

function build() {
  return new Promise((resolve) => {
    const child = spawn(process.execPath, [path.join(siteDir, 'build.mjs')], { stdio: 'inherit' });
    child.on('exit', (code) => resolve(code === 0));
  });
}

async function sendFile(res, file, status = 200) {
  const body = await fs.readFile(file);
  res.writeHead(status, {
    'Content-Type': MIME[path.extname(file).toLowerCase()] || 'application/octet-stream',
    'Content-Length': body.length,
    'Cache-Control': 'no-store',
  });
  res.end(body);
}

const server = createServer(async (req, res) => {
  try {
    const url = new URL(req.url, 'http://localhost');
    let rel = decodeURIComponent(url.pathname);
    // Contain the resolution inside docs/ — a preview server still should not
    // hand out files above its root.
    let file = path.join(outDir, path.normalize(rel).replace(/^(\.\.[/\\])+/, ''));
    if (!file.startsWith(outDir)) file = outDir;

    let stat = await fs.stat(file).catch(() => null);
    if (stat?.isDirectory()) {
      if (!rel.endsWith('/')) {
        res.writeHead(301, { Location: rel + '/' + url.search });
        return res.end();
      }
      file = path.join(file, 'index.html');
      stat = await fs.stat(file).catch(() => null);
    }

    if (stat?.isFile()) return await sendFile(res, file);

    const notFound = path.join(outDir, '404.html');
    if (await fs.stat(notFound).catch(() => null)) return await sendFile(res, notFound, 404);
    res.writeHead(404, { 'Content-Type': 'text/plain' });
    res.end('404');
  } catch (error) {
    res.writeHead(500, { 'Content-Type': 'text/plain' });
    res.end(String(error));
  }
});

const ok = await build();
if (!ok) process.exit(1);

if (shouldWatch) {
  // The site's own sources, plus every Markdown file the docs are built from.
  const watched = new Set([siteDir, ...docsPages.map((p) => path.join(repoRoot, p.src))]);
  let timer = null;
  const rebuild = () => {
    clearTimeout(timer);
    timer = setTimeout(async () => {
      process.stdout.write('\nchange detected — rebuilding\n');
      await build();
    }, 120);
  };
  for (const target of watched) {
    try {
      watch(target, { recursive: target === siteDir }, (_event, name) => {
        if (name && /(^|[/\\])\./.test(name)) return;
        rebuild();
      });
    } catch { /* a missing doc source is already reported by the build */ }
  }
}

server.listen(port, () => {
  console.log(`\n  Quanta site → http://localhost:${port}/`);
  console.log(`  serving      ${path.relative(process.cwd(), outDir) || outDir}${shouldWatch ? '  (watching for changes)' : ''}`);
  console.log('  stop         Ctrl+C\n');
});
