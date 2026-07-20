/**
 * Agend Directory — server-rendered detail enhancement.
 *
 * Progressive enhancement for the SSR detail page produced by the
 * "Server-rendered detail pages" setting (class-agend-elementor-ssr-detail.php).
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
      var nameVal = (nameInput && nameInput.value.trim()) || '';
      var emailVal = (emailInput && emailInput.value.trim()) || '';
      var contentVal = (contentInput && contentInput.value.trim()) || '';
      if (!chosen) {
        showError('Select a star rating.');
        return;
      }
      if (nameVal.length < 2) {
        showError('Enter your name.');
        return;
      }
      if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
        showError('Enter a valid email address.');
        return;
      }
      if (contentVal.length < 20) {
        showError('Your review must be at least 20 characters.');
        return;
      }
      submit.disabled = true;
      submit.textContent = 'Submitting…';
      apiPost('/directory/reviews', {
        listing_id: listingId,
        rating: chosen,
        reviewer_name: nameVal,
        reviewer_email: emailVal,
        content: contentVal,
      }).then(function (res) {
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

  function init() {
    var form = document.querySelector('.agend-directory-catalogue--ssr .agend-dir-review-form[data-agend-listing-id]');
    if (form) {
      bindReviewForm(form);
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
