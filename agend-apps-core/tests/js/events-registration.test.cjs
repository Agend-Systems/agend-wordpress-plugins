/**
 * Events catalogue: event cards as real links, prices with a thousands
 * separator, the member pricing note for a signed-in visitor who is not a
 * member (never "Sign in"), and plain-English cart errors.
 * Run with: node --test agend-apps-core/tests/js/events-registration.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(require.resolve('../../assets/js/events-catalogue.js'), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

const event = {
  id: 'e1',
  slug: 'conference',
  name: 'Annual Conference',
  start_date: '2027-02-28T14:00:00+10:30',
  end_date: '2027-02-28T16:00:00+10:30',
  timezone: 'Australia/Adelaide',
  venue_type: 'physical',
  venue_name: 'Town Hall',
  price_summary: { member_from: 1325, non_member_from: 1755 },
};

function config(extra = {}) {
  return Object.assign({
    heading: { show: false },
    layout: { desktop: 3, tablet: 2, mobile: 1, cardRadius: 10 },
    card: { image: false, dateBadge: true, pills: true, description: false, excerptLength: 110, pricing: true },
    filters: { search: false, category: false, type: false, city: false, date: false },
    exclusions: { categories: [], venueTypes: [], cities: [] },
    timeframe: 'upcoming',
    timezone: 'Australia/Adelaide',
    pagination: { style: 'none', perPage: 9 },
    colours: {},
    theme: {},
    prettyLinks: true,
    basePath: 'https://example.test/events/',
    onDetailPage: true,
    detailBase: '',
    checkoutSignInUrl: 'https://example.test/login/',
  }, extra);
}

async function build(t, { agendApps = {}, cfg = {}, url = 'https://example.test/events/', eventBody = event, cartReply } = {}) {
  const json = JSON.stringify(config(cfg)).replace(/'/g, '&#39;');
  const dom = new JSDOM(`<!doctype html><div class="agend-events-catalogue" data-agend-events-config='${json}'></div>`, {
    url,
    runScripts: 'outside-only',
  });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = Object.assign({ restUrl: 'https://example.test/wp-json/agend-apps/v1/' }, agendApps);
  const calls = [];
  window.fetch = (requestUrl, options = {}) => {
    const path = new URL(requestUrl).pathname.replace('/wp-json/agend-apps/v1', '');
    calls.push({ path, options });
    if (path === '/cart/items' && cartReply) {
      return Promise.resolve({ ok: false, status: cartReply.status, json: () => Promise.resolve(cartReply.body) });
    }
    let body = [];
    if (path === '/events') {
      body = { data: [eventBody], meta: { pagination: { page: 1, total_pages: 1 } } };
    } else if (path === '/events/conference') {
      body = { data: eventBody };
    } else if (path === '/events/conference/tickets') {
      body = { data: [{ ticket: { id: 't1', name: 'General' }, pricingTiers: [{ tier: { member_price: 1325, non_member_price: 1755 } }] }] };
    }
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
  };
  await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  await tick();
  await tick();
  return { window, document: window.document, calls };
}

async function openRegistration(view) {
  await tick();
  view.document.querySelector('.agend-ev-detail__cta').click();
  await tick();
  await tick();
}

test('an event card is a link to the event, with no button role or other control inside it', async (t) => {
  const { document } = await build(t);
  const card = document.querySelector('.agend-ev-card');
  assert.equal(card.getAttribute('role'), null);
  assert.equal(card.getAttribute('tabindex'), null);
  const links = card.querySelectorAll('a, button, input, select');
  assert.equal(links.length, 1);
  const link = card.querySelector('h3.agend-ev-card__title > a.agend-ev-card__link');
  assert.equal(link.textContent, 'Annual Conference');
  assert.equal(link.getAttribute('href'), 'https://example.test/events/event/conference/');
});

test('a plain click on the card link opens the detail in place; a modified click is left to the browser', async (t) => {
  const { window, document, calls } = await build(t);
  const link = document.querySelector('.agend-ev-card__link');
  const newTab = new window.MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true });
  link.dispatchEvent(newTab);
  assert.equal(newTab.defaultPrevented, false);
  const plain = new window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
  link.dispatchEvent(plain);
  assert.equal(plain.defaultPrevented, true);
  await tick();
  assert.ok(calls.some((call) => call.path === '/events/conference'));
});

test('card prices have a thousands separator', async (t) => {
  const { document } = await build(t);
  const values = Array.from(document.querySelectorAll('.agend-ev-card .agend-ev-price__value')).map((n) => n.textContent);
  assert.deepEqual(values, ['$1,325.00', '$1,755.00']);
});

test('a signed-out visitor is offered the sign-in link for member pricing', async (t) => {
  const view = await build(t, { url: 'https://example.test/events/event/conference/', cfg: { deepLink: 'conference' } });
  await openRegistration(view);
  assert.ok(view.document.querySelector('.agend-ev-reg__signin'));
  assert.equal(view.document.querySelector('.agend-ev-reg__join'), null);
  assert.equal(view.document.querySelector('.agend-ev-reg__ticket-price').textContent, '$1,755.00');
});

test('a signed-in visitor with no membership is never asked to sign in', async (t) => {
  const view = await build(t, {
    url: 'https://example.test/events/event/conference/',
    cfg: { deepLink: 'conference' },
    agendApps: { signedIn: true },
  });
  await openRegistration(view);
  assert.equal(view.document.querySelector('.agend-ev-reg__signin'), null);
  assert.doesNotMatch(view.document.querySelector('.agend-ev-reg').textContent, /sign in/i);
  const note = view.document.querySelector('.agend-ev-reg__join');
  assert.equal(note.textContent, 'Member pricing applies to current Members.');
  assert.equal(note.querySelector('a'), null, 'no join link unless the site sets a join page');
});

test('the member pricing note links to the site join page when one is set', async (t) => {
  const view = await build(t, {
    url: 'https://example.test/events/event/conference/',
    cfg: { deepLink: 'conference' },
    agendApps: { signedIn: true, memberJoinUrl: 'https://example.test/membership/join/' },
  });
  await openRegistration(view);
  const link = view.document.querySelector('.agend-ev-reg__join a');
  assert.equal(link.textContent, 'Become a member');
  assert.equal(link.href, 'https://example.test/membership/join/');
});

test('an unsafe join page address is not linked', async (t) => {
  const view = await build(t, {
    url: 'https://example.test/events/event/conference/',
    cfg: { deepLink: 'conference' },
    agendApps: { signedIn: true, memberJoinUrl: 'javascript:alert(1)' },
  });
  await openRegistration(view);
  assert.equal(view.document.querySelector('.agend-ev-reg__join a'), null);
});

test('a signed-in member on member pricing sees neither the sign-in link nor the join note', async (t) => {
  const view = await build(t, {
    url: 'https://example.test/events/event/conference/',
    cfg: { deepLink: 'conference' },
    agendApps: { signedIn: true },
    eventBody: Object.assign({}, event, { viewer_price_group: 'member' }),
  });
  await openRegistration(view);
  assert.equal(view.document.querySelector('.agend-ev-reg__signin'), null);
  assert.equal(view.document.querySelector('.agend-ev-reg__join'), null);
  assert.equal(view.document.querySelector('.agend-ev-reg__ticket-price').textContent, '$1,325.00');
});

test('a cart held by a checkout shows plain English and a link to the cart, never the platform message', async (t) => {
  const view = await build(t, {
    url: 'https://example.test/events/event/conference/',
    cfg: { deepLink: 'conference', cartEnabled: true, cartPageUrl: 'https://example.test/cart/' },
    agendApps: { signedIn: true },
    cartReply: {
      status: 409,
      body: {
        code: 'agend_api_error',
        message: 'Cart 49f97982-89b0-4629-a70d-d0c3120de530 is not mutable (status: locked)',
        data: { status_code: 409, body: { error: { code: 'CHECKOUT_ALREADY_INITIATED', message: 'Cart 49f97982-89b0-4629-a70d-d0c3120de530 is not mutable (status: locked)' } } },
      },
    },
  });
  await openRegistration(view);
  view.document.querySelector('.agend-ev-reg__step:last-child').click();
  view.document.querySelector('.agend-ev-reg__submit').click();
  await tick();
  await tick();
  await tick();
  const box = view.document.querySelector('.agend-ev-reg__error');
  assert.equal(box.getAttribute('role'), 'alert');
  assert.notEqual(box.style.display, 'none');
  assert.match(box.textContent, /being checked out/);
  assert.doesNotMatch(box.textContent, /mutable|49f97982/);
  assert.equal(box.querySelector('a').getAttribute('href'), 'https://example.test/cart/');
});

test('a failed add for another reason shows a plain message with no cart link', async (t) => {
  const view = await build(t, {
    url: 'https://example.test/events/event/conference/',
    cfg: { deepLink: 'conference', cartEnabled: true, cartPageUrl: 'https://example.test/cart/' },
    cartReply: { status: 500, body: { code: 'agend_api_error', message: 'relation "crm_carts" does not exist', data: { status_code: 500 } } },
  });
  await openRegistration(view);
  view.document.querySelector('.agend-ev-reg__step:last-child').click();
  view.document.querySelector('.agend-ev-reg__submit').click();
  await tick();
  await tick();
  await tick();
  const box = view.document.querySelector('.agend-ev-reg__error');
  assert.equal(box.textContent, 'Unable to add to cart. Please try again.');
  assert.equal(box.querySelector('a'), null);
});

test('without the shop script, a buyer refusal keeps its wording and a completed cart asks for a refresh', async (t) => {
  for (const [code, message, expected] of [
    ['PRODUCT_UNAVAILABLE', 'Early Bird is sold out.', /^Early Bird is sold out\.$/],
    ['CHECKOUT_ALREADY_INITIATED', 'Cart 1 is not mutable (status: complete)', /Refresh the page/],
  ]) {
    const view = await build(t, {
      url: 'https://example.test/events/event/conference/',
      cfg: { deepLink: 'conference', cartEnabled: true, cartPageUrl: 'https://example.test/cart/' },
      cartReply: { status: 409, body: { code: 'agend_api_error', message, data: { status_code: 409, body: { error: { code, message } } } } },
    });
    await openRegistration(view);
    view.document.querySelector('.agend-ev-reg__step:last-child').click();
    view.document.querySelector('.agend-ev-reg__submit').click();
    await tick();
    await tick();
    await tick();
    const box = view.document.querySelector('.agend-ev-reg__error');
    assert.match(box.textContent, expected);
    assert.equal(box.querySelector('a'), null);
  }
});
