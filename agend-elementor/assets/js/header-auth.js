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

  function isLoggedIn() {
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

  function render(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-header-auth-config'));
    } catch (e) {
      return;
    }

    root.innerHTML = '';
    var link = el('a', 'agend-header-auth__link');

    if (isLoggedIn()) {
      link.textContent = cfg.loggedInLabel || 'My Portal';
      // The href is the plain portal URL (works without JS / as the fallback);
      // the click prefers an authenticated hand-off.
      link.href = cfg.portalUrl || '#';
      link.addEventListener('click', function (event) {
        event.preventDefault();
        if (link.getAttribute('aria-busy') === 'true') {
          return;
        }
        link.setAttribute('aria-busy', 'true');
        goToPortal(link, cfg.portalUrl || '');
      });
    } else {
      link.textContent = cfg.loggedOutLabel || 'Log In';
      link.href = cfg.loginUrl || '#';
    }

    root.appendChild(link);
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
