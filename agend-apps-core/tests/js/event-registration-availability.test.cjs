const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(join(__dirname, '../../assets/js/events-catalogue.js'), 'utf8');

async function openRegistration(t, eventFields, tickets) {
  const { window } = new JSDOM(
    `<div class="agend-events-catalogue" data-agend-events-config='{"ssrDetail":true,"cartEnabled":true}'>
       <div class="agend-ev-detail"><button data-agend-event-slug="conference">Register Now</button></div>
     </div>`,
    { url: 'https://shop.example/events/event/conference/', runScripts: 'outside-only' },
  );
  t.after(() => window.close());
  const requests = [];
  window.fetch = async (url) => {
    requests.push(url);
    const data = url.endsWith('/tickets') ? tickets : { slug: 'conference', name: 'Conference', ...eventFields };
    return { json: async () => ({ data }) };
  };
  window.eval(source);
  await new Promise((resolve) => setImmediate(resolve));
  window.document.querySelector('[data-agend-event-slug]').click();
  await new Promise((resolve) => setImmediate(resolve));
  return { document: window.document, requests };
}

test('a stale registration button stops at the closed sales window', async (t) => {
  const { document, requests } = await openRegistration(t, { purchase_end_at: '2000-01-01T00:00:00Z' }, []);
  const button = document.querySelector('[data-agend-event-slug]');
  assert.equal(button.textContent, 'Registration closed');
  assert.equal(button.disabled, true);
  assert.equal(requests.length, 1);
});

test('registration offers explicitly free and paid tickets without treating missing prices as free', async (t) => {
  const { document } = await openRegistration(t, {}, [
    { ticket: { id: 'missing', name: 'Missing price' } },
    { ticket: { id: 'free', name: 'Free pass' }, pricingTiers: [{ non_member_price: '0.00' }] },
    { ticket: { id: 'paid', name: 'Paid pass' }, pricingTiers: [{ non_member_price: '42.00' }] },
  ]);
  const rows = [...document.querySelectorAll('.agend-ev-reg__ticket-price')].map((node) => node.textContent);
  assert.deepEqual(rows, ['Free', '$42.00']);
});
