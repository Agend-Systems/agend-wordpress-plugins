/**
 * Preloaded filter data (assets/js/filters.js): a catalogue whose first HTML
 * carries the shared facets and categories entries builds its filters from
 * them without asking the REST routes, and refreshes an entry once when a page
 * cache served it after its lifetime.
 *
 * Run with: node --test agend-apps-core/tests/js/filters-preload.test.mjs
 *
 * The script is evaluated afresh for every test, because it keeps one facets
 * request and one preload per page.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { JSDOM } from 'jsdom';

const source = readFileSync(new URL('../../assets/js/filters.js', import.meta.url), 'utf8');

const NOW = 1760000000;

const CITY = {
  control: 'select',
  state: 'city',
  mode: 'array',
  label: 'City',
  values: [],
  source: { facet: 'location.city' },
};
const CATEGORY = {
  control: 'select',
  state: 'categories',
  mode: 'array',
  label: 'Category',
  values: [],
  source: { path: '/directory/categories', value: 'id', labelKey: 'name', distinct: false, limit: 0, parentKey: 'parent_id' },
};

const CACHED_FACETS = { data: { 'location.city': { values: [{ value: 'Hobart', label: 'Hobart' }] } } };
const FRESH_FACETS = { data: { 'location.city': { values: [{ value: 'Hobart', label: 'Hobart' }, { value: 'Launceston', label: 'Launceston' }] } } };
const CACHED_CATEGORIES = { data: [{ id: 'c1', name: 'Clinics', parent_id: null }] };
const FRESH_CATEGORIES = { data: [{ id: 'c1', name: 'Clinics', parent_id: null }, { id: 'c2', name: 'Hospitals', parent_id: null }] };

function shell(window, cfg) {
  const node = window.document.createElement('div');
  node.setAttribute('data-agend-filter', JSON.stringify(cfg));
  node.innerHTML = '<div class="agend-filter__control"><select disabled></select></div>';
  return node;
}

/**
 * Builds the filters for a scope holding the given shells.
 *
 * @return {{ scope: Element, calls: Array<{path: string, params: Object}>, state: Object }}
 */
function build(configs, preload, responses = {}) {
  const { window } = new JSDOM('<!doctype html><div id="scope"></div>');
  const scope = window.document.getElementById('scope');
  configs.forEach((cfg) => scope.appendChild(shell(window, cfg)));

  const FixedDate = { now: () => NOW * 1000 };
  new Function('window', 'document', 'Option', 'Date', source)(window, window.document, window.Option, FixedDate);

  const calls = [];
  const state = { page: 1 };
  window.agendFilters.build(scope, {
    state,
    reload() {},
    apiGet(path, params) {
      calls.push({ path, params });
      return Promise.resolve(responses[path] || {});
    },
    preload,
  });
  return { scope, calls, state };
}

function labels(scope, index) {
  return Array.from(scope.querySelectorAll('select')[index].options).map((option) => option.textContent);
}

async function settle() {
  for (let i = 0; i < 5; i++) {
    await new Promise((resolve) => setTimeout(resolve, 0));
  }
}

function entry(body, age, names) {
  return Object.assign(names ? { names } : {}, { fetched_at: NOW - age, ttl: 600, body });
}

test('should build every control from the preloaded entries without asking when both are fresh', async () => {
  const { scope, calls } = build([CITY, CATEGORY], {
    facets: entry(CACHED_FACETS, 30, ['location.city']),
    categories: entry(CACHED_CATEGORIES, 30),
  });
  await settle();

  assert.deepEqual(calls, []);
  assert.deepEqual(labels(scope, 0), ['Any City', 'Hobart']);
  assert.deepEqual(labels(scope, 1), ['Any Category', 'Clinics']);
  assert.equal(scope.querySelectorAll('select')[0].disabled, false);
});

test('should ask the REST routes as before when nothing is preloaded', async () => {
  const { scope, calls } = build([CITY, CATEGORY], undefined, {
    '/directory/facets': CACHED_FACETS,
    '/directory/categories': CACHED_CATEGORIES,
  });
  await settle();

  assert.deepEqual(calls.map((call) => call.path).sort(), ['/directory/categories', '/directory/facets']);
  assert.deepEqual(labels(scope, 0), ['Any City', 'Hobart']);
});

test('should ask for the facets when the preloaded entry was read for other facet names', async () => {
  const { calls } = build([CITY], { facets: entry(CACHED_FACETS, 30, ['location.city', 'tags']) }, { '/directory/facets': CACHED_FACETS });
  await settle();

  assert.deepEqual(calls, [{ path: '/directory/facets', params: { fields: 'location.city' } }]);
});

test('should paint from a stale entry first, then refresh it once and redraw the control', async () => {
  const { scope, calls } = build(
    [CITY, CATEGORY],
    { facets: entry(CACHED_FACETS, 601, ['location.city']), categories: entry(CACHED_CATEGORIES, 601) },
    { '/directory/facets': FRESH_FACETS, '/directory/categories': FRESH_CATEGORIES }
  );

  assert.deepEqual(calls, [
    { path: '/directory/facets', params: { fields: 'location.city' } },
    { path: '/directory/categories', params: {} },
  ]);
  await settle();

  assert.deepEqual(labels(scope, 0), ['Any City', 'Hobart', 'Launceston']);
  assert.deepEqual(labels(scope, 1), ['Any Category', 'Clinics', 'Hospitals']);
  assert.equal(calls.length, 2);
});

test('should leave a control the visitor has already used when a stale entry is refreshed', async () => {
  let resolveFresh;
  const { window } = new JSDOM('<!doctype html><div id="scope"></div>');
  const scope = window.document.getElementById('scope');
  scope.appendChild(shell(window, CITY));
  new Function('window', 'document', 'Option', 'Date', source)(window, window.document, window.Option, { now: () => NOW * 1000 });
  const state = { page: 1 };
  window.agendFilters.build(scope, {
    state,
    reload() {},
    apiGet() {
      return new Promise((resolve) => {
        resolveFresh = resolve;
      });
    },
    preload: { facets: entry(CACHED_FACETS, 601, ['location.city']) },
  });
  await settle();

  const select = scope.querySelector('select');
  select.value = '0';
  select.dispatchEvent(new window.Event('change'));
  resolveFresh(FRESH_FACETS);
  await settle();

  assert.deepEqual(state.city, ['Hobart']);
  assert.deepEqual(labels(scope, 0), ['Any City', 'Hobart']);
  assert.equal(select.value, '0');
});
