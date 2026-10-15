/**
 * The Agend Map's pin details (assets/js/directory-map.js, popupContent()):
 * the "Show location name" control adds the name of the location the pin
 * marks, and leaves the details unchanged when off.
 *
 * Run with: node --test agend-apps-core/tests/js/directory-map-popup.test.mjs
 *
 * The script is an IIFE with no exports, so popupContent() and the helpers it
 * calls are cut out of its source by name and run against a minimal fake
 * `document`.
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

class FakeNode {
  constructor(tag) {
    this.tag = tag;
    this.className = '';
    this.children = [];
    this.text = '';
  }

  set textContent(value) {
    this.text = value;
  }

  appendChild(child) {
    this.children.push(child);
    return child;
  }

  addEventListener() {}

  find(className) {
    if (this.className === className) return this;
    for (const child of this.children) {
      const found = child.find(className);
      if (found) return found;
    }
    return null;
  }
}

const helpers = ['el', 'formatDistance', 'safeUrl', 'validPoint', 'addressOf', 'detailHref', 'directionsHref', 'popupContent']
  .map((name) => cutFunction(script, name))
  .join('\n');
const popupContent = new Function('document', `${helpers}\nreturn popupContent;`)({
  createElement: (tag) => new FakeNode(tag),
});

function cfg(popup) {
  return {
    detailBase: '',
    popup: {
      logo: false,
      category: false,
      address: true,
      distance: false,
      rating: false,
      linkText: 'View details',
      directions: '',
      directionsText: 'Get directions',
      ...popup,
    },
  };
}

const marker = { name: 'Jordan Lee', slug: '', distance_km: null };
const namedLocation = { name: 'Harbour Clinic', city: 'Sydney', state: 'NSW', latitude: -33.86, longitude: 151.2 };

test('should show the location name when the control is on and the location has one', () => {
  const box = popupContent(cfg({ locationName: true }), null, marker, namedLocation);

  assert.equal(box.find('agend-map-popup__location-name')?.text, 'Harbour Clinic');
});

test('should show no location name line when the control is on and the location has no name', () => {
  const box = popupContent(cfg({ locationName: true }), null, marker, { ...namedLocation, name: null });

  assert.equal(box.find('agend-map-popup__location-name'), null);
});

test('should leave the pin details unchanged when the control is off', () => {
  const off = popupContent(cfg({ locationName: false }), null, marker, namedLocation);
  const unset = popupContent(cfg({}), null, marker, { ...namedLocation, name: undefined });

  assert.equal(off.find('agend-map-popup__location-name'), null);
  assert.deepEqual(off, unset);
});
