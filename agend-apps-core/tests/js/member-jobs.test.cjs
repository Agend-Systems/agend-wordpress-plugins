const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');
const source = readFileSync(join(__dirname, '../../assets/js/member-jobs.js'), 'utf8');
const settle = () => new Promise(resolve => setImmediate(resolve));

function fixture(t, form = {}, credits = { available: 2 }) {
  const dom = new JSDOM('<!doctype html><div id="jobs"></div>', { url: 'https://example.test/jobs' });
  t.after(() => dom.window.close());
  const { window } = dom;
  const root = window.document.querySelector('#jobs');
  const calls = [];
  const fetch = async (url, options) => {
    calls.push({ url, options, body: options.body ? JSON.parse(options.body) : null });
    const data = options.method === 'POST' ? { id: 'accepted', status: 'pending' }
      : url.endsWith('/credits') ? credits : { items: [] };
    return { status: 200, ok: true, json: async () => ({ success: true, data }) };
  };
  // Read the constructor from the actual browser script without a public test API.
  new Function('window', 'document', 'fetch', source.replace('  function initAll()', '  window.TestMemberJobs = MemberJobs;\n  function initAll()'))(window, window.document, fetch);
  const cfg = {
    endpoint: '/jobs/me', buyUrl: '/buy', buy: [{ label: 'Buy credits', url: '/buy' }],
    text: { heading: 'My jobs', intro: 'Each job uses one credit.', post: 'Post a job', buy: 'Buy credits', noCredits: 'Buy a credit.', success: 'Sent for review.' },
    freeIntro: 'Post a job for review.',
    messages: {
      freeListing: 'Listing a job is free.', creditsOne: 'You have %d job credit.', creditsMany: 'You have %d job credits.',
      noCreditsTitle: 'You have no job credits', none: 'No jobs yet.', expiresOn: 'Closes %s',
      columns: { job: 'Job', status: 'Status', submitted: 'Submitted', actions: 'Actions' },
      status: { pending: 'Awaiting review', rejected: 'Rejected' },
      actions: { edit: 'Edit', again: 'Post again', withdraw: 'Withdraw' },
      form: {
        back: 'Back', newHeading: 'Post a job', editHeading: 'Edit job', save: 'Save changes', submit: 'Submit',
        sending: 'Sending', submitNote: 'Submitting uses one credit.', submitNoteCount: 'Uses 1 of %d credits.', submitNoteLast: 'Uses your last credit.',
        checking: 'Checking credit', choose: 'Choose', required: 'This field is required.', futureDate: 'Choose tomorrow or later.', fix: 'Check fields.',
        labels: { title: 'Job title', department: 'Department', expires_at: 'Closing date' }, hints: {},
        sections: { role: 'Role', employer: 'Employer', location: 'Location', salary: 'Salary', apply: 'How to apply' },
        options: { employment_type: { 'full-time': 'Full time' }, location_type: { hybrid: 'Hybrid' } },
      },
    },
    form: {
      hidden: ['reference_number', 'experience_level', 'category', 'short_description', 'description', 'company_name', 'company_website', 'location_city', 'location_state', 'location_postcode', 'location_country', 'salary', 'application', 'application_instructions', 'contact_name', 'contact_phone'],
      required: [], states: [], categories: [], tagGroups: [], minDate: '2099-12-01', ...form,
    },
  };
  const view = new window.TestMemberJobs(root, cfg);
  view.credits = credits;
  function submit() {
    root.querySelector('[name=title]').value ||= 'Accountant';
    root.querySelector('[name=employment_type]').value = 'full-time';
    root.querySelector('[name=location_type]').value = 'hybrid';
    root.querySelector('form').dispatchEvent(new window.Event('submit', { cancelable: true }));
    return settle();
  }
  return { view, root, calls, submit, window };
}

test('older config renders and submits neither new field, even when editing a job that has them', async t => {
  const f = fixture(t);
  f.view.drawForm({ id: 'job', title: 'Old', employment_type: 'full-time', location_type: 'hybrid', department: 'Finance', expires_at_date: '2099-12-12' });
  assert.equal(f.root.querySelector('[name=department]'), null);
  assert.equal(f.root.querySelector('[name=expires_at]'), null);
  f.root.querySelector('[name=title]').value = 'Changed';
  await f.submit();
  const patch = f.calls.find(c => c.options.method === 'PATCH');
  assert.deepEqual(patch.body, { title: 'Changed' });
});

test('enabled fields have labels, limits and date minimum and post through the proxy', async t => {
  const f = fixture(t, { department: true, closingDate: true });
  f.view.drawForm(null);
  const department = f.root.querySelector('[name=department]');
  const date = f.root.querySelector('[name=expires_at]');
  assert.equal(department.maxLength, 200);
  assert.equal(date.type, 'date');
  assert.equal(date.min, '2099-12-01');
  for (const input of [department, date]) assert.ok(f.root.querySelector(`label[for="${input.id}"]`));
  department.value = 'Finance'; date.value = '2099-12-12';
  await f.submit();
  const post = f.calls.find(c => c.options.method === 'POST');
  assert.equal(post.body.department, 'Finance');
  assert.equal(post.body.expires_at, '2099-12-12'); // PHP converts this site-local day to UTC before contacting the gateway.
});

test('edit prefills site-local values, preserves untouched dates, and sends empty values to clear', async t => {
  const f = fixture(t, { department: true, closingDate: true });
  f.view.drawForm({ id: 'job', title: 'Accountant', employment_type: 'full-time', location_type: 'hybrid', department: 'Finance', expires_at: '2099-12-12T13:29:59Z', expires_at_date: '2099-12-12' });
  assert.equal(f.root.querySelector('[name=department]').value, 'Finance');
  assert.equal(f.root.querySelector('[name=expires_at]').value, '2099-12-12');
  f.root.querySelector('[name=department]').value = '';
  f.root.querySelector('[name=expires_at]').value = '';
  await f.submit();
  assert.deepEqual(f.calls.find(c => c.options.method === 'PATCH').body, { department: '', expires_at: '' });
});

test('required and past-date errors use the existing accessible error path', async t => {
  const f = fixture(t, { department: true, closingDate: true, required: ['department', 'expires_at'] });
  f.view.drawForm(null);
  await f.submit();
  for (const name of ['department', 'expires_at']) {
    const input = f.root.querySelector(`[name=${name}]`);
    assert.equal(input.getAttribute('aria-required'), 'true');
    assert.equal(input.getAttribute('aria-invalid'), 'true');
    assert.ok(input.getAttribute('aria-describedby').includes(`${input.id}-error`));
  }
  assert.equal(f.root.querySelector('[role=status]').getAttribute('aria-live'), 'polite');
  f.root.querySelector('[name=department]').value = 'Finance';
  f.root.querySelector('[name=expires_at]').value = '2099-11-30';
  await f.submit();
  assert.equal(f.root.querySelector('.agend-member-jobs__field--expires_at .agend-member-jobs__error').textContent, 'Choose tomorrow or later.');
  assert.equal(f.calls.length, 0);
});

test('hidden enabled fields are neither rendered nor sent', async t => {
  const f = fixture(t, { department: true, closingDate: true });
  f.view.cfg.form.hidden.push('department', 'expires_at');
  f.view.drawForm(null);
  await f.submit();
  const body = f.calls.find(c => c.options.method === 'POST').body;
  assert.equal(Object.hasOwn(body, 'department'), false);
  assert.equal(Object.hasOwn(body, 'expires_at'), false);
});

test('free listings show the free note, enable post and post-again, and remove credit purchase copy', async t => {
  const f = fixture(t, {}, { available: 0, free_listings: true });
  f.view.items = [{ id: 'job', title: 'Accountant', status: 'rejected', department: 'Finance', expires_at: '2099-12-12T13:29:59Z', expires_at_formatted: '12 December 2099' }];
  f.view.drawList();
  assert.match(f.root.textContent, /Listing a job is free\./);
  assert.match(f.root.textContent, /Finance/);
  assert.match(f.root.textContent, /Closes 12 December 2099/);
  assert.match(f.root.textContent, /Post again/);
  assert.doesNotMatch(f.root.textContent, /credit|Buy/i);
  f.root.querySelector('.agend-member-jobs__button').click();
  assert.ok(f.root.querySelector('form'));
  assert.doesNotMatch(f.root.textContent, /credit|Buy/i);
  await f.submit();
  assert.equal(f.calls.find(c => c.options.method === 'POST').body.title, 'Accountant');
  assert.doesNotMatch(f.root.textContent, /credit|Buy/i);
});

test('zero credits without a boolean free flag retains the existing gate', t => {
  for (const flag of [undefined, false, 'true']) {
    const f = fixture(t, {}, { available: 0, free_listings: flag });
    f.view.drawList();
    assert.match(f.root.textContent, /You have no job credits/);
    assert.equal(Array.from(f.root.querySelectorAll('button')).some(b => b.textContent === 'Post a job'), false);
  }
});

test('an edit that leaves a closing date of today alone can still change other fields', async t => {
  const f = fixture(t, { department: true, closingDate: true });
  f.view.drawForm({ id: 'job', title: 'Accountant', employment_type: 'full-time', location_type: 'hybrid', department: 'Finance', expires_at: '2099-11-30T12:59:59Z', expires_at_date: '2099-11-30' });
  f.root.querySelector('[name=department]').value = 'Payroll';
  await f.submit();
  assert.deepEqual(f.calls.find(c => c.options.method === 'PATCH').body, { department: 'Payroll' });
});

test('an edit that moves the closing date to a past day is refused', async t => {
  const f = fixture(t, { department: true, closingDate: true });
  f.view.drawForm({ id: 'job', title: 'Accountant', employment_type: 'full-time', location_type: 'hybrid', expires_at: '2099-12-12T13:29:59Z', expires_at_date: '2099-12-12' });
  f.root.querySelector('[name=expires_at]').value = '2099-11-29';
  await f.submit();
  assert.equal(f.calls.filter(c => c.options.method === 'PATCH').length, 0);
});

test('a half-typed closing date is an error and never clears the saved date', async t => {
  const f = fixture(t, { department: true, closingDate: true });
  f.view.drawForm({ id: 'job', title: 'Accountant', employment_type: 'full-time', location_type: 'hybrid', expires_at: '2099-12-12T13:29:59Z', expires_at_date: '2099-12-12' });
  const input = f.root.querySelector('[name=expires_at]');
  input.value = '';
  Object.defineProperty(input, 'validity', { value: { badInput: true } });
  await f.submit();
  assert.equal(f.calls.filter(c => c.options.method === 'PATCH').length, 0);
  assert.equal(input.getAttribute('aria-invalid'), 'true');
});
