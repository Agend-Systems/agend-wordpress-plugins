/**
 * The catalogue script's queryParams() (assets/js/directory-catalogue.js) must
 * send the same Organisation filter value as the PHP builder,
 * agend_apps_records_listings_list_args() (includes/records/query.php), which
 * ListingFieldsTest.php asserts against the same fixture:
 * tests/fixtures/directory-location-name-params.json.
 *
 * Run with: node --test agend-apps-core/tests/js/directory-query-params.test.mjs
 *
 * The script is an IIFE with no exports, so queryParams() and the two helpers
 * it calls are cut out of its source and run against a given state. The
 * catalogue sends an empty value as '' and the apiGet() helpers drop it before
 * the request is made, so a blank Organisation reads here as no parameter.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const script = readFileSync(new URL('../../assets/js/directory-catalogue.js', import.meta.url), 'utf8');
const fixture = JSON.parse(readFileSync(new URL('../fixtures/directory-location-name-params.json', import.meta.url), 'utf8'));

function between(source, start, end) {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from);
  assert.ok(from !== -1 && to !== -1, `directory-catalogue.js no longer contains the markers "${start}" and "${end}"`);

  return source.slice(from, to);
}

const helpers = between(script, 'function nearParams(state) {', 'function isMyListing(');
const builder = between(script, 'function queryParams() {', 'function announce(');

const build = new Function('cfg', 'state', `${helpers}\n${builder}\nreturn queryParams();`);

function wireParams(state) {
  const params = build({ pagination: { perPage: 12 }, exclusions: {} }, state);

  return Object.fromEntries(Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== ''));
}

for (const { outcome, when, state, locationName } of fixture.cases) {
  test(`should ${outcome} when ${when}`, () => {
    const params = wireParams(state);

    if (locationName === null) {
      assert.equal(Object.hasOwn(params, 'locationName'), false);
    } else {
      assert.equal(params.locationName, locationName);
    }
  });
}
