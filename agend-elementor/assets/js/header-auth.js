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
  // sign-in URL server-side, then navigate to it. Falls back to the plain
  // portal URL (the link's own href) if the hand-off cannot be minted.
  function goToPortal(link, fallbackUrl) {
    var url = restBase().replace(/\/$/, '') + '/auth/portal-handoff';
    var headers = { 'Content-Type': 'application/json' };
    if (nonce()) {
      headers['X-WP-Nonce'] = nonce();
    }
    fetch(url, { method: 'POST', headers: headers, body: '{}' })
      .then(function (res) {
        return res.json();
      })
      .then(function (body) {
        var data = body && body.data && !Array.isArray(body.data) ? body.data : body;
        var target = (data && data.url) || fallbackUrl;
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

  function initAll() {
    var nodes = document.querySelectorAll('.agend-header-auth[data-agend-header-auth-config]');
    Array.prototype.forEach.call(nodes, render);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
