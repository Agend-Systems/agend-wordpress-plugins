/**
 * Agend Events Catalogue — frontend renderer.
 *
 * Reads the per-widget config emitted by the Events Catalogue Elementor widget,
 * fetches published events from the Agend Apps Core REST proxy
 * (`/wp-json/agend-apps/v1/events`), and renders a searchable, filterable card
 * grid client-side. Catalogue surface of SPEC-INFRA-EVT-001 (US-EVT.1/2/7/8).
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

  // The proxy passes the gateway envelope through: { success, data, meta }.
  function unwrapList(body) {
    if (body && Array.isArray(body.data)) {
      return { items: body.data, pagination: (body.meta && body.meta.pagination) || null };
    }
    if (Array.isArray(body)) {
      return { items: body, pagination: null };
    }
    return { items: [], pagination: null };
  }

  function formatPrice(value) {
    var num = typeof value === 'string' ? parseFloat(value) : value;
    if (num === null || num === undefined || isNaN(num)) {
      return null;
    }
    if (num === 0) {
      return 'FREE';
    }
    return '$' + num.toFixed(2);
  }

  function truncate(text, length) {
    if (!text) {
      return '';
    }
    if (text.length <= length) {
      return text;
    }
    return text.slice(0, length).replace(/\s+\S*$/, '') + '…';
  }

  function dateRange(startIso, endIso) {
    if (!startIso) {
      return '';
    }
    var start = new Date(startIso);
    var opts = { day: 'numeric', month: 'short', year: 'numeric' };
    var startStr = start.toLocaleDateString('en-AU', opts);
    if (!endIso) {
      return startStr;
    }
    var end = new Date(endIso);
    var endStr = end.toLocaleDateString('en-AU', opts);
    return startStr === endStr ? startStr : startStr + ' – ' + endStr;
  }

  function renderCard(event, cfg) {
    var card = el('article', 'agend-ev-card');

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

    var bodyEl = el('div', 'agend-ev-card__body');

    if (cfg.card.pills) {
      var pills = el('div', 'agend-ev-card__pills');
      if (event.category && event.category.name) {
        pills.appendChild(el('span', 'agend-ev-pill agend-ev-pill--category', event.category.name));
      }
      var typeLabel = TYPE_LABELS[event.venue_type] || event.venue_type;
      if (typeLabel) {
        pills.appendChild(el('span', 'agend-ev-pill agend-ev-pill--type', typeLabel));
      }
      bodyEl.appendChild(pills);
    }

    bodyEl.appendChild(el('h3', 'agend-ev-card__title', event.name || ''));

    var range = dateRange(event.start_date, event.end_date);
    if (range) {
      bodyEl.appendChild(el('div', 'agend-ev-card__date', range));
    }

    var location = event.venue_name || (event.venue_type === 'virtual' ? 'Online' : 'TBA');
    bodyEl.appendChild(el('div', 'agend-ev-card__location', location));

    if (cfg.card.description) {
      var desc = event.short_description || event.description || '';
      if (desc) {
        bodyEl.appendChild(el('p', 'agend-ev-card__desc', truncate(desc, cfg.card.excerptLength)));
      }
    }

    if (cfg.card.pricing && event.price_summary) {
      var pricing = el('div', 'agend-ev-card__pricing');
      var member = formatPrice(event.price_summary.member_from);
      var nonMember = formatPrice(event.price_summary.non_member_from);
      if (member !== null) {
        var mRow = el('div', 'agend-ev-price');
        mRow.appendChild(el('span', 'agend-ev-price__label', 'Members'));
        mRow.appendChild(el('span', 'agend-ev-price__value' + (member === 'FREE' ? ' is-free' : ''), member));
        pricing.appendChild(mRow);
      }
      if (nonMember !== null) {
        var nRow = el('div', 'agend-ev-price');
        nRow.appendChild(el('span', 'agend-ev-price__label', 'Non-Members'));
        nRow.appendChild(el('span', 'agend-ev-price__value' + (nonMember === 'FREE' ? ' is-free' : ''), nonMember));
        pricing.appendChild(nRow);
      }
      bodyEl.appendChild(pricing);
    }

    card.appendChild(bodyEl);
    return card;
  }

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
      var category = el('select', 'agend-ev-filter agend-ev-filter--category');
      category.appendChild(new Option('All Categories', ''));
      apiGet('/events/categories', {}).then(function (body) {
        var items = unwrapList(body).items;
        items.forEach(function (cat) {
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
      var type = el('select', 'agend-ev-filter agend-ev-filter--type');
      type.appendChild(new Option('All Types', ''));
      type.appendChild(new Option('In-Person', 'physical'));
      type.appendChild(new Option('Online', 'virtual'));
      type.appendChild(new Option('Hybrid', 'hybrid'));
      type.addEventListener('change', function () {
        state.type = type.value;
        state.page = 1;
        reload();
      });
      bar.appendChild(type);
    }

    if (cfg.filters.city) {
      var city = el('select', 'agend-ev-filter agend-ev-filter--city');
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
      var totalPages = pagination.total_pages || 1;
      for (var i = 1; i <= totalPages; i++) {
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

  function initWidget(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-events-config'));
    } catch (e) {
      return;
    }

    root.innerHTML = '';

    if (cfg.heading && cfg.heading.show) {
      var head = el('div', 'agend-ev-heading');
      if (cfg.heading.title) {
        head.appendChild(el('h2', 'agend-ev-heading__title', cfg.heading.title));
      }
      if (cfg.heading.subtitle) {
        head.appendChild(el('p', 'agend-ev-heading__subtitle', cfg.heading.subtitle));
      }
      root.appendChild(head);
    }

    var state = { search: '', category: '', type: '', city: '', page: 1, append: false };

    var grid = el('div', 'agend-ev-grid');
    grid.style.setProperty('--agend-ev-cols-desktop', cfg.layout.desktop);
    grid.style.setProperty('--agend-ev-cols-tablet', cfg.layout.tablet);
    grid.style.setProperty('--agend-ev-cols-mobile', cfg.layout.mobile);

    var pager = el('div', 'agend-ev-pager-slot');
    var status = el('div', 'agend-ev-status', 'Loading events…');

    function reload() {
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
          grid.appendChild(renderCard(event, cfg));
        });
        renderPagination(pager, cfg, state, result.pagination, reload);
      }).catch(function () {
        status.style.display = '';
        status.textContent = 'Unable to load events.';
      });
    }

    buildFilterBar(root, cfg, state, reload);
    root.appendChild(status);
    root.appendChild(grid);
    root.appendChild(pager);
    reload();
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
