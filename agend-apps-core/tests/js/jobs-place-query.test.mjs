/**
 * Jobs board distance search helpers (assets/js/jobs-catalogue.js):
 * - placeQuery: the board's Region is added to a typed place before it goes
 *   to the geocoder, unless the text already names it as a whole word, and
 *   the region always fits the lookup's 200 character limit.
 * - isUnmeasured: whether a distance search came back without distances
 *   (a gateway that ignores the parameters), leaving out remote jobs only
 *   when they were asked for.
 *
 * Run with: node --test agend-apps-core/tests/js/jobs-place-query.test.mjs
 *
 * The script is a browser script that starts the board on load, so it is run
 * against a stand-in window and document with no boards on the page, and the
 * helper is read back from window.agendJobsBoard.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = readFileSync(new URL('../../assets/js/jobs-catalogue.js', import.meta.url), 'utf8');
const fakeWindow = {};
const fakeDocument = {
  readyState: 'complete',
  querySelectorAll: () => [],
  addEventListener: () => {},
};
new Function('window', 'document', source)(fakeWindow, fakeDocument);
const { placeQuery } = fakeWindow.agendJobsBoard;

test('should add the region to a bare postcode', () => {
  assert.equal(placeQuery('5000', 'Australia'), '5000, Australia');
});

test('should send the text as typed when no region is set', () => {
  assert.equal(placeQuery('5000', ''), '5000');
  assert.equal(placeQuery('5000', '   '), '5000');
  assert.equal(placeQuery('5000', undefined), '5000');
});

test('should not add the region again when the text already names it as a word', () => {
  assert.equal(placeQuery('Adelaide, Australia', 'Australia'), 'Adelaide, Australia');
  assert.equal(placeQuery('adelaide australia', 'Australia'), 'adelaide australia');
  assert.equal(placeQuery('Auckland NZ', 'NZ'), 'Auckland NZ');
});

test('should still add the region when it only appears inside another word', () => {
  assert.equal(placeQuery('Houston', 'US'), 'Houston, US');
  assert.equal(placeQuery('Franz Josef', 'NZ'), 'Franz Josef, NZ');
  assert.equal(placeQuery('Indianapolis', 'India'), 'Indianapolis, India');
  assert.equal(placeQuery('Bukit Timah', 'UK'), 'Bukit Timah, UK');
});

test('should treat a region with regular expression characters as plain text', () => {
  assert.equal(placeQuery('Springfield', 'U.S.'), 'Springfield, U.S.');
  assert.equal(placeQuery('Springfield, U.S.', 'U.S.'), 'Springfield, U.S.');
});

test('should keep the whole region within the 200 character limit', () => {
  const long = 'x'.repeat(250);
  const query = placeQuery(long, 'Australia');
  assert.equal(query.length, 200);
  assert.ok(query.endsWith(', Australia'));
  assert.equal(placeQuery(long, '').length, 200);
});

const { isUnmeasured } = fakeWindow.agendJobsBoard;
const near = (km) => ({ location_type: 'on-site', distance_km: km });
const remote = { location_type: 'remote', distance_km: null };
const unmeasuredOnSite = { location_type: 'on-site', distance_km: null };

test('should call a distance search measured when the located jobs carry a distance', () => {
  assert.equal(isUnmeasured([near(2.4), remote], true), false);
});

test('should call a page of only remote jobs measured when remote jobs were asked for', () => {
  assert.equal(isUnmeasured([remote, remote], true), false);
});

test('should count remote jobs as before when they were not asked for', () => {
  // A gateway that ignores lat/lng and returns only remote jobs: the note shows.
  assert.equal(isUnmeasured([remote, remote], false), true);
});

test('should call a list with no distances unmeasured', () => {
  assert.equal(isUnmeasured([unmeasuredOnSite, remote], true), true);
  assert.equal(isUnmeasured([unmeasuredOnSite], false), true);
});

test('should not call an empty list unmeasured', () => {
  assert.equal(isUnmeasured([], true), false);
  assert.equal(isUnmeasured([], false), false);
});
