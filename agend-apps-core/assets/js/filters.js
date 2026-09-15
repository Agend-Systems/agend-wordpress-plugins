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

  function loadValues(cfg, ctx) {
    if (cfg.source && cfg.source.facet) {
      return loadFacets(ctx).then(function (facets) {
        return facetToValues(facets[cfg.source.facet]);
      });
    }
    if (!cfg.source || !cfg.source.path) {
      return Promise.resolve(cfg.values || []);
    }
    var params = cfg.source.limit ? { limit: cfg.source.limit } : {};
    var cacheKey = cfg.source.path + '|' + JSON.stringify(params);
    if (!pending[cacheKey]) {
      pending[cacheKey] = ctx.apiGet(cfg.source.path, params).catch(function () {
        return null;
      });
    }
    return pending[cacheKey].then(function (body) {
      var items = (body && Array.isArray(body.data)) ? body.data : (Array.isArray(body) ? body : []);
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
      if (cfg.mode === 'map') {
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
    shell.hidden = false;

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
    if (cfg.control === 'search') {
      buildSearch(slot, cfg, ctx);
      return;
    }
    if (cfg.control === 'date') {
      buildDate(slot, cfg, ctx);
      return;
    }

    loadValues(cfg, ctx).then(function (values) {
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
    });
  }

  /**
   * Builds every Agend Filter control inside a scope.
   *
   * @param {Element} scope   The catalogue root.
   * @param {Object}  options state, reload and apiGet from the catalogue.
   * @return {number} How many filter shells were found.
   */
  function build(scope, options) {
    if (!scope || !options || !options.state || !options.reload || !options.apiGet) {
      return 0;
    }
    var shells = scope.querySelectorAll('[data-agend-filter]');
    // Gather every facet this template needs first, so the endpoint is asked
    // once for all of them rather than once per filter.
    Array.prototype.forEach.call(shells, function (shell) {
      var cfg;
      try {
        cfg = JSON.parse(shell.getAttribute('data-agend-filter'));
      } catch (e) {
        return;
      }
      if (cfg && cfg.source && cfg.source.facet && facetNames.indexOf(cfg.source.facet) === -1) {
        facetNames.push(cfg.source.facet);
      }
    });
    Array.prototype.forEach.call(shells, function (shell) {
      buildOne(shell, options, scope);
    });
    return shells.length;
  }

  window.agendFilters = window.agendFilters || {};
  window.agendFilters.build = build;
})();
