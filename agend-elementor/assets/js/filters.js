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

  function sameSelection(a, b) {
    return a.length === b.length && a.every(function (v, i) { return v === b[i]; });
  }

  function buildSearch(shell, cfg, ctx) {
    var input = el('input');
    input.type = 'search';
    input.placeholder = cfg.placeholder || cfg.label || '';
    var timer;
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        applyValue(ctx.state, cfg, input.value.trim() ? [input.value.trim()] : []);
        ctx.reload();
      }, 300);
    });
    shell.appendChild(input);
  }

  function buildDate(shell, cfg, ctx) {
    var input = el('input');
    input.type = 'date';
    if (cfg.placeholder) {
      input.setAttribute('aria-label', cfg.placeholder);
    }
    input.addEventListener('change', function () {
      applyValue(ctx.state, cfg, input.value ? [input.value] : []);
      ctx.reload();
    });
    shell.appendChild(input);
  }

  function buildRange(shell, cfg, ctx, facet) {
    var wrap = el('div', 'agend-filter__range');
    var min = el('input');
    var max = el('input');
    min.type = 'number';
    max.type = 'number';
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
      input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(push, 400);
      });
    });
    wrap.appendChild(min);
    wrap.appendChild(max);
    shell.appendChild(wrap);
  }

  function buildSelect(shell, cfg, ctx, values) {
    var select = el('select');
    select.appendChild(new Option(cfg.anyLabel || ('All ' + (cfg.label || '')), ''));
    values.forEach(function (entry, index) {
      select.appendChild(new Option(entry.label, String(index)));
    });
    select.addEventListener('change', function () {
      var picked = select.value === '' ? [] : values[Number(select.value)].value;
      applyValue(ctx.state, cfg, picked);
      ctx.reload();
    });
    shell.appendChild(select);
  }

  function buildCheckboxes(shell, cfg, ctx, values) {
    var list = el('div', 'agend-filter__options');
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
    shell.appendChild(list);
  }

  function buildButtons(shell, cfg, ctx, values) {
    var list = el('div', 'agend-filter__options');
    var current = [];
    var buttons = [];

    function paint() {
      buttons.forEach(function (pair) {
        var on = sameSelection(current, pair.value);
        pair.node.className = 'agend-filter__button' + (on ? ' is-active' : '');
        pair.node.setAttribute('aria-pressed', on ? 'true' : 'false');
      });
    }

    var any = el('button', 'agend-filter__button', cfg.anyLabel || ('All ' + (cfg.label || '')));
    any.type = 'button';
    buttons.push({ node: any, value: [] });
    list.appendChild(any);

    values.forEach(function (entry) {
      var button = el('button', 'agend-filter__button', entry.label);
      button.type = 'button';
      buttons.push({ node: button, value: entry.value });
      list.appendChild(button);
    });

    buttons.forEach(function (pair) {
      pair.node.addEventListener('click', function () {
        // Clicking the active choice clears it, so buttons behave like a
        // toggle set rather than a dead end.
        current = sameSelection(current, pair.value) ? [] : pair.value.slice();
        applyValue(ctx.state, cfg, current);
        paint();
        ctx.reload();
      });
    });

    paint();
    shell.appendChild(list);
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
    var button = el('button', 'agend-filter__button agend-filter__reset', cfg.label || 'Clear filters');
    button.type = 'button';
    button.addEventListener('click', function () {
      resetAll(scope, ctx);
    });
    shell.appendChild(button);
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

    var slot = shell.querySelector('.agend-filter__control') || shell;
    slot.innerHTML = '';

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
        // Nothing to choose from: leave the shell empty rather than showing a
        // control that cannot do anything.
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
