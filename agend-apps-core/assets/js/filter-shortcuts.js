/**
 * Agend Filter Shortcuts: tiles that each apply one value of one directory
 * filter.
 *
 * Every tile is a link to the directory page with the value in its search
 * link, so it works without script. With a Directory Catalogue on the page
 * the click is taken over: the value is applied in place through
 * window.agendCatalogues.listing.apply() (see directory-catalogue.js), the
 * tile for the catalogue's current value is marked, and a second click on it
 * clears the filter. Shared plumbing is in directory-bridge.js.
 */
(function () {
  'use strict';

  function init(box) {
    var bridge = window.agendDirectoryBridge;
    var cfg;
    try {
      cfg = JSON.parse(box.getAttribute('data-agend-fs-config'));
    } catch (e) {
      return;
    }
    if (!bridge || !cfg || box.getAttribute('data-agend-fs-ready') === '1') {
      return;
    }
    box.setAttribute('data-agend-fs-ready', '1');

    var grid = box.querySelector('.agend-fs__grid');
    var scrollAfterResults = false;

    // What the catalogue holds for this tile's filter, as a list of strings.
    function selection(catalogue) {
      var state = catalogue.state;
      var value = cfg.mode === 'map' ? (state[cfg.state] || {})[cfg.fieldKey] : state[cfg.state];
      if (Array.isArray(value)) {
        return value.map(String).filter(function (entry) {
          return entry !== '';
        });
      }
      return value === undefined || value === null || value === '' || typeof value === 'object' ? [] : [String(value)];
    }

    function changesFor(values) {
      var changes = {};
      if (cfg.mode === 'map') {
        changes.custom = {};
        changes.custom[cfg.fieldKey] = values;
      } else {
        changes.lists = {};
        changes.lists[cfg.state] = values;
      }
      return changes;
    }

    function tiles() {
      return Array.prototype.slice.call(box.querySelectorAll('.agend-fs__tile'));
    }

    function paint(catalogue) {
      var held = selection(catalogue);
      tiles().forEach(function (tile) {
        var on = held.length === 1 && held[0] === tile.getAttribute('data-agend-fs-value');
        tile.classList.toggle('is-active', on);
        if (on) {
          tile.setAttribute('aria-current', 'true');
        } else {
          tile.removeAttribute('aria-current');
        }
      });
    }

    function el(tag, className, text) {
      var node = document.createElement(tag);
      node.className = className;
      if (text !== undefined) {
        node.textContent = text;
      }
      return node;
    }

    // A signed-in visitor's custom field values depend on what they may read,
    // so the page leaves them to the browser.
    function fill() {
      var source = cfg.pending;
      if (!source || !source.facet) {
        return Promise.resolve();
      }
      return bridge.restGet('/directory/facets', { fields: source.facet }).then(function (body) {
        var facet = body && body.data && body.data[source.facet];
        (facet && Array.isArray(facet.values) ? facet.values : []).forEach(function (entry) {
          var value = String(entry.id || entry.value || '');
          if (!value) {
            return;
          }
          var state = { custom_fields: {}, categories: [], location_state: [], location_city: [] };
          if (cfg.mode === 'map') {
            state.custom_fields[cfg.fieldKey] = [value];
          } else {
            state[cfg.state] = [value];
          }
          var item = el('li', 'agend-fs__item');
          var tile = el('a', 'agend-fs__tile');
          tile.href = bridge.destination(cfg.target, state);
          tile.setAttribute('data-agend-fs-value', value);
          if (source.icon) {
            var icon = el('span', 'agend-fs__icon');
            icon.setAttribute('aria-hidden', 'true');
            // Reduced to icon markup by the server (wp_kses) before it was
            // put in the config.
            icon.innerHTML = source.icon;
            tile.appendChild(icon);
          }
          tile.appendChild(el('span', 'agend-fs__label', String(entry.label === undefined || entry.label === null ? entry.value : entry.label)));
          item.appendChild(tile);
          grid.appendChild(item);
        });
      }).catch(function () {});
    }

    box.addEventListener('click', function (event) {
      var tile = event.target.closest ? event.target.closest('.agend-fs__tile') : null;
      var catalogue = bridge.catalogue();
      // A modified click opens the link elsewhere; no catalogue means the link
      // is the way there.
      if (!tile || !catalogue || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) {
        return;
      }
      event.preventDefault();
      var value = tile.getAttribute('data-agend-fs-value');
      var held = selection(catalogue);
      var already = held.length === 1 && held[0] === value;
      scrollAfterResults = !!cfg.scroll && !already;
      catalogue.apply(changesFor(already ? [] : [value]));
      paint(catalogue);
    });

    document.addEventListener('agend:directory-results', function (event) {
      var catalogue = (event.detail && event.detail.catalogue) || bridge.catalogue();
      if (!catalogue) {
        return;
      }
      paint(catalogue);
      if (scrollAfterResults) {
        scrollAfterResults = false;
        bridge.scrollToResults(catalogue.root);
      }
    });

    fill().then(function () {
      bridge.whenCatalogue(paint);
    });
  }

  function initAll() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-agend-fs-config]'), init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
