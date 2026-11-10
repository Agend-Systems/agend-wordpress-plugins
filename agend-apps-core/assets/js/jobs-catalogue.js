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

  var EXPERIENCE_LABELS = {
    entry: 'Entry level',
    mid: 'Mid level',
    senior: 'Senior',
    executive: 'Executive',
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
      if (Array.isArray(value)) {
        // A list filter: WordPress splits a comma list for an array argument.
        value = value.join(',');
      }
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

  // How long ago, without the "Posted" the card's own column heading would
  // repeat: "3 days ago", "Today"; an older job keeps its date.
  function postedAge(value) {
    var label = postedLabel(value);
    var relative = /^Posted (today|yesterday|\d+ \w+ ago)$/.exec(label);
    if (!relative) {
      return label;
    }
    var text = relative[1];
    return text.charAt(0).toUpperCase() + text.slice(1);
  }

  // Gateway values are entry, mid, senior or executive; anything else (an
  // older free-text value) is shown as written.
  function experienceLabel(value) {
    if (!value) {
      return '';
    }
    return EXPERIENCE_LABELS[value] || String(value);
  }

  function isClosed(job) {
    if (!job.expires_at) {
      return false;
    }
    var time = new Date(job.expires_at).getTime();
    return !isNaN(time) && time < Date.now();
  }

  // "City, STATE 1234, Country": the postcode sits with the state, as an
  // Australian address is written. Any part the listing lacks is left out.
  function locationLabel(job) {
    var region = [job.location_state, job.location_postcode]
      .filter(function (part) { return part; })
      .join(' ');
    return [job.location_city, region, job.location_country]
      .filter(function (part) { return part; })
      .join(', ');
  }

  // "12.3 km away", present only when the search carried a point; the
  // gateway sends null otherwise.
  function distanceLabel(job) {
    var km = job.distance_km;
    if (typeof km !== 'number' || !isFinite(km) || km < 0) {
      return '';
    }
    return km.toFixed(1) + ' km away';
  }

  // A tel: link needs digits; anything else a poster typed is shown as text.
  function telHref(phone) {
    var number = String(phone || '').replace(/[^\d+]/g, '');
    return number.replace(/\D/g, '').length >= 3 ? 'tel:' + number : '';
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
    // A visitor who may see the list but not the details goes to sign in,
    // and comes back to this job afterwards.
    if (cfg.access && !cfg.access.canDetail) {
      href = signInUrl(cfg, href);
      onOpen = null;
    }
    var card = el('article', 'agend-jobs-card' + (job.is_featured ? ' is-featured' : ''));
    var c = cfg.card;
    var sidebar = !!(cfg.filters && cfg.filters.layout === 'sidebar');

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

    // The sidebar layout's closing date and posted age, a column on the right
    // that drops under the title in a narrow container.
    if (sidebar) {
      var side = el('div', 'agend-jobs-card__side');
      if (c.closingDate && job.expires_at && formatDate(job.expires_at)) {
        side.appendChild(el('span', 'agend-jobs-card__closing', (isClosed(job) ? 'Closed ' : 'Closes ') + formatDate(job.expires_at)));
      }
      var age = c.postedDate ? postedAge(job.published_at || job.created_at) : '';
      if (age) {
        side.appendChild(el('span', 'agend-jobs-card__age', age));
      }
      if (side.childNodes.length) {
        body.appendChild(side);
      }
    }

    if (c.company && job.company_name) {
      body.appendChild(el('p', 'agend-jobs-card__company', job.company_name));
    }

    var meta = el('div', 'agend-jobs-card__meta');
    if (c.location && locationLabel(job)) {
      meta.appendChild(el('span', 'agend-jobs-card__location', locationLabel(job)));
    }
    var salary = c.salary ? salaryLabel(job) : '';
    if (sidebar) {
      // Location, then the salary in bold, then how far away it is.
      if (salary) {
        meta.appendChild(el('span', 'agend-jobs-card__salary', salary));
      }
      if (distanceLabel(job)) {
        meta.appendChild(el('span', 'agend-jobs-card__distance', distanceLabel(job)));
      }
    } else {
      if (distanceLabel(job)) {
        meta.appendChild(el('span', 'agend-jobs-card__distance', distanceLabel(job)));
      }
      if (salary) {
        meta.appendChild(el('span', 'agend-jobs-card__salary', salary));
      }
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
    if (c.experience && experienceLabel(job.experience_level)) {
      pills.appendChild(pill(experienceLabel(job.experience_level)));
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
    if (!sidebar && c.postedDate && postedLabel(job.published_at || job.created_at)) {
      dates.push(postedLabel(job.published_at || job.created_at));
    }
    if (!sidebar && c.closingDate && job.expires_at && formatDate(job.expires_at)) {
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

  // The sign-in URL with the page to come back to swapped in for the
  // placeholder the server put in it.
  function signInUrl(cfg, returnUrl) {
    var access = cfg.access || {};
    var url = access.signInUrl || (window.agendApps && window.agendApps.loginUrl) || '/wp-login.php';
    if (access.placeholder && url.indexOf(access.placeholder) !== -1) {
      return url.split(access.placeholder).join(encodeURIComponent(returnUrl));
    }
    return url;
  }

  function renderSignIn(cfg, returnUrl, onBack) {
    var view = el('div', 'agend-jobs-detail agend-jobs-signin');
    if (onBack) {
      var back = el('a', 'agend-jobs-detail__back', '← All jobs');
      back.href = cfg.detailBase || cfg.basePath || '#';
      back.addEventListener('click', function (e) {
        e.preventDefault();
        onBack();
      });
      view.appendChild(back);
    }
    var signIn = cfg.signIn || {};
    view.appendChild(el('p', 'agend-jobs-signin__text', signIn.text || 'Sign in as a member to see the full job details.'));
    var button = el('a', 'agend-jobs-button', signIn.button || 'Sign in');
    button.href = signInUrl(cfg, returnUrl);
    view.appendChild(button);
    return view;
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

  // Who to ask about the role: a name and a phone number, each only when
  // the listing has one.
  function contactBlock(job) {
    var name = job.contact_name ? String(job.contact_name).trim() : '';
    var phone = job.contact_phone ? String(job.contact_phone).trim() : '';
    if (!name && !phone) {
      return null;
    }
    var box = el('div', 'agend-jobs-detail__contact');
    box.appendChild(el('h3', 'agend-jobs-detail__section-title', 'Contact'));
    if (name) {
      box.appendChild(el('p', 'agend-jobs-detail__contact-name', name));
    }
    if (phone) {
      var line = el('p', 'agend-jobs-detail__contact-phone');
      var href = telHref(phone);
      if (href) {
        var link = el('a', 'agend-jobs-detail__contact-link', phone);
        link.href = href;
        line.appendChild(link);
      } else {
        line.textContent = phone;
      }
      box.appendChild(line);
    }
    return box;
  }

  // One cell of the header layout's facts strip, or null when it is empty.
  function factCell(label, value) {
    if (!value) {
      return null;
    }
    var cell = el('div', 'agend-jobs-fact');
    cell.appendChild(el('dt', 'agend-jobs-fact__label', label));
    cell.appendChild(el('dd', 'agend-jobs-fact__value', value));
    return cell;
  }

  // "Full time, hybrid": the employment type with the work arrangement after
  // it, either alone when the other is missing.
  function jobTypeLabel(job) {
    var type = EMPLOYMENT_TYPE_LABELS[job.employment_type] || '';
    var arrangement = (WORK_ARRANGEMENT_LABELS[job.location_type] || '').toLowerCase();
    return [type, arrangement].filter(function (part) { return part; }).join(', ');
  }

  // The apply box of the header layout: the button, and "or email ..." when
  // the board offers email and the listing has an address.
  function applyBox(job, cfg) {
    var box = el('div', 'agend-jobs-box agend-jobs-box--apply');
    if (isClosed(job)) {
      box.appendChild(el('p', 'agend-jobs-detail__closed', 'Applications for this role have closed.'));
      return box;
    }
    var url = safeUrl(job.application_url);
    var email = cfg.detail.emailApply ? safeEmail(job.application_email) : '';
    if (!url && !email) {
      return null;
    }
    var button = el('a', 'agend-jobs-button', cfg.detail.applyLabel || 'Apply now');
    if (url) {
      button.href = url;
      button.target = '_blank';
      button.rel = 'noopener noreferrer';
    } else {
      button.href = 'mailto:' + email + '?subject=' + encodeURIComponent('Application: ' + (job.title || ''));
    }
    box.appendChild(button);
    if (email) {
      var alt = el('p', 'agend-jobs-box__alt', url ? 'or email ' : 'Apply by email to ');
      var mail = el('a', 'agend-jobs-box__alt-link', email);
      mail.href = 'mailto:' + email + '?subject=' + encodeURIComponent('Application: ' + (job.title || ''));
      alt.appendChild(mail);
      box.appendChild(alt);
    }
    return box;
  }

  // The job page's "header" layout: a rounded panel with the title, a facts
  // strip overlapping its bottom edge, then the description beside an apply
  // box, the contact and the employer.
  function renderDetailHeader(job, cfg, onBack) {
    var view = el('div', 'agend-jobs-detail agend-jobs-detail--header');

    var hero = el('header', 'agend-jobs-hero');
    var back = el('a', 'agend-jobs-hero__back', '‹ All jobs');
    back.href = cfg.detailBase || cfg.basePath || '#';
    back.addEventListener('click', function (e) {
      if (onBack) {
        e.preventDefault();
        onBack();
      }
    });
    hero.appendChild(back);
    if (job.is_featured) {
      hero.appendChild(el('span', 'agend-jobs-hero__flag', 'Featured'));
    }
    hero.appendChild(el('h2', 'agend-jobs-hero__title', job.title));
    if (job.company_name) {
      hero.appendChild(el('p', 'agend-jobs-hero__company', job.company_name));
    }
    var posted = formatDate(job.published_at || job.created_at);
    var reference = job.reference_number ? 'Reference ' + String(job.reference_number).trim() : '';
    var line = [reference, posted ? 'Posted ' + posted : ''].filter(function (part) { return part; }).join(' · ');
    if (line) {
      hero.appendChild(el('p', 'agend-jobs-hero__meta', line));
    }
    view.appendChild(hero);

    var facts = el('dl', 'agend-jobs-facts');
    [
      factCell('Location', locationLabel(job)),
      factCell('Salary', salaryLabel(job)),
      factCell('Job type', jobTypeLabel(job)),
      factCell(isClosed(job) ? 'Closed' : 'Closes', formatDate(job.expires_at)),
    ].forEach(function (cell) {
      if (cell) {
        facts.appendChild(cell);
      }
    });
    if (facts.childNodes.length) {
      view.appendChild(facts);
    } else {
      view.classList.add('agend-jobs-detail--no-facts');
    }

    var layout = el('div', 'agend-jobs-detail__layout');
    var main = el('div', 'agend-jobs-detail__main');
    var side = el('aside', 'agend-jobs-detail__side');

    var pills = el('div', 'agend-jobs-card__pills');
    if (experienceLabel(job.experience_level)) {
      pills.appendChild(pill(experienceLabel(job.experience_level)));
    }
    (job.categories || []).forEach(function (category) {
      if (category && category.name) {
        pills.appendChild(pill(category.name, 'category'));
      }
    });
    (job.tags || []).forEach(function (tag) {
      if (tag && tag.name) {
        pills.appendChild(pill(tag.name, 'tag'));
      }
    });
    if (pills.childNodes.length) {
      main.appendChild(pills);
    }

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

    var apply = applyBox(job, cfg);
    if (apply) {
      side.appendChild(apply);
    }

    var contactName = job.contact_name ? String(job.contact_name).trim() : '';
    var contactPhone = job.contact_phone ? String(job.contact_phone).trim() : '';
    if (contactName || contactPhone) {
      var contact = el('div', 'agend-jobs-box agend-jobs-box--contact');
      contact.appendChild(el('h3', 'agend-jobs-box__title', 'Questions about the role'));
      if (contactName) {
        contact.appendChild(el('p', 'agend-jobs-box__name', contactName));
      }
      if (contactPhone) {
        var phoneLine = el('p', 'agend-jobs-box__phone');
        var tel = telHref(contactPhone);
        if (tel) {
          var telLink = el('a', 'agend-jobs-box__link', contactPhone);
          telLink.href = tel;
          phoneLine.appendChild(telLink);
        } else {
          phoneLine.textContent = contactPhone;
        }
        contact.appendChild(phoneLine);
      }
      side.appendChild(contact);
    }

    var website = safeUrl(job.company_website);
    if (cfg.detail.companyPanel && (job.company_description || website)) {
      var about = el('div', 'agend-jobs-box agend-jobs-box--employer');
      var aboutHead = el('div', 'agend-jobs-box__head');
      aboutHead.appendChild(logo(job, 'box'));
      aboutHead.appendChild(el('h3', 'agend-jobs-box__title', 'About ' + (job.company_name || 'the employer')));
      about.appendChild(aboutHead);
      if (job.company_description) {
        var aboutBody = el('div', 'agend-jobs-detail__about');
        setSafeHtml(aboutBody, job.company_description);
        about.appendChild(aboutBody);
      }
      if (website) {
        var site = el('a', 'agend-jobs-box__link', 'Visit website');
        site.href = website;
        site.target = '_blank';
        site.rel = 'noopener noreferrer';
        about.appendChild(site);
      }
      side.appendChild(about);
    }

    layout.appendChild(main);
    if (side.childNodes.length) {
      layout.appendChild(side);
    } else {
      layout.classList.add('agend-jobs-detail__layout--single');
    }
    view.appendChild(layout);
    return view;
  }

  function renderDetail(job, cfg, onBack) {
    if (cfg.detail && cfg.detail.layout === 'header') {
      return renderDetailHeader(job, cfg, onBack);
    }
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
    if (job.reference_number) {
      headText.appendChild(el('p', 'agend-jobs-detail__reference', 'Reference: ' + job.reference_number));
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
    detailRow(facts, 'Experience', experienceLabel(job.experience_level));
    detailRow(facts, 'Salary', salaryLabel(job));
    detailRow(facts, 'Posted', formatDate(job.published_at || job.created_at));
    detailRow(facts, isClosed(job) ? 'Closed' : 'Closes', formatDate(job.expires_at));
    summary.appendChild(facts);
    var contact = contactBlock(job);
    if (contact) {
      summary.appendChild(contact);
    }
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

  var POSTED_WITHIN_LABELS = [
    ['1', 'Last 24 hours'],
    ['3', 'Last 3 days'],
    ['7', 'Last 7 days'],
    ['14', 'Last 14 days'],
    ['30', 'Last 30 days'],
  ];

  function labelPairs(labels) {
    return Object.keys(labels).map(function (key) {
      return [key, labels[key]];
    });
  }

  // A slug as words, for a term whose name has not loaded.
  function slugLabel(slug) {
    var words = String(slug).replace(/[-_]+/g, ' ');
    return words.charAt(0).toUpperCase() + words.slice(1);
  }

  // The board's categories or tags as [slug, name] pairs, from the gateway's
  // term list. Until a gateway offers that list, categories fall back to the
  // distinct set on the published jobs.
  function loadTerms(kind) {
    return apiGet('/jobs/' + kind, {}).then(function (body) {
      if (!body || !Array.isArray(body.data)) {
        throw new Error(kind);
      }
      return body.data
        .filter(function (term) {
          return term && term.slug;
        })
        .map(function (term) {
          return [term.slug, term.name || term.slug];
        });
    }).catch(function () {
      if (kind !== 'categories') {
        return [];
      }
      return apiGet('/jobs', { limit: 100 }).then(function (body) {
        var seen = {};
        unwrapList(body).items.forEach(function (job) {
          (job.categories || []).forEach(function (cat) {
            if (cat && cat.slug && !seen[cat.slug]) {
              seen[cat.slug] = cat.name || cat.slug;
            }
          });
        });
        return Object.keys(seen)
          .sort(function (a, b) { return seen[a].localeCompare(seen[b]); })
          .map(function (slug) { return [slug, seen[slug]]; });
      }).catch(function () {
        return [];
      });
    });
  }

  var choiceSeq = 0;

  // One filter whose value is a list. A dropdown sets a list of at most one;
  // with `multiple`, a checkbox list in a disclosure sets any number, and a
  // job matching any ticked option shows.
  function choiceFilter(opts) {
    var onChange = opts.onChange;
    var selected = opts.selected || [];

    if (!opts.multiple) {
      var select = el('select', 'agend-jobs-select');
      select.setAttribute('aria-label', opts.label);
      function fillSelect(pairs) {
        select.innerHTML = '';
        select.appendChild(new Option(opts.allLabel, ''));
        pairs.forEach(function (pair) {
          select.appendChild(new Option(pair[1], pair[0], false, selected.indexOf(pair[0]) !== -1));
        });
      }
      fillSelect(opts.options || []);
      select.addEventListener('change', function () {
        selected = select.value ? [select.value] : [];
        onChange(selected);
      });
      return { node: select, fill: fillSelect };
    }

    var box = el('details', 'agend-jobs-choices');
    var summary = el('summary', 'agend-jobs-choices__summary');
    var list = el('div', 'agend-jobs-choices__list');
    list.setAttribute('role', 'group');
    list.setAttribute('aria-label', opts.label);
    box.appendChild(summary);
    box.appendChild(list);

    function updateSummary() {
      summary.textContent = selected.length ? opts.label + ' (' + selected.length + ')' : opts.allLabel;
    }

    function fillList(pairs) {
      list.innerHTML = '';
      pairs.forEach(function (pair) {
        var id = 'agend-jobs-choice-' + (++choiceSeq);
        var row = el('label', 'agend-jobs-choices__option');
        row.setAttribute('for', id);
        var input = el('input');
        input.type = 'checkbox';
        input.id = id;
        input.value = pair[0];
        input.checked = selected.indexOf(pair[0]) !== -1;
        input.addEventListener('change', function () {
          selected = Array.prototype.map.call(
            list.querySelectorAll('input:checked'),
            function (node) { return node.value; }
          );
          updateSummary();
          onChange(selected);
        });
        row.appendChild(input);
        row.appendChild(document.createTextNode(' ' + pair[1]));
        list.appendChild(row);
      });
      updateSummary();
    }
    fillList(opts.options || []);

    // One list open at a time, and a click outside closes it.
    box.addEventListener('toggle', function () {
      if (!box.open) {
        return;
      }
      Array.prototype.forEach.call(document.querySelectorAll('.agend-jobs-choices[open]'), function (other) {
        if (other !== box) {
          other.open = false;
        }
      });
    });
    document.addEventListener('click', function (event) {
      if (box.open && !box.contains(event.target)) {
        box.open = false;
      }
    });

    return { node: box, fill: fillList };
  }

  // Wording matches the directory's Location filter (assets/js/filters.js),
  // so a visitor meets the same messages on either.
  var GEO_MESSAGES = {
    empty: 'Enter a suburb, postcode or address.',
    notFound: 'We could not find that place. Try a suburb or postcode.',
    busy: 'Place search is busy. Please try again in a moment.',
    failed: 'Place search failed. Please try again in a moment.',
    unsupported: 'Your browser does not support finding your location.',
    denied: 'Location permission was denied. Update your browser settings to use this feature.',
    timeout: 'Could not get your location. Please try again.',
    unavailable: 'Your location is unavailable. Check that Location Services are turned on, then try again.',
    degraded: 'Distance search is not available right now, so every job is shown.',
  };

  // Nominatim, behind the gateway, allows about one lookup a second; the
  // directory's filter spaces lookups the same way.
  var GEOCODE_MIN_INTERVAL_MS = 1500;

  // The directory's map defaults, fetched once per page and shared with the
  // directory's Location filter and map (they read window.agendDirectory),
  // so they say whether the account's place search is on.
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

  var PLACE_QUERY_MAX = 200;

  // A typed place with the board's region added, so a bare postcode such as
  // "5000" is looked up in that country rather than wherever the geocoder's
  // first match happens to be. Text that already names the region as a whole
  // word is left as typed: "Adelaide, Australia" stays as it is, but a region
  // of "US" is still added to "Houston". The visitor's text is cut to leave
  // room for the region, so the 200 character limit never clips it. The chip
  // and messages keep the visitor's own words.
  function placeQuery(text, region) {
    text = String(text || '');
    region = String(region || '').trim();
    if (!region) {
      return text.slice(0, PLACE_QUERY_MAX);
    }
    var escaped = region.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var named = new RegExp('(^|[^\\p{L}\\p{N}])' + escaped + '(?![\\p{L}\\p{N}])', 'iu');
    if (named.test(text)) {
      return text.slice(0, PLACE_QUERY_MAX);
    }
    var suffix = ', ' + region;
    return text.slice(0, Math.max(0, PLACE_QUERY_MAX - suffix.length)) + suffix;
  }

  // Whether a distance search came back unmeasured: a gateway that does not
  // know the distance parameters ignores them and returns jobs with no
  // distance. Remote jobs never carry one, so when they were asked for they
  // are left out of the test; when they were not, every job counts, as before.
  function isUnmeasured(items, remoteAsked) {
    var located = remoteAsked ? items.filter(function (job) {
      return job.location_type !== 'remote';
    }) : items;
    return located.length > 0 && located.every(function (job) {
      return typeof job.distance_km !== 'number';
    });
  }

  // Exposed for the script's own tests; not an API.
  window.agendJobsBoard = { placeQuery: placeQuery, isUnmeasured: isUnmeasured };

  function radiusText(km) {
    return 'Within ' + km + ' km';
  }

  // The "near a place" control: a typed suburb or postcode (resolved to a
  // point by the directory's geocode route), or the device's position, plus a
  // radius. Sets state.near to {lat, lng, radius, label} or null. Returns the
  // node and a `say` function for messages.
  // `changed` is called when the location itself changes (the layouts also
  // move the sort to Nearest then); `filterChanged`, when only a qualifier of
  // it does, such as "Exclude remote jobs", which must leave the sort alone.
  function buildNearControl(cfg, state, changed, panel, filterChanged) {
    var f = cfg.filters;
    var radii = Array.isArray(f.distanceRadii) && f.distanceRadii.length ? f.distanceRadii : [50];
    var wrap = el('div', 'agend-jobs-near');
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Search near a place');

    var row = el('div', 'agend-jobs-near__row');
    var searchRow = el('div', 'agend-jobs-near__search');
    var input = el('input', 'agend-jobs-input agend-jobs-input--near');
    input.type = 'search';
    input.placeholder = 'Suburb or postcode';
    input.setAttribute('aria-label', 'Suburb or postcode to search near');
    input.maxLength = 200;
    // In the sidebar layout's search panel the board's one Search button sits
    // beside the fields, so the place box has none of its own.
    var searchBtn = null;
    if (!panel) {
      searchBtn = el('button', 'agend-jobs-button agend-jobs-button--ghost', 'Search');
      searchBtn.type = 'button';
    }
    searchRow.appendChild(input);
    if (searchBtn) {
      searchRow.appendChild(searchBtn);
    }
    var locateBtn = el('button', panel ? 'agend-jobs-sb-link agend-jobs-near__locate' : 'agend-jobs-button agend-jobs-button--ghost agend-jobs-near__locate', 'Use my location');
    locateBtn.type = 'button';

    var radiusSel = null;
    if (radii.length > 1) {
      radiusSel = el('select', 'agend-jobs-select agend-jobs-near__radius');
      radiusSel.setAttribute('aria-label', 'Distance');
      radii.forEach(function (km) {
        radiusSel.appendChild(new Option(radiusText(km), String(km), false, km === f.distanceRadius));
      });
    }

    row.appendChild(searchRow);
    row.appendChild(locateBtn);
    if (radiusSel) {
      row.appendChild(radiusSel);
    }
    wrap.appendChild(row);

    var status = el('p', 'agend-jobs-near__status');
    status.setAttribute('role', 'status');
    status.hidden = true;
    wrap.appendChild(status);

    // With remote jobs added to a distance search, the visitor can leave them
    // out. Shown only while a location is set, since only then are they added.
    var remoteWrap = null;
    var remoteBox = null;
    if (f.distanceRemote) {
      remoteWrap = el('label', 'agend-jobs-near__remote');
      remoteBox = el('input', 'agend-jobs-near__remote-box');
      remoteBox.type = 'checkbox';
      remoteBox.checked = !!state.excludeRemote;
      remoteWrap.appendChild(remoteBox);
      remoteWrap.appendChild(el('span', 'agend-jobs-near__remote-text', 'Exclude remote jobs'));
      remoteWrap.hidden = true;
      remoteBox.addEventListener('change', function () {
        state.excludeRemote = remoteBox.checked;
        if (state.near) {
          (filterChanged || changed)();
        }
      });
      wrap.appendChild(remoteWrap);
    }

    var chip = el('p', 'agend-jobs-near__chip');
    chip.hidden = true;
    var chipText = el('span', 'agend-jobs-near__chip-text');
    var clearBtn = el('button', 'agend-jobs-near__clear', 'Clear');
    clearBtn.type = 'button';
    chip.appendChild(chipText);
    chip.appendChild(clearBtn);
    if (!panel) {
      wrap.appendChild(chip);
    }

    var lastLookup = 0;

    function currentRadius() {
      var value = radiusSel ? Number(radiusSel.value) : Number(f.distanceRadius);
      return isFinite(value) && value > 0 ? value : radii[0];
    }

    function say(message) {
      status.textContent = message || '';
      status.hidden = !message;
    }

    function paintChip() {
      if (remoteWrap) {
        remoteWrap.hidden = !state.near;
      }
      if (!state.near) {
        chip.hidden = true;
        chipText.textContent = '';
        return;
      }
      chipText.textContent = radiusText(state.near.radius) + ' of ' + state.near.label;
      chip.hidden = false;
    }

    function busy(on) {
      [input, searchBtn, locateBtn].forEach(function (node) {
        if (node) {
          node.disabled = on;
        }
      });
      wrap.setAttribute('aria-busy', on ? 'true' : 'false');
    }

    function apply(lat, lng, label) {
      state.near = { lat: lat, lng: lng, radius: currentRadius(), label: label };
      say('');
      paintChip();
      changed();
    }

    function lookup() {
      var text = input.value.trim();
      if (!text) {
        say(GEO_MESSAGES.empty);
        return;
      }
      var now = Date.now();
      if (now - lastLookup < GEOCODE_MIN_INTERVAL_MS) {
        return;
      }
      lastLookup = now;
      busy(true);
      apiGet('/directory/geocode', { q: placeQuery(text, cfg.filters.placeCountry) })
        .then(function (body) {
          busy(false);
          var point = body && body.data;
          if (point && isFinite(Number(point.latitude)) && isFinite(Number(point.longitude))) {
            apply(Number(point.latitude), Number(point.longitude), text);
            return;
          }
          var code = body && body.data && body.data.status_code;
          if (code === 404) {
            say(GEO_MESSAGES.notFound);
          } else if (code === 403) {
            // The account does not include place search after all; the
            // button still works.
            searchRow.hidden = true;
            say('');
          } else if (code === 429 || code === 503) {
            say(GEO_MESSAGES.busy);
          } else {
            say(GEO_MESSAGES.failed);
          }
        })
        .catch(function () {
          busy(false);
          say(GEO_MESSAGES.failed);
        });
    }

    function locate() {
      if (!navigator.geolocation) {
        say(GEO_MESSAGES.unsupported);
        return;
      }
      busy(true);
      var onSuccess = function (position) {
        busy(false);
        input.value = '';
        apply(position.coords.latitude, position.coords.longitude, 'your location');
      };
      var onError = function (error, retried) {
        // A quick, low-accuracy fix fails on some desktops; one precise
        // attempt is worth making before giving up.
        if (error && error.code === 2 && !retried) {
          navigator.geolocation.getCurrentPosition(onSuccess, function (again) {
            onError(again, true);
          }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
          return;
        }
        busy(false);
        if (error && error.code === 1) {
          say(GEO_MESSAGES.denied);
        } else if (error && error.code === 3) {
          say(GEO_MESSAGES.timeout);
        } else {
          say(GEO_MESSAGES.unavailable);
        }
      };
      navigator.geolocation.getCurrentPosition(onSuccess, function (error) {
        onError(error, false);
      }, { enableHighAccuracy: false, timeout: 10000, maximumAge: 60000 });
    }

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        lookup();
      }
    });
    if (searchBtn) {
      searchBtn.addEventListener('click', lookup);
    }
    locateBtn.addEventListener('click', locate);
    if (radiusSel) {
      radiusSel.addEventListener('change', function () {
        if (state.near) {
          state.near.radius = currentRadius();
          paintChip();
          changed();
        }
      });
    }
    function clear() {
      state.near = null;
      state.excludeRemote = false;
      if (remoteBox) {
        remoteBox.checked = false;
      }
      input.value = '';
      say('');
      paintChip();
    }
    clearBtn.addEventListener('click', function () {
      clear();
      changed();
    });

    // An account without place search cannot answer a typed place, so the
    // box is hidden rather than left to fail; "Use my location" remains.
    mapSettings().then(function (settings) {
      if (settings && settings.geocoding_available === false) {
        searchRow.hidden = true;
      }
    });

    return { node: wrap, say: say, input: input, lookup: lookup, clear: clear };
  }

  // The visitor's sort dropdown, or null when the board has none. "Nearest"
  // is offered only while a location is set: the gateway refuses it without
  // one. Setting a location opens on it, as the question just asked was "what
  // is close to me". `sync(true)` is called when the location changes.
  function buildSort(cfg, state, changed) {
    var f = cfg.filters;
    if (!f.sort || !Array.isArray(f.sortOptions) || !f.sortOptions.length) {
      return null;
    }
    var sortSel = el('select', 'agend-jobs-select agend-jobs-select--sort');
    sortSel.setAttribute('aria-label', 'Sort jobs');
    f.sortOptions.forEach(function (option) {
      sortSel.appendChild(new Option(option.label, option.value, false, option.value === state.sort));
    });
    sortSel.addEventListener('change', function () {
      state.sort = sortSel.value;
      changed();
    });

    function sync(locationChanged) {
      var hasNearest = !!sortSel.querySelector('option[value="distance:asc"]');
      if (state.near && !hasNearest) {
        sortSel.appendChild(new Option('Nearest', 'distance:asc'));
      } else if (!state.near && hasNearest) {
        sortSel.removeChild(sortSel.querySelector('option[value="distance:asc"]'));
      }
      if (locationChanged) {
        if (state.near) {
          state.sort = 'distance:asc';
        } else if (state.sort === 'distance:asc') {
          state.sort = cfg.listing.sort;
        }
      }
      sortSel.value = state.sort;
    }

    return { node: sortSel, sync: sync };
  }

  function buildFilterBar(target, cfg, state, reload) {
    var f = cfg.filters;
    var tagFilters = Array.isArray(f.tags) ? f.tags : [];
    if (!f.search && !f.category && !f.type && !f.experience && !f.location && !f.workArrangement && !f.state && !f.posted && !tagFilters.length && !f.distance && !f.sort) {
      return;
    }
    var bar = el('div', 'agend-jobs-filterbar');
    bar.setAttribute('role', 'search');

    function changed() {
      state.page = 1;
      // A filter changed while "Load more" was still loading starts a fresh
      // list; without this the new first page was added after the old cards.
      state.append = false;
      reload();
    }

    function debounced(input, key) {
      var timer;
      input.addEventListener('input', function () {
        window.clearTimeout(timer);
        timer = window.setTimeout(function () {
          state[key] = input.value.trim();
          changed();
        }, 300);
      });
    }

    function listFilter(key, label, allLabel, options) {
      var filter = choiceFilter({
        label: label,
        allLabel: allLabel,
        options: options,
        multiple: !!f.multiple,
        selected: state[key],
        onChange: function (values) {
          state[key] = values;
          changed();
        },
      });
      bar.appendChild(filter.node);
      return filter;
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

    var sorter = null;

    if (f.distance) {
      var near = buildNearControl(cfg, state, function () {
        if (sorter) {
          sorter.sync(true);
        }
        changed();
      }, false, changed);
      state.say = near.say;
      bar.appendChild(near.node);
    }

    if (f.state && Array.isArray(f.states) && f.states.length) {
      var stateSelect = el('select', 'agend-jobs-select');
      stateSelect.setAttribute('aria-label', 'State');
      stateSelect.appendChild(new Option('All states', ''));
      f.states.forEach(function (name) {
        stateSelect.appendChild(new Option(name, name, false, name === state.state));
      });
      stateSelect.addEventListener('change', function () {
        state.state = stateSelect.value;
        changed();
      });
      bar.appendChild(stateSelect);
    }

    if (f.category) {
      var category = listFilter('category', 'Category', 'All categories', []);
      loadTerms('categories').then(category.fill);
    }

    if (f.type) {
      listFilter('type', 'Job type', 'All job types', labelPairs(EMPLOYMENT_TYPE_LABELS));
    }

    if (f.workArrangement) {
      listFilter('workArrangement', 'Work arrangement', 'All work arrangements', labelPairs(WORK_ARRANGEMENT_LABELS));
    }

    if (f.experience) {
      listFilter('experience', 'Experience level', 'All experience levels', labelPairs(EXPERIENCE_LABELS));
    }

    if (f.posted) {
      var posted = el('select', 'agend-jobs-select');
      posted.setAttribute('aria-label', 'Date posted');
      posted.appendChild(new Option('Any time', ''));
      POSTED_WITHIN_LABELS.forEach(function (pair) {
        posted.appendChild(new Option(pair[1], pair[0], false, pair[0] === state.postedWithin));
      });
      posted.addEventListener('change', function () {
        state.postedWithin = posted.value;
        changed();
      });
      bar.appendChild(posted);
    }

    // Each tag filter sets its own tags; the gateway keeps a job only when it
    // carries every tag set across all of them.
    if (tagFilters.length) {
      var fills = [];
      tagFilters.forEach(function (row, index) {
        var pairs = row.tags.map(function (slug) { return [slug, slugLabel(slug)]; });
        state.tagChoices[index] = state.tagChoices[index] || [];

        if (row.style === 'checkboxes') {
          // A labelled group rather than a fieldset: themes restyle legends
          // onto their own line, which breaks the filter row.
          var group = el('div', 'agend-jobs-tagchecks');
          var legendId = 'agend-jobs-choice-' + (++choiceSeq);
          var legend = el('span', 'agend-jobs-tagchecks__legend', row.label);
          legend.id = legendId;
          group.setAttribute('role', 'group');
          group.setAttribute('aria-labelledby', legendId);
          group.appendChild(legend);
          var inner = el('div', 'agend-jobs-tagchecks__list');
          group.appendChild(inner);
          var fillChecks = function (labelled) {
            inner.innerHTML = '';
            labelled.forEach(function (pair) {
              var id = 'agend-jobs-choice-' + (++choiceSeq);
              var item = el('label', 'agend-jobs-tagchecks__option');
              item.setAttribute('for', id);
              var input = el('input');
              input.type = 'checkbox';
              input.id = id;
              input.value = pair[0];
              input.checked = state.tagChoices[index].indexOf(pair[0]) !== -1;
              input.addEventListener('change', function () {
                state.tagChoices[index] = Array.prototype.map.call(
                  inner.querySelectorAll('input:checked'),
                  function (node) { return node.value; }
                );
                changed();
              });
              item.appendChild(input);
              item.appendChild(document.createTextNode(' ' + pair[1]));
              inner.appendChild(item);
            });
          };
          fillChecks(pairs);
          bar.appendChild(group);
          fills.push({ tags: row.tags, fill: fillChecks });
          return;
        }

        var filter = choiceFilter({
          label: row.label,
          allLabel: row.label + ': any',
          options: pairs,
          multiple: false,
          selected: state.tagChoices[index],
          onChange: function (values) {
            state.tagChoices[index] = values;
            changed();
          },
        });
        bar.appendChild(filter.node);
        fills.push({ tags: row.tags, fill: filter.fill });
      });

      // Swap the slugs for the tags' own names once they load, keeping the
      // order the row lists them in.
      loadTerms('tags').then(function (terms) {
        var names = {};
        terms.forEach(function (pair) { names[pair[0]] = pair[1]; });
        fills.forEach(function (entry) {
          entry.fill(entry.tags.map(function (slug) { return [slug, names[slug] || slugLabel(slug)]; }));
        });
      });
    }

    sorter = buildSort(cfg, state, changed);
    if (sorter) {
      bar.appendChild(sorter.node);
    }

    target.appendChild(bar);
  }

  // -- Filter sidebar layout ---------------------------------------------------

  // How many options a group shows before "Show N more".
  var SIDEBAR_SHOW = 5;
  var sidebarSeq = 0;

  // Runs `onValue` with the input's trimmed text, 300ms after typing stops.
  function onTypingStops(input, onValue) {
    var timer;
    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        onValue(input.value.trim());
      }, 300);
    });
  }

  // One group of the sidebar: a small heading over tick boxes (any number of
  // values) or a radio list with an "Any" first (one value). `read` and
  // `write` map the group to the board's state; `onChange` runs after a
  // visitor's change. Long lists show their first few options, and always any
  // chosen option, until "Show N more" is used.
  function sidebarGroup(opts) {
    var uid = ++sidebarSeq;
    var node = el('div', 'agend-jobs-sb-group');
    node.setAttribute('role', 'group');
    var heading = el('h4', 'agend-jobs-sb-group__title', opts.title);
    heading.id = 'agend-jobs-sb-title-' + uid;
    node.setAttribute('aria-labelledby', heading.id);
    var list = el('div', 'agend-jobs-sb-group__list');
    var more = el('button', 'agend-jobs-sb-more');
    more.type = 'button';
    more.setAttribute('aria-expanded', 'false');
    more.hidden = true;
    node.appendChild(heading);
    node.appendChild(list);
    node.appendChild(more);

    var pairs = opts.pairs || [];
    var expanded = false;
    var radio = opts.kind === 'radio';

    function option(value, label, checked) {
      var id = 'agend-jobs-sb-opt-' + uid + '-' + (++sidebarSeq);
      var row = el('label', 'agend-jobs-sb-opt');
      row.setAttribute('for', id);
      var input = el('input');
      input.type = radio ? 'radio' : 'checkbox';
      input.id = id;
      input.value = value;
      if (radio) {
        input.name = 'agend-jobs-sb-' + uid;
      }
      input.checked = checked;
      input.addEventListener('change', function () {
        if (radio) {
          opts.write(input.value ? [input.value] : []);
        } else {
          opts.write(Array.prototype.map.call(
            list.querySelectorAll('input:checked'),
            function (box) { return box.value; }
          ));
        }
        opts.onChange();
      });
      row.appendChild(input);
      row.appendChild(el('span', 'agend-jobs-sb-opt__label', label));
      return row;
    }

    function render() {
      var chosen = opts.read();
      list.innerHTML = '';
      if (radio) {
        list.appendChild(option('', opts.anyLabel || 'Any', chosen.length === 0));
      }
      var collapsible = pairs.length > SIDEBAR_SHOW;
      var shown = 0;
      pairs.forEach(function (pair, index) {
        var picked = chosen.indexOf(pair[0]) !== -1;
        if (collapsible && !expanded && index >= SIDEBAR_SHOW && !picked) {
          return;
        }
        shown++;
        list.appendChild(option(pair[0], pair[1], picked));
      });
      more.hidden = !collapsible || (!expanded && shown === pairs.length);
      if (collapsible) {
        more.textContent = expanded ? 'Show fewer' : 'Show ' + (pairs.length - shown) + ' more';
        more.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      }
      // Nothing to choose from (categories still loading, or none).
      node.hidden = pairs.length === 0;
    }

    more.addEventListener('click', function () {
      expanded = !expanded;
      render();
      more.focus();
    });

    render();

    return {
      node: node,
      title: opts.title,
      read: opts.read,
      write: opts.write,
      pairs: function () { return pairs; },
      fill: function (next) {
        pairs = next;
        render();
      },
      sync: render,
    };
  }

  // Builds the sidebar layout into `board`: the search panel, then the filter
  // groups beside the results (count and sort, applied-filter chips, the
  // cards). `parts` are the board's count, status, grid and pager nodes.
  function buildSidebarBoard(board, cfg, state, reload, parts) {
    var f = cfg.filters;
    var tagFilters = Array.isArray(f.tags) ? f.tags : [];
    var groups = [];
    var sorter = null;
    var near = null;
    var chipsBox;
    var toggle;
    var aside;

    function changed() {
      state.page = 1;
      // See the bar layout's changed(): a fresh list, not an appended page.
      state.append = false;
      refresh();
      reload();
    }

    // -- Search panel: keyword, place and radius, one Search button.
    var searchInput = null;
    var placeInput = null;
    if (f.search || f.distance || f.location) {
      var panel = el('div', 'agend-jobs-sb-search');
      panel.setAttribute('role', 'search');

      if (f.search) {
        searchInput = el('input', 'agend-jobs-input agend-jobs-input--search');
        searchInput.type = 'search';
        searchInput.placeholder = 'Job title, keyword or employer';
        searchInput.setAttribute('aria-label', 'Search jobs');
        searchInput.value = state.search;
        onTypingStops(searchInput, function (value) {
          state.search = value;
          changed();
        });
        panel.appendChild(searchInput);
      }

      if (f.distance) {
        near = buildNearControl(cfg, state, function () {
          if (sorter) {
            sorter.sync(true);
          }
          changed();
        }, true, changed);
        state.say = near.say;
        panel.appendChild(near.node);
      } else if (f.location) {
        placeInput = el('input', 'agend-jobs-input agend-jobs-input--location');
        placeInput.type = 'search';
        placeInput.placeholder = 'Suburb, city or state';
        placeInput.setAttribute('aria-label', 'Location');
        placeInput.value = state.location;
        onTypingStops(placeInput, function (value) {
          state.location = value;
          changed();
        });
        panel.appendChild(placeInput);
      }

      var go = el('button', 'agend-jobs-button agend-jobs-sb-search__go', 'Search');
      go.type = 'button';
      var submit = function () {
        if (searchInput) {
          state.search = searchInput.value.trim();
        }
        if (placeInput) {
          state.location = placeInput.value.trim();
        }
        changed();
        // A place typed but not yet looked up is resolved now.
        if (near && near.input.value.trim() && (!state.near || state.near.label !== near.input.value.trim())) {
          near.lookup();
        }
      };
      go.addEventListener('click', submit);
      [searchInput, placeInput].forEach(function (input) {
        if (input) {
          input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
              e.preventDefault();
              submit();
            }
          });
        }
      });
      panel.appendChild(go);
      board.appendChild(panel);
    }

    // -- Filter groups.
    aside = el('aside', 'agend-jobs-sb-filters');
    aside.id = 'agend-jobs-sb-filters-' + (++sidebarSeq);
    aside.setAttribute('aria-label', 'Filters');

    function addGroup(title, kind, anyLabel, pairs, read, write) {
      var group = sidebarGroup({
        title: title,
        kind: kind,
        anyLabel: anyLabel,
        pairs: pairs,
        read: read,
        write: write,
        onChange: changed,
      });
      groups.push(group);
      aside.appendChild(group.node);
      return group;
    }

    function listGroup(key, title, pairs) {
      return addGroup(title, 'check', '', pairs, function () {
        return state[key];
      }, function (values) {
        state[key] = values;
      });
    }

    if (f.category) {
      var category = listGroup('category', 'Category', []);
      loadTerms('categories').then(category.fill);
    }
    if (f.type) {
      listGroup('type', 'Job type', labelPairs(EMPLOYMENT_TYPE_LABELS));
    }
    // The gateway takes one state, so the states are a radio list.
    if (f.state && Array.isArray(f.states) && f.states.length) {
      addGroup('State', 'radio', 'Any', f.states.map(function (name) { return [name, name]; }), function () {
        return state.state ? [state.state] : [];
      }, function (values) {
        state.state = values[0] || '';
      });
    }
    if (f.workArrangement) {
      listGroup('workArrangement', 'Work arrangement', labelPairs(WORK_ARRANGEMENT_LABELS));
    }
    if (f.experience) {
      listGroup('experience', 'Experience level', labelPairs(EXPERIENCE_LABELS));
    }

    var tagFills = [];
    tagFilters.forEach(function (row, index) {
      state.tagChoices[index] = state.tagChoices[index] || [];
      var pairs = row.tags.map(function (slug) { return [slug, slugLabel(slug)]; });
      var group = addGroup(row.label, row.style === 'checkboxes' ? 'check' : 'radio', 'Any', pairs, function () {
        return state.tagChoices[index];
      }, function (values) {
        state.tagChoices[index] = values;
      });
      tagFills.push({ tags: row.tags, fill: group.fill });
    });
    if (tagFills.length) {
      loadTerms('tags').then(function (terms) {
        var names = {};
        terms.forEach(function (pair) { names[pair[0]] = pair[1]; });
        tagFills.forEach(function (entry) {
          entry.fill(entry.tags.map(function (slug) { return [slug, names[slug] || slugLabel(slug)]; }));
        });
      });
    }

    if (f.posted) {
      addGroup('Date posted', 'radio', 'Any time', POSTED_WITHIN_LABELS, function () {
        return state.postedWithin ? [state.postedWithin] : [];
      }, function (values) {
        state.postedWithin = values[0] || '';
      });
    }

    // -- Results: count and sort, chips, then the cards.
    var results = el('div', 'agend-jobs-sb-results');
    var bar = el('div', 'agend-jobs-sb-bar');
    bar.appendChild(parts.count);
    var tools = el('div', 'agend-jobs-sb-tools');

    toggle = el('button', 'agend-jobs-button agend-jobs-button--ghost agend-jobs-sb-toggle', 'Filters');
    toggle.type = 'button';
    toggle.setAttribute('aria-expanded', 'false');
    toggle.setAttribute('aria-controls', aside.id);
    toggle.addEventListener('click', function () {
      var open = !aside.classList.contains('is-open');
      aside.classList.toggle('is-open', open);
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      // The panel sits before the results in the markup, so take keyboard
      // and screen reader users straight into it when it opens.
      if (open) {
        var first = aside.querySelector('input, button, select');
        if (first) {
          first.focus({ preventScroll: true });
        }
      }
    });
    tools.appendChild(toggle);

    sorter = buildSort(cfg, state, changed);
    if (sorter) {
      var sortWrap = el('label', 'agend-jobs-sb-sort');
      sortWrap.appendChild(el('span', 'agend-jobs-sb-sort__label', 'Sort'));
      sortWrap.appendChild(sorter.node);
      tools.appendChild(sortWrap);
    }
    bar.appendChild(tools);
    results.appendChild(bar);

    chipsBox = el('div', 'agend-jobs-sb-chips');
    chipsBox.hidden = true;
    results.appendChild(chipsBox);
    results.appendChild(parts.status);
    results.appendChild(parts.grid);
    results.appendChild(parts.pager);

    var layout = el('div', 'agend-jobs-sb-layout');
    if (groups.length) {
      layout.appendChild(aside);
    } else {
      layout.classList.add('agend-jobs-sb-layout--single');
      toggle.hidden = true;
    }
    layout.appendChild(results);
    board.appendChild(layout);

    // -- Applied filters as removable chips.
    function appliedChips() {
      var chips = [];
      groups.forEach(function (group) {
        var names = {};
        group.pairs().forEach(function (pair) { names[pair[0]] = pair[1]; });
        group.read().forEach(function (value) {
          chips.push({
            label: names[value] || slugLabel(value),
            remove: function () {
              group.write(group.read().filter(function (other) { return other !== value; }));
              group.sync();
            },
          });
        });
      });
      if (state.near && near) {
        chips.push({
          label: radiusText(state.near.radius) + ' of ' + state.near.label,
          remove: function () {
            near.clear();
            if (sorter) {
              sorter.sync(true);
            }
          },
        });
      }
      return chips;
    }

    function clearAll() {
      groups.forEach(function (group) {
        group.write([]);
        group.sync();
      });
      if (state.near && near) {
        near.clear();
        if (sorter) {
          sorter.sync(true);
        }
      }
      changed();
    }

    function refresh() {
      var chips = appliedChips();
      chipsBox.innerHTML = '';
      chips.forEach(function (chip, index) {
        var button = el('button', 'agend-jobs-sb-chip');
        button.type = 'button';
        button.setAttribute('aria-label', 'Remove filter: ' + chip.label);
        button.appendChild(el('span', 'agend-jobs-sb-chip__text', chip.label));
        var cross = el('span', 'agend-jobs-sb-chip__x', '×');
        cross.setAttribute('aria-hidden', 'true');
        button.appendChild(cross);
        button.addEventListener('click', function () {
          chip.remove();
          changed();
          // Keep keyboard focus in the row: the next chip, else Clear all's
          // place, else the control that opens the filters.
          var next = chipsBox.querySelectorAll('.agend-jobs-sb-chip')[Math.min(index, chips.length - 2)];
          if (next) {
            next.focus();
          } else if (toggle.offsetParent !== null) {
            toggle.focus();
          } else {
            var first = aside.querySelector('input');
            if (first) {
              first.focus();
            }
          }
        });
        chipsBox.appendChild(button);
      });
      if (chips.length) {
        var clear = el('button', 'agend-jobs-sb-clear', 'Clear all');
        clear.type = 'button';
        clear.addEventListener('click', function () {
          clearAll();
          if (toggle.offsetParent !== null) {
            toggle.focus();
          }
        });
        chipsBox.appendChild(clear);
      }
      chipsBox.hidden = chips.length === 0;
      toggle.textContent = chips.length ? 'Filters (' + chips.length + ')' : 'Filters';
    }

    refresh();
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

  // Every tag the visitor has set, across the tag filters, once each.
  function selectedTags(state) {
    var tags = [];
    Object.keys(state.tagChoices).forEach(function (index) {
      state.tagChoices[index].forEach(function (slug) {
        if (tags.indexOf(slug) === -1) {
          tags.push(slug);
        }
      });
    });
    return tags;
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

    var state = {
      search: '',
      location: '',
      state: '',
      postedWithin: '',
      category: [],
      type: [],
      workArrangement: [],
      experience: [],
      tagChoices: {},
      near: null,
      excludeRemote: false,
      sort: cfg.listing.sort || '',
      say: null,
      page: 1,
      append: false,
    };
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
    var sidebar = cfg.filters.layout === 'sidebar';
    if (!sidebar) {
      buildFilterBar(board, cfg, state, reloadBoard);
    }
    var status = el('div', 'agend-jobs-status');
    status.setAttribute('role', 'status');
    status.style.display = 'none';
    var count = el('p', 'agend-jobs-count');
    count.setAttribute('aria-live', 'polite');
    var grid = el('div', 'agend-jobs-grid');
    var pager = el('div', 'agend-jobs-pager');
    if (sidebar) {
      buildSidebarBoard(board, cfg, state, reloadBoard, { count: count, status: status, grid: grid, pager: pager });
    } else {
      board.appendChild(count);
      board.appendChild(status);
      board.appendChild(grid);
      board.appendChild(pager);
    }

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
      if (cfg.access && !cfg.access.canDetail) {
        root.appendChild(renderSignIn(cfg, detailUrl(slug), function () { showBoard(true); }));
        if (updateUrl) {
          setUrl(slug);
        }
        return;
      }
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
        root.innerHTML = '';
        // Signed out since the page loaded, or a site filter narrowed who may
        // see details: the proxy refused, so offer the sign-in.
        if (body && body.code === 'agend_apps_sign_in_required') {
          root.appendChild(renderSignIn(cfg, detailUrl(slug), function () { showBoard(true); }));
          return;
        }
        var job = unwrapOne(body);
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
      // The visitor's sort, when the dropdown is on; otherwise the board's.
      // "Nearest" has no meaning without a point to measure from.
      var sort = String(cfg.filters.sort ? state.sort : cfg.listing.sort || '').split(':');
      if (sort[0] === 'distance' && !state.near) {
        sort = String(cfg.listing.sort || '').split(':');
      }
      var params = {
        page: state.page,
        limit: cfg.pagination.perPage,
        sortBy: sort[0] || cfg.listing.sortBy,
        sortOrder: sort[1] || cfg.listing.sortOrder,
        featuredOnly: cfg.listing.featuredOnly ? 'true' : '',
        featuredFirst: cfg.listing.featuredFirst ? 'true' : '',
        category: cfg.listing.category ? [cfg.listing.category] : state.category,
        type: state.type,
        locationType: state.workArrangement,
        experienceLevel: state.experience,
        state: state.state,
        postedWithin: state.postedWithin,
        tag: selectedTags(state),
        search: state.search.length >= 2 ? state.search : '',
        location: state.location.length >= 2 ? state.location : '',
      };
      if (state.near) {
        params.lat = state.near.lat;
        params.lng = state.near.lng;
        params.radius = state.near.radius;
        if (cfg.filters.distanceRemote && !state.excludeRemote) {
          params.includeRemote = true;
        }
      }

      // A distance search that the gateway refuses (a release that does not
      // know the parameters yet) must not blank the board: ask again without
      // it and say so.
      var degraded = false;
      // The proxy's own refusal or the gateway's. A WordPress error body has a
      // string `code` and no list; a gateway refusal it passes on carries the
      // HTTP status as `data.status_code`, not `data.status`, so the shape is
      // the test rather than either number.
      function failed(body) {
        return !body || body.success === false || (typeof body.code === 'string' && !Array.isArray(body.data));
      }
      function load(query) {
        return apiGet('/jobs', query).catch(function () {
          return { success: false };
        });
      }
      function withoutNear(query) {
        var plain = {};
        Object.keys(query).forEach(function (key) {
          plain[key] = query[key];
        });
        delete plain.lat;
        delete plain.lng;
        delete plain.radius;
        delete plain.includeRemote;
        if (plain.sortBy === 'distance') {
          plain.sortBy = cfg.listing.sortBy;
          plain.sortOrder = cfg.listing.sortOrder;
        }
        return plain;
      }

      // A gateway that refuses includeRemote (rather than ignoring it) costs
      // only the remote jobs: the distance search is asked again on its own
      // before it is given up.
      function withoutRemote(query) {
        var plain = {};
        Object.keys(query).forEach(function (key) {
          plain[key] = query[key];
        });
        delete plain.includeRemote;
        return plain;
      }
      function giveUpNear() {
        degraded = true;
        return load(withoutNear(params));
      }

      load(params).then(function (body) {
        if (!state.near || !failed(body) || body.code === 'agend_apps_sign_in_required') {
          return body;
        }
        if (params.includeRemote) {
          return load(withoutRemote(params)).then(function (retry) {
            return failed(retry) && retry.code !== 'agend_apps_sign_in_required' ? giveUpNear() : retry;
          });
        }
        return giveUpNear();
      }).then(function (body) {
        if (seq !== requestSeq) {
          return;
        }
        if (body && body.code === 'agend_apps_sign_in_required') {
          grid.innerHTML = '';
          pager.innerHTML = '';
          count.textContent = '';
          status.style.display = 'none';
          grid.appendChild(renderSignIn(cfg, boardUrl(), null));
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
        var searchable = f.search || f.category || f.type || f.experience || f.location || f.distance;
        var total = result.pagination ? result.pagination.total : result.items.length;
        count.textContent = searchable && total ? total + (total === 1 ? ' job' : ' jobs') : '';
        // A gateway that does not know the distance parameters ignores them
        // and answers with every job, none carrying a distance: say so rather
        // than let the list pass for a search within the radius.
        if (state.near && state.say) {
          var unmeasured = isUnmeasured(result.items, !!params.includeRemote);
          state.say(degraded || unmeasured ? GEO_MESSAGES.degraded : '');
        }
        if (!result.items.length && !grid.childNodes.length) {
          status.style.display = '';
          status.textContent = cfg.listing.featuredOnly ? 'There are no featured jobs right now.' : (state.near && !degraded ? 'No jobs within ' + state.near.radius + ' km of ' + state.near.label + '.' : 'No jobs match your search.');
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
