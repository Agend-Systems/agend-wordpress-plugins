/**
 * Agend Directory Search: a keyword and place box, with optional dropdowns
 * for directory filters, that drives the page's directory catalogue, or, on a
 * page with none, sends the visitor to the directory page with the search
 * carried in the address.
 *
 * The catalogue is driven through window.agendCatalogues.listing.apply() (see
 * directory-catalogue.js), not through its filter controls, so the box works
 * whichever filters the catalogue's template has. Shared plumbing (place
 * search, device location, links) is in directory-bridge.js.
 */
(function () {
  'use strict';

  function init(box) {
    var bridge = window.agendDirectoryBridge;
    var cfg;
    try {
      cfg = JSON.parse(box.getAttribute('data-agend-ds-config'));
    } catch (e) {
      return;
    }
    var form = box.querySelector('.agend-ds__form');
    if (!bridge || !cfg || !form || box.getAttribute('data-agend-ds-ready') === '1') {
      return;
    }
    box.setAttribute('data-agend-ds-ready', '1');
    box.classList.add('agend-ds--js');

    var keyword = box.querySelector('.agend-ds__field--keyword input');
    var place = box.querySelector('.agend-ds__field--location input');
    var placeField = box.querySelector('.agend-ds__field--location');
    var locateButton = box.querySelector('.agend-ds__locate');
    var status = box.querySelector('.agend-ds__status');
    var submit = box.querySelector('.agend-ds__button');
    var text = cfg.text || {};

    // Each dropdown field with the filter it writes, in the order drawn, which
    // is the order of cfg.choices.
    var choices = Array.prototype.map.call(box.querySelectorAll('[data-agend-ds-choice]'), function (field) {
      var at = Number(field.getAttribute('data-agend-ds-choice'));
      return { field: field, select: field.querySelector('select'), cfg: (cfg.choices || [])[at] || null };
    }).filter(function (choice) {
      return choice.select && choice.cfg;
    });

    // What the fields last showed of the catalogue, so a change made anywhere
    // else (a filter, Clear all) is copied in and the visitor's own typing is
    // not overwritten by the results of their own search.
    var shown = { q: null, near: null, choices: choices.map(function () { return null; }) };
    var scrollAfterResults = false;

    function say(message) {
      status.textContent = message || '';
      status.hidden = !message;
    }

    function busy(on) {
      [keyword, place, locateButton, submit].concat(choices.map(function (choice) {
        return choice.select;
      })).forEach(function (node) {
        if (node) {
          node.disabled = on;
        }
      });
      box.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    // What the catalogue holds for a dropdown's filter, as a list of strings.
    function held(catalogue, choice) {
      var state = catalogue.state;
      var value = choice.cfg.mode === 'map' ? (state[choice.cfg.state] || {})[choice.cfg.fieldKey] : state[choice.cfg.state];
      if (Array.isArray(value)) {
        return value.map(String).filter(function (entry) {
          return entry !== '';
        });
      }
      return value === undefined || value === null || value === '' || typeof value === 'object' ? [] : [String(value)];
    }

    // The plain value of the chosen option; '' for the first, "any", option.
    function chosen(choice) {
      var option = choice.select.options[choice.select.selectedIndex];
      return option ? option.getAttribute('data-agend-ds-value') || '' : '';
    }

    function choose(choice, value) {
      var at = 0;
      Array.prototype.forEach.call(choice.select.options, function (option, index) {
        if (value !== '' && option.getAttribute('data-agend-ds-value') === value) {
          at = index;
        }
      });
      choice.select.selectedIndex = at;
      choice.select.classList.toggle('is-empty', at === 0);
    }

    // A dropdown shows a value only when the catalogue holds exactly one;
    // several (checked in the filter bar) or none show the first option.
    function shownValue(values) {
      return values.length === 1 ? values[0] : '';
    }

    // The catalogue changes the dropdowns ask for: a dropdown whose choice
    // differs from what the catalogue holds writes it, and the first option
    // clears the filter, except that it leaves alone several values a filter
    // in the template chose, which the dropdown cannot show.
    function choiceChanges(catalogue) {
      var changes = {};
      choices.forEach(function (choice, index) {
        var values = catalogue ? held(catalogue, choice) : [];
        var value = chosen(choice);
        if (value === shownValue(values) || (value === '' && values.length > 1)) {
          return;
        }
        var next = value === '' ? [] : [value];
        shown.choices[index] = next.join('\n');
        if (choice.cfg.mode === 'map') {
          changes.custom = changes.custom || {};
          changes.custom[choice.cfg.fieldKey] = next;
        } else {
          changes.lists = changes.lists || {};
          changes.lists[choice.cfg.state] = next;
        }
      });
      return changes;
    }

    // A signed-in visitor's custom field values depend on what they may read,
    // so the page leaves them to the browser. A dropdown with nothing to offer
    // is hidden.
    function fill(choice) {
      if (!choice.cfg.facet) {
        return Promise.resolve();
      }
      var facetName = choice.cfg.facet;
      return bridge.restGet('/directory/facets', { fields: facetName }).then(function (body) {
        var facet = body && body.data && body.data[facetName];
        (facet && Array.isArray(facet.values) ? facet.values : []).forEach(function (entry) {
          var value = String(entry.id || entry.value || '');
          if (!value) {
            return;
          }
          var option = document.createElement('option');
          // As the server writes it: escaped the way a search link carries a
          // value, for a submit that somehow skips this script.
          option.value = value.replace(/%/g, '%25').replace(/,/g, '%2C');
          option.setAttribute('data-agend-ds-value', value);
          option.textContent = String(entry.label === undefined || entry.label === null ? entry.value : entry.label);
          choice.select.appendChild(option);
        });
      }).catch(function () {}).then(function () {
        if (choice.select.options.length < 2) {
          choice.field.hidden = true;
        }
      });
    }

    function nearKey(near) {
      return near ? [near.lat, near.lng, near.radius].join(',') : '';
    }

    function nearText(near) {
      if (!near) {
        return '';
      }
      return near.source === 'device' ? text.myLocation : String(near.label || '');
    }

    function sync(catalogue) {
      var state = catalogue.state;
      var query = typeof state.search === 'string' ? state.search : '';
      if (shown.q !== query) {
        shown.q = query;
        if (keyword) {
          keyword.value = query;
        }
      }
      var key = nearKey(state.near);
      if (shown.near !== key) {
        shown.near = key;
        if (place) {
          place.value = nearText(state.near);
        }
      }
      choices.forEach(function (choice, index) {
        var values = held(catalogue, choice);
        var joined = values.join('\n');
        if (shown.choices[index] !== joined) {
          shown.choices[index] = joined;
          choose(choice, shownValue(values));
        }
      });
    }

    function radiusFor(catalogue) {
      var held = catalogue && catalogue.state.near && Number(catalogue.state.near.radius);
      return held > 0 ? held : cfg.radius;
    }

    // Runs the search the form describes: in the catalogue when the page has
    // one, otherwise on the directory page.
    function search(query, near) {
      var catalogue = bridge.catalogue();
      if (catalogue) {
        shown.q = query;
        if (near !== undefined) {
          shown.near = nearKey(near);
        }
        scrollAfterResults = !!cfg.scroll;
        var changes = choiceChanges(catalogue);
        changes.search = query;
        if (near !== undefined) {
          changes.near = near;
        }
        catalogue.apply(changes);
        return;
      }
      var picked = choiceChanges(null);
      var state = { search: query, near: near || null, custom_fields: picked.custom || {} };
      Object.keys(picked.lists || {}).forEach(function (key) {
        state[key] = picked.lists[key];
      });
      window.location.assign(bridge.destination(cfg.target, state));
    }

    function fail(error) {
      if (error === 'throttled') {
        return;
      }
      if (error === 'disabled') {
        // The account has no place search; the box would fail on every submit.
        if (placeField) {
          placeField.hidden = true;
        }
        say('');
        return;
      }
      say(text[error] || text.failed);
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      say('');
      var catalogue = bridge.catalogue();
      var query = keyword ? keyword.value.trim() : '';
      var typed = place && !placeField.hidden ? place.value.trim() : '';

      if (!typed) {
        // An empty box clears a place the catalogue holds; on the directory
        // page there is none to clear.
        search(query, catalogue && catalogue.state.near ? null : undefined);
        return;
      }
      if (catalogue && catalogue.state.near && nearText(catalogue.state.near) === typed) {
        search(query, undefined);
        return;
      }
      busy(true);
      bridge.geocode(typed, cfg.region).then(function (result) {
        busy(false);
        if (result.error) {
          fail(result.error);
          return;
        }
        search(query, { lat: result.lat, lng: result.lng, radius: radiusFor(catalogue), label: typed, source: 'place' });
      });
    });

    if (locateButton) {
      locateButton.addEventListener('click', function () {
        say('');
        busy(true);
        bridge.locate().then(function (result) {
          busy(false);
          if (result.error) {
            say(text[result.error] || text.unavailable);
            return;
          }
          var catalogue = bridge.catalogue();
          var query = keyword ? keyword.value.trim() : '';
          var radius = radiusFor(catalogue);
          if (place) {
            place.value = text.myLocation;
          }
          if (catalogue) {
            search(query, { lat: result.lat, lng: result.lng, radius: radius, label: text.myLocation, source: 'device' });
            return;
          }
          search(query, bridge.deviceNear(result, radius, text.myLocation));
        });
      });
    }

    choices.forEach(function (choice) {
      choice.select.addEventListener('change', function () {
        choice.select.classList.toggle('is-empty', choice.select.selectedIndex === 0);
      });
    });

    if (choices.some(function (choice) { return !!choice.cfg.facet; })) {
      Promise.all(choices.map(fill)).then(function () {
        bridge.whenCatalogue(function (catalogue) {
          // Options arrived after the first sync could pick one, so every
          // dropdown is drawn again.
          shown.choices = choices.map(function () { return null; });
          sync(catalogue);
        });
      });
    }
    bridge.whenCatalogue(function (catalogue) {
      sync(catalogue);
    });
    document.addEventListener('agend:directory-results', function (event) {
      var catalogue = (event.detail && event.detail.catalogue) || bridge.catalogue();
      if (!catalogue) {
        return;
      }
      sync(catalogue);
      if (scrollAfterResults) {
        scrollAfterResults = false;
        bridge.scrollToResults(catalogue.root);
      }
    });

    if (placeField) {
      bridge.mapSettings().then(function (settings) {
        if (settings && settings.geocoding_available === false) {
          placeField.hidden = true;
        }
      });
    }
  }

  function initAll() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-agend-ds-config]'), init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
