/**
 * Agend Events widget — frontend renderer.
 *
 * One widget, connected client-side states (SPEC-INFRA-EVT-001 Decision 2.1):
 *  - catalogue: searchable/filterable grid (US-EVT.1/2)
 *  - detail:    single-event landing view (US-EVT.4)
 * State transitions happen without a WordPress reload. Every event is deep
 * linkable via ?agend_event=<slug> (US-EVT.5). Data comes from the Agend Apps
 * Core REST proxy (/wp-json/agend-apps/v1/events...).
 */
(function () {
  'use strict';

  var MONTHS = [
    'JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN',
    'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC',
  ];

  var TYPE_LABELS = {
    physical: 'In-Person',
    virtual: 'Online',
    hybrid: 'Hybrid',
  };

  var DEEP_LINK_PARAM = 'agend_event';
  var PAY_PARAM = 'agend_pay';

  // The organisation timezone (IANA name, from cfg.timezone) — the FALLBACK for
  // rendering event times when an event carries no timezone of its own. Event
  // times are a fixed wall-clock in the event's own locale, so the event's
  // timezone (event.timezone) is preferred; this org value, then the viewer's
  // local timezone, apply only when it is absent or a manual offset Intl
  // rejects.
  var orgTimeZone = '';

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

  function apiGet(path, params) {
    return apiGetFrom(restBase(), path, params);
  }

  // Same query serialisation against another REST base (the plugin's own
  // card-fragment namespace).
  function apiGetFrom(base, path, params) {
    var url = String(base || '').replace(/\/$/, '') + path;
    var qs = [];
    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];
      if (value === undefined || value === null || value === '') {
        return;
      }
      // Arrays serialise PHP-style (`key[]=a&key[]=b`) so the WP REST proxy
      // parses them back into arrays before forwarding to the gateway.
      if (Array.isArray(value)) {
        value.forEach(function (item) {
          if (item !== undefined && item !== null && item !== '') {
            qs.push(encodeURIComponent(key) + '[]=' + encodeURIComponent(item));
          }
        });
        return;
      }
      qs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
    });
    if (qs.length) {
      // The REST base already carries a query string on a site with plain
      // permalinks (index.php?rest_route=...), so join with & there.
      url += (url.indexOf('?') === -1 ? '?' : '&') + qs.join('&');
    }
    return fetch(url, {
      headers: nonce() ? { 'X-WP-Nonce': nonce() } : {},
    }).then(function (res) {
      return res.json();
    });
  }

  function apiPost(path, body) {
    var url = restBase().replace(/\/$/, '') + path;
    var headers = { 'Content-Type': 'application/json' };
    if (nonce()) {
      headers['X-WP-Nonce'] = nonce();
    }
    return fetch(url, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(body || {}),
    }).then(function (res) {
      return res.json();
    });
  }

  function unwrapList(body) {
    if (body && Array.isArray(body.data)) {
      return { items: body.data, pagination: (body.meta && body.meta.pagination) || null };
    }
    if (Array.isArray(body)) {
      return { items: body, pagination: null };
    }
    return { items: [], pagination: null };
  }

  // Request headers for the shop cart endpoints. Prefers the shop's
  // AgendCartSession helper (WP REST nonce + guest cart session token); falls
  // back to the nonce alone when the shop script is somehow unavailable.
  function cartHeaders() {
    if (window.AgendCartSession && typeof window.AgendCartSession.getHeaders === 'function') {
      return window.AgendCartSession.getHeaders();
    }
    return nonce() ? { 'X-WP-Nonce': nonce() } : {};
  }

  // Adds a single product line to the Agend Apps Shop cart, mirroring the
  // shop's own Add to Cart widget: on success it persists any returned guest
  // session token so an anonymous cart survives across requests. Resolves with
  // the response payload, or rejects with an Error carrying the gateway message
  // on a non-200 response.
  function cartAddItem(productType, productId, quantity, attendees) {
    var headers = cartHeaders();
    headers['Content-Type'] = 'application/json';
    var url = restBase().replace(/\/$/, '') + '/cart/items';
    var payload = { productType: productType, productId: productId, quantity: quantity };
    // Attendee assignments are optional; only include them when at least one
    // seat was captured. The gateway validates each attendee against the
    // ticket's event attendee-field definitions and defaults uncaptured seats
    // to the buyer at fulfilment.
    if (Array.isArray(attendees) && attendees.length) {
      payload.attendees = attendees;
    }
    return fetch(url, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(payload),
    }).then(function (res) {
      return res.json().then(function (body) {
        return { status: res.status, data: body && body.data };
      });
    }).then(function (result) {
      if (result.status !== 200) {
        var message = (result.data && result.data.body && result.data.body.error && result.data.body.error.message)
          ? result.data.body.error.message
          : 'Unable to add to cart. Please try again.';
        throw new Error(message);
      }
      if (result.data && result.data.guestSessionToken && window.AgendCartSession) {
        window.AgendCartSession.setToken(result.data.guestSessionToken);
      }
      return result.data;
    });
  }

  // Maps a registration line's per-seat rows to the cart attendee shape. A seat
  // with both a name and email is transmitted as a `named` attendee (the cart
  // schema requires an email or contact for a named attendee); any other seat
  // is `unnamed` and defaults to the buyer at fulfilment. Returns undefined
  // when no seat was named, so the line is added without attendee data.
  function cartAttendeesForLine(line) {
    var rows = (line && line.attendeeRows) || [];
    var anyNamed = false;
    var attendees = rows.map(function (row) {
      var name = ((row && row.name) || '').trim();
      var email = ((row && row.email) || '').trim();
      if (name && email) {
        anyNamed = true;
        return {
          beneficiary_type: 'named',
          beneficiary_name: name,
          beneficiary_email: email,
        };
      }
      return { beneficiary_type: 'unnamed' };
    });
    return anyNamed ? attendees : undefined;
  }

  // Theme tokens in site config are either hex (#RRGGBB) or shadcn-style HSL
  // triplets ("230 37% 16%"); normalise both to a CSS colour value.
  function normaliseColour(value) {
    if (typeof value !== 'string' || !value) {
      return null;
    }
    if (value.charAt(0) === '#' || value.indexOf('(') !== -1) {
      return value;
    }
    if (/^\d/.test(value) && value.indexOf('%') !== -1) {
      return 'hsl(' + value + ')';
    }
    return value;
  }

  // Applies the connected account's published theme (fonts/colours) to the
  // widget root when inheritance is enabled (US-EVT.8/9). Fire-and-forget: the
  // CSS custom properties update live once the config resolves.
  function applySiteTheme(root, cfg) {
    if (!cfg.theme || (!cfg.theme.inheritFonts && !cfg.theme.inheritColours)) {
      return;
    }
    apiGet('/sites/config', {}).then(function (body) {
      var config = unwrapOne(body);
      if (!config) {
        return;
      }
      var theme = config.theme || {};
      if (cfg.theme.inheritColours && theme.colors) {
        var c = theme.colors;
        var heading = normaliseColour(c.primary || c.navy || c.foreground);
        var body2 = normaliseColour(c.foreground || c.body);
        var accent = normaliseColour(c.accent || c.coral || c.ring);
        if (heading) {
          root.style.setProperty('--agend-ev-heading', heading);
        }
        if (body2) {
          root.style.setProperty('--agend-ev-body', body2);
        }
        if (accent) {
          root.style.setProperty('--agend-ev-accent', accent);
          root.style.setProperty('--agend-ev-button', accent);
        }
      }
      if (cfg.theme.inheritFonts && theme.fonts) {
        if (theme.fonts.heading) {
          root.style.setProperty('--agend-ev-font-heading', '"' + theme.fonts.heading + '", sans-serif');
        }
        if (theme.fonts.body) {
          root.style.setProperty('--agend-ev-font-body', '"' + theme.fonts.body + '", sans-serif');
        }
      }
    }).catch(function () {
      /* site config unavailable — fall back to the editor colours */
    });
  }

  function unwrapOne(body) {
    if (body && body.data && !Array.isArray(body.data)) {
      return body.data;
    }
    if (body && body.success === false) {
      return null;
    }
    return body || null;
  }

  function formatPrice(value) {
    var num = typeof value === 'string' ? parseFloat(value) : value;
    if (num === null || num === undefined || isNaN(num)) {
      return null;
    }
    return num === 0 ? 'FREE' : '$' + num.toFixed(2);
  }

  function truncate(text, length) {
    if (!text) {
      return '';
    }
    return text.length <= length ? text : text.slice(0, length).replace(/\s+\S*$/, '') + '…';
  }

  // Reduce (possibly HTML) rich text to plain text for card excerpts.
  function stripHtml(html) {
    if (!html) {
      return '';
    }
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
  }

  // Render semi-trusted CMS rich text (event descriptions authored by
  // association staff) as HTML, after stripping active content: script/style/
  // iframe/link/meta elements, inline event handlers, and javascript: URLs.
  // Allowlist mirrors the server-side sanitiser (@agend/lms/utils/sanitize-html
  // + the WP proxy's wp_kses_post) so the three layers agree on what safe rich
  // text looks like.
  var SAFE_TAGS = [
    'div', 'span', 'p', 'br', 'hr',
    'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
    'strong', 'b', 'em', 'i', 'u', 'strike', 's', 'del', 'ins', 'mark', 'sub', 'sup', 'small',
    'ul', 'ol', 'li',
    'a',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
    'blockquote', 'q', 'cite',
    'code', 'pre', 'kbd', 'samp',
    'img',
    'figure', 'figcaption', 'details', 'summary',
  ];
  var SAFE_ATTR = [
    'href', 'target', 'rel', 'class', 'id',
    'colspan', 'rowspan', 'scope', 'align', 'valign',
    'src', 'alt', 'width', 'height', 'loading',
  ];

  // Renders semi-trusted CMS rich text (association-authored event/course
  // descriptions) as HTML. Defence-in-depth: even though the gateway and the
  // WP proxy sanitise upstream, this is the final gate before innerHTML. Uses
  // the vendored DOMPurify (Cure53); if for any reason it is unavailable, it
  // fails CLOSED to plain text rather than trusting the input.
  function setSafeHtml(node, html) {
    if (!html) {
      node.textContent = '';
      return;
    }
    if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
      node.innerHTML = window.DOMPurify.sanitize(html, {
        ALLOWED_TAGS: SAFE_TAGS,
        ALLOWED_ATTR: SAFE_ATTR,
        ALLOW_DATA_ATTR: false,
        FORBID_TAGS: ['style', 'script', 'iframe', 'form', 'input', 'button', 'object', 'embed'],
      });
      return;
    }
    // Fail closed: no sanitiser, no HTML.
    node.textContent = stripHtml(html);
  }

  // Event times are shown in the event's own timezone (event.timezone), which
  // is the authoritative wall-clock for the event. The org timezone is the
  // fallback, then the viewer's local timezone. Resolves to '' when neither is
  // set so Intl uses the local timezone.
  function eventZone(tz) {
    return tz || orgTimeZone || '';
  }

  // Merges a timezone into Intl options when one is resolved.
  function tzOpts(opts, tz) {
    var zone = eventZone(tz);
    return zone ? Object.assign({}, opts, { timeZone: zone }) : opts;
  }

  // Locale date/time in the given timezone, falling back to the viewer's local
  // timezone when none resolves or the value is not a zone Intl accepts (e.g. a
  // manual "+10:00" offset).
  function localeDate(iso, opts, tz) {
    var d = new Date(iso);
    try {
      return d.toLocaleDateString('en-AU', tzOpts(opts, tz));
    } catch (e) {
      return d.toLocaleDateString('en-AU', opts);
    }
  }

  function localeTime(iso, opts, tz) {
    var d = new Date(iso);
    try {
      return d.toLocaleTimeString('en-AU', tzOpts(opts, tz));
    } catch (e) {
      return d.toLocaleTimeString('en-AU', opts);
    }
  }

  // Short timezone abbreviation (e.g. "AEST") for the resolved zone, so times
  // shown in a zone other than the viewer's are not ambiguous. Empty when no
  // explicit zone resolves (the time is then in the viewer's own timezone).
  function zoneLabel(iso, tz) {
    if (!eventZone(tz)) {
      return '';
    }
    try {
      var parts = new Intl.DateTimeFormat('en-AU', tzOpts({ hour: 'numeric', timeZoneName: 'short' }, tz)).formatToParts(new Date(iso));
      for (var i = 0; i < parts.length; i++) {
        if (parts[i].type === 'timeZoneName') {
          return parts[i].value;
        }
      }
    } catch (e) {
      /* invalid timeZone — no label */
    }
    return '';
  }

  // The calendar day and month index for an ISO timestamp in the given
  // timezone, for the card date badge (which indexes the MONTHS array).
  function zonedDateParts(iso, tz) {
    var d = new Date(iso);
    try {
      var parts = new Intl.DateTimeFormat('en-US', tzOpts({ day: 'numeric', month: 'numeric' }, tz)).formatToParts(d);
      var find = function (type) {
        for (var i = 0; i < parts.length; i++) {
          if (parts[i].type === type) {
            return parseInt(parts[i].value, 10);
          }
        }
        return NaN;
      };
      var day = find('day');
      var month = find('month');
      if (!isNaN(day) && !isNaN(month)) {
        return { day: day, month: month - 1 };
      }
    } catch (e) {
      /* invalid timeZone — fall back to the viewer's local calendar */
    }
    return { day: d.getDate(), month: d.getMonth() };
  }

  function dateRange(startIso, endIso, tz) {
    if (!startIso) {
      return '';
    }
    var opts = { day: 'numeric', month: 'short', year: 'numeric' };
    var startStr = localeDate(startIso, opts, tz);
    if (!endIso) {
      return startStr;
    }
    var endStr = localeDate(endIso, opts, tz);
    return startStr === endStr ? startStr : startStr + ' – ' + endStr;
  }

  function dateTime(startIso, endIso, tz) {
    if (!startIso) {
      return '';
    }
    var dOpts = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
    var tOpts = { hour: 'numeric', minute: '2-digit' };
    var str = localeDate(startIso, dOpts, tz) + ', ' + localeTime(startIso, tOpts, tz);
    if (endIso) {
      str += ' – ' + localeTime(endIso, tOpts, tz);
    }
    var label = zoneLabel(startIso, tz);
    if (label) {
      str += ' ' + label;
    }
    return str;
  }

  function typeLabel(venueType) {
    return TYPE_LABELS[venueType] || venueType || '';
  }

  // -- Loading skeletons ------------------------------------------------------

  function skeletonLine(width) {
    var line = el('div', 'agend-skel-line');
    line.style.width = width;
    return line;
  }

  function skeletonCard() {
    var card = el('article', 'agend-ev-card agend-ev-skeleton');
    card.setAttribute('aria-hidden', 'true');
    card.appendChild(el('div', 'agend-ev-card__media'));
    var body = el('div', 'agend-ev-card__body');
    body.appendChild(skeletonLine('40%'));
    body.appendChild(skeletonLine('85%'));
    body.appendChild(skeletonLine('60%'));
    body.appendChild(skeletonLine('75%'));
    card.appendChild(body);
    return card;
  }

  // One complete grid row of placeholders (3 when the layout is a single
  // column, i.e. list-like).
  function skeletonCount(cfg) {
    var cols = (cfg.layout && cfg.layout.desktop) || 3;
    return cols === 1 ? 3 : cols;
  }

  function appendGridSkeletons(grid, cfg) {
    for (var i = 0; i < skeletonCount(cfg); i++) {
      grid.appendChild(skeletonCard());
    }
  }

  function renderDetailSkeleton() {
    var wrap = el('div', 'agend-ev-detail agend-ev-skeleton agend-ev-detail-skeleton');
    wrap.setAttribute('role', 'status');
    var hidden = el('span', 'agend-visually-hidden', 'Loading event…');
    wrap.appendChild(hidden);
    wrap.setAttribute('aria-hidden', 'false');
    wrap.appendChild(el('div', 'agend-ev-detail__hero agend-skel-block'));
    var layout = el('div', 'agend-ev-detail__layout');
    var main = el('div', 'agend-ev-detail__main');
    ['30%', '95%', '90%', '80%', '60%'].forEach(function (w) {
      main.appendChild(skeletonLine(w));
    });
    layout.appendChild(main);
    var side = el('aside', 'agend-ev-detail__side');
    side.appendChild(el('div', 'agend-ev-detail__panel agend-skel-block'));
    side.appendChild(el('div', 'agend-ev-detail__panel agend-skel-block'));
    layout.appendChild(side);
    wrap.appendChild(layout);
    return wrap;
  }

  // -- Catalogue ------------------------------------------------------------

  function renderCard(event, cfg, onOpen) {
    var card = el('article', 'agend-ev-card');
    card.setAttribute('role', 'button');
    card.setAttribute('tabindex', '0');
    card.addEventListener('click', function () {
      onOpen(event.slug);
    });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        onOpen(event.slug);
      }
    });

    if (cfg.card.image) {
      var media = el('div', 'agend-ev-card__media');
      if (event.hero_image_url) {
        var img = el('img', 'agend-ev-card__img');
        img.src = event.hero_image_url;
        img.alt = event.name || '';
        img.loading = 'lazy';
        media.appendChild(img);
      } else {
        media.classList.add('agend-ev-card__media--placeholder');
      }
      if (cfg.card.dateBadge && event.start_date) {
        var parts = zonedDateParts(event.start_date, event.timezone);
        var badge = el('div', 'agend-ev-card__date-badge');
        badge.appendChild(el('span', 'agend-ev-card__date-month', MONTHS[parts.month]));
        badge.appendChild(el('span', 'agend-ev-card__date-day', parts.day));
        media.appendChild(badge);
      }
      if (event.sold_out) {
        media.appendChild(el('span', 'agend-ev-card__soldout', 'Sold Out'));
      }
      card.appendChild(media);
    }

    var body = el('div', 'agend-ev-card__body');

    if (cfg.card.pills) {
      var pills = el('div', 'agend-ev-card__pills');
      if (event.category && event.category.name) {
        pills.appendChild(el('span', 'agend-ev-pill agend-ev-pill--category', event.category.name));
      }
      var tl = typeLabel(event.venue_type);
      if (tl) {
        pills.appendChild(el('span', 'agend-ev-pill agend-ev-pill--type', tl));
      }
      body.appendChild(pills);
    }

    body.appendChild(el('h3', 'agend-ev-card__title', event.name || ''));

    var range = dateRange(event.start_date, event.end_date, event.timezone);
    if (range) {
      body.appendChild(el('div', 'agend-ev-card__date', range));
    }
    body.appendChild(el('div', 'agend-ev-card__location', event.venue_name || (event.venue_type === 'virtual' ? 'Online' : 'TBA')));

    if (cfg.card.description) {
      var desc = stripHtml(event.short_description || event.description || '');
      if (desc) {
        body.appendChild(el('p', 'agend-ev-card__desc', truncate(desc, cfg.card.excerptLength)));
      }
    }

    // Signed-in member registered for this event: show the state on the card
    // (list responses carry my_registration when a member bearer is present).
    if (event.my_registration) {
      body.appendChild(el('div', 'agend-ev-card__registered', '✓ Registered'));
    }

    if (cfg.card.pricing && event.price_summary) {
      var group = viewerGroup(event);
      // The applicable price is the member tier for a member/corporate viewer,
      // otherwise the non-member tier (which also covers an anonymous visitor —
      // the price they would pay). Consistent with the detail ticket list.
      var cardActiveIsMember = group === 'member' || group === 'corporate';
      var pricing = el('div', 'agend-ev-card__pricing');
      [
        ['Members', event.price_summary.member_from, cardActiveIsMember],
        ['Non-Members', event.price_summary.non_member_from, !cardActiveIsMember],
      ].forEach(function (pair) {
        var price = formatPrice(pair[1]);
        if (price === null) {
          return;
        }
        // The applicable price is conveyed by weight + highlight colour
        // (.is-yours), not a text suffix (SPEC-CORE-20260722 US-2.3).
        var row = el('div', 'agend-ev-price' + (pair[2] ? ' is-yours' : ''));
        row.appendChild(el('span', 'agend-ev-price__label', pair[0]));
        row.appendChild(el('span', 'agend-ev-price__value' + (price === 'FREE' ? ' is-free' : ''), price));
        pricing.appendChild(row);
      });
      body.appendChild(pricing);
    }

    card.appendChild(body);
    return card;
  }

  // -- Detail ---------------------------------------------------------------

  // Raw price for a pricing tier's group key (member_price / non_member_price /
  // corporate_price), or undefined when that group has no explicit price. Reads
  // the ticket's first pricing tier; a flat-priced ticket has no tiers.
  function ticketTierValue(entry, key) {
    var tiers = (entry && entry.pricingTiers) || [];
    if (!tiers.length) {
      return undefined;
    }
    var tier = tiers[0].tier || tiers[0];
    var v = tier[key];
    if (v === null || v === undefined) {
      return undefined;
    }
    return typeof v === 'string' ? parseFloat(v) : v;
  }

  function priceRow(label, price, isActive) {
    var row = el('div', 'agend-ev-price' + (isActive ? ' is-yours' : ''));
    row.appendChild(el('span', 'agend-ev-price__label', label));
    row.appendChild(el('span', 'agend-ev-price__value' + (price === 'FREE' ? ' is-free' : ''), price));
    return row;
  }

  // Builds the price rows for one ticket, highlighting the viewer's applicable
  // tier via .is-yours (SPEC-CORE-20260722 US-2.3). A flat-priced ticket (no
  // member/non-member split) shows a single highlighted price row.
  function ticketPriceRows(entry, group) {
    var container = el('div', 'agend-ev-detail__ticket-prices');
    var activeIsMember = group === 'member' || group === 'corporate';

    var memberVal = ticketTierValue(entry, 'member_price');
    var nonMemberVal = ticketTierValue(entry, 'non_member_price');

    if (memberVal === undefined && nonMemberVal === undefined) {
      var flat = formatPrice(ticketTierPrice(entry, group));
      if (flat !== null) {
        container.appendChild(priceRow('Price', flat, true));
      }
      return container;
    }

    var memberPrice = formatPrice(memberVal);
    if (memberPrice !== null) {
      container.appendChild(priceRow('Members', memberPrice, activeIsMember));
    }
    var nonMemberPrice = formatPrice(nonMemberVal);
    if (nonMemberPrice !== null) {
      container.appendChild(priceRow('Non-Members', nonMemberPrice, !activeIsMember));
    }
    return container;
  }

  function renderDetail(event, cfg, onBack, onRegister) {
    var wrap = el('div', 'agend-ev-detail');

    var back = el('button', 'agend-ev-detail__back', '← Back to Events');
    back.addEventListener('click', onBack);
    wrap.appendChild(back);

    var hero = el('div', 'agend-ev-detail__hero');
    if (event.hero_image_url) {
      hero.style.backgroundImage = 'linear-gradient(180deg, rgba(30,42,74,0.35), rgba(30,42,74,0.85)), url("' + event.hero_image_url + '")';
    }
    var heroInner = el('div', 'agend-ev-detail__hero-inner');
    var heroPills = el('div', 'agend-ev-card__pills');
    var cat = (event.categories && event.categories[0]) || event.category;
    if (cat && cat.name) {
      heroPills.appendChild(el('span', 'agend-ev-pill agend-ev-pill--category', cat.name));
    }
    var tl = typeLabel(event.venue_type);
    if (tl) {
      heroPills.appendChild(el('span', 'agend-ev-pill agend-ev-pill--type', tl));
    }
    heroInner.appendChild(heroPills);
    heroInner.appendChild(el('h2', 'agend-ev-detail__title', event.name || ''));
    heroInner.appendChild(el('div', 'agend-ev-detail__meta', [dateRange(event.start_date, event.end_date, event.timezone), event.venue_name || (event.venue_type === 'virtual' ? 'Online' : 'TBA')].filter(Boolean).join(' · ')));
    hero.appendChild(heroInner);
    wrap.appendChild(hero);

    var layout = el('div', 'agend-ev-detail__layout');
    var main = el('div', 'agend-ev-detail__main');

    if (event.description) {
      var about = el('section', 'agend-ev-detail__section');
      about.appendChild(el('h3', 'agend-ev-detail__section-title', 'About This Event'));
      var para = el('div', 'agend-ev-detail__body-text');
      setSafeHtml(para, event.description);
      about.appendChild(para);
      main.appendChild(about);
    }

    if (event.sponsors && event.sponsors.length) {
      var sponsors = el('section', 'agend-ev-detail__section');
      sponsors.appendChild(el('h3', 'agend-ev-detail__section-title', 'Sponsors'));
      var logos = el('div', 'agend-ev-detail__sponsors');
      event.sponsors.forEach(function (sp) {
        if (sp.logo_url) {
          var simg = el('img', 'agend-ev-detail__sponsor-logo');
          simg.src = sp.logo_url;
          simg.alt = sp.name || '';
          logos.appendChild(simg);
        } else {
          logos.appendChild(el('span', 'agend-ev-detail__sponsor-name', sp.name || ''));
        }
      });
      sponsors.appendChild(logos);
      main.appendChild(sponsors);
    }

    layout.appendChild(main);

    // Sidebar: registration panel + summary facts.
    var side = el('aside', 'agend-ev-detail__side');

    var reg = el('div', 'agend-ev-detail__panel agend-ev-detail__panel--register');
    reg.appendChild(el('h3', 'agend-ev-detail__panel-title', 'Registration'));

    // Signed-in member already registered: show the state and offer additional
    // seats instead of the default CTA (SPEC-CORE-20260722 US-2.3).
    var isRegistered = !!event.my_registration;
    if (isRegistered) {
      reg.appendChild(el('div', 'agend-ev-detail__registered', '✓ You’re registered for this event'));
    }

    var ctaLabel = event.sold_out
      ? 'Sold Out'
      : (isRegistered ? 'Register Another Attendee' : 'Register Now');
    var registerBtn = el('button', 'agend-ev-detail__cta', ctaLabel);
    if (event.sold_out) {
      registerBtn.disabled = true;
    }
    registerBtn.setAttribute('data-agend-event-slug', event.slug);
    registerBtn.addEventListener('click', function () {
      if (!event.sold_out && typeof onRegister === 'function') {
        onRegister(event);
      }
    });
    reg.appendChild(registerBtn);
    var calendar = el('a', 'agend-ev-detail__calendar', 'Add to Calendar');
    calendar.href = restBase().replace(/\/$/, '') + '/events/' + encodeURIComponent(event.slug) + '/ical';
    reg.appendChild(calendar);
    var group = viewerGroup(event);
    if (group === 'member' || group === 'corporate') {
      reg.appendChild(el('p', 'agend-ev-detail__note', 'Member pricing applies to your registration.'));
    } else {
      reg.appendChild(el('p', 'agend-ev-detail__note', 'Not a member? Join for discounted pricing.'));
    }
    side.appendChild(reg);

    // Tickets panel: all ticket types with the viewer's applicable price
    // highlighted (SPEC-CORE-20260722 US-2.3). Fetched async; removed if the
    // event has no purchasable tickets or the fetch fails.
    var ticketsPanel = el('div', 'agend-ev-detail__panel agend-ev-detail__panel--tickets');
    ticketsPanel.appendChild(el('h3', 'agend-ev-detail__panel-title', 'Tickets'));
    var ticketsBody = el('div', 'agend-ev-detail__tickets');
    ticketsBody.appendChild(el('div', 'agend-ev-status', 'Loading tickets…'));
    ticketsPanel.appendChild(ticketsBody);
    side.appendChild(ticketsPanel);

    apiGet('/events/' + encodeURIComponent(event.slug) + '/tickets', {}).then(function (body) {
      var items = unwrapList(body).items;
      if (!items.length) {
        if (ticketsPanel.parentNode) {
          ticketsPanel.parentNode.removeChild(ticketsPanel);
        }
        return;
      }
      ticketsBody.innerHTML = '';
      items.forEach(function (entry) {
        var ticket = entry.ticket || entry;
        var block = el('div', 'agend-ev-detail__ticket');
        block.appendChild(el('span', 'agend-ev-detail__ticket-name', ticket.name || 'Ticket'));
        block.appendChild(ticketPriceRows(entry, group));
        ticketsBody.appendChild(block);
      });
    }).catch(function () {
      if (ticketsPanel.parentNode) {
        ticketsPanel.parentNode.removeChild(ticketsPanel);
      }
    });

    var facts = el('div', 'agend-ev-detail__panel');
    facts.appendChild(el('h3', 'agend-ev-detail__panel-title', 'Details'));
    [
      ['Date & Time', dateTime(event.start_date, event.end_date, event.timezone)],
      ['Location', [event.venue_name, event.venue_address, event.venue_city].filter(Boolean).join(', ') || (event.venue_type === 'virtual' ? 'Online' : 'TBA')],
      ['Format', tl],
    ].forEach(function (pair) {
      if (!pair[1]) {
        return;
      }
      var row = el('div', 'agend-ev-detail__fact');
      row.appendChild(el('span', 'agend-ev-detail__fact-label', pair[0]));
      row.appendChild(el('span', 'agend-ev-detail__fact-value', pair[1]));
      facts.appendChild(row);
    });
    side.appendChild(facts);

    layout.appendChild(side);
    wrap.appendChild(layout);
    return wrap;
  }

  function renderNotFound(onBack) {
    var wrap = el('div', 'agend-ev-detail');
    var back = el('button', 'agend-ev-detail__back', '← Back to Events');
    back.addEventListener('click', onBack);
    wrap.appendChild(back);
    var msg = el('div', 'agend-ev-status', 'Event not found.');
    wrap.appendChild(msg);
    return wrap;
  }

  // -- Filter bar + pagination ---------------------------------------------

  // Opens/closes a popover panel anchored to a toggle. The panel is
  // position:fixed and positioned from the toggle's viewport rect, so it is
  // never clipped by an ancestor's overflow (e.g. an Elementor section with
  // overflow:hidden) even when the results grid is short. Caps its height to
  // the space below the toggle so a long list scrolls inside the panel. Handles
  // outside click, Escape, and reposition on scroll/resize.
  function attachPopover(toggle, panel) {
    var isOpen = false;
    var themed = false;

    var position = function () {
      var rect = toggle.getBoundingClientRect();
      panel.style.top = Math.round(rect.bottom + 4) + 'px';
      panel.style.left = Math.round(rect.left) + 'px';
      panel.style.minWidth = Math.round(rect.width) + 'px';
      var available = window.innerHeight - rect.bottom - 16;
      panel.style.maxHeight = Math.max(160, available) + 'px';
    };

    var reposition = function () {
      if (isOpen) {
        position();
      }
    };

    var close = function () {
      if (!isOpen) {
        return;
      }
      isOpen = false;
      panel.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      window.removeEventListener('scroll', reposition, true);
      window.removeEventListener('resize', reposition);
    };

    var open = function () {
      // Portal to <body> so the panel escapes any ancestor stacking context
      // (Elementor sections use position:relative;z-index:1, which traps a
      // fixed child so later sections paint over it) and any ancestor overflow.
      // Copy the widget's theme custom properties over on first open so styling
      // survives the move out of the widget subtree.
      if (panel.parentNode !== document.body) {
        if (!themed) {
          var root = toggle.closest('.agend-events-catalogue');
          if (root) {
            [
              '--agend-ev-heading',
              '--agend-ev-body',
              '--agend-ev-accent',
              '--agend-ev-button',
              '--agend-ev-button-text',
              '--agend-ev-card-radius',
            ].forEach(function (name) {
              var val = getComputedStyle(root).getPropertyValue(name);
              if (val) {
                panel.style.setProperty(name, val.trim());
              }
            });
          }
          themed = true;
        }
        document.body.appendChild(panel);
      }
      isOpen = true;
      panel.hidden = false;
      toggle.setAttribute('aria-expanded', 'true');
      position();
      window.addEventListener('scroll', reposition, true);
      window.addEventListener('resize', reposition);
    };

    toggle.addEventListener('click', function (e) {
      e.stopPropagation();
      if (isOpen) {
        close();
      } else {
        open();
      }
    });
    panel.addEventListener('click', function (e) {
      e.stopPropagation();
    });
    document.addEventListener('click', close);
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        close();
      }
    });

    return { close: close };
  }

  // A checkbox dropdown filter (US-2.5). Renders a toggle button and a panel of
  // checkboxes; onChange(values[]) fires whenever a checkbox is toggled. Options
  // are added via the returned addOption (categories/cities load asynchronously).
  function buildCheckboxFilter(allLabel, onChange) {
    var wrap = el('div', 'agend-ev-multiselect');
    var toggle = el('button', 'agend-ev-filter agend-ev-multiselect__toggle', allLabel);
    toggle.type = 'button';
    toggle.setAttribute('aria-expanded', 'false');
    var panel = el('div', 'agend-ev-multiselect__panel');
    panel.hidden = true;
    var selected = [];

    var updateLabel = function () {
      if (!selected.length) {
        toggle.textContent = allLabel;
      } else if (selected.length === 1) {
        toggle.textContent = selected[0].label;
      } else {
        toggle.textContent = selected.length + ' selected';
      }
    };

    var addOption = function (value, label) {
      var row = el('label', 'agend-ev-multiselect__option');
      var cb = el('input', 'agend-ev-multiselect__checkbox');
      cb.type = 'checkbox';
      cb.value = value;
      cb.addEventListener('change', function () {
        if (cb.checked) {
          selected.push({ value: value, label: label });
        } else {
          selected = selected.filter(function (s) {
            return s.value !== value;
          });
        }
        updateLabel();
        onChange(
          selected.map(function (s) {
            return s.value;
          }),
        );
      });
      row.appendChild(cb);
      row.appendChild(el('span', 'agend-ev-multiselect__optlabel', label));
      panel.appendChild(row);
    };

    attachPopover(toggle, panel);

    wrap.appendChild(toggle);
    wrap.appendChild(panel);
    return { wrap: wrap, addOption: addOption };
  }

  function buildFilterBar(root, cfg, state, reload) {
    if (!cfg.filters.search && !cfg.filters.category && !cfg.filters.type && !cfg.filters.city && !cfg.filters.date) {
      return;
    }
    var bar = el('div', 'agend-ev-filterbar');

    if (cfg.filters.search) {
      var search = el('input', 'agend-ev-search');
      search.type = 'search';
      search.placeholder = 'Search events…';
      var debounce;
      search.addEventListener('input', function () {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(function () {
          state.search = search.value.trim();
          state.page = 1;
          reload();
        }, 300);
      });
      bar.appendChild(search);
    }

    // Excluded categories can never match an event in this widget, so offering
    // them in the filter would only produce empty results.
    var excludedCategories = (cfg.exclusions && cfg.exclusions.categories) || [];

    if (cfg.filters.category) {
      if (cfg.filters.categoryMulti) {
        var catMulti = buildCheckboxFilter('All Categories', function (values) {
          state.categories = values;
          state.category = '';
          state.page = 1;
          reload();
        });
        apiGet('/events/categories', {}).then(function (body) {
          unwrapList(body).items.forEach(function (cat) {
            if (excludedCategories.indexOf(String(cat.id)) !== -1) {
              return;
            }
            catMulti.addOption(String(cat.id), cat.name);
          });
        });
        bar.appendChild(catMulti.wrap);
      } else {
        var category = el('select', 'agend-ev-filter');
        category.appendChild(new Option('All Categories', ''));
        apiGet('/events/categories', {}).then(function (body) {
          unwrapList(body).items.forEach(function (cat) {
            if (excludedCategories.indexOf(String(cat.id)) !== -1) {
              return;
            }
            category.appendChild(new Option(cat.name, cat.id));
          });
        });
        category.addEventListener('change', function () {
          state.category = category.value;
          state.page = 1;
          reload();
        });
        bar.appendChild(category);
      }
    }

    if (cfg.filters.type) {
      var typeOptions = [
        ['physical', 'In-Person'],
        ['virtual', 'Online'],
        ['hybrid', 'Hybrid'],
      ];
      if (cfg.filters.typeMulti) {
        var typeMulti = buildCheckboxFilter('All Types', function (values) {
          state.types = values;
          state.type = '';
          state.page = 1;
          reload();
        });
        typeOptions.forEach(function (o) {
          typeMulti.addOption(o[0], o[1]);
        });
        bar.appendChild(typeMulti.wrap);
      } else {
        var type = el('select', 'agend-ev-filter');
        type.appendChild(new Option('All Types', ''));
        typeOptions.forEach(function (o) {
          type.appendChild(new Option(o[1], o[0]));
        });
        type.addEventListener('change', function () {
          state.type = type.value;
          state.page = 1;
          reload();
        });
        bar.appendChild(type);
      }
    }

    if (cfg.filters.city) {
      if (cfg.filters.cityMulti) {
        var cityMulti = buildCheckboxFilter('All Cities', function (values) {
          state.cities = values;
          state.city = '';
          state.page = 1;
          reload();
        });
        apiGet('/events/venues', { limit: 100 }).then(function (body) {
          var seen = {};
          unwrapList(body).items.forEach(function (venue) {
            var name = venue.city || venue.venue_city;
            if (name && !seen[name]) {
              seen[name] = true;
              cityMulti.addOption(name, name);
            }
          });
        });
        bar.appendChild(cityMulti.wrap);
      } else {
        var city = el('select', 'agend-ev-filter');
        city.appendChild(new Option('All Cities', ''));
        apiGet('/events/venues', { limit: 100 }).then(function (body) {
          var seen = {};
          unwrapList(body).items.forEach(function (venue) {
            var name = venue.city || venue.venue_city;
            if (name && !seen[name]) {
              seen[name] = true;
              city.appendChild(new Option(name, name));
            }
          });
        });
        city.addEventListener('change', function () {
          state.city = city.value;
          state.page = 1;
          reload();
        });
        bar.appendChild(city);
      }
    }

    // Date range dropdown (US-2.4): two date fields filtering on event start
    // date. "Starting after" sets a lower bound, "starting before" an upper
    // bound; both together select events starting between the two dates.
    if (cfg.filters.date) {
      var dateWrap = el('div', 'agend-ev-datefilter');
      var dateToggle = el('button', 'agend-ev-filter agend-ev-datefilter__toggle', 'All Dates');
      dateToggle.type = 'button';
      dateToggle.setAttribute('aria-expanded', 'false');

      var panel = el('div', 'agend-ev-datefilter__panel');
      panel.hidden = true;

      var afterField = el('label', 'agend-ev-datefilter__field');
      afterField.appendChild(el('span', 'agend-ev-datefilter__label', 'Starting after'));
      var afterInput = el('input', 'agend-ev-datefilter__input');
      afterInput.type = 'date';
      afterField.appendChild(afterInput);

      var beforeField = el('label', 'agend-ev-datefilter__field');
      beforeField.appendChild(el('span', 'agend-ev-datefilter__label', 'Starting before'));
      var beforeInput = el('input', 'agend-ev-datefilter__input');
      beforeInput.type = 'date';
      beforeField.appendChild(beforeInput);

      var clearBtn = el('button', 'agend-ev-datefilter__clear', 'Clear');
      clearBtn.type = 'button';

      panel.appendChild(afterField);
      panel.appendChild(beforeField);
      panel.appendChild(clearBtn);

      // DD/MM/YYYY for the toggle label (tenant-facing date format).
      var fmtDate = function (d) {
        var parts = d.split('-');
        return parts.length === 3 ? parts[2] + '/' + parts[1] + '/' + parts[0] : d;
      };
      var updateLabel = function () {
        var a = afterInput.value;
        var b = beforeInput.value;
        if (a && b) {
          dateToggle.textContent = fmtDate(a) + ' – ' + fmtDate(b);
        } else if (a) {
          dateToggle.textContent = 'After ' + fmtDate(a);
        } else if (b) {
          dateToggle.textContent = 'Before ' + fmtDate(b);
        } else {
          dateToggle.textContent = 'All Dates';
        }
      };
      var applyDates = function () {
        // Inclusive of both selected days: after = start of day, before = end
        // of day, so the range brackets whole days.
        state.startAfter = afterInput.value ? afterInput.value + 'T00:00:00' : '';
        state.startBefore = beforeInput.value ? beforeInput.value + 'T23:59:59' : '';
        state.page = 1;
        updateLabel();
        reload();
      };

      afterInput.addEventListener('change', applyDates);
      beforeInput.addEventListener('change', applyDates);
      clearBtn.addEventListener('click', function () {
        afterInput.value = '';
        beforeInput.value = '';
        applyDates();
      });

      attachPopover(dateToggle, panel);

      dateWrap.appendChild(dateToggle);
      dateWrap.appendChild(panel);
      bar.appendChild(dateWrap);
    }

    root.appendChild(bar);
  }

  function renderPagination(root, cfg, state, pagination, reload) {
    if (cfg.pagination.style === 'none' || !pagination) {
      return;
    }
    var nav = el('div', 'agend-ev-pagination');
    if (cfg.pagination.style === 'load_more') {
      if (pagination.has_next) {
        var more = el('button', 'agend-ev-loadmore', 'Load more events');
        more.addEventListener('click', function () {
          state.page = (pagination.page || state.page) + 1;
          state.append = true;
          reload();
        });
        nav.appendChild(more);
      }
    } else {
      for (var i = 1; i <= (pagination.total_pages || 1); i++) {
        (function (pageNum) {
          var btn = el('button', 'agend-ev-page' + (pageNum === pagination.page ? ' is-active' : ''), pageNum);
          btn.addEventListener('click', function () {
            state.page = pageNum;
            reload();
          });
          nav.appendChild(btn);
        })(i);
      }
    }
    root.appendChild(nav);
  }

  // -- Registration + payment ----------------------------------------------

  // Ticket price for the viewer's price group. Anonymous visitors resolve to
  // non-member pricing; a signed-in member's group arrives server-resolved on
  // the event payload as `viewer_price_group` (SPEC-CORE-20260722 US-2.1) and
  // the gateway re-resolves it at registration time, so this is display-only.
  function ticketTierPrice(entry, group) {
    var tiers = (entry && entry.pricingTiers) || [];
    if (tiers.length) {
      var tier = tiers[0].tier || tiers[0];
      var v;
      if (group === 'corporate') {
        v = tier.corporate_price != null ? tier.corporate_price : tier.member_price;
      } else if (group === 'member') {
        v = tier.member_price;
      } else {
        v = tier.non_member_price;
      }
      if (v === null || v === undefined) {
        v = tier.non_member_price != null ? tier.non_member_price : tier.member_price;
      }
      return typeof v === 'string' ? parseFloat(v) : (v || 0);
    }
    var t = (entry && entry.ticket) || entry || {};
    var p = t.price != null ? t.price : t.base_price;
    return typeof p === 'string' ? parseFloat(p) : (p || 0);
  }

  // The viewer's server-resolved price group, or '' when signed out. Presence
  // of the enrichment field is the signed-in signal (no extra probe request).
  function viewerGroup(event) {
    return (event && event.viewer_price_group) || '';
  }

  function labelledField(labelText, input) {
    var wrap = el('label', 'agend-ev-reg__field');
    wrap.appendChild(el('span', 'agend-ev-reg__field-label', labelText));
    wrap.appendChild(input);
    return wrap;
  }

  function textInput(type, placeholder) {
    var input = el('input', 'agend-ev-reg__input');
    input.type = type || 'text';
    if (placeholder) {
      input.placeholder = placeholder;
    }
    return input;
  }

  // Registration screen (US-EVT.9): ticket quantity selection, per-seat
  // attendee details, guest buyer capture, order summary, then register +
  // (for paid tickets) hand off to the checkout session (US-EVT.10).
  function renderRegistration(event, cfg, onBack, onSuccess) {
    var wrap = el('div', 'agend-ev-reg');

    var back = el('button', 'agend-ev-detail__back', '← Back to Event');
    back.addEventListener('click', onBack);
    wrap.appendChild(back);

    wrap.appendChild(el('h2', 'agend-ev-reg__title', 'Register: ' + (event.name || '')));
    wrap.appendChild(el('div', 'agend-ev-reg__meta', dateRange(event.start_date, event.end_date, event.timezone)));

    var status = el('div', 'agend-ev-status', 'Loading tickets…');
    wrap.appendChild(status);

    var form = el('div', 'agend-ev-reg__form');
    form.style.display = 'none';
    wrap.appendChild(form);

    var ticketsSection = el('div', 'agend-ev-reg__tickets');
    form.appendChild(ticketsSection);

    // Signed-in member: identity and price group are resolved server-side
    // from the bearer (SPEC-CORE-20260722 US-2.3), so lines are priced at the
    // member's rate and the guest buyer capture is skipped.
    var group = viewerGroup(event) || 'non_member';
    var isSignedIn = !!viewerGroup(event);

    // Buyer (guest) details.
    var buyerSection = el('div', 'agend-ev-reg__section');
    buyerSection.appendChild(el('h3', 'agend-ev-reg__section-title', 'Your Details'));
    var buyerFirst = textInput('text', 'First name');
    var buyerLast = textInput('text', 'Last name');
    var buyerEmail = textInput('email', 'you@example.com');
    var buyerPhone = textInput('tel', 'Phone (optional)');
    var buyerGrid = el('div', 'agend-ev-reg__grid');
    buyerGrid.appendChild(labelledField('First name', buyerFirst));
    buyerGrid.appendChild(labelledField('Last name', buyerLast));
    buyerGrid.appendChild(labelledField('Email', buyerEmail));
    buyerGrid.appendChild(labelledField('Phone', buyerPhone));
    buyerSection.appendChild(buyerGrid);
    if (isSignedIn) {
      buyerSection.style.display = 'none';
    }
    form.appendChild(buyerSection);

    var summary = el('div', 'agend-ev-reg__summary');
    form.appendChild(summary);

    var errorBox = el('div', 'agend-ev-reg__error');
    errorBox.style.display = 'none';
    form.appendChild(errorBox);

    var submit = el('button', 'agend-ev-detail__cta agend-ev-reg__submit', cfg.cartEnabled ? 'Add to Cart' : 'Confirm Registration');
    form.appendChild(submit);

    // ticketId -> { entry, qty, price, attendeeRows: [{name,email}] }
    var lines = {};

    function total() {
      return Object.keys(lines).reduce(function (sum, id) {
        return sum + lines[id].qty * lines[id].price;
      }, 0);
    }

    function refreshSummary() {
      summary.innerHTML = '';
      var t = total();
      var any = false;
      Object.keys(lines).forEach(function (id) {
        var line = lines[id];
        if (line.qty <= 0) {
          return;
        }
        any = true;
        var row = el('div', 'agend-ev-reg__summary-row');
        row.appendChild(el('span', null, (line.entry.ticket.name || 'Ticket') + ' × ' + line.qty));
        row.appendChild(el('span', null, line.price === 0 ? 'Free' : '$' + (line.qty * line.price).toFixed(2)));
        summary.appendChild(row);
      });
      if (any) {
        var totalRow = el('div', 'agend-ev-reg__summary-row agend-ev-reg__summary-row--total');
        totalRow.appendChild(el('span', null, 'Total'));
        totalRow.appendChild(el('span', null, t === 0 ? 'Free' : '$' + t.toFixed(2)));
        summary.appendChild(totalRow);
        submit.textContent = cfg.cartEnabled ? 'Add to Cart' : (t === 0 ? 'Confirm Registration' : 'Proceed to Payment');
      }
      submit.disabled = !any;
    }

    function buildAttendeeRows(line, container) {
      container.innerHTML = '';
      for (var i = 0; i < line.qty; i++) {
        var name = textInput('text', 'Attendee name');
        var email = textInput('email', 'Attendee email (optional)');
        line.attendeeRows[i] = line.attendeeRows[i] || {};
        (function (slot, nameInput, emailInput) {
          nameInput.value = slot.name || '';
          emailInput.value = slot.email || '';
          nameInput.addEventListener('input', function () { slot.name = nameInput.value; });
          emailInput.addEventListener('input', function () { slot.email = emailInput.value; });
        })(line.attendeeRows[i], name, email);
        var rowWrap = el('div', 'agend-ev-reg__attendee');
        rowWrap.appendChild(el('span', 'agend-ev-reg__attendee-idx', 'Attendee ' + (i + 1)));
        rowWrap.appendChild(name);
        rowWrap.appendChild(email);
        container.appendChild(rowWrap);
      }
      line.attendeeRows.length = line.qty;
    }

    apiGet('/events/' + encodeURIComponent(event.slug) + '/tickets', {}).then(function (body) {
      var items = unwrapList(body).items;
      status.style.display = 'none';
      form.style.display = '';
      if (!items.length) {
        status.style.display = '';
        status.textContent = 'No tickets are available for this event.';
        form.style.display = 'none';
        return;
      }
      items.forEach(function (entry) {
        var ticket = entry.ticket || entry;
        var price = ticketTierPrice(entry, group);
        var cap = entry.capacityRemaining;
        var max = typeof cap === 'number' && cap >= 0 ? Math.min(cap, 20) : 20;
        var line = { entry: { ticket: ticket, pricingTiers: entry.pricingTiers }, qty: 0, price: price, attendeeRows: [] };
        lines[ticket.id] = line;

        var row = el('div', 'agend-ev-reg__ticket');
        var info = el('div', 'agend-ev-reg__ticket-info');
        info.appendChild(el('span', 'agend-ev-reg__ticket-name', ticket.name || 'Ticket'));
        info.appendChild(el('span', 'agend-ev-reg__ticket-price', price === 0 ? 'Free' : '$' + price.toFixed(2)));
        if (typeof cap === 'number') {
          info.appendChild(el('span', 'agend-ev-reg__ticket-cap', cap + ' remaining'));
        }
        row.appendChild(info);

        var stepper = el('div', 'agend-ev-reg__stepper');
        var minus = el('button', 'agend-ev-reg__step', '−');
        var qtyLabel = el('span', 'agend-ev-reg__qty', '0');
        var plus = el('button', 'agend-ev-reg__step', '+');
        var attendees = el('div', 'agend-ev-reg__attendees');

        minus.addEventListener('click', function () {
          if (line.qty > 0) {
            line.qty--;
            qtyLabel.textContent = line.qty;
            buildAttendeeRows(line, attendees);
            refreshSummary();
          }
        });
        plus.addEventListener('click', function () {
          if (line.qty < max) {
            line.qty++;
            qtyLabel.textContent = line.qty;
            buildAttendeeRows(line, attendees);
            refreshSummary();
          }
        });
        stepper.appendChild(minus);
        stepper.appendChild(qtyLabel);
        stepper.appendChild(plus);
        row.appendChild(stepper);
        ticketsSection.appendChild(row);
        ticketsSection.appendChild(attendees);
      });
      refreshSummary();
    }).catch(function () {
      status.style.display = '';
      status.textContent = 'Unable to load tickets.';
      form.style.display = 'none';
    });

    function showError(message) {
      errorBox.style.display = '';
      errorBox.textContent = message;
    }

    submit.addEventListener('click', function () {
      errorBox.style.display = 'none';

      // Cart mode (Agend Apps Shop active): add the selected ticket lines to the
      // shop cart instead of registering and paying immediately. Any per-seat
      // attendee details captured above are transmitted with the line; capture
      // is optional, so seats left blank default to the buyer at fulfilment and
      // can be completed later from the cart view (SPEC-CORE-20260721 US-5.2).
      if (cfg.cartEnabled) {
        var cartSelected = Object.keys(lines).filter(function (id) { return lines[id].qty > 0; });
        if (!cartSelected.length) {
          showError('Select at least one ticket.');
          return;
        }
        submit.disabled = true;
        submit.textContent = 'Adding…';
        // Add lines sequentially so a single guest cart session token (returned
        // on the first add) is set before the next request reuses it.
        var addChain = Promise.resolve();
        cartSelected.forEach(function (id) {
          var line = lines[id];
          addChain = addChain.then(function () {
            return cartAddItem('event_tickets', id, line.qty, cartAttendeesForLine(line));
          });
        });
        addChain.then(function () {
          document.dispatchEvent(new CustomEvent('agend:cart:updated'));
          if (typeof onSuccess === 'function') {
            onSuccess(event, 'cart');
          }
        }).catch(function (err) {
          submit.disabled = false;
          refreshSummary();
          showError((err && err.message) || 'Unable to add to cart. Please try again.');
        });
        return;
      }

      var first = buyerFirst.value.trim();
      var email = buyerEmail.value.trim();
      if (!isSignedIn && (!first || !email)) {
        showError('Enter your first name and email to continue.');
        return;
      }
      var selected = Object.keys(lines).filter(function (id) { return lines[id].qty > 0; });
      if (!selected.length) {
        showError('Select at least one ticket.');
        return;
      }
      var missingName = false;
      selected.forEach(function (id) {
        lines[id].attendeeRows.forEach(function (a) {
          if (!a || !(a.name || '').trim()) {
            missingName = true;
          }
        });
      });
      if (missingName) {
        showError('Enter a name for every attendee.');
        return;
      }

      // A signed-in member's buyer identity is derived server-side from the
      // bearer; the guest buyer object is only sent for anonymous visitors.
      var buyer = isSignedIn ? undefined : {
        email: email,
        first_name: first,
        last_name: buyerLast.value.trim() || undefined,
        phone: buyerPhone.value.trim() || undefined,
      };

      submit.disabled = true;
      submit.textContent = 'Processing…';

      var registrationIds = [];
      var chain = Promise.resolve();
      selected.forEach(function (id) {
        var line = lines[id];
        chain = chain.then(function () {
          return apiPost('/events/' + encodeURIComponent(event.slug) + '/register', {
            ticket_id: id,
            // Display-consistent group; the gateway resolves the authoritative
            // price group server-side whenever the buyer identity is validated.
            price_group: group,
            beneficiaries: line.attendeeRows.map(function (a) {
              return {
                beneficiary_type: 'named',
                beneficiary_name: (a.name || '').trim(),
                beneficiary_email: (a.email || '').trim() || undefined,
              };
            }),
            buyer: buyer,
          }).then(function (res) {
            var data = unwrapOne(res);
            var regs = (data && data.registrations) || [];
            regs.forEach(function (r) {
              if (r.registrationId || r.id) {
                registrationIds.push(r.registrationId || r.id);
              }
            });
            if (res && res.success === false) {
              throw new Error((res.error && res.error.message) || 'Registration failed.');
            }
          });
        });
      });

      chain.then(function () {
        if (!registrationIds.length) {
          throw new Error('Registration could not be completed.');
        }
        if (total() === 0) {
          onSuccess(event, 'free');
          return null;
        }
        var base = new URL(window.location.href);
        base.searchParams.set(DEEP_LINK_PARAM, event.slug);
        var successUrl = new URL(base.toString());
        successUrl.searchParams.set(PAY_PARAM, 'success');
        var cancelUrl = new URL(base.toString());
        cancelUrl.searchParams.set(PAY_PARAM, 'cancel');
        return apiPost('/events/registrations/pay', {
          registration_ids: registrationIds,
          success_url: successUrl.toString(),
          cancel_url: cancelUrl.toString(),
        }).then(function (res) {
          var data = unwrapOne(res);
          var checkoutUrl = data && (data.checkout_url || data.checkoutUrl);
          if (!checkoutUrl) {
            throw new Error((res && res.error && res.error.message) || 'Unable to start payment.');
          }
          window.location.href = checkoutUrl;
        });
      }).catch(function (err) {
        submit.disabled = false;
        refreshSummary();
        showError((err && err.message) || 'Something went wrong. Please try again.');
      });
    });

    return wrap;
  }

  // Post-flow confirmation. `mode` is 'cart' when tickets were added to the
  // shop cart (US: shop integration), otherwise a completed registration.
  function renderConfirmation(event, cfg, onBackToEvent, onBackToEvents, mode) {
    var isCart = 'cart' === mode;
    var wrap = el('div', 'agend-ev-reg');
    var panel = el('div', 'agend-ev-reg__confirm');
    panel.appendChild(el('div', 'agend-ev-reg__confirm-tick', '✓'));
    panel.appendChild(el('h2', 'agend-ev-reg__confirm-title', isCart ? 'Added to Cart' : 'Registration Confirmed'));
    panel.appendChild(el('p', 'agend-ev-reg__confirm-text', isCart
      ? 'Your tickets for ' + (event.name || 'this event') + ' have been added to your cart.'
      : 'You are registered for ' + (event.name || 'this event') + '. A confirmation email is on its way.'));
    var actions = el('div', 'agend-ev-reg__confirm-actions');
    if (isCart && cfg.cartPageUrl) {
      var cartLink = el('a', 'agend-ev-detail__cta', 'View Cart');
      cartLink.href = cfg.cartPageUrl;
      actions.appendChild(cartLink);
      var browseBtn = el('button', 'agend-ev-reg__link', 'Keep browsing events');
      browseBtn.addEventListener('click', onBackToEvents);
      actions.appendChild(browseBtn);
    } else {
      var eventBtn = el('button', 'agend-ev-detail__cta', isCart ? 'Back to Event' : 'View Event');
      eventBtn.addEventListener('click', onBackToEvent);
      var listBtn = el('button', 'agend-ev-reg__link', 'Back to all events');
      listBtn.addEventListener('click', onBackToEvents);
      actions.appendChild(eventBtn);
      actions.appendChild(listBtn);
    }
    panel.appendChild(actions);
    wrap.appendChild(panel);
    return wrap;
  }

  // -- SSR detail hydration -------------------------------------------------

  // When the "Server-rendered detail pages" plugin setting is on, an event
  // detail is rendered server-side into a virtual child page (breadcrumb
  // parenting + SEO). The read-only detail is already in the DOM; here we only
  // layer the interactive flows (registration, post-payment confirmation) onto
  // the server-rendered markup, reusing renderRegistration/renderConfirmation.

  // Hides the read-only detail and mounts an overlay in its place. Returns the
  // overlay node plus a restore() that removes it and shows the detail again.
  function mountSsrOverlay(root, detailEl) {
    detailEl.style.display = 'none';
    var overlay = el('div', 'agend-ev-ssr-overlay');
    root.appendChild(overlay);
    function restore() {
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
      detailEl.style.display = '';
    }
    try {
      overlay.scrollIntoView({ behavior: 'smooth', block: 'start' });
    } catch (e) {
      /* scrollIntoView options unsupported — no-op */
    }
    return { overlay: overlay, restore: restore };
  }

  function ssrPayState() {
    try {
      return new URL(window.location.href).searchParams.get(PAY_PARAM);
    } catch (e) {
      return null;
    }
  }

  // Strips the payment-return marker so a refresh does not re-open the
  // confirmation screen.
  function clearSsrPayParam() {
    try {
      var url = new URL(window.location.href);
      url.searchParams.delete(PAY_PARAM);
      window.history.replaceState({}, '', url.toString());
    } catch (e) {
      /* history API unavailable — no-op */
    }
  }

  function openSsrRegistration(root, detailEl, event, cfg) {
    var mount = mountSsrOverlay(root, detailEl);
    mount.overlay.appendChild(renderRegistration(
      event,
      cfg,
      mount.restore,
      function (ev, mode) {
        mount.overlay.innerHTML = '';
        mount.overlay.appendChild(renderConfirmation(
          ev,
          cfg,
          mount.restore,
          function () { window.location.href = cfg.basePath || '/'; },
          mode
        ));
      }
    ));
  }

  // Post-payment return (?agend_pay=success): mirror the client catalogue's
  // showDetail(slug, false, 'success') path so a paid registrant lands on the
  // confirmation screen instead of the plain detail.
  function openSsrConfirmation(root, detailEl, slug, cfg) {
    clearSsrPayParam();
    var mount = mountSsrOverlay(root, detailEl);
    mount.overlay.appendChild(renderDetailSkeleton());
    apiGet('/events/' + encodeURIComponent(slug), { include: 'sponsors,categories' }).then(function (body) {
      var event = unwrapOne(body);
      mount.overlay.innerHTML = '';
      if (!event || !event.slug) {
        mount.restore();
        return;
      }
      mount.overlay.appendChild(renderConfirmation(
        event,
        cfg,
        mount.restore,
        function () { window.location.href = cfg.basePath || '/'; }
      ));
    }).catch(function () {
      mount.restore();
    });
  }

  function hydrateSsrDetail(root, cfg) {
    var detailEl = root.querySelector('.agend-ev-detail');
    if (!detailEl) {
      return;
    }
    var btn = detailEl.querySelector('[data-agend-event-slug]');
    var slug = cfg.deepLink || (btn ? btn.getAttribute('data-agend-event-slug') : '');
    if (!slug) {
      return;
    }
    // Wire the registration flow onto the "Register Now" button (absent/disabled
    // for sold-out events).
    if (btn && !btn.disabled) {
      btn.addEventListener('click', function () {
        btn.disabled = true;
        apiGet('/events/' + encodeURIComponent(slug), { include: 'sponsors,categories' }).then(function (body) {
          var event = unwrapOne(body);
          btn.disabled = false;
          if (event && event.slug) {
            openSsrRegistration(root, detailEl, event, cfg);
          }
        }).catch(function () {
          btn.disabled = false;
        });
      });
    }
    // Show the confirmation screen on return from a paid registration.
    if (ssrPayState() === 'success') {
      openSsrConfirmation(root, detailEl, slug, cfg);
    }
  }

  // -- Widget orchestration -------------------------------------------------

  function initWidget(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-events-config'));
    } catch (e) {
      return;
    }

    // Render event times in the organisation timezone (site-wide, so the same
    // for every widget on the page) rather than the viewer's browser timezone,
    // matching the server-rendered detail.
    if (cfg && typeof cfg.timezone === 'string') {
      orgTimeZone = cfg.timezone;
    }

    // Server-rendered detail page: the read-only detail is already in the DOM;
    // only hydrate the registration flow, never build the catalogue.
    if (cfg && cfg.ssrDetail) {
      hydrateSsrDetail(root, cfg);
      return;
    }

    var state = { search: '', category: '', type: '', city: '', categories: [], types: [], cities: [], startAfter: '', startBefore: '', page: 1, append: false };

    // Apply inherited site theme (fonts/colours) — live via CSS custom props.
    // The filter template is rendered server-side inside the widget; take it
    // out of the root before the catalogue view replaces the markup, so the
    // same controls survive both the listing and the detail view.
    var serverFilters = root.querySelector('.agend-ev-filter-slot');
    var hasTemplatedFilters = !!(cfg.filterTemplate && serverFilters && serverFilters.querySelector('[data-agend-filter]'));
    // In card-template mode the slot is adopted with the rest of the
    // server markup and is already in the right place; in legacy mode the
    // catalogue view replaces the root, so detach it first.
    if (hasTemplatedFilters && cfg.cardMode !== 'template' && serverFilters.parentNode) {
      serverFilters.parentNode.removeChild(serverFilters);
    }

    applySiteTheme(root, cfg);

    // Card template mode: the first page arrived server-rendered, so adopt
    // that markup (grid, pager, filter slot) instead of rebuilding it, and
    // fetch later pages as rendered fragments.
    var templated = cfg.cardMode === 'template';
    var catalogueEl, status, grid, pager;
    if (templated) {
      catalogueEl = el('div', 'agend-ev-catalogue');
      while (root.firstChild) {
        catalogueEl.appendChild(root.firstChild);
      }
      status = catalogueEl.querySelector('.agend-ev-status') || el('div', 'agend-ev-status');
      grid = catalogueEl.querySelector('.agend-ev-grid') || el('div', 'agend-ev-grid');
      pager = catalogueEl.querySelector('.agend-ev-pager-slot') || el('div', 'agend-ev-pager-slot');
      mountFilters(catalogueEl.querySelector('.agend-ev-filter-slot') || catalogueEl);
      grid.addEventListener('click', onTemplatedCardClick);
    } else {

    // The catalogue view is built once and cached so returning from a detail
    // view preserves the search/filter state and the rendered grid.
    var catalogueEl = el('div', 'agend-ev-catalogue');
    var status = el('div', 'agend-ev-status', 'Loading events…');
    var grid = el('div', 'agend-ev-grid');
    grid.style.setProperty('--agend-ev-cols-desktop', cfg.layout.desktop);
    grid.style.setProperty('--agend-ev-cols-tablet', cfg.layout.tablet);
    grid.style.setProperty('--agend-ev-cols-mobile', cfg.layout.mobile);
    var pager = el('div', 'agend-ev-pager-slot');

    if (cfg.heading && cfg.heading.show) {
      var head = el('div', 'agend-ev-heading');
      if (cfg.heading.title) {
        head.appendChild(el('h2', 'agend-ev-heading__title', cfg.heading.title));
      }
      if (cfg.heading.subtitle) {
        head.appendChild(el('p', 'agend-ev-heading__subtitle', cfg.heading.subtitle));
      }
      catalogueEl.appendChild(head);
    }
    mountFilters(catalogueEl);
    catalogueEl.appendChild(status);
    catalogueEl.appendChild(grid);
    catalogueEl.appendChild(pager);
    }

    // Build the canonical detail URL for a slug. The dedicated Events page
    // (cfg.detailBase) takes priority over everything else: pretty path
    // (/{page}/event/{slug}/) when permalinks are on, else the legacy
    // ?agend_event= query param on that page. With no dedicated page
    // configured, falls back to the host page path (US-1.2), and finally to
    // the legacy query param on the current URL.
    function deepLinkUrl(slug) {
      if (cfg.detailBase) {
        var detailBase = cfg.detailBase;
        if (detailBase.charAt(detailBase.length - 1) !== '/') {
          detailBase += '/';
        }
        if (cfg.prettyLinks) {
          return detailBase + 'event/' + encodeURIComponent(slug) + '/';
        }
        var sep = detailBase.indexOf('?') === -1 ? '?' : '&';
        return detailBase + sep + DEEP_LINK_PARAM + '=' + encodeURIComponent(slug);
      }
      if (cfg.prettyLinks && cfg.basePath) {
        var base = cfg.basePath;
        if (base.charAt(base.length - 1) !== '/') {
          base += '/';
        }
        return base + 'event/' + encodeURIComponent(slug) + '/';
      }
      var url = new URL(window.location.href);
      url.searchParams.set(DEEP_LINK_PARAM, slug);
      return url.toString();
    }

    // Opens an item: in place when the widget is on the detail page (or no
    // dedicated page is configured), otherwise navigates there.
    function mountFilters(target) {
      if (hasTemplatedFilters && window.agendFilters && window.agendFilters.build) {
        // Side placement is a grid on whichever element holds the filter slot
        // and the results. The server put that class on the root, but the
        // catalogue view has since moved those children into catalogueEl, so
        // the class moves with them or the root is left as a grid of one.
        var posClass = 'agend-filters-' + (cfg.filterPosition || 'top');
        root.classList.remove(posClass);
        catalogueEl.classList.add(posClass);
        if (!target.contains(serverFilters)) {
          target.appendChild(serverFilters);
        }
        window.agendFilters.build(serverFilters, {
          state: state,
          reload: reloadCatalogue,
          apiGet: apiGet,
        });
        return;
      }
      buildFilterBar(target, cfg, state, reloadCatalogue);
    }

    function openItem(slug) {
      if (cfg.onDetailPage) {
        showDetail(slug, true);
        return;
      }
      window.location.assign(deepLinkUrl(slug));
    }

    // Templated cards are raw HTML, so clicks are delegated from the grid. A
    // register button inside a card also opens the detail, where the
    // registration flow lives.
    function onTemplatedCardClick(e) {
      var card = e.target.closest ? e.target.closest('[data-agend-slug]') : null;
      if (!card || !grid.contains(card)) {
        return;
      }
      var slug = card.getAttribute('data-agend-slug');
      if (!slug) {
        return;
      }
      // Off the dedicated page the anchor navigates natively, so middle-click
      // and open-in-new-tab keep working.
      if (!cfg.onDetailPage && card.tagName === 'A') {
        return;
      }
      e.preventDefault();
      openItem(slug);
    }

    function fragmentGet(params) {
      var query = { template: cfg.cardTemplate, detail_page: cfg.hostPageId || 0, card_link_whole: cfg.cardLinkWhole ? 1 : 0 };
      Object.keys(params || {}).forEach(function (key) {
        query[key] = params[key];
      });
      return apiGetFrom(cfg.restBase || '', cfg.fragmentPath, query);
    }

    // Fragments are this plugin's own PHP output with every record value
    // escaped server-side by the field widgets, so they are inserted as-is:
    // the DOMPurify pass used for gateway rich text would strip Elementor's
    // inline styles and data attributes.
    function insertFragments(cards) {
      cards.forEach(function (card) {
        var wrap = document.createElement('div');
        wrap.innerHTML = card.html || '';
        while (wrap.firstChild) {
          grid.appendChild(wrap.firstChild);
        }
      });
      if (window.agendRecordFields && window.agendRecordFields.apply) {
        window.agendRecordFields.apply(grid);
      }
    }

    function setUrlParam(slug) {
      try {
        var target;
        if (slug) {
          target = deepLinkUrl(slug);
        } else if (cfg.prettyLinks && cfg.basePath) {
          // Returning to the catalogue: drop the /event/{slug}/ path segment.
          target = cfg.basePath;
        } else {
          var url = new URL(window.location.href);
          url.searchParams.delete(DEEP_LINK_PARAM);
          target = url.toString();
        }
        window.history.pushState({ agendEvent: slug || null }, '', target);
      } catch (e) {
        /* history API unavailable — navigation still works in-page */
      }
    }

    function showCatalogue(updateUrl) {
      root.innerHTML = '';
      root.appendChild(catalogueEl);
      if (updateUrl) {
        setUrlParam(null);
      }
    }

    function showDetail(slug, updateUrl, payState) {
      root.innerHTML = '';
      root.appendChild(renderDetailSkeleton());
      if (updateUrl) {
        setUrlParam(slug);
      }
      apiGet('/events/' + encodeURIComponent(slug), { include: 'sponsors,categories' }).then(function (body) {
        var event = unwrapOne(body);
        root.innerHTML = '';
        if (!event || !event.slug) {
          root.appendChild(renderNotFound(function () { showCatalogue(true); }));
          return;
        }
        if (payState === 'success') {
          root.appendChild(renderConfirmation(
            event,
            cfg,
            function () { clearPayParam(); showDetail(slug, false); },
            function () { clearPayParam(); showCatalogue(true); }
          ));
          return;
        }
        root.appendChild(renderDetail(event, cfg, function () { showCatalogue(true); }, function (ev) { showRegistration(ev); }));
      }).catch(function () {
        root.innerHTML = '';
        root.appendChild(renderNotFound(function () { showCatalogue(true); }));
      });
    }

    function showRegistration(event) {
      root.innerHTML = '';
      root.appendChild(renderRegistration(
        event,
        cfg,
        function () { showDetail(event.slug, false); },
        function (ev, mode) {
          root.innerHTML = '';
          root.appendChild(renderConfirmation(
            ev,
            cfg,
            function () { showDetail(ev.slug, false); },
            function () { showCatalogue(true); },
            mode
          ));
        }
      ));
    }

    function clearPayParam() {
      try {
        var url = new URL(window.location.href);
        url.searchParams.delete(PAY_PARAM);
        window.history.replaceState({}, '', url.toString());
      } catch (e) {
        /* history API unavailable */
      }
    }

    function reloadCatalogue() {
      status.style.display = 'none';
      pager.innerHTML = '';
      // Pending state: one complete row of card placeholders (US: no plain
      // "Loading…" text while data loads).
      if (!state.append) {
        grid.innerHTML = '';
        appendGridSkeletons(grid, cfg);
      }
      var exclusions = cfg.exclusions || {};
      var params = {
        page: state.page,
        limit: cfg.pagination.perPage,
        search: state.search,
        category: state.category,
        type: state.type,
        city: state.city,
        categories: state.categories,
        types: state.types,
        cities: state.cities,
        categoriesMatch: (cfg.filters && cfg.filters.categoryMatch) || 'any',
        timeframe: cfg.timeframe || 'upcoming',
        startAfter: state.startAfter,
        startBefore: state.startBefore,
        excludeCategories: exclusions.categories || [],
        excludeVenueTypes: exclusions.venueTypes || [],
        excludeCities: exclusions.cities || [],
        excludeCategoriesMatch: exclusions.categoryMatch || 'any',
      };
      (templated ? fragmentGet(params) : apiGet('/events', params)).then(function (body) {
        var result = templated
          ? { items: (body && body.cards) || [], pagination: (body && body.meta && body.meta.pagination) || null }
          : unwrapList(body);
        status.style.display = 'none';
        if (!state.append) {
          grid.innerHTML = '';
        }
        state.append = false;
        if (!result.items.length && !grid.childNodes.length) {
          status.style.display = '';
          status.textContent = 'No events found.';
          return;
        }
        if (templated) {
          insertFragments(result.items);
        } else {
          result.items.forEach(function (event) {
            grid.appendChild(renderCard(event, cfg, function (slug) { openItem(slug); }));
          });
        }
        renderPagination(pager, cfg, state, result.pagination, reloadCatalogue);
      }).catch(function () {
        if (!state.append) {
          grid.innerHTML = '';
        }
        status.style.display = '';
        status.textContent = 'Unable to load events.';
      });
    }

    // Deep linking (US-EVT.5): render the detail directly when the URL names an
    // event on initial load, and respond to browser back/forward.
    function currentDeepLink() {
      try {
        // Pretty path form: /{page}/event/{slug}/ (US-1.2).
        if (cfg.prettyLinks) {
          var m = window.location.pathname.match(/\/event\/([^/]+)\/?$/);
          if (m && m[1]) {
            return decodeURIComponent(m[1]);
          }
        }
        // Legacy fallback: ?agend_event= query param.
        return new URL(window.location.href).searchParams.get(DEEP_LINK_PARAM);
      } catch (e) {
        return null;
      }
    }

    window.addEventListener('popstate', function () {
      var slug = currentDeepLink();
      if (slug) {
        showDetail(slug, false);
      } else {
        showCatalogue(false);
      }
    });

    function currentPayState() {
      try {
        return new URL(window.location.href).searchParams.get(PAY_PARAM);
      } catch (e) {
        return null;
      }
    }

    // Server-injected slug (from the rewrite endpoint) wins on first load, then
    // fall back to parsing the URL (pretty path or legacy query param).
    var deepLinkSlug = cfg.deepLink || currentDeepLink();
    var payState = currentPayState();
    if (templated) {
      // The first page is already in the DOM; only wire pagination, and
      // refetch when the server-side fetch failed.
      renderPagination(pager, cfg, state, cfg.initialPagination || null, reloadCatalogue);
      if (deepLinkSlug) {
        showDetail(deepLinkSlug, false, payState === 'success' ? 'success' : null);
      } else {
        showCatalogue(false);
      }
      if (cfg.initialError) {
        reloadCatalogue();
      }
    } else if (deepLinkSlug) {
      showDetail(deepLinkSlug, false, payState === 'success' ? 'success' : null);
      reloadCatalogue(); // warm the cached catalogue for a snappy Back
    } else {
      showCatalogue(false);
      reloadCatalogue();
    }
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-events-catalogue[data-agend-events-config]');
    Array.prototype.forEach.call(nodes, initWidget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
