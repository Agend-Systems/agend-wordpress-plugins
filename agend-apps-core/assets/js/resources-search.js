/**
 * Agend Resources: search.
 *
 * The page holds a plain search form that sends a visitor to the association's
 * public search page, and that works with no script. This script upgrades it:
 * for a signed-in member it shows results in the page as they type, from the
 * member's own view of the library; for a visitor it does the same only when
 * the editor chose live results. Either way the words go to the site's own
 * search route, never to the gateway, which refuses a person who searches too
 * fast.
 *
 * Results are drawn with DOM nodes. The one exception is each result's extract,
 * which the server has already escaped and marked up (`<mark>` round the words
 * that matched).
 */
(function () {
  'use strict';

  var SELECTOR = '[data-agend-resources-search]';
  var DEBOUNCE_MS = 400;

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

  function normalise(value) {
    return String(value || '').replace(/\s+/g, ' ').replace(/^ | $/g, '');
  }

  function init(root) {
    var cfg = parse(root.getAttribute('data-agend-resources-search'));
    var form = root.querySelector('.agend-resources-search__form');
    var input = root.querySelector('.agend-resources-search__input');
    var box = root.querySelector('.agend-resources-search__results');

    if (!cfg || !form || !input || !box || typeof window.fetch !== 'function') {
      return;
    }

    var member = isSignedIn();
    var live = member || !!cfg.visitorLive;
    var messages = cfg.messages || {};

    if (member) {
      // A page held for visitors may have hidden the box when it had nowhere
      // to send them; a member has live results, so it comes back.
      root.hidden = false;
    }

    if (!live) {
      return;
    }

    var note = root.querySelector('.agend-resources-search__note');
    if (member && note) {
      note.hidden = true;
    }

    // Set when the site cannot match the signed-in user to a member: from then on
    // the box behaves as the plain form it is, and the visitor search page takes
    // over.
    var off = false;
    var timer = null;
    var sequence = 0;
    var controller = null;
    var shown = '';

    function clear() {
      box.hidden = true;
      box.textContent = '';
      box.removeAttribute('aria-busy');
      shown = '';
    }

    function say(text, error) {
      box.textContent = '';
      box.removeAttribute('aria-busy');
      box.appendChild(el('p', 'agend-resources-search__status' + (error ? ' agend-resources-search__status--error' : ''), text));
      box.hidden = false;
    }

    function loading() {
      box.textContent = '';
      box.setAttribute('aria-busy', 'true');

      var skeleton = el('div', 'agend-resources-search__skeleton');
      skeleton.appendChild(el('span', 'agend-resources-search__bar'));
      skeleton.appendChild(el('span', 'agend-resources-search__bar'));
      box.appendChild(skeleton);
      box.appendChild(el('span', 'agend-res__sr', messages.searching || ''));
      box.hidden = false;
    }

    function resultRow(result) {
      var linked = !!result.url;
      var row = el('li', 'agend-resources-search__result' + (linked ? ' agend-resources-search__result--link' : ''));
      var main = el('div', 'agend-resources-search__result-main');

      if (linked) {
        var link = el('a', 'agend-resources-search__result-link exclude-agend-theme-link', result.title);
        link.href = result.url;
        main.appendChild(link);
      } else {
        main.appendChild(el('span', 'agend-resources-search__result-title', result.title));
      }

      if (cfg.excerpt && result.excerpt_html) {
        var excerpt = el('p', 'agend-resources-search__excerpt');
        // Escaped, and marked, by the site.
        excerpt.innerHTML = result.excerpt_html;
        main.appendChild(excerpt);
      }

      row.appendChild(main);

      if (linked) {
        var icon = el('span');
        icon.innerHTML = CHEVRON;
        row.appendChild(icon.firstChild);
      }

      return row;
    }

    function show(results, query) {
      if (!results.length) {
        say(messages.none || '', false);
        return;
      }

      box.textContent = '';
      box.removeAttribute('aria-busy');

      var list = el('ul', 'agend-resources-search__list');
      list.setAttribute('role', 'list');
      for (var i = 0; i < results.length; i++) {
        list.appendChild(resultRow(results[i]));
      }
      box.appendChild(list);

      // One note for the whole list, not one under every page.
      var unlinked = false;
      for (var j = 0; j < results.length; j++) {
        if (results[j].sign_in) {
          unlinked = true;
        }
      }

      if (unlinked) {
        box.appendChild(el('p', 'agend-resources-search__sign-in', messages.signInNote || ''));
      }

      if (cfg.seeAllUrl) {
        var foot = el('p', 'agend-resources-search__foot');
        var all = el('a', 'agend-resources-search__all exclude-agend-theme-link');
        all.href = cfg.seeAllUrl + (cfg.seeAllUrl.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(query);
        all.appendChild(document.createTextNode(cfg.seeAllLabel || ''));
        var chevron = el('span');
        chevron.innerHTML = CHEVRON;
        all.appendChild(chevron.firstChild);
        foot.appendChild(all);
        box.appendChild(foot);
      }

      box.hidden = false;
    }

    function failure(status, body) {
      if (status === 429) {
        say((body && body.message) || messages.busy || messages.error || '', false);
      } else if (status === 403 && body && body.code === 'agend_apps_resources_no_member') {
        off = true;
        say(messages.notLinked || messages.error || '', true);
      } else if (status === 403) {
        // A visitor on a page that is not offering live search: nothing to show.
        clear();
      } else {
        say(messages.error || '', true);
      }
    }

    function search(raw, submitted) {
      var query = normalise(raw);

      if (off) {
        return;
      }

      window.clearTimeout(timer);

      if (query.length === 0) {
        sequence++;
        if (controller) {
          controller.abort();
        }
        clear();
        return;
      }

      if (query.length < (cfg.minChars || 3)) {
        if (submitted) {
          say(messages.tooShort || '', false);
        }
        return;
      }

      if (query === shown && !submitted) {
        return;
      }

      var mine = ++sequence;

      if (controller) {
        controller.abort();
      }
      controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;

      loading();

      var url = cfg.endpoint + (cfg.endpoint.indexOf('?') === -1 ? '?' : '&') +
        'q=' + encodeURIComponent(query) + '&limit=' + encodeURIComponent(cfg.limit || 6) +
        (cfg.visitorToken ? '&token=' + encodeURIComponent(cfg.visitorToken) : '');
      var headers = { Accept: 'application/json' };
      if (nonce()) {
        headers['X-WP-Nonce'] = nonce();
      }

      window.fetch(url, { credentials: 'same-origin', headers: headers, signal: controller ? controller.signal : undefined })
        .then(function (res) {
          return res.json().then(
            function (json) { return { status: res.status, json: json }; },
            function () { return { status: res.status, json: null }; }
          );
        })
        .then(function (result) {
          if (mine !== sequence) {
            return;
          }

          if (result.json && result.json.success && result.json.data) {
            shown = query;
            show(result.json.data.results || [], query);
          } else {
            failure(result.status, result.json);
          }
        })
        .catch(function (error) {
          if (mine !== sequence || (error && error.name === 'AbortError')) {
            return;
          }
          say(messages.error || '', true);
        });
    }

    input.addEventListener('input', function () {
      window.clearTimeout(timer);
      timer = window.setTimeout(function () {
        search(input.value, false);
      }, DEBOUNCE_MS);
    });

    input.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && !box.hidden) {
        clear();
      }
    });

    form.addEventListener('submit', function (event) {
      if (off) {
        // The plain form goes to the public search page.
        return;
      }
      event.preventDefault();
      search(input.value, true);
    });

    var chips = root.querySelectorAll('[data-agend-suggest]');
    for (var i = 0; i < chips.length; i++) {
      chips[i].addEventListener('click', function (event) {
        if (off) {
          return;
        }
        event.preventDefault();
        input.value = this.getAttribute('data-agend-suggest') || '';
        input.focus();
        search(input.value, true);
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
