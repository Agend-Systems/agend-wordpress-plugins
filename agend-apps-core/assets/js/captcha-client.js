/**
 * Captcha client shared by public Agend forms.
 *
 * Loads the selected provider's own script once, only when a form that needs it
 * is on the page, and hands back a small object per form:
 *
 *   var captcha = window.AgendAppsCaptcha.setup(holderElement, config);
 *   captcha.token()           // Promise<string>: the token now, '' if unsolved
 *   captcha.tokenWhenReady(ms) // Promise<string>: waits up to ms for a token
 *   captcha.reset()           // a token is single use: call after every attempt
 *   captcha.silent            // true when a token normally needs nothing from the visitor
 *
 * `config` is Agend_Apps_Captcha::client_config() as the page prints it:
 * { provider, siteKey, action }. The secret never reaches the browser.
 * `setup()` returns null when there is no provider, so a form that is allowed
 * to run without one (the development opt-out) sends no token.
 *
 * The provider code is the one the listing enquiry form uses
 * (listing-enquiry.js). The enquiry keeps its own copy until it is moved onto
 * this file; change them together.
 */
(function () {
  'use strict';

  if (window.AgendAppsCaptcha) {
    return;
  }

  var TURNSTILE_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
  var RECAPTCHA_V2_SRC = 'https://www.google.com/recaptcha/api.js?render=explicit';
  var RECAPTCHA_SRC = 'https://www.google.com/recaptcha/api.js?render=';

  var scriptPromises = {};

  function loadScript(src) {
    if (!scriptPromises[src]) {
      scriptPromises[src] = new Promise(function (resolve, reject) {
        var el = document.createElement('script');
        el.src = src;
        el.async = true;
        el.defer = true;
        el.onload = resolve;
        el.onerror = function () {
          delete scriptPromises[src];
          reject(new Error('captcha script failed to load'));
        };
        document.head.appendChild(el);
      });
    }
    return scriptPromises[src];
  }

  function waitFor(test) {
    return new Promise(function (resolve, reject) {
      var tries = 0;
      (function poll() {
        if (test()) {
          resolve();
        } else if (tries++ > 100) {
          reject(new Error('captcha did not become ready'));
        } else {
          setTimeout(poll, 50);
        }
      })();
    });
  }

  /** Resolves with the first non-empty token read, or '' after ms. */
  function pollToken(read, ms) {
    return new Promise(function (resolve) {
      var waited = 0;
      (function poll() {
        Promise.resolve(read())
          .then(function (token) {
            if (token) {
              resolve(token);
            } else if (waited >= ms) {
              resolve('');
            } else {
              waited += 250;
              setTimeout(poll, 250);
            }
          })
          .catch(function () {
            resolve('');
          });
      })();
    });
  }

  function explicit(holder, config, src, silent, isReady, render, read, reset) {
    var widgetId = null;
    var ready = loadScript(src)
      .then(function () {
        return waitFor(isReady);
      })
      .then(function () {
        widgetId = render(holder, config.siteKey);
      });

    function token() {
      return ready.then(function () {
        return read(widgetId) || '';
      });
    }

    return {
      silent: silent,
      token: token,
      tokenWhenReady: function (ms) {
        return pollToken(token, ms);
      },
      reset: function () {
        if (widgetId !== null) {
          reset(widgetId);
        }
      },
    };
  }

  function setup(holder, config) {
    if (!config || !config.provider || !config.siteKey) {
      return null;
    }

    if (config.provider === 'turnstile') {
      return explicit(
        holder,
        config,
        TURNSTILE_SRC,
        // Managed mode solves itself in the background for most visitors.
        true,
        function () {
          return window.turnstile && typeof window.turnstile.render === 'function';
        },
        function (el, siteKey) {
          return window.turnstile.render(el, { sitekey: siteKey });
        },
        function (id) {
          return window.turnstile.getResponse(id);
        },
        function (id) {
          if (window.turnstile) {
            window.turnstile.reset(id);
          }
        }
      );
    }

    if (config.provider === 'recaptcha_v2') {
      return explicit(
        holder,
        config,
        RECAPTCHA_V2_SRC,
        // The "I'm not a robot" checkbox always needs the visitor to tick it.
        false,
        function () {
          return window.grecaptcha && typeof window.grecaptcha.render === 'function';
        },
        function (el, siteKey) {
          return window.grecaptcha.render(el, { sitekey: siteKey });
        },
        function (id) {
          return window.grecaptcha.getResponse(id);
        },
        function (id) {
          if (window.grecaptcha) {
            window.grecaptcha.reset(id);
          }
        }
      );
    }

    if (config.provider === 'recaptcha') {
      // Invisible: a token comes from a script call, with nothing to tick.
      var loaded = loadScript(RECAPTCHA_SRC + encodeURIComponent(config.siteKey)).then(function () {
        return waitFor(function () {
          return window.grecaptcha && typeof window.grecaptcha.execute === 'function';
        });
      });

      var invisible = function () {
        return loaded.then(function () {
          return new Promise(function (resolve, reject) {
            window.grecaptcha.ready(function () {
              window.grecaptcha.execute(config.siteKey, { action: config.action }).then(resolve, reject);
            });
          });
        });
      };

      return {
        silent: true,
        token: invisible,
        tokenWhenReady: function () {
          return invisible();
        },
        reset: function () {},
      };
    }

    return null;
  }

  window.AgendAppsCaptcha = { setup: setup };
})();
