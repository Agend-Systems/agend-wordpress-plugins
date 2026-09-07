/**
 * Agend Courses (Learning Hub) widget — frontend renderer.
 *
 * One widget, connected client-side states (SPEC-INFRA-LMS-001):
 *  - catalogue: searchable/filterable grid (US-LMS.1/2)
 *  - detail:    single-course landing view (US-LMS.4)
 * Deep linkable via ?agend_course=<slug> (US-LMS.6). Data comes from the Agend
 * Apps Core REST proxy (/wp-json/agend-apps/v1/lms/courses...). A course
 * enrolment grants a specific learner login-gated access, so it needs a member
 * identity; the Enrol action opens a member sign-in gate rather than an
 * anonymous purchase flow. Member enrolment-state sidebars (US-LMS.5) and
 * in-widget enrolment activate once the SSO bearer worker lands (E-11).
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

  var DELIVERY_MODE_LABELS = {
    self_paced: 'Self-paced',
    live_online: 'Live Online',
    in_person: 'In-Person',
    blended: 'Blended',
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

  function deliveryModeLabel(value) {
    return DELIVERY_MODE_LABELS[value] || '';
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

  // Request headers for the shop cart endpoints. Prefers the shop's
  // AgendCartSession helper (WP REST nonce + guest cart session token); falls
  // back to the nonce alone when the shop script is somehow unavailable.
  function cartHeaders() {
    if (window.AgendCartSession && typeof window.AgendCartSession.getHeaders === 'function') {
      return window.AgendCartSession.getHeaders();
    }
    return nonce() ? { 'X-WP-Nonce': nonce() } : {};
  }

  // Adds a single product line to the Agend Apps Shop cart (mirrors
  // events-catalogue.js's cartAddItem, SPEC-CORE-20260722 US-2.4). A signed-in
  // member's identity rides the bearer the WP proxy already attaches, so no
  // attendee/guest data is needed for a course line; the `attendees` param is
  // kept for signature parity with the events helper and is never used here.
  function cartAddItem(productType, productId, quantity, attendees) {
    var headers = cartHeaders();
    headers['Content-Type'] = 'application/json';
    var url = restBase().replace(/\/$/, '') + '/cart/items';
    var payload = { productType: productType, productId: productId, quantity: quantity };
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

  function stripHtml(html) {
    if (!html) {
      return '';
    }
    var tmp = document.createElement('div');
    tmp.innerHTML = html;
    return (tmp.textContent || tmp.innerText || '').replace(/\s+/g, ' ').trim();
  }

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

  // Renders semi-trusted CMS rich text (association-authored course
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

  // -- Loading skeletons ------------------------------------------------------

  function skeletonLine(width) {
    var line = el('div', 'agend-skel-line');
    line.style.width = width;
    return line;
  }

  function skeletonCard() {
    var card = el('article', 'agend-lms-card agend-lms-skeleton');
    card.setAttribute('aria-hidden', 'true');
    card.appendChild(el('div', 'agend-lms-card__media'));
    var body = el('div', 'agend-lms-card__body');
    body.appendChild(skeletonLine('35%'));
    body.appendChild(skeletonLine('85%'));
    body.appendChild(skeletonLine('60%'));
    body.appendChild(skeletonLine('45%'));
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
    var wrap = el('div', 'agend-lms-detail agend-lms-skeleton agend-lms-detail-skeleton');
    wrap.setAttribute('role', 'status');
    wrap.appendChild(el('span', 'agend-visually-hidden', 'Loading course…'));
    wrap.appendChild(el('div', 'agend-lms-detail__hero agend-skel-block'));
    var layout = el('div', 'agend-lms-detail__layout');
    var main = el('div', 'agend-lms-detail__main');
    ['30%', '95%', '90%', '80%', '60%'].forEach(function (w) {
      main.appendChild(skeletonLine(w));
    });
    layout.appendChild(main);
    var side = el('aside', 'agend-lms-detail__side');
    side.appendChild(el('div', 'agend-lms-detail__panel agend-skel-block'));
    side.appendChild(el('div', 'agend-lms-detail__panel agend-skel-block'));
    layout.appendChild(side);
    wrap.appendChild(layout);
    return wrap;
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
      if (cfg.card.deliveryMode && course.delivery_mode) {
        media.appendChild(el('span', 'agend-lms-mode-pill', deliveryModeLabel(course.delivery_mode)));
      }
      // Member completion on the grid (bearer-enriched list item).
      if (course.my_enrollment && course.my_enrollment.progress && course.my_enrollment.progress.completed) {
        media.appendChild(el('span', 'agend-lms-card__completed', '✓ Completed'));
      }
      card.appendChild(media);
    }

    var body = el('div', 'agend-lms-card__body');

    // When the image is hidden the completion state still needs a home.
    if (!cfg.card.image && course.my_enrollment && course.my_enrollment.progress && course.my_enrollment.progress.completed) {
      body.appendChild(el('span', 'agend-lms-card__completed agend-lms-card__completed--inline', '✓ Completed'));
    }

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

  function renderDetail(course, cfg, onBack, onEnrol) {
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
    if (course.delivery_mode) {
      pills.appendChild(el('span', 'agend-lms-pill agend-lms-pill--mode', deliveryModeLabel(course.delivery_mode)));
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

    // The detail response is bearer-enriched (Decision 2.7): my_enrollment
    // arrives WITH the course, so the sidebar is decided synchronously — an
    // enrolled member gets their progress panel and never sees "Enrol Now"
    // (Decision 2.9); everyone else gets the pricing panel with the CTA
    // immediately (no loading placeholder needed).
    if (course.my_enrollment) {
      side.appendChild(renderEnrollmentPanel(course.my_enrollment));
    } else {
      var pricing = el('div', 'agend-lms-detail__panel agend-lms-detail__panel--pricing');
      pricing.appendChild(el('h3', 'agend-lms-detail__panel-title', 'Course Pricing'));
      var priceRow = el('div', 'agend-lms-detail__price-row');
      priceRow.appendChild(el('span', 'agend-lms-detail__price-label', 'Price'));
      var price = priceLabel(course);
      priceRow.appendChild(el('span', 'agend-lms-detail__price-value' + (price === 'Free' ? ' is-free' : ''), price));
      pricing.appendChild(priceRow);

      // Signed-in member, not yet enrolled: let them act in place instead of
      // the anonymous sign-in wall (SPEC-CORE-20260722 US-2.4).
      if (window.agendApps && window.agendApps.loggedIn) {
        renderMemberEnrolCta(pricing, course, cfg);
      } else {
        var cta = el('button', 'agend-lms-detail__cta', 'Enrol Now');
        cta.setAttribute('data-agend-course-slug', course.slug);
        cta.addEventListener('click', function () {
          if (typeof onEnrol === 'function') {
            onEnrol(course);
          }
        });
        pricing.appendChild(cta);
        pricing.appendChild(el('p', 'agend-lms-detail__note', 'Sign in to enrol and track your progress.'));
      }
      side.appendChild(pricing);
    }

    var facts = el('div', 'agend-lms-detail__panel');
    facts.appendChild(el('h3', 'agend-lms-detail__panel-title', 'Details'));
    [
      ['Level', difficultyLabel(course.difficulty)],
      ['Format', deliveryModeLabel(course.delivery_mode)],
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

  // -- Enrolment state (US-LMS.5) --------------------------------------------

  // Builds the sidebar panel for an existing enrolment record. The pricing
  // panel is fully suppressed for ANY enrolment record (Decision 2.9): an
  // enrolled member sees progress (accent), a completed member sees the
  // completed state (semantic green), and neither ever sees a price.
  function renderEnrollmentPanel(enrollment) {
    var panel = el('div', 'agend-lms-detail__panel agend-lms-detail__panel--enrollment');
    var progress = enrollment.progress || {};

    if (progress.completed) {
      panel.classList.add('is-completed');
      panel.appendChild(el('h3', 'agend-lms-detail__panel-title', 'Course Completed'));
      var done = el('div', 'agend-lms-enrol__completed');
      done.appendChild(el('span', 'agend-lms-enrol__tick', '✓'));
      var when = progress.completed_at
        ? ' on ' + new Date(progress.completed_at).toLocaleDateString('en-AU', { day: 'numeric', month: 'short', year: 'numeric' })
        : '';
      done.appendChild(el('span', 'agend-lms-enrol__completed-text', 'You completed this course' + when + '.'));
      panel.appendChild(done);
      panel.appendChild(el('p', 'agend-lms-detail__note', 'Your certificate is available in your learning portal.'));
      return panel;
    }

    panel.appendChild(el('h3', 'agend-lms-detail__panel-title', 'Your Progress'));
    var pct = Math.max(0, Math.min(100, Math.round(progress.percentage || 0)));
    var bar = el('div', 'agend-lms-enrol__bar');
    var fill = el('div', 'agend-lms-enrol__bar-fill');
    fill.style.width = pct + '%';
    bar.appendChild(fill);
    panel.appendChild(bar);
    var meta = el('div', 'agend-lms-enrol__meta');
    meta.appendChild(el('span', 'agend-lms-enrol__lessons', (progress.lessons_completed || 0) + ' of ' + (progress.total_lessons || 0) + ' modules complete'));
    meta.appendChild(el('span', 'agend-lms-enrol__pct', pct + '%'));
    panel.appendChild(meta);
    panel.appendChild(el('p', 'agend-lms-detail__note', 'Continue learning in your member portal.'));
    return panel;
  }

  // Builds the signed-in, not-yet-enrolled CTA in place (SPEC-CORE-20260722
  // US-2.4): a cart-enabled site adds the course to the shop cart; otherwise
  // the member is handed to the portal to complete enrolment there. Appends
  // directly to `panel` rather than returning a node, since the error text
  // needs to sit alongside the button it belongs to.
  function renderMemberEnrolCta(panel, course, cfg) {
    var error = el('p', 'agend-lms-detail__note is-error');
    error.style.display = 'none';

    function showError(message) {
      error.style.display = '';
      error.textContent = message;
    }

    // Cart mode: same product line the shop's own Add to Cart widget uses.
    if (cfg.cartEnabled) {
      var addBtn = el('button', 'agend-lms-detail__cta', 'Add to Cart');
      addBtn.addEventListener('click', function () {
        addBtn.disabled = true;
        addBtn.textContent = 'Adding…';
        error.style.display = 'none';
        cartAddItem('courses', course.id, 1).then(function () {
          document.dispatchEvent(new CustomEvent('agend:cart:updated'));
          addBtn.textContent = 'Added to Cart ✓';
        }).catch(function (err) {
          addBtn.disabled = false;
          addBtn.textContent = 'Add to Cart';
          showError((err && err.message) || 'Unable to add to cart. Please try again.');
        });
      });
      panel.appendChild(addBtn);
      panel.appendChild(error);
      return;
    }

    // No shop cart: a configured "sign in" URL doubles as the member's
    // account/enrolment link when they are already signed in.
    var linkUrl = (cfg.enrol && cfg.enrol.signInUrl) || (window.agendApps && window.agendApps.loginUrl) || '';
    if (linkUrl) {
      var link = el('a', 'agend-lms-detail__cta', 'Enrol Now');
      link.href = linkUrl;
      panel.appendChild(link);
      return;
    }

    // Otherwise fall back to the member portal hand-off (the same mechanism
    // the Member Login and Account Link widgets use): mint a single-use
    // signed-in portal link and send the member there to complete enrolment.
    var portalBtn = el('button', 'agend-lms-detail__cta', 'Enrol Now');
    portalBtn.addEventListener('click', function () {
      portalBtn.disabled = true;
      portalBtn.textContent = 'Working…';
      error.style.display = 'none';
      apiPost('/auth/portal-handoff', {}).then(function (body) {
        var data = unwrapOne(body);
        var url = data && data.url;
        if (!url) {
          throw new Error((data && data.message) || 'The portal sign-in link could not be created.');
        }
        window.location.assign(url);
      }).catch(function (err) {
        portalBtn.disabled = false;
        portalBtn.textContent = 'Enrol Now';
        showError((err && err.message) || 'Unable to open the member portal. Please try again.');
      });
    });
    panel.appendChild(portalBtn);
    panel.appendChild(error);
  }

  // -- Enrolment gate -------------------------------------------------------

  // A course enrolment grants a specific learner login-gated access, so unlike
  // an event ticket it cannot be completed anonymously: it needs a member
  // identity. Until the SSO bearer worker lands (addendum E-11) the widget
  // hands the visitor to member sign-in rather than presenting a dead-end
  // guest form. A configured sign-in URL wins; otherwise fall back to the WP
  // login with a return to this course's deep link.
  function memberSignInUrl(cfg, course) {
    if (cfg.enrol && cfg.enrol.signInUrl) {
      return cfg.enrol.signInUrl;
    }
    if (window.agendApps && window.agendApps.loginUrl) {
      return window.agendApps.loginUrl;
    }
    var ret = window.location.href;
    try {
      var u = new URL(window.location.href);
      u.searchParams.set(DEEP_LINK_PARAM, course.slug);
      ret = u.toString();
    } catch (e) {
      /* URL API unavailable — return the raw href */
    }
    return '/wp-login.php?redirect_to=' + encodeURIComponent(ret);
  }

  function renderEnrolGate(course, cfg, onBack) {
    var wrap = el('div', 'agend-lms-detail');

    var back = el('button', 'agend-lms-detail__back', '← Back to Course');
    back.addEventListener('click', onBack);
    wrap.appendChild(back);

    var panel = el('div', 'agend-lms-gate');
    panel.appendChild(el('div', 'agend-lms-gate__icon', '🔒'));
    panel.appendChild(el('h2', 'agend-lms-gate__title', 'Enrol in ' + (course.title || 'this course')));

    var price = priceLabel(course);
    var priceRow = el('div', 'agend-lms-gate__price');
    priceRow.appendChild(el('span', null, 'Price'));
    priceRow.appendChild(el('span', 'agend-lms-gate__price-value' + (price === 'Free' ? ' is-free' : ''), price));
    panel.appendChild(priceRow);

    panel.appendChild(el('p', 'agend-lms-gate__text', 'Enrolment is available to members. Sign in to enrol and track your progress in your learning portal.'));

    var signIn = el('a', 'agend-lms-detail__cta agend-lms-gate__cta', 'Sign in to enrol');
    signIn.href = memberSignInUrl(cfg, course);
    panel.appendChild(signIn);

    wrap.appendChild(panel);
    return wrap;
  }

  // -- Filters + pagination -------------------------------------------------

  function buildFilterBar(root, cfg, state, reload) {
    if (!cfg.filters.search && !cfg.filters.category && !cfg.filters.difficulty && !cfg.filters.deliveryMode) {
      return;
    }
    var bar = el('div', 'agend-lms-filterbar');
    var exclusions = cfg.exclusions || {};

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
      var excludedCategories = exclusions.categories || [];
      // Course categories are free-text on the course; derive the distinct set
      // from a wide fetch. Excluded categories can never match, so hide them.
      apiGet('/lms/courses', { limit: 100 }).then(function (body) {
        var seen = {};
        unwrapList(body).items.forEach(function (course) {
          if (course.category && !seen[course.category] && excludedCategories.indexOf(course.category) === -1) {
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
      var excludedDifficulties = exclusions.difficulties || [];
      [['All Levels', ''], ['Beginner', 'beginner'], ['Intermediate', 'intermediate'], ['Advanced', 'advanced']].forEach(function (o) {
        if (o[1] && excludedDifficulties.indexOf(o[1]) !== -1) {
          return;
        }
        difficulty.appendChild(new Option(o[0], o[1]));
      });
      difficulty.addEventListener('change', function () {
        state.difficulty = difficulty.value;
        state.page = 1;
        reload();
      });
      bar.appendChild(difficulty);
    }

    if (cfg.filters.deliveryMode) {
      var mode = el('select', 'agend-lms-filter');
      var excludedModes = exclusions.deliveryModes || [];
      [['All Formats', ''], ['Self-paced', 'self_paced'], ['Live Online', 'live_online'], ['In-Person', 'in_person'], ['Blended', 'blended']].forEach(function (o) {
        if (o[1] && excludedModes.indexOf(o[1]) !== -1) {
          return;
        }
        mode.appendChild(new Option(o[0], o[1]));
      });
      mode.addEventListener('change', function () {
        state.deliveryMode = mode.value;
        state.page = 1;
        reload();
      });
      bar.appendChild(mode);
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

  // bindsUrl: only the first catalogue instance in document order reads
  // filter/deep-link state from window.location and writes it back via the
  // history API (Decision 2.6); later instances keep that state in memory
  // only.
  function initWidget(root, bindsUrl) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-courses-config'));
    } catch (e) {
      return;
    }

    var state = { search: '', category: '', difficulty: '', deliveryMode: '', page: 1, append: false };
    // The filter template is rendered server-side inside the widget; take it
    // out of the root before the catalogue view replaces the markup, so the
    // same controls survive both the listing and the detail view.
    var serverFilters = root.querySelector('.agend-lms-filter-slot');
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
      catalogueEl = el('div', 'agend-lms-catalogue');
      while (root.firstChild) {
        catalogueEl.appendChild(root.firstChild);
      }
      status = catalogueEl.querySelector('.agend-lms-status') || el('div', 'agend-lms-status');
      grid = catalogueEl.querySelector('.agend-lms-grid') || el('div', 'agend-lms-grid');
      pager = catalogueEl.querySelector('.agend-lms-pager-slot') || el('div', 'agend-lms-pager-slot');
      mountFilters(catalogueEl.querySelector('.agend-lms-filter-slot') || catalogueEl);
      grid.addEventListener('click', onTemplatedCardClick);
    } else {

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
    mountFilters(catalogueEl);
    catalogueEl.appendChild(status);
    catalogueEl.appendChild(grid);
    catalogueEl.appendChild(pager);
    }

    // Build the canonical detail URL for a slug. The dedicated Courses page
    // (cfg.detailBase) takes priority over everything else: pretty path
    // (/{page}/course/{slug}/) when permalinks are on, else the legacy
    // ?agend_course= query param on that page. With no dedicated page
    // configured, falls back to the host page path (US-1.3), and finally to
    // the legacy query param on the current URL.
    function deepLinkUrl(slug) {
      if (cfg.detailBase) {
        var detailBase = cfg.detailBase;
        if (detailBase.charAt(detailBase.length - 1) !== '/') {
          detailBase += '/';
        }
        if (cfg.prettyLinks) {
          return detailBase + 'course/' + encodeURIComponent(slug) + '/';
        }
        var sep = detailBase.indexOf('?') === -1 ? '?' : '&';
        return detailBase + sep + DEEP_LINK_PARAM + '=' + encodeURIComponent(slug);
      }
      if (cfg.prettyLinks && cfg.basePath) {
        var base = cfg.basePath;
        if (base.charAt(base.length - 1) !== '/') {
          base += '/';
        }
        return base + 'course/' + encodeURIComponent(slug) + '/';
      }
      var u = new URL(window.location.href);
      u.searchParams.set(DEEP_LINK_PARAM, slug);
      return u.toString();
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
      if (!bindsUrl) {
        return;
      }
      try {
        var target;
        if (slug) {
          target = deepLinkUrl(slug);
        } else if (cfg.prettyLinks && cfg.basePath) {
          // Returning to the catalogue: drop the /course/{slug}/ path segment.
          target = cfg.basePath;
        } else {
          var url = new URL(window.location.href);
          url.searchParams.delete(DEEP_LINK_PARAM);
          target = url.toString();
        }
        window.history.pushState({ agendCourse: slug || null }, '', target);
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
      root.appendChild(renderDetailSkeleton());
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
        root.appendChild(renderDetail(
          course,
          cfg,
          function () { showCatalogue(true); },
          function (c) { showEnrolGate(c); }
        ));
      }).catch(function () {
        root.innerHTML = '';
        root.appendChild(renderNotFound(function () { showCatalogue(true); }));
      });
    }

    function showEnrolGate(course) {
      root.innerHTML = '';
      root.appendChild(renderEnrolGate(course, cfg, function () { showDetail(course.slug, false); }));
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
        difficulty: state.difficulty,
        deliveryMode: state.deliveryMode,
        excludeCategories: exclusions.categories || [],
        excludeDifficulties: exclusions.difficulties || [],
        excludeDeliveryModes: exclusions.deliveryModes || [],
      };
      (templated ? fragmentGet(params) : apiGet('/lms/courses', params)).then(function (body) {
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
          status.textContent = 'No courses found.';
          return;
        }
        if (templated) {
          insertFragments(result.items);
        } else {
          result.items.forEach(function (course) {
            grid.appendChild(renderCard(course, cfg, function (slug) { openItem(slug); }));
          });
        }
        renderPagination(pager, cfg, state, result.pagination, reloadCatalogue);
      }).catch(function () {
        if (!state.append) {
          grid.innerHTML = '';
        }
        status.style.display = '';
        status.textContent = 'Unable to load courses.';
      });
    }

    function currentDeepLink() {
      try {
        // Pretty path form: /{page}/course/{slug}/ (US-1.3).
        if (cfg.prettyLinks) {
          var m = window.location.pathname.match(/\/course\/([^/]+)\/?$/);
          if (m && m[1]) {
            return decodeURIComponent(m[1]);
          }
        }
        // Legacy fallback: ?agend_course= query param.
        return new URL(window.location.href).searchParams.get(DEEP_LINK_PARAM);
      } catch (e) {
        return null;
      }
    }

    if (bindsUrl) {
      window.addEventListener('popstate', function () {
        var slug = currentDeepLink();
        if (slug) {
          showDetail(slug, false);
        } else {
          showCatalogue(false);
        }
      });
    }

    // Server-injected slug (from the rewrite endpoint) wins on first load, then
    // fall back to parsing the URL (pretty path or legacy query param) — only
    // for the instance that binds to the URL (Decision 2.6).
    var deepLinkSlug = cfg.deepLink || (bindsUrl && currentDeepLink());
    if (templated) {
      renderPagination(pager, cfg, state, cfg.initialPagination || null, reloadCatalogue);
      if (deepLinkSlug) {
        showDetail(deepLinkSlug, false);
      } else {
        showCatalogue(false);
      }
      if (cfg.initialError) {
        reloadCatalogue();
      }
    } else if (deepLinkSlug) {
      showDetail(deepLinkSlug, false);
      reloadCatalogue();
    } else {
      showCatalogue(false);
      reloadCatalogue();
    }
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-courses-catalogue[data-agend-courses-config]');
    Array.prototype.forEach.call(nodes, function (root, index) {
      initWidget(root, index === 0);
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
