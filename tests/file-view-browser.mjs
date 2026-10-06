import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { createRequire } from 'node:module';
import { readFile, mkdir, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const require = createRequire(import.meta.url);
let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (error) {
  const installedModules = process.env.CODEX_PRIMARY_RUNTIME_NODE_MODULES;
  if (!installedModules) {
    throw new Error('Install playwright locally first: npm install --no-save --package-lock=false playwright', { cause: error });
  }
  ({ chromium } = require(resolve(installedModules, 'playwright')));
}

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const outputDirectory = process.env.QUANTA_BROWSER_EVIDENCE
  ? resolve(process.env.QUANTA_BROWSER_EVIDENCE) : null;
const scriptSource = process.env.QUANTA_FILE_UPLOAD_SOURCE
  ? resolve(process.env.QUANTA_FILE_UPLOAD_SOURCE)
  : resolve(root, 'src/modules/file/assets/js/file-upload.js');
const cssSource = process.env.QUANTA_FILE_VIEW_CSS_SOURCE
  ? resolve(process.env.QUANTA_FILE_VIEW_CSS_SOURCE)
  : resolve(root, 'src/modules/file/assets/css/file.css');

// Use the actual Qtag's button markup and actual file-row template. PHP rendering
// and authentication are outside this isolated browser fixture's scope.
const php = await readFile(resolve(root, 'src/modules/list/classes/Qtags/FilesAdmin.qtag.php'), 'utf8');
const switcherAssignment = php.match(/\$switcher\s*=([\s\S]+?);\s*\n/);
assert.ok(switcherAssignment, 'FilesAdmin must define the switcher markup');
const switcher = [...switcherAssignment[1].matchAll(/'((?:\\.|[^'\\])*)'/g)]
  .map((match) => match[1].replace(/\\'/g, "'").replace(/\\\\/g, '\\')).join('');
assert.ok(switcher.includes('data-file-view="preview"'));
const rowTemplate = await readFile(resolve(root, 'src/modules/list/tpl/file_admin.tpl.php'), 'utf8');
const names = ['architecture.svg', 'notes.pdf', 'source.json'];
const escape = (value) => String(value).replaceAll('&', '&amp;').replaceAll('"', '&quot;')
  .replaceAll('<', '&lt;').replaceAll('>', '&gt;');

function fileRow(name, index, sortable) {
  let markup = rowTemplate.replaceAll('{LISTNODE}', 'example')
    .replaceAll('[FILE_ATTRIBUTE|name=name|node=example:{LISTITEM}]', escape(name))
    .replaceAll('[TEXT:Loading preview...]', 'Loading preview...');
  if (!sortable) markup = markup.replace('<span class="sort-handle"></span>', '');
  return `<li class="list-item list-item-file_admin" data-file-id="${index}">${markup}</li>`;
}

function container(id, options) {
  const files = options.empty ? [] : options.longName
    ? [`${'unbroken'.repeat(22)}.pdf`, ...names.slice(1)] : names;
  return `<div id="${id}" class="file-view-container">${switcher}
    <ul class="list file_admin ${options.sortable ? 'list-file_admin' : ''} just-view" data-node="example">
    ${files.map((name, index) => fileRow(name, index, options.sortable)).join('')}
    </ul></div>`;
}

function documentFixture(options) {
  return `<!doctype html><html lang="en"><meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Manage Files browser fixture</title>
  <link rel="stylesheet" href="/file.css"><link rel="stylesheet" href="/list.css">
  <style>body{margin:0;padding:20px;font:15px system-ui;color:#222;box-sizing:border-box}
  main{max-width:920px;margin:0 auto}h1{font-size:24px}p{color:#555}
  .fixture-label{font-size:12px;margin-top:20px}.file-preview-item{box-sizing:border-box}
  </style><main><h1>Manage Files</h1><p>Choose a display mode for these example files.</p>
  <div class="shadow-content"><form id="files-form"><input id="edit_path" type="hidden" value="example">
  <input id="tmp_files_dir" type="hidden" value="fixture"><input id="edit_thumbnail" type="hidden" value="">
  ${container('files-one', options)}${options.multiple ? container('files-two', options) : ''}
  </form></div><p class="fixture-label">Isolated browser fixture using Quanta's Qtag markup and production assets.</p>
  </main><script src="/jquery.js"></script><script src="/jquery-ui.js"></script>
  <script src="/file-upload.js"></script></html>`;
}

const assets = new Map([
  ['/jquery.js', [resolve(root, 'src/modules/jquery/assets/js/jquery.min.js'), 'text/javascript']],
  ['/jquery-ui.js', [resolve(root, 'src/modules/jquery/assets/js/jquery-ui.min.js'), 'text/javascript']],
  ['/file-upload.js', [scriptSource, 'text/javascript']],
  ['/file.css', [cssSource, 'text/css']],
  ['/list.css', [resolve(root, 'src/modules/list/assets/css/list.css'), 'text/css']],
]);
const server = createServer(async (request, response) => {
  try {
    const url = new URL(request.url, 'http://localhost');
    const asset = assets.get(url.pathname);
    if (asset) {
      response.writeHead(200, { 'Content-Type': asset[1], 'Cache-Control': 'no-store' });
      response.end(await readFile(asset[0]));
    } else if (url.pathname.startsWith('/qtag/')) {
      // Only the preview endpoint is faked; the real jQuery load/refresh path runs.
      response.writeHead(200, { 'Content-Type': 'text/html' });
      response.end('<span class="file-preview-item" style="display:block;background:linear-gradient(135deg,#e2e7f2,#b6c8e9)"></span>');
    } else if (url.pathname === '/') {
      response.writeHead(200, { 'Content-Type': 'text/html', 'Cache-Control': 'no-store' });
      response.end(documentFixture({
        empty: url.searchParams.has('empty'), longName: url.searchParams.has('long'),
        sortable: url.searchParams.has('sortable'), multiple: url.searchParams.has('multiple'),
      }));
    } else {
      response.writeHead(204);
      response.end();
    }
  } catch (error) {
    response.writeHead(500);
    response.end(String(error));
  }
});
await new Promise((resolve) => server.listen(0, '127.0.0.1', resolve));
const origin = `http://127.0.0.1:${server.address().port}`;
const browser = await chromium.launch(process.env.QUANTA_CHROMIUM_EXECUTABLE ? {
  headless: true,
  executablePath: process.env.QUANTA_CHROMIUM_EXECUTABLE,
  args: JSON.parse(process.env.QUANTA_CHROMIUM_ARGS || '[]'),
} : { headless: true, channel: 'chromium' });
const results = [];

async function test(name, callback, options = {}) {
  const context = await browser.newContext({ viewport: options.viewport ?? { width: 1000, height: 750 } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  try {
    if (options.setup) await page.addInitScript(options.setup);
    await page.goto(`${origin}/${options.query ?? ''}`);
    await page.waitForFunction(() => typeof initFileViewSwitchers === 'function');
    await page.evaluate(() => new Promise((resolve) => $(resolve)));
    const textWidth = await page.evaluate(async () => {
      await document.fonts.ready;
      const canvas = document.createElement('canvas');
      const context = canvas.getContext('2d');
      context.font = '16px sans-serif';
      return context.measureText('Manage Files').width;
    });
    assert.ok(textWidth > 40, 'Browser fonts must render text before testing layout');
    await callback(page);
    assert.deepEqual(errors, [], 'Production JavaScript must not throw');
    results.push({ name, status: 'passed' });
    console.log(`PASS ${name}`);
  } catch (error) {
    results.push({ name, status: 'failed', error: error.message });
    console.log(`FAIL ${name}: ${error.message}`);
  } finally {
    await context.close();
  }
}

async function assertMode(page, mode, id = 'files-one') {
  const target = page.locator(`#${id}`);
  assert.equal(await target.locator('[data-file-view="preview"]').getAttribute('aria-pressed'), mode === 'preview' ? 'true' : 'false');
  assert.equal(await target.locator('[data-file-view="list"]').getAttribute('aria-pressed'), mode === 'list' ? 'true' : 'false');
  assert.equal(await target.evaluate((element) => element.classList.contains('file-view-preview-mode')), mode === 'preview');
}

async function refresh(page, replaceRows = false) {
  await page.evaluate((replace) => {
    if (replace) {
      const container = document.querySelector('#files-one');
      container.outerHTML = container.outerHTML.replace('file-view-preview-mode', '');
    }
    $(document).trigger('refresh');
  }, replaceRows);
  await page.waitForLoadState('networkidle');
}

try {
  await test('Default list mode and accessible controls', async (page) => {
    await assertMode(page, 'list');
    assert.equal(await page.getByRole('group', { name: 'File display mode' }).count(), 1);
    assert.equal(await page.getByRole('button', { name: 'List', exact: true }).count(), 1);
    assert.equal(await page.getByRole('button', { name: 'Preview', exact: true }).count(), 1);
  });
  await test('Preview selection survives a page reload', async (page) => {
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await assertMode(page, 'preview');
    await page.reload();
    await page.evaluate(() => new Promise((resolve) => $(resolve)));
    await assertMode(page, 'preview');
  });
  await test('Keyboard activation uses Enter and Space', async (page) => {
    const preview = page.getByRole('button', { name: 'Preview', exact: true });
    await preview.focus();
    await page.keyboard.press('Enter');
    await assertMode(page, 'preview');
    await page.getByRole('button', { name: 'List', exact: true }).focus();
    await page.keyboard.press('Space');
    await assertMode(page, 'list');
  });
  await test('Shadow refresh and replacement retain the selected mode', async (page) => {
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await refresh(page, true);
    await assertMode(page, 'preview');
    assert.equal(await page.locator('.file-view-button').count(), 2);
  });
  await test('Unavailable localStorage keeps the mode after Shadow refresh', async (page) => {
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await refresh(page, true);
    await assertMode(page, 'preview');
  }, { setup: () => Object.defineProperty(window, 'localStorage', {
    configurable: true, get() { throw new DOMException('Storage disabled', 'SecurityError'); },
  }) });
  await test('A failed storage write does not restore an older preference', async (page) => {
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await refresh(page, true);
    await assertMode(page, 'preview');
  }, { setup: () => {
    localStorage.setItem('quanta-file-admin-view', 'list');
    Storage.prototype.setItem = function () { throw new DOMException('Quota full', 'QuotaExceededError'); };
  } });
  await test('An invalid saved preference falls back to list mode', async (page) => {
    await assertMode(page, 'list');
  }, { setup: () => localStorage.setItem('quanta-file-admin-view', 'unexpected') });
  await test('An empty file list can still switch modes', async (page) => {
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await refresh(page);
    await assertMode(page, 'preview');
    assert.equal(await page.locator('.list-item-file_admin').count(), 0);
  }, { query: '?empty=1' });
  await test('A saved preview preference initializes each file panel', async (page) => {
    await assertMode(page, 'preview', 'files-one');
    await assertMode(page, 'preview', 'files-two');
  }, { query: '?multiple=1', setup: () => localStorage.setItem('quanta-file-admin-view', 'preview') });
  for (const width of [320, 375]) {
    await test(`Long filenames stay inside preview cards at ${width}px`, async (page) => {
      await page.getByRole('button', { name: 'Preview', exact: true }).click();
      const geometry = await page.evaluate(() => {
        const row = document.querySelector('.list-item-file_admin').getBoundingClientRect();
        const link = document.querySelector('.file-link').getBoundingClientRect();
        return { rowLeft: row.left, rowRight: row.right, linkLeft: link.left, linkRight: link.right,
          scrollWidth: document.documentElement.scrollWidth, viewport: innerWidth };
      });
      assert.ok(geometry.linkLeft >= geometry.rowLeft - 1 && geometry.linkRight <= geometry.rowRight + 1,
        `Filename escapes its card: ${JSON.stringify(geometry)}`);
      assert.ok(geometry.scrollWidth <= geometry.viewport, 'Preview must not create horizontal page overflow');
    }, { query: '?long=1', viewport: { width, height: 800 } });
  }
  await test('Changing the mode leaves the file order intact', async (page) => {
    const ids = () => page.locator('.list-item-file_admin').evaluateAll((rows) => rows.map((row) => row.dataset.fileId));
    const before = await ids();
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await refresh(page);
    await page.getByRole('button', { name: 'List', exact: true }).click();
    assert.deepEqual(await ids(), before);
    assert.equal(await page.locator('.sort-handle').count(), 3);
  }, { query: '?sortable=1' });
  for (const mode of ['list', 'preview']) {
    await test(`Real jQuery UI dragging still reorders files in ${mode} mode`, async (page) => {
      await refresh(page);
      if (mode === 'preview') await page.getByRole('button', { name: 'Preview', exact: true }).click();
      const initial = await page.locator('.list-item-file_admin').evaluateAll((rows) => rows.map((row) => row.dataset.fileId));
      const first = await page.locator('.sort-handle').first().boundingBox();
      const last = await page.locator('.list-item-file_admin').last().boundingBox();
      await page.mouse.move(first.x + first.width / 2, first.y + first.height / 2);
      await page.mouse.down();
      await page.mouse.move(last.x + last.width / 2, last.y + last.height * 0.85, { steps: 20 });
      await page.mouse.up();
      const after = await page.locator('.list-item-file_admin').evaluateAll((rows) => rows.map((row) => row.dataset.fileId));
      assert.notDeepEqual(after, initial, 'Dragging through the existing handle should reorder the list');
      assert.deepEqual([...after].sort(), [...initial].sort());
    }, { query: '?sortable=1' });
  }

  if (outputDirectory) {
    await mkdir(outputDirectory, { recursive: true });
    const page = await browser.newPage({ viewport: { width: 1000, height: 750 } });
    await page.goto(`${origin}/?sortable=1`);
    await page.evaluate(() => new Promise((resolve) => $(resolve)));
    await refresh(page);
    await page.screenshot({ path: resolve(outputDirectory, 'file-view-list.png'), fullPage: true });
    await page.getByRole('button', { name: 'Preview', exact: true }).click();
    await page.screenshot({ path: resolve(outputDirectory, 'file-view-preview.png'), fullPage: true });
    await page.setViewportSize({ width: 375, height: 800 });
    await page.screenshot({ path: resolve(outputDirectory, 'file-view-mobile.png'), fullPage: true });
    await page.close();
    await writeFile(resolve(outputDirectory, 'results.json'), JSON.stringify({
      browser: `Chromium ${browser.version()}`, node: process.version, results,
    }, null, 2) + '\n');
  }
} finally {
  await browser.close();
  await new Promise((resolve) => server.close(resolve));
}
const failed = results.filter((result) => result.status === 'failed').length;
console.log(`\n${results.length - failed} passed, ${failed} failed`);
if (failed) process.exitCode = 1;
