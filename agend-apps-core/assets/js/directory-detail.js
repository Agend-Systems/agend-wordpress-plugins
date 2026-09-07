/**
 * Agend Directory — server-rendered detail enhancement.
 *
 * Progressive enhancement for the SSR detail page produced by the
 * "Server-rendered detail pages" setting (includes/records/ssr-detail.php in Agend Apps Core).
 * The detail body is already in the HTML; this only wires the interactive bits:
 * the review submission form (posted via the Agend Apps Core REST proxy) and the
 * gallery lightbox. It never renders content, so search engines and no-JS
 * visitors still get the full detail.
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

  function apiGet(path, params) {
    var url = restBase().replace(/\/$/, '') + path;
    var qs = [];
    Object.keys(params || {}).forEach(function (key) {
      var value = params[key];
      if (value === undefined || value === null || value === '') {
        return;
      }
      qs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
    });
    if (qs.length) {
      // The REST base already carries a query string on a site with plain
      // permalinks (index.php?rest_route=...), so join with & there.
      url += (url.indexOf('?') === -1 ? '?' : '&') + qs.join('&');
    }
    return fetch(url, {
      headers: nonce() ? { 'X-WP-Nonce': nonce() } : {},
    }).then(function (res) {
      return res.json();
    });
  }

  function apiPost(path, body) {
    var url = restBase().replace(/\/$/, '') + path;
    var headers = { 'Content-Type': 'application/json' };
    if (nonce()) {
      headers['X-WP-Nonce'] = nonce();
    }
    return fetch(url, {
      method: 'POST',
      headers: headers,
      body: JSON.stringify(body || {}),
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

  function bindReviewForm(form) {
    var listingId = form.getAttribute('data-agend-listing-id');
    if (!listingId) {
      return;
    }
    var stars = Array.prototype.slice.call(form.querySelectorAll('.agend-dir-review-form__star'));
    var nameInput = form.querySelector('[data-field="name"]');
    var emailInput = form.querySelector('[data-field="email"]');
    var contentInput = form.querySelector('[data-field="content"]');
    var errorBox = form.querySelector('.agend-dir-review-form__error');
    var submit = form.querySelector('.agend-dir-review-form__submit');
    var chosen = 0;

    // Signed-in member: identity is derived server-side from the bearer, so
    // the name/email fields are not sent (SPEC-CORE-20260722 US-2.6). The SSR
    // template already omits these fields for a member (data-agend-member="1");
    // this client-side hide is a null-safe safety net for any guest/cached
    // markup hydrated in a since-authenticated session. Guests keep the
    // required name/email capture.
    var isSignedIn = !!(window.agendApps && window.agendApps.loggedIn);
    if (isSignedIn) {
      [nameInput, emailInput].forEach(function (input) {
        var field = input && input.closest ? input.closest('.agend-dir-review-form__field') : null;
        if (field) {
          field.style.display = 'none';
        }
      });
    }

    function paint(value) {
      stars.forEach(function (star, idx) {
        if (idx < value) {
          star.classList.add('is-on');
        } else {
          star.classList.remove('is-on');
        }
      });
    }

    stars.forEach(function (star) {
      star.addEventListener('click', function () {
        chosen = parseInt(star.getAttribute('data-value'), 10) || 0;
        paint(chosen);
      });
    });

    function showError(message) {
      if (errorBox) {
        errorBox.style.display = '';
        errorBox.textContent = message;
      }
    }

    if (!submit) {
      return;
    }

    submit.addEventListener('click', function () {
      if (errorBox) {
        errorBox.style.display = 'none';
      }
      var nameVal = (!isSignedIn && nameInput && nameInput.value.trim()) || '';
      var emailVal = (!isSignedIn && emailInput && emailInput.value.trim()) || '';
      var contentVal = (contentInput && contentInput.value.trim()) || '';
      if (!chosen) {
        showError('Select a star rating.');
        return;
      }
      if (!isSignedIn && nameVal.length < 2) {
        showError('Enter your name.');
        return;
      }
      if (!isSignedIn && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
        showError('Enter a valid email address.');
        return;
      }
      if (contentVal.length < 20) {
        showError('Your review must be at least 20 characters.');
        return;
      }
      submit.disabled = true;
      submit.textContent = 'Submitting…';
      var payload = {
        listing_id: listingId,
        rating: chosen,
        content: contentVal,
      };
      if (!isSignedIn) {
        payload.reviewer_name = nameVal;
        payload.reviewer_email = emailVal;
      }
      apiPost('/directory/reviews', payload).then(function (res) {
        if (res && res.success === false) {
          throw new Error((res.error && res.error.message) || 'Submission failed.');
        }
        form.innerHTML = '';
        var done = el('div', 'agend-dir-review-form__done');
        done.appendChild(el('div', 'agend-dir-review-form__tick', '✓'));
        done.appendChild(el('p', null, 'Thank you. Your review has been submitted and is awaiting moderation before it appears.'));
        form.appendChild(done);
      }).catch(function (err) {
        submit.disabled = false;
        submit.textContent = 'Submit Review';
        showError((err && err.message) || 'Something went wrong. Please try again.');
      });
    });
  }

  function bindLightbox(thumb) {
    var url = thumb.getAttribute('data-agend-lightbox');
    if (!url) {
      return;
    }
    thumb.addEventListener('click', function () {
      var overlay = el('div', 'agend-dir-lightbox');
      var img = el('img', 'agend-dir-lightbox__img');
      img.src = url;
      img.alt = '';
      overlay.appendChild(img);
      var close = function () {
        if (overlay.parentNode) {
          overlay.parentNode.removeChild(overlay);
        }
        document.removeEventListener('keydown', onKey);
      };
      var onKey = function (e) {
        if (e.key === 'Escape') {
          close();
        }
      };
      overlay.addEventListener('click', close);
      document.addEventListener('keydown', onKey);
      document.body.appendChild(overlay);
    });
  }

  // If the signed-in member's own listing matches this detail page, replace
  // the review form with an indicator (SPEC-CORE-20260722 US-2.6) — reviewing
  // your own business does not make sense. Progressive enhancement: run after
  // the form is already bound, so a slow/failed check just leaves the form.
  function checkOwnListing(form) {
    if (!(window.agendApps && window.agendApps.loggedIn)) {
      return;
    }
    var listingId = form.getAttribute('data-agend-listing-id');
    if (!listingId) {
      return;
    }
    apiGet('/directory/me/listing', {}).then(function (body) {
      var mine = unwrapOne(body);
      if (mine && mine.id && String(mine.id) === String(listingId)) {
        var indicator = el('p', 'agend-dir-reviews__mine', 'This is your listing.');
        form.parentNode.insertBefore(indicator, form);
        form.style.display = 'none';
      }
    }).catch(function () {
      // Progressive enhancement only — the review form still works.
    });
  }

  function init() {
    var form = document.querySelector('.agend-directory-catalogue--ssr .agend-dir-review-form[data-agend-listing-id]');
    if (form) {
      bindReviewForm(form);
      checkOwnListing(form);
    }
    var thumbs = document.querySelectorAll('.agend-directory-catalogue--ssr .agend-dir-gallery__thumb[data-agend-lightbox]');
    Array.prototype.forEach.call(thumbs, bindLightbox);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
