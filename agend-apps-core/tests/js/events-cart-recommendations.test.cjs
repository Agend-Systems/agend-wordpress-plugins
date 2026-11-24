const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(require.resolve('../../assets/js/events-catalogue.js'), 'utf8');
const sessionSource = readFileSync(require.resolve('../../../agend-apps-shop/assets/js/agend-apps-shop-cart-session.js'), 'utf8');
const eventId = 'c3fa4551-fa95-411d-8ad4-c3898f6c629a';
const workshop = {
  type: 'event',
  eventId: '6a7f02ad-43ff-43fa-afe9-2abc46e15b4e',
  slug: 'workshop',
  name: 'Afternoon workshop',
};
const networking = {
  type: 'event',
  eventId: '48d29a05-e001-4a8c-9cec-afb74affbaf3',
  slug: 'networking',
  name: '<b>Networking</b>',
};
const tick = () => new Promise((resolve) => setImmediate(resolve));

function response(data, status = 200) {
  return { status, ok: status === 200, json: async () => ({ data }) };
}

function offers(recommendations) {
  return response({ source: { type: 'event', id: eventId }, recommendations });
}

async function setup(t, options = {}) {
  const dom = new JSDOM('<div class="agend-events-catalogue"><div class="agend-ev-detail"><button data-agend-event-slug="conference">Register Now</button></div></div>', {
    url: 'https://example.test/events/event/conference/',
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
  if (options.interceptTimeout) {
    const setTimeout = window.setTimeout.bind(window);
    window.setTimeout = options.interceptTimeout(setTimeout);
  }
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
    assert.equal(path, '/cart/recommendations');
    return options.recommendations ? options.recommendations(args.signal) : offers([workshop, networking]);
  };

  await new Promise((resolve) => document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  document.querySelector('[data-agend-event-slug]').click();
  await tick();
  const selectTicket = (index) => document.querySelectorAll('.agend-ev-reg__ticket')[index].querySelectorAll('.agend-ev-reg__step')[1].click();
  selectTicket(0);
  return {
    window,
    document,
    calls,
    selectTicket,
    submit: document.querySelector('.agend-ev-reg__submit'),
    getOffers: () => Array.from(document.querySelectorAll('.agend-ev-reg__recommendations a'), (node) => [node.textContent, node.getAttribute('href')]),
  };
}

test('after both ticket lines succeed, fetches ordered offers once using the resulting guest cart', async (t) => {
  const { document, calls, getOffers, selectTicket, submit } = await setup(t);
  selectTicket(1);
  submit.click();
  submit.click();
  await tick();

  assert.equal(document.querySelector('.agend-ev-reg__confirm-title')?.textContent, 'Added to Cart');
  assert.equal(new URL(document.querySelector('.agend-ev-reg__confirm-actions a').href).pathname, '/cart/');
  assert.match(document.querySelector('.agend-ev-reg__confirm-actions button').textContent, /Keep browsing/);
  const cartCalls = calls.filter(({ path }) => path.startsWith('/cart/'));
  assert.deepEqual(cartCalls.map(({ path }) => path), ['/cart/items', '/cart/items', '/cart/recommendations']);
  assert.equal(cartCalls[0].headers['X-Cart-Session'], undefined);
  assert.equal(cartCalls[1].headers['X-Cart-Session'], 'session-returned-by-add');
  assert.equal(cartCalls[2].headers['X-Cart-Session'], 'session-returned-by-add');
  assert.equal(cartCalls[2].headers['X-WP-Nonce'], 'wp-nonce');
  assert.equal(cartCalls[2].requestUrl.searchParams.get('sourceEventId'), eventId);
  assert.deepEqual(getOffers(), [
    [workshop.name, 'https://example.test/events/event/workshop/'],
    [networking.name, 'https://example.test/events/event/networking/'],
  ]);
  assert.equal(document.querySelector('.agend-ev-reg__recommendations b'), null);
});

test('uses same-origin plain permalinks and omits a path-like target for a signed-in cart', async (t) => {
  const { calls, getOffers, submit } = await setup(t, {
    member: true,
    restUrl: 'https://example.test/?rest_route=/agend-apps/v1/',
    config: { prettyLinks: false, basePath: '/?page_id=17', detailBase: '/?page_id=42' },
    recommendations: () => offers([{ ...workshop, slug: '//external.test/' }, networking]),
  });
  submit.click();
  await tick();

  const request = calls.find(({ path }) => path === '/cart/recommendations');
  assert.equal(request.headers['X-Cart-Session'], undefined);
  assert.equal(request.headers['X-WP-Nonce'], 'wp-nonce');
  assert.equal(request.requestUrl.searchParams.get('rest_route'), '/agend-apps/v1/cart/recommendations');
  assert.equal(request.requestUrl.searchParams.get('sourceEventId'), eventId);
  assert.deepEqual(getOffers(), [[networking.name, 'https://example.test/?page_id=42&agend_event=networking']]);
});

test('while lookup is pending and when it fails, confirmation and cart remain usable', async (t) => {
  let finish;
  const { document, getOffers, submit } = await setup(t, {
    recommendations: () => new Promise((resolve) => { finish = resolve; }),
  });
  submit.click();
  await tick();

  assert.equal(document.querySelector('.agend-ev-reg__confirm-title')?.textContent, 'Added to Cart');
  const cartLink = document.querySelector('.agend-ev-reg__confirm-actions a');
  assert.equal(new URL(cartLink.href).pathname, '/cart/');
  assert.equal(document.querySelector('.agend-ev-reg__recommendations'), null);
  finish(response(undefined, 502));
  await tick();
  assert.equal(document.querySelector('.agend-ev-reg__confirm-actions a'), cartLink);
  assert.deepEqual(getOffers(), []);
});

test('discards the recommendation response if another cart update supersedes it', async (t) => {
  let finish;
  let signal;
  const { document, getOffers, submit } = await setup(t, {
    recommendations: (requestSignal) => {
      signal = requestSignal;
      return new Promise((resolve) => { finish = resolve; });
    },
  });
  submit.click();
  await tick();
  document.dispatchEvent(new document.defaultView.CustomEvent('agend:cart:updated'));
  assert.equal(signal?.aborted, true);
  finish(offers([workshop]));
  await tick();
  assert.deepEqual(getOffers(), []);
});

test('clears an already rendered offer when another widget changes the cart', async (t) => {
  const { document, getOffers, submit } = await setup(t);
  submit.click();
  await tick();
  assert.equal(getOffers().length, 2);

  document.dispatchEvent(new document.defaultView.CustomEvent('agend:cart:updated'));
  assert.deepEqual(getOffers(), []);
  assert.equal(document.querySelector('.agend-ev-reg__recommendations'), null);
  assert.equal(document.querySelector('.agend-ev-reg__confirm-actions a').textContent, 'View Cart');
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

test('a slow lookup is aborted without interrupting the confirmation', async (t) => {
  let timeout;
  let signal;
  const { document, getOffers, submit } = await setup(t, {
    recommendations: (requestSignal) => {
      signal = requestSignal;
      return new Promise(() => {});
    },
    interceptTimeout: (setTimeout) => (callback, delay) => {
      if (delay === 2500) timeout = callback;
      else return setTimeout(callback, delay);
    },
  });
  submit.click();
  await tick();
  assert.equal(signal?.aborted, false);
  timeout();

  assert.equal(signal.aborted, true);
  assert.equal(document.querySelector('.agend-ev-reg__confirm-title').textContent, 'Added to Cart');
  assert.equal(document.querySelector('.agend-ev-reg__confirm-actions a').textContent, 'View Cart');
  assert.deepEqual(getOffers(), []);
});
