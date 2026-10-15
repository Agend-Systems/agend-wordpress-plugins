/**
 * The overflow control of a capped Agend Field or Agend Pills list
 * (assets/js/record-fields.js): it reveals the held-back items in place and
 * hides them again, and a click on it never follows the card link around it.
 *
 * Run with: node --test agend-apps-core/tests/js/record-fields-rest.test.mjs
 *
 * The script runs whole against a minimal fake `window` and `document`; the
 * one document click listener it installs is driven with fake events.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const script = readFileSync(new URL('../../assets/js/record-fields.js', import.meta.url), 'utf8');

class FakeElement {
  constructor(attributes = {}, children = []) {
    this.attributes = { ...attributes };
    this.hidden = 'hidden' in attributes;
    this.textContent = '';
    this.parent = null;
    this.children = [];
    children.forEach((child) => this.append(child));
  }

  append(child) {
    child.parent = this;
    this.children.push(child);
    return child;
  }

  getAttribute(name) {
    return name in this.attributes ? this.attributes[name] : null;
  }

  setAttribute(name, value) {
    this.attributes[name] = String(value);
  }

  matches(selector) {
    return selector.slice(1, -1) in this.attributes;
  }

  closest(selector) {
    for (let node = this; node; node = node.parent) {
      if (node.matches(selector)) return node;
    }
    return null;
  }

  querySelectorAll(selector) {
    const found = [];
    const walk = (node) => {
      node.children.forEach((child) => {
        if (child.matches(selector)) found.push(child);
        walk(child);
      });
    };
    walk(this);
    return found;
  }
}

function loadScript() {
  const listeners = [];
  const document = {
    readyState: 'complete',
    addEventListener: (type, handler, capture) => listeners.push({ type, handler, capture }),
    querySelectorAll: () => [],
  };
  const window = {};
  new Function('window', 'document', script)(window, document);
  return { listeners, window };
}

// A whole-card link holding a capped list: two items shown, two held back.
function card() {
  const restA = new FakeElement({ 'data-agend-rest': '', hidden: '' });
  const restB = new FakeElement({ 'data-agend-rest': '', hidden: '' });
  const button = new FakeElement({
    'data-agend-rest-toggle': '',
    'aria-expanded': 'false',
    'data-agend-more': '+2',
    'data-agend-less': 'Show less',
  });
  button.textContent = '+2';
  const group = new FakeElement({ 'data-agend-rest-group': '' }, [new FakeElement(), new FakeElement(), restA, restB, button]);
  const link = new FakeElement({ href: '/listing/jordan-lee/' }, [group]);
  return { link, group, rest: [restA, restB], button };
}

function click(listener, target) {
  const event = {
    target,
    defaultPrevented: false,
    propagationStopped: false,
    preventDefault() {
      this.defaultPrevented = true;
    },
    stopPropagation() {
      this.propagationStopped = true;
    },
  };
  listener.handler(event);
  return event;
}

const { listeners } = loadScript();
const clickListener = listeners.find((listener) => listener.type === 'click');

test('should listen for clicks on the document in the capture phase so every card, including ones inserted later, responds', () => {
  assert.ok(clickListener, 'a document click listener is installed');
  assert.equal(clickListener.capture, true);
});

test('should reveal every held-back item in place when the control is activated', () => {
  const { rest, button } = card();

  click(clickListener, button);

  assert.deepEqual(rest.map((item) => item.hidden), [false, false]);
  assert.equal(button.getAttribute('aria-expanded'), 'true');
  assert.equal(button.textContent, 'Show less');
});

test('should restore the shortened list when the expanded control is activated again', () => {
  const { rest, button } = card();

  click(clickListener, button);
  click(clickListener, button);

  assert.deepEqual(rest.map((item) => item.hidden), [true, true]);
  assert.equal(button.getAttribute('aria-expanded'), 'false');
  assert.equal(button.textContent, '+2');
});

test('should not follow the card link or reach card handlers when the control is activated inside a linked card', () => {
  const { button } = card();

  const event = click(clickListener, button);

  assert.equal(event.defaultPrevented, true);
  assert.equal(event.propagationStopped, true);
});

test('should leave any other click inside the card alone', () => {
  const { group } = card();

  const event = click(clickListener, group.children[0]);

  assert.equal(event.defaultPrevented, false);
  assert.equal(event.propagationStopped, false);
});
