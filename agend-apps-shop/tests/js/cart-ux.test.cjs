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
  quantity = 1, productType = 'event_tickets', mutationStatus = 200,
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
    quantity,
    product_type: productType,
    metadata: { event_slug: 'event', attendees },
  };
  window.agendApps = { restUrl: 'https://shop.example/api/' };
  window.agendAppsShop = { continueShoppingUrl: browseUrl, payOnInvoice, portalHomeUrl };
  window.AgendCartSession = { getToken: () => 'token', getHeaders: () => ({}) };
  window.fetch = async (url, options = {}) => {
    calls.push({ url, options });
    let payload = { data: { items: [item], total_amount: amount, currency: 'AUD' } };
    if (url.endsWith('/cart/checkout-options')) {
      const option = typeof invoice === 'function' ? await invoice() : invoice;
      return { ok: true, status: 200, json: async () => ({ data: { invoice: option, membershipInstalments: null } }) };
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
    } else if (url.endsWith('/cart/item/update')) {
      if (mutationStatus !== 200) return { ok: false, status: mutationStatus, json: async () => ({ message: 'Update refused' }) };
      item.quantity = JSON.parse(options.body).quantity;
      payload = { data: item, meta: { totals: { newTotalAmount: amount * item.quantity, currency: 'AUD', newTotalCount: item.quantity } } };
    } else if (url.endsWith('/cart/items/attendees')) {
      if (mutationStatus !== 200) return { ok: false, status: mutationStatus, json: async () => ({ message: 'Update refused' }) };
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


test('a single approved company is shown by name and automatically invoiced', async (t) => {
  const companyId = '11111111-1111-4111-8111-111111111111';
  const { window, dom, calls } = await setup(t, {
    member: true,
    invoice: invoiceOption({ purchaseOrderRequired: false, approvedCompanyIds: [companyId], approvedCompanies: [{ id: companyId, name: 'Acme School' }] }),
    invoiceReply: accepted,
  });
  await chooseInvoice(window, dom);
  assert.equal(dom.querySelector('select.agend-shop-invoice-company'), null);
  assert.equal(dom.querySelector('.agend-shop-invoice-company-name').textContent, 'Company to invoice: Acme School');
  dom.querySelector('.agend-shop-btn-proceed-checkout').click();
  await tick();
  assert.equal(JSON.parse(invoiceCalls(calls)[0].options.body).companyId, companyId);
});

test('a single approved company without enriched names still supplies its ID', async (t) => {
  const companyId = '11111111-1111-4111-8111-111111111111';
  const { window, dom, calls } = await setup(t, {
    member: true, invoice: invoiceOption({ purchaseOrderRequired: false, approvedCompanyIds: [companyId] }), invoiceReply: accepted,
  });
  await chooseInvoice(window, dom);
  assert.equal(dom.querySelector('.agend-shop-invoice-company-name').textContent, 'Company to invoice: Company 1');
  dom.querySelector('.agend-shop-btn-proceed-checkout').click();
  await tick();
  assert.equal(JSON.parse(invoiceCalls(calls)[0].options.body).companyId, companyId);
});

const optionsCalls = (calls) => calls.filter((call) => call.url.endsWith('/cart/checkout-options'));
const refreshPayment = async () => { await new Promise((resolve) => setTimeout(resolve, 250)); await tick(); };
const capacityRefusal = () => invoiceOption({ available: false, reason: 'ticket_capacity_unavailable', reasonMessage: 'Not enough places.' });

test('ticket quantity changes refresh a stale invoice refusal without replacing attendee inputs', async (t) => {
  let option = capacityRefusal();
  const { window, dom, calls } = await setup(t, { member: true, invoice: () => option, quantity: 2 });
  dom.querySelector('.agend-shop-btn-attendees').click();
  await tick();
  // The decrement removes the first unset seat; edit the seat being retained.
  const input = dom.querySelectorAll('.agend-shop-attendee-name')[1];
  input.value = 'Unsaved name';
  option = invoiceOption();
  dom.querySelector('.agend-shop-qty-decrement').click();
  await tick();
  await refreshPayment();
  assert.equal(optionsCalls(calls).length, 2);
  assert.equal(dom.querySelector('.agend-shop-invoice-unavailable'), null);
  assert.ok(dom.querySelector('.agend-shop-payment-method[value="invoice"]'));
  assert.equal(dom.querySelector('.agend-shop-attendee-name'), input);
  assert.equal(input.value, 'Unsaved name');
  assert.equal(dom.querySelector('.agend-shop-payment-method[value="card"]').checked, true);
});

test('successful attendee saves and ticket increases debounce refreshes and retain invoice entries', async (t) => {
  const companies = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
  const { window, dom, calls } = await setup(t, { member: true, invoice: invoiceOption({ approvedCompanyIds: companies }) });
  await chooseInvoice(window, dom);
  const po = dom.querySelector('.agend-shop-invoice-po');
  po.value = 'PO-retained';
  po.dispatchEvent(new window.Event('input'));
  const company = dom.querySelector('.agend-shop-invoice-company');
  company.value = companies[1];
  company.dispatchEvent(new window.Event('change'));
  dom.querySelector('.agend-shop-btn-attendees').click();
  await tick();
  const input = dom.querySelector('.agend-shop-attendee-name');
  dom.querySelector('.agend-shop-btn-attendees-save').click();
  await tick();
  dom.querySelector('.agend-shop-qty-increment').click();
  await tick();
  input.value = 'Still editing';
  assert.equal(optionsCalls(calls).length, 1, 'refresh waits for the mutation burst');
  await refreshPayment();
  assert.equal(optionsCalls(calls).length, 2, 'one refresh for both mutations');
  assert.equal(dom.querySelector('.agend-shop-attendee-name'), input);
  assert.equal(input.value, 'Still editing');
  assert.equal(dom.querySelector('.agend-shop-invoice-po').value, 'PO-retained');
  assert.equal(dom.querySelector('.agend-shop-invoice-company').value, companies[1]);
  assert.equal(dom.querySelector('.agend-shop-payment-method[value="invoice"]').checked, true);
  assert.match(dom.querySelector('.agend-shop-btn-proceed-checkout').textContent, /40\.00/);
});

test('attendee saves alone refresh invoice availability', async (t) => {
  let option = capacityRefusal();
  const { dom, calls } = await setup(t, { member: true, invoice: () => option });
  dom.querySelector('.agend-shop-btn-attendees').click();
  await tick();
  option = invoiceOption();
  dom.querySelector('.agend-shop-btn-attendees-save').click();
  await tick();
  await refreshPayment();
  assert.equal(optionsCalls(calls).length, 2);
  assert.ok(dom.querySelector('.agend-shop-payment-method[value="invoice"]'));
});

test('non-event quantity changes also refresh payment options and their total', async (t) => {
  let option = capacityRefusal();
  const { dom, calls } = await setup(t, { member: true, productType: 'courses', invoice: () => option });
  option = invoiceOption();
  dom.querySelector('.agend-shop-qty-increment').click();
  await new Promise((resolve) => setTimeout(resolve, 550));
  await refreshPayment();
  assert.equal(optionsCalls(calls).length, 2);
  assert.ok(dom.querySelector('.agend-shop-payment-method[value="invoice"]'));
  assert.match(dom.querySelector('.agend-shop-btn-proceed-checkout').textContent, /40\.00/);
});

test('failed quantity and attendee mutations do not refresh checkout options', async (t) => {
  const { dom, calls } = await setup(t, { member: true, invoice: invoiceOption(), mutationStatus: 400 });
  dom.querySelector('.agend-shop-qty-increment').click();
  await tick();
  dom.querySelector('.agend-shop-btn-attendees').click();
  await tick();
  dom.querySelector('.agend-shop-btn-attendees-save').click();
  await tick();
  await refreshPayment();
  assert.equal(optionsCalls(calls).length, 1);
});


test('invoice entries survive a temporary refusal and an obsolete company is not submitted', async (t) => {
  const companies = ['11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222'];
  let option = invoiceOption({ approvedCompanyIds: companies });
  const { window, dom, calls } = await setup(t, { member: true, invoice: () => option, invoiceReply: accepted });
  await chooseInvoice(window, dom);
  dom.querySelector('.agend-shop-invoice-po').value = 'PO-kept';
  dom.querySelector('.agend-shop-invoice-company').value = companies[1];
  option = capacityRefusal();
  dom.querySelector('.agend-shop-qty-increment').click();
  await tick();
  await refreshPayment();
  assert.ok(dom.querySelector('.agend-shop-invoice-unavailable'));
  option = invoiceOption({ approvedCompanyIds: companies });
  dom.querySelector('.agend-shop-qty-increment').click();
  await tick();
  await refreshPayment();
  assert.equal(dom.querySelector('.agend-shop-invoice-po').value, 'PO-kept');
  assert.equal(dom.querySelector('.agend-shop-invoice-company').value, companies[1]);

  option = invoiceOption();
  dom.querySelector('.agend-shop-qty-increment').click();
  await tick();
  await refreshPayment();
  await chooseInvoice(window, dom);
  dom.querySelector('.agend-shop-btn-proceed-checkout').click();
  await tick();
  const body = JSON.parse(invoiceCalls(calls)[0].options.body);
  assert.equal(body.companyId, undefined);
  assert.equal(body.purchaseOrderNumber, 'PO-kept');
});

test('an older checkout-options reply cannot overwrite a changed cart', async (t) => {
  let resolveOld;
  let count = 0;
  const { dom, calls } = await setup(t, {
    member: true,
    invoice: () => ++count === 1 ? new Promise((resolve) => { resolveOld = resolve; }) : invoiceOption(),
  });
  dom.querySelector('.agend-shop-qty-increment').click();
  await tick();
  resolveOld(capacityRefusal());
  await tick();
  assert.equal(dom.querySelector('.agend-shop-invoice-unavailable'), null, 'the old response is ignored even during the debounce');
  await refreshPayment();
  assert.equal(optionsCalls(calls).length, 2);
  assert.ok(dom.querySelector('.agend-shop-payment-method[value="invoice"]'));
});

test('an allowed guest still checks out by card with buyer details', async (t) => {
  const { dom, calls } = await setup(t);
  dom.querySelector('.agend-shop-buyer-name').value = 'Guest Buyer';
  dom.querySelector('.agend-shop-buyer-email').value = 'guest@example.test';
  dom.querySelector('.agend-shop-btn-proceed-checkout').click();
  await tick();
  const call = calls.find((entry) => entry.url.endsWith('/cart/checkout'));
  assert.deepEqual(JSON.parse(call.options.body).guest_buyer, { name: 'Guest Buyer', email: 'guest@example.test' });
  assert.equal(optionsCalls(calls).length, 0);
});
