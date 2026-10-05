/**
 * Agend Map: pins the listings of the Directory Catalogue on the same page.
 *
 * The map follows the catalogue rather than keeping filters of its own. A
 * catalogue publishes itself on window.agendCatalogues.listing (its state,
 * the query it last asked as params(), reload(), hrefFor() and open()), and
 * dispatches two events on document:
 *  - agend:directory-results after every load, with the query it asked, so
 *    the map fetches the same listings as pins from /directory/markers;
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
    box.appendChild(body);
    return box;
  }

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

    var map = L.map(canvas, {
      scrollWheelZoom: !!cfg.scrollZoom,
      zoomControl: true,
      attributionControl: true,
      center: FALLBACK_CENTRE,
      zoom: FALLBACK_ZOOM,
    });
    L.tileLayer(cfg.tiles.url, { maxZoom: cfg.tiles.maxZoom, attribution: cfg.tiles.attribution }).addTo(map);
    L.control.scale({ position: 'bottomleft', metric: true, imperial: false, maxWidth: 200 }).addTo(map);

    var pinIcon = L.divIcon({
      className: 'agend-map-pin',
      html: PIN_SVG,
      iconSize: [28, 36],
      iconAnchor: [14, 36],
      popupAnchor: [0, -32],
    });

    var pins = cfg.cluster && typeof L.markerClusterGroup === 'function'
      ? L.markerClusterGroup({
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
      })
      : L.layerGroup();
    map.addLayer(pins);
    var searchArea = L.layerGroup().addTo(map);

    var catalogue = null;
    var requestId = 0;
    var lastBounds = null;
    var fitPending = false;
    var programmatic = false;
    var userMoved = false;
    var moveTimer = null;

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

    function fitToAccount() {
      mapSettings().then(function (settings) {
        if (!settings) {
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

    function draw(markers, params, refit) {
      pins.clearLayers();
      var points = [];
      markers.forEach(function (marker) {
        (Array.isArray(marker.locations) ? marker.locations : []).forEach(function (location) {
          var lat = Number(location.latitude);
          var lng = Number(location.longitude);
          if (!validPoint(lat, lng)) {
            return;
          }
          var pin = L.marker([lat, lng], { icon: pinIcon, title: marker.name || '', alt: marker.name || '', keyboard: true, riseOnHover: true });
          pin.bindPopup(function () {
            return popupContent(cfg, catalogue, marker, location);
          }, { className: 'agend-map-popup-wrap', maxWidth: 260, minWidth: 200 });
          pins.addLayer(pin);
          points.push([lat, lng]);
        });
      });

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
      var query = {};
      Object.keys(params || {}).forEach(function (key) {
        if (key !== 'page' && key !== 'limit' && key !== 'per_page' && key !== 'sortBy' && key !== 'sortOrder') {
          query[key] = params[key];
        }
      });
      query.limit = cfg.limit;
      var id = ++requestId;
      root.setAttribute('aria-busy', 'true');
      apiGet('/directory/markers', query).then(function (body) {
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
        draw(body.data, query, refit);
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

    function showForView(view) {
      if (!cfg.followView) {
        return;
      }
      root.classList.toggle('agend-directory-map--awaiting-view', view !== 'map');
      if (view === 'map') {
        map.invalidateSize();
        if (fitPending && lastBounds) {
          fitTo(lastBounds);
        }
      }
    }

    document.addEventListener('agend:directory-results', function (e) {
      var detail = e.detail || {};
      adopt(detail.catalogue);
      var refit = !userMoved;
      userMoved = false;
      load(detail.params || {}, refit);
    });

    document.addEventListener('agend:directory-view', function (e) {
      adopt((e.detail || {}).catalogue);
      showForView((e.detail || {}).view);
    });

    map.on('moveend', function () {
      if (!cfg.updateOnMove || programmatic || !catalogue || !catalogue.state) {
        return;
      }
      window.clearTimeout(moveTimer);
      moveTimer = window.setTimeout(function () {
        var state = catalogue.state;
        // A location search already sets the area; moving the map around
        // it only looks closer.
        if (state.near) {
          return;
        }
        var b = map.getBounds();
        state.bbox = [b.getNorth(), b.getSouth(), b.getEast(), b.getWest()].map(function (edge) {
          return Number(edge).toFixed(5);
        }).join(',');
        userMoved = true;
        catalogue.reload();
      }, MOVE_DEBOUNCE_MS);
    });

    // Anything the map was hidden or resized through (a tab, an accordion, a
    // column that reflows) leaves Leaflet with the wrong size.
    if (typeof ResizeObserver === 'function') {
      new ResizeObserver(function () {
        map.invalidateSize();
        if (fitPending && lastBounds && visible()) {
          fitTo(lastBounds);
        }
      }).observe(canvas);
    }

    fitToAccount();

    // The catalogue may have loaded before this script ran, in which case its
    // first results event has already gone; it keeps the question it asked.
    var existing = window.agendCatalogues && window.agendCatalogues.listing;
    if (existing) {
      adopt(existing);
      showForView(existing.view);
      if (existing.lastParams) {
        load(existing.lastParams, true);
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
          load(late.lastParams, true);
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
