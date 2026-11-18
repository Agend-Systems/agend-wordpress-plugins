/**
 * Agend Resources: Ask.
 *
 * The page holds a question box (hidden) and a sign-in prompt (shown), the same
 * for every viewer. This script swaps them when it finds a signed-in member, and
 * sends that member's questions to the site's own Ask route. Only a member can
 * ask: each answer is written on the association's monthly allowance, and the
 * route limits each member and the site before it calls the gateway.
 *
 * Everything the route returns is drawn as text or as DOM nodes: an answer is
 * plain text with `[n]` markers, which become small numbered circles matching the
 * numbered sources underneath.
 */
(function () {
  'use strict';

  var SELECTOR = '[data-agend-resources-ask]';
  var TIMEOUT_MS = 50000;

  var CHEVRON = '<svg class="agend-res__icon agend-res__icon--chevron" width="16" height="16" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 3.5 10.5 8 6 12.5"/></svg>';

  function parse(raw) {
    try {
      var cfg = JSON.parse(raw);
      return cfg && typeof cfg === 'object' ? cfg : null;
    } catch (e) {
      return null;
    }
  }

  function isSignedIn() {
    return !!(window.agendApps && window.agendApps.loggedIn) ||
      !!(document.body && document.body.classList.contains('logged-in'));
  }

  function nonce() {
    return (window.agendApps && window.agendApps.nonce) || '';
  }

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined) {
      node.textContent = text;
    }
    return node;
  }

  function chevron() {
    var holder = el('span');
    holder.innerHTML = CHEVRON;
    return holder.firstChild;
  }

  function normalise(value) {
    return String(value || '').replace(/\s+/g, ' ').replace(/^ | $/g, '');
  }

  function init(root) {
    var cfg = parse(root.getAttribute('data-agend-resources-ask'));
    var panel = root.querySelector('.agend-resources-ask__panel');
    var signedOut = root.querySelector('.agend-resources-ask__signed-out');
    var form = root.querySelector('.agend-resources-ask__form');
    var input = root.querySelector('.agend-resources-ask__input');
    var button = root.querySelector('.agend-resources-ask__button');
    var out = root.querySelector('.agend-resources-ask__answer');

    if (!cfg || !panel || !form || !input || !button || !out || typeof window.fetch !== 'function') {
      return;
    }

    if (!isSignedIn()) {
      return;
    }

    var messages = cfg.messages || {};

    root.hidden = false;
    panel.hidden = false;
    if (signedOut) {
      signedOut.hidden = true;
    }

    var busy = false;

    function show(node, error) {
      out.textContent = '';
      out.className = 'agend-resources-ask__answer' + (error ? ' agend-resources-ask__answer--error' : '');
      out.removeAttribute('aria-busy');
      out.appendChild(node);
      out.hidden = false;
    }

    function plain(text, title) {
      var wrap = el('div', 'agend-resources-ask__text');
      if (title) {
        wrap.appendChild(el('p', 'agend-resources-ask__title', title));
      }
      wrap.appendChild(el('p', '', text));
      return wrap;
    }

    function asked(question) {
      return el('p', 'agend-resources-ask__question', (messages.asked || '') + ' ' + question);
    }

    function marker(number, className) {
      var node = el('span', className, String(number));
      return node;
    }

    // Plain text in, paragraphs out, with `[n]` turned into a numbered circle.
    function paragraphs(text, withMarkers) {
      var wrap = el('div', 'agend-resources-ask__text');
      var blocks = String(text || '').split(/\n{2,}/);

      for (var i = 0; i < blocks.length; i++) {
        var block = blocks[i].replace(/^\s+|\s+$/g, '');
        if (!block) {
          continue;
        }

        var p = el('p');
        var pattern = /\[(\d{1,2})\]/g;
        var last = 0;
        var match;

        while ((match = pattern.exec(block)) !== null) {
          p.appendChild(document.createTextNode(block.slice(last, match.index)));
          if (withMarkers) {
            var circle = marker(match[1], 'agend-resources-ask__marker');
            circle.setAttribute('role', 'img');
            circle.setAttribute('aria-label', (messages.source || 'Source') + ' ' + match[1]);
            p.appendChild(circle);
          }
          last = match.index + match[0].length;
        }

        p.appendChild(document.createTextNode(block.slice(last)));
        wrap.appendChild(p);
      }

      return wrap;
    }

    function sourceRow(source) {
      var linked = !!source.url;
      var row = el('li', 'agend-resources-ask__source' + (linked ? ' agend-resources-ask__source--link' : ''));
      row.appendChild(marker(source.marker, 'agend-resources-ask__source-marker'));

      var main = el('span', 'agend-resources-ask__source-main');

      if (linked) {
        var link = el('a', 'agend-resources-ask__source-link exclude-agend-theme-link', source.title);
        link.href = source.url;
        main.appendChild(link);
      } else {
        main.appendChild(el('span', 'agend-resources-ask__source-title', source.title));
      }

      row.appendChild(main);

      if (linked) {
        row.appendChild(chevron());
      }

      return row;
    }

    function sources(list) {
      var wrap = el('div', 'agend-resources-ask__sources');
      wrap.appendChild(el('p', 'agend-resources-ask__sources-title', messages.sources || ''));

      var ol = el('ol');
      ol.setAttribute('role', 'list');
      var unlinked = false;

      for (var i = 0; i < list.length; i++) {
        ol.appendChild(sourceRow(list[i]));
        if (list[i].sign_in) {
          unlinked = true;
        }
      }

      wrap.appendChild(ol);

      if (unlinked) {
        wrap.appendChild(el('p', 'agend-resources-ask__small', messages.signInNote || ''));
      }

      return wrap;
    }

    function matches(list) {
      var wrap = el('div', 'agend-resources-ask__matches');
      wrap.appendChild(el('p', 'agend-resources-ask__sources-title', messages.matches || ''));

      var ul = el('ul');
      ul.setAttribute('role', 'list');

      for (var i = 0; i < list.length; i++) {
        var item = list[i];
        var linked = !!item.url;
        var row = el('li', 'agend-resources-search__result' + (linked ? ' agend-resources-search__result--link' : ''));
        var main = el('div', 'agend-resources-search__result-main');

        if (linked) {
          var link = el('a', 'agend-resources-search__result-link exclude-agend-theme-link', item.title);
          link.href = item.url;
          main.appendChild(link);
        } else {
          main.appendChild(el('span', 'agend-resources-search__result-title', item.title));
        }

        if (item.excerpt_html) {
          var excerpt = el('p', 'agend-resources-search__excerpt');
          // Escaped by the site.
          excerpt.innerHTML = item.excerpt_html;
          main.appendChild(excerpt);
        }

        row.appendChild(main);
        if (linked) {
          row.appendChild(chevron());
        }
        ul.appendChild(row);
      }

      wrap.appendChild(ul);
      return wrap;
    }

    function disclaimer(text) {
      return text ? el('p', 'agend-resources-ask__disclaimer', text) : null;
    }

    function contact(text) {
      if (!text) {
        return null;
      }

      var p = el('p', 'agend-resources-ask__contact');
      p.appendChild(document.createTextNode((messages.contact || '') + ' '));

      if (/^https?:\/\//i.test(text)) {
        var a = el('a', 'exclude-agend-theme-link', text);
        a.href = text;
        p.appendChild(a);
      } else {
        p.appendChild(document.createTextNode(text));
      }

      return p;
    }

    function draw(data, question) {
      var card = el('div');

      if (data.kind === 'answer') {
        card.appendChild(asked(question));
        card.appendChild(paragraphs(data.answer, cfg.sources !== false));

        if (data.confidence === 'low') {
          card.appendChild(el('p', 'agend-resources-ask__confidence', messages.lowConfidence || ''));
        }

        if (cfg.sources !== false) {
          if (data.sources && data.sources.length) {
            card.appendChild(sources(data.sources));
          } else {
            card.appendChild(el('p', 'agend-resources-ask__small', messages.noSources || ''));
          }
        }

        var small = disclaimer(data.disclaimer);
        if (small) {
          card.appendChild(small);
        }

        show(card, false);
        return;
      }

      if (data.kind === 'refusal') {
        card.appendChild(asked(question));
        card.appendChild(plain(data.message, messages.refusalTitle));
        var refusalSmall = disclaimer(data.disclaimer);
        if (refusalSmall) {
          card.appendChild(refusalSmall);
        }
        show(card, false);
        return;
      }

      if (data.kind === 'degraded') {
        card.appendChild(asked(question));
        card.appendChild(plain(data.message || messages.unavailable));
        if (data.matches && data.matches.length) {
          card.appendChild(matches(data.matches));
        }
        var way = contact(data.contact);
        if (way) {
          card.appendChild(way);
        }
        show(card, false);
        return;
      }

      if (data.kind === 'not_entitled') {
        // The account cannot answer. Say so quietly and put the box away; an
        // editor is told why.
        card.appendChild(plain(data.editor_note || messages.unavailable));
        show(card, false);
        form.hidden = true;
        var chips = root.querySelector('.agend-resources-ask__suggestions');
        if (chips) {
          chips.hidden = true;
        }
        return;
      }

      show(plain(messages.error), true);
    }

    function failure(status, body) {
      if (status === 403 && body && body.code === 'agend_apps_resources_no_member') {
        // Signed in to WordPress, but not as a member Atlas knows.
        panel.hidden = true;
        if (signedOut) {
          signedOut.hidden = false;
        }
        var note = el('p', 'agend-res__note', messages.notLinked || messages.error || '');
        root.appendChild(note);
        return;
      }

      if (status === 429) {
        show(plain((body && body.message) || messages.busy || messages.error), false);
        return;
      }

      show(plain(messages.error), true);
    }

    function ask(question) {
      if (busy) {
        return;
      }

      if (question.length < 3) {
        show(plain(messages.tooShort), true);
        return;
      }

      busy = true;
      button.disabled = true;
      button.setAttribute('aria-disabled', 'true');

      var thinking = el('div', 'agend-resources-ask__thinking');
      thinking.appendChild(asked(question));
      thinking.appendChild(el('p', '', messages.thinking || ''));
      var skeleton = el('div', 'agend-resources-ask__skeleton');
      skeleton.appendChild(el('span', 'agend-resources-ask__bar'));
      skeleton.appendChild(el('span', 'agend-resources-ask__bar'));
      thinking.appendChild(skeleton);
      show(thinking, false);
      out.setAttribute('aria-busy', 'true');

      var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
      var timer = controller ? window.setTimeout(function () { controller.abort(); }, TIMEOUT_MS) : null;

      var headers = { Accept: 'application/json', 'Content-Type': 'application/json' };
      if (nonce()) {
        headers['X-WP-Nonce'] = nonce();
      }

      window.fetch(cfg.endpoint, {
        method: 'POST',
        credentials: 'same-origin',
        headers: headers,
        body: JSON.stringify({ question: question }),
        signal: controller ? controller.signal : undefined,
      })
        .then(function (res) {
          return res.json().then(
            function (json) { return { status: res.status, json: json }; },
            function () { return { status: res.status, json: null }; }
          );
        })
        .then(function (result) {
          if (result.json && result.json.success && result.json.data) {
            draw(result.json.data, question);
          } else {
            failure(result.status, result.json);
          }
        })
        .catch(function () {
          show(plain(messages.error), true);
        })
        .then(function () {
          window.clearTimeout(timer);
          busy = false;
          button.disabled = false;
          button.removeAttribute('aria-disabled');
        });
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      ask(normalise(input.value));
    });

    // Enter asks; Shift and Enter start a new line.
    input.addEventListener('keydown', function (event) {
      if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        ask(normalise(input.value));
      }
    });

    var chips = root.querySelectorAll('[data-agend-suggest]');
    for (var i = 0; i < chips.length; i++) {
      chips[i].addEventListener('click', function () {
        input.value = this.getAttribute('data-agend-suggest') || '';
        input.focus();
        ask(normalise(input.value));
      });
    }
  }

  function boot() {
    var roots = document.querySelectorAll(SELECTOR);
    for (var i = 0; i < roots.length; i++) {
      init(roots[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
