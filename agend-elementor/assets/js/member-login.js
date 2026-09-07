/**
 * Agend Member Login widget — frontend renderer.
 *
 * Renders an in-page sign-in form (or a signed-in panel) backed entirely by the
 * Agend Apps Core REST proxy (/wp-json/agend-apps/v1/auth/*). Credentials are
 * posted to the proxy, which validates them server-side, stores the session,
 * and signs the member in; the secret API key and refresh token never reach the
 * browser. The widget exchanges only the WordPress REST nonce.
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

  function request(method, path, body) {
    var url = restBase().replace(/\/$/, '') + path;
    var headers = { 'X-WP-Nonce': nonce() };
    var opts = { method: method, headers: headers };
    if (body) {
      headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    }
    return fetch(url, opts).then(function (res) {
      return res.json().then(function (data) {
        return { ok: res.ok, status: res.status, data: data };
      });
    });
  }

  function unwrap(body) {
    if (body && body.data && !Array.isArray(body.data)) {
      return body.data;
    }
    return body || {};
  }

  function renderSignedIn(root, cfg, portalUrl) {
    root.innerHTML = '';
    var wrap = el('div', 'agend-ml-card is-signed-in');
    if (cfg.messages.heading) {
      wrap.appendChild(el('h3', 'agend-ml-card__heading', cfg.messages.heading));
    }
    if (cfg.messages.signedIn) {
      wrap.appendChild(el('p', 'agend-ml-card__text', cfg.messages.signedIn));
    }

    if (portalUrl && cfg.messages.portalButton) {
      var portalBtn = el('button', 'agend-ml-card__button', cfg.messages.portalButton);
      portalBtn.type = 'button';
      portalBtn.addEventListener('click', function () {
        portalBtn.disabled = true;
        request('POST', '/auth/portal-handoff', {}).then(function (r) {
          var data = unwrap(r.data);
          if (r.ok && data.url) {
            window.location.assign(data.url);
          } else {
            portalBtn.disabled = false;
          }
        }).catch(function () {
          portalBtn.disabled = false;
        });
      });
      wrap.appendChild(portalBtn);
    }

    if (cfg.messages.signOut) {
      var signOut = el('button', 'agend-ml-card__link', cfg.messages.signOut);
      signOut.type = 'button';
      signOut.addEventListener('click', function () {
        signOut.disabled = true;
        request('POST', '/auth/logout', {}).then(function () {
          window.location.reload();
        }).catch(function () {
          signOut.disabled = false;
        });
      });
      wrap.appendChild(signOut);
    }

    root.appendChild(wrap);
  }

  function renderForm(root, cfg) {
    root.innerHTML = '';
    var wrap = el('div', 'agend-ml-card');
    if (cfg.messages.heading) {
      wrap.appendChild(el('h3', 'agend-ml-card__heading', cfg.messages.heading));
    }
    if (cfg.messages.intro) {
      wrap.appendChild(el('p', 'agend-ml-card__text', cfg.messages.intro));
    }

    var form = el('form', 'agend-ml-form');
    form.setAttribute('novalidate', 'novalidate');

    var emailLabel = el('label', 'agend-ml-form__label', cfg.messages.email);
    var email = el('input', 'agend-ml-form__input');
    email.type = 'email';
    email.name = 'email';
    email.autocomplete = 'email';
    email.required = true;
    emailLabel.appendChild(email);

    var passwordLabel = el('label', 'agend-ml-form__label', cfg.messages.password);
    var password = el('input', 'agend-ml-form__input');
    password.type = 'password';
    password.name = 'password';
    password.autocomplete = 'current-password';
    password.required = true;
    passwordLabel.appendChild(password);

    var error = el('p', 'agend-ml-form__error');
    error.setAttribute('role', 'alert');
    error.style.display = 'none';

    var submit = el('button', 'agend-ml-card__button', cfg.messages.submit);
    submit.type = 'submit';

    form.appendChild(emailLabel);
    form.appendChild(passwordLabel);
    form.appendChild(error);
    form.appendChild(submit);

    // Password recovery (SPEC-CORE-20260722 US-2.7): a link that swaps the
    // sign-in form for the reset-request view, prefilling the typed email.
    if (cfg.messages.forgot) {
      var forgotLink = el('button', 'agend-ml-card__link agend-ml-form__forgot', cfg.messages.forgot);
      forgotLink.type = 'button';
      forgotLink.addEventListener('click', function () {
        renderForgot(root, cfg, email.value || '');
      });
      form.appendChild(forgotLink);
    }

    // Account registration (SPEC-CORE-20260907 US-3.2): swaps the sign-in form
    // for the registration view, prefilling the typed email.
    if (cfg.messages.register) {
      var registerLink = el('button', 'agend-ml-card__link agend-ml-form__register', cfg.messages.register);
      registerLink.type = 'button';
      registerLink.addEventListener('click', function () {
        renderRegister(root, cfg, email.value || '');
      });
      form.appendChild(registerLink);
    }

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      error.style.display = 'none';
      if (!email.value || !password.value) {
        return;
      }
      submit.disabled = true;
      submit.textContent = cfg.messages.working;

      request('POST', '/auth/login', {
        email: email.value,
        password: password.value,
      }).then(function (r) {
        if (r.ok) {
          // The sign-in rotated the WordPress session; reload so the fresh
          // nonce and the member's server-rendered widgets take effect.
          window.location.reload();
          return;
        }
        var data = unwrap(r.data);
        error.textContent = data.message || cfg.messages.error;
        error.style.display = '';
        submit.disabled = false;
        submit.textContent = cfg.messages.submit;
      }).catch(function () {
        error.textContent = cfg.messages.error;
        error.style.display = '';
        submit.disabled = false;
        submit.textContent = cfg.messages.submit;
      });
    });

    wrap.appendChild(form);
    root.appendChild(wrap);
  }

  // Account registration view (SPEC-CORE-20260907 US-3.2). Posts to the proxy
  // register route, which creates the Agend account and signs the member in.
  // A 409 (email already in use) is shown verbosely here and only here: the
  // sign-in form collapses that case into the generic credentials error.
  function renderRegister(root, cfg, prefillEmail) {
    root.innerHTML = '';
    var wrap = el('div', 'agend-ml-card');
    if (cfg.messages.registerTitle) {
      wrap.appendChild(el('h3', 'agend-ml-card__heading', cfg.messages.registerTitle));
    }
    if (cfg.messages.registerIntro) {
      wrap.appendChild(el('p', 'agend-ml-card__text', cfg.messages.registerIntro));
    }

    var form = el('form', 'agend-ml-form');
    form.setAttribute('novalidate', 'novalidate');

    function field(labelText, type, name, autocomplete, required) {
      var label = el('label', 'agend-ml-form__label', labelText);
      var input = el('input', 'agend-ml-form__input');
      input.type = type;
      input.name = name;
      input.autocomplete = autocomplete;
      input.required = !!required;
      label.appendChild(input);
      return { label: label, input: input };
    }

    var firstName = field(cfg.messages.firstName, 'text', 'first_name', 'given-name', false);
    var lastName = field(cfg.messages.lastName, 'text', 'family-name', 'family-name', false);
    lastName.input.name = 'last_name';
    var email = field(cfg.messages.email, 'email', 'email', 'email', true);
    email.input.value = prefillEmail || '';
    var password = field(cfg.messages.password, 'password', 'password', 'new-password', true);

    var emailError = el('p', 'agend-ml-form__error');
    emailError.setAttribute('role', 'alert');
    emailError.style.display = 'none';

    var error = el('p', 'agend-ml-form__error');
    error.setAttribute('role', 'alert');
    error.style.display = 'none';

    var done = el('p', 'agend-ml-form__notice');
    done.setAttribute('role', 'status');
    done.style.display = 'none';

    var submit = el('button', 'agend-ml-card__button', cfg.messages.registerSubmit);
    submit.type = 'submit';

    var back = el('button', 'agend-ml-card__link', cfg.messages.backToSignIn);
    back.type = 'button';
    back.addEventListener('click', function () {
      renderForm(root, cfg);
    });

    form.appendChild(firstName.label);
    form.appendChild(lastName.label);
    form.appendChild(email.label);
    form.appendChild(emailError);
    form.appendChild(password.label);
    form.appendChild(error);
    form.appendChild(done);
    form.appendChild(submit);
    form.appendChild(back);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      error.style.display = 'none';
      emailError.style.display = 'none';
      if (!email.input.value || !password.input.value) {
        return;
      }
      submit.disabled = true;
      submit.textContent = cfg.messages.registerWorking;

      request('POST', '/auth/register', {
        email: email.input.value,
        password: password.input.value,
        first_name: firstName.input.value,
        last_name: lastName.input.value,
      }).then(function (r) {
        var data = unwrap(r.data);
        if (r.ok && r.status === 200) {
          window.location.reload();
          return;
        }
        if (r.status === 202) {
          done.textContent = data.message || '';
          done.style.display = '';
          Array.prototype.forEach.call(form.querySelectorAll('input'), function (i) {
            i.disabled = true;
          });
          submit.style.display = 'none';
          return;
        }
        if (r.status === 409 && data.code === 'email_already_registered') {
          emailError.textContent = data.message;
          emailError.style.display = '';
          email.input.focus();
        } else {
          error.textContent = data.message || cfg.messages.registerError;
          error.style.display = '';
        }
        submit.disabled = false;
        submit.textContent = cfg.messages.registerSubmit;
      }).catch(function () {
        error.textContent = cfg.messages.registerError;
        error.style.display = '';
        submit.disabled = false;
        submit.textContent = cfg.messages.registerSubmit;
      });
    });

    wrap.appendChild(form);
    root.appendChild(wrap);
  }

  // Password-reset request view (SPEC-CORE-20260722 US-2.7). Posts the email to
  // the proxy, which asks the gateway to send a reset link that lands on the
  // Agend portal recovery page. The confirmation is generic whether or not the
  // email matched an account, so the widget never discloses account existence.
  function renderForgot(root, cfg, prefillEmail) {
    root.innerHTML = '';
    var wrap = el('div', 'agend-ml-card');
    if (cfg.messages.forgotTitle) {
      wrap.appendChild(el('h3', 'agend-ml-card__heading', cfg.messages.forgotTitle));
    }
    if (cfg.messages.forgotIntro) {
      wrap.appendChild(el('p', 'agend-ml-card__text', cfg.messages.forgotIntro));
    }

    var form = el('form', 'agend-ml-form');
    form.setAttribute('novalidate', 'novalidate');

    var emailLabel = el('label', 'agend-ml-form__label', cfg.messages.email);
    var email = el('input', 'agend-ml-form__input');
    email.type = 'email';
    email.name = 'email';
    email.autocomplete = 'email';
    email.required = true;
    email.value = prefillEmail || '';
    emailLabel.appendChild(email);

    var error = el('p', 'agend-ml-form__error');
    error.setAttribute('role', 'alert');
    error.style.display = 'none';

    var done = el('p', 'agend-ml-form__notice');
    done.setAttribute('role', 'status');
    done.style.display = 'none';

    var submit = el('button', 'agend-ml-card__button', cfg.messages.forgotSubmit);
    submit.type = 'submit';

    var back = el('button', 'agend-ml-card__link', cfg.messages.backToSignIn);
    back.type = 'button';
    back.addEventListener('click', function () {
      renderForm(root, cfg);
    });

    form.appendChild(emailLabel);
    form.appendChild(error);
    form.appendChild(done);
    form.appendChild(submit);
    form.appendChild(back);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      error.style.display = 'none';
      if (!email.value) {
        return;
      }
      submit.disabled = true;
      submit.textContent = cfg.messages.forgotWorking;

      request('POST', '/auth/forgot-password', { email: email.value }).then(function (r) {
        if (r.ok) {
          // Success is intentionally generic (anti-enumeration): show the
          // confirmation and disable further submits.
          done.textContent = cfg.messages.forgotDone;
          done.style.display = '';
          email.disabled = true;
          submit.style.display = 'none';
          return;
        }
        var data = unwrap(r.data);
        error.textContent = data.message || cfg.messages.forgotError;
        error.style.display = '';
        submit.disabled = false;
        submit.textContent = cfg.messages.forgotSubmit;
      }).catch(function () {
        error.textContent = cfg.messages.forgotError;
        error.style.display = '';
        submit.disabled = false;
        submit.textContent = cfg.messages.forgotSubmit;
      });
    });

    wrap.appendChild(form);
    root.appendChild(wrap);
  }

  // Reads the recovery token from the URL when the page is a password-reset
  // landing (SPEC-CORE-20260722 US-2.7). The gateway-minted reset link appends
  // ?token_hash=<hash>&type=recovery to the configured WordPress reset page.
  function recoveryToken() {
    try {
      var params = new URL(window.location.href).searchParams;
      if (params.get('type') === 'recovery') {
        var hash = params.get('token_hash');
        if (hash) {
          return hash;
        }
      }
    } catch (e) {
      /* URL API unavailable */
    }
    return '';
  }

  // Password-reset completion view (SPEC-CORE-20260722 US-2.7). The member
  // re-enters their email (the gateway cross-checks it against the token) and
  // sets a new password; the token comes from the reset link, not the browser.
  function renderReset(root, cfg, token) {
    root.innerHTML = '';
    var wrap = el('div', 'agend-ml-card');
    if (cfg.messages.resetTitle) {
      wrap.appendChild(el('h3', 'agend-ml-card__heading', cfg.messages.resetTitle));
    }
    if (cfg.messages.resetIntro) {
      wrap.appendChild(el('p', 'agend-ml-card__text', cfg.messages.resetIntro));
    }

    var form = el('form', 'agend-ml-form');
    form.setAttribute('novalidate', 'novalidate');

    var emailLabel = el('label', 'agend-ml-form__label', cfg.messages.email);
    var email = el('input', 'agend-ml-form__input');
    email.type = 'email';
    email.autocomplete = 'email';
    email.required = true;
    emailLabel.appendChild(email);

    var pwLabel = el('label', 'agend-ml-form__label', cfg.messages.newPassword);
    var password = el('input', 'agend-ml-form__input');
    password.type = 'password';
    password.autocomplete = 'new-password';
    password.required = true;
    pwLabel.appendChild(password);

    var confirmLabel = el('label', 'agend-ml-form__label', cfg.messages.confirmPassword);
    var confirmPw = el('input', 'agend-ml-form__input');
    confirmPw.type = 'password';
    confirmPw.autocomplete = 'new-password';
    confirmPw.required = true;
    confirmLabel.appendChild(confirmPw);

    var error = el('p', 'agend-ml-form__error');
    error.setAttribute('role', 'alert');
    error.style.display = 'none';

    var done = el('p', 'agend-ml-form__notice');
    done.setAttribute('role', 'status');
    done.style.display = 'none';

    var submit = el('button', 'agend-ml-card__button', cfg.messages.resetSubmit);
    submit.type = 'submit';

    form.appendChild(emailLabel);
    form.appendChild(pwLabel);
    form.appendChild(confirmLabel);
    form.appendChild(error);
    form.appendChild(done);
    form.appendChild(submit);

    form.addEventListener('submit', function (event) {
      event.preventDefault();
      error.style.display = 'none';
      if (!email.value || !password.value) {
        return;
      }
      if (password.value !== confirmPw.value) {
        error.textContent = cfg.messages.passwordMismatch;
        error.style.display = '';
        return;
      }
      submit.disabled = true;
      submit.textContent = cfg.messages.resetWorking;

      request('POST', '/auth/reset-password', {
        email: email.value,
        token: token,
        new_password: password.value,
      }).then(function (r) {
        if (r.ok) {
          done.textContent = cfg.messages.resetDone;
          done.style.display = '';
          Array.prototype.forEach.call(form.querySelectorAll('input'), function (i) {
            i.disabled = true;
          });
          submit.style.display = 'none';
          // Offer a clean route back to sign-in without the spent token in the URL.
          var backLink = el('a', 'agend-ml-card__link', cfg.messages.backToSignIn);
          backLink.href = window.location.pathname;
          form.appendChild(backLink);
          return;
        }
        var data = unwrap(r.data);
        error.textContent = data.message || cfg.messages.resetError;
        error.style.display = '';
        submit.disabled = false;
        submit.textContent = cfg.messages.resetSubmit;
      }).catch(function () {
        error.textContent = cfg.messages.resetError;
        error.style.display = '';
        submit.disabled = false;
        submit.textContent = cfg.messages.resetSubmit;
      });
    });

    wrap.appendChild(form);
    root.appendChild(wrap);
  }

  function initWidget(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-member-login-config'));
    } catch (e) {
      return;
    }

    // A password-reset landing takes precedence over the sign-in/session views:
    // the member arrived from a reset email and must set a new password.
    var token = recoveryToken();
    if (token) {
      renderReset(root, cfg, token);
      return;
    }

    request('GET', '/auth/session').then(function (r) {
      var status = unwrap(r.data);
      if (status.signed_in) {
        renderSignedIn(root, cfg, status.portal_url || '');
      } else {
        renderForm(root, cfg);
      }
    }).catch(function () {
      renderForm(root, cfg);
    });
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-member-login[data-agend-member-login-config]');
    Array.prototype.forEach.call(nodes, initWidget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
