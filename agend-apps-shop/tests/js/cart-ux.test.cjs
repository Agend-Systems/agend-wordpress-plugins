const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(join(__dirname, '../../assets/js/agend-apps-shop-cart-view.js'), 'utf8');
const tick = () => new Promise((resolve) => setImmediate(resolve));

async function setup(t, {
  member = false, query = '', amount = 2000, attendees = [], browseUrl = 'https://shop.example/events/',
  signIn = false, payOnInvoice = '1', invoice = null, invoiceReply = null, portalHomeUrl = '',
} = {}) {
  let buyer = member ? '<div class="agend-shop-cart-payment" hidden></div>' : '<input class="agend-shop-buyer-name"><input class="agend-shop-buyer-email">';
  if (signIn) {
    buyer = '<div class="agend-shop-cart-signin"><a class="agend-shop-cart-signin-link" href="https://shop.example/login/?agend_cart_signin=1">Sign in to check out</a></div>';
  }
  const checkoutButton = signIn ? '' : '<button class="agend-shop-btn-proceed-checkout">Proceed to Checkout</button>';
  const confirmation = member ? '<div class="agend-shop-invoice-confirmation" tabindex="-1" hidden></div>' : '';
  const html = `
    <div class="agend-apps-shop-cart-view" data-member-signed-in="${member}">
      <div class="agend-shop-cart-loading"></div>
      <div class="agend-shop-cart-empty" hidden><p></p><a class="agend-shop-keep-browsing" href="/members/">Keep browsing</a></div>
      <div class="agend-shop-cart-locked-notice" hidden></div>
      <div class="agend-shop-cart-content" hidden>
        <table>
          <tbody class="agend-shop-cart-items"></tbody>
          <tfoot><tr><td class="agend-shop-cart-total-amount"></td></tr></tfoot>
        </table>
        ${buyer}
        ${checkoutButton}
        <div class="agend-shop-cart-message"></div><a class="agend-shop-keep-browsing" href="/members/">Keep browsing</a>
      </div>
      ${confirmation}
    </div>`;
  const dom = new JSDOM(html, {
    url: `https://shop.example/cart/${query}`,
    runScripts: 'outside-only',
  });
  const { window } = dom;
  t.after(() => window.close());

  let saved;
  const calls = [];
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
  window.agendAppsShop = { continueShoppingUrl: browseUrl, payOnInvoice, portalHomeUrl };
  window.AgendCartSession = { getToken: () => 'token', getHeaders: () => ({}) };
  window.fetch = async (url, options = {}) => {
    calls.push({ url, options });
    let payload = { data: { items: [item], total_amount: amount, currency: 'AUD' } };
    if (url.endsWith('/cart/checkout-options')) {
      return { ok: true, status: 200, json: async () => ({ data: { invoice, membershipInstalments: null } }) };
    }
    if (url.endsWith('/cart/checkout/invoice')) {
      const reply = typeof invoiceReply === 'function' ? invoiceReply(JSON.parse(options.body)) : invoiceReply;
      if (reply instanceof Error) throw reply;
      return { ok: reply.status === 200, status: reply.status, json: async () => reply.body };
    }
    if (url.endsWith('/sites/config')) {
      payload = {};
    } else if (url.endsWith('/attendee-fields')) {
      payload = { data: [{ field_key: 'meal', name: 'Meal', field_type: 'text' }] };
    } else if (url.endsWith('/cart/items/attendees')) {
      saved = JSON.parse(options.body);
      payload = { success: true };
    }
    return { ok: true, status: 200, json: async () => payload };
  };
  // JSDOM fires DOMContentLoaded itself once parsing finishes; dispatching it
  // again would start a second copy of the widget on the same markup.
  const loaded = new Promise((resolve) => window.document.addEventListener('DOMContentLoaded', resolve));
  window.eval(source);
  if (window.document.readyState === 'loading') {
    await loaded;
  } else {
    window.document.dispatchEvent(new window.Event('DOMContentLoaded'));
  }
  await tick();
  return { window, dom: window.document, getSaved: () => saved, calls };
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


test('a cart without a continuation keeps the home link rendered by WordPress', async (t) => {
  const { dom } = await setup(t, { browseUrl: '' });
  assert.equal(dom.querySelector('.agend-shop-keep-browsing').href, 'https://shop.example/members/');
});


const invoiceOption = (overrides = {}) => ({
  available: true,
  reason: null,
  reasonMessage: null,
  purchaseOrderRequired: true,
  termsDays: 30,
  paymentInstructions: 'Pay by EFT to BSB 000-000, account 1234.',
  releasePolicies: { event_tickets: 'payment_confirmed' },
  unsupportedProductTypes: [],
  approvedCompanyIds: [],
  dueAt: '2026-12-20T13:00:00+00:00',
  dueLocalDate: '2026-12-21',
  timezone: 'Australia/Sydney',
  ticketCutoffAt: null,
  ticketCutoffShortened: false,
  ...overrides,
});

const accepted = (body) => ({
  status: 200,
  body: {
    success: true,
    data: {
      orderId: '33333333-3333-4333-8333-333333333333',
      orderNumber: 'ORD-1001',
      invoiceOrderId: '44444444-4444-4444-8444-444444444444',
      totalCents: 2000,
      currency: 'AUD',
      dueAt: '2026-12-20T13:00:00+00:00',
      dueLocalDate: '2026-12-21',
      timezone: 'Australia/Sydney',
      purchaseOrderNumber: body.purchaseOrderNumber || null,
      paymentInstructions: 'Pay by EFT.',
      lines: [{ orderItemId: '1', name: 'Event', productType: 'event_tickets', releasePolicy: 'payment_confirmed' }],
    },
  },
});

async function chooseInvoice(window, dom) {
  await tick();
  const radio = dom.querySelector('.agend-shop-payment-method[value="invoice"]');
  radio.checked = true;
  radio.dispatchEvent(new window.Event('change'));
}

const invoiceCalls = (calls) => calls.filter((call) => call.url.endsWith('/cart/checkout/invoice'));

test('a member orders on invoice once the required purchase order number is entered', async (t) => {
  const { window, dom, calls } = await setup(t, {
    member: true,
    invoice: invoiceOption(),
    invoiceReply: accepted,
    portalHomeUrl: 'https://portal.example/home/atem-sandbox',
  });
  await chooseInvoice(window, dom);
  const button = dom.querySelector('.agend-shop-btn-proceed-checkout');
  assert.match(button.textContent, /^Place order on invoice — /);
  assert.match(dom.querySelector('.agend-shop-invoice-field').textContent, /Purchase order number \(required\)/);
  assert.match(dom.querySelector('.agend-shop-invoice-terms').textContent, /30 days, due .*2026/);
  assert.equal(dom.querySelector('.agend-shop-invoice-instructions').textContent, 'Pay by EFT to BSB 000-000, account 1234.');
  assert.equal(dom.querySelector('.agend-shop-invoice-tickets').textContent, 'Your event tickets are held for you until the invoice is paid.');

  button.click();
  await tick();
  assert.equal(invoiceCalls(calls).length, 0, 'an empty required purchase order number blocks the order');

  dom.querySelector('.agend-shop-invoice-po').value = '  PO-77 ';
  button.click();
  await tick();
  await tick();
  const [call] = invoiceCalls(calls);
  const body = JSON.parse(call.options.body);
  assert.equal(body.paymentMethod, 'invoice');
  assert.equal(body.purchaseOrderNumber, 'PO-77');
  assert.match(body.idempotencyKey, /^wp-cart-/);
  assert.equal(body.companyId, undefined);

  const confirmation = dom.querySelector('.agend-shop-invoice-confirmation');
  assert.equal(confirmation.hidden, false);
  assert.equal(dom.querySelector('.agend-shop-cart-content').hidden, true);
  assert.match(confirmation.textContent, /Order placed/);
  assert.match(confirmation.textContent, /awaiting payment/);
  assert.match(confirmation.textContent, /ORD-1001/);
  assert.match(confirmation.textContent, /20\.00/);
  assert.match(confirmation.textContent, /PO-77/);
  assert.equal(confirmation.querySelector('.agend-shop-invoice-link').href, 'https://portal.example/home/atem-sandbox/invoices/33333333-3333-4333-8333-333333333333');
});

test('an optional purchase order number may be left empty and a company must be chosen', async (t) => {
  const companies = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
  const { window, dom, calls } = await setup(t, {
    member: true,
    invoice: invoiceOption({
      purchaseOrderRequired: false,
      releasePolicies: {},
      approvedCompanyIds: companies,
      approvedCompanies: [{ id: companies[0], name: 'Acme School' }, { id: companies[1], name: '' }],
    }),
    invoiceReply: accepted,
  });
  await chooseInvoice(window, dom);
  assert.match(dom.querySelector('.agend-shop-invoice-field').textContent, /\(optional\)/);
  assert.equal(dom.querySelector('.agend-shop-invoice-tickets'), null);
  const select = dom.querySelector('.agend-shop-invoice-company');
  assert.deepEqual([...select.options].map((option) => option.textContent), ['Choose a company', 'Acme School', 'Company 2']);

  dom.querySelector('.agend-shop-btn-proceed-checkout').click();
  await tick();
  assert.equal(invoiceCalls(calls).length, 0, 'no company chosen yet');

  select.value = companies[1];
  select.dispatchEvent(new window.Event('change'));
  dom.querySelector('.agend-shop-btn-proceed-checkout').click();
  await tick();
  await tick();
  const body = JSON.parse(invoiceCalls(calls)[0].options.body);
  assert.equal(body.companyId, companies[1]);
  assert.equal(body.purchaseOrderNumber, undefined);
  assert.equal(dom.querySelector('.agend-shop-invoice-link'), null, 'no portal link without the account portal address');
});

test('a refusal shows the platform message and the next attempt gets a new key; a lost reply keeps it', async (t) => {
  const replies = [
    new Error('network'),
    { status: 400, body: { code: 'INVALID_PURCHASE_ORDER', message: 'Enter a purchase order number to order on invoice', data: { status_code: 400 } } },
    accepted,
  ];
  const { window, dom, calls } = await setup(t, {
    member: true,
    invoice: invoiceOption({ purchaseOrderRequired: false }),
    invoiceReply: (body) => {
      const next = replies.shift();
      return typeof next === 'function' ? next(body) : next;
    },
  });
  await chooseInvoice(window, dom);
  const button = dom.querySelector('.agend-shop-btn-proceed-checkout');
  for (let attempt = 0; attempt < 3; attempt += 1) {
    button.click();
    await tick();
    await tick();
    if (attempt === 1) {
      assert.equal(dom.querySelector('.agend-shop-cart-message').textContent, 'Enter a purchase order number to order on invoice');
      assert.equal(button.disabled, false);
    }
  }
  const keys = invoiceCalls(calls).map((call) => JSON.parse(call.options.body).idempotencyKey);
  assert.equal(keys[0], keys[1], 'the key survives a request whose outcome is unknown');
  assert.notEqual(keys[1], keys[2], 'a refused attempt is not replayed');
  assert.equal(dom.querySelector('.agend-shop-invoice-confirmation').hidden, false);
});

test('only card checkout shows when invoice is unavailable, and helpful reasons are explained', async (t) => {
  const { dom } = await setup(t, { member: true, invoice: invoiceOption({ available: false, reason: 'buyer_not_approved' }) });
  await tick();
  assert.equal(dom.querySelector('.agend-shop-cart-payment').hidden, true);
  assert.equal(dom.querySelector('.agend-shop-payment-method'), null);
  assert.match(dom.querySelector('.agend-shop-btn-proceed-checkout').textContent, /^Proceed to Checkout/);

  const other = await setup(t, {
    member: true,
    invoice: invoiceOption({ available: false, reason: 'ticket_capacity_unavailable', reasonMessage: 'There are not enough ticket places available.' }),
  });
  await tick();
  assert.equal(other.dom.querySelector('.agend-shop-cart-payment').hidden, false);
  assert.match(other.dom.querySelector('.agend-shop-invoice-unavailable').textContent, /not enough ticket places/);
  assert.equal(other.dom.querySelector('.agend-shop-payment-method'), null);
});

test('with Pay on Invoice switched off the cart never asks for checkout options', async (t) => {
  const { dom, calls } = await setup(t, { member: true, payOnInvoice: '', invoice: invoiceOption() });
  await tick();
  assert.equal(calls.some((call) => call.url.endsWith('/cart/checkout-options')), false);
  assert.equal(dom.querySelector('.agend-shop-cart-payment').hidden, true);
});

test('card stays the default and checks out by card', async (t) => {
  const { dom, calls } = await setup(t, { member: true, invoice: invoiceOption() });
  await tick();
  assert.equal(dom.querySelector('.agend-shop-payment-method[value="card"]').checked, true);
  dom.querySelector('.agend-shop-btn-proceed-checkout').click();
  await tick();
  assert.equal(calls.some((call) => call.url.endsWith('/cart/checkout')), true);
  assert.equal(invoiceCalls(calls).length, 0);
});

test('a signed-out visitor who must sign in gets no buyer fields and returns to the cart', async (t) => {
  const { window, dom, calls } = await setup(t, { signIn: true, query: '?continue=%2Fevents%2F' });
  assert.equal(dom.querySelector('.agend-shop-buyer-name'), null);
  assert.equal(dom.querySelector('.agend-shop-btn-proceed-checkout'), null);
  assert.equal(dom.querySelector('.agend-shop-cart-content').hidden, false, 'the cart itself still shows');
  assert.equal(calls.some((call) => call.url.endsWith('/cart/checkout-options')), false);
  const link = dom.querySelector('.agend-shop-cart-signin-link');
  link.addEventListener('click', (event) => event.preventDefault());
  link.click();
  const saved = JSON.parse(window.sessionStorage.getItem('agend_cart_signin_return'));
  assert.equal(saved.target, 'https://shop.example/cart/?continue=%2Fevents%2F');
  assert.ok(saved.expires > Date.now());
});
