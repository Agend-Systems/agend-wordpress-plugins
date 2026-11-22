/**
 * Memberships catalogue: a paid join is confirmed on the member's return
 * from the payment page, with a clear outcome and never "failed".
 * Run with: node --test agend-apps-core/tests/js/memberships-return.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(require.resolve('../../assets/js/memberships-catalogue.js'), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));
const REF = 'Abcdefghijklmnop0123456789ABCDEF';

async function land(t, { search, replies }) {
  const dom = new JSDOM(`<!doctype html>
    <div class="agend-memberships" data-agend-memberships-config='{}'>
      <span class="agend-visually-hidden" role="status">Loading membership options…</span>
      <div class="agend-mem-container"><div class="agend-mem-grid"></div></div>
    </div>`, { url: 'https://example.test/membership/join/' + search, runScripts: 'outside-only' });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = { loggedIn: true, restUrl: 'https://example.test/wp-json/agend-apps/v1/', nonce: 'n' };
  const posts = [];
  window.fetch = (url, options = {}) => {
    const path = new URL(url, window.location.href).pathname.replace('/wp-json/agend-apps/v1', '');
    if (path === '/crm/me/memberships/return-confirm') {
      posts.push(JSON.parse(options.body));
      const reply = replies.shift();
      if (reply instanceof Error) return Promise.reject(reply);
      return Promise.resolve({ json: () => Promise.resolve(reply) });
    }
    return Promise.resolve({ json: () => Promise.resolve(path === '/crm/tiers' ? [] : []) });
  };
  await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  for (let i = 0; i < 6; i++) await tick();
  return { window, document: window.document, posts };
}

test('a confirmed join says the membership is active and links to the portal', async (t) => {
  const { window, document, posts } = await land(t, {
    search: `?agend_membership=success&agend_membership_ref=${REF}&keep=1`,
    replies: [{ status: 'confirmed', portalUrl: 'https://portal.example.test/home/acme' }],
  });
  assert.deepEqual(posts, [{ token: REF }]);
  const panel = document.querySelector('.agend-mem-confirmation');
  assert.equal(panel.getAttribute('role'), 'status');
  assert.equal(panel.querySelector('h2').textContent, 'Welcome, your membership is active');
  assert.equal(panel.querySelector('a').href, 'https://portal.example.test/home/acme');
  assert.equal(window.location.search, '?keep=1', 'both return arguments are dropped once confirmed');
});

test('a pending join offers Check again, keeps the reference for a reload, and never says failed', async (t) => {
  const { window, document, posts } = await land(t, {
    search: `?agend_membership=success&agend_membership_ref=${REF}`,
    replies: [{ status: 'pending' }, { status: 'unknown' }, { status: 'confirmed', portalUrl: '' }],
  });
  const panel = document.querySelector('.agend-mem-confirmation');
  assert.equal(panel.querySelector('h2').textContent, 'We\'re confirming your payment');
  assert.doesNotMatch(panel.textContent, /fail/i);
  assert.match(window.location.search, /agend_membership_ref=/);

  panel.querySelector('.agend-mem-confirmation__again').click();
  for (let i = 0; i < 4; i++) await tick();
  assert.equal(panel.querySelector('h2').textContent, 'We\'re confirming your payment');

  panel.querySelector('.agend-mem-confirmation__again').click();
  for (let i = 0; i < 4; i++) await tick();
  assert.equal(panel.querySelector('h2').textContent, 'Welcome, your membership is active');
  assert.equal(panel.querySelector('a'), null, 'no portal link without a portal address');
  assert.equal(posts.length, 3);
});

test('a lost connection reads as still confirming', async (t) => {
  const { document } = await land(t, {
    search: `?agend_membership=success&agend_membership_ref=${REF}`,
    replies: [new Error('Failed to fetch')],
  });
  assert.equal(document.querySelector('.agend-mem-confirmation h2').textContent, 'We\'re confirming your payment');
});

test('a reference that is not this member\'s join, or a refused check, shows the general thank-you', async (t) => {
  for (const reply of [{ status: 'none' }, { code: 'rest_forbidden', message: 'Sorry' }]) {
    const { window, document } = await land(t, { search: `?agend_membership=success&agend_membership_ref=${REF}`, replies: [reply] });
    assert.equal(document.querySelector('.agend-mem-confirmation h2').textContent, 'Thank you');
    assert.equal(window.location.search, '');
  }
});

test('a success return without a reference keeps the general thank-you and asks nothing', async (t) => {
  const { document, posts } = await land(t, { search: '?agend_membership=success', replies: [] });
  assert.equal(posts.length, 0);
  assert.equal(document.querySelector('.agend-mem-confirmation h2').textContent, 'Thank you');
});

test('an unsafe portal address is not linked', async (t) => {
  const { document } = await land(t, {
    search: `?agend_membership=success&agend_membership_ref=${REF}`,
    replies: [{ status: 'confirmed', portalUrl: 'javascript:alert(1)' }],
  });
  assert.equal(document.querySelector('.agend-mem-confirmation a'), null);
});
