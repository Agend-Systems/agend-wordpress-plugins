/**
 * Email-check copy for the member login frontend, evaluated afresh per test.
 * Run with: node --test agend-apps-core/tests/js/member-login.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(join(__dirname, '../../assets/js/member-login.js'), 'utf8');
const settle = () => new Promise((resolve) => setImmediate(resolve));

async function fixture(t, verificationSent, response) {
  const dom = new JSDOM('<!doctype html><div class="agend-member-login"></div>');
  t.after(() => dom.window.close());
  const document = dom.window.document;
  const root = document.querySelector('.agend-member-login');
  root.setAttribute('data-agend-member-login-config', JSON.stringify({
    messages: {
      email: 'Email', password: 'Password', submit: 'Sign in', working: 'Signing in…',
      register: 'Create an account', registerSubmit: 'Create account',
      registerWorking: 'Creating…', backToSignIn: 'Back to sign in',
      resend: 'Resend', error: 'Sign-in failed.', verificationSent,
    },
  }));
  const calls = [];
  let reloads = 0;
  const window = {
    agendApps: { restUrl: '/wp-json/agend-apps/v1/', nonce: 'test-nonce' },
    location: { href: 'https://example.test/login', reload() { reloads++; } },
  };
  const fetch = async (url, options) => {
    calls.push({ url, options });
    const reply = url.endsWith('/auth/session')
      ? { status: 200, data: { signed_in: false } }
      : url.endsWith('/auth/mfa/verify')
        ? { status: 200, data: {} }
        : { status: 202, data: response };
    return { ok: true, status: reply.status, json: async () => reply.data };
  };
  Object.defineProperty(document, 'readyState', { value: 'complete' });
  new Function('window', 'document', 'fetch', source)(window, document, fetch);
  await settle();
  return { root, calls, window: dom.window, reloads: () => reloads };
}

async function submitCredentials(view, mode) {
  const { root, window } = view;
  root.querySelector('input[name="email"]').value = 'member@example.test';
  if (mode === 'register') {
    root.querySelector('.agend-ml-form__register').click();
    assert.equal(root.querySelector('input[name="email"]').value, 'member@example.test');
  }
  root.querySelector('input[name="password"]').value = 'example-password';
  root.querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true }));
  await settle();
}

for (const mode of ['login', 'register']) {
  for (const configured of [true, false]) {
    test(`${mode} 202 ${configured ? 'shows configured email-check copy' : 'uses the gateway message when the setting is cleared'}`, async (t) => {
      const custom = 'Confirm your email, then sign in again.';
      const gateway = 'Email is temporarily unavailable. Please try again later.';
      const view = await fixture(t, configured ? custom : '', {
        code: 'verification_required', message: gateway,
      });
      await submitCredentials(view, mode);

      const notice = view.root.querySelector('form .agend-ml-form__notice');
      assert.equal(notice.textContent, configured ? custom : gateway);
      assert.equal(notice.style.display, '');
      assert.equal(view.root.querySelector('form [type="submit"]').style.display, 'none');
      assert.ok(Array.from(view.root.querySelectorAll('input')).every((input) => input.disabled));
      assert.equal(view.root.querySelector('.agend-ml-resend-notice + button').textContent, 'Resend');
      assert.equal(view.root.querySelector('input[autocomplete="one-time-code"]'), null);
      assert.equal(view.reloads(), 0);
      assert.deepEqual(view.calls.map((call) => call.url), [
        '/wp-json/agend-apps/v1/auth/session', `/wp-json/agend-apps/v1/auth/${mode}`,
      ]);
      const request = view.calls[1].options;
      assert.equal(request.method, 'POST');
      assert.equal(request.headers['X-WP-Nonce'], 'test-nonce');
      assert.equal(JSON.parse(request.body).email, 'member@example.test');
    });
  }
}

for (const verificationSent of ['Custom email-check copy.', '']) {
  test(`login MFA 202 still asks for and verifies an authenticator code with ${verificationSent ? 'configured' : 'cleared'} email-check copy`, async (t) => {
    const view = await fixture(t, verificationSent, {
      code: 'mfa_required', challenge_id: 'challenge-1',
      factors: [{ id: 'factor-1', friendly_name: 'Authenticator app' }],
      message: 'Enter your authenticator code.',
    });
    await submitCredentials(view, 'login');

    const code = view.root.querySelector('input[autocomplete="one-time-code"]');
    assert.ok(code);
    assert.equal(code.disabled, false);
    assert.equal(view.root.querySelector('[type="submit"]').textContent, 'Verify code');
    assert.equal(view.root.querySelector('.agend-ml-form__notice'), null);
    assert.equal(view.root.querySelector('.agend-ml-resend-notice'), null);
    assert.equal(view.root.querySelector('input[name="password"]'), null);
    assert.equal(view.reloads(), 0);

    code.value = '123456';
    view.root.querySelector('form').dispatchEvent(new view.window.Event('submit', { cancelable: true }));
    await settle();

    assert.equal(view.calls[2].url, '/wp-json/agend-apps/v1/auth/mfa/verify');
    assert.deepEqual(JSON.parse(view.calls[2].options.body), {
      challenge_id: 'challenge-1', factor_id: 'factor-1', code: '123456',
    });
    assert.equal(code.value, '');
    assert.equal(view.reloads(), 1);
  });
}
