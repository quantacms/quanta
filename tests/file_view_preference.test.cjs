const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const path = require('node:path');
const { test } = require('node:test');
const vm = require('node:vm');

const source = readFileSync(process.env.QUANTA_FILE_UPLOAD_SOURCE
  || path.join(__dirname, '../src/modules/file/assets/js/file-upload.js'), 'utf8');
const storageKey = 'quanta-file-admin-view';

// Exercise the real preference, delegated click and refresh handlers. DOM
// operations are isolated here; this is not a PHP or browser layout test.
function fixture({ saved, denied = false, quota = false } = {}) {
  const values = new Map(saved === undefined ? [] : [[storageKey, saved]]);
  const document = {};
  const handlers = new Map();
  const panel = { mode: null };
  const storage = {
    getItem(key) { return values.get(key) ?? null; },
    setItem(key, value) {
      if (quota) throw new Error('QuotaExceededError');
      values.set(key, value);
    },
  };
  const window = {};
  Object.defineProperty(window, 'localStorage', {
    get() {
      if (denied) throw new Error('SecurityError');
      return storage;
    },
  });

  function selection(items) {
    return {
      items,
      on(event, selector, callback) { handlers.set(`${event}:${selector}`, callback); return this; },
      bind(event, callback) { handlers.set(event, callback); return this; },
      each(callback) { items.forEach((item) => callback.call(item)); return this; },
      data(key) { return items[0]?.[key]; },
      closest() { return selection([panel]); },
      val() { return undefined; },
    };
  }
  const $ = (value) => {
    if (typeof value === 'function') return selection([]); // Upload setup is out of scope.
    if (value === '.file-view-container') return selection([panel]);
    if (typeof value === 'string') return selection([]);
    return selection([value]);
  };
  const context = vm.createContext({ window, document, $, console });
  vm.runInContext(source, context, { filename: 'file-upload.js' });
  context.applyFileView = (container, mode) => { container.items[0].mode = mode; };
  context.initFileViewSwitchers();

  return {
    panel, values,
    select(mode) { handlers.get('click:.file-view-button').call({ 'file-view': mode }); },
    refresh() { handlers.get('refresh')(); },
  };
}

test('list is the initial mode when no preference exists', () => {
  assert.equal(fixture().panel.mode, 'list');
});

test('a saved preview preference is reapplied on refresh', () => {
  const page = fixture({ saved: 'preview' });
  page.refresh();
  assert.equal(page.panel.mode, 'preview');
});

test('choosing preview stores the preference for the next page', () => {
  const page = fixture();
  page.select('preview');
  assert.equal(page.values.get(storageKey), 'preview');
  assert.equal(fixture({ saved: page.values.get(storageKey) }).panel.mode, 'preview');
});

test('an unavailable localStorage does not reset preview after refresh', () => {
  const page = fixture({ denied: true });
  page.select('preview');
  assert.equal(page.panel.mode, 'preview');
  page.refresh();
  assert.equal(page.panel.mode, 'preview');
});

test('a failed write does not restore the stale stored list preference', () => {
  const page = fixture({ saved: 'list', quota: true });
  page.select('preview');
  assert.equal(page.values.get(storageKey), 'list');
  page.refresh();
  assert.equal(page.panel.mode, 'preview');
});

test('switching back to list remains possible when storage is disabled', () => {
  const page = fixture({ denied: true });
  page.select('preview');
  page.select('list');
  page.refresh();
  assert.equal(page.panel.mode, 'list');
});

test('invalid persisted values fall back to list', () => {
  assert.equal(fixture({ saved: 'unknown' }).panel.mode, 'list');
});

test('an ordinary Shadow refresh retains a newly selected preview', () => {
  const page = fixture();
  page.select('preview');
  page.refresh();
  assert.equal(page.panel.mode, 'preview');
});
