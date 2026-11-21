/**
 * Returning from the payment page's cancel link unlocks the cart at once:
 * the cart session script asks the platform to cancel the abandoned checkout
 * (best effort, once), and the cart view loads after that answer.
 * Run with: node --test agend-apps-shop/tests/js/cancel-landing.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const read = (name) => readFileSync(join(__dirname, '../../assets/js/' + name), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

async function land(t, { url, cancelStatus = 200 }) {
  const dom = new JSDOM(`<!doctype html>
    <div class="agend-apps-shop-cart-view" data-member-signed-in="true">
      <div class="agend-shop-cart-loading"></div>
      <div class="agend-shop-cart-empty" hidden><p></p></div>
      <div class="agend-shop-cart-locked-notice" hidden></div>
      <div class="agend-shop-cart-content" hidden>
        <table><tbody class="agend-shop-cart-items"></tbody><tfoot><tr><td class="agend-shop-cart-total-amount"></td></tr></tfoot></table>
        <div class="agend-shop-cart-payment" hidden></div>
        <button class="agend-shop-btn-proceed-checkout">Proceed to Checkout</button>
        <button class="agend-shop-btn-cancel-checkout" hidden disabled>Cancel checkout and edit cart</button>
        <div class="agend-shop-cart-message" aria-live="polite"></div>
      </div>
    </div>`, { url, runScripts: 'outside-only' });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = { restUrl: 'https://shop.example/api/', nonce: 'n' };
  window.agendAppsShop = { payOnInvoice: '' };
  const calls = [];
  let locked = true;
  window.fetch = async (requestUrl, options = {}) => {
    calls.push({ path: new URL(requestUrl).pathname, method: options.method || 'GET' });
    if (requestUrl.endsWith('/cart/checkout/cancel')) {
      if (cancelStatus === 200) locked = false;
      return { ok: cancelStatus === 200, status: cancelStatus, json: async () => (cancelStatus === 200 ? { data: { status: 'cancelled' } } : { code: 'agend_api_error', message: 'Checkout not initiated' }) };
    }
    if (requestUrl.endsWith('/cart/checkout-options')) return { ok: true, status: 200, json: async () => ({ data: { invoice: null } }) };
    const item = { id: '1', name: 'Ticket', unit_amount: 8800, currency: 'AUD', quantity: 1, product_type: 'membership', metadata: {} };
    return { ok: true, status: 200, json: async () => ({ data: { items: [item], total_amount: 8800, currency: 'AUD', status: locked ? 'locked' : 'current' } }) };
  };
  const loaded = new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(read('agend-apps-shop-cart-session.js'));
  window.AgendCartSession.getToken = () => 'token';
  window.eval(read('agend-apps-shop-cart-view.js'));
  await loaded;
  for (let i = 0; i < 6; i++) await tick();
  return { window, calls };
}

test('a cancel return cancels the abandoned checkout once, before the cart loads, and drops the marker', async (t) => {
  const { window, calls } = await land(t, { url: 'https://shop.example/cart/?continue=x&agend_checkout=cancelled' });
  const cancels = calls.filter((c) => c.path === '/api/cart/checkout/cancel');
  assert.equal(cancels.length, 1);
  assert.equal(cancels[0].method, 'POST');
  const firstCart = calls.findIndex((c) => c.path === '/api/cart');
  assert.ok(firstCart > calls.indexOf(cancels[0]), 'the cart is read after the cancel');
  assert.equal(window.location.search, '?continue=x');
  assert.equal(window.document.querySelector('.agend-shop-cart-locked-notice').hidden, true);
  assert.equal(window.document.querySelector('.agend-shop-cart-message').textContent, '');
});

test('a refused cancel (no checkout to cancel) is ignored quietly', async (t) => {
  for (const status of [404, 409]) {
    const { window, calls } = await land(t, { url: 'https://shop.example/cart/?agend_checkout=cancelled', cancelStatus: status });
    assert.equal(calls.filter((c) => c.path === '/api/cart/checkout/cancel').length, 1);
    assert.ok(calls.some((c) => c.path === '/api/cart'));
    assert.equal(window.document.querySelector('.agend-shop-cart-message').textContent, '');
  }
});

test('any other visit to the cart asks nothing', async (t) => {
  const { calls } = await land(t, { url: 'https://shop.example/cart/' });
  assert.equal(calls.filter((c) => c.path === '/api/cart/checkout/cancel').length, 0);
});
