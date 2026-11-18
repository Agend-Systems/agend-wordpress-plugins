/**
 * Credential check.
 *
 * Posts a certificate or badge code to the Agend Apps Core `lms/credentials/verify`
 * route and draws the answer. The route decides everything: what the code may
 * be, how many checks a visitor may make, whether the captcha passed, and what
 * the record may say. This script only collects a code, gets a captcha token,
 * and shows one of the states below.
 *
 * Everything from the route is drawn with textContent and element properties,
 * never as markup, so nothing the learning record holds can become HTML. The
 * only colour taken from a result is a hex value that matches a strict pattern,
 * and the only picture an https address.
 *
 * States: checking, verified (certificate or badge), no longer valid, no
 * credential found, code looks wrong, too many checks, security check failed,
 * could not check. "Could not check" is never shown as "not found".
 *
 * A link with ?code= (or ?agend_code=) fills the box, and checks straight away
 * when the page is set to and the captcha can be passed without the visitor
 * doing anything.
 */
(function () {
  'use strict';

  var SVG_NS = 'http://www.w3.org/2000/svg';
  var HEX_COLOUR = /^#[0-9a-fA-F]{3,8}$/;
  var HTTPS_URL = /^https:\/\//i;
  var AUTO_WAIT_MS = 10000;

  function parseConfig(root) {
    try {
      return JSON.parse(root.getAttribute('data-agend-credential-config') || '{}');
    } catch (e) {
      return {};
    }
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null && text !== '') {
      node.textContent = String(text);
    }
    return node;
  }

  function clear(node) {
    while (node.firstChild) {
      node.removeChild(node.firstChild);
    }
  }

  function tick() {
    var svg = document.createElementNS(SVG_NS, 'svg');
    svg.setAttribute('class', 'agend-credential-check__tick');
    svg.setAttribute('width', '22');
    svg.setAttribute('height', '22');
    svg.setAttribute('viewBox', '0 0 22 22');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');
    var disc = document.createElementNS(SVG_NS, 'circle');
    disc.setAttribute('cx', '11');
    disc.setAttribute('cy', '11');
    disc.setAttribute('r', '11');
    disc.setAttribute('fill', 'currentColor');
    var mark = document.createElementNS(SVG_NS, 'path');
    mark.setAttribute('d', 'M6.2 11.4 9.6 14.8 15.8 7.8');
    mark.setAttribute('fill', 'none');
    // A style property, not an attribute: only a property can read a CSS variable.
    mark.style.stroke = 'var(--agend-cc-heading, #1e2a4a)';
    mark.setAttribute('stroke-width', '2.2');
    mark.setAttribute('stroke-linecap', 'round');
    mark.setAttribute('stroke-linejoin', 'round');
    svg.appendChild(disc);
    svg.appendChild(mark);
    return svg;
  }

  /** The code a link carries: the configured name first, then the two the plugin always accepts. */
  function codeFromLink(cfg) {
    var query;
    try {
      query = new URLSearchParams(window.location.search);
    } catch (e) {
      return '';
    }
    var names = [cfg.param, 'agend_code', 'code'];
    for (var i = 0; i < names.length; i++) {
      var value = names[i] ? query.get(names[i]) : null;
      if (value && value.trim()) {
        return value.trim();
      }
    }
    return '';
  }

  /** "in 12 seconds", or "in 3 minutes" once the wait is long enough that seconds are noise. */
  function wait(text, seconds, t) {
    var secs = Math.max(1, Math.min(3600, parseInt(seconds, 10) || 30));
    var phrase = secs <= 90 ? secs + ' ' + (t.unitSeconds || 'seconds') : Math.ceil(secs / 60) + ' ' + (t.unitMinutes || 'minutes');
    return String(text || '')
      .replace(/\{seconds\}\s+seconds/i, phrase)
      .replace(/\{seconds\}/g, String(secs));
  }

  function init(root) {
    if (root.getAttribute('data-agend-credential-ready') === '1') {
      return;
    }
    root.setAttribute('data-agend-credential-ready', '1');

    // The editor preview draws the form but never submits it.
    if (root.getAttribute('data-agend-credential-preview') === '1') {
      return;
    }

    var cfg = parseConfig(root);
    var t = cfg.text || {};
    var show = cfg.show || {};
    var form = root.querySelector('form');
    var input = root.querySelector('[data-agend-credential-input]');
    var button = root.querySelector('[data-agend-credential-submit]');
    var result = root.querySelector('[data-agend-credential-result]');
    var live = root.querySelector('[data-agend-credential-live]');
    var holder = root.querySelector('[data-agend-credential-captcha]');

    if (!form || !input || !result || !cfg.endpoint) {
      return;
    }

    var captcha = window.AgendAppsCaptcha ? window.AgendAppsCaptcha.setup(holder, cfg.captcha) : null;
    var busy = false;

    // Sentence for screen readers, set in a live region of its own so the whole
    // result card is not read out. The card is next in the page for anyone who
    // wants the detail.
    function announce(text) {
      if (live) {
        live.textContent = text || '';
      }
    }

    function setBusy(on) {
      busy = on;
      form.setAttribute('aria-busy', on ? 'true' : 'false');
      result.setAttribute('aria-busy', on ? 'true' : 'false');
      if (button) {
        button.setAttribute('aria-disabled', on ? 'true' : 'false');
        button.classList.toggle('is-busy', on);
      }
    }

    function showMessage(kind, title, text, extra) {
      clear(result);
      var box = el('div', 'agend-credential-check__message agend-credential-check__message--' + kind);
      box.appendChild(el('p', 'agend-credential-check__message-title', title));
      if (text) {
        box.appendChild(el('p', 'agend-credential-check__message-text', text));
      }
      if (extra) {
        box.appendChild(extra);
      }
      result.appendChild(box);
      announce([title, text].filter(Boolean).join('. '));
    }

    function helpLink() {
      var help = cfg.help || {};
      if (!help.url || !help.label) {
        return null;
      }
      var p = el('p', 'agend-credential-check__message-help');
      var a = el('a', 'agend-credential-check__link exclude-agend-theme-link', help.label);
      a.href = help.url;
      p.appendChild(a);
      return p;
    }

    function row(list, label, value) {
      if (!value) {
        return;
      }
      var wrap = el('div', 'agend-credential-check__row');
      wrap.appendChild(el('dt', 'agend-credential-check__term', label));
      var dd = el('dd', 'agend-credential-check__detail');
      if (typeof value === 'string') {
        dd.textContent = value;
      } else {
        dd.appendChild(value);
      }
      wrap.appendChild(dd);
      list.appendChild(wrap);
    }

    function dateNode(label, iso) {
      if (!label) {
        return '';
      }
      var node = el('time', '', label);
      if (iso && /^\d{4}-\d{2}-\d{2}$/.test(iso)) {
        node.setAttribute('datetime', iso);
      }
      return node;
    }

    function chips(topics) {
      var list = el('ul', 'agend-credential-check__chips');
      list.setAttribute('role', 'list');
      topics.forEach(function (topic) {
        if (typeof topic === 'string' && topic) {
          list.appendChild(el('li', 'agend-credential-check__chip', topic));
        }
      });
      return list.firstChild ? list : '';
    }

    function badgePicture(data) {
      if (!show.image || !data.image || !HTTPS_URL.test(data.image)) {
        return null;
      }
      var frame = el('div', 'agend-credential-check__picture');
      if (data.colour && HEX_COLOUR.test(data.colour)) {
        frame.style.setProperty('--agend-cc-badge', data.colour);
      }
      var img = document.createElement('img');
      img.className = 'agend-credential-check__picture-img';
      // The name is beside it, so the picture adds nothing to a screen reader.
      img.alt = '';
      img.width = 72;
      img.height = 72;
      img.decoding = 'async';
      img.loading = 'lazy';
      img.referrerPolicy = 'no-referrer';
      img.addEventListener('error', function () {
        if (frame.parentNode) {
          frame.parentNode.removeChild(frame);
        }
      });
      img.src = data.image;
      frame.appendChild(img);
      return frame;
    }

    function showCredential(data) {
      var ended = data.status === 'ended';
      var isBadge = data.kind === 'badge';
      clear(result);

      var card = el('div', 'agend-credential-check__card agend-credential-check__card--' + (ended ? 'ended' : 'valid'));

      var head = el('div', 'agend-credential-check__card-head');
      var status = el('p', 'agend-credential-check__status');
      if (!ended) {
        status.appendChild(tick());
      }
      status.appendChild(el('span', 'agend-credential-check__status-text', ended ? t.ended : t.validTitle));
      head.appendChild(status);
      if (t.checkedText) {
        head.appendChild(el('p', 'agend-credential-check__source', t.checkedText));
      }
      card.appendChild(head);

      var body = el('div', 'agend-credential-check__card-body');
      var picture = isBadge ? badgePicture(data) : null;
      if (picture) {
        body.appendChild(picture);
      }

      var text = el('div', 'agend-credential-check__card-text');
      text.appendChild(el('p', 'agend-credential-check__kind', isBadge ? t.badge : t.certificate));
      text.appendChild(el('h3', 'agend-credential-check__title', data.title || t.unknownCourse));

      var list = el('dl', 'agend-credential-check__rows');
      if (isBadge) {
        if (show.course) {
          row(list, t.rowCourse, data.course);
        }
        if (show.issued) {
          row(list, t.rowEarned, dateNode(data.issued, data.issued_iso));
        }
        if (show.cpd) {
          row(list, t.rowCpd, data.cpd);
        }
        if (show.holder) {
          row(list, t.rowHolder, data.holder);
        }
      } else {
        if (show.number) {
          row(list, t.rowNumber, data.number);
        }
        if (show.issued) {
          row(list, t.rowIssued, dateNode(data.issued, data.issued_iso));
        }
        if (show.cpd) {
          row(list, t.rowCpd, data.cpd);
        }
        if (show.instructor) {
          row(list, t.rowInstructor, data.instructor);
        }
        if (show.topics && data.topics && data.topics.length) {
          row(list, t.rowTopics, chips(data.topics));
        }
      }
      if (data.valid_until) {
        row(list, t.validUntil, data.valid_until);
      }
      if (show.checkedAt) {
        row(list, t.rowChecked, data.checked);
      }
      if (list.firstChild) {
        text.appendChild(list);
      }
      body.appendChild(text);
      card.appendChild(body);

      var notes = [ended ? t.endedNote : t.validNote];
      if (!isBadge && !ended) {
        notes.push(t.holderNote);
      }
      notes = notes.filter(Boolean);
      if (notes.length) {
        var foot = el('div', 'agend-credential-check__card-foot');
        notes.forEach(function (note) {
          foot.appendChild(el('p', 'agend-credential-check__note', note));
        });
        card.appendChild(foot);
      }

      result.appendChild(card);
      announce((ended ? t.ended : t.validTitle) + '. ' + (data.title || t.unknownCourse));
    }

    function showResult(http, data) {
      var status = data && typeof data.status === 'string' ? data.status : '';

      if (status === 'valid' || status === 'ended') {
        input.removeAttribute('aria-invalid');
        showCredential(data);
        return;
      }

      if (status === 'not_found') {
        input.removeAttribute('aria-invalid');
        showMessage('not-found', t.notFound, t.notFoundHelp, helpLink());
        return;
      }

      if (status === 'invalid') {
        input.setAttribute('aria-invalid', 'true');
        var reasons = { empty: t.invalidEmpty, uuid: t.invalidUuid, badges_unsupported: t.invalidBadges };
        showMessage('invalid', reasons[data.reason] || t.invalid, '');
        input.focus();
        return;
      }

      input.removeAttribute('aria-invalid');

      if (status === 'busy' || http === 429) {
        showMessage('busy', wait(t.busy, data && data.retry_after, t), '');
        return;
      }

      if (status === 'captcha_failed') {
        showMessage('captcha', t.captcha, '');
        return;
      }

      // Anything else, including a refusal that is not ours, is "could not
      // check". It is never presented as "no credential found".
      showMessage('unavailable', t.unavailable, '');
    }

    function send(code, token) {
      var body = { code: code };
      if (token) {
        body.captcha_token = token;
      }
      if (cfg.holderKey) {
        body.holder_key = cfg.holderKey;
      }
      return fetch(cfg.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body),
      }).then(function (res) {
        return res
          .json()
          .catch(function () {
            return {};
          })
          .then(function (json) {
            return { http: res.status, json: json || {} };
          });
      });
    }

    /**
     * One check. `auto` is a check started by the page link rather than by the
     * visitor: it waits for a captcha token that needs nothing from the visitor,
     * and quietly stops if there is none.
     */
    function check(auto) {
      if (busy) {
        return;
      }

      var code = String(input.value || '').trim();

      if (!code) {
        showResult(400, { status: 'invalid', reason: 'empty' });
        return;
      }

      setBusy(true);
      clear(result);
      result.appendChild(el('p', 'agend-credential-check__checking', t.checking));
      announce(t.checking);

      var scriptFailed = false;
      var tokenPromise = captcha ? (auto ? captcha.tokenWhenReady(AUTO_WAIT_MS) : captcha.token()) : Promise.resolve('');

      tokenPromise
        .catch(function () {
          // The provider's script did not load or ran into an error.
          scriptFailed = true;
          return '';
        })
        .then(function (token) {
          if (captcha && !token) {
            var e = new Error('no captcha token');
            e.captcha = true;
            e.loadFailed = scriptFailed;
            e.auto = auto;
            throw e;
          }
          return send(code, token);
        })
        .then(function (answer) {
          showResult(answer.http, answer.json);
        })
        .catch(function (err) {
          if (err && err.captcha && err.auto && !err.loadFailed) {
            // The page link started this, the provider wanted something from
            // the visitor, and they have not seen a button yet. Leave the code
            // in the box and let them press it.
            clear(result);
            announce('');
            return;
          }
          if (err && err.loadFailed) {
            showMessage('captcha', t.captchaLoad, '');
          } else if (err && err.captcha) {
            showMessage('captcha', t.challenge, '');
          } else {
            showMessage('unavailable', t.unavailable, '');
          }
        })
        .then(function () {
          // A token is single use, whatever happened.
          if (captcha) {
            captcha.reset();
          }
          setBusy(false);
        });
    }

    input.addEventListener('input', function () {
      input.removeAttribute('aria-invalid');
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      check(false);
    });

    var linked = codeFromLink(cfg);

    if (linked) {
      input.value = linked;

      // The checkbox captcha cannot be passed by a script, so the visitor
      // presses the button themselves. Every other provider is passed quietly.
      if (cfg.autoCheck && (!captcha || captcha.silent)) {
        check(true);
      }
    }
  }

  function initAll() {
    var roots = document.querySelectorAll('.agend-credential-check');
    for (var i = 0; i < roots.length; i++) {
      init(roots[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  // Elementor redraws a widget in the editor and when a popup opens.
  window.addEventListener('elementor/frontend/init', function () {
    if (window.elementorFrontend && window.elementorFrontend.hooks) {
      window.elementorFrontend.hooks.addAction('frontend/element_ready/agend-credential-check.default', function ($el) {
        var node = $el && $el[0] ? $el[0] : $el;
        if (node && node.querySelector) {
          var root = node.querySelector('.agend-credential-check');
          if (root) {
            init(root);
          }
        }
      });
    }
  });
})();
