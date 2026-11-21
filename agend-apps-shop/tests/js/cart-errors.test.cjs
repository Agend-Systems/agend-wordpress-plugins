/**
 * Failed cart requests are shown in plain English (AgendCartSession
 * describeError), with a way out when a checkout holds the cart.
 * Run with: node --test agend-apps-shop/tests/js/cart-errors.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const read = (name) => readFileSync(join(__dirname, '../../assets/js/' + name), 'utf8');
const sessionSource = read('agend-apps-shop-cart-session.js');
const tick = () => new Promise((resolve) => setImmediate(resolve));

const LOCKED = {
  code: 'agend_api_error',
  message: 'Cart 49f97982-89b0-4629-a70d-d0c3120de530 is not mutable (status: locked)',
  data: { status_code: 409, body: { error: { code: 'CHECKOUT_ALREADY_INITIATED', message: 'Cart 49f97982-89b0-4629-a70d-d0c3120de530 is not mutable (status: locked)' } } },
};

function session() {
  const dom = new JSDOM('<!doctype html>', { url: 'https://shop.example/', runScripts: 'outside-only' });
  dom.window.eval(sessionSource);
  return dom.window;
}

test('describeError never passes on the platform wording for a held cart', () => {
  const window = session();
  const described = window.AgendCartSession.describeError(409, LOCKED, 'Fallback.');
  assert.equal(described.locked, true);
  assert.doesNotMatch(described.message, /mutable|49f97982/);
  window.close();
});

test('describeError keeps messages a visitor can act on and hides the rest', () => {
  const window = session();
  const d = (status, body) => window.AgendCartSession.describeError(status, body, 'Fallback.');
  assert.equal(d(401, { code: 'agend_apps_sign_in_required', message: 'Sign in to check out.' }).message, 'Sign in to check out.');
  assert.equal(d(422, { data: { body: { error: { code: 'VALIDATION_ERROR', message: 'Invalid', details: { fields: { quantity: ['Only 2 places are left.'] } } } } } }).message, 'Only 2 places are left.');
  assert.equal(d(400, { message: 'Enter a purchase order number to order on invoice' }).message, 'Enter a purchase order number to order on invoice');
  assert.equal(d(403, { message: "The authenticated user is not a member of the API key's account" }).message, 'Fallback.');
  assert.equal(d(500, { message: 'relation "crm_carts" does not exist' }).message, 'Fallback.');
  assert.match(d(429, { message: 'Agend API rate limit exceeded.' }).message, /Wait a moment/);
  assert.equal(d(502, null).message, 'Fallback.');
  window.close();
});

test('the Add to Cart widget shows a held cart in plain English with a link to the cart', async (t) => {
  const dom = new JSDOM(`<!doctype html>
    <div class="agend-apps-shop-add-to-cart">
      <button class="agend-shop-atc-qty-decrement"></button>
      <input class="agend-shop-atc-qty-input" value="1" min="1">
      <button class="agend-shop-atc-qty-increment"></button>
      <button class="agend-shop-add-to-cart-btn" data-product-type="event_tickets" data-product-id="t1"></button>
      <div class="agend-shop-atc-message" aria-live="polite"></div>
    </div>`, { url: 'https://shop.example/event/', runScripts: 'outside-only' });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = { restUrl: 'https://shop.example/api/' };
  window.agendAppsShop = { cartPageUrl: 'https://shop.example/cart/' };
  window.console.log = () => assert.fail('no debugging output');
  window.fetch = async () => ({ ok: false, status: 409, json: async () => LOCKED });
  window.eval(sessionSource);
  const loaded = new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(read('agend-apps-shop-add-to-cart.js'));
  await loaded;
  window.document.querySelector('.agend-shop-add-to-cart-btn').click();
  await tick();
  await tick();
  const message = window.document.querySelector('.agend-shop-atc-message');
  assert.match(message.textContent, /being checked out/);
  assert.doesNotMatch(message.textContent, /mutable/);
  assert.equal(message.querySelector('a').getAttribute('href'), 'https://shop.example/cart/');
});

test('the cart view switches to its locked view when a change is refused for a held cart', async (t) => {
  const dom = new JSDOM(`<!doctype html>
    <div class="agend-apps-shop-cart-view" data-member-signed-in="true">
      <div class="agend-shop-cart-loading"></div>
      <div class="agend-shop-cart-empty" hidden><p></p></div>
      <div class="agend-shop-cart-locked-notice" hidden></div>
      <div class="agend-shop-cart-content" hidden>
        <table><tbody class="agend-shop-cart-items"></tbody><tfoot><tr><td class="agend-shop-cart-total-amount"></td></tr></tfoot></table>
        <div class="agend-shop-cart-payment" hidden></div>
        <button class="agend-shop-btn-clear-cart">Clear Cart</button>
        <button class="agend-shop-btn-proceed-checkout">Proceed to Checkout</button>
        <button class="agend-shop-btn-cancel-checkout" hidden disabled>Cancel checkout and edit cart</button>
        <div class="agend-shop-cart-message" aria-live="polite"></div>
      </div>
    </div>`, { url: 'https://shop.example/cart/', runScripts: 'outside-only' });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = { restUrl: 'https://shop.example/api/' };
  window.agendAppsShop = { payOnInvoice: '' };
  const item = { id: '1', name: 'Event', unit_amount: 2000, currency: 'AUD', quantity: 1, product_type: 'membership', metadata: {} };
  window.fetch = async (url, options = {}) => {
    if (url.endsWith('/cart/clear')) return { ok: false, status: 409, json: async () => LOCKED };
    if (url.endsWith('/cart/checkout-options')) return { ok: true, status: 200, json: async () => ({ data: { invoice: null } }) };
    return { ok: true, status: 200, json: async () => ({ data: { items: [item], total_amount: 2000, currency: 'AUD', status: 'current' } }) };
  };
  window.eval(sessionSource);
  window.AgendCartSession.getToken = () => 'token';
  const loaded = new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(read('agend-apps-shop-cart-view.js'));
  await loaded;
  await tick();
  await tick();
  window.confirm = () => true;
  window.document.querySelector('.agend-shop-btn-clear-cart').click();
  await tick();
  await tick();
  const message = window.document.querySelector('.agend-shop-cart-message');
  assert.match(message.textContent, /being checked out/);
  assert.doesNotMatch(message.textContent, /mutable/);
  assert.equal(window.document.querySelector('.agend-shop-cart-locked-notice').hidden, false);
  const cancel = window.document.querySelector('.agend-shop-btn-cancel-checkout');
  assert.equal(cancel.hidden, false);
  assert.equal(cancel.disabled, false);
  assert.equal(window.document.querySelector('.agend-shop-btn-proceed-checkout').disabled, true);
});
