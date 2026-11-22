/**
 * Dates in the cart read the way the site writes them (its WordPress
 * language), never the way the visitor's browser is set: an en-AU site shows
 * "21 December 2026", not "December 21, 2026".
 * Run with: node --test agend-apps-shop/tests/js/dates.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(join(__dirname, '../../assets/js/agend-apps-shop-cart-view.js'), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

async function termsText(t, { shopLocale, coreLocale, lang = '' }) {
  const dom = new JSDOM(`<!doctype html><html lang="${lang}"><body>
    <div class="agend-apps-shop-cart-view" data-member-signed-in="true">
      <div class="agend-shop-cart-loading"></div>
      <div class="agend-shop-cart-empty" hidden><p></p></div>
      <div class="agend-shop-cart-locked-notice" hidden></div>
      <div class="agend-shop-cart-content" hidden>
        <table><tbody class="agend-shop-cart-items"></tbody><tfoot><tr><td class="agend-shop-cart-total-amount"></td></tr></tfoot></table>
        <div class="agend-shop-cart-payment" hidden></div>
        <button class="agend-shop-btn-proceed-checkout">Proceed to Checkout</button>
        <div class="agend-shop-cart-message"></div>
      </div>
      <div class="agend-shop-invoice-confirmation" tabindex="-1" hidden></div>
    </div></body></html>`, { url: 'https://shop.example/cart/', runScripts: 'outside-only' });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = { restUrl: 'https://shop.example/api/', locale: coreLocale };
  window.agendAppsShop = { payOnInvoice: '1', locale: shopLocale };
  window.AgendCartSession = { getToken: () => 'token', getHeaders: () => ({}) };
  const item = { id: '1', name: 'Event', unit_amount: 2000, currency: 'AUD', quantity: 1, product_type: 'membership', metadata: {} };
  window.fetch = async (url) => {
    if (url.endsWith('/cart/checkout-options')) {
      return { ok: true, status: 200, json: async () => ({ data: { invoice: {
        available: true, purchaseOrderRequired: false, termsDays: 14, approvedCompanyIds: [],
        dueAt: '2026-12-05T13:00:00+00:00', dueLocalDate: '2026-12-06', timezone: 'Australia/Sydney',
        releasePolicies: {}, unsupportedProductTypes: [],
      }, membershipInstalments: null } }) };
    }
    return { ok: true, status: 200, json: async () => ({ data: { items: [item], total_amount: 2000, currency: 'AUD' } }) };
  };
  const loaded = new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  await loaded;
  for (let i = 0; i < 8; i++) await tick();
  await new Promise((resolve) => setTimeout(resolve, 400));
  for (let i = 0; i < 4; i++) await tick();
  const radio = window.document.querySelector('.agend-shop-payment-method[value="invoice"]');
  radio.checked = true;
  radio.dispatchEvent(new window.Event('change'));
  await tick();
  return window.document.querySelector('.agend-shop-invoice-terms').textContent;
}

test('an en-AU site shows the payment due date as "6 December 2026"', async (t) => {
  assert.equal(await termsText(t, { shopLocale: 'en-AU' }), 'Payment terms: 14 days, due 6 December 2026.');
});

test('a US site keeps the US order', async (t) => {
  assert.equal(await termsText(t, { shopLocale: 'en-US' }), 'Payment terms: 14 days, due December 6, 2026.');
});

test('without the shop\'s locale, Core\'s locale and then the page language are used', async (t) => {
  assert.match(await termsText(t, { coreLocale: 'en-AU' }), /due 6 December 2026\./);
  assert.match(await termsText(t, { lang: 'en-GB' }), /due 6 December 2026\./);
  assert.match(await termsText(t, { shopLocale: 'not a locale!', lang: 'en-AU' }), /due 6 December 2026\./);
});
