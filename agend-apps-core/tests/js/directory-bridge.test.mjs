/**
 * The helpers the directory widgets beside a catalogue share
 * (assets/js/directory-bridge.js): finding the catalogue they can drive,
 * reading a place search answer, and the search link a visitor is sent to when
 * the page has no catalogue.
 *
 * Run with: node --test agend-apps-core/tests/js/directory-bridge.test.mjs
 *
 * The scripts are browser scripts that attach themselves to window, so they are
 * run against a stand-in window and read back from there.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const linkSource = readFileSync(new URL('../../assets/js/search-link.js', import.meta.url), 'utf8');
const bridgeSource = readFileSync(new URL('../../assets/js/directory-bridge.js', import.meta.url), 'utf8');

function load(extra = {}) {
  const listeners = {};
  const fakeWindow = {
    location: { origin: 'https://example.test', pathname: '/here/' },
    document: {
      addEventListener(name, handler) {
        listeners[name] = handler;
      },
    },
    agendApps: { restUrl: 'https://example.test/wp-json/agend-apps/v1/', nonce: 'n' },
    ...extra,
  };
  new Function('window', linkSource)(fakeWindow);
  new Function('window', bridgeSource)(fakeWindow);
  return { bridge: fakeWindow.agendDirectoryBridge, fakeWindow, listeners };
}

test('should build a search link on the directory page when a keyword is searched off the page', () => {
  const { bridge } = load();

  assert.equal(
    bridge.destination('https://example.test/find/', { search: 'Jane Smith' }),
    'https://example.test/find/?q=Jane%20Smith',
  );
});

test('should keep the directory page address own query when the search link is added', () => {
  const { bridge } = load();

  assert.equal(
    bridge.destination('https://example.test/?page_id=5', { search: 'x', location_state: ['NSW'] }),
    'https://example.test/?page_id=5&q=x&state=NSW',
  );
});

test('should carry a place with its radius and address when the visitor typed it', () => {
  const { bridge } = load();
  const near = { lat: -33.8688, lng: 151.2093, radius: 25, label: 'Sydney', source: 'place' };

  assert.equal(
    bridge.destination('https://example.test/find/', { search: '', near }),
    'https://example.test/find/?near=-33.869,151.209&radius=25&address=Sydney',
  );
});

test('should set one custom field value from a tile on the directory page address', () => {
  const { bridge } = load();

  assert.equal(
    bridge.destination('https://example.test/find/', { custom_fields: { profession: ['Specialist Advisor'] } }),
    'https://example.test/find/?cf_profession=Specialist%20Advisor',
  );
});

test('should stay on this page when no directory page is given', () => {
  const { bridge } = load();

  assert.equal(bridge.destination('', { search: 'x' }), 'https://example.test/here/?q=x');
});

test('should cut a device position to two decimals and mark it as a place so it is carried', () => {
  const { bridge } = load();
  const near = bridge.deviceNear({ lat: -33.868812, lng: 151.209296 }, 15, 'My location');

  assert.deepEqual(near, { lat: -33.87, lng: 151.21, radius: 15, label: 'My location', source: 'place' });
  assert.equal(
    bridge.destination('https://example.test/find/', { near }),
    'https://example.test/find/?near=-33.87,151.21&radius=15&address=My%20location',
  );
});

test('should offer only a catalogue whose script can be driven', () => {
  const none = load();
  assert.equal(none.bridge.catalogue(), null);

  const old = load({ agendCatalogues: { listing: { state: {} } } });
  assert.equal(old.bridge.catalogue(), null);

  const apply = () => {};
  const current = load({ agendCatalogues: { listing: { state: {}, apply } } });
  assert.equal(current.bridge.catalogue().apply, apply);
});

test('should call back when a catalogue announces itself after the widget loaded', () => {
  const { bridge, fakeWindow, listeners } = load();
  const seen = [];
  bridge.whenCatalogue((catalogue) => seen.push(catalogue));

  assert.equal(seen.length, 0);
  fakeWindow.agendCatalogues = { listing: { state: {}, apply() {} } };
  listeners['agend:directory-ready']();
  listeners['agend:directory-ready']();

  assert.equal(seen.length, 1);
});

test('should call back at once when the catalogue is already there', () => {
  const { bridge } = load({ agendCatalogues: { listing: { state: {}, apply() {} } } });
  const seen = [];
  bridge.whenCatalogue((catalogue) => seen.push(catalogue));

  assert.equal(seen.length, 1);
});

function geocodeWith(body) {
  return load({ fetch: async () => ({ json: async () => body }) });
}

test('should return the point when the place search finds the place', async () => {
  const { bridge } = geocodeWith({ data: { latitude: '-37.81', longitude: '144.96' } });

  assert.deepEqual(await bridge.geocode('Melbourne', 'Australia'), { lat: -37.81, lng: 144.96 });
});

test('should name why a place was not found', async () => {
  const cases = [
    [{ data: { status_code: 404 } }, 'notFound'],
    [{ data: { status_code: 403 } }, 'disabled'],
    [{ data: { status_code: 429 } }, 'busy'],
    [{ data: { status_code: 503 } }, 'busy'],
    [{ data: { status_code: 500 } }, 'failed'],
    [{}, 'failed'],
  ];
  for (const [body, error] of cases) {
    const { bridge } = geocodeWith(body);
    assert.deepEqual(await bridge.geocode('Nowhere', ''), { error });
  }
});

test('should not look up an empty place', async () => {
  const { bridge } = geocodeWith({});

  assert.deepEqual(await bridge.geocode('   ', ''), { error: 'empty' });
});

test('should add the region to the place and send it to the geocoder', async () => {
  let url = '';
  const { bridge } = load({
    fetch: async (requested) => {
      url = requested;
      return { json: async () => ({ data: { latitude: 1, longitude: 2 } }) };
    },
  });

  await bridge.geocode('Richmond', 'Australia');

  assert.equal(url, 'https://example.test/wp-json/agend-apps/v1/directory/geocode?q=Richmond%2C%20Australia');
});

test('should ask a second lookup in quick succession to wait', async () => {
  const { bridge } = geocodeWith({ data: { latitude: 1, longitude: 2 } });

  await bridge.geocode('A', '');

  assert.deepEqual(await bridge.geocode('B', ''), { error: 'throttled' });
});

test('should say the browser cannot locate when it has no geolocation', async () => {
  const { bridge } = load({ navigator: {} });

  assert.deepEqual(await bridge.locate(), { error: 'unsupported' });
});

test('should return the device position and map a refusal to denied', async () => {
  const granted = load({ navigator: { geolocation: { getCurrentPosition: (ok) => ok({ coords: { latitude: 1.5, longitude: 2.5 } }) } } });
  assert.deepEqual(await granted.bridge.locate(), { lat: 1.5, lng: 2.5 });

  const refused = load({ navigator: { geolocation: { getCurrentPosition: (ok, fail) => fail({ code: 1 }) } } });
  assert.deepEqual(await refused.bridge.locate(), { error: 'denied' });
});

test('should try once more with a precise fix when the first position is unavailable', async () => {
  const attempts = [];
  const { bridge } = load({
    navigator: {
      geolocation: {
        getCurrentPosition(ok, fail, options) {
          attempts.push(options.enableHighAccuracy);
          if (attempts.length === 1) {
            fail({ code: 2 });
          } else {
            ok({ coords: { latitude: 3, longitude: 4 } });
          }
        },
      },
    },
  });

  assert.deepEqual(await bridge.locate(), { lat: 3, lng: 4 });
  assert.deepEqual(attempts, [false, true]);
});
