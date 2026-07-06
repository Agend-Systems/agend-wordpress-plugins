/**
 * Agend Courses (Learning Hub) widget — frontend renderer.
 *
 * One widget, connected client-side states (SPEC-INFRA-LMS-001):
 *  - catalogue: searchable/filterable grid (US-LMS.1/2)
 *  - detail:    single-course landing view (US-LMS.4)
 * Deep linkable via ?agend_course=<slug> (US-LMS.6). Data comes from the Agend
 * Apps Core REST proxy (/wp-json/agend-apps/v1/lms/courses...). Enrolment-state
 * sidebars (US-LMS.5) require a member session and are a later slice; the
 * detail shows the anonymous pricing/enrol shell.
 */
(function () {
  'use strict';

  var DEEP_LINK_PARAM = 'agend_course';

  var DIFFICULTY_LABELS = {
    beginner: 'Beginner',
    intermediate: 'Intermediate',
    advanced: 'Advanced',
    all_levels: 'All Levels',
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

  function stripHtml(html) {
    if (!html) {
      return '';
    }
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
  }

  function setSafeHtml(node, html) {
    var tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    var dangerous = tmp.querySelectorAll('script, style, iframe, object, embed, link, meta, form');
    Array.prototype.forEach.call(dangerous, function (n) {
      n.parentNode.removeChild(n);
    });
    Array.prototype.forEach.call(tmp.querySelectorAll('*'), function (elm) {
      Array.prototype.slice.call(elm.attributes).forEach(function (attr) {
        var name = attr.name.toLowerCase();
        var value = (attr.value || '').replace(/\s+/g, '').toLowerCase();
        if (name.indexOf('on') === 0 || ((name === 'href' || name === 'src' || name === 'xlink:href') && value.indexOf('javascript:') === 0)) {
          elm.removeAttribute(attr.name);
        }
      });
    });
    node.innerHTML = tmp.innerHTML;
  }

  function truncate(text, length) {
    if (!text) {
      return '';
    }
    return text.length <= length ? text : text.slice(0, length).replace(/\s+\S*$/, '') + '…';
  }

  function difficultyLabel(value) {
    return DIFFICULTY_LABELS[value] || value || '';
  }

  function formatDuration(minutes) {
    var m = typeof minutes === 'string' ? parseInt(minutes, 10) : minutes;
    if (!m || isNaN(m) || m <= 0) {
      return 'Self-paced';
    }
    var h = Math.floor(m / 60);
    var rem = m % 60;
    if (h && rem) {
      return h + 'h ' + rem + 'm';
    }
    return h ? h + 'h' : rem + 'm';
  }

  function priceLabel(course) {
    if (course.is_free) {
      return 'Free';
    }
    var p = course.base_price;
    var num = typeof p === 'string' ? parseFloat(p) : p;
    if (num === null || num === undefined || isNaN(num) || num === 0) {
      return 'Free';
    }
    return '$' + num.toFixed(2);
  }

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
          root.style.setProperty('--agend-lms-heading', heading);
        }
        if (body2) {
          root.style.setProperty('--agend-lms-body', body2);
        }
        if (accent) {
          root.style.setProperty('--agend-lms-accent', accent);
          root.style.setProperty('--agend-lms-button', accent);
        }
      }
      if (cfg.theme.inheritFonts && theme.fonts) {
        if (theme.fonts.heading) {
          root.style.setProperty('--agend-lms-font-heading', '"' + theme.fonts.heading + '", sans-serif');
        }
        if (theme.fonts.body) {
          root.style.setProperty('--agend-lms-font-body', '"' + theme.fonts.body + '", sans-serif');
        }
      }
    }).catch(function () {});
  }

  // -- Catalogue ------------------------------------------------------------

  function renderCard(course, cfg, onOpen) {
    var card = el('article', 'agend-lms-card');
    card.setAttribute('role', 'button');
    card.setAttribute('tabindex', '0');
    card.addEventListener('click', function () {
      onOpen(course.slug);
    });
    card.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        onOpen(course.slug);
      }
    });

    if (cfg.card.image) {
      var media = el('div', 'agend-lms-card__media');
      if (course.image_url) {
        var img = el('img', 'agend-lms-card__img');
        img.src = course.image_url;
        img.alt = course.title || '';
        img.loading = 'lazy';
        media.appendChild(img);
      } else {
        media.classList.add('agend-lms-card__media--placeholder');
      }
      if (cfg.card.difficulty && course.difficulty) {
        var badge = el('span', 'agend-lms-badge agend-lms-badge--' + course.difficulty, difficultyLabel(course.difficulty));
        media.appendChild(badge);
      }
      card.appendChild(media);
    }

    var body = el('div', 'agend-lms-card__body');

    if (cfg.card.category && course.category) {
      body.appendChild(el('span', 'agend-lms-card__category', course.category));
    }

    body.appendChild(el('h3', 'agend-lms-card__title', course.title || ''));

    if (cfg.card.description) {
      var desc = stripHtml(course.description || '');
      if (desc) {
        body.appendChild(el('p', 'agend-lms-card__desc', truncate(desc, cfg.card.excerptLength)));
      }
    }

    var footer = el('div', 'agend-lms-card__footer');
    if (cfg.card.meta) {
      var meta = el('div', 'agend-lms-card__meta');
      meta.appendChild(el('span', 'agend-lms-card__meta-item', formatDuration(course.total_duration_minutes)));
      var modules = (course.lessons_count || 0) + ' ' + ((course.lessons_count === 1) ? 'module' : 'modules');
      meta.appendChild(el('span', 'agend-lms-card__meta-item', modules));
      footer.appendChild(meta);
    }
    if (cfg.card.price) {
      var price = priceLabel(course);
      footer.appendChild(el('span', 'agend-lms-card__price' + (price === 'Free' ? ' is-free' : ''), price));
    }
    body.appendChild(footer);

    card.appendChild(body);
    return card;
  }

  // -- Detail ---------------------------------------------------------------

  function renderDetail(course, cfg, onBack) {
    var wrap = el('div', 'agend-lms-detail');

    var back = el('button', 'agend-lms-detail__back', '← Back to Learning');
    back.addEventListener('click', onBack);
    wrap.appendChild(back);

    var hero = el('div', 'agend-lms-detail__hero');
    if (course.image_url) {
      hero.style.backgroundImage = 'linear-gradient(180deg, rgba(30,42,74,0.4), rgba(30,42,74,0.88)), url("' + course.image_url + '")';
    }
    var heroInner = el('div', 'agend-lms-detail__hero-inner');
    var pills = el('div', 'agend-lms-card__pills');
    if (course.category) {
      pills.appendChild(el('span', 'agend-lms-pill agend-lms-pill--category', course.category));
    }
    if (course.difficulty) {
      pills.appendChild(el('span', 'agend-lms-pill agend-lms-pill--difficulty', difficultyLabel(course.difficulty)));
    }
    heroInner.appendChild(pills);
    heroInner.appendChild(el('h2', 'agend-lms-detail__title', course.title || ''));
    var meta = [formatDuration(course.total_duration_minutes), (course.lessons_count || 0) + ' modules'];
    if (course.instructor_name) {
      meta.push(course.instructor_name);
    }
    heroInner.appendChild(el('div', 'agend-lms-detail__meta', meta.filter(Boolean).join(' · ')));
    hero.appendChild(heroInner);
    wrap.appendChild(hero);

    var layout = el('div', 'agend-lms-detail__layout');
    var main = el('div', 'agend-lms-detail__main');

    if (course.description) {
      var about = el('section', 'agend-lms-detail__section');
      about.appendChild(el('h3', 'agend-lms-detail__section-title', 'About This Course'));
      var para = el('div', 'agend-lms-detail__body-text');
      setSafeHtml(para, course.description);
      about.appendChild(para);
      main.appendChild(about);
    }

    if (course.learning_outcomes && course.learning_outcomes.length) {
      var outcomes = el('section', 'agend-lms-detail__section');
      outcomes.appendChild(el('h3', 'agend-lms-detail__section-title', "What You'll Learn"));
      var list = el('ul', 'agend-lms-detail__outcomes');
      course.learning_outcomes.forEach(function (o) {
        list.appendChild(el('li', 'agend-lms-detail__outcome', typeof o === 'string' ? o : (o && o.text) || ''));
      });
      outcomes.appendChild(list);
      main.appendChild(outcomes);
    }

    layout.appendChild(main);

    var side = el('aside', 'agend-lms-detail__side');
    var pricing = el('div', 'agend-lms-detail__panel agend-lms-detail__panel--pricing');
    pricing.appendChild(el('h3', 'agend-lms-detail__panel-title', 'Course Pricing'));
    var priceRow = el('div', 'agend-lms-detail__price-row');
    priceRow.appendChild(el('span', 'agend-lms-detail__price-label', 'Price'));
    var price = priceLabel(course);
    priceRow.appendChild(el('span', 'agend-lms-detail__price-value' + (price === 'Free' ? ' is-free' : ''), price));
    pricing.appendChild(priceRow);
    // Enrolment (tier selection + member state) is a later slice; the CTA is
    // the anonymous shell for now.
    var cta = el('button', 'agend-lms-detail__cta', 'Enrol Now');
    cta.setAttribute('data-agend-course-slug', course.slug);
    pricing.appendChild(cta);
    pricing.appendChild(el('p', 'agend-lms-detail__note', 'Sign in to enrol and track your progress.'));
    side.appendChild(pricing);

    var facts = el('div', 'agend-lms-detail__panel');
    facts.appendChild(el('h3', 'agend-lms-detail__panel-title', 'Details'));
    [
      ['Level', difficultyLabel(course.difficulty)],
      ['Duration', formatDuration(course.total_duration_minutes)],
      ['Modules', String(course.lessons_count || 0)],
      ['Category', course.category],
      ['Instructor', course.instructor_name],
    ].forEach(function (pair) {
      if (!pair[1]) {
        return;
      }
      var row = el('div', 'agend-lms-detail__fact');
      row.appendChild(el('span', 'agend-lms-detail__fact-label', pair[0]));
      row.appendChild(el('span', 'agend-lms-detail__fact-value', pair[1]));
      facts.appendChild(row);
    });
    side.appendChild(facts);

    layout.appendChild(side);
    wrap.appendChild(layout);
    return wrap;
  }

  function renderNotFound(onBack) {
    var wrap = el('div', 'agend-lms-detail');
    var back = el('button', 'agend-lms-detail__back', '← Back to Learning');
    back.addEventListener('click', onBack);
    wrap.appendChild(back);
    wrap.appendChild(el('div', 'agend-lms-status', 'Course not found.'));
    return wrap;
  }

  // -- Filters + pagination -------------------------------------------------

  function buildFilterBar(root, cfg, state, reload) {
    if (!cfg.filters.search && !cfg.filters.category && !cfg.filters.difficulty) {
      return;
    }
    var bar = el('div', 'agend-lms-filterbar');

    if (cfg.filters.search) {
      var search = el('input', 'agend-lms-search');
      search.type = 'search';
      search.placeholder = 'Search courses…';
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
      var category = el('select', 'agend-lms-filter');
      category.appendChild(new Option('All Categories', ''));
      // Course categories are free-text on the course; derive the distinct set
      // from a wide fetch.
      apiGet('/lms/courses', { limit: 100 }).then(function (body) {
        var seen = {};
        unwrapList(body).items.forEach(function (course) {
          if (course.category && !seen[course.category]) {
            seen[course.category] = true;
            category.appendChild(new Option(course.category, course.category));
          }
        });
      });
      category.addEventListener('change', function () {
        state.category = category.value;
        state.page = 1;
        reload();
      });
      bar.appendChild(category);
    }

    if (cfg.filters.difficulty) {
      var difficulty = el('select', 'agend-lms-filter');
      [['All Levels', ''], ['Beginner', 'beginner'], ['Intermediate', 'intermediate'], ['Advanced', 'advanced']].forEach(function (o) {
        difficulty.appendChild(new Option(o[0], o[1]));
      });
      difficulty.addEventListener('change', function () {
        state.difficulty = difficulty.value;
        state.page = 1;
        reload();
      });
      bar.appendChild(difficulty);
    }

    root.appendChild(bar);
  }

  function renderPagination(root, cfg, state, pagination, reload) {
    if (cfg.pagination.style === 'none' || !pagination) {
      return;
    }
    var nav = el('div', 'agend-lms-pagination');
    if (cfg.pagination.style === 'load_more') {
      if (pagination.has_next) {
        var more = el('button', 'agend-lms-loadmore', 'Load more courses');
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
          var btn = el('button', 'agend-lms-page' + (pageNum === pagination.page ? ' is-active' : ''), pageNum);
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
      cfg = JSON.parse(root.getAttribute('data-agend-courses-config'));
    } catch (e) {
      return;
    }

    var state = { search: '', category: '', difficulty: '', page: 1, append: false };
    applySiteTheme(root, cfg);

    var catalogueEl = el('div', 'agend-lms-catalogue');
    var status = el('div', 'agend-lms-status', 'Loading courses…');
    var grid = el('div', 'agend-lms-grid');
    grid.style.setProperty('--agend-lms-cols-desktop', cfg.layout.desktop);
    grid.style.setProperty('--agend-lms-cols-tablet', cfg.layout.tablet);
    grid.style.setProperty('--agend-lms-cols-mobile', cfg.layout.mobile);
    var pager = el('div', 'agend-lms-pager-slot');

    if (cfg.heading && cfg.heading.show) {
      var head = el('div', 'agend-lms-heading');
      if (cfg.heading.title) {
        head.appendChild(el('h2', 'agend-lms-heading__title', cfg.heading.title));
      }
      if (cfg.heading.subtitle) {
        head.appendChild(el('p', 'agend-lms-heading__subtitle', cfg.heading.subtitle));
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
        window.history.pushState({ agendCourse: slug || null }, '', url.toString());
      } catch (e) {}
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
      root.appendChild(el('div', 'agend-lms-status', 'Loading course…'));
      if (updateUrl) {
        setUrlParam(slug);
      }
      apiGet('/lms/courses/' + encodeURIComponent(slug), {}).then(function (body) {
        var course = unwrapOne(body);
        root.innerHTML = '';
        if (!course || !course.slug) {
          root.appendChild(renderNotFound(function () { showCatalogue(true); }));
          return;
        }
        root.appendChild(renderDetail(course, cfg, function () { showCatalogue(true); }));
      }).catch(function () {
        root.innerHTML = '';
        root.appendChild(renderNotFound(function () { showCatalogue(true); }));
      });
    }

    function reloadCatalogue() {
      status.textContent = 'Loading courses…';
      status.style.display = '';
      pager.innerHTML = '';
      apiGet('/lms/courses', {
        page: state.page,
        limit: cfg.pagination.perPage,
        search: state.search,
        category: state.category,
        difficulty: state.difficulty,
      }).then(function (body) {
        var result = unwrapList(body);
        status.style.display = 'none';
        if (!state.append) {
          grid.innerHTML = '';
        }
        state.append = false;
        if (!result.items.length && !grid.childNodes.length) {
          status.style.display = '';
          status.textContent = 'No courses found.';
          return;
        }
        result.items.forEach(function (course) {
          grid.appendChild(renderCard(course, cfg, function (slug) { showDetail(slug, true); }));
        });
        renderPagination(pager, cfg, state, result.pagination, reloadCatalogue);
      }).catch(function () {
        status.style.display = '';
        status.textContent = 'Unable to load courses.';
      });
    }

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
      reloadCatalogue();
    } else {
      showCatalogue(false);
      reloadCatalogue();
    }
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-courses-catalogue[data-agend-courses-config]');
    Array.prototype.forEach.call(nodes, initWidget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
