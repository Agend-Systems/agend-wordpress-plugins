/**
 * The client-rendered item list (assets/js/directory-catalogue.js) must draw the
 * same markup as the PHP renderer (agend_apps_records_render_item_list()), which
 * ItemListRenderTest.php asserts against the same fixture:
 * tests/fixtures/item-list-render.json.
 *
 * Run with: node --test agend-apps-core/tests/js/item-list-render.test.mjs
 *
 * The script is an IIFE with no exports, so the item list portion is cut out of
 * its source and loaded into a minimal fake `document`. The fake serialises
 * with the same escaping as esc_html() and esc_attr() (a real browser would
 * serialise a `"` or `'` in text differently), so this compares structure, text
 * and attributes, not a browser's innerHTML.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const script = readFileSync(new URL('../../assets/js/directory-catalogue.js', import.meta.url), 'utf8');
const fixture = JSON.parse(readFileSync(new URL('../fixtures/item-list-render.json', import.meta.url), 'utf8'));

function escapeHtml(value) {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

class FakeNode {
  constructor(tag) {
    this.tag = tag;
    this.className = '';
    this.attributes = [];
    this.children = [];
    this.text = '';
  }

  set textContent(value) {
    this.text = String(value);
  }

  appendChild(child) {
    this.children.push(child);
    return child;
  }

  setAttribute(name, value) {
    this.attributes.push([name, String(value)]);
  }

  toHtml() {
    const attributes = (this.className ? [['class', this.className]] : []).concat(this.attributes);
    const open = attributes.map(([name, value]) => ` ${name}="${escapeHtml(value)}"`).join('');

    return `<${this.tag}${open}>${escapeHtml(this.text)}${this.children.map((child) => child.toHtml()).join('')}</${this.tag}>`;
  }
}

function between(source, start, end) {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from);
  assert.ok(from !== -1 && to !== -1, `directory-catalogue.js no longer contains the markers "${start}" and "${end}"`);

  return source.slice(from, to);
}

const elSource = script.match(/function el\(tag, className, text\) \{[\s\S]*?\n {2}\}\n/);
assert.ok(elSource, 'directory-catalogue.js no longer defines el(tag, className, text)');

const itemListSource = between(script, '// Item list custom fields.', '// Renders a single public custom-field value');

const fakeDocument = {
  createElement: (tag) => new FakeNode(tag),
  documentElement: { lang: 'en-AU' },
};

const { renderItemList, itemListOptions } = new Function(
  'document',
  `${elSource[0]}\n${itemListSource}\nreturn { renderItemList: renderItemList, itemListOptions: itemListOptions };`
)(fakeDocument);

for (const { name, entry, options, html } of fixture.cases) {
  test(`should draw the shared fixture markup when ${name}`, () => {
    const node = renderItemList(entry, options);

    assert.equal(node ? node.toHtml() : '', html);
  });
}

test('should read the layout and item fields from the catalogue config', () => {
  assert.deepEqual(itemListOptions({ detail: { itemList: { layout: 'list', fields: ['name', 'area_sqm'] } } }), {
    layout: 'list',
    fields: ['name', 'area_sqm'],
  });
});

test('should default to a table of every item field when the config says nothing usable', () => {
  const defaults = { layout: 'table', fields: [] };

  assert.deepEqual(itemListOptions(null), defaults);
  assert.deepEqual(itemListOptions({}), defaults);
  assert.deepEqual(itemListOptions({ detail: { itemList: { layout: 'grid', fields: 'name' } } }), defaults);
});

test('should name and focus the scrolling wrapper', () => {
  const entry = fixture.cases.find((fixtureCase) => fixtureCase.name === 'table_default').entry;
  const wrapper = renderItemList(entry, { layout: 'table', fields: [] });

  assert.deepEqual(wrapper.attributes, [
    ['tabindex', '0'],
    ['role', 'region'],
    ['aria-label', 'Tenants'],
  ]);
});
