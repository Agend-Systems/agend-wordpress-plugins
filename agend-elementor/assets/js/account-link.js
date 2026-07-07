/**
 * Agend Account Link widget — frontend renderer.
 *
 * Shows a logged-in WordPress member whether their account is linked to the
 * connected Agend account and, when it is not, a button to establish the link
 * via SSO. Status comes from the Agend Apps Core REST proxy
 * (/wp-json/agend-apps/v1/account-link/status), which resolves the current
 * user's external id server-side and returns the SSO initiate URL to use.
 */
(function () {
  'use strict';

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

  function apiGet(path) {
    var url = restBase().replace(/\/$/, '') + path;
    return fetch(url, {
      headers: nonce() ? { 'X-WP-Nonce': nonce() } : {},
    }).then(function (res) {
      return res.json();
    });
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

  // Theme tokens in site config are either hex (#RRGGBB) or shadcn-style HSL
  // triplets ("230 37% 16%"); normalise both to a CSS colour value.
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

  // Applies the connected account's published theme (fonts/colours) to the
  // widget root when inheritance is enabled. Fire-and-forget.
  function applySiteTheme(root, cfg) {
    if (!cfg.theme || (!cfg.theme.inheritFonts && !cfg.theme.inheritColours)) {
      return;
    }
    apiGet('/sites/config').then(function (body) {
      var config = unwrapOne(body);
      if (!config) {
        return;
      }
      var theme = config.theme || {};
      if (cfg.theme.inheritColours && theme.colors) {
        var c = theme.colors;
        var heading = normaliseColour(c.primary || c.navy || c.foreground);
        var body2 = normaliseColour(c.foreground || c.body);
        var accent = normaliseColour(c.accent || c.coral || c.ring);
        if (heading) {
          root.style.setProperty('--agend-al-heading', heading);
        }
        if (body2) {
          root.style.setProperty('--agend-al-body', body2);
        }
        if (accent) {
          root.style.setProperty('--agend-al-accent', accent);
          root.style.setProperty('--agend-al-button', accent);
        }
      }
      if (cfg.theme.inheritFonts && theme.fonts) {
        if (theme.fonts.heading) {
          root.style.setProperty('--agend-al-font-heading', '"' + theme.fonts.heading + '", sans-serif');
        }
        if (theme.fonts.body) {
          root.style.setProperty('--agend-al-font-body', '"' + theme.fonts.body + '", sans-serif');
        }
      }
    }).catch(function () {
      /* site config unavailable — fall back to the editor colours */
    });
  }

  function card(cfg) {
    var wrap = el('div', 'agend-al-card');
    if (cfg.showHeading && cfg.messages.heading) {
      wrap.appendChild(el('h3', 'agend-al-card__heading', cfg.messages.heading));
    }
    return wrap;
  }

  function renderLoggedOut(root, cfg) {
    if (!cfg.messages.loggedOut) {
      // Empty logged-out message means hide the widget entirely.
      root.style.display = 'none';
      return;
    }
    var wrap = card(cfg);
    wrap.appendChild(el('p', 'agend-al-card__text', cfg.messages.loggedOut));
    root.appendChild(wrap);
  }

  function renderLinked(root, cfg, portalUrl) {
    var wrap = card(cfg);
    wrap.classList.add('is-linked');
    var row = el('div', 'agend-al-card__status');
    row.appendChild(el('span', 'agend-al-card__tick', '✓'));
    row.appendChild(el('span', 'agend-al-card__badge', 'Linked'));
    wrap.appendChild(row);
    if (cfg.messages.linked) {
      wrap.appendChild(el('p', 'agend-al-card__text', cfg.messages.linked));
    }
    if (portalUrl && cfg.messages.portalLink) {
      var portal = el('a', 'agend-al-card__button', cfg.messages.portalLink);
      portal.href = portalUrl;
      wrap.appendChild(portal);
    }
    root.appendChild(wrap);
  }

  function renderUnlinked(root, cfg, initiateUrl) {
    var wrap = card(cfg);
    wrap.classList.add('is-unlinked');
    if (cfg.messages.unlinked) {
      wrap.appendChild(el('p', 'agend-al-card__text', cfg.messages.unlinked));
    }
    if (initiateUrl) {
      var button = el('a', 'agend-al-card__button', cfg.messages.button || 'Link my account');
      button.href = initiateUrl;
      wrap.appendChild(button);
    } else {
      // No external id resolvable, or no account slug configured: we cannot
      // build an SSO link, so guide the member rather than offer a dead button.
      wrap.appendChild(el('p', 'agend-al-card__note', 'Account linking is not available for your profile. Please contact support.'));
    }
    root.appendChild(wrap);
  }

  function initWidget(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-account-link-config'));
    } catch (e) {
      return;
    }

    applySiteTheme(root, cfg);

    apiGet('/account-link/status').then(function (body) {
      var status = unwrapOne(body) || {};
      root.innerHTML = '';

      if (!status.logged_in) {
        renderLoggedOut(root, cfg);
        return;
      }
      if (status.linked) {
        renderLinked(root, cfg, status.portal_url || '');
        return;
      }
      renderUnlinked(root, cfg, status.initiate_url || '');
    }).catch(function () {
      root.innerHTML = '';
      var wrap = card(cfg);
      wrap.appendChild(el('p', 'agend-al-card__text', 'Unable to check your account status right now.'));
      root.appendChild(wrap);
    });
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-account-link[data-agend-account-link-config]');
    Array.prototype.forEach.call(nodes, initWidget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
