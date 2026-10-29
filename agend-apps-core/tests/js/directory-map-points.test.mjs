/**
 * The Agend Map's points-first load (assets/js/directory-map.js): the query
 * it asks for pins, how it tells map points from map markers, when it groups
 * pins whatever the setting, and the pin details it fetches by listing id
 * when a pin is opened.
 *
 * Run with: node --test agend-apps-core/tests/js/directory-map-points.test.mjs
 *
 * The script is an IIFE with no exports, so the functions under test are cut
 * out of its source by name.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const script = readFileSync(new URL('../../assets/js/directory-map.js', import.meta.url), 'utf8');

function cutFunction(source, name) {
  const start = source.indexOf(`  function ${name}(`);
  assert.notEqual(start, -1, `${name} not found in directory-map.js`);
  let depth = 0;
  for (let i = source.indexOf('{', start); i < source.length; i += 1) {
    if (source[i] === '{') depth += 1;
    if (source[i] === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(start, i + 1);
    }
  }
  throw new Error(`${name} has no closing brace`);
}

function cutVar(source, name) {
  const line = source.match(new RegExp(`^  var ${name} = [^;]+;$`, 'm'));
  assert.ok(line, `${name} not found in directory-map.js`);
  return line[0];
}

const { markersQuery, isMarkerRow, locationAt, drawsUngrouped, createPinDetails } = new Function(
  [
    cutVar(script, 'UNGROUPED_PIN_LIMIT'),
    ...['withParam', 'markersQuery', 'isMarkerRow', 'locationAt', 'drawsUngrouped', 'createPinDetails'].map((name) => cutFunction(script, name)),
    'return { markersQuery, isMarkerRow, locationAt, drawsUngrouped, createPinDetails };',
  ].join('\n'),
)();

const LISTING = '00000000-0000-4000-8000-000000000001';
const OTHER = '00000000-0000-4000-8000-000000000002';

function marker(id, extra = {}) {
  return { id, slug: `listing-${id.slice(-1)}`, name: `Listing ${id.slice(-1)}`, locations: [], distance_km: null, ...extra };
}

// A fetchMarkers stand-in that records each query and answers from a queue.
function gateway(...answers) {
  const queries = [];
  const fetchMarkers = (query) => {
    queries.push(query);
    const answer = answers.length ? answers.shift() : { data: [] };
    return answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer);
  };
  return { queries, fetchMarkers };
}

test('should keep the catalogue filters and drop paging, ordering and any page size when the map asks for pins', () => {
  const query = markersQuery({ q: 'physio', category: 'c1', bbox: '1,0,1,0', page: 2, limit: 12, per_page: 12, sortBy: 'name', sortOrder: 'asc' });

  assert.deepEqual(query, { q: 'physio', category: 'c1', bbox: '1,0,1,0' });
});

test('should ask for map points with no page size when the map loads', () => {
  const load = cutFunction(script, 'load');

  assert.match(load, /apiGet\('\/directory\/markers', withParam\(filters, 'projection', 'points'\)\)/);
  assert.doesNotMatch(load, /\blimit\b/);
});

test('should treat a row as a map marker when it carries a slug', () => {
  assert.equal(isMarkerRow(marker(LISTING)), true);
});

test('should treat a row as a map point when it carries only an id, a name and locations', () => {
  assert.equal(isMarkerRow({ id: LISTING, name: 'Listing 1', locations: [{ latitude: -33.8, longitude: 151.2 }] }), false);
});

test('should find the listing location whose coordinates equal the pin', () => {
  const office = { name: 'Office', latitude: -33.86, longitude: 151.2 };
  const clinic = { name: 'Clinic', latitude: -37.81, longitude: 144.96 };

  assert.equal(locationAt(marker(LISTING, { locations: [office, clinic] }), -37.81, 144.96), clinic);
});

test('should find the location in the pin own place when two locations share its coordinates', () => {
  const practice = { name: 'Practice', latitude: -41.44, longitude: 147.16 };
  const company = { name: 'Practice Pty Ltd', latitude: -41.44, longitude: 147.16 };
  const listing = marker(LISTING, { locations: [company, practice] });

  assert.equal(locationAt(listing, -41.44, 147.16, 1), practice);
  assert.equal(locationAt(listing, -41.44, 147.16, 0), company);
});

test('should search every location by coordinates when the pin own place no longer holds them', () => {
  const office = { name: 'Office', latitude: -33.86, longitude: 151.2 };
  const clinic = { name: 'Clinic', latitude: -37.81, longitude: 144.96 };

  assert.equal(locationAt(marker(LISTING, { locations: [clinic, office] }), -37.81, 144.96, 1), clinic);
  assert.equal(locationAt(marker(LISTING, { locations: [office] }), -37.81, 144.96, 3), null);
});

test('should find no listing location when none has the pin coordinates', () => {
  const office = { name: 'Office', latitude: -33.86, longitude: 151.2 };

  assert.equal(locationAt(marker(LISTING, { locations: [office] }), -33.861, 151.2), null);
  assert.equal(locationAt(null, -33.86, 151.2), null);
});

test('should draw pins ungrouped when grouping is off and there are at most 2,000', () => {
  assert.equal(drawsUngrouped({ cluster: false }, 2000), true);
  assert.equal(drawsUngrouped({ cluster: false }, 1), true);
});

test('should group pins when grouping is off and there are more than 2,000', () => {
  assert.equal(drawsUngrouped({ cluster: false }, 2001), false);
});

test('should group pins at any count when grouping is on', () => {
  assert.equal(drawsUngrouped({ cluster: true }, 3), false);
});

test('should ask for one listing in the default projection with the points filters when a pin is opened', async () => {
  const { queries, fetchMarkers } = gateway({ data: [marker(LISTING)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({ q: 'physio', bbox: '1,0,1,0' });

  const found = await details.get(LISTING);

  assert.equal(found.id, LISTING);
  assert.deepEqual(queries, [{ q: 'physio', bbox: '1,0,1,0', ids: LISTING }]);
});

test('should show the details again with no second request when a pin is opened twice for the same points response', async () => {
  const { queries, fetchMarkers } = gateway({ data: [marker(LISTING)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({});

  await details.get(LISTING);
  const again = await details.get(LISTING);

  assert.equal(again.id, LISTING);
  assert.equal(queries.length, 1);
  assert.equal(details.peek(LISTING).marker.id, LISTING);
});

test('should share one request when a pin is opened again before its details arrive', async () => {
  const { queries, fetchMarkers } = gateway({ data: [marker(LISTING)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({});

  const [first, second] = await Promise.all([details.get(LISTING), details.get(LISTING)]);

  assert.equal(first, second);
  assert.equal(queries.length, 1);
});

test('should ask again when a pin is opened after a new points response', async () => {
  const { queries, fetchMarkers } = gateway({ data: [marker(LISTING, { distance_km: 4 })] }, { data: [marker(LISTING, { distance_km: 9 })] });
  const details = createPinDetails(fetchMarkers);
  details.reset({ lat: -33.8, lng: 151.2, radius: 10 });
  await details.get(LISTING);

  details.reset({ lat: -37.8, lng: 144.9, radius: 10 });

  assert.equal(details.peek(LISTING), null);
  const found = await details.get(LISTING);
  assert.equal(found.distance_km, 9);
  assert.deepEqual(queries[1], { lat: -37.8, lng: 144.9, radius: 10, ids: LISTING });
});

test('should report the listing as gone when the response no longer holds it', async () => {
  const { fetchMarkers } = gateway({ data: [marker(OTHER)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({});

  assert.equal(await details.get(LISTING), null);
  assert.deepEqual(details.peek(LISTING), { marker: null });
});

test('should fail and keep nothing when the request fails, so trying again asks again', async () => {
  const { queries, fetchMarkers } = gateway(new Error('offline'), { data: [marker(LISTING)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({});

  await assert.rejects(details.get(LISTING));
  assert.equal(details.peek(LISTING), null);

  const found = await details.get(LISTING);
  assert.equal(found.id, LISTING);
  assert.equal(queries.length, 2);
});

test('should fail when the response carries no listings array', async () => {
  const { fetchMarkers } = gateway({ code: 'rest_error', message: 'Bad gateway' });
  const details = createPinDetails(fetchMarkers);
  details.reset({});

  await assert.rejects(details.get(LISTING));
  assert.equal(details.peek(LISTING), null);
});

test('should keep a superseded response out of the details for the next points response', async () => {
  let answer;
  const pending = new Promise((resolve) => {
    answer = resolve;
  });
  const queries = [];
  const details = createPinDetails((query) => {
    queries.push(query);
    return queries.length === 1 ? pending : Promise.resolve({ data: [marker(LISTING, { distance_km: 9 })] });
  });
  details.reset({ q: 'old' });
  const stale = details.get(LISTING);

  details.reset({ q: 'new' });
  answer({ data: [marker(LISTING, { distance_km: 4 })] });
  await stale;

  assert.equal(details.peek(LISTING), null);
  assert.equal((await details.get(LISTING)).distance_km, 9);
});
