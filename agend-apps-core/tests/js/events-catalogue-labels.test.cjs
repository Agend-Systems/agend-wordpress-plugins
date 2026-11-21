/**
 * Events catalogue: the site's wording for the non-member price (from
 * window.agendApps.pricingLabels, set in Settings > Agend Widgets > Event
 * Pricing) and the accessible names of the filter controls.
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
  price_summary: { member_from: 50, non_member_from: 80 },
};

function config(filters = {}) {
  return {
    heading: { show: false },
    layout: { desktop: 3, tablet: 2, mobile: 1, cardRadius: 10 },
    card: { image: false, dateBadge: true, pills: true, description: false, excerptLength: 110, pricing: true },
    filters: Object.assign({ search: true, category: true, type: true, city: true, date: true }, filters),
    exclusions: { categories: [], venueTypes: [], cities: [] },
    timeframe: 'upcoming',
    timezone: 'Australia/Adelaide',
    pagination: { style: 'none', perPage: 9 },
    colours: {},
    theme: {},
    prettyLinks: false,
    onDetailPage: true,
    detailBase: '',
  };
}

async function build(t, { pricingLabels, filters } = {}) {
  const cfg = JSON.stringify(config(filters)).replace(/'/g, '&#39;');
  const dom = new JSDOM(`<!doctype html><div class="agend-events-catalogue" data-agend-events-config='${cfg}'></div>`, {
    url: 'https://example.test/events/',
    runScripts: 'outside-only',
  });
  const { window } = dom;
  t.after(() => window.close());
  window.agendApps = { restUrl: 'https://example.test/wp-json/agend-apps/v1/' };
  if (pricingLabels) {
    window.agendApps.pricingLabels = pricingLabels;
  }
  window.fetch = (url) => {
    const path = new URL(url).pathname.replace('/wp-json/agend-apps/v1', '');
    let body = [];
    if (path === '/events') {
      body = { data: [event], meta: { pagination: { page: 1, total_pages: 1 } } };
    } else if (path === '/events/categories') {
      body = { data: [{ id: 'c1', name: 'Workshops' }] };
    } else if (path === '/events/venues') {
      body = { data: [{ city: 'Adelaide' }] };
    }
    return Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve(body) });
  };
  await new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve, { once: true }));
  window.eval(source);
  await tick();
  await tick();
  return window.document;
}

function priceLabels(document) {
  return Array.from(document.querySelectorAll('.agend-ev-card__pricing .agend-ev-price__label')).map((n) => n.textContent);
}

test('the card keeps the built-in "Non-Members" label when the site sets no wording', async (t) => {
  const document = await build(t);
  assert.deepEqual(priceLabels(document), ['Members', 'Non-Members']);
});

test('the card uses the site wording for the non-member price', async (t) => {
  const document = await build(t, { pricingLabels: { nonMember: 'Public', nonMemberNote: 'The public rate is shown where available. Final pricing is confirmed at checkout.' } });
  assert.deepEqual(priceLabels(document), ['Members', 'Public']);
  assert.doesNotMatch(document.body.textContent, /non-member/i);
});

test('a blank site wording falls back to the built-in label', async (t) => {
  const document = await build(t, { pricingLabels: { nonMember: '  ' } });
  assert.deepEqual(priceLabels(document), ['Members', 'Non-Members']);
});

test('the site wording is shown as text, never as markup', async (t) => {
  const document = await build(t, { pricingLabels: { nonMember: '<img src=x onerror=alert(1)>' } });
  assert.equal(document.querySelector('.agend-ev-card__pricing img'), null);
  assert.equal(priceLabels(document)[1], '<img src=x onerror=alert(1)>');
});

test('every single-choice filter has an accessible name', async (t) => {
  const document = await build(t);
  assert.equal(document.querySelector('.agend-ev-search').getAttribute('aria-label'), 'Search events');
  const names = Array.from(document.querySelectorAll('select.agend-ev-filter')).map((s) => s.getAttribute('aria-label'));
  assert.deepEqual(names, ['Filter by category', 'Filter by event type', 'Filter by city']);
  const date = document.querySelector('.agend-ev-datefilter__toggle');
  assert.equal(date.getAttribute('aria-label'), 'Filter by date: All Dates');
  assert.equal(document.querySelector('.agend-ev-datefilter__panel').getAttribute('aria-label'), 'Filter by date');
});

test('multi-choice filters name the filter and the current choice', async (t) => {
  const document = await build(t, { filters: { categoryMulti: true, typeMulti: true, cityMulti: true } });
  const toggles = Array.from(document.querySelectorAll('.agend-ev-multiselect__toggle'));
  assert.deepEqual(toggles.map((b) => b.getAttribute('aria-label')), [
    'Filter by category: All Categories',
    'Filter by event type: All Types',
    'Filter by city: All Cities',
  ]);
  const typePanel = toggles[1].parentNode.querySelector('.agend-ev-multiselect__panel');
  assert.equal(typePanel.getAttribute('role'), 'group');
  const box = typePanel.querySelector('input[type="checkbox"]');
  box.checked = true;
  box.dispatchEvent(new box.ownerDocument.defaultView.Event('change'));
  assert.equal(toggles[1].getAttribute('aria-label'), 'Filter by event type: In-Person');
});
