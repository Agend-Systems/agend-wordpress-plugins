/**
 * Member Summary: places a signed-in member's own cards on the page.
 *
 * The page holds nothing about the member. This asks agend-apps/v1/member/summary
 * for the cards, which the server draws and escapes, and puts them in the
 * shell. It also does the waiting: right after a first sign-in the member's
 * membership may still be connecting, so it asks again at growing intervals for
 * about thirty seconds and then asks once more, telling the server it has
 * waited as long as it will, so the server can say so and offer a retry.
 *
 * It never reads window.agendApps.loggedIn, which is false for every member who
 * signs in with WordPress or SSO; the server's view of the session is the only
 * one used, and a visitor who turns out not to be signed in is sent the
 * sign-in card by the same route.
 *
 * The markup is passed through DOMPurify before it is placed, though the server
 * has escaped everything in it. If DOMPurify is missing nothing is placed.
 */
(function () {
  'use strict';

  // How long to wait before each further ask while a connection is being made:
  // 3, 3, 5, 5, 7 and 7 seconds, thirty in all.
  var WAITS = [3000, 3000, 5000, 5000, 7000, 7000];
  // Longest the route is given to answer. It may wait on Agend and on a token
  // being minted.
  var TIMEOUT = 25000;

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text) {
      node.textContent = text;
    }
    return node;
  }

  function nonce() {
    return (window.agendApps && window.agendApps.nonce) || '';
  }

  function readConfig(shell) {
    try {
      var config = JSON.parse(shell.getAttribute('data-agend-member-summary-config') || '');
      return config && typeof config === 'object' ? config : null;
    } catch (e) {
      return null;
    }
  }

  function clean(html) {
    if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
      return window.DOMPurify.sanitize(html, { USE_PROFILES: { html: true, svg: true } });
    }
    return null;
  }

  /**
   * Says it could not load, with a way to try again, or to reload when the page
   * is so old that its sign-in check has run out.
   */
  function fail(shell, config, reload) {
    var body = shell.querySelector('[data-agend-ms-body]');
    var messages = (config && config.messages) || {};
    var notice = el('div', 'agend-ms-notice agend-ms-notice--unavailable');
    var text = el('div', 'agend-ms-notice__text');
    var actions = el('p', 'agend-ms-actions');
    var button = el('button', 'agend-ms-button agend-ms-button--quiet', reload ? messages.reloadLabel : messages.retry);

    notice.setAttribute('data-agend-ms-state', 'unavailable');
    notice.appendChild(el('span', 'agend-ms-notice__mark'));
    notice.firstChild.setAttribute('aria-hidden', 'true');
    text.appendChild(el('h3', 'agend-ms-notice__title', messages.unavailableTitle || ''));
    text.appendChild(el('p', 'agend-ms-notice__message', reload ? messages.reload : messages.unavailable));
    button.type = 'button';
    if (reload) {
      button.addEventListener('click', function () {
        window.location.reload();
      });
    } else {
      button.setAttribute('data-agend-ms-retry', '');
    }
    actions.appendChild(button);
    text.appendChild(actions);
    notice.appendChild(text);

    body.textContent = '';
    body.appendChild(notice);
    body.setAttribute('aria-busy', 'false');
  }

  function start(shell) {
    var config = readConfig(shell);
    var body = shell.querySelector('[data-agend-ms-body]');

    if (!config || !body || shell.getAttribute('data-agend-ms-started')) {
      return;
    }
    shell.setAttribute('data-agend-ms-started', '1');

    var skeleton = body.innerHTML;
    var run = 0;
    var timer = null;

    function load(attempt, settled) {
      var mine = ++run;

      if (typeof window.fetch !== 'function') {
        fail(shell, config, false);
        return;
      }

      var controller = typeof window.AbortController === 'function' ? new window.AbortController() : null;
      var aborter = controller ? window.setTimeout(function () { controller.abort(); }, TIMEOUT) : null;

      window
        .fetch(config.route, {
          method: 'POST',
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-WP-Nonce': nonce(),
          },
          body: JSON.stringify({
            settings: config.settings || {},
            return_url: window.location.href,
            settled: !!settled,
          }),
          signal: controller ? controller.signal : undefined,
        })
        .then(function (response) {
          return response.json().then(
            function (data) { return { ok: response.ok, data: data }; },
            function () { return { ok: false, data: null }; }
          );
        })
        .then(function (result) {
          if (mine !== run) {
            return; // A newer ask has taken over.
          }

          var data = result.data;

          if (!result.ok || !data || typeof data.html !== 'string') {
            // A stale page: the sign-in check in its script has run out.
            fail(shell, config, !!(data && data.code === 'rest_cookie_invalid_nonce'));
            return;
          }

          var html = clean(data.html);

          if (html === null) {
            fail(shell, config, false);
            return;
          }

          body.innerHTML = html;
          body.setAttribute('aria-busy', 'false');

          if (data.state === 'connecting' && !settled) {
            if (attempt < WAITS.length) {
              timer = window.setTimeout(function () { load(attempt + 1, false); }, WAITS[attempt]);
            } else {
              timer = window.setTimeout(function () { load(attempt + 1, true); }, 0);
            }
          }
        })
        .catch(function () {
          if (mine === run) {
            fail(shell, config, false);
          }
        })
        .then(function () {
          if (aborter) {
            window.clearTimeout(aborter);
          }
        });
    }

    shell.addEventListener('click', function (event) {
      var target = event.target && event.target.closest ? event.target.closest('[data-agend-ms-retry]') : null;

      if (!target || !shell.contains(target)) {
        return;
      }

      event.preventDefault();
      window.clearTimeout(timer);
      body.innerHTML = skeleton;
      body.setAttribute('aria-busy', 'true');
      load(0, false);
    });

    load(0, false);
  }

  function init() {
    var shells = document.querySelectorAll('[data-agend-member-summary-config]');

    for (var i = 0; i < shells.length; i++) {
      start(shells[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
