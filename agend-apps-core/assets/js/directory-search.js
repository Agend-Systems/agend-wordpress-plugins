/**
 * Agend Directory Search: a keyword and place box that drives the page's
 * directory catalogue, or, on a page with none, sends the visitor to the
 * directory page with the search carried in the address.
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

    // What the fields last showed of the catalogue, so a change made anywhere
    // else (a filter, Clear all) is copied in and the visitor's own typing is
    // not overwritten by the results of their own search.
    var shown = { q: null, near: null };
    var scrollAfterResults = false;

    function say(message) {
      status.textContent = message || '';
      status.hidden = !message;
    }

    function busy(on) {
      [keyword, place, locateButton, submit].forEach(function (node) {
        if (node) {
          node.disabled = on;
        }
      });
      box.setAttribute('aria-busy', on ? 'true' : 'false');
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
        var changes = { search: query };
        if (near !== undefined) {
          changes.near = near;
        }
        catalogue.apply(changes);
        return;
      }
      window.location.assign(bridge.destination(cfg.target, { search: query, near: near || null }));
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
