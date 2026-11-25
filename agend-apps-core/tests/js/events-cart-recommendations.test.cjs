const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(require.resolve('../../assets/js/events-catalogue.js'), 'utf8');
const sessionSource = readFileSync(require.resolve('../../../agend-apps-shop/assets/js/agend-apps-shop-cart-session.js'), 'utf8');
const eventId = 'c3fa4551-fa95-411d-8ad4-c3898f6c629a';
const tick = () => new Promise((resolve) => setImmediate(resolve));

function response(data, status = 200) {
  return { status, ok: status === 200, json: async () => ({ data }) };
}

async function setup(t, options = {}) {
  const dom = new JSDOM('<div class="agend-events-catalogue"><div class="agend-ev-detail"><button data-agend-event-slug="conference">Register Now</button></div></div>', {
    url: 'https://example.test/events/event/conference/' + (options.hash || ''),
    runScripts: 'outside-only',
  });
  const { window } = dom;
  const { document } = window;
  t.after(() => window.close());
  const root = document.querySelector('.agend-events-catalogue');
  root.setAttribute('data-agend-events-config', JSON.stringify({
    ssrDetail: true,
    cartEnabled: true,
    cartRecommendations: true,
    cartPageUrl: '/cart/',
    prettyLinks: true,
    basePath: '/events/',
    ...options.config,
  }));
  window.agendApps = { nonce: 'wp-nonce', restUrl: options.restUrl };
  window.eval(sessionSource);
  const calls = [];
  const tickets = [
    { ticket: { id: 'general', name: 'General', price: 20 }, capacityRemaining: 20 },
    { ticket: { id: 'vip', name: 'VIP', price: 50 }, capacityRemaining: 20 },
  ];
  window.fetch = async (url, args) => {
    const requestUrl = new URL(url, window.location.href);
    const path = (requestUrl.searchParams.get('rest_route') || requestUrl.pathname).replace(/^\/(?:wp-json\/)?agend-apps\/v1/, '');
    calls.push({ path, requestUrl, ...args });
    if (path === '/events/conference') return response({ id: eventId, slug: 'conference', name: 'Conference' });
    if (path === '/events/conference/tickets') return response(tickets);
    if (path === '/cart/items') {
      if (options.add) return options.add();
      return response(options.member ? {} : { guestSessionToken: 'session-returned-by-add' });
    }
    throw new Error('Unexpected request ' + path);
  };

  await new Promise((resolve) => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  if (!options.hash) document.querySelector('[data-agend-event-slug]').click();
  await tick();
  const selectTicket = (index) => document.querySelectorAll('.agend-ev-reg__ticket')[index].querySelectorAll('.agend-ev-reg__step')[1].click();
  selectTicket(0);
  return {
    window,
    document,
    calls,
    selectTicket,
    submit: document.querySelector('.agend-ev-reg__submit'),
  };
}

test('all selected tickets are added to one cart, then the delegate goes straight there', async (t) => {
  const { window, document, calls, selectTicket, submit } = await setup(t, {
    config: { basePath: '', cartPageUrl: '#cart' },
  });
  selectTicket(1);
  submit.click();
  submit.click();
  await tick();

  const writes = calls.filter(({ path }) => path.startsWith('/cart/'));
  assert.deepEqual(writes.map(({ path }) => path), ['/cart/items', '/cart/items']);
  assert.equal(writes[0].headers['X-Cart-Session'], undefined);
  assert.equal(writes[1].headers['X-Cart-Session'], 'session-returned-by-add');
  assert.equal(writes[1].headers['X-WP-Nonce'], 'wp-nonce');
  assert.equal(window.location.hash, '#cart');
  assert.equal(document.querySelector('.agend-ev-reg__confirm-title'), null);
});

test('sites without a cart destination keep the existing confirmation', async (t) => {
  const { document, calls, submit } = await setup(t, { config: { cartPageUrl: '' } });
  submit.click();
  await tick();
  assert.equal(document.querySelector('.agend-ev-reg__confirm-title').textContent, 'Added to Cart');
  assert.equal(calls.some(({ path }) => path === '/cart/recommendations'), false);
});

test('Choose tickets opens the standard event selector without purchasing anything', async (t) => {
  const { document, window, calls } = await setup(t, { hash: '#agend-tickets' });
  assert.equal(window.location.hash, '');
  assert.equal(document.querySelectorAll('.agend-ev-reg__ticket').length, 2);
  assert.equal(calls.some(({ path }) => path.startsWith('/cart/')), false);
});

test('no recommendations are requested after a failed add', async (t) => {
  const { document, calls, submit } = await setup(t, { add: () => response(undefined, 409) });
  submit.click();
  await tick();
  assert.match(document.querySelector('.agend-ev-reg__error').textContent, /Unable to add to cart/);
  assert.equal(document.querySelector('.agend-ev-reg__confirm-title'), null);
  assert.equal(calls.some(({ path }) => path === '/cart/recommendations'), false);
});

test('no opt-in keeps existing cart navigation and does not request offers', async (t) => {
  const { calls, document, submit, window } = await setup(t, {
    config: { cartRecommendations: undefined, basePath: '', cartPageUrl: '#cart' },
  });
  submit.click();
  await tick();

  assert.equal(window.location.hash, '#cart');
  assert.equal(document.querySelector('.agend-ev-reg__confirm-title'), null);
  assert.equal(calls.some(({ path }) => path === '/cart/recommendations'), false);
});
