/**
 * What the directory widgets that sit outside a catalogue's filter template
 * (Agend Directory Search, Agend Filter Shortcuts, Agend Directory Count)
 * share: finding the page's directory catalogue, asking the place search and
 * the browser for a location, and sending a visitor to the directory page with
 * a search link when the page has no catalogue.
 *
 * Exposed as window.agendDirectoryBridge.
 */
(function (root) {
  'use strict';

  // Nominatim, behind the gateway, allows about one lookup a second.
  var GEOCODE_MIN_INTERVAL_MS = 1500;

  // Device coordinates carried to another page are cut to two decimals, about
  // a kilometre, because the address of that page is shareable.
  var DEVICE_PLACES = 2;

  function restBase() {
    return (root.agendApps && root.agendApps.restUrl) || '/wp-json/agend-apps/v1/';
  }

  function restGet(path, params) {
    var url = String(restBase()).replace(/\/$/, '') + path;
    var query = Object.keys(params || {}).filter(function (key) {
      return params[key] !== undefined && params[key] !== null && params[key] !== '';
    }).map(function (key) {
      return encodeURIComponent(key) + '=' + encodeURIComponent(params[key]);
    }).join('&');
    if (query) {
      url += (url.indexOf('?') === -1 ? '?' : '&') + query;
    }
    var nonce = (root.agendApps && root.agendApps.nonce) || '';
    return root.fetch(url, { headers: nonce ? { 'X-WP-Nonce': nonce } : {} }).then(function (res) {
      return res.json();
    });
  }

  /**
   * The catalogue a widget can drive, or null: the page's directory
   * catalogue, when its script offers the write path.
   *
   * @return {?Object}
   */
  function catalogue() {
    var found = root.agendCatalogues && root.agendCatalogues.listing;
    return found && typeof found.apply === 'function' ? found : null;
  }

  /**
   * Calls back with the catalogue as soon as there is one, now or when its
   * script announces itself.
   *
   * @param {Function} callback Called once, with the catalogue.
   */
  function whenCatalogue(callback) {
    var now = catalogue();
    if (now) {
      callback(now);
      return;
    }
    var done = false;
    root.document.addEventListener('agend:directory-ready', function () {
      var found = catalogue();
      if (found && !done) {
        done = true;
        callback(found);
      }
    });
  }

  /**
   * The directory's map defaults, fetched once per page and shared with the
   * location filter and the Agend Map.
   *
   * @return {Promise<?Object>}
   */
  function mapSettings() {
    var shared = (root.agendDirectory = root.agendDirectory || {});
    if (!shared.mapSettings) {
      shared.mapSettings = restGet('/directory/map-settings', {})
        .then(function (body) {
          return (body && body.data && typeof body.data === 'object') ? body.data : null;
        })
        .catch(function () {
          return null;
        });
    }
    return shared.mapSettings;
  }

  var lastGeocode = 0;

  /**
   * Looks a typed place up with the same geocoder the location filter uses.
   *
   * @param {string} text   What the visitor typed.
   * @param {string} region Appended to the text to settle ambiguous names.
   * @return {Promise<{lat: number, lng: number}|{error: string}>} `error` is
   *         one of empty, throttled, notFound, disabled (the account has
   *         no place search), busy or failed.
   */
  function geocode(text, region) {
    var place = String(text || '').trim();
    if (!place) {
      return Promise.resolve({ error: 'empty' });
    }
    var now = Date.now();
    if (now - lastGeocode < GEOCODE_MIN_INTERVAL_MS) {
      return Promise.resolve({ error: 'throttled' });
    }
    lastGeocode = now;
    var query = region ? place + ', ' + region : place;
    return restGet('/directory/geocode', { q: query.slice(0, 200) }).then(function (body) {
      var point = body && body.data;
      if (point && isFinite(Number(point.latitude)) && isFinite(Number(point.longitude))) {
        return { lat: Number(point.latitude), lng: Number(point.longitude) };
      }
      var code = point && point.status_code;
      if (code === 404) {
        return { error: 'notFound' };
      }
      if (code === 403) {
        return { error: 'disabled' };
      }
      return { error: code === 429 || code === 503 ? 'busy' : 'failed' };
    }).catch(function () {
      return { error: 'failed' };
    });
  }

  /**
   * The visitor's own position from the browser.
   *
   * @return {Promise<{lat: number, lng: number}|{error: string}>} `error` is
   *         one of unsupported, denied, timeout or unavailable.
   */
  function locate() {
    var geolocation = root.navigator && root.navigator.geolocation;
    if (!geolocation) {
      return Promise.resolve({ error: 'unsupported' });
    }
    return new Promise(function (resolve) {
      var succeed = function (position) {
        resolve({ lat: position.coords.latitude, lng: position.coords.longitude });
      };
      var fail = function (error, retried) {
        // A quick, low-accuracy fix fails on some desktops; one precise
        // attempt is worth making before giving up.
        if (error && error.code === 2 && !retried) {
          geolocation.getCurrentPosition(succeed, function (again) {
            fail(again, true);
          }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
          return;
        }
        resolve({ error: error && error.code === 1 ? 'denied' : (error && error.code === 3 ? 'timeout' : 'unavailable') });
      };
      geolocation.getCurrentPosition(succeed, function (error) {
        fail(error, false);
      }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
    });
  }

  /**
   * The directory page address carrying a search, for a page with no
   * catalogue to run it in.
   *
   * @param {string} target The directory page address; empty for this page.
   * @param {Object} state  A catalogue-shaped state (search, near, lists,
   *                        custom_fields) holding only what the search sets.
   * @return {string}
   */
  function destination(target, state) {
    var base = target || (root.location.origin + root.location.pathname);
    var link = root.agendSearchLink;
    if (!link) {
      return base;
    }
    return link.apply(base, link.encode(state, {}));
  }

  /**
   * A point as a search link carries it from a device: rounded, and marked as
   * a place so the encoder writes it.
   *
   * @param {{lat: number, lng: number}} point  The device position.
   * @param {number}                     radius Search radius in kilometres.
   * @param {string}                     label  Shown to the visitor on arrival.
   * @return {Object} A `near` value.
   */
  function deviceNear(point, radius, label) {
    var round = root.agendSearchLink ? root.agendSearchLink.roundTo : function (value, places) {
      var factor = Math.pow(10, places);
      return Math.round(value * factor) / factor;
    };
    return {
      lat: round(point.lat, DEVICE_PLACES),
      lng: round(point.lng, DEVICE_PLACES),
      radius: radius,
      label: label,
      source: 'place',
    };
  }

  /**
   * Scrolls the catalogue's results into view, without animation when the
   * visitor has asked for less motion.
   *
   * @param {?Element} node The catalogue root.
   */
  function scrollToResults(node) {
    if (!node || typeof node.scrollIntoView !== 'function') {
      return;
    }
    var reduced = root.matchMedia && root.matchMedia('(prefers-reduced-motion: reduce)').matches;
    node.scrollIntoView({ behavior: reduced ? 'auto' : 'smooth', block: 'start' });
  }

  root.agendDirectoryBridge = {
    catalogue: catalogue,
    whenCatalogue: whenCatalogue,
    mapSettings: mapSettings,
    restGet: restGet,
    geocode: geocode,
    locate: locate,
    destination: destination,
    deviceNear: deviceNear,
    scrollToResults: scrollToResults,
  };
})(typeof window !== 'undefined' ? window : this);
