/**
 * Agend catalogue filters: builds the controls for Agend Filter widgets placed
 * in a catalogue's filter template.
 *
 * Each widget renders an empty shell carrying its configuration. This runtime
 * finds those shells inside a catalogue, builds the control, and writes the
 * catalogue's own filter state before asking it to reload, so a filter widget
 * never needs to know which catalogue it is driving.
 *
 * Exposed as window.agendFilters.build(scope, options).
 */
(function () {
  'use strict';

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null) {
      node.textContent = String(text);
    }
    return node;
  }

  // The server draws each control disabled at first paint (render/filter.php)
  // so nothing pops into the page a request later. Building a control means
  // adopting that node, filling it, and enabling it; a node is created only
  // when the shell has none, which is the case for markup older than the
  // stand-in.
  function adopt(shell, selector, tag, className) {
    var node = shell.querySelector(selector);
    if (!node) {
      node = el(tag, className);
      shell.appendChild(node);
    }
    return node;
  }

  function ready(node) {
    node.disabled = false;
    node.removeAttribute('aria-busy');
    node.classList.remove('agend-filter__placeholder');
    Array.prototype.forEach.call(node.querySelectorAll('[disabled]'), function (child) {
      child.disabled = false;
    });
    return node;
  }

  // Controls are built once and rebuilt only on Clear, so a control whose
  // options follow another filter's choice (a Subcategories of selected
  // filter) registers here and is redrawn whenever a filter writes the state
  // it reads. One entry per shell, so a rebuild replaces rather than adds.
  var watchers = [];

  function watch(state, shell, redraw) {
    watchers = watchers.filter(function (watcher) {
      return watcher.shell !== shell;
    });
    watchers.push({ state: state, shell: shell, redraw: redraw });
  }

  function changed(state) {
    watchers.slice().forEach(function (watcher) {
      if (watcher.state === state) {
        watcher.redraw();
      }
    });
  }

  // A filter writes either a list or a single value, and clearing it must
  // restore the shape the catalogue's query builder expects.
  function applyValue(state, cfg, values) {
    if (cfg.mode === 'map') {
      // A custom field filter writes one key of a map, so several of them can
      // sit on the same state key without overwriting each other.
      state[cfg.state] = state[cfg.state] || {};
      state[cfg.state][cfg.fieldKey] = values.slice();
    } else if (cfg.mode === 'array') {
      state[cfg.state] = values.slice();
    } else {
      state[cfg.state] = values.length ? values[0] : '';
    }
    state.page = 1;
    changed(state);
  }

  function applyBounds(state, cfg, bounds) {
    state[cfg.state] = state[cfg.state] || {};
    state[cfg.state][cfg.fieldKey] = bounds;
    state.page = 1;
  }

  // "Any" rather than "All" so a singular label still reads correctly, and
  // matching the editor preview the widget draws server-side.
  function anyLabel(cfg) {
    return cfg.anyLabel || ('Any ' + (cfg.label || ''));
  }

  function sameSelection(a, b) {
    return a.length === b.length && a.every(function (v, i) { return v === b[i]; });
  }

  // Listeners are attached once per node; a rebuild (reset) only clears the
  // value, so the node the designer styled is never replaced.
  function once(node, event, handler) {
    var key = 'agendFilterBound' + event;
    if (node.dataset[key] === '1') {
      return;
    }
    node.dataset[key] = '1';
    node.addEventListener(event, handler);
  }

  function buildSearch(shell, cfg, ctx) {
    var input = adopt(shell, 'input[type="search"]', 'input');
    input.type = 'search';
    input.placeholder = cfg.placeholder || cfg.label || '';
    input.value = '';
    var timer;
    once(input, 'input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        applyValue(ctx.state, cfg, input.value.trim() ? [input.value.trim()] : []);
        ctx.reload();
      }, 300);
    });
    ready(input);
  }

  function buildDate(shell, cfg, ctx) {
    var input = adopt(shell, 'input[type="date"]', 'input');
    input.type = 'date';
    input.value = '';
    if (cfg.placeholder) {
      input.setAttribute('aria-label', cfg.placeholder);
    }
    once(input, 'change', function () {
      applyValue(ctx.state, cfg, input.value ? [input.value] : []);
      ctx.reload();
    });
    ready(input);
  }

  function buildRange(shell, cfg, ctx, facet) {
    var wrap = adopt(shell, '.agend-filter__range', 'div', 'agend-filter__range');
    var inputs = wrap.querySelectorAll('input[type="number"]');
    var min = inputs[0] || wrap.appendChild(el('input'));
    var max = inputs[1] || wrap.appendChild(el('input'));
    min.type = 'number';
    max.type = 'number';
    min.value = '';
    max.value = '';
    min.placeholder = 'Min';
    max.placeholder = 'Max';
    // The facet reports the bounds that exist, so the control does not need
    // the author to guess them.
    if (facet && facet.min !== null && facet.min !== undefined) {
      min.min = String(facet.min);
      max.min = String(facet.min);
      min.placeholder = 'Min (' + facet.min + ')';
    }
    if (facet && facet.max !== null && facet.max !== undefined) {
      min.max = String(facet.max);
      max.max = String(facet.max);
      max.placeholder = 'Max (' + facet.max + ')';
    }

    function push() {
      var bounds = {};
      if (min.value !== '') {
        bounds.min = min.value;
      }
      if (max.value !== '') {
        bounds.max = max.value;
      }
      applyBounds(ctx.state, cfg, bounds);
      ctx.reload();
    }

    var timer;
    [min, max].forEach(function (input) {
      once(input, 'input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(push, 400);
      });
    });
    ready(wrap);
  }

  // -- Range brackets ------------------------------------------------------
  //
  // Preset brackets ("Under 10,000", "10,000 to 30,000", "Over 30,000") that
  // fill in the same {min, max} the Number range control writes. A bracket is
  // { label, min, max }; a bound is a number, or null for an open end, and
  // both ends are inclusive. Choosing a bracket writes those bounds as strings
  // exactly as typing them would, so the catalogue's request, Reset and
  // pagination cannot tell the two controls apart.
  //
  // The helpers up to buildRangeBrackets() are pure and state the same rules
  // as includes/records/filters.php (agend_apps_records_filter_range_brackets_*),
  // which validates the author's own list on the server. Keep the two in step.

  var BRACKET_BOUND_LIMIT = 1e15;

  // A bound as a number; null for an open end (absent, null or blank);
  // undefined for a value that is not a usable plain number.
  function toBound(value) {
    if (value === null || value === undefined) {
      return null;
    }
    if (typeof value === 'string') {
      value = value.trim();
      if (value === '') {
        return null;
      }
      if (!/^-?\d+(\.\d+)?$/.test(value)) {
        return undefined;
      }
      value = Number(value);
    } else if (typeof value !== 'number') {
      return undefined;
    }
    return isFinite(value) && Math.abs(value) <= BRACKET_BOUND_LIMIT ? value : undefined;
  }

  // The usable brackets in a list: a label, at least one bound, bounds that
  // are numbers, and a minimum not above the maximum. Anything else is dropped.
  function normaliseBrackets(list) {
    var out = [];
    (Array.isArray(list) ? list : []).forEach(function (entry) {
      if (!entry || typeof entry.label !== 'string') {
        return;
      }
      var label = entry.label.replace(/<\/?[a-z][^>]*>/gi, '').replace(/\s+/g, ' ').trim();
      var min = toBound(entry.min);
      var max = toBound(entry.max);
      if (!label || min === undefined || max === undefined) {
        return;
      }
      if (min === null && max === null) {
        return;
      }
      if (min !== null && max !== null && min > max) {
        return;
      }
      out.push({ label: label, min: min, max: max });
    });
    return out;
  }

  // Which brackets to offer. "agend" takes the facet's presets and falls back
  // to the widget's own list when the facet has none; "custom" takes the
  // widget's list and never Agend's. An empty answer means the filter has
  // nothing to offer.
  function resolveBrackets(source, presets, custom) {
    var fromAgend = source === 'custom' ? [] : normaliseBrackets(presets);
    if (fromAgend.length) {
      return { origin: 'agend', brackets: fromAgend };
    }
    var own = normaliseBrackets(custom);
    return own.length ? { origin: 'custom', brackets: own } : { origin: 'none', brackets: [] };
  }

  // The {min, max} a bracket writes: strings, and only the bounds it has.
  function boundsOf(bracket) {
    var bounds = {};
    if (bracket.min !== null) {
      bounds.min = String(bracket.min);
    }
    if (bracket.max !== null) {
      bounds.max = String(bracket.max);
    }
    return bounds;
  }

  // The index of the bracket whose bounds are exactly these, or -1. Both ends
  // must agree, so a manual 10000 to 25000 does not match 10000 to 30000, and
  // an empty range matches nothing. This is also what a chip that names an
  // active range would call to show a bracket's label.
  function findBracket(brackets, bounds) {
    var min = toBound(bounds && bounds.min);
    var max = toBound(bounds && bounds.max);
    if (min === undefined || max === undefined) {
      return -1;
    }
    for (var i = 0; i < brackets.length; i++) {
      if (brackets[i].min === min && brackets[i].max === max) {
        return i;
      }
    }
    return -1;
  }

  function sameBounds(a, b) {
    return toBound(a && a.min) === toBound(b && b.min) && toBound(a && a.max) === toBound(b && b.max);
  }

  function isEmptyBounds(bounds) {
    return toBound(bounds && bounds.min) === null && toBound(bounds && bounds.max) === null;
  }

  var bracketGroups = 0;

  // The control is drawn in one of three displays, all single choice: the
  // first choice is "Any", which clears the range.
  //
  // Restoring a selection. When the control is built, the range already in the
  // catalogue state decides what shows as selected, so a range put there
  // before the controls are built (a shared link, a rebuild after Reset) is
  // reflected rather than ignored. If its bounds match a bracket exactly that
  // bracket is selected. If they match none (a hand-edited or shared range, or
  // a bracket Agend has since changed) "Any" is shown, but the state is left
  // alone: the results stay filtered by that range, and choosing "Any" clears
  // it. The control never rewrites the state to suit itself.
  function buildRangeBrackets(shell, slot, cfg, ctx, facet) {
    var opts = cfg.rangeBrackets || {};
    var resolved = resolveBrackets(opts.source, facet && facet.presets, opts.custom);
    if (!resolved.brackets.length) {
      // Nothing to choose from: hide the filter rather than leave a control
      // that cannot do anything, as the value-list controls do. Hidden, not
      // removed, so a later rebuild can show it again.
      shell.hidden = true;
      return;
    }

    var anyText = opts.anyLabel || 'Any';
    var display = opts.display === 'buttons' || opts.display === 'radio' ? opts.display : 'select';
    var choices = [{ label: anyText, bracket: null }].concat(resolved.brackets.map(function (bracket) {
      return { label: bracket.label, bracket: bracket };
    }));
    var host = display === 'select'
      ? adopt(slot, 'select', 'select')
      : adopt(slot, '.agend-filter__options', 'div', 'agend-filter__options');
    if (!host.getAttribute('aria-label') && cfg.label) {
      host.setAttribute('aria-label', cfg.label);
    }

    function currentBounds() {
      var map = ctx.state[cfg.state];
      return (map && map[cfg.fieldKey]) || {};
    }

    // Writes the choice's bounds as the Number range control would, and asks
    // the catalogue to reload only when they differ from what is already set.
    // Handlers are bound once per node and read the current build through
    // host.agendBrackets, so a rebuild (Reset) never leaves one painting an
    // earlier build.
    function choose(index) {
      var live = host.agendBrackets;
      // Fresh bounds each time: the state must not share an object with the
      // choice list.
      var next = live.choices[index].bracket ? boundsOf(live.choices[index].bracket) : {};
      var changed = !sameBounds(currentBounds(), next);
      if (changed) {
        applyBounds(ctx.state, cfg, next);
      }
      live.paint(index, false);
      if (changed) {
        ctx.reload();
      }
    }

    var model = { choices: choices, selected: 0, paint: null };
    host.agendBrackets = model;

    if (display === 'select') {
      host.innerHTML = '';
      choices.forEach(function (choice, index) {
        host.appendChild(new Option(choice.label, String(index)));
      });
      // A selected "Any" cannot be chosen again, so a range no bracket matches
      // would be impossible to clear from the dropdown. Showing it on a hidden
      // stand-in "Any" keeps the real one a change away.
      model.paint = function (index, held) {
        var old = host.querySelector('[data-agend-held]');
        if (old) {
          old.parentNode.removeChild(old);
        }
        model.selected = index;
        if (held) {
          var stand = new Option(anyText, 'held');
          stand.hidden = true;
          stand.disabled = true;
          stand.setAttribute('data-agend-held', '');
          host.appendChild(stand);
          host.value = 'held';
        } else {
          host.value = String(index);
        }
      };
      once(host, 'change', function () {
        choose(Number(host.value));
      });
    } else if (display === 'buttons') {
      // The stand-in already holds the "Any" pill; it stays the first button
      // so the node a designer styled is the one that remains.
      var existing = host.querySelector('.agend-filter__button');
      host.innerHTML = '';
      var buttons = choices.map(function (choice, index) {
        var button = index === 0 && existing ? existing : el('button', 'agend-filter__button');
        button.textContent = choice.label;
        button.type = 'button';
        button.disabled = false;
        button.agendBracketIndex = index;
        host.appendChild(button);
        // Choosing the bracket already selected clears it, as the Buttons
        // presentation does for any other value.
        once(button, 'click', function () {
          var live = host.agendBrackets;
          choose(button.agendBracketIndex !== 0 && live.selected === button.agendBracketIndex ? 0 : button.agendBracketIndex);
        });
        return button;
      });
      model.paint = function (index) {
        model.selected = index;
        buttons.forEach(function (button, i) {
          button.className = 'agend-filter__button' + (i === index ? ' is-active' : '');
          button.setAttribute('aria-pressed', i === index ? 'true' : 'false');
        });
      };
    } else {
      host.innerHTML = '';
      bracketGroups += 1;
      var inputs = choices.map(function (choice, index) {
        var label = el('label', 'agend-filter__option');
        var input = el('input');
        input.type = 'radio';
        input.name = 'agend-filter-brackets-' + bracketGroups;
        input.value = String(index);
        // click, not change: it also fires on a radio that is already checked,
        // which is how a range no bracket matches (shown as "Any") is cleared.
        input.addEventListener('click', function () {
          choose(index);
        });
        label.appendChild(input);
        label.appendChild(el('span', null, choice.label));
        host.appendChild(label);
        return input;
      });
      model.paint = function (index) {
        model.selected = index;
        inputs.forEach(function (input, i) {
          input.checked = i === index;
        });
      };
    }

    var bounds = currentBounds();
    var index = findBracket(resolved.brackets, bounds) + 1;
    model.paint(index, index === 0 && !isEmptyBounds(bounds));
    ready(host);
  }

  function buildSelect(shell, cfg, ctx, values) {
    var select = adopt(shell, 'select', 'select');
    // The option list is the one thing that changes between the stand-in
    // and the live control, and again on a rebuild.
    select.innerHTML = '';
    select.appendChild(new Option(anyLabel(cfg), ''));
    values.forEach(function (entry, index) {
      select.appendChild(new Option(entry.label, String(index)));
    });
    select.agendFilterValues = values;
    once(select, 'change', function () {
      var current = select.agendFilterValues || [];
      var picked = select.value === '' ? [] : current[Number(select.value)].value;
      applyValue(ctx.state, cfg, picked);
      ctx.reload();
    });
    ready(select);
  }

  function buildCheckboxes(shell, cfg, ctx, values) {
    var list = adopt(shell, '.agend-filter__options', 'div', 'agend-filter__options');
    list.innerHTML = '';
    var selected = [];
    values.forEach(function (entry) {
      var label = el('label', 'agend-filter__option');
      var box = el('input');
      box.type = 'checkbox';
      box.addEventListener('change', function () {
        selected = [];
        Array.prototype.forEach.call(list.querySelectorAll('input:checked'), function (checked) {
          selected = selected.concat(values[Number(checked.getAttribute('data-index'))].value);
        });
        applyValue(ctx.state, cfg, selected);
        ctx.reload();
      });
      box.setAttribute('data-index', String(values.indexOf(entry)));
      label.appendChild(box);
      label.appendChild(el('span', null, entry.label));
      list.appendChild(label);
    });
    ready(list);
  }

  function buildButtons(shell, cfg, ctx, values) {
    var list = adopt(shell, '.agend-filter__options', 'div', 'agend-filter__options');
    // The stand-in already holds the "Any" pill; it is reused as the first
    // button so the pill a designer styled is the one that stays. Its click
    // handler is bound once and reads the list's current build through
    // `list.agendFilter`, so a rebuild (reset) does not leave it painting the
    // previous build's buttons.
    var existing = list.querySelector('.agend-filter__button');
    list.innerHTML = '';

    var state = { current: [], buttons: [] };
    list.agendFilter = state;

    state.paint = function () {
      state.buttons.forEach(function (pair) {
        var on = sameSelection(state.current, pair.value);
        pair.node.className = 'agend-filter__button' + (on ? ' is-active' : '');
        pair.node.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    };

    function bind(node, value) {
      once(node, 'click', function () {
        var live = list.agendFilter;
        // Clicking the active choice clears it, so buttons behave like a
        // toggle set rather than a dead end.
        live.current = sameSelection(live.current, value) ? [] : value.slice();
        applyValue(ctx.state, cfg, live.current);
        live.paint();
        ctx.reload();
      });
    }

    var any = existing || el('button', 'agend-filter__button');
    any.textContent = anyLabel(cfg);
    any.type = 'button';
    any.disabled = false;
    state.buttons.push({ node: any, value: [] });
    list.appendChild(any);
    bind(any, []);

    values.forEach(function (entry) {
      var button = el('button', 'agend-filter__button', entry.label);
      button.type = 'button';
      state.buttons.push({ node: button, value: entry.value });
      list.appendChild(button);
      bind(button, entry.value);
    });

    state.paint();
    ready(list);
  }

  // -- Category levels ------------------------------------------------------

  function categoryLevels() {
    return window.agendFilters && window.agendFilters.categoryLevels;
  }

  // Options carry the category id as their value, not an index, because the
  // list is redrawn as the selection changes.
  function drawLevelSelect(slot, cfg, view, pick) {
    var select = adopt(slot, 'select', 'select');
    select.innerHTML = '';
    select.appendChild(new Option(anyLabel(cfg), ''));
    view.options.forEach(function (option) {
      select.appendChild(new Option(option.name, option.id));
    });
    select.value = view.selected;
    select.agendLevelPick = pick;
    once(select, 'change', function () {
      select.agendLevelPick(select.value);
    });
    ready(select);
  }

  function drawLevelButtons(slot, cfg, view, pick) {
    var list = adopt(slot, '.agend-filter__options', 'div', 'agend-filter__options');
    // The stand-in's "Any" pill is kept across redraws, as buildButtons()
    // keeps it, so the pill a designer styled is the one that stays.
    var any = list.agendLevelAny || list.querySelector('.agend-filter__button') || el('button', 'agend-filter__button');
    var kept = list.agendLevelButtons || {};
    var focused = document.activeElement;
    var hadFocus = !!focused && focused !== list && list.contains(focused);
    list.agendLevelAny = any;
    list.agendLevelPick = pick;

    // Buttons are keyed by category id and reused while still offered, so a
    // redraw after a choice repaints the nodes a keyboard user is on rather
    // than replacing them.
    var entries = [{ node: any, id: '' }];
    var next = {};
    view.options.forEach(function (option) {
      var button = kept[option.id] || el('button', 'agend-filter__button');
      button.type = 'button';
      button.textContent = option.name;
      next[option.id] = button;
      entries.push({ node: button, id: option.id });
    });
    list.agendLevelButtons = next;

    var wanted = entries.map(function (entry) {
      return entry.node;
    });
    Array.prototype.slice.call(list.children).forEach(function (child) {
      if (wanted.indexOf(child) === -1) {
        list.removeChild(child);
      }
    });
    // Only a node out of place is moved, because moving a node takes focus
    // off it.
    wanted.forEach(function (node, index) {
      if (list.children[index] !== node) {
        list.insertBefore(node, list.children[index] || null);
      }
    });

    any.textContent = anyLabel(cfg);
    any.disabled = false;
    any.type = 'button';
    entries.forEach(function (entry) {
      var on = entry.id === view.selected;
      entry.node.className = 'agend-filter__button' + (on ? ' is-active' : '');
      entry.node.setAttribute('aria-pressed', on ? 'true' : 'false');
      once(entry.node, 'click', function () {
        list.agendLevelPick(entry.id);
      });
    });
    ready(list);

    if (hadFocus && document.activeElement !== focused) {
      (list.contains(focused) ? focused : any).focus();
    }
  }

  // A Top level or Subcategories of selected filter. Both write the shared
  // categories selection; what each offers and shows is derived from it on
  // every change, so the subcategory list follows the top-level choice.
  function buildCategoryLevel(shell, slot, cfg, ctx, nodes) {
    var levels = categoryLevels();
    var which = levels.level(cfg.categoryLevel);

    function pick(id) {
      applyValue(ctx.state, cfg, levels.pick(nodes, ctx.state[cfg.state], which, id));
      ctx.reload();
    }

    function draw() {
      var view = levels.view(nodes, ctx.state[cfg.state], which);
      if (!view.options.length) {
        shell.hidden = true;
        return;
      }
      shell.hidden = false;
      if (cfg.control === 'buttons') {
        drawLevelButtons(slot, cfg, view, pick);
      } else {
        drawLevelSelect(slot, cfg, view, pick);
      }
    }

    watch(ctx.state, shell, draw);
    draw();
  }

  // -- Location -------------------------------------------------------------

  // The directory's map defaults, fetched once per page and shared with the
  // Agend Map widget (assets/js/directory-map.js reads the same promise), so
  // a filter and a map on one page cost one request between them.
  function mapSettings(ctx) {
    var shared = (window.agendDirectory = window.agendDirectory || {});
    if (!shared.mapSettings) {
      shared.mapSettings = ctx.apiGet('/directory/map-settings', {})
        .then(function (body) {
          return (body && body.data && typeof body.data === 'object') ? body.data : null;
        })
        .catch(function () {
          return null;
        });
    }
    return shared.mapSettings;
  }

  function radiusText(km) {
    return 'Within ' + km + ' km';
  }

  // Wording matches the Directory app's own toolbar, so a visitor meets the
  // same messages on either.
  var GEO_MESSAGES = {
    empty: 'Enter a suburb, postcode or address.',
    notFound: 'We could not find that place. Try a suburb or postcode.',
    busy: 'Place search is busy. Please try again in a moment.',
    failed: 'Place search failed. Please try again in a moment.',
    unsupported: 'Your browser does not support finding your location.',
    denied: 'Location permission was denied. Update your browser settings to use this feature.',
    timeout: 'Could not get your location. Please try again.',
    unavailable: 'Your location is unavailable. Check that Location Services are turned on, then try again.',
  };

  // Nominatim, behind the gateway, allows about one lookup a second.
  var GEOCODE_MIN_INTERVAL_MS = 1500;

  function buildLocation(shell, cfg, ctx) {
    var opts = cfg.location || {};
    var wrap = adopt(shell, '.agend-filter__location', 'div', 'agend-filter__location');
    var searchRow = wrap.querySelector('.agend-filter__location-row--search');
    var input = wrap.querySelector('.agend-filter__location-input');
    var searchBtn = wrap.querySelector('.agend-filter__location-search');
    var locateBtn = wrap.querySelector('.agend-filter__location-locate');
    var radiusSel = wrap.querySelector('.agend-filter__location-radius');
    var status = wrap.querySelector('.agend-filter__location-status');
    var chip = wrap.querySelector('.agend-filter__location-chip');
    var chipText = wrap.querySelector('.agend-filter__location-chip-text');
    var clearBtn = wrap.querySelector('.agend-filter__location-clear');
    if (!status || !chip || !chipText || !clearBtn) {
      // Markup older than this control: nothing to adopt, and inventing it
      // here would drift from the server-drawn version.
      return;
    }

    var radii = Array.isArray(opts.radii) && opts.radii.length ? opts.radii : [15];
    var lastLookup = 0;

    function currentRadius() {
      var value = radiusSel ? Number(radiusSel.value) : Number(opts.radius);
      return isFinite(value) && value > 0 ? value : radii[0];
    }

    function say(message) {
      status.textContent = message || '';
      status.hidden = !message;
    }

    function paintChip() {
      var near = ctx.state[cfg.state];
      if (!near) {
        chip.hidden = true;
        chipText.textContent = '';
        return;
      }
      chipText.textContent = radiusText(near.radius) + ' of ' + near.label;
      chip.hidden = false;
    }

    function busy(on) {
      [input, searchBtn, locateBtn].forEach(function (node) {
        if (node) {
          node.disabled = on;
        }
      });
      wrap.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    function apply(lat, lng, label) {
      ctx.state[cfg.state] = { lat: lat, lng: lng, radius: currentRadius(), label: label };
      // A map viewport and a point cannot be searched together; the point
      // is the newer question, so it wins.
      ctx.state.bbox = '';
      ctx.state.page = 1;
      say('');
      paintChip();
      ctx.reload();
    }

    function lookup() {
      var text = input ? input.value.trim() : '';
      if (!text) {
        say(GEO_MESSAGES.empty);
        return;
      }
      var now = Date.now();
      if (now - lastLookup < GEOCODE_MIN_INTERVAL_MS) {
        return;
      }
      lastLookup = now;
      var query = opts.region ? text + ', ' + opts.region : text;
      busy(true);
      ctx.apiGet('/directory/geocode', { q: query.slice(0, 200) })
        .then(function (body) {
          busy(false);
          var point = body && body.data;
          if (point && isFinite(Number(point.latitude)) && isFinite(Number(point.longitude))) {
            apply(Number(point.latitude), Number(point.longitude), text);
            return;
          }
          var code = body && body.data && body.data.status_code;
          if (code === 404) {
            say(GEO_MESSAGES.notFound);
          } else if (code === 403) {
            // The account does not include place search after all; the
            // button still works.
            if (searchRow) {
              searchRow.hidden = true;
            }
            say('');
          } else if (code === 429 || code === 503) {
            say(GEO_MESSAGES.busy);
          } else {
            say(GEO_MESSAGES.failed);
          }
        })
        .catch(function () {
          busy(false);
          say(GEO_MESSAGES.failed);
        });
    }

    function locate() {
      if (!navigator.geolocation) {
        say(GEO_MESSAGES.unsupported);
        return;
      }
      busy(true);
      var onSuccess = function (position) {
        busy(false);
        if (input) {
          input.value = '';
        }
        apply(position.coords.latitude, position.coords.longitude, 'your location');
      };
      var onError = function (error, retried) {
        // A quick, low-accuracy fix fails on some desktops; one precise
        // attempt is worth making before giving up.
        if (error && error.code === 2 && !retried) {
          navigator.geolocation.getCurrentPosition(onSuccess, function (again) {
            onError(again, true);
          }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
          return;
        }
        busy(false);
        if (error && error.code === 1) {
          say(GEO_MESSAGES.denied);
        } else if (error && error.code === 3) {
          say(GEO_MESSAGES.timeout);
        } else {
          say(GEO_MESSAGES.unavailable);
        }
      };
      navigator.geolocation.getCurrentPosition(onSuccess, function (error) {
        onError(error, false);
      }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
    }

    // A rebuild (reset) starts from nothing.
    if (input) {
      input.value = '';
    }
    say('');
    paintChip();

    if (input) {
      once(input, 'keydown', function (e) {
        if (e.key === 'Enter') {
          e.preventDefault();
          lookup();
        }
      });
    }
    if (searchBtn) {
      once(searchBtn, 'click', lookup);
    }
    if (locateBtn) {
      once(locateBtn, 'click', locate);
    }
    if (radiusSel) {
      once(radiusSel, 'change', function () {
        var near = ctx.state[cfg.state];
        if (near) {
          near.radius = currentRadius();
          ctx.state.page = 1;
          paintChip();
          ctx.reload();
        }
      });
    }
    once(clearBtn, 'click', function () {
      ctx.state[cfg.state] = null;
      ctx.state.page = 1;
      if (input) {
        input.value = '';
      }
      say('');
      paintChip();
      ctx.reload();
    });

    ready(wrap);

    // Without the account's place search the box cannot answer, so it is
    // hidden rather than left to fail on every submit.
    if (searchRow && opts.search) {
      mapSettings(ctx).then(function (settings) {
        if (settings && settings.geocoding_available === false) {
          searchRow.hidden = true;
          if (!locateBtn) {
            shell.hidden = true;
          }
        }
      });
    }
  }

  // -- List / Map switch ----------------------------------------------------

  function buildView(shell, cfg, ctx) {
    var group = adopt(shell, '.agend-filter__view', 'div', 'agend-filter__options agend-filter__view');
    var buttons = group.querySelectorAll('[data-agend-view]');
    var initial = (cfg.view && cfg.view.default) === 'map' ? 'map' : 'list';

    function paint(view) {
      Array.prototype.forEach.call(buttons, function (button) {
        var on = button.getAttribute('data-agend-view') === view;
        button.className = 'agend-filter__button' + (on ? ' is-active' : '');
        button.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    function choose(view) {
      ctx.state[cfg.state] = view;
      paint(view);
      if (ctx.setView) {
        ctx.setView(view);
      }
    }

    Array.prototype.forEach.call(buttons, function (button) {
      once(button, 'click', function () {
        choose(button.getAttribute('data-agend-view'));
      });
    });

    // The view survives a reset: clearing filters is about results, not
    // about which way the visitor chose to read them.
    choose(ctx.state[cfg.state] === 'map' || ctx.state[cfg.state] === 'list' ? ctx.state[cfg.state] : initial);
    ready(group);
  }

  // Endpoint-backed lists are per-account data, so they are fetched at render
  // time rather than saved into the template. One fetch per path per page,
  // shared by every filter reading it.
  var pending = {};

  // The facets endpoint answers for several fields at once and is
  // entitlement-scoped, so one request per page serves every facet-backed
  // filter, and a field this visitor may not read is simply absent.
  var facetRequest = null;
  var facetNames = [];

  function loadFacets(ctx) {
    if (!facetRequest) {
      facetRequest = ctx.apiGet('/directory/facets', facetNames.length ? { fields: facetNames.join(',') } : {})
        .then(function (body) {
          return (body && body.data) || {};
        })
        .catch(function () {
          return {};
        });
    }
    return facetRequest;
  }

  function facetToValues(entry) {
    if (!entry || !Array.isArray(entry.values)) {
      return [];
    }
    return entry.values.map(function (v) {
      // Taxonomy facets carry the id the gateway filters on; a custom field
      // facet has no id, so its own value is what gets sent.
      return { label: String(v.label === undefined || v.label === null ? v.value : v.label), value: [String(v.id || v.value)] };
    });
  }

  function itemParams(cfg) {
    return cfg.source.limit ? { limit: cfg.source.limit } : {};
  }

  function itemsKey(cfg) {
    return cfg.source.path + '|' + JSON.stringify(itemParams(cfg));
  }

  // The raw records of an endpoint-backed source, one request per page.
  function loadItems(cfg, ctx) {
    var cacheKey = itemsKey(cfg);
    if (!pending[cacheKey]) {
      pending[cacheKey] = ctx.apiGet(cfg.source.path, itemParams(cfg)).catch(function () {
        return null;
      });
    }
    return pending[cacheKey].then(function (body) {
      return (body && Array.isArray(body.data)) ? body.data : (Array.isArray(body) ? body : []);
    });
  }

  // -- Preloaded data -------------------------------------------------------
  //
  // The page render inlines the shared facets and categories entries it found
  // cached (render/directory-catalogue.php), so the filters work at first
  // paint without asking. Each entry says when it was fetched and how long it
  // lives: one a page cache served after that is used for first paint and
  // refreshed once.

  var CATEGORIES_PATH = '/directory/categories';
  var preloaded = false;

  function shellConfigs(scope) {
    var list = [];
    Array.prototype.forEach.call(scope.querySelectorAll('[data-agend-filter]'), function (shell) {
      var cfg;
      try {
        cfg = JSON.parse(shell.getAttribute('data-agend-filter'));
      } catch (e) {
        return;
      }
      if (cfg) {
        list.push({ shell: shell, cfg: cfg });
      }
    });
    return list;
  }

  function isStale(entry) {
    return typeof entry.fetched_at === 'number' && typeof entry.ttl === 'number' &&
      Date.now() / 1000 - entry.fetched_at > entry.ttl;
  }

  // Whether a visitor has left a filter as it started, so redrawing it with
  // fresher values takes nothing from them.
  function isUnset(state, cfg) {
    var value = state[cfg.state];
    if (cfg.mode === 'map') {
      value = value ? value[cfg.fieldKey] : undefined;
      if (value && typeof value === 'object' && !Array.isArray(value)) {
        return Object.keys(value).length === 0;
      }
    }
    if (Array.isArray(value)) {
      return value.length === 0;
    }
    return value === undefined || value === null || value === '';
  }

  function redraw(scope, ctx, configs, matches) {
    configs.forEach(function (entry) {
      if (matches(entry.cfg) && isUnset(ctx.state, entry.cfg)) {
        entry.shell.removeAttribute('data-agend-filter-ready');
        buildOne(entry.shell, ctx, scope);
      }
    });
  }

  function isFacetBacked(cfg) {
    return !!(cfg.source && cfg.source.facet);
  }

  function readsCategories(cfg) {
    return !!(cfg.source && cfg.source.path === CATEGORIES_PATH);
  }

  function usePreload(scope, ctx, names) {
    var preload = ctx.preload;
    if (preloaded || !preload) {
      return;
    }
    preloaded = true;
    var configs = shellConfigs(scope);

    var facets = preload.facets;
    if (facets && facets.body && !facetRequest && sameSelection(facets.names || [], names)) {
      facetRequest = Promise.resolve(facets.body.data || {});
      if (isStale(facets)) {
        ctx.apiGet('/directory/facets', { fields: names.join(',') })
          .then(function (body) {
            facetRequest = Promise.resolve((body && body.data) || {});
            redraw(scope, ctx, configs, isFacetBacked);
          })
          .catch(function () {});
      }
    }

    var categories = preload.categories;
    var readers = configs.filter(function (entry) {
      return readsCategories(entry.cfg);
    });
    if (categories && categories.body && readers.length) {
      readers.forEach(function (entry) {
        pending[itemsKey(entry.cfg)] = Promise.resolve(categories.body);
      });
      if (isStale(categories)) {
        ctx.apiGet(CATEGORIES_PATH, itemParams(readers[0].cfg))
          .then(function (body) {
            readers.forEach(function (entry) {
              pending[itemsKey(entry.cfg)] = Promise.resolve(body);
            });
            redraw(scope, ctx, configs, readsCategories);
          })
          .catch(function () {});
      }
    }
  }

  function loadValues(cfg, ctx) {
    if (cfg.source && cfg.source.facet) {
      return loadFacets(ctx).then(function (facets) {
        return facetToValues(facets[cfg.source.facet]);
      });
    }
    if (!cfg.source || !cfg.source.path) {
      return Promise.resolve(cfg.values || []);
    }
    return loadItems(cfg, ctx).then(function (items) {
      var seen = {};
      var values = [];
      items.forEach(function (item) {
        if (!item || typeof item !== 'object') {
          return;
        }
        var value = item[cfg.source.value];
        var label = item[cfg.source.labelKey];
        if (value === undefined || value === null || value === '') {
          return;
        }
        value = String(value);
        if (cfg.source.distinct && seen[value]) {
          return;
        }
        seen[value] = true;
        values.push({ label: String(label === undefined || label === null || label === '' ? value : label), value: [value] });
      });
      if (cfg.source.distinct) {
        values.sort(function (a, b) { return a.label.localeCompare(b.label); });
      }
      return values;
    });
  }

  // Clearing every filter in the template: each shell knows the state key it
  // writes, so the reset button can undo all of them without being told.
  function resetAll(scope, ctx) {
    var shells = scope.querySelectorAll('[data-agend-filter]');
    Array.prototype.forEach.call(shells, function (shell) {
      var cfg;
      try {
        cfg = JSON.parse(shell.getAttribute('data-agend-filter'));
      } catch (e) {
        return;
      }
      if (!cfg || !cfg.state) {
        return;
      }
      if (cfg.mode === 'view') {
        // Left as the visitor set it; buildView() repaints it on rebuild.
      } else if (cfg.mode === 'location') {
        ctx.state[cfg.state] = null;
      } else if (cfg.mode === 'map') {
        ctx.state[cfg.state] = {};
      } else {
        ctx.state[cfg.state] = cfg.mode === 'array' ? [] : '';
      }
      shell.removeAttribute('data-agend-filter-ready');
    });
    ctx.state.page = 1;
    build(scope, ctx);
    ctx.reload();
  }

  function buildReset(shell, cfg, ctx, scope) {
    var button = adopt(shell, '.agend-filter__reset', 'button', 'agend-filter__button agend-filter__reset');
    button.textContent = cfg.label || 'Clear filters';
    button.type = 'button';
    once(button, 'click', function () {
      resetAll(scope, ctx);
    });
    ready(button);
  }

  function buildOne(shell, ctx, scope) {
    var cfg;
    try {
      cfg = JSON.parse(shell.getAttribute('data-agend-filter'));
    } catch (e) {
      return;
    }
    if (!cfg || shell.getAttribute('data-agend-filter-ready') === '1') {
      return;
    }
    shell.setAttribute('data-agend-filter-ready', '1');

    // Nothing is removed from the slot: the server-drawn control stays
    // disabled until its values arrive and is then filled and enabled in
    // place, so the node a designer styled is the node a visitor uses.
    var slot = shell.querySelector('.agend-filter__control') || shell;
    // A Subcategories of selected filter starts hidden and is shown by its
    // own draw once it has something to offer.
    if (cfg.categoryLevel !== 'sub') {
      shell.hidden = false;
    }

    if (cfg.control === 'reset') {
      buildReset(slot, cfg, ctx, scope || document);
      return;
    }
    if (cfg.control === 'range') {
      loadFacets(ctx).then(function (facets) {
        buildRange(slot, cfg, ctx, cfg.source && cfg.source.facet ? facets[cfg.source.facet] : null);
      });
      return;
    }
    if (cfg.control === 'range_brackets') {
      loadFacets(ctx).then(function (facets) {
        buildRangeBrackets(shell, slot, cfg, ctx, cfg.source && cfg.source.facet ? facets[cfg.source.facet] : null);
      });
      return;
    }
    if (cfg.control === 'search') {
      buildSearch(slot, cfg, ctx);
      return;
    }
    if (cfg.control === 'date') {
      buildDate(slot, cfg, ctx);
      return;
    }
    if (cfg.control === 'location') {
      buildLocation(slot, cfg, ctx);
      return;
    }
    if (cfg.control === 'view') {
      buildView(slot, cfg, ctx);
      return;
    }

    if (cfg.categoryLevel && cfg.source && cfg.source.path && categoryLevels()) {
      loadItems(cfg, ctx).then(function (items) {
        var levels = categoryLevels();
        var nodes = levels.tree(items, { value: cfg.source.value, label: cfg.source.labelKey, parent: cfg.source.parentKey });
        if (levels.level(cfg.categoryLevel) !== 'all') {
          buildCategoryLevel(shell, slot, cfg, ctx, nodes);
          return;
        }
        buildValues(shell, slot, cfg, ctx, levels.view(nodes, [], 'all').options.map(function (option) {
          return { label: option.name, value: [option.id] };
        }));
      });
      return;
    }

    loadValues(cfg, ctx).then(function (values) {
      buildValues(shell, slot, cfg, ctx, values);
    });
  }

  function buildValues(shell, slot, cfg, ctx, values) {
    if (!values.length) {
      // Nothing to choose from: hide the filter rather than leaving a
      // control that cannot do anything. Hidden, not removed, so a later
      // rebuild can show it again.
      shell.hidden = true;
      return;
    }
    if (cfg.control === 'checkboxes') {
      buildCheckboxes(slot, cfg, ctx, values);
    } else if (cfg.control === 'buttons') {
      buildButtons(slot, cfg, ctx, values);
    } else {
      buildSelect(slot, cfg, ctx, values);
    }
  }

  /**
   * Builds every Agend Filter control inside a scope.
   *
   * @param {Element} scope   The catalogue root.
   * @param {Object}  options state, reload and apiGet from the catalogue, and
   *                          optionally setView for a List / Map switch and
   *                          preload, the catalogue config's inlined entries.
   * @return {number} How many filter shells were found.
   */
  function build(scope, options) {
    if (!scope || !options || !options.state || !options.reload || !options.apiGet) {
      return 0;
    }
    var shells = scope.querySelectorAll('[data-agend-filter]');
    // Gather every facet this template needs first, so the endpoint is asked
    // once for all of them rather than once per filter. The server derives
    // the same list to find the entry it inlines
    // (agend_apps_records_filter_facet_names()); keep the two in step.
    var names = [];
    Array.prototype.forEach.call(shells, function (shell) {
      var cfg;
      try {
        cfg = JSON.parse(shell.getAttribute('data-agend-filter'));
      } catch (e) {
        return;
      }
      if (cfg && cfg.source && cfg.source.facet) {
        if (facetNames.indexOf(cfg.source.facet) === -1) {
          facetNames.push(cfg.source.facet);
        }
        if (names.indexOf(cfg.source.facet) === -1) {
          names.push(cfg.source.facet);
        }
      }
    });
    usePreload(scope, options, names);
    Array.prototype.forEach.call(shells, function (shell) {
      buildOne(shell, options, scope);
    });
    return shells.length;
  }

  window.agendFilters = window.agendFilters || {};
  window.agendFilters.build = build;
  // The pure bracket helpers, exposed so a layer that shows the active range
  // (a chip) can name the matching bracket, and so they can be exercised on
  // their own.
  window.agendFilters.rangeBrackets = {
    normalise: normaliseBrackets,
    resolve: resolveBrackets,
    find: findBracket,
    boundsOf: boundsOf,
  };
})();
