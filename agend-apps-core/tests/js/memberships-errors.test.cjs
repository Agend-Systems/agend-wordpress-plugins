/**
 * Memberships catalogue: prices as "$1,540.00 a year", the loading text
 * cleared once tiers show, and failed checkouts shown in the page (never a
 * browser alert, never the platform's own wording).
 * Run with: node --test agend-apps-core/tests/js/memberships-errors.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(require.resolve('../../assets/js/memberships-catalogue.js'), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

const govt = {
  id: 'govt', name: 'Govt Dept', slug: 'govt', is_active: true, sort_order: 1,
  price_cents: 154000, billing_period: 'annually', currency: 'AUD', formatted_price: '$1540.00/annually',
};
const member = {
  id: 'member', name: 'Member', slug: 'member', is_active: true, sort_order: 2,
  price_cents: 22000, billing_period: 'monthly', currency: 'AUD', formatted_price: '$220.00/monthly',
};
const legacy = { id: 'legacy', name: 'Legacy', slug: 'legacy', is_active: true, sort_order: 3, formatted_price: '$10.00/fortnightly' };

async function build(t, { loggedIn = false, responses = {}, cfg = { fields: { price: true }, signupMode: 'direct' } } = {}) {
  const json = JSON.stringify(cfg).replace(/'/g, '&#39;');
  const dom = new JSDOM(`<!doctype html>
    <div class="agend-memberships" data-agend-memberships-config='${json}'>
      <span class="agend-visually-hidden" role="status">Loading membership options…</span>
      <div class="agend-mem-container"><div class="agend-mem-grid"></div></div>
    </div>`, { url: 'https://example.test/join/', runScripts: 'outside-only' });
  const { window } = dom;
  t.after(() => window.close());
  const alerts = [];
  window.alert = (message) => alerts.push(message);
  window.agendApps = { loggedIn, restUrl: 'https://example.test/wp-json/agend-apps/v1/' };
  window.fetch = (url) => {
    const path = new URL(url, window.location.href).pathname.replace('/wp-json/agend-apps/v1', '');
    const body = Object.hasOwn(responses, path) ? responses[path]
      : path === '/crm/tiers' ? [govt, member, legacy] : path === '/crm/fields' ? [] : [];
    return Promise.resolve(body).then((data) => {
      if (data instanceof Error) throw data;
      return { json: () => Promise.resolve(data) };
    });
  };
  await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  await tick();
  await tick();
  return { window, document: window.document, alerts };
}

test('tier prices have a thousands separator and plain period wording', async (t) => {
  const { document } = await build(t);
  const prices = Array.from(document.querySelectorAll('.agend-mem-card__price-value')).map((n) => n.textContent);
  assert.deepEqual(prices, ['$1,540.00 a year', '$220.00 a month', '$10.00/fortnightly']);
});

test('the loading text goes once the tiers are shown', async (t) => {
  const { document } = await build(t);
  assert.equal(document.querySelector('[role="status"]').textContent, '');
});

test('a refused member checkout is shown in the page, in plain English, with no alert', async (t) => {
  const { window, document, alerts } = await build(t, {
    loggedIn: true,
    responses: {
      '/crm/me/memberships': { code: 'agend_api_error', message: "The authenticated user is not a member of the API key's account", data: { status_code: 403, body: { error: { code: 'NOT_ACCOUNT_MEMBER', message: "The authenticated user is not a member of the API key's account" } } } },
    },
  });
  document.querySelector('.agend-mem-card__button').click();
  await tick();
  const submit = document.querySelector('.agend-mem-form__submit');
  assert.equal(submit.textContent, 'Proceed to Checkout — $1,540.00 a year');
  submit.click();
  await tick();
  await tick();
  assert.deepEqual(alerts, []);
  const box = document.querySelector('.agend-mem-error');
  assert.equal(box.getAttribute('role'), 'alert');
  assert.match(box.textContent, /couldn't start the checkout/);
  assert.doesNotMatch(box.textContent, /API key/);
  assert.equal(submit.disabled, false);
  assert.equal(box.nextElementSibling, submit, 'the message sits just above the button');

  // A retry replaces the message rather than adding another.
  submit.click();
  await tick();
  await tick();
  assert.equal(document.querySelectorAll('.agend-mem-error').length, 1);
  void window;
});

test('a lost connection during the member checkout is shown in the page', async (t) => {
  const { document, alerts } = await build(t, {
    loggedIn: true,
    responses: { '/crm/me/memberships': new Error('Failed to fetch') },
  });
  document.querySelector('.agend-mem-card__button').click();
  await tick();
  document.querySelector('.agend-mem-form__submit').click();
  await tick();
  await tick();
  assert.deepEqual(alerts, []);
  assert.doesNotMatch(document.querySelector('.agend-mem-error').textContent, /Failed to fetch/);
});

test('the source has no browser alert left', () => {
  assert.doesNotMatch(source.replace(/\/\/.*$/gm, ''), /\balert\(/);
});
