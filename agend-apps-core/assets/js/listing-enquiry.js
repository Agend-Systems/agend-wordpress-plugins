/**
 * Listing enquiry form.
 *
 * Posts the visitor's message to the Agend Apps Core enquiry route without a
 * page reload. The route checks the nonce and the captcha, then the Agend
 * gateway sends the emails; nothing here emails anyone.
 *
 * The captcha provider's script is loaded here, once, and only when a form is
 * on the page. The page markup is the source of truth for the provider and
 * the public site key (data-agend-enquiry-config); the secret never reaches
 * the browser.
 */
(function () {
  'use strict';

  var TURNSTILE_SRC = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
  var RECAPTCHA_V2_SRC = 'https://www.google.com/recaptcha/api.js?render=explicit';
  var RECAPTCHA_SRC = 'https://www.google.com/recaptcha/api.js?render=';
  var EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  var LIMITS = { first_name: 100, last_name: 100, email: 254, phone: 40, message: 5000 };
  var FIELDS = ['first_name', 'last_name', 'email', 'phone', 'message', 'terms_accepted'];

  // Codes the enquiry route answers with for refusals other than a stale nonce.
  var OWN_CODES = {
    validation_failed: true,
    captcha_failed: true,
    not_accepting: true,
    terms_required: true,
    rate_limited: true,
    not_found: true,
    send_failed: true,
  };

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

  function parseConfig(root) {
    try {
      return JSON.parse(root.getAttribute('data-agend-enquiry-config') || '{}');
    } catch (e) {
      return {};
    }
  }

  function nonce() {
    return (window.agendApps && window.agendApps.nonce) || '';
  }

  function field(form, name) {
    return form.querySelector('[data-agend-enquiry-field="' + name + '"]');
  }

  function setFieldError(form, name, message) {
    var input = field(form, name);
    if (!input) {
      return;
    }
    var error = document.getElementById(input.id + '-error');
    if (error) {
      error.textContent = message || '';
      error.hidden = !message;
    }
    if (message) {
      input.setAttribute('aria-invalid', 'true');
    } else {
      input.removeAttribute('aria-invalid');
    }
  }

  function clearErrors(form) {
    FIELDS.forEach(function (name) {
      setFieldError(form, name, '');
    });
  }

  function value(form, name) {
    var input = field(form, name);
    return input ? String(input.value || '').trim() : '';
  }

  /** Client-side hints only: the route validates again, and its word is final. */
  function validate(form, cfg) {
    var m = cfg.messages || {};
    var errors = {};

    ['first_name', 'last_name'].forEach(function (name) {
      var v = value(form, name);
      if (!v) {
        errors[name] = m.required;
      } else if (v.length > LIMITS[name]) {
        errors[name] = m.tooLong;
      }
    });

    var email = value(form, 'email');
    if (!email) {
      errors.email = m.required;
    } else if (!EMAIL_PATTERN.test(email) || email.length > LIMITS.email) {
      errors.email = m.email;
    }

    if (value(form, 'phone').length > LIMITS.phone) {
      errors.phone = m.tooLong;
    }

    var message = value(form, 'message');
    if (!message) {
      errors.message = m.required;
    } else if (message.length < 10) {
      errors.message = m.messageShort;
    } else if (message.length > LIMITS.message) {
      errors.message = m.tooLong;
    }

    var terms = field(form, 'terms_accepted');
    if (terms && !terms.checked) {
      errors.terms_accepted = m.terms;
    }

    return errors;
  }

  function showErrors(form, errors) {
    var first = null;
    FIELDS.forEach(function (name) {
      if (errors[name]) {
        setFieldError(form, name, errors[name]);
        first = first || field(form, name);
      }
    });
    if (first) {
      first.focus();
    }
  }

  function setBusy(form, status, busy, text) {
    var button = form.querySelector('[data-agend-enquiry-submit]');
    if (button) {
      button.disabled = busy;
    }
    form.setAttribute('aria-busy', busy ? 'true' : 'false');
    status.textContent = text || '';
    status.classList.toggle('agend-enquiry__status--error', false);
  }

  function showStatusError(status, text) {
    status.textContent = text;
    status.classList.add('agend-enquiry__status--error');
  }

  /** Prepares the provider and returns a function that yields a token for one submit. */
  function setupCaptcha(root, cfg) {
    var captcha = cfg.captcha;
    if (!captcha || !captcha.provider || !captcha.siteKey) {
      return null;
    }

    if (captcha.provider === 'turnstile') {
      var holder = root.querySelector('[data-agend-enquiry-captcha]');
      var widgetId = null;
      var ready = loadScript(TURNSTILE_SRC)
        .then(function () {
          return waitFor(function () {
            return window.turnstile && typeof window.turnstile.render === 'function';
          });
        })
        .then(function () {
          widgetId = window.turnstile.render(holder, { sitekey: captcha.siteKey });
        });

      return {
        token: function () {
          return ready.then(function () {
            return window.turnstile.getResponse(widgetId) || '';
          });
        },
        reset: function () {
          if (widgetId !== null && window.turnstile) {
            window.turnstile.reset(widgetId);
          }
        },
      };
    }

    if (captcha.provider === 'recaptcha_v2') {
      var v2Holder = root.querySelector('[data-agend-enquiry-captcha]');
      var v2WidgetId = null;
      var v2Ready = loadScript(RECAPTCHA_V2_SRC)
        .then(function () {
          return waitFor(function () {
            return window.grecaptcha && typeof window.grecaptcha.render === 'function';
          });
        })
        .then(function () {
          // Rendering into a container inside a closed <details> still works.
          v2WidgetId = window.grecaptcha.render(v2Holder, { sitekey: captcha.siteKey });
        });

      return {
        token: function () {
          return v2Ready.then(function () {
            return window.grecaptcha.getResponse(v2WidgetId) || '';
          });
        },
        reset: function () {
          if (v2WidgetId !== null && window.grecaptcha) {
            window.grecaptcha.reset(v2WidgetId);
          }
        },
      };
    }

    if (captcha.provider === 'recaptcha') {
      var loaded = loadScript(RECAPTCHA_SRC + encodeURIComponent(captcha.siteKey)).then(function () {
        return waitFor(function () {
          return window.grecaptcha && typeof window.grecaptcha.execute === 'function';
        });
      });

      return {
        token: function () {
          return loaded.then(function () {
            return new Promise(function (resolve, reject) {
              window.grecaptcha.ready(function () {
                window.grecaptcha.execute(captcha.siteKey, { action: captcha.action }).then(resolve, reject);
              });
            });
          });
        },
        reset: function () {},
      };
    }

    return null;
  }

  function payload(form, token) {
    var body = {
      first_name: value(form, 'first_name'),
      last_name: value(form, 'last_name'),
      email: value(form, 'email'),
      message: value(form, 'message'),
    };
    if (value(form, 'phone')) {
      body.phone = value(form, 'phone');
    }
    var terms = field(form, 'terms_accepted');
    if (terms && terms.checked) {
      body.terms_accepted = true;
    }
    if (token) {
      body.captcha_token = token;
    }
    return body;
  }

  function send(cfg, body) {
    return fetch(cfg.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-WP-Nonce': nonce(),
      },
      body: JSON.stringify(body),
    }).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (json) {
        return { status: res.status, ok: res.ok && json && json.success === true, json: json || {} };
      });
    });
  }

  function init(root) {
    if (root.getAttribute('data-agend-enquiry-ready') === '1') {
      return;
    }
    root.setAttribute('data-agend-enquiry-ready', '1');

    // The editor preview draws the form but never submits it.
    if (root.getAttribute('data-agend-enquiry-preview') === '1') {
      return;
    }

    var cfg = parseConfig(root);
    var m = cfg.messages || {};
    var form = root.querySelector('form');
    var status = root.querySelector('[data-agend-enquiry-status]');
    if (!form || !status || !cfg.endpoint) {
      return;
    }

    var captcha = setupCaptcha(root, cfg);

    FIELDS.forEach(function (name) {
      var input = field(form, name);
      if (input) {
        input.addEventListener('input', function () {
          setFieldError(form, name, '');
        });
      }
    });

    form.addEventListener('submit', function (event) {
      event.preventDefault();

      if (form.getAttribute('aria-busy') === 'true') {
        return;
      }

      clearErrors(form);
      status.textContent = '';
      status.classList.remove('agend-enquiry__status--error');

      var errors = validate(form, cfg);
      if (Object.keys(errors).length) {
        showErrors(form, errors);
        showStatusError(status, m.fix);
        return;
      }

      setBusy(form, status, true, m.sending);

      var tokenPromise = captcha ? captcha.token() : Promise.resolve('');
      var scriptFailed = false;

      tokenPromise
        .catch(function () {
          // The provider's script did not load or ran into an error.
          scriptFailed = true;
          return '';
        })
        .then(function (token) {
          if (captcha && !token) {
            // An interactive Turnstile challenge the visitor has not solved is
            // not a failure: ask them to complete it.
            var e = new Error('no captcha token');
            e.captcha = true;
            e.loadFailed = scriptFailed;
            e.challenge = !scriptFailed && cfg.captcha && (cfg.captcha.provider === 'turnstile' || cfg.captcha.provider === 'recaptcha_v2');
            throw e;
          }
          return send(cfg, payload(form, token));
        })
        .then(function (result) {
          if (result.ok) {
            form.hidden = true;
            status.textContent = cfg.successMessage || '';
            status.classList.add('agend-enquiry__status--success');
            form.setAttribute('aria-busy', 'false');
            // The form is hidden, so focus would be lost: move it to the message.
            status.focus();
            return;
          }

          if (captcha) {
            captcha.reset();
          }
          setBusy(form, status, false, '');

          var json = result.json || {};
          if (json.fields) {
            showErrors(form, json.fields);
          }

          var text = json.message;
          // A stale or missing nonce is refused by the route's own code or by
          // WordPress core's: either way the answer is to reload the page.
          if ((result.status === 401 || result.status === 403) && !OWN_CODES[json.code]) {
            text = m.session;
          }
          showStatusError(status, text || m.failed);
        })
        .catch(function (err) {
          if (captcha) {
            captcha.reset();
          }
          setBusy(form, status, false, '');
          showStatusError(
            status,
            err && err.loadFailed ? m.captchaLoad : err && err.challenge ? m.challenge : err && err.captcha ? m.captcha : m.failed
          );
        });
    });
  }

  var ANCHOR = 'agend-enquiry';

  function prefersReducedMotion() {
    return !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  }

  /** Opens the folded form, brings it into view and focuses its first field. */
  function openEnquiry() {
    var root = document.getElementById(ANCHOR);
    if (!root) {
      return false;
    }
    var details = root.querySelector('details');
    if (details && !details.open) {
      details.open = true;
    }
    root.scrollIntoView({ behavior: prefersReducedMotion() ? 'auto' : 'smooth', block: 'start' });
    var target = root.querySelector('.agend-enquiry__form:not([hidden]) input, .agend-enquiry__form:not([hidden]) textarea');
    if (!target) {
      target = root.querySelector('[data-agend-enquiry-status]');
    }
    if (target && target.focus) {
      target.focus({ preventScroll: true });
    }
    return true;
  }

  document.addEventListener('click', function (event) {
    var node = event.target;
    var link = node && node.closest ? node.closest('a[href="#' + ANCHOR + '"]') : null;
    if (link && openEnquiry()) {
      event.preventDefault();
    }
  });

  window.addEventListener('hashchange', function () {
    if (window.location.hash === '#' + ANCHOR) {
      openEnquiry();
    }
  });

  function openOnLoad() {
    if (window.location.hash === '#' + ANCHOR) {
      openEnquiry();
    }
  }

  function initAll() {
    var roots = document.querySelectorAll('.agend-enquiry');
    for (var i = 0; i < roots.length; i++) {
      init(roots[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () {
      initAll();
      openOnLoad();
    });
  } else {
    initAll();
    openOnLoad();
  }

  // Elementor redraws a widget in the editor and when a popup opens.
  window.addEventListener('elementor/frontend/init', function () {
    if (window.elementorFrontend && window.elementorFrontend.hooks) {
      window.elementorFrontend.hooks.addAction('frontend/element_ready/agend-listing-enquiry.default', function ($el) {
        var node = $el && $el[0] ? $el[0] : $el;
        if (node && node.querySelector) {
          var root = node.querySelector('.agend-enquiry');
          if (root) {
            init(root);
          }
        }
      });
    }
  });
})();
