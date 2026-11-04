/**
 * Agend Jobs (Agend Careers) surface: frontend renderer.
 *
 * One surface, two connected client-side states:
 *  - board:  a searchable, filterable list or grid of published jobs
 *  - detail: a single job, with its apply action
 * Deep linkable via /{page}/job/{slug}/ (or ?agend_job=<slug> without pretty
 * permalinks). Data comes from the Agend Apps Core REST proxy
 * (/wp-json/agend-apps/v1/jobs...), which forwards to the gateway's public
 * `/v1/jobs` endpoints. Applications are made off-site: the apply action opens
 * the listing's application link, or its application email when the surface
 * allows it.
 */
(function () {
  'use strict';

  var DEEP_LINK_PARAM = 'agend_job';

  var EMPLOYMENT_TYPE_LABELS = {
    'full-time': 'Full time',
    'part-time': 'Part time',
    contract: 'Contract',
    casual: 'Casual',
    internship: 'Internship',
  };

  var WORK_ARRANGEMENT_LABELS = {
    'on-site': 'On site',
    hybrid: 'Hybrid',
    remote: 'Remote',
  };

  var SALARY_PERIOD_LABELS = {
    hour: 'per hour',
    hourly: 'per hour',
    day: 'per day',
    daily: 'per day',
    week: 'per week',
    weekly: 'per week',
    month: 'per month',
    monthly: 'per month',
    year: 'per year',
    yearly: 'per year',
    annual: 'per year',
    annually: 'per year',
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
    var url = String(restBase()).replace(/\/$/, '') + path;
    var qs = [];
    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];
      if (value === undefined || value === null || value === '' || value === false) {
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

  // Allowlist mirrors the other catalogues (and the WP proxy's wp_kses_post)
  // so every layer agrees on what safe rich text looks like.
  var SAFE_TAGS = [
    'div', 'span', 'p', 'br', 'hr',
    'h2', 'h3', 'h4', 'h5', 'h6',
    'strong', 'b', 'em', 'i', 'u', 's', 'del', 'ins', 'mark', 'sub', 'sup', 'small',
    'ul', 'ol', 'li',
    'a',
    'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
    'blockquote', 'q', 'cite',
    'code', 'pre',
  ];
  var SAFE_ATTR = ['href', 'target', 'rel', 'class', 'colspan', 'rowspan', 'scope'];

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
  }

  // Job text may arrive as HTML or as plain text with line breaks; plain text
  // is turned into paragraphs so a pasted ad keeps its shape.
  function toHtml(text) {
    if (!text) {
      return '';
    }
    if (/<[a-z][\s\S]*>/i.test(text)) {
      return text;
    }
    return String(text)
      .split(/\n{2,}/)
      .map(function (para) {
        return '<p>' + escapeHtml(para.trim()).replace(/\n/g, '<br>') + '</p>';
      })
      .join('');
  }

  // Final gate before innerHTML. Uses the vendored DOMPurify; if it is not
  // available, fails closed to plain text rather than trusting the input.
  function setSafeHtml(node, text) {
    var html = toHtml(text);
    if (!html) {
      node.textContent = '';
      return;
    }
    if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
      node.innerHTML = window.DOMPurify.sanitize(html, {
        ALLOWED_TAGS: SAFE_TAGS,
        ALLOWED_ATTR: SAFE_ATTR,
        ALLOW_DATA_ATTR: false,
        FORBID_TAGS: ['style', 'script', 'iframe', 'form', 'input', 'button', 'object', 'embed', 'img'],
      });
      Array.prototype.forEach.call(node.querySelectorAll('a[href]'), function (a) {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
      });
      return;
    }
    node.textContent = stripHtml(html);
  }

  function truncate(text, length) {
    if (!text) {
      return '';
    }
    return text.length <= length ? text : text.slice(0, length).replace(/\s+\S*$/, '') + '…';
  }

  function safeUrl(url) {
    if (!url || typeof url !== 'string') {
      return '';
    }
    try {
      var parsed = new URL(url, window.location.href);
      return parsed.protocol === 'http:' || parsed.protocol === 'https:' ? parsed.href : '';
    } catch (e) {
      return '';
    }
  }

  function safeEmail(email) {
    return typeof email === 'string' && /^[^\s@<>"]+@[^\s@<>"]+\.[^\s@<>"]+$/.test(email) ? email : '';
  }

  function formatDate(value) {
    if (!value) {
      return '';
    }
    var date = new Date(value);
    if (isNaN(date.getTime())) {
      return '';
    }
    return date.toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' });
  }

  function postedLabel(value) {
    if (!value) {
      return '';
    }
    var time = new Date(value).getTime();
    if (isNaN(time)) {
      return '';
    }
    var days = Math.floor((Date.now() - time) / 86400000);
    if (days <= 0) {
      return 'Posted today';
    }
    if (days === 1) {
      return 'Posted yesterday';
    }
    if (days < 7) {
      return 'Posted ' + days + ' days ago';
    }
    if (days < 31) {
      var weeks = Math.floor(days / 7);
      return 'Posted ' + weeks + (weeks === 1 ? ' week ago' : ' weeks ago');
    }
    return 'Posted ' + formatDate(value);
  }

  function isClosed(job) {
    if (!job.expires_at) {
      return false;
    }
    var time = new Date(job.expires_at).getTime();
    return !isNaN(time) && time < Date.now();
  }

  function locationLabel(job) {
    return [job.location_city, job.location_state, job.location_country]
      .filter(function (part) { return part; })
      .join(', ');
  }

  function salaryLabel(job) {
    if (!job.show_salary) {
      return '';
    }
    if (job.salary_range) {
      return job.salary_range;
    }
    if (!job.salary_min && !job.salary_max) {
      return '';
    }
    var fmt;
    try {
      fmt = new Intl.NumberFormat('en-AU', {
        style: 'currency',
        currency: job.salary_currency || 'AUD',
        maximumFractionDigits: 0,
      });
    } catch (e) {
      fmt = new Intl.NumberFormat('en-AU', { style: 'currency', currency: 'AUD', maximumFractionDigits: 0 });
    }
    var text;
    if (job.salary_min && job.salary_max) {
      text = fmt.format(job.salary_min) + ' to ' + fmt.format(job.salary_max);
    } else if (job.salary_min) {
      text = 'From ' + fmt.format(job.salary_min);
    } else {
      text = 'Up to ' + fmt.format(job.salary_max);
    }
    var period = SALARY_PERIOD_LABELS[String(job.salary_period || '').toLowerCase()];
    return period ? text + ' ' + period : text;
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
    if (!cfg.theme || (!cfg.theme.inheritFonts && cfg.theme.colourSource !== 'agend')) {
      return;
    }
    apiGet('/sites/config', {}).then(function (body) {
      var config = unwrapOne(body);
      if (!config) {
        return;
      }
      var theme = config.theme || {};
      if (cfg.theme.colourSource === 'agend' && theme.colors) {
        var c = theme.colors;
        var heading = normaliseColour(c.primary || c.navy || c.foreground);
        var bodyColour = normaliseColour(c.foreground || c.body);
        var accent = normaliseColour(c.accent || c.coral || c.ring);
        if (heading) {
          root.style.setProperty('--agend-jobs-heading', heading);
        }
        if (bodyColour) {
          root.style.setProperty('--agend-jobs-body', bodyColour);
        }
        if (accent) {
          root.style.setProperty('--agend-jobs-accent', accent);
          root.style.setProperty('--agend-jobs-button', accent);
        }
      }
      if (cfg.theme.inheritFonts && theme.fonts) {
        if (theme.fonts.heading) {
          root.style.setProperty('--agend-jobs-font-heading', '"' + theme.fonts.heading + '", sans-serif');
        }
        if (theme.fonts.body) {
          root.style.setProperty('--agend-jobs-font-body', '"' + theme.fonts.body + '", sans-serif');
        }
      }
    }).catch(function () {});
  }

  // -- Pieces ------------------------------------------------------------------

  function pill(text, modifier) {
    return el('span', 'agend-jobs-pill' + (modifier ? ' agend-jobs-pill--' + modifier : ''), text);
  }

  function logo(job, size) {
    var src = safeUrl(job.company_logo_url);
    var box = el('div', 'agend-jobs-logo agend-jobs-logo--' + size);
    if (src) {
      var img = el('img');
      img.src = src;
      img.alt = job.company_name ? job.company_name + ' logo' : '';
      img.loading = 'lazy';
      box.appendChild(img);
    } else {
      box.classList.add('is-placeholder');
      box.setAttribute('aria-hidden', 'true');
      box.textContent = (job.company_name || job.title || '?').trim().charAt(0).toUpperCase();
    }
    return box;
  }

  function skeletonCard() {
    var card = el('article', 'agend-jobs-card agend-jobs-skeleton');
    card.setAttribute('aria-hidden', 'true');
    var body = el('div', 'agend-jobs-card__body');
    ['55%', '35%', '80%'].forEach(function (width) {
      var line = el('div', 'agend-skel-line');
      line.style.width = width;
      body.appendChild(line);
    });
    card.appendChild(body);
    return card;
  }

  function appendSkeletons(grid, cfg) {
    var count = cfg.layout.style === 'grid' ? Math.max(1, cfg.layout.desktop) : 3;
    for (var i = 0; i < count; i++) {
      grid.appendChild(skeletonCard());
    }
  }

  function renderCard(job, cfg, href, onOpen) {
    var card = el('article', 'agend-jobs-card' + (job.is_featured ? ' is-featured' : ''));
    var c = cfg.card;

    if (c.logo) {
      card.appendChild(logo(job, 'card'));
    }

    var body = el('div', 'agend-jobs-card__body');

    if (job.is_featured) {
      body.appendChild(el('span', 'agend-jobs-card__flag', 'Featured'));
    }

    var title = el('h3', 'agend-jobs-card__title');
    var link = el('a', 'agend-jobs-card__link', job.title);
    link.href = href;
    link.addEventListener('click', function (e) {
      if (e.metaKey || e.ctrlKey || e.shiftKey || e.button === 1) {
        return;
      }
      if (onOpen) {
        e.preventDefault();
        onOpen(job.slug);
      }
    });
    title.appendChild(link);
    body.appendChild(title);

    if (c.company && job.company_name) {
      body.appendChild(el('p', 'agend-jobs-card__company', job.company_name));
    }

    var meta = el('div', 'agend-jobs-card__meta');
    if (c.location && locationLabel(job)) {
      meta.appendChild(el('span', 'agend-jobs-card__location', locationLabel(job)));
    }
    var salary = c.salary ? salaryLabel(job) : '';
    if (salary) {
      meta.appendChild(el('span', 'agend-jobs-card__salary', salary));
    }
    if (meta.childNodes.length) {
      body.appendChild(meta);
    }

    var pills = el('div', 'agend-jobs-card__pills');
    if (c.employmentType && EMPLOYMENT_TYPE_LABELS[job.employment_type]) {
      pills.appendChild(pill(EMPLOYMENT_TYPE_LABELS[job.employment_type]));
    }
    if (c.workArrangement && WORK_ARRANGEMENT_LABELS[job.location_type]) {
      pills.appendChild(pill(WORK_ARRANGEMENT_LABELS[job.location_type], job.location_type === 'remote' ? 'remote' : ''));
    }
    if (c.categories && Array.isArray(job.categories)) {
      job.categories.forEach(function (category) {
        if (category && category.name) {
          pills.appendChild(pill(category.name, 'category'));
        }
      });
    }
    if (pills.childNodes.length) {
      body.appendChild(pills);
    }

    if (c.summary) {
      var summary = job.short_description || stripHtml(toHtml(job.description || ''));
      if (summary) {
        body.appendChild(el('p', 'agend-jobs-card__summary', truncate(summary, c.excerptLength || 160)));
      }
    }

    var dates = [];
    if (c.postedDate && postedLabel(job.published_at || job.created_at)) {
      dates.push(postedLabel(job.published_at || job.created_at));
    }
    if (c.closingDate && job.expires_at && formatDate(job.expires_at)) {
      dates.push((isClosed(job) ? 'Closed ' : 'Closes ') + formatDate(job.expires_at));
    }
    if (dates.length) {
      body.appendChild(el('p', 'agend-jobs-card__dates', dates.join(' · ')));
    }

    card.appendChild(body);

    // The whole card opens the job; the title link stays the accessible
    // target so keyboard and screen reader users get one link per card.
    card.addEventListener('click', function (e) {
      if (e.target.closest && e.target.closest('a')) {
        return;
      }
      link.click();
    });

    return card;
  }

  function detailRow(list, label, value) {
    if (!value) {
      return;
    }
    list.appendChild(el('dt', null, label));
    list.appendChild(el('dd', null, value));
  }

  function applyAction(job, cfg) {
    if (isClosed(job)) {
      return el('p', 'agend-jobs-detail__closed', 'Applications for this role have closed.');
    }
    var url = safeUrl(job.application_url);
    var email = cfg.detail.emailApply ? safeEmail(job.application_email) : '';
    if (!url && !email) {
      return null;
    }
    var wrap = el('div', 'agend-jobs-apply');
    var button = el('a', 'agend-jobs-button', cfg.detail.applyLabel || 'Apply now');
    if (url) {
      button.href = url;
      button.target = '_blank';
      button.rel = 'noopener noreferrer';
    } else {
      button.href = 'mailto:' + email + '?subject=' + encodeURIComponent('Application: ' + (job.title || ''));
      wrap.appendChild(button);
      wrap.appendChild(el('p', 'agend-jobs-apply__email', 'Apply by email to ' + email));
      return wrap;
    }
    wrap.appendChild(button);
    return wrap;
  }

  function renderDetail(job, cfg, onBack) {
    var view = el('div', 'agend-jobs-detail');

    var back = el('a', 'agend-jobs-detail__back', '← All jobs');
    back.href = cfg.detailBase || cfg.basePath || '#';
    back.addEventListener('click', function (e) {
      if (onBack) {
        e.preventDefault();
        onBack();
      }
    });
    view.appendChild(back);

    var head = el('header', 'agend-jobs-detail__head');
    head.appendChild(logo(job, 'detail'));
    var headText = el('div', 'agend-jobs-detail__head-text');
    if (job.is_featured) {
      headText.appendChild(el('span', 'agend-jobs-card__flag', 'Featured'));
    }
    headText.appendChild(el('h2', 'agend-jobs-detail__title', job.title));
    if (job.company_name) {
      headText.appendChild(el('p', 'agend-jobs-detail__company', job.company_name));
    }
    var pills = el('div', 'agend-jobs-card__pills');
    if (EMPLOYMENT_TYPE_LABELS[job.employment_type]) {
      pills.appendChild(pill(EMPLOYMENT_TYPE_LABELS[job.employment_type]));
    }
    if (WORK_ARRANGEMENT_LABELS[job.location_type]) {
      pills.appendChild(pill(WORK_ARRANGEMENT_LABELS[job.location_type], job.location_type === 'remote' ? 'remote' : ''));
    }
    (job.categories || []).forEach(function (category) {
      if (category && category.name) {
        pills.appendChild(pill(category.name, 'category'));
      }
    });
    if (pills.childNodes.length) {
      headText.appendChild(pills);
    }
    head.appendChild(headText);
    view.appendChild(head);

    var layout = el('div', 'agend-jobs-detail__layout');
    var main = el('div', 'agend-jobs-detail__main');
    var side = el('aside', 'agend-jobs-detail__side');

    var description = el('div', 'agend-jobs-detail__description');
    setSafeHtml(description, job.description || job.short_description || '');
    main.appendChild(description);

    if (job.application_instructions) {
      var howTo = el('section', 'agend-jobs-detail__section');
      howTo.appendChild(el('h3', 'agend-jobs-detail__section-title', 'How to apply'));
      var howToBody = el('div');
      setSafeHtml(howToBody, job.application_instructions);
      howTo.appendChild(howToBody);
      main.appendChild(howTo);
    }

    if (Array.isArray(job.tags) && job.tags.length) {
      var tags = el('div', 'agend-jobs-detail__tags');
      job.tags.forEach(function (tag) {
        if (tag && tag.name) {
          tags.appendChild(pill(tag.name, 'tag'));
        }
      });
      main.appendChild(tags);
    }

    var summary = el('div', 'agend-jobs-detail__panel');
    var apply = applyAction(job, cfg);
    if (apply) {
      summary.appendChild(apply);
    }
    var facts = el('dl', 'agend-jobs-detail__facts');
    detailRow(facts, 'Location', locationLabel(job));
    detailRow(facts, 'Work arrangement', WORK_ARRANGEMENT_LABELS[job.location_type] || '');
    detailRow(facts, 'Employment type', EMPLOYMENT_TYPE_LABELS[job.employment_type] || '');
    detailRow(facts, 'Experience', job.experience_level || '');
    detailRow(facts, 'Salary', salaryLabel(job));
    detailRow(facts, 'Posted', formatDate(job.published_at || job.created_at));
    detailRow(facts, isClosed(job) ? 'Closed' : 'Closes', formatDate(job.expires_at));
    summary.appendChild(facts);
    side.appendChild(summary);

    var website = safeUrl(job.company_website);
    if (cfg.detail.companyPanel && (job.company_description || website)) {
      var about = el('div', 'agend-jobs-detail__panel');
      about.appendChild(el('h3', 'agend-jobs-detail__section-title', 'About ' + (job.company_name || 'the employer')));
      if (job.company_description) {
        var aboutBody = el('div', 'agend-jobs-detail__about');
        setSafeHtml(aboutBody, job.company_description);
        about.appendChild(aboutBody);
      }
      if (website) {
        var site = el('a', 'agend-jobs-detail__website', 'Visit website');
        site.href = website;
        site.target = '_blank';
        site.rel = 'noopener noreferrer';
        about.appendChild(site);
      }
      side.appendChild(about);
    }

    layout.appendChild(main);
    layout.appendChild(side);
    view.appendChild(layout);
    return view;
  }

  function renderDetailSkeleton() {
    var view = el('div', 'agend-jobs-detail agend-jobs-skeleton');
    view.setAttribute('aria-hidden', 'true');
    ['30%', '60%', '40%', '90%', '85%', '70%'].forEach(function (width) {
      var line = el('div', 'agend-skel-line');
      line.style.width = width;
      view.appendChild(line);
    });
    return view;
  }

  function renderNotFound(onBack) {
    var view = el('div', 'agend-jobs-detail agend-jobs-detail--missing');
    view.appendChild(el('p', 'agend-jobs-status', 'This job is no longer available.'));
    var back = el('button', 'agend-jobs-button agend-jobs-button--ghost', 'See all jobs');
    back.type = 'button';
    back.addEventListener('click', onBack);
    view.appendChild(back);
    return view;
  }

  // -- Filters and pagination --------------------------------------------------

  function buildFilterBar(target, cfg, state, reload) {
    var f = cfg.filters;
    if (!f.search && !f.category && !f.type && !f.location) {
      return;
    }
    var bar = el('div', 'agend-jobs-filterbar');
    bar.setAttribute('role', 'search');

    function debounced(input, key) {
      var timer;
      input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
          state[key] = input.value.trim();
          state.page = 1;
          reload();
        }, 300);
      });
    }

    if (f.search) {
      var search = el('input', 'agend-jobs-input agend-jobs-input--search');
      search.type = 'search';
      search.placeholder = 'Job title, keyword or employer';
      search.setAttribute('aria-label', 'Search jobs');
      search.value = state.search;
      debounced(search, 'search');
      bar.appendChild(search);
    }

    if (f.location) {
      var location = el('input', 'agend-jobs-input agend-jobs-input--location');
      location.type = 'search';
      location.placeholder = 'Suburb, city or state';
      location.setAttribute('aria-label', 'Location');
      location.value = state.location;
      debounced(location, 'location');
      bar.appendChild(location);
    }

    if (f.category) {
      var category = el('select', 'agend-jobs-select');
      category.setAttribute('aria-label', 'Category');
      category.appendChild(new Option('All categories', ''));
      // No public categories endpoint: derive the distinct set from a wide
      // fetch of the published jobs.
      apiGet('/jobs', { limit: 100 }).then(function (body) {
        var seen = {};
        unwrapList(body).items.forEach(function (job) {
          (job.categories || []).forEach(function (cat) {
            if (cat && cat.slug && !seen[cat.slug]) {
              seen[cat.slug] = cat.name || cat.slug;
            }
          });
        });
        Object.keys(seen)
          .sort(function (a, b) { return seen[a].localeCompare(seen[b]); })
          .forEach(function (slug) {
            category.appendChild(new Option(seen[slug], slug, false, slug === state.category));
          });
      }).catch(function () {});
      category.addEventListener('change', function () {
        state.category = category.value;
        state.page = 1;
        reload();
      });
      bar.appendChild(category);
    }

    if (f.type) {
      var type = el('select', 'agend-jobs-select');
      type.setAttribute('aria-label', 'Employment type');
      type.appendChild(new Option('All job types', ''));
      Object.keys(EMPLOYMENT_TYPE_LABELS).forEach(function (key) {
        type.appendChild(new Option(EMPLOYMENT_TYPE_LABELS[key], key, false, key === state.type));
      });
      type.addEventListener('change', function () {
        state.type = type.value;
        state.page = 1;
        reload();
      });
      bar.appendChild(type);
    }

    target.appendChild(bar);
  }

  function renderPagination(target, cfg, state, pagination, reload) {
    target.innerHTML = '';
    if (cfg.pagination.style === 'none' || !pagination || (pagination.total_pages || 1) <= 1) {
      return;
    }
    var nav = el('nav', 'agend-jobs-pagination');
    nav.setAttribute('aria-label', 'Job pages');
    if (cfg.pagination.style === 'load_more') {
      if (pagination.has_next) {
        var more = el('button', 'agend-jobs-button agend-jobs-button--ghost', 'Load more jobs');
        more.type = 'button';
        more.addEventListener('click', function () {
          state.page = (pagination.page || state.page) + 1;
          state.append = true;
          reload();
        });
        nav.appendChild(more);
      }
    } else {
      for (var i = 1; i <= pagination.total_pages; i++) {
        (function (pageNum) {
          var btn = el('button', 'agend-jobs-page' + (pageNum === pagination.page ? ' is-active' : ''), pageNum);
          btn.type = 'button';
          if (pageNum === pagination.page) {
            btn.setAttribute('aria-current', 'page');
          }
          btn.addEventListener('click', function () {
            state.page = pageNum;
            reload();
          });
          nav.appendChild(btn);
        })(i);
      }
    }
    target.appendChild(nav);
  }

  // -- Orchestration -----------------------------------------------------------

  // bindsUrl: only the first board in document order reads and writes the
  // detail deep link in window.location; later boards keep state in memory.
  function initWidget(root, bindsUrl) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-jobs-config'));
    } catch (e) {
      return;
    }

    var state = { search: '', location: '', category: '', type: '', page: 1, append: false };
    var pageTitle = document.title;

    applySiteTheme(root, cfg);

    var board = el('div', 'agend-jobs-board');
    if (cfg.heading && cfg.heading.show && (cfg.heading.title || cfg.heading.subtitle)) {
      var head = el('div', 'agend-jobs-heading');
      if (cfg.heading.title) {
        head.appendChild(el('h2', 'agend-jobs-heading__title', cfg.heading.title));
      }
      if (cfg.heading.subtitle) {
        head.appendChild(el('p', 'agend-jobs-heading__subtitle', cfg.heading.subtitle));
      }
      board.appendChild(head);
    }
    buildFilterBar(board, cfg, state, reloadBoard);
    var status = el('div', 'agend-jobs-status');
    status.setAttribute('role', 'status');
    status.style.display = 'none';
    var count = el('p', 'agend-jobs-count');
    count.setAttribute('aria-live', 'polite');
    var grid = el('div', 'agend-jobs-grid');
    var pager = el('div', 'agend-jobs-pager');
    board.appendChild(count);
    board.appendChild(status);
    board.appendChild(grid);
    board.appendChild(pager);

    function withSlash(path) {
      return path.charAt(path.length - 1) === '/' ? path : path + '/';
    }

    // The canonical detail URL for a slug: the dedicated Jobs page first, then
    // the page hosting this board, then the legacy query param.
    function detailUrl(slug) {
      var base = cfg.detailBase || (cfg.prettyLinks ? cfg.basePath : '');
      if (base && cfg.prettyLinks) {
        return withSlash(base) + 'job/' + encodeURIComponent(slug) + '/';
      }
      var u = new URL(cfg.detailBase || window.location.href, window.location.href);
      u.searchParams.set(DEEP_LINK_PARAM, slug);
      return u.toString();
    }

    function boardUrl() {
      if (cfg.prettyLinks && cfg.basePath) {
        return cfg.basePath;
      }
      var u = new URL(window.location.href);
      u.searchParams.delete(DEEP_LINK_PARAM);
      return u.toString();
    }

    function setUrl(slug) {
      if (!bindsUrl) {
        return;
      }
      try {
        window.history.pushState({ agendJob: slug || null }, '', slug ? detailUrl(slug) : boardUrl());
      } catch (e) {}
    }

    // On the board's own page a job opens in place; elsewhere (a featured
    // strip on the home page) the link navigates to the Jobs page.
    var openInPlace = cfg.onDetailPage ? function (slug) { showDetail(slug, true); } : null;

    function showBoard(updateUrl) {
      root.innerHTML = '';
      root.appendChild(board);
      if (bindsUrl) {
        document.title = pageTitle;
      }
      if (updateUrl) {
        setUrl(null);
      }
    }

    function showDetail(slug, updateUrl) {
      root.innerHTML = '';
      root.appendChild(renderDetailSkeleton());
      if (updateUrl) {
        setUrl(slug);
      }
      if (root.scrollIntoView && updateUrl) {
        var top = root.getBoundingClientRect().top;
        if (top < 0) {
          root.scrollIntoView({ block: 'start' });
        }
      }
      apiGet('/jobs/' + encodeURIComponent(slug), {}).then(function (body) {
        var job = unwrapOne(body);
        root.innerHTML = '';
        if (!job || !job.slug) {
          root.appendChild(renderNotFound(function () { showBoard(true); }));
          return;
        }
        root.appendChild(renderDetail(job, cfg, function () { showBoard(true); }));
        if (bindsUrl && job.title) {
          document.title = job.title + (job.company_name ? ' at ' + job.company_name : '') + ' | ' + pageTitle;
        }
      }).catch(function () {
        root.innerHTML = '';
        root.appendChild(renderNotFound(function () { showBoard(true); }));
      });
    }

    // Each reload supersedes the last: a slow response to an earlier query
    // (a visitor still typing) must not paint over a newer one.
    var requestSeq = 0;

    function reloadBoard() {
      var seq = ++requestSeq;
      status.style.display = 'none';
      if (!state.append) {
        grid.innerHTML = '';
        appendSkeletons(grid, cfg);
      }
      var params = {
        page: state.page,
        limit: cfg.pagination.perPage,
        sortBy: cfg.listing.sortBy,
        sortOrder: cfg.listing.sortOrder,
        featuredOnly: cfg.listing.featuredOnly ? 'true' : '',
        category: cfg.listing.category || state.category,
        type: state.type,
        search: state.search.length >= 2 ? state.search : '',
        location: state.location.length >= 2 ? state.location : '',
      };
      apiGet('/jobs', params).then(function (body) {
        if (seq !== requestSeq) {
          return;
        }
        if (body && body.success === false) {
          throw new Error('jobs');
        }
        var result = unwrapList(body);
        if (!state.append) {
          grid.innerHTML = '';
        }
        state.append = false;
        Array.prototype.forEach.call(grid.querySelectorAll('.agend-jobs-skeleton'), function (node) {
          node.parentNode.removeChild(node);
        });
        // A result count answers a search; a strip with no filters (featured
        // jobs on the home page) has nothing to count against.
        var f = cfg.filters;
        var searchable = f.search || f.category || f.type || f.location;
        var total = result.pagination ? result.pagination.total : result.items.length;
        count.textContent = searchable && total ? total + (total === 1 ? ' job' : ' jobs') : '';
        if (!result.items.length && !grid.childNodes.length) {
          status.style.display = '';
          status.textContent = cfg.listing.featuredOnly ? 'There are no featured jobs right now.' : 'No jobs match your search.';
          renderPagination(pager, cfg, state, null, reloadBoard);
          return;
        }
        result.items.forEach(function (job) {
          grid.appendChild(renderCard(job, cfg, detailUrl(job.slug), openInPlace));
        });
        renderPagination(pager, cfg, state, result.pagination, reloadBoard);
      }).catch(function () {
        if (seq !== requestSeq) {
          return;
        }
        state.append = false;
        grid.innerHTML = '';
        count.textContent = '';
        status.style.display = '';
        status.textContent = 'Jobs could not be loaded. Please try again shortly.';
      });
    }

    function currentDeepLink() {
      try {
        if (cfg.prettyLinks) {
          var m = window.location.pathname.match(/\/job\/([^/]+)\/?$/);
          if (m && m[1]) {
            return decodeURIComponent(m[1]);
          }
        }
        return new URL(window.location.href).searchParams.get(DEEP_LINK_PARAM);
      } catch (e) {
        return null;
      }
    }

    if (bindsUrl && cfg.onDetailPage) {
      window.addEventListener('popstate', function () {
        var slug = currentDeepLink();
        if (slug) {
          showDetail(slug, false);
        } else {
          showBoard(false);
        }
      });
    }

    var deepLinkSlug = cfg.deepLink || (bindsUrl && cfg.onDetailPage && currentDeepLink());
    if (deepLinkSlug) {
      showDetail(deepLinkSlug, false);
    } else {
      showBoard(false);
    }
    reloadBoard();
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-jobs-catalogue[data-agend-jobs-config]');
    Array.prototype.forEach.call(nodes, function (root, index) {
      if (root.getAttribute('data-agend-jobs-ready')) {
        return;
      }
      root.setAttribute('data-agend-jobs-ready', '1');
      initWidget(root, index === 0);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  // Elementor's editor canvas re-renders a widget without a page load, and
  // announces its frontend through a jQuery event rather than a DOM one.
  if (window.jQuery) {
    window.jQuery(window).on('elementor/frontend/init', function () {
      if (window.elementorFrontend && window.elementorFrontend.hooks) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/agend-jobs-catalogue.default', initAll);
      }
    });
  }
})();
