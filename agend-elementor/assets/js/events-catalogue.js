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
    var url = restBase().replace(/\/$/, '') + path;
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
      url += '?' + qs.join('&');
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

  function dateRange(startIso, endIso) {
    if (!startIso) {
      return '';
    }
    var opts = { day: 'numeric', month: 'short', year: 'numeric' };
    var startStr = new Date(startIso).toLocaleDateString('en-AU', opts);
    if (!endIso) {
      return startStr;
    }
    var endStr = new Date(endIso).toLocaleDateString('en-AU', opts);
    return startStr === endStr ? startStr : startStr + ' – ' + endStr;
  }

  function dateTime(startIso, endIso) {
    if (!startIso) {
      return '';
    }
    var dOpts = { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' };
    var tOpts = { hour: 'numeric', minute: '2-digit' };
    var start = new Date(startIso);
    var str = start.toLocaleDateString('en-AU', dOpts) + ', ' + start.toLocaleTimeString('en-AU', tOpts);
    if (endIso) {
      str += ' – ' + new Date(endIso).toLocaleTimeString('en-AU', tOpts);
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
        var d = new Date(event.start_date);
        var badge = el('div', 'agend-ev-card__date-badge');
        badge.appendChild(el('span', 'agend-ev-card__date-month', MONTHS[d.getMonth()]));
        badge.appendChild(el('span', 'agend-ev-card__date-day', d.getDate()));
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

    var range = dateRange(event.start_date, event.end_date);
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

    if (cfg.card.pricing && event.price_summary) {
      var pricing = el('div', 'agend-ev-card__pricing');
      [['Members', event.price_summary.member_from], ['Non-Members', event.price_summary.non_member_from]].forEach(function (pair) {
        var price = formatPrice(pair[1]);
        if (price === null) {
          return;
        }
        var row = el('div', 'agend-ev-price');
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
    heroInner.appendChild(el('div', 'agend-ev-detail__meta', [dateRange(event.start_date, event.end_date), event.venue_name || (event.venue_type === 'virtual' ? 'Online' : 'TBA')].filter(Boolean).join(' · ')));
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
    var registerBtn = el('button', 'agend-ev-detail__cta', event.sold_out ? 'Sold Out' : 'Register Now');
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
    reg.appendChild(el('p', 'agend-ev-detail__note', 'Not a member? Join for discounted pricing.'));
    side.appendChild(reg);

    var facts = el('div', 'agend-ev-detail__panel');
    facts.appendChild(el('h3', 'agend-ev-detail__panel-title', 'Details'));
    [
      ['Date & Time', dateTime(event.start_date, event.end_date)],
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

    if (cfg.filters.category) {
      var category = el('select', 'agend-ev-filter');
      category.appendChild(new Option('All Categories', ''));
      var excludedCategories = (cfg.exclusions && cfg.exclusions.categories) || [];
      apiGet('/events/categories', {}).then(function (body) {
        unwrapList(body).items.forEach(function (cat) {
          // Excluded categories can never match an event in this widget, so
          // offering them in the filter would only produce empty results.
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

    if (cfg.filters.type) {
      var type = el('select', 'agend-ev-filter');
      [['All Types', ''], ['In-Person', 'physical'], ['Online', 'virtual'], ['Hybrid', 'hybrid']].forEach(function (o) {
        type.appendChild(new Option(o[0], o[1]));
      });
      type.addEventListener('change', function () {
        state.type = type.value;
        state.page = 1;
        reload();
      });
      bar.appendChild(type);
    }

    if (cfg.filters.city) {
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

      var closePanel = function () {
        panel.hidden = true;
        dateToggle.setAttribute('aria-expanded', 'false');
      };
      dateToggle.addEventListener('click', function (e) {
        e.stopPropagation();
        if (panel.hidden) {
          panel.hidden = false;
          dateToggle.setAttribute('aria-expanded', 'true');
        } else {
          closePanel();
        }
      });
      panel.addEventListener('click', function (e) {
        e.stopPropagation();
      });
      document.addEventListener('click', closePanel);

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

  // Anonymous widget visitors resolve to non-member pricing; identity-aware
  // member pricing is gated on the SSO bearer worker (held, SPEC addendum E-11).
  function ticketTierPrice(entry, group) {
    var tiers = (entry && entry.pricingTiers) || [];
    if (tiers.length) {
      var tier = tiers[0].tier || tiers[0];
      var v = group === 'member' ? tier.member_price : tier.non_member_price;
      if (v === null || v === undefined) {
        v = tier.non_member_price != null ? tier.non_member_price : tier.member_price;
      }
      return typeof v === 'string' ? parseFloat(v) : (v || 0);
    }
    var t = (entry && entry.ticket) || entry || {};
    var p = t.price != null ? t.price : t.base_price;
    return typeof p === 'string' ? parseFloat(p) : (p || 0);
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
    wrap.appendChild(el('div', 'agend-ev-reg__meta', dateRange(event.start_date, event.end_date)));

    var status = el('div', 'agend-ev-status', 'Loading tickets…');
    wrap.appendChild(status);

    var form = el('div', 'agend-ev-reg__form');
    form.style.display = 'none';
    wrap.appendChild(form);

    var ticketsSection = el('div', 'agend-ev-reg__tickets');
    form.appendChild(ticketsSection);

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
    form.appendChild(buyerSection);

    var summary = el('div', 'agend-ev-reg__summary');
    form.appendChild(summary);

    var errorBox = el('div', 'agend-ev-reg__error');
    errorBox.style.display = 'none';
    form.appendChild(errorBox);

    var submit = el('button', 'agend-ev-detail__cta agend-ev-reg__submit', 'Confirm Registration');
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
        submit.textContent = t === 0 ? 'Confirm Registration' : 'Proceed to Payment';
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
        var price = ticketTierPrice(entry, 'non_member');
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
      var first = buyerFirst.value.trim();
      var email = buyerEmail.value.trim();
      if (!first || !email) {
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

      var buyer = {
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
            price_group: 'non_member',
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

  function renderConfirmation(event, cfg, onBackToEvent, onBackToEvents) {
    var wrap = el('div', 'agend-ev-reg');
    var panel = el('div', 'agend-ev-reg__confirm');
    panel.appendChild(el('div', 'agend-ev-reg__confirm-tick', '✓'));
    panel.appendChild(el('h2', 'agend-ev-reg__confirm-title', 'Registration Confirmed'));
    panel.appendChild(el('p', 'agend-ev-reg__confirm-text', 'You are registered for ' + (event.name || 'this event') + '. A confirmation email is on its way.'));
    var actions = el('div', 'agend-ev-reg__confirm-actions');
    var eventBtn = el('button', 'agend-ev-detail__cta', 'View Event');
    eventBtn.addEventListener('click', onBackToEvent);
    var listBtn = el('button', 'agend-ev-reg__link', 'Back to all events');
    listBtn.addEventListener('click', onBackToEvents);
    actions.appendChild(eventBtn);
    actions.appendChild(listBtn);
    panel.appendChild(actions);
    wrap.appendChild(panel);
    return wrap;
  }

  // -- Widget orchestration -------------------------------------------------

  function initWidget(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-events-config'));
    } catch (e) {
      return;
    }

    var state = { search: '', category: '', type: '', city: '', startAfter: '', startBefore: '', page: 1, append: false };

    // Apply inherited site theme (fonts/colours) — live via CSS custom props.
    applySiteTheme(root, cfg);

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
    buildFilterBar(catalogueEl, cfg, state, reloadCatalogue);
    catalogueEl.appendChild(status);
    catalogueEl.appendChild(grid);
    catalogueEl.appendChild(pager);

    // Build the canonical detail URL for a slug. Pretty path
    // (/{page}/event/{slug}/) when permalinks are on and the host page path is
    // known (US-1.2); otherwise the legacy ?agend_event= query param.
    function deepLinkUrl(slug) {
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
        function (ev) {
          root.innerHTML = '';
          root.appendChild(renderConfirmation(
            ev,
            cfg,
            function () { showDetail(ev.slug, false); },
            function () { showCatalogue(true); }
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
      apiGet('/events', {
        page: state.page,
        limit: cfg.pagination.perPage,
        search: state.search,
        category: state.category,
        type: state.type,
        city: state.city,
        timeframe: cfg.timeframe || 'upcoming',
        startAfter: state.startAfter,
        startBefore: state.startBefore,
        excludeCategories: exclusions.categories || [],
        excludeVenueTypes: exclusions.venueTypes || [],
        excludeCities: exclusions.cities || [],
      }).then(function (body) {
        var result = unwrapList(body);
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
        result.items.forEach(function (event) {
          grid.appendChild(renderCard(event, cfg, function (slug) { showDetail(slug, true); }));
        });
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
    if (deepLinkSlug) {
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
