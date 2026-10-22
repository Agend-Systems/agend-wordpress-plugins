/**
 * Search links: a directory catalogue's search carried in the page address.
 *
 * The parameter table, the encoder that writes a catalogue's state into a
 * query string, and the decoder that reads one back.
 * includes/records/search-link.php states the same read rules for the server,
 * and tests/fixtures/directory-search-link-cases.json pins the two together:
 * change a rule in one and the shared cases fail until the other matches.
 *
 * Pure: no DOM, no requests. assets/js/directory-catalogue.js reads and writes
 * the address with it, and the share control builds its link with it.
 *
 * Exposed as window.agendSearchLink.
 */
(function (root) {
  'use strict';

  // The Location filter's own geocoder limit, and the gateway's location
  // filter limits.
  var TEXT_MAX = 200;
  var LOCATION_NAME_MAX = 100;
  var LOCATION_VALUE_MAX = 100;
  var LOCATION_VALUES_MAX = 50;
  // Past the last page of any directory, so a larger number is shown page 1
  // like any other page past the end, rather than sent on to the gateway.
  var PAGE_MAX = 10000;
  var RADIUS_MAX = 1000;

  var SORTS = ['relevance', 'distance', 'name', 'created_at', 'view_count', 'rating'];
  var NUMBER = /^-?\d+(\.\d+)?$/;
  var UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

  var VALUE_LIST_CONTROLS = ['select', 'checkboxes', 'buttons'];
  var RANGE_CONTROLS = ['range', 'range_brackets'];

  // Parameter and catalogue state key for each list, in address order.
  var ID_LISTS = [['category_ids', 'categories']];
  var LOCATION_LISTS = [
    ['city', 'location_city'],
    ['state', 'location_state'],
    ['postcode', 'location_postcode'],
    ['country', 'location_country'],
  ];
  var TAG_LISTS = [['tag_ids', 'tag_ids'], ['badge_ids', 'badge_ids']];

  var NAMES = ['q', 'category_ids', 'location_name', 'city', 'state', 'postcode', 'country', 'tag_ids', 'badge_ids', 'rating', 'featured_only', 'sort', 'near', 'radius', 'address', 'bbox', 'view', 'pg'];

  // The state keys a plain filter writes, each read back from one parameter.
  var PLAIN_KEYS = ['search', 'categories', 'location_name', 'location_city', 'location_state', 'location_postcode', 'location_country', 'tag_ids', 'badge_ids', 'rating', 'featured', 'sortBy'];

  function isParam(name) {
    return NAMES.indexOf(name) !== -1 || /^(cf|rf)_./.test(name);
  }

  // ASCII whitespace only, as PHP's trim() strips it, so both parsers agree on
  // a value that starts or ends with a non-breaking space.
  function trimAscii(value) {
    return value.replace(/^[ \t\n\r\f\v]+|[ \t\n\r\f\v]+$/g, '');
  }

  // Cut by code point, as mb_substr() counts, not by UTF-16 unit.
  function cut(value, max) {
    var points = Array.from(value);
    return points.length > max ? points.slice(0, max).join('') : value;
  }

  function text(value, max) {
    return trimAscii(cut(trimAscii(value), max));
  }

  function unescapeValue(value) {
    return value.replace(/%(25|2C)/gi, function (match, code) {
      return code === '25' ? '%' : ',';
    });
  }

  function escapeValue(value) {
    return String(value).replace(/%/g, '%25').replace(/,/g, '%2C');
  }

  function splitValues(value) {
    return value.split(',').map(unescapeValue).filter(function (entry) {
      return entry !== '';
    });
  }

  // Half away from zero, as PHP's round() does, so a place written here
  // searches the same point agend_apps_directory_round_coordinates() would.
  function roundTo(value, places) {
    var factor = Math.pow(10, places);
    var rounded = Math.round(Math.abs(value) * factor * (1 + Number.EPSILON)) / factor;
    return value < 0 && rounded !== 0 ? -rounded : rounded;
  }

  function numberText(value) {
    return String(value);
  }

  // A plain decimal number without leading zeros or a trailing fractional
  // zero, so `4.0` and `04` both read as the `4` a rating filter offers.
  function numberTextOf(value) {
    var out = value.replace(/^(-?)0+(?=\d)/, '$1');
    return out.indexOf('.') === -1 ? out : out.replace(/0+$/, '').replace(/\.$/, '');
  }

  /**
   * What a catalogue's filter template lets a search link set: Decision 2.3's
   * list, from the configs of the filters the template renders.
   *
   * @param {Array} configs The `data-agend-filter` config of every filter.
   * @return {{keys: Object, cf: Object, rf: Object, near: ?{radius: number}, view: ?{default: string}}}
   */
  function readable(configs) {
    var out = { keys: {}, cf: {}, rf: {}, near: null, view: null };
    (Array.isArray(configs) ? configs : []).forEach(function (cfg) {
      if (!cfg || typeof cfg.state !== 'string' || cfg.state === '') {
        return;
      }
      if (cfg.mode === 'map') {
        var field = typeof cfg.fieldKey === 'string' ? cfg.fieldKey : '';
        if (field !== '' && VALUE_LIST_CONTROLS.indexOf(cfg.control) !== -1) {
          out.cf[field] = true;
        } else if (field !== '' && RANGE_CONTROLS.indexOf(cfg.control) !== -1) {
          out.rf[field] = true;
        }
        return;
      }
      if (cfg.mode === 'location') {
        if (!out.near) {
          var radius = Number(cfg.location && cfg.location.radius);
          out.near = { radius: isFinite(radius) && radius > 0 ? radius : 15 };
        }
        return;
      }
      if (cfg.mode === 'view') {
        if (!out.view) {
          out.view = { default: cfg.view && cfg.view.default === 'map' ? 'map' : 'list' };
        }
        return;
      }
      if (PLAIN_KEYS.indexOf(cfg.state) !== -1) {
        out.keys[cfg.state] = true;
      }
    });
    return out;
  }

  function lastOf(params, name) {
    var all = params.getAll(name);
    return all.length ? all[all.length - 1] : null;
  }

  function readNear(params, allowed) {
    var raw = lastOf(params, 'near');
    var parts = raw === null ? [] : raw.split(',');
    if (parts.length !== 2 || !NUMBER.test(parts[0]) || !NUMBER.test(parts[1])) {
      return null;
    }
    var lat = Number(parts[0]);
    var lng = Number(parts[1]);
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) {
      return null;
    }
    var radiusRaw = lastOf(params, 'radius');
    var radius = radiusRaw !== null && NUMBER.test(radiusRaw) ? Number(radiusRaw) : NaN;
    if (!(radius > 0 && radius <= RADIUS_MAX)) {
      // A point without a usable radius searches at the filter's own default,
      // as a point the visitor sets does.
      radius = allowed.near.radius;
    }
    var addressRaw = lastOf(params, 'address');
    var label = addressRaw === null ? '' : text(addressRaw, TEXT_MAX);
    return { lat: lat, lng: lng, radius: radius, label: label !== '' ? label : raw, source: 'place' };
  }

  function readBbox(params) {
    var raw = lastOf(params, 'bbox');
    var parts = raw === null ? [] : raw.split(',');
    if (parts.length !== 4 || !parts.every(function (part) { return NUMBER.test(part); })) {
      return '';
    }
    var north = Number(parts[0]);
    var south = Number(parts[1]);
    var east = Number(parts[2]);
    var west = Number(parts[3]);
    if (north > 90 || south < -90 || south > north || east < -180 || east > 180 || west < -180 || west > 180) {
      return '';
    }
    return parts.join(',');
  }

  function readRange(raw) {
    var match = raw === null ? null : /^([^:]*):([^:]*)$/.exec(raw);
    if (!match) {
      return null;
    }
    var min = trimAscii(match[1]);
    var max = trimAscii(match[2]);
    if ((min !== '' && !NUMBER.test(min)) || (max !== '' && !NUMBER.test(max)) || (min === '' && max === '')) {
      return null;
    }
    var bounds = {};
    if (min !== '') {
      bounds.min = min;
    }
    if (max !== '') {
      bounds.max = max;
    }
    return bounds;
  }

  /**
   * Reads a search link into catalogue state.
   *
   * @param {string} query   A query string, with or without its leading "?".
   * @param {Object} allowed readable() output for the catalogue's template.
   * @param {Object} options `bbox`: whether a map search area can stand on
   *                         this page (Decision 2.8).
   * @return {{state: Object, page: number}} Only the keys the link set.
   */
  function decode(query, allowed, options) {
    var params = new URLSearchParams(String(query || '').replace(/^\?/, ''));
    var keys = (allowed && allowed.keys) || {};
    var state = {};
    var page = 1;

    var pg = lastOf(params, 'pg');
    if (pg !== null && /^\d+$/.test(pg) && Number(pg) > 0) {
      page = Math.min(Number(pg), PAGE_MAX);
    }

    var q = lastOf(params, 'q');
    if (keys.search && q !== null && text(q, TEXT_MAX) !== '') {
      state.search = text(q, TEXT_MAX);
    }

    ID_LISTS.concat(TAG_LISTS).forEach(function (pair) {
      var raw = lastOf(params, pair[0]);
      if (!keys[pair[1]] || raw === null) {
        return;
      }
      var ids = splitValues(raw).filter(function (id) {
        return UUID.test(id);
      }).map(function (id) {
        return id.toLowerCase();
      });
      if (ids.length) {
        state[pair[1]] = ids;
      }
    });

    var custom = {};
    Object.keys((allowed && allowed.cf) || {}).forEach(function (field) {
      var raw = lastOf(params, 'cf_' + field);
      var values = raw === null ? [] : splitValues(raw);
      if (values.length) {
        custom[field] = values;
      }
    });
    Object.keys((allowed && allowed.rf) || {}).forEach(function (field) {
      var bounds = readRange(lastOf(params, 'rf_' + field));
      if (bounds) {
        custom[field] = bounds;
      }
    });
    if (Object.keys(custom).length) {
      state.custom_fields = custom;
    }

    var locationName = lastOf(params, 'location_name');
    if (keys.location_name && locationName !== null && text(locationName, LOCATION_NAME_MAX) !== '') {
      state.location_name = text(locationName, LOCATION_NAME_MAX);
    }

    LOCATION_LISTS.forEach(function (pair) {
      var raw = lastOf(params, pair[0]);
      if (!keys[pair[1]] || raw === null) {
        return;
      }
      var values = splitValues(raw).filter(function (value) {
        return Array.from(value).length <= LOCATION_VALUE_MAX;
      }).slice(0, LOCATION_VALUES_MAX);
      if (values.length) {
        state[pair[1]] = values;
      }
    });

    var rating = lastOf(params, 'rating');
    if (keys.rating && rating !== null && NUMBER.test(rating) && Number(rating) >= 1 && Number(rating) <= 5) {
      state.rating = numberTextOf(rating);
    }

    if (keys.featured && lastOf(params, 'featured_only') === 'true') {
      state.featured = '1';
    }

    var sort = lastOf(params, 'sort');
    if (keys.sortBy && SORTS.indexOf(sort) !== -1) {
      state.sortBy = sort;
    }

    if (allowed && allowed.near) {
      var near = readNear(params, allowed);
      if (near) {
        state.near = near;
      }
    }

    // A point wins over an area, as it does in every query the catalogue asks.
    if (options && options.bbox && !state.near) {
      var bbox = readBbox(params);
      if (bbox !== '') {
        state.bbox = bbox;
        state.bboxOrigin = 'visitor';
      }
    }

    var view = lastOf(params, 'view');
    if (allowed && allowed.view && (view === 'list' || view === 'map')) {
      state.view = view;
    }

    return { state: state, page: page };
  }

  /**
   * Whether a decoded link asks for anything but the default results. The
   * view changes how they are shown, not which they are.
   *
   * @param {{state: Object, page: number}} decoded decode() output.
   * @return {boolean}
   */
  function isSearch(decoded) {
    return decoded.page > 1 || Object.keys(decoded.state).some(function (key) {
      return key !== 'view' && key !== 'bboxOrigin';
    });
  }

  function encodeComponent(value) {
    // Commas and colons are left readable: they separate values, and a value's
    // own comma was already written as %2C, which arrives here as %252C. An
    // apostrophe is encoded, as the browser stores it in the address, so an
    // unchanged search compares equal to the address and is not written again.
    return encodeURIComponent(value).replace(/%2C/g, ',').replace(/%3A/g, ':').replace(/'/g, '%27');
  }

  function joinValues(list) {
    return list.map(escapeValue).join(',');
  }

  function nonEmptyList(value) {
    return Array.isArray(value) ? value.filter(function (entry) {
      return entry !== '' && entry !== null && entry !== undefined;
    }) : [];
  }

  function placeNear(state) {
    var near = state.near;
    if (!near || near.source !== 'place') {
      return null;
    }
    var lat = Number(near.lat);
    var lng = Number(near.lng);
    var radius = Number(near.radius);
    if (!isFinite(lat) || !isFinite(lng) || !isFinite(radius) || lat < -90 || lat > 90 || lng < -180 || lng > 180 || radius <= 0) {
      return null;
    }
    return { lat: lat, lng: lng, radius: radius, label: typeof near.label === 'string' ? near.label : '' };
  }

  /**
   * The search link parameters for a catalogue state, in Decision 2.2's order.
   *
   * @param {Object} state   The catalogue state.
   * @param {Object} options `page`; `numbered` (the pagination style writes a
   *                         page); `defaultView` (the List / Map switch's
   *                         Starts on, or null with no switch); `bbox` (a
   *                         visitor-chosen map search area may be written).
   * @return {Array<Array<string>>} [name, encoded value] pairs.
   */
  function encode(state, options) {
    var opts = options || {};
    var pairs = [];
    var put = function (name, raw) {
      pairs.push([encodeURIComponent(name), encodeComponent(raw)]);
    };

    if (typeof state.search === 'string' && trimAscii(state.search) !== '') {
      put('q', state.search);
    }
    ID_LISTS.forEach(function (pair) {
      if (nonEmptyList(state[pair[1]]).length) {
        put(pair[0], joinValues(nonEmptyList(state[pair[1]])));
      }
    });

    var custom = state.custom_fields && typeof state.custom_fields === 'object' ? state.custom_fields : {};
    var lists = [];
    var ranges = [];
    Object.keys(custom).sort().forEach(function (field) {
      var value = custom[field];
      if (Array.isArray(value)) {
        if (nonEmptyList(value).length) {
          lists.push(['cf_' + field, joinValues(nonEmptyList(value))]);
        }
        return;
      }
      if (value && typeof value === 'object') {
        var min = value.min === undefined || value.min === null ? '' : String(value.min);
        var max = value.max === undefined || value.max === null ? '' : String(value.max);
        if (min !== '' || max !== '') {
          ranges.push(['rf_' + field, min + ':' + max]);
        }
      }
    });
    lists.concat(ranges).forEach(function (entry) {
      put(entry[0], entry[1]);
    });

    if (typeof state.location_name === 'string' && trimAscii(state.location_name) !== '') {
      put('location_name', trimAscii(state.location_name));
    }
    LOCATION_LISTS.concat(TAG_LISTS).forEach(function (pair) {
      if (nonEmptyList(state[pair[1]]).length) {
        put(pair[0], joinValues(nonEmptyList(state[pair[1]])));
      }
    });

    if (state.rating !== undefined && state.rating !== null && String(state.rating) !== '') {
      put('rating', String(state.rating));
    }
    if (state.featured) {
      put('featured_only', 'true');
    }
    if (typeof state.sortBy === 'string' && state.sortBy !== '') {
      put('sort', state.sortBy);
    }

    var near = placeNear(state);
    if (near) {
      put('near', numberText(roundTo(near.lat, 3)) + ',' + numberText(roundTo(near.lng, 3)));
      put('radius', numberText(roundTo(near.radius, 1)));
      if (trimAscii(near.label) !== '') {
        put('address', near.label);
      }
    } else if (opts.bbox && typeof state.bbox === 'string' && state.bbox !== '' && state.bboxOrigin === 'visitor' && !state.near) {
      put('bbox', state.bbox);
    }

    if (opts.defaultView && (state.view === 'list' || state.view === 'map') && state.view !== opts.defaultView) {
      put('view', state.view);
    }
    if (opts.numbered && Number(opts.page) > 1) {
      put('pg', String(Math.floor(Number(opts.page))));
    }

    return pairs;
  }

  function paramName(segment) {
    var name = segment.split('=')[0].replace(/\+/g, ' ');
    try {
      return decodeURIComponent(name);
    } catch (e) {
      return name;
    }
  }

  /**
   * An address with its search link parameters replaced by `pairs`. Every
   * other part, other query parameters included, is kept exactly as written.
   *
   * @param {string} href  The address.
   * @param {Array}  pairs encode() output; empty to remove them all.
   * @return {string}
   */
  function apply(href, pairs) {
    var value = String(href || '');
    var hashAt = value.indexOf('#');
    var hash = hashAt === -1 ? '' : value.slice(hashAt);
    var rest = hashAt === -1 ? value : value.slice(0, hashAt);
    var queryAt = rest.indexOf('?');
    var base = queryAt === -1 ? rest : rest.slice(0, queryAt);
    var kept = queryAt === -1 ? [] : rest.slice(queryAt + 1).split('&').filter(function (segment) {
      return segment !== '' && !isParam(paramName(segment));
    });
    var query = kept.concat((pairs || []).map(function (pair) {
      return pair[0] + '=' + pair[1];
    })).join('&');
    return base + (query ? '?' + query : '') + hash;
  }

  /**
   * The restored values no visible control writes, once every control has
   * been built: each as its state key, plus the field for a custom field.
   *
   * @param {Object} restored decode() state.
   * @param {Array}  shells   {config, hidden} for every filter in the template.
   * @return {Array<{key: string, field?: string}>}
   */
  function unwritten(restored, shells) {
    var visible = (shells || []).filter(function (shell) {
      return shell && shell.config && !shell.hidden;
    }).map(function (shell) {
      return shell.config;
    });
    var shown = readable(visible);
    var out = [];
    Object.keys(restored).forEach(function (key) {
      if (key === 'custom_fields') {
        Object.keys(restored.custom_fields).forEach(function (field) {
          var range = !Array.isArray(restored.custom_fields[field]);
          if (!(range ? shown.rf : shown.cf)[field]) {
            out.push({ key: key, field: field });
          }
        });
        return;
      }
      if (key === 'near' ? !shown.near : PLAIN_KEYS.indexOf(key) !== -1 && !shown.keys[key]) {
        out.push({ key: key });
      }
    });
    return out;
  }

  root.agendSearchLink = {
    readable: readable,
    decode: decode,
    encode: encode,
    apply: apply,
    isParam: isParam,
    isSearch: isSearch,
    unwritten: unwritten,
    roundTo: roundTo,
  };
})(typeof window !== 'undefined' ? window : this);
