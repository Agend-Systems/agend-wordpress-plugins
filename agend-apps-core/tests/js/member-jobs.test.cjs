/**
 * Member Jobs list: the credits panel, Current and Past groups, the line
 * under each job and the row actions.
 * Run with: node --test agend-apps-core/tests/js/member-jobs.test.cjs
 */
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');

const source = readFileSync(join(__dirname, '../../assets/js/member-jobs.js'), 'utf8');
const settle = () => new Promise((resolve) => setTimeout(resolve, 0));

const messages = {
  loadFailed: 'Load failed.',
  creditsOne: 'job credit available',
  creditsMany: 'job credits available',
  creditsUnknown: 'Unknown.',
  none: 'No jobs.',
  noCreditsTitle: 'You have no job credits',
  buyNote: 'Each button takes you straight to checkout.',
  columns: { job: 'Job', status: 'Status', dates: 'Dates', actions: 'Actions' },
  groups: { current: 'Current', past: 'Past' },
  jobsOne: '%d job',
  jobsMany: '%d jobs',
  submittedOn: 'Submitted %s',
  expiresOn: 'Listed until %s',
  inReview: 'With our team for review',
  creditReturned: 'Credit returned',
  offBoard: 'Taken off the board',
  reason: 'Reason:',
  noCreditNote: 'No credit.',
  checkingNote: 'Checking.',
  status: { pending: 'Awaiting review', published: 'Live', rejected: 'Not approved', filled: 'Filled', expired: 'Expired' },
  actions: {
    view: 'View', again: 'Post again', edit: 'Edit', withdraw: 'Withdraw', withdrawConfirm: 'Withdraw this job?',
    close: 'Close', closeConfirm: 'Take this job off the board?', filled: 'Mark as filled', filledConfirm: 'Mark filled?',
    yes: 'Yes', no: 'Cancel',
  },
  form: {
    failed: 'Failed.',
    session: 'Session.',
    options: {
      employment_type: { 'full-time': 'Full time', contract: 'Contract' },
      location_type: { hybrid: 'Hybrid', remote: 'Remote' },
    },
  },
};

const items = [
  { id: 'a', title: 'Old role', status: 'filled', submitted_at: '2026-09-02T00:00:00Z' },
  { id: 'b', title: 'Live role', status: 'published', slug: 'live-role', submitted_at: '2026-11-12T00:00:00Z', expires_at: '2026-12-12T00:00:00Z', location_city: 'Melbourne', location_state: 'VIC', employment_type: 'full-time', location_type: 'hybrid' },
  { id: 'c', title: 'Declined role', status: 'rejected', submitted_at: '2026-11-03T00:00:00Z', employment_type: 'contract', location_type: 'remote', rejection_reason: 'Add a salary range.' },
  { id: 'd', title: 'New role', status: 'pending', submitted_at: '2026-11-24T00:00:00Z' },
];

async function view(t, available) {
  const dom = new JSDOM('<!doctype html><div class="agend-member-jobs"></div>', { url: 'https://example.test/my-account/my-jobs/' });
  t.after(() => dom.window.close());
  const { document } = dom.window;
  const root = document.querySelector('.agend-member-jobs');
  root.setAttribute('data-agend-member-jobs-config', JSON.stringify({
    endpoint: '/wp-json/agend-apps/v1/jobs/me',
    jobsPage: 'https://example.test/careers/',
    prettyLinks: true,
    buy: [{ label: 'Buy 1 credit', url: '/?agend_jobs_buy=1' }, { label: 'Buy 5 credits', url: '/?agend_jobs_buy=5' }],
    text: { heading: '', intro: 'Intro.', post: 'Post a job', creditsNote: 'Each job uses one credit.', noCredits: 'Buy a credit to post.', success: 'Sent.' },
    form: {},
    messages,
  }));
  const fetch = async (url) => {
    const body = String(url).endsWith('/credits')
      ? { success: true, data: { available } }
      : { success: true, data: { items, pagination: { has_next: false } } };
    return { ok: true, status: 200, json: async () => body };
  };
  Object.defineProperty(document, 'readyState', { value: 'complete' });
  const window = { agendApps: { nonce: 'n' }, addEventListener() {}, crypto: dom.window.crypto, location: dom.window.location };
  new Function('window', 'document', 'fetch', source)(window, document, fetch);
  await settle();
  await settle();
  return root;
}

const text = (node) => (node ? node.textContent.replace(/\s+/g, ' ').trim() : '');

test('the credits panel shows the count, the note, the Buy buttons and then Post a job', async (t) => {
  const root = await view(t, 3);
  const panel = root.querySelector('.agend-member-jobs__credits');
  assert.equal(text(panel.querySelector('.agend-member-jobs__credits-count')), '3');
  assert.equal(text(panel.querySelector('.agend-member-jobs__credits-text')), 'job credits available');
  assert.equal(text(panel.querySelector('.agend-member-jobs__credits-note')), 'Each job uses one credit.');
  const buttons = [...panel.querySelectorAll('.agend-member-jobs__toolbar > *')];
  assert.deepEqual(buttons.map(text), ['Buy 1 credit', 'Buy 5 credits', 'Post a job']);
  assert.ok(buttons[0].classList.contains('agend-member-jobs__button--secondary'));
  assert.ok(!buttons[2].classList.contains('agend-member-jobs__button--secondary'));
});

test('with no credits the first Buy button leads and there is no Post a job', async (t) => {
  const root = await view(t, 0);
  const panel = root.querySelector('.agend-member-jobs__credits');
  assert.ok(panel.classList.contains('agend-member-jobs__credits--none'));
  assert.equal(text(panel.querySelector('.agend-member-jobs__credits-text')), 'You have no job credits');
  assert.equal(text(panel.querySelector('.agend-member-jobs__credits-note')), 'Buy a credit to post.');
  const buttons = [...panel.querySelectorAll('.agend-member-jobs__toolbar > *')];
  assert.deepEqual(buttons.map(text), ['Buy 1 credit', 'Buy 5 credits']);
  assert.ok(!buttons[0].classList.contains('agend-member-jobs__button--secondary'));
  assert.equal(text(panel.querySelector('.agend-member-jobs__note')), 'Each button takes you straight to checkout.');
});

test('jobs are grouped into Current then Past, each in the order Agend gave', async (t) => {
  const root = await view(t, 3);
  const groups = [...root.querySelectorAll('tbody.agend-member-jobs__group')];
  assert.deepEqual(groups.map((g) => text(g.querySelector('.agend-member-jobs__group-heading'))), ['Current 2 jobs', 'Past 2 jobs']);
  const titles = (g) => [...g.querySelectorAll('.agend-member-jobs__title')].map(text);
  assert.deepEqual(titles(groups[0]), ['Live role', 'New role']);
  assert.deepEqual(titles(groups[1]), ['Old role', 'Declined role']);
  assert.ok(root.querySelector('table').classList.contains('at-table-reset'));
});

test('each row shows its place and type, and what has happened since it was submitted', async (t) => {
  const root = await view(t, 3);
  const row = (title) => [...root.querySelectorAll('tr')].find((tr) => text(tr.querySelector('.agend-member-jobs__title')) === title);

  const live = row('Live role');
  assert.equal(text(live.querySelector('.agend-member-jobs__sub')), 'Melbourne VIC · Full time · Hybrid');
  assert.match(text(live.querySelector('.agend-member-jobs__date-line')), /^Submitted /);
  assert.match(text(live.querySelector('.agend-member-jobs__date-note--published')), /^Listed until /);

  const declined = row('Declined role');
  assert.equal(text(declined.querySelector('.agend-member-jobs__sub')), 'Contract · Remote');
  assert.equal(text(declined.querySelector('.agend-member-jobs__reason')), 'Reason: Add a salary range.');
  assert.equal(text(declined.querySelector('.agend-member-jobs__date-note')), 'Credit returned');

  assert.equal(row('New role').querySelector('.agend-member-jobs__sub'), null);
  assert.equal(text(row('New role').querySelector('.agend-member-jobs__date-note')), 'With our team for review');
  assert.equal(text(row('Old role').querySelector('.agend-member-jobs__date-note')), 'Taken off the board');
});

test('Withdraw and Close are marked as the actions that take a job away, and keep that on Yes', async (t) => {
  const root = await view(t, 3);
  const actions = (title) => [...root.querySelectorAll('tr')].find((tr) => text(tr.querySelector('.agend-member-jobs__title')) === title).querySelector('.agend-member-jobs__actions');

  const live = actions('Live role');
  assert.deepEqual([...live.children].map(text), ['View', 'Mark as filled', 'Close']);
  assert.equal(live.querySelector('a').getAttribute('href'), 'https://example.test/careers/job/live-role/');
  assert.ok(live.querySelector('a svg'));
  assert.deepEqual([...live.querySelectorAll('.agend-member-jobs__action--danger')].map(text), ['Close']);

  const pending = actions('New role');
  assert.deepEqual([...pending.querySelectorAll('.agend-member-jobs__action--danger')].map(text), ['Withdraw']);
  pending.querySelector('.agend-member-jobs__action--danger').click();
  assert.ok(pending.classList.contains('agend-member-jobs__actions--confirm'));
  assert.equal(text(pending.querySelector('.agend-member-jobs__confirm')), 'Withdraw this job?');
  assert.deepEqual([...pending.querySelectorAll('.agend-member-jobs__action--danger')].map(text), ['Yes']);
});
