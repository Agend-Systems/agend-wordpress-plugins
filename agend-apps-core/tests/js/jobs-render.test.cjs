const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { join } = require('node:path');
const { test } = require('node:test');
const { JSDOM } = require('jsdom');
const source = readFileSync(join(__dirname, '../../assets/js/jobs-catalogue.js'), 'utf8');

function fixture(t) {
  const dom = new JSDOM('<!doctype html>', { url: 'https://example.test/jobs/' });
  t.after(() => dom.window.close());
  new Function('window', 'document', source.replace('  function initAll()', '  window.renderJobs = { renderCard, renderDetail };\n  function initAll()'))(dom.window, dom.window.document);
  return dom.window.renderJobs;
}
const job = { title: 'Accountant', company_name: 'Employer', department: '<b>Finance</b>', expires_at: '2099-12-12T13:29:59Z', expires_at_formatted: '12 December 2099' };
function config(style = 'standard', closingDate = false) {
  return { card: { style, company: true, closingDate }, detail: { layout: style, similar: false }, filters: {} };
}
for (const style of ['standard', 'board']) {
  test(`${style} card renders department as text and closing date only when its existing switch is on`, t => {
    const { renderCard } = fixture(t);
    const off = renderCard(job, config(style), '/job');
    assert.match(off.textContent, /<b>Finance<\/b>/);
    assert.equal(off.querySelector('.agend-jobs-department b'), null);
    assert.doesNotMatch(off.textContent, /Closes|12 December/);
    const on = renderCard(job, config(style, true), '/job');
    assert.match(on.textContent, /Closes 12 December 2099/);
    for (const department of [undefined, null, '', '   ']) {
      assert.equal(renderCard({ ...job, department }, config(style), '/job').querySelector('.agend-jobs-department'), null);
    }
  });
}
for (const layout of ['standard', 'header', 'cards']) {
  test(`${layout} detail renders department and retains closing dates independently of the card switch`, t => {
    const { renderDetail } = fixture(t);
    const off = renderDetail(job, config(layout), null);
    assert.match(off.textContent, /<b>Finance<\/b>/);
    assert.match(off.textContent, /12 December 2099/);
    const on = renderDetail(job, config(layout, true), null);
    assert.match(on.textContent, /12 December 2099/);
    const absent = renderDetail({ title: 'Accountant' }, config(layout, true), null);
    assert.equal(absent.querySelector('.agend-jobs-department'), null);
    assert.doesNotMatch(absent.textContent, /Closes|Applications close/);
  });
}
