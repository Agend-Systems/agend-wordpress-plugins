/**
 * The Category filter's levels (assets/js/category-levels.js): what a Top level
 * and a Subcategories of selected filter offer and show from the category tree
 * and the catalogue's one `categories` selection, and the selection after a
 * visitor chooses.
 *
 * Run with: node --test agend-apps-core/tests/js/category-levels.test.mjs
 *
 * The module is a browser script that attaches itself to window.agendFilters,
 * so it is run against a stand-in window and read back from there.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const source = readFileSync(new URL('../../assets/js/category-levels.js', import.meta.url), 'utf8');
const fakeWindow = {};
new Function('window', source)(fakeWindow);
const levels = fakeWindow.agendFilters.categoryLevels;

// In the order the API returns it: by sort order and name across both levels,
// so parents and children interleave and neither level is alphabetical.
const items = [
  { id: 'paed', name: 'Paediatrics', parent_id: null, is_active: true },
  { id: 'feeding', name: 'Feeding', parent_id: 'paed', is_active: true },
  { id: 'mental', name: 'Mental health', parent_id: null, is_active: true },
  { id: 'autism', name: 'Autism', parent_id: 'paed', is_active: true },
  { id: 'retired-sub', name: 'Retired sub-area', parent_id: 'paed', is_active: false },
  { id: 'anxiety', name: 'Anxiety', parent_id: 'mental', is_active: true },
  { id: 'driving', name: 'Driving', parent_id: null, is_active: true },
  { id: 'retired', name: 'Retired area', parent_id: null, is_active: false },
  { id: 'retired-child', name: 'Under a retired area', parent_id: 'retired', is_active: true },
  { id: 'depression', name: 'Depression', parent_id: 'mental', is_active: true },
];
const tree = levels.tree(items);

function ids(view) {
  return view.options.map((option) => option.id);
}

test('should offer every category the API returns in the order it returns them when the level is All categories', () => {
  assert.deepEqual(ids(levels.view(tree, [], 'all')), [
    'paed',
    'feeding',
    'mental',
    'autism',
    'anxiety',
    'driving',
    'retired-child',
    'depression',
  ]);
  assert.deepEqual(levels.view(tree, [], 'all').options[0], { id: 'paed', name: 'Paediatrics' });
});

test('should treat an unknown or missing level as All categories', () => {
  assert.equal(levels.level(undefined), 'all');
  assert.equal(levels.level('grandchildren'), 'all');
  assert.deepEqual(levels.view(tree, [], 'grandchildren'), levels.view(tree, [], 'all'));
});

test('should offer only categories with no parent when the level is Top level', () => {
  const view = levels.view(tree, [], 'top');

  assert.deepEqual(ids(view), ['paed', 'mental', 'driving']);
  assert.equal(view.selected, '');
});

test('should hide the subcategories filter when no top-level category is selected', () => {
  assert.deepEqual(levels.view(tree, [], 'sub'), { options: [], selected: '' });
  assert.deepEqual(levels.view(tree, undefined, 'sub'), { options: [], selected: '' });
});

test('should hide the subcategories filter when the selected top-level category has no children', () => {
  assert.deepEqual(levels.view(tree, ['driving'], 'sub'), { options: [], selected: '' });
});

test("should offer the selected top-level category's children only in API order when a visitor selects it", () => {
  const selection = levels.choose(tree, [], 'top', 'paed');
  const sub = levels.view(tree, selection, 'sub');

  assert.deepEqual(ids(sub), ['feeding', 'autism']);
  assert.equal(sub.selected, '', 'no subcategory is chosen yet');
  assert.equal(levels.view(tree, selection, 'top').selected, 'paed');
});

test('should send only the top-level id when a visitor selects a top-level category and no subcategory', () => {
  // The API widens a parent category to its subcategories' listings; the
  // filter's part is to send the parent alone.
  assert.deepEqual(levels.choose(tree, [], 'top', 'paed'), ['paed']);
});

test('should select only the subcategory and show its parent in the Top level filter when a visitor selects a subcategory', () => {
  const selection = levels.choose(tree, ['paed'], 'sub', 'autism');

  assert.deepEqual(selection, ['autism']);
  assert.equal(levels.view(tree, selection, 'top').selected, 'paed');
  assert.equal(levels.view(tree, selection, 'sub').selected, 'autism');
  assert.deepEqual(ids(levels.view(tree, selection, 'sub')), ['feeding', 'autism'], 'the siblings stay on offer');
});

test("should set the selection back to the parent when the subcategories filter's any option is chosen", () => {
  const selection = levels.choose(tree, ['autism'], 'sub', '');

  assert.deepEqual(selection, ['paed']);
  assert.equal(levels.view(tree, selection, 'top').selected, 'paed', 'the Top level filter still shows the parent');
  assert.equal(levels.view(tree, selection, 'sub').selected, '');
});

test("should keep the selected top-level category when the subcategories filter's any option is chosen with no subcategory selected", () => {
  assert.deepEqual(levels.choose(tree, ['paed'], 'sub', ''), ['paed']);
});

test("should replace a selected subcategory with a different top-level category and offer the new category's children with none selected", () => {
  const selection = levels.choose(tree, ['autism'], 'top', 'mental');
  const sub = levels.view(tree, selection, 'sub');

  assert.deepEqual(selection, ['mental']);
  assert.deepEqual(ids(sub), ['anxiety', 'depression']);
  assert.equal(sub.selected, '');
});

test("should clear the category selection when the Top level filter's any option is chosen", () => {
  assert.deepEqual(levels.choose(tree, ['autism'], 'top', ''), []);
});

test('should show both filters at their any option once the filters are cleared', () => {
  const before = levels.choose(tree, ['paed'], 'sub', 'autism');
  assert.equal(levels.view(tree, before, 'top').selected, 'paed');

  // Clear writes an empty selection for every Category filter on the page.
  const cleared = [];

  assert.equal(levels.view(tree, cleared, 'top').selected, '');
  assert.deepEqual(ids(levels.view(tree, cleared, 'top')), ['paed', 'mental', 'driving']);
  assert.deepEqual(levels.view(tree, cleared, 'sub'), { options: [], selected: '' }, 'with no top-level category the subcategories filter is hidden');
});

test('should show the any option in both filters when another Category filter leaves several categories selected under one parent', () => {
  const selection = ['feeding', 'autism'];
  const sub = levels.view(tree, selection, 'sub');

  assert.equal(levels.view(tree, selection, 'top').selected, '');
  assert.deepEqual(ids(sub), ['feeding', 'autism']);
  assert.equal(sub.selected, '');
});

test('should show the Top level any option and hide the subcategories filter when several categories under different parents are selected', () => {
  const selection = ['autism', 'mental'];

  assert.equal(levels.view(tree, selection, 'top').selected, '');
  assert.deepEqual(levels.view(tree, selection, 'sub'), { options: [], selected: '' });
});

test('should replace several selected categories with the one category chosen in either level', () => {
  assert.deepEqual(levels.choose(tree, ['autism', 'mental'], 'top', 'driving'), ['driving']);
  assert.deepEqual(levels.choose(tree, ['feeding', 'autism'], 'sub', 'feeding'), ['feeding']);
  assert.deepEqual(levels.choose(tree, ['feeding', 'autism'], 'sub', ''), ['paed']);
});

test('should never offer an inactive category at any level', () => {
  for (const level of ['all', 'top', 'sub']) {
    for (const selection of [[], ['paed'], ['retired']]) {
      const offered = ids(levels.view(tree, selection, level));

      assert.equal(offered.includes('retired'), false, `${level} with ${JSON.stringify(selection)}`);
      assert.equal(offered.includes('retired-sub'), false, `${level} with ${JSON.stringify(selection)}`);
    }
  }
});

test('should show the any option when the selection names an inactive category', () => {
  assert.equal(levels.view(tree, ['retired'], 'top').selected, '');
  assert.deepEqual(levels.view(tree, ['retired'], 'sub'), { options: [], selected: '' });
  assert.deepEqual(levels.view(tree, ['retired-child'], 'sub'), { options: [], selected: '' }, 'a child of an inactive parent has no top-level category to sit under');
});

test('should read ids as text and leave the given selection unchanged', () => {
  const numeric = levels.tree([
    { id: 1, name: 'One', parent_id: null },
    { id: 2, name: '', parent_id: 1 },
    { id: null, name: 'No id' },
    'not a category',
  ]);
  assert.deepEqual(numeric, [
    { id: '1', name: 'One', parentId: '' },
    { id: '2', name: '2', parentId: '1' },
  ]);
  assert.equal(levels.view(numeric, [2], 'top').selected, '1');

  const selection = ['autism'];
  levels.choose(tree, selection, 'sub', '');
  assert.deepEqual(selection, ['autism']);
});

test('should read the id, name and parent through the keys the filter config names', () => {
  const custom = levels.tree(
    [
      { uuid: 'p', title: 'Parent', parent_uuid: null, id: 'ignored', name: 'Ignored', parent_id: 'ignored' },
      { uuid: 'c', title: 'Child', parent_uuid: 'p' },
    ],
    { value: 'uuid', label: 'title', parent: 'parent_uuid' }
  );

  assert.deepEqual(custom, [
    { id: 'p', name: 'Parent', parentId: '' },
    { id: 'c', name: 'Child', parentId: 'p' },
  ]);
  assert.deepEqual(ids(levels.view(custom, ['p'], 'sub')), ['c']);
});

test('should fall back to id, name and parent_id when the config names no keys', () => {
  assert.deepEqual(levels.tree(items, {}), tree);
  assert.deepEqual(levels.tree(items, { value: '', label: undefined }), tree);
});

test('should replace the selection with the chosen option when it is not the one shown', () => {
  assert.deepEqual(levels.pick(tree, [], 'top', 'paed'), ['paed']);
  assert.deepEqual(levels.pick(tree, ['paed'], 'sub', 'autism'), ['autism']);
  assert.deepEqual(levels.pick(tree, ['autism'], 'sub', 'feeding'), ['feeding']);
});

test('should empty the selection when the Top level option already shown is chosen again', () => {
  assert.deepEqual(levels.pick(tree, ['paed'], 'top', 'paed'), []);
  assert.deepEqual(levels.pick(tree, ['autism'], 'top', 'paed'), [], 'the parent shown for a selected subcategory clears too');
});

test('should go back to the parent when the subcategory already shown is chosen again', () => {
  const selection = levels.pick(tree, ['autism'], 'sub', 'autism');

  assert.deepEqual(selection, ['paed']);
  assert.equal(levels.view(tree, selection, 'top').selected, 'paed');
});

test("should treat choosing a level's any option as clearing it", () => {
  assert.deepEqual(levels.pick(tree, ['paed'], 'top', ''), []);
  assert.deepEqual(levels.pick(tree, ['autism'], 'sub', ''), ['paed']);
  assert.deepEqual(levels.pick(tree, ['paed'], 'sub', ''), ['paed']);
});
