/**
 * Discount codes in the cart: apply, show the discounted totals from the
 * platform's quote, remove, plain-English refusals, locked carts, and carts
 * the platform cannot discount.
 * Run with: node --test agend-apps-shop/tests/js/discount.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const read = (name) => readFileSync(join(__dirname, '../../assets/js/' + name), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

const QUOTE = {
  code: 'ATEMDEMO10', applies: true, refusal_message: null, version: 'v1',
  undiscounted_total_cents: 12100, discount_cents: 1210, subtotal_cents: 9900, tax_cents: 990, total_cents: 10890, show_gst: true, lines: [],
};

async function setup(t, { member = true, discountSlot = true, status = 'current', productType = 'event_tickets', applyReply, stored = null } = {}) {
  const dom = new JSDOM(`<!doctype html><html lang="en-AU"><body>
    <div class="agend-apps-shop-cart-view" data-member-signed-in="${member}">
      <div class="agend-shop-cart-loading"></div>
      <div class="agend-shop-cart-empty" hidden><p></p></div>
      <div class="agend-shop-cart-locked-notice" hidden></div>
      <div class="agend-shop-cart-content" hidden>
        <table><tbody class="agend-shop-cart-items"></tbody><tfoot><tr><td class="agend-shop-cart-total-amount"></td></tr></tfoot></table>
        ${member ? '<div class="agend-shop-cart-payment" hidden></div>' : '<input class="agend-shop-buyer-name"><input class="agend-shop-buyer-email">'}
        ${discountSlot ? '<div class="agend-shop-cart-discount" hidden></div>' : ''}
        <button class="agend-shop-btn-proceed-checkout">Proceed to Checkout</button>
        <button class="agend-shop-btn-cancel-checkout" hidden disabled>Cancel</button>
        <div class="agend-shop-cart-message"></div>
      </div>
    </div></body></html>`, { url: 'https://shop.example/cart/', runScripts: 'outside-only' });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = { restUrl: 'https://shop.example/api/', nonce: 'n' };
  window.agendAppsShop = { payOnInvoice: '', locale: 'en-AU' };
  const calls = [];
  let code = stored;
  window.fetch = async (url, options = {}) => {
    const path = new URL(url).pathname;
    calls.push({ path, method: options.method || 'GET', body: options.body ? JSON.parse(options.body) : null });
    if (path === '/api/cart/discount') {
      if ((options.method || 'GET') === 'DELETE') { code = null; return { ok: true, status: 200, json: async () => ({ data: { ...QUOTE, code: null, applies: false } }) }; }
      const reply = applyReply || { status: 200, body: { data: QUOTE } };
      if (reply.status === 200) code = 'ATEMDEMO10';
      return { ok: reply.status === 200, status: reply.status, json: async () => reply.body };
    }
    if (path === '/api/cart/checkout-options') return { ok: true, status: 200, json: async () => ({ data: { invoice: null } }) };
    const item = { id: '1', name: 'Workshop', unit_amount: 12100, currency: 'AUD', quantity: 1, product_type: productType, metadata: {} };
    return { ok: true, status: 200, json: async () => ({ data: { items: [item], total_amount: 12100, currency: 'AUD', status, discount_code: code, discount_quote: code ? QUOTE : null } }) };
  };
  const loaded = new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(read('agend-apps-shop-cart-session.js'));
  window.AgendCartSession.getToken = () => 'token';
  window.eval(read('agend-apps-shop-cart-view.js'));
  await loaded;
  for (let i = 0; i < 6; i++) await tick();
  return { window, doc: window.document, calls };
}

async function applyCode(window, doc, value) {
  doc.querySelector('.agend-shop-discount-input').value = value;
  doc.querySelector('.agend-shop-discount-form').dispatchEvent(new window.Event('submit', { cancelable: true }));
  for (let i = 0; i < 8; i++) await tick();
}

test('a member applies a code and sees the discounted totals from the platform', async (t) => {
  const { window, doc, calls } = await setup(t);
  const label = doc.querySelector('.agend-shop-cart-discount label');
  assert.equal(label.textContent, 'Discount code');
  assert.equal(doc.getElementById(label.getAttribute('for')), doc.querySelector('.agend-shop-discount-input'));

  await applyCode(window, doc, ' atemdemo10 ');
  const post = calls.find((c) => c.path === '/api/cart/discount');
  assert.deepEqual(post.body, { discount_code: 'atemdemo10' });
  assert.equal(doc.querySelector('.agend-shop-discount-applied strong').textContent, 'ATEMDEMO10');
  const summary = Array.from(doc.querySelectorAll('.agend-shop-discount-summary dt, .agend-shop-discount-summary dd')).map((n) => n.textContent);
  assert.deepEqual(summary, ['Subtotal', '$121.00', 'Discount', '−$12.10', 'GST included', '$9.90', 'Total', '$108.90']);
  assert.equal(doc.querySelector('.agend-shop-cart-total-amount').textContent, '$108.90');
  assert.match(doc.querySelector('.agend-shop-btn-proceed-checkout').textContent, /\$108\.90/);
});

test('removing the code restores the full total', async (t) => {
  const { doc, calls } = await setup(t, { stored: 'ATEMDEMO10' });
  assert.equal(doc.querySelector('.agend-shop-cart-total-amount').textContent, '$108.90');
  doc.querySelector('.agend-shop-discount-remove').click();
  for (let i = 0; i < 8; i++) await tick();
  assert.ok(calls.some((c) => c.path === '/api/cart/discount' && c.method === 'DELETE'));
  assert.equal(doc.querySelector('.agend-shop-cart-total-amount').textContent, '$121.00');
  assert.ok(doc.querySelector('.agend-shop-discount-input'));
});

test('a refused code shows the platform\'s buyer message in an alert', async (t) => {
  const refusal = { code: 'agend_api_error', message: 'This code has expired.', data: { status_code: 400, body: { error: { code: 'INVALID_DISCOUNT_CODE', message: 'This code has expired.' } } } };
  const { window, doc } = await setup(t, { applyReply: { status: 400, body: refusal } });
  await applyCode(window, doc, 'OLDCODE');
  const message = doc.querySelector('.agend-shop-discount-message');
  assert.equal(message.getAttribute('role'), 'alert');
  assert.equal(message.textContent, 'This code has expired.');
  assert.equal(doc.querySelector('.agend-shop-cart-total-amount').textContent, '$121.00');
});

test('an empty code is not sent', async (t) => {
  const { window, doc, calls } = await setup(t);
  await applyCode(window, doc, '   ');
  assert.equal(calls.filter((c) => c.path === '/api/cart/discount').length, 0);
  assert.equal(doc.querySelector('.agend-shop-discount-message').textContent, 'Enter a discount code.');
});

test('the field is disabled while a checkout holds the cart', async (t) => {
  const { doc } = await setup(t, { status: 'locked' });
  assert.equal(doc.querySelector('.agend-shop-discount-input').disabled, true);
  assert.equal(doc.querySelector('.agend-shop-discount-apply').disabled, true);
});

test('no field on a site that has not turned codes on, or for a cart the platform cannot discount', async (t) => {
  const off = await setup(t, { discountSlot: false });
  assert.equal(off.doc.querySelector('.agend-shop-discount-input'), null);
  assert.equal(off.doc.querySelector('.agend-shop-cart-total-amount').textContent, '$121.00');
  const addon = await setup(t, { productType: 'crm_subscription_products' });
  assert.equal(addon.doc.querySelector('.agend-shop-cart-discount').hidden, true);
});

test('a guest names the buyer before applying a code', async (t) => {
  const { window, doc, calls } = await setup(t, { member: false });
  await applyCode(window, doc, 'ATEMDEMO10');
  assert.equal(calls.filter((c) => c.path === '/api/cart/discount').length, 0);
  assert.match(doc.querySelector('.agend-shop-discount-message').textContent, /name and email/);
  doc.querySelector('.agend-shop-buyer-name').value = 'Sam Guest';
  doc.querySelector('.agend-shop-buyer-email').value = 'sam@example.test';
  await applyCode(window, doc, 'ATEMDEMO10');
  assert.deepEqual(calls.find((c) => c.path === '/api/cart/discount').body, { discount_code: 'ATEMDEMO10', guest_buyer: { name: 'Sam Guest', email: 'sam@example.test' } });
});
