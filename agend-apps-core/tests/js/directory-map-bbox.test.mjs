/**
 * The Agend Map's viewport query (assets/js/directory-map.js, bboxParam()):
 * the `bbox` sent when the map is set to update as it moves stays within the
 * API's -180 to 180 longitude range however far the view reaches past the
 * date line.
 *
 * Run with: node --test agend-apps-core/tests/js/directory-map-bbox.test.mjs
 *
 * The script is an IIFE with no exports, so bboxParam() is cut out of its
 * source by name.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const script = readFileSync(new URL('../../assets/js/directory-map.js', import.meta.url), 'utf8');

function cutFunction(source, name) {
  const start = source.indexOf(`  function ${name}(`);
  assert.notEqual(start, -1, `${name} not found in directory-map.js`);
  let depth = 0;
  for (let i = source.indexOf('{', start); i < source.length; i += 1) {
    if (source[i] === '{') depth += 1;
    if (source[i] === '}') {
      depth -= 1;
      if (depth === 0) return source.slice(start, i + 1);
    }
  }
  throw new Error(`${name} has no closing brace`);
}

const bboxParam = new Function(`${cutFunction(script, 'bboxParam')}\nreturn bboxParam;`)();

test('should send the viewport unchanged when it lies within -180 to 180', () => {
  assert.equal(bboxParam(-5.61599, -47.87214, 180, 88.50586), '-5.61599,-47.87214,180.00000,88.50586');
});

test('should cut the east edge at 180 when the view reaches past the date line', () => {
  assert.equal(bboxParam(-1.58183, -45.08904, 182.90039, 84.63867), '-1.58183,-45.08904,180.00000,84.63867');
});

test('should cut the west edge at -180 when the view reaches back past the date line', () => {
  assert.equal(bboxParam(72, 50, -100, -200), '72.00000,50.00000,-100.00000,-180.00000');
});

test('should move the box onto the copy of the world its centre is on when the centre lies past 180', () => {
  assert.equal(bboxParam(72, 50, 260, 160), '72.00000,50.00000,-100.00000,-180.00000');
});

test('should move the box onto the copy of the world its centre is on when the centre lies past -180', () => {
  assert.equal(bboxParam(10, -30, -160, -260), '10.00000,-30.00000,180.00000,100.00000');
});

test('should send the whole world when the view is wider than the world', () => {
  assert.equal(bboxParam(80, -80, 300, -300), '80.00000,-80.00000,180.00000,-180.00000');
});
