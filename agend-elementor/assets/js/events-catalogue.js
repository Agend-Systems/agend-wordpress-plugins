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

  function unwrapList(body) {
    if (body && Array.isArray(body.data)) {
      return { items: body.data, pagination: (body.meta && body.meta.pagination) || null };
    }
    if (Array.isArray(body)) {
      return { items: body, pagination: null };
    }
    return { items: [], pagination: null };
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
      var desc = event.short_description || event.description || '';
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

  function renderDetail(event, cfg, onBack) {
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
      para.textContent = event.description;
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
    // Registration flow (ticket selection + payment) is a later slice; the CTA
    // is the panel shell for now.
    registerBtn.setAttribute('data-agend-event-slug', event.slug);
    reg.appendChild(registerBtn);
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
    if (!cfg.filters.search && !cfg.filters.category && !cfg.filters.type && !cfg.filters.city) {
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
      apiGet('/events/categories', {}).then(function (body) {
        unwrapList(body).items.forEach(function (cat) {
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

  // -- Widget orchestration -------------------------------------------------

  function initWidget(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-events-config'));
    } catch (e) {
      return;
    }

    var state = { search: '', category: '', type: '', city: '', page: 1, append: false };

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

    function setUrlParam(slug) {
      try {
        var url = new URL(window.location.href);
        if (slug) {
          url.searchParams.set(DEEP_LINK_PARAM, slug);
        } else {
          url.searchParams.delete(DEEP_LINK_PARAM);
        }
        window.history.pushState({ agendEvent: slug || null }, '', url.toString());
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

    function showDetail(slug, updateUrl) {
      root.innerHTML = '';
      var loading = el('div', 'agend-ev-status', 'Loading event…');
      root.appendChild(loading);
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
        root.appendChild(renderDetail(event, cfg, function () { showCatalogue(true); }));
      }).catch(function () {
        root.innerHTML = '';
        root.appendChild(renderNotFound(function () { showCatalogue(true); }));
      });
    }

    function reloadCatalogue() {
      status.textContent = 'Loading events…';
      status.style.display = '';
      pager.innerHTML = '';
      apiGet('/events', {
        page: state.page,
        limit: cfg.pagination.perPage,
        search: state.search,
        category: state.category,
        type: state.type,
        city: state.city,
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
        status.style.display = '';
        status.textContent = 'Unable to load events.';
      });
    }

    // Deep linking (US-EVT.5): render the detail directly when the URL names an
    // event on initial load, and respond to browser back/forward.
    function currentDeepLink() {
      try {
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

    var deepLinkSlug = currentDeepLink();
    if (deepLinkSlug) {
      showDetail(deepLinkSlug, false);
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
