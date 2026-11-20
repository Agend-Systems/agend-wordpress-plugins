const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(join(__dirname, '../../assets/js/agend-apps-shop-cart-view.js'), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

async function setup(t, { member = false, query = '', amount = 2000, attendees = [] } = {}) {
  const buyer = member ? '' : '<input class="agend-shop-buyer-name"><input class="agend-shop-buyer-email">';
  const html = `
    <div class="agend-apps-shop-cart-view" data-member-signed-in="${member}">
      <div class="agend-shop-cart-loading"></div>
      <div class="agend-shop-cart-empty" hidden><p></p><a class="agend-shop-keep-browsing"></a></div>
      <div class="agend-shop-cart-locked-notice" hidden></div>
      <div class="agend-shop-cart-content" hidden>
        <table>
          <tbody class="agend-shop-cart-items"></tbody>
          <tfoot><tr><td class="agend-shop-cart-total-amount"></td></tr></tfoot>
        </table>
        ${buyer}
        <button class="agend-shop-btn-proceed-checkout">Proceed to Checkout</button>
        <div class="agend-shop-cart-message"></div><a class="agend-shop-keep-browsing"></a>
      </div>
    </div>`;
  const dom = new JSDOM(html, {
    url: `https://shop.example/cart/${query}`,
    runScripts: 'outside-only',
  });
  const { window } = dom;
  t.after(() => window.close());

  let saved;
  const item = {
    id: '1',
    name: 'Event',
    unit_amount: amount,
    currency: 'AUD',
    quantity: 1,
    product_type: 'event_tickets',
    metadata: { event_slug: 'event', attendees },
  };
  window.agendApps = { restUrl: 'https://shop.example/api/' };
  window.agendAppsShop = { continueShoppingUrl: 'https://shop.example/events/' };
  window.AgendCartSession = { getToken: () => 'token', getHeaders: () => ({}) };
  window.fetch = async (url, options = {}) => {
    let payload = { data: { items: [item], total_amount: amount, currency: 'AUD' } };
    if (url.endsWith('/sites/config')) {
      payload = {};
    } else if (url.endsWith('/attendee-fields')) {
      payload = { data: [{ field_key: 'meal', name: 'Meal', field_type: 'text' }] };
    } else if (url.endsWith('/cart/items/attendees')) {
      saved = JSON.parse(options.body);
      payload = { success: true };
    }
    return { ok: true, json: async () => payload };
  };
  window.eval(source);
  window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  await tick();
  return { window, dom: window.document, getSaved: () => saved };
}

const namedAttendee = {
  beneficiary_type: 'named',
  beneficiary_name: 'Alex',
  beneficiary_email: 'alex@example.test',
};

test('guest buyer details copy only on request and offsite continuation falls back', async (t) => {
  const { dom } = await setup(t, {
    query: '?continue=https%3A%2F%2Fevil.example%2F',
    attendees: [namedAttendee],
  });
  const buyer = dom.querySelector('.agend-shop-buyer-name');
  assert.equal(buyer.value, '');
  assert.match(dom.querySelector('.agend-shop-btn-proceed-checkout').textContent, /20\.00/);
  assert.equal(dom.querySelector('.agend-shop-keep-browsing').href, 'https://shop.example/events/');
  dom.querySelector('.agend-shop-btn-attendees').click();
  await tick();
  dom.querySelector('.agend-shop-use-for-buyer').click();
  assert.equal(buyer.value, 'Alex');
  buyer.value = 'Different buyer';
  assert.equal(dom.querySelector('.agend-shop-attendee-name').value, 'Alex');
});

test('a free ticket explains the default attendee and labels the final action', async (t) => {
  const { dom } = await setup(t, { amount: 0 });
  assert.equal(dom.querySelector('.agend-shop-attendee-summary').textContent, 'Defaults to buyer');
  assert.equal(dom.querySelector('.agend-shop-btn-proceed-checkout').textContent, 'Confirm free booking');
});

test('member self-assignment saves the beneficiary and preserves other attendee fields', async (t) => {
  const { window, dom, getSaved } = await setup(t, { member: true, attendees: [namedAttendee] });
  dom.querySelector('.agend-shop-btn-attendees').click();
  await tick();
  const choice = dom.querySelector('.agend-shop-attendee-beneficiary');
  choice.value = 'self';
  choice.dispatchEvent(new window.Event('change'));
  dom.querySelectorAll('.agend-shop-attendee-field input')[2].value = 'Vegetarian';
  dom.querySelector('.agend-shop-btn-attendees-save').click();
  await tick();
  assert.deepEqual(getSaved().attendees, [{
    beneficiary_type: 'self',
    custom_fields: { meal: 'Vegetarian' },
  }]);
  assert.equal(dom.querySelector('.agend-shop-attendee-summary').textContent, 'You');

  choice.value = 'named';
  choice.dispatchEvent(new window.Event('change'));
  assert.equal(dom.querySelector('.agend-shop-attendee-name').value, 'Alex');
});


test('choosing the default ticket holder ignores hidden named-attendee inputs', async (t) => {
  const { window, dom, getSaved } = await setup(t, { member: true, attendees: [namedAttendee] });
  dom.querySelector('.agend-shop-btn-attendees').click();
  await tick();
  const choice = dom.querySelector('.agend-shop-attendee-beneficiary');
  choice.value = '';
  choice.dispatchEvent(new window.Event('change'));
  dom.querySelector('.agend-shop-btn-attendees-save').click();
  await tick();
  assert.deepEqual(getSaved().attendees, [{ beneficiary_type: 'unnamed' }]);
  assert.equal(dom.querySelector('.agend-shop-attendee-summary').textContent, 'Defaults to you');
});
