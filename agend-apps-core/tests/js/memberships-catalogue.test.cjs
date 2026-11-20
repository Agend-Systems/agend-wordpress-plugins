/** Frontend catalogue initialization, checkout returns and member summaries. */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(require.resolve('../../assets/js/memberships-catalogue.js'), 'utf8');
const tier = { id: 'listed', name: 'Standard', is_active: true, sort_order: 1 };

async function build({ marker, tiers = [], fields = [], loggedIn = false, responses = {} } = {}) {
  const dom = new JSDOM(`<!doctype html>
    <div class="agend-memberships" data-agend-memberships-config='{}'>
      <span class="agend-visually-hidden" role="status">Loading membership options…</span>
      <div class="agend-mem-container"><div class="agend-mem-grid">
        ${'<article class="agend-mem-card agend-mem-skeleton" aria-hidden="true"></article>'.repeat(3)}
      </div></div>
    </div>`, {
    url: 'https://example.test/join?keep=value' + (marker ? '&agend_membership=' + marker : '') + '#plans',
    runScripts: 'outside-only',
  });
  const { window } = dom;
  const calls = [];
  window.agendApps = { loggedIn };
  window.fetch = (url) => {
    const path = new URL(url, window.location.href).pathname.replace('/wp-json/agend-apps/v1', '');
    calls.push(path);
    const body = Object.hasOwn(responses, path) ? responses[path]
      : path === '/crm/tiers' ? tiers : path === '/crm/fields' ? fields : [];
    return Promise.resolve(body).then((data) => {
      if (data instanceof Error) throw data;
      return { json: () => Promise.resolve(data) };
    });
  };
  // Wait for jsdom's native DOMContentLoaded before evaluating to initialize once.
  await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  await new Promise((resolve) => setImmediate(resolve));
  return { window, document: window.document, calls };
}

for (const [marker, selector, message] of [
  ['success', '.agend-mem-confirmation--success', 'Thank you'],
  ['cancel', '.agend-mem-notice--cancelled', 'Checkout was cancelled.'],
]) {
  for (const scenario of ['empty tiers', 'pending fields', 'failed fields', 'pending tiers', 'failed tiers']) {
    test(`${marker} return displays and clears its URL marker with ${scenario}`, async (t) => {
      const { window, document } = await build({
        marker,
        tiers: scenario === 'pending tiers' ? new Promise(() => {})
          : scenario === 'failed tiers' ? new Error('tiers unavailable')
            : scenario === 'empty tiers' ? [] : [tier],
        fields: scenario === 'failed fields' ? new Error('fields unavailable') : new Promise(() => {}),
      });
      t.after(() => window.close());
      assert.ok(document.querySelector(selector)?.textContent.includes(message));
      assert.equal(document.querySelectorAll(selector).length, 1);
      assert.equal(window.location.search, '?keep=value');
      assert.equal(window.location.hash, '#plans');
    });
  }
}

test('empty catalogue clears all three placeholders and exposes a plain-English status', async (t) => {
  const { window, document, calls } = await build();
  t.after(() => window.close());
  assert.equal(document.querySelector('.agend-mem-grid').children.length, 0);
  const status = document.querySelector('[role="status"]');
  assert.equal(status.textContent, 'No membership tiers are available.');
  assert.equal(status.classList.contains('agend-visually-hidden'), false);
  assert.notEqual(window.getComputedStyle(status).display, 'none');
  assert.deepEqual(calls, ['/crm/tiers']);
});

test('empty catalogue retains the signed-in summary, hidden-tier lookup and Pay Now', async (t) => {
  const { window, document, calls } = await build({
    loggedIn: true,
    responses: {
      '/crm/me/memberships': { data: [{ id: 'membership', tier_id: 'hidden', status: 'pending' }] },
      '/crm/tiers/hidden': { data: { id: 'hidden', name: 'Existing plan' } },
      '/crm/me/transactions': { data: [{ id: 'order', membership_id: 'membership', payment_status: 'unpaid' }] },
    },
  });
  t.after(() => window.close());
  assert.ok(calls.includes('/crm/tiers/hidden'));
  assert.equal(document.querySelector('.agend-mem-mystatus .agend-mem-summary__name').textContent, 'Existing plan');
  assert.equal(document.querySelector('.agend-mem-mystatus__pay').textContent, 'Pay Now');
  assert.equal(document.querySelector('.agend-mem-grid').children.length, 0);
});

test('listed tiers keep their sorting, active filter, selection and member summary without fallback', async (t) => {
  const { window, document, calls } = await build({
    loggedIn: true,
    tiers: [tier, { ...tier, id: 'first', name: 'First', sort_order: 0 }, { ...tier, id: 'inactive', is_active: false }],
    responses: {
      '/crm/me/memberships': [{ id: 'membership', tier_id: 'listed', status: 'active' }],
    },
  });
  t.after(() => window.close());
  assert.deepEqual(Array.from(document.querySelectorAll('.agend-mem-card__title'), (node) => node.textContent), ['First', 'Standard']);
  assert.equal(document.querySelectorAll('.agend-mem-skeleton').length, 0);
  assert.ok(calls.includes('/crm/fields'));
  assert.equal(calls.some((path) => path.startsWith('/crm/tiers/')), false);
  assert.equal(document.querySelector('.agend-mem-mystatus .agend-mem-summary__name').textContent, 'Standard');
  document.querySelector('.agend-mem-card__button').click();
  assert.equal(document.querySelector('.agend-mem-form-wrapper .agend-mem-summary__name').textContent, 'First');
});
