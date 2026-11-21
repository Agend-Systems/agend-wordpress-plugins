/**
 * Courses and directory catalogues: every filter control has a name screen
 * readers announce, as the events catalogue's do.
 * Run with: node --test agend-apps-core/tests/js/catalogue-filter-names.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const tick = () => new Promise((resolve) => setImmediate(resolve));

async function mount(t, script, rootClass, attribute, cfg) {
  const source = readFileSync(require.resolve('../../assets/js/' + script), 'utf8');
  const json = JSON.stringify(cfg).replace(/'/g, '&#39;');
  const dom = new JSDOM(`<!doctype html><div class="${rootClass}" ${attribute}='${json}'></div>`, {
    url: 'https://example.test/list/',
    runScripts: 'outside-only',
  });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = { restUrl: 'https://example.test/wp-json/agend-apps/v1/' };
  window.fetch = () => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({ data: [], meta: { pagination: { page: 1, total_pages: 1 } } }) });
  await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  await tick();
  await tick();
  return window.document;
}

const shared = {
  heading: { show: false },
  layout: { desktop: 3, tablet: 2, mobile: 1, cardRadius: 10 },
  card: {},
  pagination: { style: 'none', perPage: 9 },
  colours: {},
  theme: {},
  exclusions: {},
};

test('the courses filters are named', async (t) => {
  const document = await mount(t, 'courses-catalogue.js', 'agend-courses-catalogue', 'data-agend-courses-config',
    Object.assign({}, shared, { filters: { search: true, category: true, difficulty: true, deliveryMode: true } }));
  assert.equal(document.querySelector('.agend-lms-search').getAttribute('aria-label'), 'Search courses');
  const names = Array.from(document.querySelectorAll('select.agend-lms-filter')).map((s) => s.getAttribute('aria-label'));
  assert.deepEqual(names, ['Filter by category', 'Filter by level', 'Filter by format']);
});

test('the directory filters are named, and a multi-choice filter names its current choice', async (t) => {
  const single = await mount(t, 'directory-catalogue.js', 'agend-directory-catalogue', 'data-agend-directory-config',
    Object.assign({}, shared, { filters: { search: true, category: true, rating: true } }));
  assert.equal(single.querySelector('.agend-dir-search').getAttribute('aria-label'), 'Search listings');
  const names = Array.from(single.querySelectorAll('select.agend-dir-filter')).map((s) => s.getAttribute('aria-label'));
  assert.deepEqual(names, ['Filter by category', 'Filter by rating']);

  const multi = await mount(t, 'directory-catalogue.js', 'agend-directory-catalogue', 'data-agend-directory-config',
    Object.assign({}, shared, { filters: { category: true, categoryMulti: true } }));
  const toggle = multi.querySelector('.agend-dir-multiselect__toggle');
  assert.equal(toggle.getAttribute('aria-label'), 'Filter by category: All Categories');
  const panel = multi.querySelector('.agend-dir-multiselect__panel');
  assert.equal(panel.getAttribute('role'), 'group');
  assert.equal(panel.getAttribute('aria-label'), 'Filter by category');
});
