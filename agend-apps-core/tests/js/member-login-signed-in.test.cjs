/**
 * Member login: the signed-in card's heading and portal link, and the
 * create-account password rule.
 * Run with: node --test agend-apps-core/tests/js/member-login-signed-in.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(join(__dirname, '../../assets/js/member-login.js'), 'utf8');
const settle = () => new Promise((resolve) => setImmediate(resolve));

async function fixture(t, { session, handoff, messages = {}, href = 'https://example.test/login/' }) {
  const dom = new JSDOM('<!doctype html><div class="agend-member-login"></div>', { url: 'https://example.test/login/' });
  t.after(() => dom.window.close());
  const document = dom.window.document;
  const root = document.querySelector('.agend-member-login');
  root.setAttribute('data-agend-member-login-config', JSON.stringify({
    messages: Object.assign({
      heading: 'Sign in', signedInHeading: 'You’re signed in', signedIn: 'You are signed in.',
      portalButton: 'Go to my account', signOut: 'Sign out', email: 'Email', password: 'Password',
      submit: 'Sign in', register: 'Create an account', registerTitle: 'Create your account',
      registerSubmit: 'Create account', registerWorking: 'Creating…', backToSignIn: 'Back to sign in',
      passwordHint: 'Use at least 8 characters.', registerError: 'Could not create the account.',
      emailVerified: 'Your email is confirmed. Sign in to continue.',
    }, messages),
  }));
  const calls = [];
  const assigned = [];
  const replaced = [];
  const window = {
    agendApps: { restUrl: '/wp-json/agend-apps/v1/', nonce: 'n' },
    location: { href, assign(url) { assigned.push(url); }, reload() {} },
    history: { state: null, replaceState(state, title, url) { replaced.push(url); } },
    URL: dom.window.URL,
  };
  const fetch = async (url, options) => {
    calls.push({ url, options });
    if (url.endsWith('/auth/session')) return { ok: true, status: 200, json: async () => session };
    if (url.endsWith('/auth/portal-handoff')) return { ok: !!handoff, status: handoff ? 200 : 500, json: async () => (handoff ? { data: { url: handoff } } : {}) };
    return { ok: false, status: 400, json: async () => ({}) };
  };
  Object.defineProperty(document, 'readyState', { value: 'complete' });
  new Function('window', 'document', 'fetch', 'URL', source)(window, document, fetch, dom.window.URL);
  await settle();
  return { root, calls, assigned, replaced, dom };
}

test('the signed-in card is headed "You’re signed in" and the portal is a real link', async (t) => {
  const view = await fixture(t, { session: { signed_in: true, portal_url: 'https://portal.example.test/home/acme' }, handoff: 'https://portal.example.test/auth/handoff?t=1' });
  assert.equal(view.root.querySelector('.agend-ml-card__heading').textContent, 'You’re signed in');
  const link = view.root.querySelector('a.agend-ml-card__button');
  assert.equal(link.textContent, 'Go to my account');
  assert.equal(link.getAttribute('href'), 'https://portal.example.test/home/acme');
  assert.equal(view.root.querySelector('button.agend-ml-card__button'), null);

  const click = new view.dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 });
  link.dispatchEvent(click);
  await settle();
  assert.equal(click.defaultPrevented, true);
  assert.deepEqual(view.assigned, ['https://portal.example.test/auth/handoff?t=1']);
});

test('a modified click on the portal link is left to the browser, and a failed hand-off still goes to the portal', async (t) => {
  const view = await fixture(t, { session: { signed_in: true, portal_url: 'https://portal.example.test/home/acme' }, handoff: null });
  const link = view.root.querySelector('a.agend-ml-card__button');
  const newTab = new view.dom.window.MouseEvent('click', { bubbles: true, cancelable: true, metaKey: true });
  link.dispatchEvent(newTab);
  assert.equal(newTab.defaultPrevented, false);
  link.dispatchEvent(new view.dom.window.MouseEvent('click', { bubbles: true, cancelable: true, button: 0 }));
  await settle();
  assert.deepEqual(view.assigned, ['https://portal.example.test/home/acme']);
});

test('an unsafe portal address is not linked', async (t) => {
  const view = await fixture(t, { session: { signed_in: true, portal_url: 'javascript:alert(1)' } });
  assert.equal(view.root.querySelector('.agend-ml-card__button'), null);
});

test('the create-account form states the password rule and checks it before sending', async (t) => {
  const view = await fixture(t, { session: { signed_in: false } });
  view.root.querySelector('.agend-ml-form__register').click();
  const password = view.root.querySelector('input[name="password"]');
  const hint = view.root.querySelector('.agend-ml-form__hint');
  assert.equal(hint.textContent, 'Use at least 8 characters.');
  assert.equal(password.getAttribute('aria-describedby'), hint.id);
  assert.equal(password.minLength, 8);
  view.root.querySelector('input[name="email"]').value = 'new@example.test';
  password.value = 'short';
  view.root.querySelector('form').dispatchEvent(new view.dom.window.Event('submit', { cancelable: true }));
  await settle();
  assert.equal(view.calls.filter((call) => call.url.endsWith('/auth/register')).length, 0);
  const error = Array.from(view.root.querySelectorAll('.agend-ml-form__error')).find((node) => node.style.display !== 'none');
  assert.equal(error.textContent, 'Use at least 8 characters.');
});

test('a member back from confirming their email is told to sign in, once', async (t) => {
  const view = await fixture(t, { session: { signed_in: false }, href: 'https://example.test/login/?agend_email_verified=1&keep=1' });
  const notice = view.root.querySelector('.agend-ml-verified-notice');
  assert.equal(notice.textContent, 'Your email is confirmed. Sign in to continue.');
  assert.equal(notice.getAttribute('role'), 'status');
  assert.deepEqual(view.replaced, ['https://example.test/login/?keep=1'], 'the marker is taken out of the address');
  assert.ok(view.root.querySelector('input[name="email"]'), 'the sign-in form is shown with it');
});

test('no notice without the marker, or with any other value', async (t) => {
  for (const href of ['https://example.test/login/', 'https://example.test/login/?agend_email_verified=0']) {
    const view = await fixture(t, { session: { signed_in: false }, href });
    assert.equal(view.root.querySelector('.agend-ml-verified-notice'), null);
  }
});
