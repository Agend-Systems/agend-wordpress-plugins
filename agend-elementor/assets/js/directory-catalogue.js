/**
 * Agend Directory widget — frontend renderer.
 *
 * One widget, connected client-side states (SPEC-INFRA-20260717):
 *  - catalogue: searchable/filterable grid or list of business listings (US-2.1/2.2/2.3)
 *  - detail:    single-listing view with reviews (US-3.1, US-4.1/4.2)
 * State transitions happen without a WordPress reload. Every listing is deep
 * linkable via /{page}/listing/{slug}/ (US-3.2). Data comes from the Agend Apps
 * Core REST proxy (/wp-json/agend-apps/v1/directory...). Listings are public;
 * the listing email is never rendered.
 */
(function () {
  'use strict';

  var DEEP_LINK_PARAM = 'agend_listing';

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

  function unwrapOne(body) {
    if (body && body.data && !Array.isArray(body.data)) {
      return body.data;
    }
    if (body && body.success === false) {
      return null;
    }
    return body || null;
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
  // widget root when inheritance is enabled. Fire-and-forget: the CSS custom
  // properties update live once the config resolves.
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
          root.style.setProperty('--agend-dir-heading', heading);
        }
        if (body2) {
          root.style.setProperty('--agend-dir-body', body2);
        }
        if (accent) {
          root.style.setProperty('--agend-dir-accent', accent);
          root.style.setProperty('--agend-dir-button', accent);
        }
      }
      if (cfg.theme.inheritFonts && theme.fonts) {
        if (theme.fonts.heading) {
          root.style.setProperty('--agend-dir-font-heading', '"' + theme.fonts.heading + '", sans-serif');
        }
        if (theme.fonts.body) {
          root.style.setProperty('--agend-dir-font-body', '"' + theme.fonts.body + '", sans-serif');
        }
      }
    }).catch(function () {
      /* site config unavailable — fall back to the editor colours */
    });
  }

  function truncate(text, length) {
    if (!text) {
      return '';
    }
    return text.length <= length ? text : text.slice(0, length).replace(/\s+\S*$/, '') + '…';
  }

  function stripHtml(html) {
    if (!html) {
      return '';
    }
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
  }

  // Semi-trusted CMS rich text allowlist, mirroring the server-side sanitiser.
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

  // Renders semi-trusted CMS rich text (association-authored listing
  // descriptions) as HTML. Defence-in-depth before innerHTML; fails CLOSED to
  // plain text when the vendored DOMPurify is unavailable.
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
    node.textContent = stripHtml(html);
  }

  // Only ever render http(s) URLs into href/src, so a persisted javascript: or
  // data: value can never execute.
  function safeUrl(value) {
    if (typeof value !== 'string' || !value) {
      return '';
    }
    var trimmed = value.trim();
    return /^https?:\/\//i.test(trimmed) ? trimmed : '';
  }

  function initials(name) {
    if (!name) {
      return '?';
    }
    var parts = String(name).trim().split(/\s+/).slice(0, 2);
    return parts.map(function (p) {
      return p.charAt(0).toUpperCase();
    }).join('') || '?';
  }

  // Reviews are tenant-facing; DD/MM/YYYY per SPEC-INFRA-20260717 US-4.1 AC2.
  function formatDate(iso) {
    if (!iso) {
      return '';
    }
    var d = new Date(iso);
    if (isNaN(d.getTime())) {
      return '';
    }
    var dd = ('0' + d.getDate()).slice(-2);
    var mm = ('0' + (d.getMonth() + 1)).slice(-2);
    return dd + '/' + mm + '/' + d.getFullYear();
  }

  var DAY_LABELS = {
    monday: 'Monday',
    tuesday: 'Tuesday',
    wednesday: 'Wednesday',
    thursday: 'Thursday',
    friday: 'Friday',
    saturday: 'Saturday',
    sunday: 'Sunday',
  };
  var DAY_ORDER = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

  // -- Rating stars ---------------------------------------------------------

  function renderStars(avg, count, includeCount) {
    var wrap = el('div', 'agend-dir-stars');
    var rating = typeof avg === 'number' ? avg : parseFloat(avg);
    var rounded = isNaN(rating) ? 0 : Math.round(rating);
    for (var i = 1; i <= 5; i++) {
      wrap.appendChild(el('span', 'agend-dir-star' + (i <= rounded ? ' is-on' : ''), '★'));
    }
    if (includeCount) {
      var n = typeof count === 'number' ? count : parseInt(count, 10) || 0;
      var label = isNaN(rating) || !n
        ? 'No reviews yet'
        : rating.toFixed(1) + ' (' + n + ')';
      wrap.appendChild(el('span', 'agend-dir-stars__count', label));
    }
    return wrap;
  }

  // -- Loading skeletons ----------------------------------------------------

  function skeletonLine(width) {
    var line = el('div', 'agend-skel-line');
    line.style.width = width;
    return line;
  }

  function skeletonCard() {
    var card = el('article', 'agend-dir-card agend-dir-skeleton');
    card.setAttribute('aria-hidden', 'true');
    card.appendChild(el('div', 'agend-dir-card__media'));
    var body = el('div', 'agend-dir-card__body');
    body.appendChild(skeletonLine('40%'));
    body.appendChild(skeletonLine('85%'));
    body.appendChild(skeletonLine('60%'));
    card.appendChild(body);
    return card;
  }

  function skeletonCount(cfg) {
    if (cfg.layout && cfg.layout.style === 'list') {
      return 3;
    }
    var cols = (cfg.layout && cfg.layout.desktop) || 3;
    return cols === 1 ? 3 : cols;
  }

  function appendGridSkeletons(grid, cfg) {
    for (var i = 0; i < skeletonCount(cfg); i++) {
      grid.appendChild(skeletonCard());
    }
  }

  function renderDetailSkeleton() {
    var wrap = el('div', 'agend-dir-detail agend-dir-skeleton agend-dir-detail-skeleton');
    wrap.setAttribute('role', 'status');
    wrap.appendChild(el('span', 'agend-visually-hidden', 'Loading listing…'));
    wrap.appendChild(el('div', 'agend-dir-detail__hero agend-skel-block'));
    var layout = el('div', 'agend-dir-detail__layout');
    var main = el('div', 'agend-dir-detail__main');
    ['30%', '95%', '90%', '80%', '60%'].forEach(function (w) {
      main.appendChild(skeletonLine(w));
    });
    layout.appendChild(main);
    var side = el('aside', 'agend-dir-detail__side');
    side.appendChild(el('div', 'agend-dir-detail__panel agend-skel-block'));
    layout.appendChild(side);
    wrap.appendChild(layout);
    return wrap;
  }

  // -- Catalogue card -------------------------------------------------------

  function renderCard(listing, cfg, onOpen, hrefFor) {
    // In server-rendered detail mode the card is a real link to the detail
    // page (full navigation), so breadcrumbs and SEO resolve server-side.
    var href = cfg.ssrDetail && hrefFor ? hrefFor(listing.slug) : null;
    var card = el(href ? 'a' : 'article', 'agend-dir-card');
    if (href) {
      card.href = href;
    } else {
      card.setAttribute('role', 'button');
      card.setAttribute('tabindex', '0');
      card.addEventListener('click', function () {
        onOpen(listing.slug);
      });
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          onOpen(listing.slug);
        }
      });
    }

    var featured = listing.is_featured || (typeof listing.sponsor_level === 'number' && listing.sponsor_level > 0);
    if (featured) {
      card.classList.add('is-featured');
    }

    if (cfg.card.logo) {
      var media = el('div', 'agend-dir-card__media');
      var logo = safeUrl(listing.logo_url) || safeUrl(listing.hero_image_url);
      if (logo) {
        var img = el('img', 'agend-dir-card__img');
        img.src = logo;
        img.alt = listing.name || '';
        img.loading = 'lazy';
        media.appendChild(img);
      } else {
        media.classList.add('agend-dir-card__media--placeholder');
        media.appendChild(el('span', 'agend-dir-card__initials', initials(listing.name)));
      }
      if (featured) {
        media.appendChild(el('span', 'agend-dir-card__ribbon', 'Featured'));
      }
      card.appendChild(media);
    }

    var body = el('div', 'agend-dir-card__body');

    if (cfg.card.category && listing.primary_category && listing.primary_category.name) {
      var pills = el('div', 'agend-dir-card__pills');
      pills.appendChild(el('span', 'agend-dir-pill agend-dir-pill--category', listing.primary_category.name));
      body.appendChild(pills);
    }

    body.appendChild(el('h3', 'agend-dir-card__title', listing.name || ''));

    if (cfg.card.location && listing.primary_location) {
      var loc = [listing.primary_location.city, listing.primary_location.state].filter(Boolean).join(', ');
      if (loc) {
        body.appendChild(el('div', 'agend-dir-card__location', loc));
      }
    }

    if (cfg.card.rating) {
      body.appendChild(renderStars(listing.average_rating, listing.review_count, true));
    }

    if (cfg.card.description) {
      var desc = stripHtml(listing.short_description || '');
      if (desc) {
        body.appendChild(el('p', 'agend-dir-card__desc', truncate(desc, cfg.card.excerptLength)));
      }
    }

    if (cfg.card.badges && Array.isArray(listing.badges) && listing.badges.length) {
      var badges = el('div', 'agend-dir-card__badges');
      listing.badges.forEach(function (badge) {
        var pill = el('span', 'agend-dir-badge', badge.name || '');
        if (badge.color) {
          pill.style.setProperty('--agend-dir-badge-colour', badge.color);
        }
        badges.appendChild(pill);
      });
      body.appendChild(badges);
    }

    card.appendChild(body);
    return card;
  }

  // -- Detail ---------------------------------------------------------------

  function factRow(label, value) {
    var row = el('div', 'agend-dir-detail__fact');
    row.appendChild(el('span', 'agend-dir-detail__fact-label', label));
    row.appendChild(el('span', 'agend-dir-detail__fact-value', value));
    return row;
  }

  function openLightbox(url, alt) {
    var overlay = el('div', 'agend-dir-lightbox');
    var img = el('img', 'agend-dir-lightbox__img');
    img.src = url;
    img.alt = alt || '';
    overlay.appendChild(img);
    var close = function () {
      if (overlay.parentNode) {
        overlay.parentNode.removeChild(overlay);
      }
      document.removeEventListener('keydown', onKey);
    };
    var onKey = function (e) {
      if (e.key === 'Escape') {
        close();
      }
    };
    overlay.addEventListener('click', close);
    document.addEventListener('keydown', onKey);
    document.body.appendChild(overlay);
  }

  function renderBusinessHours(hours) {
    if (!hours || typeof hours !== 'object') {
      return null;
    }
    var section = el('section', 'agend-dir-detail__section');
    section.appendChild(el('h3', 'agend-dir-detail__section-title', 'Opening Hours'));
    var list = el('div', 'agend-dir-hours');
    var any = false;
    DAY_ORDER.forEach(function (day) {
      var entry = hours[day];
      if (!entry) {
        return;
      }
      any = true;
      var row = el('div', 'agend-dir-hours__row');
      row.appendChild(el('span', 'agend-dir-hours__day', DAY_LABELS[day] || day));
      var value = entry.closed
        ? 'Closed'
        : [entry.open, entry.close].filter(Boolean).join(' – ') || 'Closed';
      row.appendChild(el('span', 'agend-dir-hours__value', value));
      list.appendChild(row);
    });
    if (!any) {
      return null;
    }
    section.appendChild(list);
    return section;
  }

  function renderLocations(locations) {
    if (!Array.isArray(locations) || !locations.length) {
      return null;
    }
    var section = el('section', 'agend-dir-detail__section');
    section.appendChild(el('h3', 'agend-dir-detail__section-title', 'Locations'));
    var list = el('div', 'agend-dir-locations');
    locations.forEach(function (loc) {
      var card = el('div', 'agend-dir-location');
      if (loc.name) {
        card.appendChild(el('div', 'agend-dir-location__name', loc.name));
      }
      var addr = [loc.address_line_1, loc.address_line_2, loc.city, loc.state, loc.postcode]
        .filter(Boolean).join(', ');
      if (addr) {
        card.appendChild(el('div', 'agend-dir-location__address', addr));
      }
      if (loc.phone) {
        var tel = el('a', 'agend-dir-location__phone', loc.phone);
        tel.href = 'tel:' + loc.phone.replace(/[^+\d]/g, '');
        card.appendChild(tel);
      }
      var hours = renderBusinessHours(loc.hours);
      if (hours) {
        card.appendChild(hours);
      }
      list.appendChild(card);
    });
    section.appendChild(list);
    return section;
  }

  var SOCIALS = [
    ['website', 'Website'],
    ['facebook_url', 'Facebook'],
    ['instagram_url', 'Instagram'],
    ['twitter_url', 'Twitter'],
    ['linkedin_url', 'LinkedIn'],
    ['youtube_url', 'YouTube'],
  ];

  function renderContactPanel(listing) {
    var panel = el('div', 'agend-dir-detail__panel');
    panel.appendChild(el('h3', 'agend-dir-detail__panel-title', 'Contact'));
    var any = false;

    // Phone is present only when the listing exposes it (server-gated); email is
    // never returned by the public API.
    if (listing.phone) {
      any = true;
      var tel = el('a', 'agend-dir-detail__contact', listing.phone);
      tel.href = 'tel:' + String(listing.phone).replace(/[^+\d]/g, '');
      panel.appendChild(tel);
    }

    SOCIALS.forEach(function (pair) {
      var url = safeUrl(listing[pair[0]]);
      if (!url) {
        return;
      }
      any = true;
      var link = el('a', 'agend-dir-detail__contact agend-dir-detail__contact--link', pair[1]);
      link.href = url;
      link.target = '_blank';
      link.rel = 'noopener noreferrer';
      panel.appendChild(link);
    });

    if (!any) {
      panel.appendChild(el('p', 'agend-dir-detail__note', 'No contact details provided.'));
    }
    return panel;
  }

  // Renders a single public custom-field value by its type (US-4.x / Part A).
  function renderCustomFieldValue(field) {
    var type = field.type || 'text';
    var value = field.value;
    if (type === 'array' && Array.isArray(value)) {
      var wrap = el('span', 'agend-dir-cf__value');
      var pills = el('span', 'agend-dir-card__pills');
      value.forEach(function (item) {
        pills.appendChild(el('span', 'agend-dir-pill agend-dir-pill--tag', String(item)));
      });
      wrap.appendChild(pills);
      return wrap;
    }
    if (type === 'url') {
      var url = safeUrl(String(value));
      if (url) {
        var link = el('a', 'agend-dir-cf__value agend-dir-cf__link', String(value));
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener noreferrer';
        return link;
      }
      return el('span', 'agend-dir-cf__value', String(value));
    }
    if (type === 'email') {
      var mail = el('a', 'agend-dir-cf__value agend-dir-cf__link', String(value));
      mail.href = 'mailto:' + String(value);
      return mail;
    }
    if (type === 'boolean') {
      return el('span', 'agend-dir-cf__value', value ? 'Yes' : 'No');
    }
    if (type === 'date') {
      return el('span', 'agend-dir-cf__value', formatDate(String(value)));
    }
    return el('span', 'agend-dir-cf__value', String(value));
  }

  // Renders the public custom fields as a "Details" section, or null if none.
  function renderCustomFields(fields) {
    if (!Array.isArray(fields) || !fields.length) {
      return null;
    }
    var section = el('section', 'agend-dir-detail__section');
    section.appendChild(el('h3', 'agend-dir-detail__section-title', 'Details'));
    var list = el('div', 'agend-dir-cf');
    var any = false;
    fields.forEach(function (field) {
      if (!field || !field.label) {
        return;
      }
      any = true;
      var row = el('div', 'agend-dir-cf__row');
      row.appendChild(el('span', 'agend-dir-cf__label', field.label));
      row.appendChild(renderCustomFieldValue(field));
      list.appendChild(row);
    });
    if (!any) {
      return null;
    }
    section.appendChild(list);
    return section;
  }

  function renderDetail(listing, cfg, onBack, reviewsMount) {
    var wrap = el('div', 'agend-dir-detail');

    var back = el('button', 'agend-dir-detail__back', '← Back to Directory');
    back.addEventListener('click', onBack);
    wrap.appendChild(back);

    var hero = el('div', 'agend-dir-detail__hero');
    var heroImg = safeUrl(listing.hero_image_url);
    if (heroImg) {
      hero.style.backgroundImage = 'linear-gradient(180deg, rgba(30,42,74,0.30), rgba(30,42,74,0.80)), url("' + heroImg + '")';
    }
    var heroInner = el('div', 'agend-dir-detail__hero-inner');
    var logo = safeUrl(listing.logo_url);
    if (logo) {
      var logoImg = el('img', 'agend-dir-detail__logo');
      logoImg.src = logo;
      logoImg.alt = listing.name || '';
      heroInner.appendChild(logoImg);
    }
    heroInner.appendChild(el('h2', 'agend-dir-detail__title', listing.name || ''));
    if (listing.primary_category && listing.primary_category.name) {
      var heroPills = el('div', 'agend-dir-card__pills');
      heroPills.appendChild(el('span', 'agend-dir-pill agend-dir-pill--category', listing.primary_category.name));
      heroInner.appendChild(heroPills);
    }
    if (Array.isArray(listing.badges) && listing.badges.length) {
      var heroBadges = el('div', 'agend-dir-detail__badges');
      listing.badges.forEach(function (badge) {
        var pill = el('span', 'agend-dir-detail__badge');
        if (badge.color) {
          pill.style.setProperty('--agend-dir-badge-colour', badge.color);
        }
        if (badge.icon) {
          pill.appendChild(el('span', 'agend-dir-detail__badge-icon', badge.icon));
        }
        pill.appendChild(document.createTextNode(badge.name || ''));
        heroBadges.appendChild(pill);
      });
      heroInner.appendChild(heroBadges);
    }
    heroInner.appendChild(renderStars(listing.average_rating, listing.review_count, true));
    hero.appendChild(heroInner);
    wrap.appendChild(hero);

    var layout = el('div', 'agend-dir-detail__layout');
    var main = el('div', 'agend-dir-detail__main');

    if (listing.description) {
      var about = el('section', 'agend-dir-detail__section');
      about.appendChild(el('h3', 'agend-dir-detail__section-title', 'About'));
      var para = el('div', 'agend-dir-detail__body-text');
      setSafeHtml(para, listing.description);
      about.appendChild(para);
      main.appendChild(about);
    }

    var detailsSection = renderCustomFields(listing.custom_fields);
    if (detailsSection) {
      main.appendChild(detailsSection);
    }

    if (Array.isArray(listing.gallery_images) && listing.gallery_images.length) {
      var gallery = el('section', 'agend-dir-detail__section');
      gallery.appendChild(el('h3', 'agend-dir-detail__section-title', 'Gallery'));
      var grid = el('div', 'agend-dir-gallery');
      listing.gallery_images.forEach(function (raw) {
        var src = safeUrl(raw);
        if (!src) {
          return;
        }
        var thumb = el('button', 'agend-dir-gallery__thumb');
        thumb.type = 'button';
        var gimg = el('img', 'agend-dir-gallery__img');
        gimg.src = src;
        gimg.alt = listing.name || '';
        gimg.loading = 'lazy';
        thumb.appendChild(gimg);
        thumb.addEventListener('click', function () {
          openLightbox(src, listing.name);
        });
        grid.appendChild(thumb);
      });
      gallery.appendChild(grid);
      main.appendChild(gallery);
    }

    var locations = renderLocations(listing.locations);
    if (locations) {
      main.appendChild(locations);
    }

    var hours = renderBusinessHours(listing.business_hours);
    if (hours) {
      main.appendChild(hours);
    }

    if (Array.isArray(listing.tags) && listing.tags.length) {
      var tagsSection = el('section', 'agend-dir-detail__section');
      tagsSection.appendChild(el('h3', 'agend-dir-detail__section-title', 'Tags'));
      var tagWrap = el('div', 'agend-dir-card__pills');
      listing.tags.forEach(function (tag) {
        tagWrap.appendChild(el('span', 'agend-dir-pill agend-dir-pill--tag', tag.name || ''));
      });
      tagsSection.appendChild(tagWrap);
      main.appendChild(tagsSection);
    }

    // Reviews mount point — populated asynchronously by the caller (US-4.x).
    if (reviewsMount) {
      main.appendChild(reviewsMount);
    }

    layout.appendChild(main);

    var side = el('aside', 'agend-dir-detail__side');
    side.appendChild(renderContactPanel(listing));

    if (Array.isArray(listing.categories) && listing.categories.length) {
      var catPanel = el('div', 'agend-dir-detail__panel');
      catPanel.appendChild(el('h3', 'agend-dir-detail__panel-title', 'Categories'));
      var catWrap = el('div', 'agend-dir-card__pills');
      listing.categories.forEach(function (cat) {
        catWrap.appendChild(el('span', 'agend-dir-pill agend-dir-pill--category', cat.name || ''));
      });
      catPanel.appendChild(catWrap);
      side.appendChild(catPanel);
    }

    layout.appendChild(side);
    wrap.appendChild(layout);
    return wrap;
  }

  function renderNotFound(onBack) {
    var wrap = el('div', 'agend-dir-detail');
    var back = el('button', 'agend-dir-detail__back', '← Back to Directory');
    back.addEventListener('click', onBack);
    wrap.appendChild(back);
    wrap.appendChild(el('div', 'agend-dir-status', 'Listing not found.'));
    return wrap;
  }

  // -- Reviews (US-4.1 / US-4.2) -------------------------------------------

  function renderReviewList(mount, listing, cfg) {
    mount.innerHTML = '';
    var section = el('section', 'agend-dir-detail__section agend-dir-reviews');
    section.appendChild(el('h3', 'agend-dir-detail__section-title', 'Reviews'));

    var summary = el('div', 'agend-dir-reviews__summary');
    summary.appendChild(renderStars(listing.average_rating, listing.review_count, true));
    section.appendChild(summary);

    var list = el('div', 'agend-dir-reviews__list');
    var status = el('div', 'agend-dir-status', 'Loading reviews…');
    section.appendChild(status);
    section.appendChild(list);

    var pager = el('div', 'agend-dir-reviews__pager');
    section.appendChild(pager);

    var page = 1;
    var perPage = 5;

    function load() {
      status.style.display = '';
      status.textContent = 'Loading reviews…';
      apiGet('/directory/listings/' + encodeURIComponent(listing.slug) + '/reviews', {
        page: page,
        limit: perPage,
      }).then(function (body) {
        // A gateway error resolves as an error envelope, not a thrown fetch;
        // surface it rather than masking it as an empty "no reviews" state.
        if (body && body.success === false) {
          list.innerHTML = '';
          pager.innerHTML = '';
          status.style.display = '';
          status.textContent = 'Unable to load reviews.';
          return;
        }
        var result = unwrapList(body);
        list.innerHTML = '';
        pager.innerHTML = '';
        if (!result.items.length) {
          status.style.display = '';
          status.textContent = page === 1 ? 'No reviews yet. Be the first to review.' : 'No more reviews.';
          return;
        }
        status.style.display = 'none';
        result.items.forEach(function (review) {
          var item = el('div', 'agend-dir-review');
          var head = el('div', 'agend-dir-review__head');
          var nameWrap = el('div', 'agend-dir-review__name-wrap');
          nameWrap.appendChild(el('span', 'agend-dir-review__name', review.reviewer_name || 'Anonymous'));
          if (review.is_verified) {
            nameWrap.appendChild(el('span', 'agend-dir-review__verified', 'Verified'));
          }
          head.appendChild(nameWrap);
          head.appendChild(renderStars(review.rating, 0, false));
          item.appendChild(head);
          if (review.created_at) {
            item.appendChild(el('div', 'agend-dir-review__date', formatDate(review.created_at)));
          }
          item.appendChild(el('p', 'agend-dir-review__content', review.content || ''));
          list.appendChild(item);
        });
        var pg = result.pagination;
        if (pg && pg.total_pages > 1) {
          for (var i = 1; i <= pg.total_pages; i++) {
            (function (pageNum) {
              var btn = el('button', 'agend-dir-page' + (pageNum === pg.page ? ' is-active' : ''), pageNum);
              btn.addEventListener('click', function () {
                page = pageNum;
                load();
              });
              pager.appendChild(btn);
            })(i);
          }
        }
      }).catch(function () {
        status.style.display = '';
        status.textContent = 'Unable to load reviews.';
      });
    }

    load();

    if (cfg.detail && cfg.detail.reviewForm) {
      section.appendChild(renderReviewForm(listing));
    }

    mount.appendChild(section);
  }

  function labelledField(labelText, input) {
    var wrap = el('label', 'agend-dir-review-form__field');
    wrap.appendChild(el('span', 'agend-dir-review-form__label', labelText));
    wrap.appendChild(input);
    return wrap;
  }

  function renderReviewForm(listing) {
    var form = el('div', 'agend-dir-review-form');
    form.appendChild(el('h4', 'agend-dir-review-form__title', 'Write a Review'));

    var ratingWrap = el('div', 'agend-dir-review-form__rating');
    ratingWrap.appendChild(el('span', 'agend-dir-review-form__label', 'Your rating'));
    var starsRow = el('div', 'agend-dir-review-form__stars');
    var chosen = 0;
    var starEls = [];
    function paint(value) {
      starEls.forEach(function (s, idx) {
        if (idx < value) {
          s.classList.add('is-on');
        } else {
          s.classList.remove('is-on');
        }
      });
    }
    for (var i = 1; i <= 5; i++) {
      (function (value) {
        var star = el('button', 'agend-dir-review-form__star', '★');
        star.type = 'button';
        star.setAttribute('aria-label', value + ' star' + (value > 1 ? 's' : ''));
        star.addEventListener('click', function () {
          chosen = value;
          paint(value);
        });
        starEls.push(star);
        starsRow.appendChild(star);
      })(i);
    }
    ratingWrap.appendChild(starsRow);
    form.appendChild(ratingWrap);

    var name = el('input', 'agend-dir-review-form__input');
    name.type = 'text';
    name.placeholder = 'Your name';
    var email = el('input', 'agend-dir-review-form__input');
    email.type = 'email';
    email.placeholder = 'you@example.com';
    var content = el('textarea', 'agend-dir-review-form__textarea');
    content.placeholder = 'Share your experience (at least 20 characters)…';
    content.rows = 4;

    form.appendChild(labelledField('Name', name));
    form.appendChild(labelledField('Email', email));
    form.appendChild(labelledField('Review', content));

    var error = el('div', 'agend-dir-review-form__error');
    error.style.display = 'none';
    form.appendChild(error);

    var submit = el('button', 'agend-dir-review-form__submit', 'Submit Review');
    submit.type = 'button';
    form.appendChild(submit);

    function showError(msg) {
      error.style.display = '';
      error.textContent = msg;
    }

    submit.addEventListener('click', function () {
      error.style.display = 'none';
      var nameVal = name.value.trim();
      var emailVal = email.value.trim();
      var contentVal = content.value.trim();
      if (!chosen) {
        showError('Select a star rating.');
        return;
      }
      if (nameVal.length < 2) {
        showError('Enter your name.');
        return;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
        showError('Enter a valid email address.');
        return;
      }
      if (contentVal.length < 20) {
        showError('Your review must be at least 20 characters.');
        return;
      }
      submit.disabled = true;
      submit.textContent = 'Submitting…';
      apiPost('/directory/reviews', {
        listing_id: listing.id,
        rating: chosen,
        reviewer_name: nameVal,
        reviewer_email: emailVal,
        content: contentVal,
      }).then(function (res) {
        if (res && res.success === false) {
          throw new Error((res.error && res.error.message) || 'Submission failed.');
        }
        form.innerHTML = '';
        var done = el('div', 'agend-dir-review-form__done');
        done.appendChild(el('div', 'agend-dir-review-form__tick', '✓'));
        done.appendChild(el('p', null, 'Thank you. Your review has been submitted and is awaiting moderation before it appears.'));
        form.appendChild(done);
      }).catch(function (err) {
        submit.disabled = false;
        submit.textContent = 'Submit Review';
        showError((err && err.message) || 'Something went wrong. Please try again.');
      });
    });

    return form;
  }

  // -- Filter bar (US-2.2) --------------------------------------------------

  // Opens/closes a popover panel anchored to a toggle, portalled to <body> so it
  // escapes any ancestor stacking context or overflow (Elementor sections use
  // position:relative;z-index:1, which traps a fixed child).
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
      if (panel.parentNode !== document.body) {
        if (!themed) {
          var root = toggle.closest('.agend-directory-catalogue');
          if (root) {
            [
              '--agend-dir-heading',
              '--agend-dir-body',
              '--agend-dir-accent',
              '--agend-dir-button',
              '--agend-dir-button-text',
              '--agend-dir-card-radius',
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

  function buildCheckboxFilter(allLabel, onChange) {
    var wrap = el('div', 'agend-dir-multiselect');
    var toggle = el('button', 'agend-dir-filter agend-dir-multiselect__toggle', allLabel);
    toggle.type = 'button';
    toggle.setAttribute('aria-expanded', 'false');
    var panel = el('div', 'agend-dir-multiselect__panel');
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
      var row = el('label', 'agend-dir-multiselect__option');
      var cb = el('input', 'agend-dir-multiselect__checkbox');
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
        onChange(selected.map(function (s) {
          return s.value;
        }));
      });
      row.appendChild(cb);
      row.appendChild(el('span', 'agend-dir-multiselect__optlabel', label));
      panel.appendChild(row);
    };

    attachPopover(toggle, panel);
    wrap.appendChild(toggle);
    wrap.appendChild(panel);
    return { wrap: wrap, addOption: addOption };
  }

  function buildFilterBar(root, cfg, state, reload) {
    if (!cfg.filters.search && !cfg.filters.category && !cfg.filters.rating) {
      return null;
    }
    var bar = el('div', 'agend-dir-filterbar');

    var clearBtn = el('button', 'agend-dir-clear', 'Clear');
    clearBtn.type = 'button';
    clearBtn.style.display = 'none';

    function refreshClear() {
      var active = state.search || state.category || (state.categories && state.categories.length) || state.rating;
      clearBtn.style.display = active ? '' : 'none';
    }

    if (cfg.filters.search) {
      var search = el('input', 'agend-dir-search');
      search.type = 'search';
      search.placeholder = 'Search listings…';
      var debounce;
      search.addEventListener('input', function () {
        window.clearTimeout(debounce);
        debounce = window.setTimeout(function () {
          state.search = search.value.trim();
          state.page = 1;
          refreshClear();
          reload();
        }, 300);
      });
      state._searchInput = search;
      bar.appendChild(search);
    }

    var excludedCategories = (cfg.exclusions && cfg.exclusions.categories) || [];

    if (cfg.filters.category) {
      if (cfg.filters.categoryMulti) {
        var catMulti = buildCheckboxFilter('All Categories', function (values) {
          state.categories = values;
          state.category = '';
          state.page = 1;
          refreshClear();
          reload();
        });
        apiGet('/directory/categories', {}).then(function (body) {
          unwrapList(body).items.forEach(function (cat) {
            if (excludedCategories.indexOf(String(cat.id)) !== -1) {
              return;
            }
            catMulti.addOption(String(cat.id), cat.name);
          });
        });
        bar.appendChild(catMulti.wrap);
      } else {
        var category = el('select', 'agend-dir-filter');
        category.appendChild(new Option('All Categories', ''));
        apiGet('/directory/categories', {}).then(function (body) {
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
          refreshClear();
          reload();
        });
        state._categorySelect = category;
        bar.appendChild(category);
      }
    }

    if (cfg.filters.rating) {
      var rating = el('select', 'agend-dir-filter');
      [
        ['', 'All Ratings'],
        ['4', '4 stars & up'],
        ['3', '3 stars & up'],
        ['2', '2 stars & up'],
        ['1', '1 star & up'],
      ].forEach(function (o) {
        rating.appendChild(new Option(o[1], o[0]));
      });
      rating.addEventListener('change', function () {
        state.rating = rating.value;
        state.page = 1;
        refreshClear();
        reload();
      });
      state._ratingSelect = rating;
      bar.appendChild(rating);
    }

    clearBtn.addEventListener('click', function () {
      state.search = '';
      state.category = '';
      state.categories = [];
      state.rating = '';
      state.page = 1;
      if (state._searchInput) {
        state._searchInput.value = '';
      }
      if (state._categorySelect) {
        state._categorySelect.value = '';
      }
      if (state._ratingSelect) {
        state._ratingSelect.value = '';
      }
      refreshClear();
      reload();
    });
    bar.appendChild(clearBtn);

    root.appendChild(bar);
    return bar;
  }

  function renderPagination(root, cfg, state, pagination, reload) {
    if (cfg.pagination.style === 'none' || !pagination) {
      return;
    }
    var nav = el('div', 'agend-dir-pagination');
    if (cfg.pagination.style === 'load_more') {
      if (pagination.has_next) {
        var more = el('button', 'agend-dir-loadmore', 'Load more listings');
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
          var btn = el('button', 'agend-dir-page' + (pageNum === pagination.page ? ' is-active' : ''), pageNum);
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
      cfg = JSON.parse(root.getAttribute('data-agend-directory-config'));
    } catch (e) {
      return;
    }

    var state = {
      search: '',
      category: '',
      categories: [],
      rating: '',
      page: 1,
      append: false,
    };

    applySiteTheme(root, cfg);

    var catalogueEl = el('div', 'agend-dir-catalogue');
    var status = el('div', 'agend-dir-status', 'Loading listings…');
    var grid = el('div', 'agend-dir-grid agend-dir-grid--' + (cfg.layout.style || 'grid'));
    grid.style.setProperty('--agend-dir-cols-desktop', cfg.layout.style === 'list' ? 1 : cfg.layout.desktop);
    grid.style.setProperty('--agend-dir-cols-tablet', cfg.layout.style === 'list' ? 1 : cfg.layout.tablet);
    grid.style.setProperty('--agend-dir-cols-mobile', cfg.layout.mobile);
    var pager = el('div', 'agend-dir-pager-slot');

    if (cfg.heading && cfg.heading.show) {
      var head = el('div', 'agend-dir-heading');
      if (cfg.heading.title) {
        head.appendChild(el('h2', 'agend-dir-heading__title', cfg.heading.title));
      }
      if (cfg.heading.subtitle) {
        head.appendChild(el('p', 'agend-dir-heading__subtitle', cfg.heading.subtitle));
      }
      catalogueEl.appendChild(head);
    }
    buildFilterBar(catalogueEl, cfg, state, reloadCatalogue);
    catalogueEl.appendChild(status);
    catalogueEl.appendChild(grid);
    catalogueEl.appendChild(pager);

    function deepLinkUrl(slug) {
      if (cfg.prettyLinks && cfg.basePath) {
        var base = cfg.basePath;
        if (base.charAt(base.length - 1) !== '/') {
          base += '/';
        }
        return base + 'listing/' + encodeURIComponent(slug) + '/';
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
          target = cfg.basePath;
        } else {
          var url = new URL(window.location.href);
          url.searchParams.delete(DEEP_LINK_PARAM);
          target = url.toString();
        }
        window.history.pushState({ agendListing: slug || null }, '', target);
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
      root.appendChild(renderDetailSkeleton());
      if (updateUrl) {
        setUrlParam(slug);
      }
      apiGet('/directory/listings/' + encodeURIComponent(slug), {}).then(function (body) {
        var listing = unwrapOne(body);
        root.innerHTML = '';
        if (!listing || !listing.slug) {
          root.appendChild(renderNotFound(function () { showCatalogue(true); }));
          return;
        }
        var reviewsMount = null;
        if (cfg.detail && cfg.detail.reviews) {
          reviewsMount = el('div', 'agend-dir-reviews-slot');
        }
        root.appendChild(renderDetail(listing, cfg, function () { showCatalogue(true); }, reviewsMount));
        if (reviewsMount) {
          renderReviewList(reviewsMount, listing, cfg);
        }
        window.scrollTo({ top: root.getBoundingClientRect().top + window.pageYOffset - 20, behavior: 'auto' });
      }).catch(function () {
        root.innerHTML = '';
        root.appendChild(renderNotFound(function () { showCatalogue(true); }));
      });
    }

    function reloadCatalogue() {
      status.style.display = 'none';
      pager.innerHTML = '';
      if (!state.append) {
        grid.innerHTML = '';
        appendGridSkeletons(grid, cfg);
      }
      var exclusions = cfg.exclusions || {};
      var categoryParam = state.categories && state.categories.length
        ? state.categories.join(',')
        : state.category;
      apiGet('/directory/search', {
        page: state.page,
        limit: cfg.pagination.perPage,
        search: state.search,
        category: categoryParam,
        rating: state.rating,
        featured: exclusions.featured ? 'true' : '',
        excludeCategories: (exclusions.categories || []).join(','),
        sortBy: 'relevance',
        sortOrder: 'desc',
      }).then(function (body) {
        var result = unwrapList(body);
        status.style.display = 'none';
        if (!state.append) {
          grid.innerHTML = '';
        }
        state.append = false;
        if (!result.items.length && !grid.childNodes.length) {
          status.style.display = '';
          status.textContent = 'No listings found.';
          return;
        }
        result.items.forEach(function (listing) {
          grid.appendChild(renderCard(listing, cfg, function (slug) { showDetail(slug, true); }, deepLinkUrl));
        });
        renderPagination(pager, cfg, state, result.pagination, reloadCatalogue);
      }).catch(function () {
        if (!state.append) {
          grid.innerHTML = '';
        }
        status.style.display = '';
        status.textContent = 'Unable to load listings.';
      });
    }

    function currentDeepLink() {
      try {
        if (cfg.prettyLinks) {
          var m = window.location.pathname.match(/\/listing\/([^/]+)\/?$/);
          if (m && m[1]) {
            return decodeURIComponent(m[1]);
          }
        }
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

    // In server-rendered detail mode the detail is its own page, so the
    // catalogue widget only ever renders the grid (cards are links).
    var deepLinkSlug = cfg.ssrDetail ? '' : (cfg.deepLink || currentDeepLink());
    if (deepLinkSlug) {
      showDetail(deepLinkSlug, false);
      reloadCatalogue();
    } else {
      showCatalogue(false);
      reloadCatalogue();
    }
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-directory-catalogue[data-agend-directory-config]');
    Array.prototype.forEach.call(nodes, initWidget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
