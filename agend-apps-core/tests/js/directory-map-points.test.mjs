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

// A minimal DOM node: enough for el(), popupStatus() and a click on the
// Try again button.
class FakeNode {
  constructor(tag) {
    this.tag = tag;
    this.className = '';
    this.children = [];
    this.text = '';
    this.attributes = {};
    this.listeners = {};
    this.parent = null;
  }

  set textContent(value) {
    this.text = value;
  }

  get firstChild() {
    return this.children[0] || null;
  }

  appendChild(child) {
    child.parent = this;
    this.children.push(child);
    return child;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  addEventListener(type, fn) {
    this.listeners[type] = fn;
  }

  click() {
    const event = { stopped: false, stopPropagation() { this.stopped = true; } };
    this.listeners.click(event);
    return event;
  }

  focus() {
    fakeDocument.activeElement = this;
  }

  contains(node) {
    for (let at = node; at; at = at.parent) if (at === this) return true;
    return false;
  }

  find(className) {
    if (this.className.split(' ').includes(className)) return this;
    for (const child of this.children) {
      const found = child.find(className);
      if (found) return found;
    }
    return null;
  }

  querySelector(selector) {
    return this.find(selector.replace(/^\./, ''));
  }
}

const fakeDocument = { activeElement: null, createElement: (tag) => new FakeNode(tag) };

const {
  markersQuery, pointsQuery, isMarkerRow, locationAt, drawsUngrouped, preloads, createPinDetails, bindPinDetails, createPinLayers, showResponse, trackPopupPans,
} = new Function(
  'document',
  'window',
  [
    cutVar(script, 'UNGROUPED_PIN_LIMIT'),
    ...['el', 'withParam', 'markersQuery', 'pointsQuery', 'isMarkerRow', 'locationAt', 'drawsUngrouped', 'preloads', 'createPinDetails', 'popupStatus', 'bindPinDetails', 'createPinLayers', 'showResponse', 'trackPopupPans']
      .map((name) => cutFunction(script, name)),
    'return { markersQuery, pointsQuery, isMarkerRow, locationAt, drawsUngrouped, preloads, createPinDetails, bindPinDetails, createPinLayers, showResponse, trackPopupPans };',
  ].join('\n'),
)(fakeDocument, { setTimeout });

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

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

test('should ask for map points with the catalogue filters and no page size when the map loads', () => {
  const query = pointsQuery(markersQuery({ q: 'physio', category: 'c1', limit: 12, per_page: 12 }));

  assert.deepEqual(query, { q: 'physio', category: 'c1', projection: 'points' });
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

// A fetchMarkers stand-in whose first answer is held until release() is
// called, so a test can open pins while a preload is in flight.
function heldGateway(first, ...rest) {
  let release;
  const held = new Promise((resolve, reject) => {
    release = () => (first instanceof Error ? reject(first) : resolve(first));
  });
  const queries = [];
  const fetchMarkers = (query) => {
    queries.push(query);
    if (queries.length === 1) return held;
    const answer = rest.length ? rest.shift() : { data: [] };
    return answer instanceof Error ? Promise.reject(answer) : Promise.resolve(answer);
  };
  return { queries, fetchMarkers, release };
}

test('should preload when the points total equals the threshold', () => {
  assert.equal(preloads(500, 500), true);
  assert.equal(preloads(1, 250), true);
});

test('should not preload when the points total is one over the threshold', () => {
  assert.equal(preloads(501, 500), false);
});

test('should not preload when nothing matches', () => {
  assert.equal(preloads(0, 500), false);
  assert.equal(preloads(undefined, 500), false);
});

test('should preload in the default projection with the points filters and the threshold as the page size', () => {
  const { queries, fetchMarkers } = gateway({ data: [] });
  const details = createPinDetails(fetchMarkers);
  details.reset({ q: 'physio', bbox: '1,0,1,0' });

  details.preload(500);

  assert.deepEqual(queries, [{ q: 'physio', bbox: '1,0,1,0', limit: 500 }]);
});

test('should show a preloaded listing with no further request when its pin is opened', async () => {
  const { queries, fetchMarkers, release } = heldGateway({ data: [marker(LISTING), marker(OTHER)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({});
  details.preload(500);
  release();

  const found = await details.get(OTHER);

  assert.equal(found.id, OTHER);
  assert.equal(details.peek(LISTING).marker.id, LISTING);
  assert.equal(queries.length, 1);
});

test('should wait for the preload and show its details when a pin is opened while it is in flight', async () => {
  const { queries, fetchMarkers, release } = heldGateway({ data: [marker(LISTING)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({});
  details.preload(500);

  const opened = details.get(LISTING);
  assert.equal(details.peek(LISTING), null, 'the pin shows its loading state meanwhile');
  release();

  assert.equal((await opened).id, LISTING);
  assert.equal(queries.length, 1);
});

test('should load a listing on open when the preload does not hold it', async () => {
  const { queries, fetchMarkers, release } = heldGateway({ data: [marker(OTHER)] }, { data: [marker(LISTING)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({ q: 'physio' });
  details.preload(500);

  const opened = details.get(LISTING);
  release();

  assert.equal((await opened).id, LISTING);
  assert.deepEqual(queries[1], { q: 'physio', ids: LISTING });
});

test('should load each pin on open with no error when the preload fails', async () => {
  const { queries, fetchMarkers, release } = heldGateway(new Error('offline'), { data: [marker(LISTING)] });
  const details = createPinDetails(fetchMarkers);
  details.reset({});
  details.preload(500);

  const opened = details.get(LISTING);
  release();

  assert.equal((await opened).id, LISTING);
  assert.deepEqual(queries[1], { ids: LISTING });
});

test('should hand a pin the map marker exactly as the gateway sent it, from the preload or on open', async () => {
  const fromPreload = marker(LISTING, { distance_km: 3.2, locations: [{ name: 'Office', latitude: -33.86, longitude: 151.2 }] });
  const fromIds = structuredClone(fromPreload);
  const preloaded = createPinDetails(gateway({ data: [marker(OTHER), fromPreload] }).fetchMarkers);
  const onOpen = createPinDetails(gateway({ data: [fromIds] }).fetchMarkers);
  preloaded.reset({});
  onOpen.reset({});
  preloaded.preload(500);

  assert.equal(await preloaded.get(LISTING), fromPreload);
  assert.equal(await onOpen.get(LISTING), fromIds);
});

test('should drop a pin waiting on a preload when a newer points response replaces it', async () => {
  const { queries, fetchMarkers, release } = heldGateway({ data: [] });
  const details = createPinDetails(fetchMarkers);
  details.reset({ q: 'old' });
  details.preload(500);
  const opened = details.get(LISTING);

  details.reset({ q: 'new' });
  release();

  assert.equal(await opened, null);
  assert.deepEqual(queries, [{ q: 'old', limit: 500 }]);
});

test('should keep a superseded preload out of the details for the next points response', async () => {
  const { fetchMarkers, release } = heldGateway({ data: [marker(LISTING, { distance_km: 4 })] }, { data: [marker(LISTING, { distance_km: 9 })] });
  const details = createPinDetails(fetchMarkers);
  details.reset({ q: 'old' });
  details.preload(500);

  details.reset({ q: 'new' });
  release();
  await new Promise((resolve) => setTimeout(resolve, 0));

  assert.equal(details.peek(LISTING), null);
  assert.equal((await details.get(LISTING)).distance_km, 9);
});

// Records what showResponse() does to the details store and the map, in order.
function responseSteps(rows, total, threshold) {
  const steps = [];
  const details = {
    reset: (filters) => steps.push(['reset', filters]),
    preload: (limit) => steps.push(['preload', limit]),
  };
  showResponse(rows, total, { q: 'physio' }, threshold, details, (drawn) => steps.push(['draw', drawn.length]));
  return steps;
}

const point = (id) => ({ id, name: 'Listing', locations: [{ latitude: -33.8, longitude: 151.2 }] });

test('should drop the last details, draw the pins and only then preload when the total is at the threshold', () => {
  assert.deepEqual(responseSteps([point(LISTING), point(OTHER)], 500, 500), [['reset', { q: 'physio' }], ['draw', 2], ['preload', 500]]);
});

test('should draw with no preload when the total is one over the threshold', () => {
  assert.deepEqual(responseSteps([point(LISTING)], 501, 500), [['reset', { q: 'physio' }], ['draw', 1]]);
});

test('should draw with no preload when an older gateway answers with map markers', () => {
  assert.deepEqual(responseSteps([marker(LISTING)], 1, 500), [['reset', { q: 'physio' }], ['draw', 1]]);
});

test('should draw with no preload when nothing matches', () => {
  assert.deepEqual(responseSteps([], 0, 500), [['reset', { q: 'physio' }], ['draw', 0]]);
});

// A Leaflet marker stand-in: the popup content function runs on open and on
// update(), as Leaflet runs it, and each render is kept.
function fakePin() {
  const element = new FakeNode('div');
  const contentNode = element.appendChild(new FakeNode('div'));
  contentNode.className = 'leaflet-popup-content';
  const pin = {
    open: false,
    renders: [],
    render() {
      const node = this.content();
      contentNode.children = [];
      contentNode.appendChild(node);
      this.renders.push(node);
    },
    bindPopup(content, options) {
      this.content = content;
      this.options = options;
    },
    isPopupOpen() {
      return this.open;
    },
    getPopup() {
      return { getElement: () => element, update: () => pin.render() };
    },
    openPopup() {
      this.open = true;
      this.render();
    },
    closePopup() {
      this.open = false;
    },
    last() {
      return this.renders[this.renders.length - 1];
    },
    status() {
      const node = this.last().find('agend-map-popup__status');
      return node ? node.text : null;
    },
    contentNode,
  };
  return pin;
}

const TEXT = { detailsLoading: 'Loading details…', detailsGone: 'These details are no longer available.', detailsFailed: 'Details could not be loaded.', retry: 'Try again' };

function shownDetails(found) {
  const node = new FakeNode('div');
  node.className = 'agend-map-popup';
  node.text = found.name;
  return node;
}

function boundPin(fetchMarkers, text = TEXT) {
  const details = createPinDetails(fetchMarkers);
  details.reset({ q: 'physio' });
  const pin = fakePin();
  pin.announced = [];
  bindPinDetails(pin, LISTING, details, shownDetails, text, { popup: { maxWidth: 260 }, announce: (message) => pin.announced.push(message) });
  return pin;
}

test('should show the loading state, then the details, when a pin is opened', async () => {
  const pin = boundPin(gateway({ data: [marker(LISTING)] }).fetchMarkers);

  pin.openPopup();
  assert.equal(pin.status(), 'Loading details…');
  await flush();

  assert.equal(pin.last().text, 'Listing 1');
  assert.deepEqual(pin.options, { maxWidth: 260 });
});

test('should tell a screen reader each state the opened pin shows', async () => {
  const pin = boundPin(gateway(new Error('offline'), { data: [marker(LISTING)] }).fetchMarkers);

  pin.openPopup();
  await flush();
  pin.last().find('agend-map__retry').click();
  await flush();

  assert.deepEqual(pin.announced, ['Loading details…', 'Details could not be loaded. ', 'Loading details…', 'Listing 1']);
});

test('should say the details are no longer available when the listing is absent', async () => {
  const pin = boundPin(gateway({ data: [] }).fetchMarkers);

  pin.openPopup();
  await flush();

  assert.equal(pin.status(), 'These details are no longer available.');
});

test('should offer Try again when the request fails and repeat it when pressed', async () => {
  const { queries, fetchMarkers } = gateway(new Error('offline'), { data: [marker(LISTING)] });
  const pin = boundPin(fetchMarkers);

  pin.openPopup();
  await flush();
  assert.equal(pin.status(), 'Details could not be loaded. ');
  const again = pin.last().find('agend-map__retry');
  assert.equal(again.text, 'Try again');

  const click = again.click();
  assert.equal(click.stopped, true, 'the click must not reach the map, which would close the popup');
  assert.equal(pin.status(), 'Loading details…');
  await flush();

  assert.equal(pin.last().text, 'Listing 1');
  assert.equal(queries.length, 2);
});

test('should keep focus in the popup when Try again redraws it', async () => {
  const pin = boundPin(gateway(new Error('offline'), { data: [marker(LISTING)] }).fetchMarkers);
  pin.openPopup();
  await flush();
  const again = pin.last().find('agend-map__retry');
  again.focus();

  again.click();

  assert.equal(fakeDocument.activeElement, pin.contentNode);
  assert.equal(pin.contentNode.attributes.tabindex, '-1');
});

test('should keep showing a failure, with one request, when the pin was reopened while it loaded', async () => {
  const { queries, fetchMarkers, release } = heldGateway(new Error('offline'), { data: [marker(LISTING)] });
  const pin = boundPin(fetchMarkers);

  pin.openPopup();
  pin.closePopup();
  pin.openPopup();
  assert.equal(pin.status(), 'Loading details…');
  release();
  await flush();
  await flush();

  assert.equal(pin.status(), 'Details could not be loaded. ');
  assert.equal(queries.length, 1);
});

test('should ask again when a pin whose request failed while it was closed is reopened', async () => {
  const { queries, fetchMarkers } = gateway(new Error('offline'), { data: [marker(LISTING)] });
  const pin = boundPin(fetchMarkers);
  pin.openPopup();
  pin.closePopup();
  await flush();

  pin.openPopup();
  assert.equal(pin.status(), 'Loading details…');
  await flush();

  assert.equal(pin.last().text, 'Listing 1');
  assert.equal(queries.length, 2);
});

test('should show its own wording when the page config predates the pin details states', async () => {
  const pin = boundPin(gateway(new Error('offline')).fetchMarkers, {});

  pin.openPopup();
  assert.equal(pin.status(), 'Loading details…');
  await flush();

  assert.equal(pin.status(), 'Details could not be loaded. ');
  assert.equal(pin.last().find('agend-map__retry').text, 'Try again');
});


// Leaflet stand-ins. The group adds pins the way the vendored markercluster
// does: one chunk at once and the rest on timers, reporting chunkProgress
// after each chunk and before its final step, which (like each pin it adds)
// reads the map and throws once the group is off it. Errors thrown from a
// timer are kept for the test to assert on.
const CHUNK = 500;

function fakeLeaflet({ canGroup = true } = {}) {
  const errors = [];
  const map = {
    layers: new Set(),
    addLayer(layer) {
      this.layers.add(layer);
      layer._map = this;
      if (layer.kind === 'group') layer.addLayers([]);
    },
    removeLayer(layer) {
      this.layers.delete(layer);
      layer._map = null;
    },
  };
  const L = { layerGroup: (pins) => ({ kind: 'plain', pins }) };
  if (canGroup) {
    L.markerClusterGroup = (options) => ({
      kind: 'group',
      options,
      pins: [],
      _map: null,
      _addLayer(pin) {
        this._map.getMinZoom;
        this.pins.push(pin);
      },
      addLayers(list) {
        let done = 0;
        const chunk = () => {
          const end = Math.min(list.length, done + CHUNK);
          for (; done < end; done += 1) this._addLayer(list[done]);
          if (this.options.chunkProgress) this.options.chunkProgress(done, list.length);
          if (done === list.length) {
            this._map.getMinZoom;
          } else {
            setTimeout(() => {
              try {
                chunk();
              } catch (error) {
                errors.push(error);
              }
            }, 0);
          }
        };
        chunk();
      },
      clearLayers() {
        this.pins = [];
      },
    });
  }
  return { L, map, errors };
}

const pins = (count) => Array.from({ length: count }, (_, i) => ({ pin: i }));

// Lets every queued chunk run.
async function settle() {
  for (let i = 0; i < 50; i += 1) await flush();
}

test('should draw at most 2,000 pins on a plain layer when grouping is off', () => {
  const { L, map } = fakeLeaflet();
  createPinLayers(L, map, { cluster: false }, {})(pins(2000));

  const [layer] = map.layers;
  assert.equal(layer.kind, 'plain');
  assert.equal(layer.pins.length, 2000);
});

test('should group more than 2,000 pins when grouping is off', async () => {
  const { L, map } = fakeLeaflet();
  createPinLayers(L, map, { cluster: false }, { maxClusterRadius: 80 })(pins(2001));
  await settle();

  const [layer] = map.layers;
  assert.equal(layer.kind, 'group');
  assert.equal(layer.pins.length, 2001);
  assert.equal(layer.options.maxClusterRadius, 80);
});

test('should group any number of pins when grouping is on', () => {
  const { L, map } = fakeLeaflet();
  createPinLayers(L, map, { cluster: true }, {})(pins(3));

  assert.equal([...map.layers][0].kind, 'group');
});

test('should draw a plain layer when the grouping library is missing', () => {
  const { L, map } = fakeLeaflet({ canGroup: false });
  createPinLayers(L, map, { cluster: true }, {})(pins(5000));

  assert.equal([...map.layers][0].kind, 'plain');
});

test('should replace the last response pins with the next response pins once their add has finished', async () => {
  const { L, map, errors } = fakeLeaflet();
  const show = createPinLayers(L, map, { cluster: true }, {});
  show(pins(10));
  const first = [...map.layers][0];

  show(pins(4));
  await settle();

  assert.equal(map.layers.has(first), false);
  assert.deepEqual([...map.layers].map((layer) => layer.pins.length), [4]);
  assert.deepEqual(errors, []);
});

test('should end a replaced add quietly, with none of its pins left, when the next response arrives mid-add', async () => {
  const { L, map, errors } = fakeLeaflet();
  const show = createPinLayers(L, map, { cluster: true }, {});
  show(pins(20000));
  const stale = [...map.layers][0];
  assert.ok(stale.pins.length < 20000, 'the add is still in progress');

  show(pins(4));
  await settle();

  assert.deepEqual(errors, []);
  assert.equal(stale.pins.length, 0);
  assert.equal(map.layers.has(stale), false);
  assert.deepEqual([...map.layers].map((layer) => layer.pins.length), [4]);
});

test('should leave only the newest pins when two responses are replaced mid-add in a row', async () => {
  const { L, map, errors } = fakeLeaflet();
  const show = createPinLayers(L, map, { cluster: true }, {});
  show(pins(20000));
  show(pins(15000));
  show(pins(3000));
  await settle();

  assert.deepEqual(errors, []);
  assert.deepEqual([...map.layers].map((layer) => layer.pins.length), [3000]);
});

test('should end a replaced add quietly when the next response draws a plain layer', async () => {
  const { L, map, errors } = fakeLeaflet();
  const show = createPinLayers(L, map, { cluster: false }, {});
  show(pins(20000));

  show(pins(12));
  await settle();

  assert.deepEqual(errors, []);
  assert.deepEqual([...map.layers].map((layer) => layer.kind), ['plain']);
});

// A Leaflet map stand-in that only emits events.
function fakeMap() {
  const handlers = {};
  return {
    on(type, fn) {
      (handlers[type] = handlers[type] || []).push(fn);
    },
    fire(type) {
      (handlers[type] || []).forEach((fn) => fn());
    },
  };
}

// Plays a move: movestart (unless left out), then moveend, reporting
// whether the moveend was a popup's.
function move(map, popupMoved, { start = true } = {}) {
  if (start) map.fire('movestart');
  return popupMoved();
}

test('should treat a visitor move as the visitor own', () => {
  const map = fakeMap();
  const popupMoved = trackPopupPans(map);

  assert.equal(move(map, popupMoved), false);
});

test('should treat a popup pan as the map own move, then the next visitor move as the visitor own', () => {
  const map = fakeMap();
  const popupMoved = trackPopupPans(map);

  map.fire('autopanstart');
  assert.equal(move(map, popupMoved), true);
  assert.equal(move(map, popupMoved), false);
});

test('should treat both moves as the map own when a second popup pan cuts the first short', () => {
  const map = fakeMap();
  const popupMoved = trackPopupPans(map);

  map.fire('autopanstart');
  map.fire('movestart');
  map.fire('autopanstart');
  map.fire('movestart');

  assert.equal(popupMoved(), true, 'the first pan, stopped');
  assert.equal(popupMoved(), true, 'the second pan');
  assert.equal(move(map, popupMoved), false);
});

test('should not swallow the next visitor move when a popup pan rounds to nothing', () => {
  const map = fakeMap();
  const popupMoved = trackPopupPans(map);

  map.fire('autopanstart');
  assert.equal(move(map, popupMoved, { start: false }), true);
  assert.equal(move(map, popupMoved), false);
});
