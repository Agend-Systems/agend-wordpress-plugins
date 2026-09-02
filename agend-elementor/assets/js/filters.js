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
    if (cfg.mode === 'array') {
      state[cfg.state] = values.slice();
    } else {
      state[cfg.state] = values.length ? values[0] : '';
    }
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

  function loadValues(cfg, ctx) {
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

  function buildOne(shell, ctx) {
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
    Array.prototype.forEach.call(shells, function (shell) {
      buildOne(shell, options);
    });
    return shells.length;
  }

  window.agendFilters = window.agendFilters || {};
  window.agendFilters.build = build;
})();
