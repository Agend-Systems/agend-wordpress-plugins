/**
 * Search links (assets/js/search-link.js): the browser's parse rules and the
 * encoder, against the case table SearchLinkParseTest.php also loads, so the
 * browser and the server read every search link the same way:
 * tests/fixtures/directory-search-link-cases.json.
 *
 * Run with: node --test agend-apps-core/tests/js/search-link.test.mjs
 *
 * The module is a browser script that attaches itself to window, so it is run
 * against a stand-in window and read back from there.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = readFileSync(new URL('../../assets/js/search-link.js', import.meta.url), 'utf8');
const fixture = JSON.parse(readFileSync(new URL('../fixtures/directory-search-link-cases.json', import.meta.url), 'utf8'));
const fakeWindow = {};
new Function('window', source)(fakeWindow);
const link = fakeWindow.agendSearchLink;

for (const { outcome, when, controls, bbox, query, expect } of fixture.cases) {
  test(`should ${outcome} when ${when}`, () => {
    assert.deepEqual(link.decode(query, link.readable(fixture.controls[controls]), { bbox }), expect);
  });
}

for (const { outcome, when, state, options, query } of fixture.encodeCases) {
  test(`should ${outcome} when ${when}`, () => {
    const pairs = link.encode(state, options);
    assert.equal(pairs.map((pair) => pair.join('=')).join('&'), query);
  });
}

test('should read back what it writes when a search is encoded and decoded', () => {
  const state = {
    search: 'Sarah, Smith & Co',
    categories: ['11111111-2222-4333-8444-555555555555'],
    custom_fields: { funding: ['home,care', '50%', 'a&b=c'], fees: { min: '10' } },
    location_name: "St Vincent's \\ Co",
    location_city: ['Carlton North', 'Fitzroy'],
    near: { lat: -37.8, lng: 144.96, radius: 20, label: 'Carlton, VIC 3053', source: 'place' },
    view: 'map',
  };
  const query = link.apply('/find', link.encode(state, { page: 2, numbered: true, defaultView: 'list' })).split('?')[1];

  assert.deepEqual(link.decode(query, link.readable(fixture.controls.full), { bbox: true }), { state, page: 2 });
});

test('should read a crafted link padded with 200,000 spaces and zeros quickly when its values are trimmed and normalised', () => {
  const allowed = link.readable(fixture.controls.full);
  const query = `q=${encodeURIComponent(' '.repeat(200000) + 'x' + ' '.repeat(200000) + 'y')}&rating=1.${'0'.repeat(200000)}1`;
  const started = Date.now();
  const decoded = link.decode(query, allowed, { bbox: true });

  assert.ok(Date.now() - started < 1000, 'parsing took longer than a second');
  assert.equal(decoded.state.search, 'x');
  assert.equal(decoded.state.rating, `1.${'0'.repeat(200000)}1`);
});

test('should keep every other query parameter as written when the search link parameters are replaced', () => {
  const href = 'https://example.test/find/?page_id=7&utm_source=a%20b&q=old&rf_fees=1:2&lang=en#results';

  assert.equal(link.apply(href, [['q', 'new']]), 'https://example.test/find/?page_id=7&utm_source=a%20b&lang=en&q=new#results');
});

test('should leave the bare address when every parameter is a search link parameter and the search is empty', () => {
  assert.equal(link.apply('https://example.test/find/?q=old&pg=2', []), 'https://example.test/find/');
});

test('should treat only the table names and the custom field prefixes as search link parameters when names are checked', () => {
  assert.deepEqual(['q', 'pg', 'cf_x', 'rf_x', 'cf_', 'page', 'featured', 'tag', 'utm_source'].map(link.isParam), [true, true, true, true, false, false, false, false, false]);
});

test('should count the view alone as no search when a link sets only the view', () => {
  assert.equal(link.isSearch({ state: { view: 'map' }, page: 1 }), false);
  assert.equal(link.isSearch({ state: {}, page: 2 }), true);
  assert.equal(link.isSearch({ state: { rating: '4' }, page: 1 }), true);
});

test('should name the restored values no visible control writes when controls have hidden themselves', () => {
  const [search, , category, , , , , , , rating, , , location, , , funding, fees] = fixture.controls.full;
  const restored = {
    search: 'physio',
    categories: ['11111111-2222-4333-8444-555555555555'],
    rating: '4',
    near: { lat: 1, lng: 2, radius: 5, label: 'x', source: 'place' },
    custom_fields: { funding: ['ndis'], fees: { min: '1' } },
    bbox: '1,0,1,0',
    bboxOrigin: 'visitor',
    view: 'map',
  };
  const shells = [
    { config: search, hidden: false },
    { config: category, hidden: false },
    { config: { ...category, categoryLevel: 'sub' }, hidden: true },
    { config: rating, hidden: true },
    { config: location, hidden: true },
    { config: funding, hidden: true },
    { config: fees, hidden: false },
  ];

  assert.deepEqual(link.unwritten(restored, shells), [{ key: 'rating' }, { key: 'near' }, { key: 'custom_fields', field: 'funding' }]);
});
