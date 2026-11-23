/**
 * My Communities: places a signed-in member's own communities on the page.
 *
 * The page holds nothing about the member. This asks
 * agend-apps/v1/member/communities for the list, which the server draws and
 * escapes, and puts it in the shell.
 *
 * It never reads window.agendApps.loggedIn, which is false for every member who
 * signs in with WordPress or SSO; the server's view of the session is the only
 * one used. A page left open past its sign-in check is told to reload.
 *
 * The markup is passed through DOMPurify before it is placed, though the server
 * has escaped everything in it. If DOMPurify is missing nothing is placed. Links
 * the server sends carry target="_blank"; DOMPurify drops that attribute unless
 * it is allowed, so it is allowed here and rel is kept alongside it.
 */
(function () {
  'use strict';

  // Longest the route is given to answer. It may wait on Agend.
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
      var config = JSON.parse(shell.getAttribute('data-agend-my-communities-config') || '');
      return config && typeof config === 'object' ? config : null;
    } catch (e) {
      return null;
    }
  }

  function clean(html) {
    if (window.DOMPurify && typeof window.DOMPurify.sanitize === 'function') {
      return window.DOMPurify.sanitize(html, {
        USE_PROFILES: { html: true },
        ADD_ATTR: ['target', 'rel'],
      });
    }
    return null;
  }

  /**
   * Says it could not load, with a way to try again, or to reload when the page
   * is so old that its sign-in check has run out.
   */
  function fail(shell, config, reload) {
    var body = shell.querySelector('[data-agend-mc-body]');
    var messages = (config && config.messages) || {};
    var notice = el('div', 'agend-mc-notice agend-mc-notice--unavailable');
    var actions = el('p', 'agend-mc-actions');
    var button = el('button', 'agend-mc-button agend-mc-button--quiet', reload ? messages.reloadLabel : messages.retry);

    notice.setAttribute('data-agend-mc-state', 'unavailable');
    notice.appendChild(el('h3', 'agend-mc-notice__title', messages.unavailableTitle || ''));
    notice.appendChild(el('p', 'agend-mc-notice__message', reload ? messages.reload : messages.unavailable));
    button.type = 'button';
    if (reload) {
      button.addEventListener('click', function () {
        window.location.reload();
      });
    } else {
      button.setAttribute('data-agend-mc-retry', '');
    }
    actions.appendChild(button);
    notice.appendChild(actions);

    body.textContent = '';
    body.appendChild(notice);
    body.setAttribute('aria-busy', 'false');
  }

  function start(shell) {
    var config = readConfig(shell);
    var body = shell.querySelector('[data-agend-mc-body]');

    if (!config || !body || shell.getAttribute('data-agend-mc-started')) {
      return;
    }
    shell.setAttribute('data-agend-mc-started', '1');

    var skeleton = body.innerHTML;
    var run = 0;

    function load() {
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
          body: JSON.stringify({ settings: config.settings || {} }),
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
            fail(shell, config, !!(data && (data.code === 'rest_cookie_invalid_nonce' || data.code === 'rest_forbidden')));
            return;
          }

          var html = clean(data.html);

          if (html === null) {
            fail(shell, config, false);
            return;
          }

          body.innerHTML = html;
          body.setAttribute('aria-busy', 'false');
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
      var target = event.target && event.target.closest ? event.target.closest('[data-agend-mc-retry]') : null;

      if (!target || !shell.contains(target)) {
        return;
      }

      event.preventDefault();
      body.innerHTML = skeleton;
      body.setAttribute('aria-busy', 'true');
      load();
    });

    load();
  }

  function init() {
    var shells = document.querySelectorAll('[data-agend-my-communities-config]');

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
