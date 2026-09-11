/**
 * Agend header auth-link widget — frontend renderer.
 *
 * A single header call-to-action that adapts to the member session
 * (SPEC-CORE-20260722 US-2.8): signed out -> "Log In" linking to the configured
 * login page; signed in -> "My Portal", which hands off to the member portal
 * already authenticated via the Agend Apps Core portal-handoff proxy. The
 * signed-in/out decision reads the shared window.agendApps.loggedIn signal, so
 * a single cached header markup adapts per member with no per-page server render.
 */
(function () {
  'use strict';

  function restBase() {
    return (window.agendApps && window.agendApps.restUrl) || '/wp-json/agend-apps/v1/';
  }

  function nonce() {
    return (window.agendApps && window.agendApps.nonce) || '';
  }

  // In `wordpress` sign-in mode there is no credential session for
  // window.agendApps.loggedIn to reflect (it is populated from the
  // credential-login session store, which nothing writes to in this mode),
  // so the widget's own config carries the real WordPress session state
  // instead (docs/PLAN-wordpress-idp-option-b.md section 4.5).
  function isLoggedIn(cfg) {
    if (cfg && cfg.wordpressMode) {
      return !!cfg.signedIn;
    }
    return !!(window.agendApps && window.agendApps.loggedIn);
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

  // Hand off to the member portal already signed in: mint a single-use portal
  // sign-in URL server-side, then navigate to it. The same proxy endpoint the
  // member-login widget uses; the gateway derives the destination (the
  // account portal home) from the connected account. Falls back to the plain
  // portal URL (the link's own href) if the hand-off cannot be minted.
  function goToPortal(link, fallbackUrl) {
    var url = restBase().replace(/\/$/, '') + '/auth/portal-handoff';
    var headers = { 'X-WP-Nonce': nonce(), 'Content-Type': 'application/json' };
    fetch(url, { method: 'POST', headers: headers, body: '{}' })
      .then(function (res) {
        return res.json().then(function (body) {
          return { ok: res.ok, body: body };
        });
      })
      .then(function (r) {
        var data =
          r.body && r.body.data && !Array.isArray(r.body.data) ? r.body.data : r.body;
        var target = (r.ok && data && data.url) || fallbackUrl;
        if (target) {
          window.location.assign(target);
        } else {
          link.removeAttribute('aria-busy');
        }
      })
      .catch(function () {
        if (fallbackUrl) {
          window.location.assign(fallbackUrl);
        } else {
          link.removeAttribute('aria-busy');
        }
      });
  }

  // Signs the member out via the Agend Apps Core proxy, then reloads so the
  // fresh nonce and the signed-out markup take effect (the same flow as the
  // member-login widget's sign-out control).
  function signOut(button) {
    var url = restBase().replace(/\/$/, '') + '/auth/logout';
    var headers = { 'X-WP-Nonce': nonce(), 'Content-Type': 'application/json' };
    fetch(url, { method: 'POST', headers: headers, body: '{}' })
      .then(function () {
        window.location.reload();
      })
      .catch(function () {
        button.removeAttribute('aria-busy');
      });
  }

  // Builds the sign-out dropdown revealed on hover/focus (visibility is
  // CSS-driven via :hover / :focus-within; the listeners here only keep
  // aria-expanded in sync for assistive tech).
  function buildMenu(root, link, cfg) {
    root.classList.add('agend-header-auth--has-menu');
    link.setAttribute('aria-haspopup', 'true');
    link.setAttribute('aria-expanded', 'false');

    var menu = el('div', 'agend-header-auth__menu');
    var item = el('button', 'agend-header-auth__menu-item', cfg.signOutLabel);
    item.type = 'button';
    item.addEventListener('click', function () {
      if (item.getAttribute('aria-busy') === 'true') {
        return;
      }
      item.setAttribute('aria-busy', 'true');
      if (cfg.wordpressMode) {
        // No `/auth/logout` proxy route exists in this mode; sign out is a
        // plain navigation to WordPress's own logout URL.
        window.location.assign(cfg.logoutUrl || '/');
      } else {
        signOut(item);
      }
    });
    menu.appendChild(item);

    var setExpanded = function (expanded) {
      link.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    };
    root.addEventListener('mouseenter', function () {
      setExpanded(true);
    });
    root.addEventListener('mouseleave', function () {
      setExpanded(false);
    });
    root.addEventListener('focusin', function () {
      setExpanded(true);
    });
    root.addEventListener('focusout', function (event) {
      if (!root.contains(event.relatedTarget)) {
        setExpanded(false);
      }
    });

    return menu;
  }

  function render(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-header-auth-config'));
    } catch (e) {
      return;
    }

    root.innerHTML = '';
    root.classList.remove('agend-header-auth--has-menu');
    var link = el('a', 'agend-header-auth__link');
    var loggedIn = isLoggedIn(cfg);

    if (loggedIn) {
      link.textContent = cfg.loggedInLabel || 'My Portal';
      // The href is the plain portal URL. In the default (credential-login)
      // mode this is a fallback and the click prefers an authenticated
      // hand-off; in `wordpressMode` there is no hand-off route to prefer,
      // so it is the actual destination and plain navigation is enough.
      link.href = cfg.portalUrl || '#';
      if (!cfg.wordpressMode) {
        link.addEventListener('click', function (event) {
          event.preventDefault();
          if (link.getAttribute('aria-busy') === 'true') {
            return;
          }
          link.setAttribute('aria-busy', 'true');
          goToPortal(link, cfg.portalUrl || '');
        });
      }
    } else {
      link.textContent = cfg.loggedOutLabel || 'Log In';
      link.href = cfg.loginUrl || '#';
    }

    root.appendChild(link);

    if (loggedIn && cfg.signOutLabel) {
      root.appendChild(buildMenu(root, link, cfg));
    }
  }

  function initAll(context) {
    var scope = context && context.querySelectorAll ? context : document;
    var nodes = scope.querySelectorAll('.agend-header-auth[data-agend-header-auth-config]');
    Array.prototype.forEach.call(nodes, function (node) {
      render(node);
    });
  }

  // Elementor renders (and re-renders) widgets dynamically in the editor and
  // fires a per-widget "element ready" action in both the editor preview and
  // the published frontend. Hooking it makes the button render live in the
  // editor as its controls change, without publishing. render() is idempotent
  // (it rebuilds the link each call), so the DOMContentLoaded fallback below is
  // harmless when this also runs.
  function bindElementor() {
    if (!window.elementorFrontend || !elementorFrontend.hooks) {
      return;
    }
    elementorFrontend.hooks.addAction(
      'frontend/element_ready/agend-header-auth.default',
      function ($scope) {
        var el = $scope && $scope[0] ? $scope[0] : $scope;
        if (el && el.querySelector) {
          initAll(el);
        }
      },
    );
  }

  if (window.jQuery) {
    window.jQuery(window).on('elementor/frontend/init', bindElementor);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initAll();
    });
  } else {
    initAll();
  }
})();
