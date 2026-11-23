/**
 * Agend Resources: collections.
 *
 * The page already holds the collections an editor chose, drawn on the server
 * and the same for every viewer, so it can be held in a full-page cache. This
 * script adds one thing for a signed-in member: it asks the site for the
 * collections that member can open and draws them after the page has loaded.
 * The answer is the same card markup the server draws, escaped there, and is
 * never cached.
 *
 * A visitor makes no request at all. A member the site cannot match to a member
 * record (a 401 or 403) quietly keeps the visitor's view.
 */
(function () {
  'use strict';

  var SELECTOR = '[data-agend-resources-collections]';

  function parse(raw) {
    try {
      var cfg = JSON.parse(raw);
      return cfg && typeof cfg === 'object' ? cfg : null;
    } catch (e) {
      return null;
    }
  }

  // The signed-in signal. The page body is built the same for every viewer, so
  // it cannot say; the site's page header can: it sets window.agendApps.signedIn
  // for a signed-in WordPress user in every member sign-in mode. This is not
  // window.agendApps.loggedIn, which is false for every member who signs in with
  // WordPress or SSO. Whether that user is a member Atlas knows is for the route
  // to say.
  function isSignedIn() {
    return !!(window.agendApps && window.agendApps.signedIn === true);
  }

  function nonce() {
    return (window.agendApps && window.agendApps.nonce) || '';
  }

  function query(cfg) {
    return [
      'topics=' + (cfg.topics ? '1' : '0'),
      'description=' + (cfg.description ? '1' : '0'),
      'excerpt=' + encodeURIComponent(cfg.excerpt || 140),
      'pages=' + encodeURIComponent(cfg.pages === 0 ? 0 : (cfg.pages || 4)),
      'level=' + encodeURIComponent(cfg.level || 4),
    ].join('&');
  }

  function note(text) {
    var p = document.createElement('p');
    p.className = 'agend-res__note';
    p.textContent = text;
    return p;
  }

  function init(root) {
    var cfg = parse(root.getAttribute('data-agend-resources-collections'));
    var mine = root.querySelector('.agend-resources-collections__mine');
    var body = root.querySelector('.agend-resources-collections__mine-body');

    if (!cfg || !mine || !body || !isSignedIn() || typeof window.fetch !== 'function') {
      return;
    }

    var headers = { Accept: 'application/json' };
    if (nonce()) {
      headers['X-WP-Nonce'] = nonce();
    }

    window.fetch(cfg.endpoint + (cfg.endpoint.indexOf('?') === -1 ? '?' : '&') + query(cfg), {
      credentials: 'same-origin',
      headers: headers,
    })
      .then(function (res) {
        return res.json().then(
          function (json) { return { status: res.status, json: json }; },
          function () { return { status: res.status, json: null }; }
        );
      })
      .then(function (result) {
        var data = result.json && result.json.success && result.json.data;

        if (data && data.count > 0) {
          // The server escaped every string in this markup.
          body.innerHTML = data.html;
          body.setAttribute('data-loaded', '1');
          mine.hidden = false;
          root.hidden = false;

          var visitor = root.querySelector('.agend-resources-collections__visitor');
          if (visitor) {
            visitor.hidden = true;
          }

          var chosen = root.querySelector('.agend-resources-collections__public');
          if (chosen && cfg.replace) {
            chosen.hidden = true;
          }
          return;
        }

        // A signed-in user who is not a linked member keeps the visitor's view,
        // and so does a member with no collections. Only a real failure says so.
        if (!data && result.status !== 401 && result.status !== 403) {
          body.appendChild(note((cfg.messages && cfg.messages.failed) || ''));
          mine.hidden = false;
          root.hidden = false;
        }
      })
      .catch(function () {
        body.appendChild(note((cfg.messages && cfg.messages.failed) || ''));
        mine.hidden = false;
        root.hidden = false;
      });
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
