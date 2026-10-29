/**
 * Agend Map: pins the listings of the Directory Catalogue on the same page.
 *
 * The map follows the catalogue rather than keeping filters of its own. A
 * catalogue publishes itself on window.agendCatalogues.listing (its state,
 * the query it last asked as params(), reload(), hrefFor() and open()), and
 * dispatches two events on document:
 *  - agend:directory-results after every load, with the query it asked, so
 *    the map fetches the same listings from /directory/markers, as map
 *    points (id, name and coordinates) for every pin, and a pin's details
 *    by its listing id when the visitor opens it;
 *  - agend:directory-view when a List / Map switch changes view, so a map
 *    set to follow the switch shows in place of the results list.
 * With no catalogue on the page the map pins every listing with a location.
 *
 * Drawn with Leaflet and leaflet.markercluster (vendored under
 * assets/js/vendor/) on tiles that need no API key.
 */
(function () {
  'use strict';

  // Australia, the one place every Agend account has a listing today; only
  // ever seen for a moment before the account's own bounds arrive.
  var FALLBACK_CENTRE = [-25.2744, 133.7751];
  var FALLBACK_ZOOM = 4;
  var MOVE_DEBOUNCE_MS = 400;
  // With "Group nearby pins" off, the most pins drawn one by one. A plain
  // layer of more is unreadable and slow to draw, so they are grouped anyway.
  var UNGROUPED_PIN_LIMIT = 2000;

  function restBase() {
    return (window.agendApps && window.agendApps.restUrl) || '/wp-json/agend-apps/v1/';
  }

  function nonce() {
    return (window.agendApps && window.agendApps.nonce) || '';
  }

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

  // The same query serialisation as apiGetFrom() in directory-catalogue.js:
  // a plain-object value (custom_fields) becomes key[sub] or key[sub][bound],
  // anything else a single key=value.
  function apiGet(path, params) {
    var url = restBase().replace(/\/$/, '') + path;
    var qs = [];
    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];
      if (value === undefined || value === null || value === '') {
        return;
      }
      if (typeof value === 'object' && !Array.isArray(value)) {
        Object.keys(value).forEach(function (subKey) {
          var subValue = value[subKey];
          if (subValue === undefined || subValue === null || subValue === '') {
            return;
          }
          if (typeof subValue === 'object' && !Array.isArray(subValue)) {
            Object.keys(subValue).forEach(function (bound) {
              var boundValue = subValue[bound];
              if (boundValue === undefined || boundValue === null || boundValue === '') {
                return;
              }
              qs.push(encodeURIComponent(key + '[' + subKey + '][' + bound + ']') + '=' + encodeURIComponent(boundValue));
            });
            return;
          }
          qs.push(encodeURIComponent(key + '[' + subKey + ']') + '=' + encodeURIComponent(subValue));
        });
        return;
      }
      qs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
    });
    if (qs.length) {
      url += (url.indexOf('?') === -1 ? '?' : '&') + qs.join('&');
    }
    return fetch(url, { headers: nonce() ? { 'X-WP-Nonce': nonce() } : {} }).then(function (res) {
      return res.json();
    });
  }

  // Shared with the Location filter (assets/js/filters.js), so a filter and a
  // map on one page cost one request between them.
  function mapSettings() {
    var shared = (window.agendDirectory = window.agendDirectory || {});
    if (!shared.mapSettings) {
      shared.mapSettings = apiGet('/directory/map-settings', {})
        .then(function (body) {
          return (body && body.data && typeof body.data === 'object') ? body.data : null;
        })
        .catch(function () {
          return null;
        });
    }
    return shared.mapSettings;
  }

  function formatDistance(km) {
    var value = Number(km);
    if (km === null || km === undefined || km === '' || !isFinite(value) || value < 0) {
      return '';
    }
    if (value < 1) {
      return Math.round(value * 1000) + ' m';
    }
    return value.toFixed(1) + ' km';
  }

  function safeUrl(value) {
    if (typeof value !== 'string' || !value) {
      return '';
    }
    return /^https?:\/\//i.test(value) ? value : '';
  }

  function validPoint(lat, lng) {
    return isFinite(lat) && isFinite(lng) && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
  }

  function withParam(query, key, value) {
    var copy = {};
    Object.keys(query).forEach(function (name) {
      copy[name] = query[name];
    });
    copy[key] = value;
    return copy;
  }

  // The catalogue's question as the map asks it. Paging and ordering mean
  // nothing to a set of pins, and the map sends no page size of its own, so
  // the gateway's points cap applies (and a gateway without points mode
  // answers with its default page of map markers).
  function markersQuery(params) {
    var query = {};
    Object.keys(params || {}).forEach(function (key) {
      if (key !== 'page' && key !== 'limit' && key !== 'per_page' && key !== 'sortBy' && key !== 'sortOrder') {
        query[key] = params[key];
      }
    });
    return query;
  }

  // A map marker always carries its slug and a map point never does. A
  // gateway without points mode ignores `projection` and answers with map
  // markers, which already hold everything the pin details show.
  function isMarkerRow(row) {
    return !!row && typeof row === 'object' && Object.prototype.hasOwnProperty.call(row, 'slug');
  }

  // The listing location a pin marks. Both projections return coordinates
  // at their stored precision, so they compare exactly. Two locations can
  // share coordinates (two practices at one address), so the location in
  // the pin's own place in the list wins: both projections list locations
  // in the same order.
  function locationAt(marker, lat, lng, index) {
    var locations = marker && Array.isArray(marker.locations) ? marker.locations : [];
    function at(location) {
      return !!location && Number(location.latitude) === lat && Number(location.longitude) === lng;
    }
    if (at(locations[index])) {
      return locations[index];
    }
    for (var i = 0; i < locations.length; i += 1) {
      if (at(locations[i])) {
        return locations[i];
      }
    }
    return null;
  }

  function drawsUngrouped(cfg, pinCount) {
    return !cfg.cluster && pinCount <= UNGROUPED_PIN_LIMIT;
  }

  // The preload threshold ("Preload pin details for up to"): at or under
  // it, every matching listing's details load with the map.
  function preloads(total, threshold) {
    return typeof total === 'number' && total > 0 && total <= threshold;
  }

  // Each listing's map marker for one points response, fetched by id when a
  // pin is opened and kept for the next open, or held from one preload of
  // every match. reset() starts afresh for the next points response, because
  // whether a listing still matches, and its distance, depend on the
  // request. fetchMarkers(query) resolves to a /directory/markers response
  // body.
  function createPinDetails(fetchMarkers) {
    var filters = {};
    var memo = {};
    // The preload in flight, if any; a pin opened meanwhile waits for it.
    var preloading = null;

    function markerIn(body, id) {
      if (!body || !Array.isArray(body.data)) {
        throw new Error('No markers in the response.');
      }
      for (var i = 0; i < body.data.length; i += 1) {
        if (body.data[i] && body.data[i].id === id) {
          return body.data[i];
        }
      }
      return null;
    }

    function request(id) {
      var held = memo;
      var entry = {};
      entry.promise = fetchMarkers(withParam(filters, 'ids', id)).then(function (body) {
        return markerIn(body, id);
      }).then(function (marker) {
        entry.marker = marker;
        return marker;
      }, function (error) {
        // A failed request is not kept, so opening the pin again retries.
        if (held[id] === entry) {
          delete held[id];
        }
        throw error;
      });
      held[id] = entry;
      return entry.promise;
    }

    function get(id) {
      if (memo[id]) {
        return memo[id].promise;
      }
      if (preloading) {
        // A listing the preload did not hold, or a failed preload, falls
        // back to its own request, unless a newer points response has
        // replaced the pin meanwhile.
        var held = memo;
        return preloading.then(function () {
          return memo === held ? get(id) : null;
        });
      }
      return request(id);
    }

    return {
      reset: function (nextFilters) {
        filters = nextFilters || {};
        memo = {};
        preloading = null;
      },
      // One request for every matching listing's map marker, up to limit.
      // A failure is silent: each pin then loads its own details.
      preload: function (limit) {
        var held = memo;
        var settled = fetchMarkers(withParam(filters, 'limit', limit)).then(function (body) {
          if (body && Array.isArray(body.data)) {
            body.data.forEach(function (row) {
              if (row && typeof row.id === 'string' && !held[row.id]) {
                held[row.id] = { promise: Promise.resolve(row), marker: row };
              }
            });
          }
        }).catch(function () {}).then(function () {
          if (preloading === settled) {
            preloading = null;
          }
        });
        preloading = settled;
      },
      // The listing's map marker, or null once the response says it no
      // longer matches; rejects when the request fails.
      get: get,
      // What get() would settle to, when it already has: { marker }.
      peek: function (id) {
        var entry = memo[id];
        return entry && Object.prototype.hasOwnProperty.call(entry, 'marker') ? { marker: entry.marker } : null;
      },
    };
  }

  // The viewport as the `bbox` query value, north,south,east,west. Leaflet
  // keeps counting longitude past 180 once the view reaches the date line,
  // and the API takes -180 to 180 only and cannot match a box that crosses
  // it. So the box moves to the copy of the world its centre is on, and
  // whatever still lies past the line, on the side away from the centre, is
  // left out.
  function bboxParam(north, south, east, west) {
    var shift = Math.round((east + west) / 2 / 360) * 360;
    return [north, south, Math.min(east - shift, 180), Math.max(west - shift, -180)].map(function (edge) {
      return Number(edge).toFixed(5);
    }).join(',');
  }

  function addressOf(location) {
    var street = [location.address_line_1, location.address_line_2].filter(Boolean).join(', ');
    var locality = [location.city, location.state, location.postcode].filter(Boolean).join(' ');
    return [street, locality].filter(Boolean).join(', ');
  }

  // A teardrop pin whose fill follows the text colour, so the pin colour is
  // one CSS custom property (see assets/css/directory-map.css).
  var PIN_SVG = '<svg viewBox="0 0 28 36" width="28" height="36" aria-hidden="true" focusable="false">' +
    '<path d="M14 0C6.3 0 0 6.2 0 13.9 0 24.3 14 36 14 36s14-11.7 14-22.1C28 6.2 21.7 0 14 0z" fill="currentColor"/>' +
    '<circle cx="14" cy="14" r="5" fill="#fff"/></svg>';

  // Map button icons, drawn in the button's text colour.
  var FULLSCREEN_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' +
    '<path d="M4 9V4h5M15 4h5v5M20 15v5h-5M9 20H4v-5" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
  var EXIT_FULLSCREEN_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' +
    '<path d="M9 4v5H4M20 9h-5V4M15 20v-5h5M4 15h5v5" fill="none" stroke="currentColor" stroke-width="2"/></svg>';
  var LOCATE_SVG = '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false">' +
    '<circle cx="12" cy="12" r="7" fill="none" stroke="currentColor" stroke-width="2"/>' +
    '<circle cx="12" cy="12" r="2.5" fill="currentColor"/>' +
    '<path d="M12 2v3M12 19v3M2 12h3M19 12h3" stroke="currentColor" stroke-width="2"/></svg>';

  function detailHref(cfg, catalogue, slug) {
    if (catalogue && typeof catalogue.hrefFor === 'function') {
      return catalogue.hrefFor(slug);
    }
    if (!cfg.detailBase) {
      return '';
    }
    var base = cfg.detailBase;
    if (cfg.prettyLinks) {
      return base.replace(/\/?$/, '/') + 'listing/' + encodeURIComponent(slug) + '/';
    }
    return base + (base.indexOf('?') === -1 ? '?' : '&') + 'agend_listing=' + encodeURIComponent(slug);
  }

  function popupContent(cfg, catalogue, marker, location) {
    var box = el('div', 'agend-map-popup');
    var logo = safeUrl(marker.logo_url);
    if (cfg.popup.logo && logo) {
      var img = el('img', 'agend-map-popup__logo');
      img.src = logo;
      img.alt = '';
      img.loading = 'lazy';
      box.appendChild(img);
    }
    var body = el('div', 'agend-map-popup__body');
    if (cfg.popup.category && marker.primary_category && marker.primary_category.name) {
      body.appendChild(el('span', 'agend-map-popup__category', marker.primary_category.name));
    }
    body.appendChild(el('strong', 'agend-map-popup__title', marker.name || ''));
    if (cfg.popup.locationName && location && typeof location.name === 'string' && location.name.trim()) {
      body.appendChild(el('span', 'agend-map-popup__location-name', location.name.trim()));
    }
    if (cfg.popup.address) {
      var address = addressOf(location);
      if (address) {
        body.appendChild(el('span', 'agend-map-popup__address', address));
      }
    }
    if (cfg.popup.distance) {
      var distance = formatDistance(marker.distance_km);
      if (distance) {
        body.appendChild(el('span', 'agend-map-popup__distance', distance));
      }
    }
    if (cfg.popup.rating && typeof marker.average_rating === 'number' && marker.review_count > 0) {
      body.appendChild(el('span', 'agend-map-popup__rating', '★ ' + marker.average_rating.toFixed(1) + ' (' + marker.review_count + ')'));
    }
    var href = marker.slug ? detailHref(cfg, catalogue, marker.slug) : '';
    if (href) {
      var link = el('a', 'agend-map-popup__link', cfg.popup.linkText);
      link.href = href;
      link.addEventListener('click', function (e) {
        // The catalogue knows whether a listing opens in place or on its own
        // page; a modified click still opens a new tab natively.
        if (catalogue && typeof catalogue.open === 'function' && !e.metaKey && !e.ctrlKey && !e.shiftKey && e.button === 0) {
          e.preventDefault();
          catalogue.open(marker.slug);
        }
      });
      body.appendChild(link);
    }
    var route = directionsHref(cfg.popup.directions, location);
    if (route) {
      var directions = el('a', 'agend-map-popup__directions', cfg.popup.directionsText);
      directions.href = route;
      directions.target = '_blank';
      directions.rel = 'noopener';
      body.appendChild(directions);
    }
    box.appendChild(body);
    return box;
  }

  // Pin details that are not a listing yet: loading, gone or failed.
  function popupStatus(message) {
    var box = el('div', 'agend-map-popup agend-map-popup--status');
    var status = el('span', 'agend-map-popup__status', message);
    status.setAttribute('role', 'status');
    box.appendChild(status);
    return box;
  }

  // A map point's pin: its details load when it is opened, and the popup
  // redraws itself once they have arrived or failed. show(marker) builds a
  // found listing's details; text carries the status wording, with
  // fallbacks for a page cached before the wording existed.
  function bindPinDetails(pin, id, details, show, text, popupOptions) {
    var loading = false;
    var failed = false;

    // Redraws an open popup. Focus inside it (on Try again, which the
    // redraw removes) moves to the new content, not back to the page.
    function refresh() {
      if (!pin.isPopupOpen()) {
        failed = false;
        return;
      }
      var popup = pin.getPopup();
      var node = popup.getElement();
      var focused = !!node && node.contains(document.activeElement);
      popup.update();
      var content = focused ? node.querySelector('.leaflet-popup-content') : null;
      if (content) {
        content.setAttribute('tabindex', '-1');
        content.focus();
      }
    }

    pin.bindPopup(function () {
      var held = details.peek(id);
      if (held) {
        return held.marker ? show(held.marker) : popupStatus(text.detailsGone || 'These details are no longer available.');
      }
      if (failed) {
        failed = false;
        var box = popupStatus((text.detailsFailed || 'Details could not be loaded.') + ' ');
        var again = el('button', 'agend-map__retry', text.retry || 'Try again');
        again.type = 'button';
        again.addEventListener('click', function (e) {
          // The details are redrawn under this click, so Leaflet could no
          // longer trace it to the popup and would close it as a click
          // on the map.
          e.stopPropagation();
          refresh();
        });
        box.firstChild.appendChild(again);
        return box;
      }
      // One request however often the popup reopens or redraws meanwhile,
      // so a failure is shown once and stays.
      if (!loading) {
        loading = true;
        details.get(id).catch(function () {
          failed = true;
        }).then(function () {
          loading = false;
          refresh();
        });
      }
      return popupStatus(text.detailsLoading || 'Loading details…');
    }, popupOptions);
  }

  // Draws each points response into a layer of its own: a group when the
  // pins are grouped, adding tens of thousands of them in chunks so the page
  // never stalls. A chunked add cannot be cancelled, and the vendored
  // markercluster (1.5.3) throws from its next chunk if the group has left
  // the map. So a group replaced mid-add is emptied, its remaining chunks
  // add nothing, and it leaves the map once they have run out.
  function createPinLayers(L, map, cfg, groupOptions) {
    var current = null;

    function newGroup() {
      var group = null;
      var options = withParam(groupOptions, 'chunkProgress', function (processed, total) {
        if (processed === total) {
          group.agendAdding = false;
          if (group.agendRetired) {
            // After the chunk that called this has finished with the map.
            window.setTimeout(function () {
              map.removeLayer(group);
            }, 0);
          }
        }
      });
      group = L.markerClusterGroup(options);
      return group;
    }

    function retire(layer) {
      if (!layer.agendAdding) {
        map.removeLayer(layer);
        return;
      }
      layer.clearLayers();
      layer._addLayer = function () {};
      layer.agendRetired = true;
    }

    return function show(pins) {
      if (current) {
        retire(current);
      }
      if (typeof L.markerClusterGroup === 'function' && !drawsUngrouped(cfg, pins.length)) {
        current = newGroup();
        map.addLayer(current);
        current.agendAdding = true;
        current.addLayers(pins);
      } else {
        current = L.layerGroup(pins);
        map.addLayer(current);
      }
    };
  }

  // The request for every pin: the catalogue's filters as map points.
  function pointsQuery(filters) {
    return withParam(filters, 'projection', 'points');
  }

  // What follows a points response: the last response's details are
  // dropped, the pins drawn, and only then, when few enough listings match,
  // every pin's details preloaded. Map markers from a gateway without
  // points mode already hold their details.
  function showResponse(rows, total, filters, threshold, details, draw) {
    details.reset(filters);
    draw(rows);
    if (rows.length && !isMarkerRow(rows[0]) && preloads(total, threshold)) {
      details.preload(threshold);
    }
  }

  // The directions template from the server, with the listing's own
  // coordinates in place of {lat} and {lng}.
  function directionsHref(template, location) {
    var lat = Number(location.latitude);
    var lng = Number(location.longitude);
    if (!template || !validPoint(lat, lng)) {
      return '';
    }
    // split / join replaces every placeholder, not only the first.
    return safeUrl(template.split('{lat}').join(String(lat)).split('{lng}').join(String(lng)));
  }

  // The map in full screen, or asking for it; one at a time. A single set
  // of document listeners serves every map, so a map re-rendered by an
  // editor leaves none behind pointing at it.
  var fullscreenMap = null;

  function onFullscreenChange() {
    if (!fullscreenMap) {
      return;
    }
    var current = document.fullscreenElement || document.webkitFullscreenElement;
    if (current === fullscreenMap.root) {
      fullscreenMap.enteredApi();
    } else if (fullscreenMap.viaApi) {
      fullscreenMap.close();
    }
  }

  document.addEventListener('fullscreenchange', onFullscreenChange);
  document.addEventListener('webkitfullscreenchange', onFullscreenChange);
  document.addEventListener('keydown', function (e) {
    // The browser's own full screen handles Escape itself.
    if (e.key === 'Escape' && fullscreenMap && !fullscreenMap.viaApi) {
      fullscreenMap.close();
    }
  });

  function initMap(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-map-config'));
    } catch (e) {
      return;
    }
    var canvas = root.querySelector('.agend-map__canvas');
    var notice = root.querySelector('.agend-map__notice');
    if (!canvas || !window.L) {
      return;
    }
    var L = window.L;

    // With mouse-wheel zoom off, the map takes Google Maps' "cooperative"
    // gestures: the page scrolls past it, Ctrl (Command on a Mac) + scroll
    // zooms it, and on a phone one finger scrolls the page while two move
    // the map. Each gesture that would have been caught shows how.
    var cooperative = !cfg.scrollZoom;
    var touchFirst = cooperative && typeof window.matchMedia === 'function' && window.matchMedia('(pointer: coarse)').matches;
    var isFullscreen = false;
    // On a touch-first device, the pointer the visitor last pressed with: a
    // mouse or trackpad (a tablet's keyboard cover, a Chromebook) drags the
    // map as on any desktop.
    var lastPointer = 'touch';

    var map = L.map(canvas, {
      // Wheel zoom stays on; the cooperative listener below decides which
      // wheel events reach it.
      scrollWheelZoom: true,
      // Without one-finger dragging, Leaflet leaves the page free to scroll
      // under one finger, and its pinch handler moves the map under two.
      dragging: !touchFirst,
      // Pins are drawn on one copy of the world only, so a visitor dragging
      // onto the next copy is moved back to the same place on this one.
      worldCopyJump: true,
      zoomControl: true,
      attributionControl: true,
      center: FALLBACK_CENTRE,
      zoom: FALLBACK_ZOOM,
    });
    // Leaflet's own credit is optional; the map data's licence requires
    // the OpenStreetMap (and CARTO) one, which stays.
    map.attributionControl.setPrefix(false);
    L.tileLayer(cfg.tiles.url, {
      maxZoom: cfg.tiles.maxZoom,
      maxNativeZoom: cfg.tiles.maxNativeZoom,
      attribution: cfg.tiles.attribution,
    }).addTo(map);
    L.control.scale({ position: 'bottomleft', metric: true, imperial: false, maxWidth: 200 }).addTo(map);

    function syncDragging() {
      if (!touchFirst) {
        return;
      }
      map.dragging[isFullscreen || lastPointer === 'mouse' ? 'enable' : 'disable']();
    }

    // A live region is only reliably announced when its text changes after
    // it is in the page, so the hint goes in empty now and is filled later.
    var hint = el('div', 'agend-map__hint');
    hint.setAttribute('role', 'status');
    canvas.appendChild(hint);
    var hintTimer = null;

    function showHint(message, ms) {
      hint.textContent = message;
      hint.classList.add('agend-map__hint--shown');
      window.clearTimeout(hintTimer);
      hintTimer = window.setTimeout(function () {
        hint.classList.remove('agend-map__hint--shown');
      }, ms || 1500);
    }

    if (cooperative) {
      var isMac = /Mac|iPhone|iPad|iPod/.test(navigator.platform || navigator.userAgent || '');
      // Caught on the way down, before Leaflet's own listener on the canvas:
      // a plain wheel goes on scrolling the page. A trackpad pinch arrives
      // as a wheel with ctrlKey set, so it zooms the map as it would on
      // Google Maps. A full-screen map has no page behind it to scroll.
      // Over a popup or a button the wheel carries on to it (a long popup
      // scrolls), and the listener below keeps it from the map.
      root.addEventListener('wheel', function (e) {
        if (e.ctrlKey || e.metaKey || isFullscreen || !canvas.contains(e.target) || e.target.closest('.leaflet-popup, .leaflet-control')) {
          return;
        }
        e.stopPropagation();
        showHint(isMac ? cfg.text.scrollHintMac : cfg.text.scrollHint);
      }, { capture: true, passive: true });

      // Leaflet stops the wheel only inside a popup's content, so a wheel
      // over its edge or over a button would otherwise zoom the map.
      [map.getPane('popupPane'), canvas.querySelector('.leaflet-control-container')].forEach(function (node) {
        if (node) {
          node.addEventListener('wheel', function (e) {
            if (!e.ctrlKey && !e.metaKey && !isFullscreen) {
              e.stopPropagation();
            }
          }, { passive: true });
        }
      });

      if (touchFirst) {
        canvas.addEventListener('touchmove', function (e) {
          if (e.touches.length === 1 && !isFullscreen && lastPointer !== 'mouse') {
            showHint(cfg.text.touchHint);
          }
        }, { passive: true });

        // Pointer events come before Leaflet's mousedown and touchstart, so
        // the first mouse drag already moves the map.
        if (window.PointerEvent) {
          root.addEventListener('pointerdown', function (e) {
            lastPointer = e.pointerType === 'mouse' ? 'mouse' : 'touch';
            syncDragging();
          }, { capture: true, passive: true });
        }
      }
    }

    // A Leaflet-styled button in its own bar, like the zoom buttons.
    function addButton(position, className, label, icon, onClick) {
      var button = null;
      var Control = L.Control.extend({
        options: { position: position },
        onAdd: function () {
          var bar = L.DomUtil.create('div', 'leaflet-bar agend-map-control ' + className);
          button = L.DomUtil.create('a', '', bar);
          button.href = '#';
          button.setAttribute('role', 'button');
          button.title = label;
          button.setAttribute('aria-label', label);
          button.innerHTML = icon;
          L.DomEvent.disableClickPropagation(bar);
          L.DomEvent.on(button, 'click', function (e) {
            L.DomEvent.preventDefault(e);
            onClick(button);
          });
          L.DomEvent.on(button, 'keydown', function (e) {
            if (e.key === ' ') {
              L.DomEvent.preventDefault(e);
              onClick(button);
            }
          });
          return bar;
        },
      });
      map.addControl(new Control());
      return button;
    }

    if (cfg.fullscreen) {
      // The browser's own full screen where it has one for any element.
      // Otherwise (an iPhone) the map is fixed over the page, moved to the
      // end of <body> while it is: position: fixed inside a transformed
      // ancestor (an Elementor entrance animation) would only fill that
      // ancestor, and an ancestor's stacking context would cap its z-index.
      var fullscreenButton = null;
      var placeholder = null;

      var setFullscreen = function (on) {
        if (on === isFullscreen) {
          return;
        }
        isFullscreen = on;
        root.classList.toggle('agend-directory-map--fullscreen', on);
        document.documentElement.classList.toggle('agend-map-fullscreen-open', on);
        syncDragging();
        // One label, with its state in aria-pressed.
        fullscreenButton.setAttribute('aria-pressed', on ? 'true' : 'false');
        fullscreenButton.innerHTML = on ? EXIT_FULLSCREEN_SVG : FULLSCREEN_SVG;
        // A resize, not the visitor moving the map: with "Update results as
        // the map moves" on it must not narrow the results.
        moveQuietly(function () {
          map.invalidateSize();
        });
      };

      var state = {
        root: root,
        viaApi: false,
        enteredApi: function () {
          state.viaApi = true;
          setFullscreen(true);
        },
        close: function () {
          state.viaApi = false;
          if (placeholder) {
            if (placeholder.parentNode) {
              placeholder.parentNode.replaceChild(root, placeholder);
            } else {
              // The map's place on the page has gone (an editor re-render).
              root.parentNode && root.parentNode.removeChild(root);
            }
            placeholder = null;
          }
          setFullscreen(false);
          if (fullscreenMap === state) {
            fullscreenMap = null;
          }
        },
      };

      var enterFallback = function () {
        if (fullscreenMap !== state || isFullscreen) {
          return;
        }
        placeholder = document.createComment('agend-map');
        root.parentNode.insertBefore(placeholder, root);
        document.body.appendChild(root);
        setFullscreen(true);
      };

      fullscreenButton = addButton('topright', 'agend-map-control--fullscreen', cfg.text.fullscreen, FULLSCREEN_SVG, function () {
        if (isFullscreen) {
          var exit = document.exitFullscreen || document.webkitExitFullscreen;
          if (state.viaApi && exit) {
            // fullscreenchange closes it.
            exit.call(document);
          } else {
            state.close();
          }
          return;
        }
        if (fullscreenMap && fullscreenMap !== state) {
          fullscreenMap.close();
        }
        fullscreenMap = state;
        var request = root.requestFullscreen || root.webkitRequestFullscreen;
        if (!request) {
          enterFallback();
          return;
        }
        try {
          var asked = request.call(root);
          if (asked && typeof asked.catch === 'function') {
            asked.catch(enterFallback);
          }
        } catch (e) {
          enterFallback();
        }
      });
      fullscreenButton.setAttribute('aria-pressed', 'false');
    }

    var here = L.layerGroup().addTo(map);

    if (cfg.locate && navigator.geolocation && window.isSecureContext !== false) {
      addButton('topleft', 'agend-map-control--locate', cfg.text.locate, LOCATE_SVG, function (button) {
        if (button.getAttribute('aria-busy') === 'true') {
          return;
        }
        button.setAttribute('aria-busy', 'true');
        navigator.geolocation.getCurrentPosition(function (position) {
          button.removeAttribute('aria-busy');
          var point = L.latLng(position.coords.latitude, position.coords.longitude);
          here.clearLayers();
          L.circleMarker(point, { radius: 7, className: 'agend-map-here', weight: 2, fillOpacity: 1 })
            .bindTooltip(cfg.text.here)
            .addTo(here);
          // A move the visitor asked for, so "Update results as the map
          // moves" follows it like a drag. Its area is centred on the
          // visitor, so it is marked as theirs and kept out of the page
          // address; not animated, so the move ends inside this mark.
          moveOrigin = 'device';
          map.setView(point, Math.max(map.getZoom(), 13), { animate: false });
          moveOrigin = 'visitor';
        }, function () {
          button.removeAttribute('aria-busy');
          showHint(cfg.text.locateFailed, 3000);
        }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 300000 });
      });
    }

    var pinIcon = L.divIcon({
      className: 'agend-map-pin',
      html: PIN_SVG,
      iconSize: [28, 36],
      iconAnchor: [14, 36],
      popupAnchor: [0, -32],
    });

    var showPins = createPinLayers(L, map, cfg, {
      maxClusterRadius: 80,
      showCoverageOnHover: false,
      chunkedLoading: true,
      iconCreateFunction: function (cluster) {
        var count = cluster.getChildCount();
        var size = count < 10 ? 'small' : (count < 100 ? 'medium' : 'large');
        return L.divIcon({
          html: '<span>' + count + '</span>',
          className: 'agend-map-cluster agend-map-cluster--' + size,
          iconSize: L.point(40, 40),
        });
      },
    });
    var searchArea = L.layerGroup().addTo(map);
    var POPUP_OPTIONS = { className: 'agend-map-popup-wrap', maxWidth: 260, minWidth: 200 };
    var details = createPinDetails(function (query) {
      return apiGet('/directory/markers', query);
    });

    var catalogue = null;
    var requestId = 0;
    var lastBounds = null;
    var fitPending = false;
    var programmatic = false;
    var userMoved = false;
    var moveTimer = null;
    // Where the move in progress came from: the visitor (a drag or a zoom),
    // the map itself ('map', showing it), or "Show my location" ('device').
    // Only the visitor's area may enter the page address.
    var moveOrigin = 'visitor';
    // The map search area the map last wrote or fitted itself to.
    var shownArea = '';
    var fits = 0;

    function say(message) {
      if (!notice) {
        return;
      }
      notice.textContent = message || '';
      notice.hidden = !message;
    }

    function visible() {
      return canvas.offsetWidth > 0 && canvas.offsetHeight > 0;
    }

    // Moves the map without it counting as the visitor moving it.
    function moveQuietly(fn) {
      programmatic = true;
      try {
        fn();
      } catch (e) {
        map.setView(FALLBACK_CENTRE, FALLBACK_ZOOM, { animate: false });
      }
      window.setTimeout(function () {
        programmatic = false;
      }, 50);
    }

    function fitTo(bounds) {
      lastBounds = bounds;
      fits += 1;
      if (!visible()) {
        // A hidden map has no size to fit to; do it once it is shown.
        fitPending = true;
        return;
      }
      fitPending = false;
      moveQuietly(function () {
        map.fitBounds(bounds, { padding: [24, 24], maxZoom: 15, animate: false });
      });
    }

    // The account's own extent, the view before anything else is known. Once
    // something else has been fitted (a restored map search area) it would
    // only move the map off what the results are bounded by.
    function fitToAccount(onlyFirst) {
      var before = fits;
      mapSettings().then(function (settings) {
        if (!settings || (onlyFirst && fits !== before)) {
          return;
        }
        var b = settings.listing_bounds;
        if (b && validPoint(Number(b.north), Number(b.east)) && validPoint(Number(b.south), Number(b.west))) {
          fitTo(L.latLngBounds([Number(b.south), Number(b.west)], [Number(b.north), Number(b.east)]));
          return;
        }
        var d = settings.default_location;
        if (d && validPoint(Number(d.latitude), Number(d.longitude))) {
          fitTo(L.latLng(Number(d.latitude), Number(d.longitude)).toBounds(25000));
        }
      });
    }

    function drawSearchArea(params) {
      searchArea.clearLayers();
      var lat = Number(params.lat);
      var lng = Number(params.lng);
      var radius = Number(params.radius);
      if (!validPoint(lat, lng) || !isFinite(radius) || radius <= 0) {
        return null;
      }
      var centre = L.latLng(lat, lng);
      if (cfg.showRadius) {
        L.circle(centre, { radius: radius * 1000, className: 'agend-map-radius', weight: 1, fillOpacity: 0.08, interactive: false }).addTo(searchArea);
      }
      L.circleMarker(centre, { radius: 7, className: 'agend-map-you', weight: 2, fillOpacity: 1, interactive: false })
        .bindTooltip(cfg.text.you)
        .addTo(searchArea);
      return centre.toBounds(radius * 2000);
    }

    function draw(rows, params, refit) {
      var layers = [];
      var points = [];
      rows.forEach(function (row) {
        var full = isMarkerRow(row);
        (Array.isArray(row.locations) ? row.locations : []).forEach(function (location, index) {
          var lat = Number(location.latitude);
          var lng = Number(location.longitude);
          if (!validPoint(lat, lng)) {
            return;
          }
          var pin = L.marker([lat, lng], { icon: pinIcon, title: row.name || '', alt: row.name || '', keyboard: true, riseOnHover: true });
          if (full) {
            pin.bindPopup(function () {
              return popupContent(cfg, catalogue, row, location);
            }, POPUP_OPTIONS);
          } else {
            bindPinDetails(pin, row.id, details, function (marker) {
              return popupContent(cfg, catalogue, marker, locationAt(marker, lat, lng, index) || { latitude: lat, longitude: lng });
            }, cfg.text, POPUP_OPTIONS);
          }
          layers.push(pin);
          points.push([lat, lng]);
        });
      });
      showPins(layers);

      var area = drawSearchArea(params);
      if (!refit) {
        return;
      }
      if (area) {
        fitTo(area);
      } else if (points.length) {
        fitTo(L.latLngBounds(points));
      } else {
        fitToAccount();
      }
    }

    // A failed load retries once on its own (a busy gateway or a brief rate
    // limit usually clears within seconds), then offers the visitor a retry.
    var RETRY_MS = 4000;
    var lastLoad = null;

    function sayFailed() {
      if (!notice) {
        return;
      }
      notice.textContent = cfg.text.failed + ' ';
      var again = el('button', 'agend-map__retry', cfg.text.retry || 'Try again');
      again.type = 'button';
      again.addEventListener('click', function () {
        if (lastLoad) {
          load(lastLoad.params, lastLoad.refit, 0);
        }
      });
      notice.appendChild(again);
      notice.hidden = false;
    }

    function load(params, refit, attempt) {
      lastLoad = { params: params, refit: refit };
      attempt = attempt || 0;
      var filters = markersQuery(params);
      var id = ++requestId;
      root.setAttribute('aria-busy', 'true');
      apiGet('/directory/markers', pointsQuery(filters)).then(function (body) {
        if (id !== requestId) {
          return;
        }
        if (!body || !Array.isArray(body.data)) {
          retryOrFail();
          return;
        }
        root.removeAttribute('aria-busy');
        var meta = body.meta || {};
        var total = meta.pagination && typeof meta.pagination.total === 'number' ? meta.pagination.total : body.data.length;
        if (!body.data.length) {
          say(cfg.emptyText);
        } else if (meta.truncated) {
          say(cfg.text.truncated.replace('{shown}', String(body.data.length)).replace('{total}', String(total)));
        } else {
          say('');
        }
        showResponse(body.data, total, filters, cfg.preload, details, function (rows) {
          draw(rows, filters, refit);
        });
      }).catch(function () {
        if (id !== requestId) {
          return;
        }
        retryOrFail();
      });

      function retryOrFail() {
        if (attempt < 1) {
          window.setTimeout(function () {
            if (id === requestId) {
              load(params, refit, attempt + 1);
            }
          }, RETRY_MS);
          return;
        }
        root.removeAttribute('aria-busy');
        sayFailed();
      }
    }

    function adopt(published) {
      if (!published || catalogue === published) {
        return;
      }
      catalogue = published;
      // Pins take the catalogue's accent colour unless the map sets its own.
      var accent = catalogue.root && catalogue.root.style ? catalogue.root.style.getPropertyValue('--agend-dir-accent') : '';
      if (accent) {
        root.style.setProperty('--agend-dir-accent', accent.trim());
      }
    }

    // A map search area the visitor chose: by moving this map, or by opening
    // a search link that carries one.
    function chosenArea(state) {
      return !!state && typeof state.bbox === 'string' && state.bbox !== '' && state.bboxOrigin === 'visitor' && !state.near;
    }

    function areaBounds(bbox) {
      var edges = bbox.split(',').map(Number);
      return L.latLngBounds([edges[1], edges[3]], [edges[0], edges[2]]);
    }

    function showForView(view) {
      if (!cfg.followView) {
        return;
      }
      root.classList.toggle('agend-directory-map--awaiting-view', view !== 'map');
      if (view === 'map') {
        if (catalogue && chosenArea(catalogue.state)) {
          // The results are already bounded by an area the visitor chose;
          // showing the map must not replace it.
          moveQuietly(function () {
            map.invalidateSize();
          });
        } else {
          moveOrigin = 'map';
          map.invalidateSize();
          moveOrigin = 'visitor';
        }
        if (fitPending && lastBounds) {
          fitTo(lastBounds);
        }
      }
    }

    // A map search area the map has not shown yet came from a search link:
    // the map fits to it, and not to the results it bounds.
    function follow(params) {
      var state = catalogue && catalogue.state;
      var refit = !userMoved;
      userMoved = false;
      if (chosenArea(state) && state.bbox !== shownArea) {
        shownArea = state.bbox;
        fitTo(areaBounds(state.bbox));
        refit = false;
      }
      load(params || {}, refit);
    }

    document.addEventListener('agend:directory-results', function (e) {
      var detail = e.detail || {};
      adopt(detail.catalogue);
      follow(detail.params);
    });

    document.addEventListener('agend:directory-view', function (e) {
      adopt((e.detail || {}).catalogue);
      showForView((e.detail || {}).view);
    });

    // A popup panning the map to fit (on opening, or as its details arrive)
    // is the map moving itself: following it would reload the pins and
    // close the popup the visitor just opened. Each such pan starts a move
    // and ends one, even when the next pan cuts its animation short, so
    // the pans are counted rather than flagged.
    var autoPanStarting = false;
    var autoPans = 0;
    map.on('autopanstart', function () {
      autoPanStarting = true;
    });
    map.on('movestart', function () {
      if (autoPanStarting) {
        autoPanStarting = false;
        autoPans += 1;
      }
    });

    map.on('moveend', function () {
      if (autoPans > 0) {
        autoPans -= 1;
        return;
      }
      if (!cfg.updateOnMove || programmatic || !catalogue || !catalogue.state) {
        return;
      }
      var origin = moveOrigin;
      window.clearTimeout(moveTimer);
      moveTimer = window.setTimeout(function () {
        var state = catalogue.state;
        // A location search already sets the area; moving the map around
        // it only looks closer.
        if (state.near) {
          return;
        }
        var b = map.getBounds();
        state.bbox = bboxParam(b.getNorth(), b.getSouth(), b.getEast(), b.getWest());
        state.bboxOrigin = origin;
        shownArea = state.bbox;
        userMoved = true;
        catalogue.reload();
      }, MOVE_DEBOUNCE_MS);
    });

    // Anything the map was hidden or resized through (a tab, an accordion, a
    // column that reflows) leaves Leaflet with the wrong size.
    if (typeof ResizeObserver === 'function') {
      new ResizeObserver(function () {
        // A resize (full screen, a column reflowing) is not the visitor
        // moving the map, so it never narrows the results.
        moveQuietly(function () {
          map.invalidateSize();
        });
        if (fitPending && lastBounds && visible()) {
          fitTo(lastBounds);
        }
      }).observe(canvas);
    }

    fitToAccount(true);

    // The catalogue may have loaded before this script ran, in which case its
    // first results event has already gone; it keeps the question it asked.
    var existing = window.agendCatalogues && window.agendCatalogues.listing;
    if (existing) {
      adopt(existing);
      showForView(existing.view);
      if (existing.lastParams) {
        follow(existing.lastParams);
      }
      return;
    }
    // No catalogue yet: give one on the page the rest of this turn to start,
    // then pin everything on its own.
    window.setTimeout(function () {
      if (catalogue) {
        return;
      }
      var late = window.agendCatalogues && window.agendCatalogues.listing;
      if (late) {
        adopt(late);
        showForView(late.view);
        if (late.lastParams) {
          follow(late.lastParams);
        }
        return;
      }
      // Nothing to follow: a map waiting for a switch that is not there
      // would never show.
      root.classList.remove('agend-directory-map--awaiting-view');
      map.invalidateSize();
      load({}, true);
    }, 0);
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-directory-map[data-agend-map-config]');
    Array.prototype.forEach.call(nodes, function (node) {
      if (node.getAttribute('data-agend-map-ready') === '1') {
        return;
      }
      node.setAttribute('data-agend-map-ready', '1');
      initMap(node);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  // Elementor's editor renders a widget after the page has loaded, and
  // announces its frontend through a jQuery event, not a DOM one.
  if (window.jQuery) {
    window.jQuery(window).on('elementor/frontend/init', function () {
      if (window.elementorFrontend && window.elementorFrontend.hooks) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/agend-directory-map.default', initAll);
      }
    });
  }
})();
