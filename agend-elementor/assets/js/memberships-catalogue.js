/**
 * Agend Memberships Catalogue widget — frontend renderer.
 *
 * Displays membership tiers as a pricing grid. Selecting a tier reveals a
 * dynamic signup form built from the organisation's configured member fields.
 * On submit, either creates a pending contact (application) or opens a
 * checkout session (direct purchase). Data comes from the Agend Apps Core REST
 * proxy (/wp-json/agend-apps/v1/crm/...).
 */
(function () {
  'use strict';

  var DEEP_LINK_PARAM = 'agend_membership';
  var SUCCESS_MARKER = 'success';
  var CANCEL_MARKER = 'cancel';

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
      if (Array.isArray(value)) {
        value.forEach(function (item) {
          if (item !== undefined && item !== null && item !== '') {
            qs.push(encodeURIComponent(key) + '[]=' + encodeURIComponent(item));
          }
        });
        return;
      }
      qs.push(encodeURIComponent(key) + '=' + encodeURIComponent(value));
    });
    if (qs.length) {
      url += '?' + qs.join('&');
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

  function unwrapList(body) {
    if (body && Array.isArray(body.data)) {
      return { items: body.data, pagination: (body.meta && body.meta.pagination) || null };
    }
    if (Array.isArray(body)) {
      return { items: body, pagination: null };
    }
    return { items: [], pagination: null };
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

  /**
   * Resolves the signup mode for a tier.
   * Global signupMode can be overridden per tier (e.g. corporate always application).
   */
  function resolveMode(tier, cfg) {
    if (tier.tier_type === 'corporate') {
      return 'application';
    }
    var override = cfg.tierModeOverrides && cfg.tierModeOverrides[tier.slug];
    if (override) {
      return override;
    }
    return cfg.signupMode || 'application';
  }

  /**
   * Returns a current success/cancel state from the URL (e.g. ?agend_membership=success).
   */
  function currentState() {
    try {
      return new URL(window.location.href).searchParams.get(DEEP_LINK_PARAM);
    } catch (e) {
      return null;
    }
  }

  /**
   * Removes the marker param from the URL after rendering the state.
   */
  function clearState() {
    try {
      var url = new URL(window.location.href);
      url.searchParams.delete(DEEP_LINK_PARAM);
      window.history.replaceState({}, '', url.toString());
    } catch (e) {
      /* history API unavailable */
    }
  }

  /**
   * Initialises the widget in a container.
   */
  function initWidget(root) {
    var configAttr = root.getAttribute('data-agend-memberships-config');
    if (!configAttr) {
      return;
    }

    var cfg;
    try {
      cfg = JSON.parse(configAttr);
    } catch (e) {
      return;
    }

    var state = {
      selectedTier: null,
      fields: [],
      formValues: {},
    };

    var container = root.querySelector('.agend-mem-container');
    var grid = root.querySelector('.agend-mem-grid');
    var status = root.querySelector('[role="status"]');

    /**
     * Renders the tier grid.
     */
    function renderTiers(tiers) {
      grid.innerHTML = '';
      if (!tiers || !tiers.length) {
        if (status) {
          status.style.display = '';
          status.textContent = 'No membership tiers available.';
        }
        return;
      }

      var activeOnly = tiers.filter(function (t) { return t.is_active; });
      activeOnly.sort(function (a, b) { return (a.sort_order || 0) - (b.sort_order || 0); });

      activeOnly.forEach(function (tier, index) {
        var card = renderCard(tier, cfg, index, activeOnly.length, function () { selectTier(tier, tiers); });
        grid.appendChild(card);
      });
    }

    /**
     * Renders a single tier card.
     */
    function renderCard(tier, cfg, idx, total, onSelect) {
      var card = el('article', 'agend-mem-card');
      var isSecond = idx === 1;
      if (isSecond && total > 1) {
        card.classList.add('agend-mem-card--popular');
      }

      var titleRow = el('div', 'agend-mem-card__title-row');
      var title = el('h3', 'agend-mem-card__title', tier.name);
      titleRow.appendChild(title);
      if (isSecond && total > 1) {
        var ribbon = el('span', 'agend-mem-card__ribbon', 'Most Popular');
        titleRow.appendChild(ribbon);
      }
      card.appendChild(titleRow);

      if (cfg.fields && cfg.fields.description && tier.description) {
        var desc = el('p', 'agend-mem-card__description', tier.description);
        card.appendChild(desc);
      }

      if (cfg.fields && cfg.fields.price && tier.formatted_price) {
        var priceBlock = el('div', 'agend-mem-card__price');
        var priceVal = el('span', 'agend-mem-card__price-value', tier.formatted_price);
        priceBlock.appendChild(priceVal);
        if (tier.billing_period) {
          var period = el('span', 'agend-mem-card__price-period', ' / ' + tier.billing_period);
          priceBlock.appendChild(period);
        }
        card.appendChild(priceBlock);
      }

      if (cfg.fields && cfg.fields.benefits && tier.benefits && Array.isArray(tier.benefits)) {
        var benefitsBlock = el('ul', 'agend-mem-card__benefits');
        tier.benefits.forEach(function (benefit) {
          var li = el('li', 'agend-mem-card__benefit', benefit);
          benefitsBlock.appendChild(li);
        });
        card.appendChild(benefitsBlock);
      }

      var btn = el('button', 'agend-mem-card__button', 'Choose Plan');
      btn.type = 'button';
      btn.addEventListener('click', function (e) {
        e.preventDefault();
        onSelect();
      });
      card.appendChild(btn);

      return card;
    }

    /**
     * Selects a tier and renders the signup form.
     */
    function selectTier(tier, allTiers) {
      state.selectedTier = tier;
      var cards = grid.querySelectorAll('.agend-mem-card');
      cards.forEach(function (c) {
        c.classList.remove('agend-mem-card--selected');
      });
      var selectedCard = Array.prototype.find.call(cards, function (c) {
        var nameEl = c.querySelector('.agend-mem-card__title');
        return nameEl && nameEl.textContent === tier.name;
      });
      if (selectedCard) {
        selectedCard.classList.add('agend-mem-card--selected');
      }

      renderForm(tier, state.fields, cfg);
    }

    /**
     * Renders the signup form after tier selection.
     */
    /**
     * Builds the selected-membership summary shown at the top of the form:
     * the tier name, its price, and its benefits/entitlements. Always shown
     * (independent of the card field toggles) so the applicant can confirm
     * what they are signing up for.
     */
    function renderMembershipSummary(tier) {
      var summary = el('div', 'agend-mem-summary');

      var header = el('div', 'agend-mem-summary__header');
      header.appendChild(el('h3', 'agend-mem-summary__name', tier.name));

      if (tier.formatted_price) {
        var price = el('div', 'agend-mem-summary__price');
        price.appendChild(
          el('span', 'agend-mem-summary__price-value', tier.formatted_price)
        );
        if (tier.billing_period) {
          price.appendChild(
            el('span', 'agend-mem-summary__price-period', ' / ' + tier.billing_period)
          );
        }
        header.appendChild(price);
      }
      summary.appendChild(header);

      if (tier.description) {
        summary.appendChild(
          el('p', 'agend-mem-summary__description', tier.description)
        );
      }

      if (tier.benefits && Array.isArray(tier.benefits) && tier.benefits.length) {
        var benefitsTitle = el(
          'p',
          'agend-mem-summary__benefits-title',
          "What's included"
        );
        summary.appendChild(benefitsTitle);

        var benefits = el('ul', 'agend-mem-summary__benefits');
        tier.benefits.forEach(function (benefit) {
          benefits.appendChild(
            el('li', 'agend-mem-summary__benefit', benefit)
          );
        });
        summary.appendChild(benefits);
      }

      return summary;
    }

    function renderForm(tier, fields, cfg) {
      var formContainer = root.querySelector('.agend-mem-form-wrapper');
      if (!formContainer) {
        formContainer = el('div', 'agend-mem-form-wrapper');
        container.appendChild(formContainer);
      }
      formContainer.innerHTML = '';

      var form = el('form', 'agend-mem-form');
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        submitForm(tier, fields, cfg, form);
      });

      // Selected membership summary (name, price, benefits) at the top.
      form.appendChild(renderMembershipSummary(tier));

      // Personal Details section (always shown).
      var personalSection = el('fieldset', 'agend-mem-form__section');
      var personalLegend = el('legend', 'agend-mem-form__section-title', 'Personal Details');
      personalSection.appendChild(personalLegend);

      var firstNameGroup = renderInputField('first_name', 'First Name', 'text', '', true);
      personalSection.appendChild(firstNameGroup);

      var lastNameGroup = renderInputField('last_name', 'Last Name', 'text', '', true);
      personalSection.appendChild(lastNameGroup);

      var emailGroup = renderInputField('email', 'Email Address', 'email', '', true);
      personalSection.appendChild(emailGroup);

      var phoneGroup = renderPhoneField('phone', 'Phone', '', false);
      personalSection.appendChild(phoneGroup);

      form.appendChild(personalSection);

      // Custom fields grouped by group_name.
      var groups = {};
      fields.forEach(function (field) {
        var groupName = field.group_name || 'Other';
        if (!groups[groupName]) {
          groups[groupName] = [];
        }
        groups[groupName].push(field);
      });

      Object.keys(groups).sort().forEach(function (groupName) {
        var groupFields = groups[groupName];
        groupFields.sort(function (a, b) {
          return (a.sort_order || 0) - (b.sort_order || 0);
        });

        var section = el('fieldset', 'agend-mem-form__section');
        var legend = el('legend', 'agend-mem-form__section-title', groupName);
        section.appendChild(legend);

        groupFields.forEach(function (field) {
          var fieldEl = renderCustomField(field);
          section.appendChild(fieldEl);
        });

        form.appendChild(section);
      });

      // Submit button.
      var mode = resolveMode(tier, cfg);
      var btnLabel = 'application' === mode
        ? 'Submit Application'
        : 'Proceed to Checkout — ' + tier.formatted_price + ' / ' + tier.billing_period;
      var submitBtn = el('button', 'agend-mem-form__submit', btnLabel);
      submitBtn.type = 'submit';
      form.appendChild(submitBtn);

      formContainer.appendChild(form);
    }

    /**
     * Renders a standard text/email/number input field.
     */
    function renderInputField(name, label, type, placeholder, required) {
      var group = el('div', 'agend-mem-form__field-group');

      var labelEl = el('label', 'agend-mem-form__label');
      labelEl.setAttribute('for', 'mem-field-' + name);
      var labelText = document.createTextNode(label);
      labelEl.appendChild(labelText);
      if (required) {
        var req = el('span', 'agend-mem-form__required', ' *');
        labelEl.appendChild(req);
      }
      group.appendChild(labelEl);

      var input = document.createElement('input');
      input.type = type;
      input.className = 'agend-mem-form__input';
      input.id = 'mem-field-' + name;
      input.name = name;
      input.placeholder = placeholder || '';
      input.required = required;
      group.appendChild(input);

      return group;
    }

    /**
     * Renders a phone input field (dial code + number).
     */
    function renderPhoneField(name, label, placeholder, required) {
      var group = el('div', 'agend-mem-form__field-group');

      var labelEl = el('label', 'agend-mem-form__label');
      labelEl.setAttribute('for', 'mem-field-' + name);
      labelEl.textContent = label;
      if (required) {
        var req = el('span', 'agend-mem-form__required', ' *');
        labelEl.appendChild(req);
      }
      group.appendChild(labelEl);

      // Simple text input for MVP; dial_code mapping is minimal.
      var input = document.createElement('input');
      input.type = 'tel';
      input.className = 'agend-mem-form__input';
      input.id = 'mem-field-' + name;
      input.name = name;
      input.placeholder = placeholder || '+61 400 000 000';
      input.required = required;
      group.appendChild(input);

      return group;
    }

    /**
     * Renders a custom field (select, text, textarea, checkbox, etc.).
     */
    function renderCustomField(field) {
      var group = el('div', 'agend-mem-form__field-group');

      var labelEl = el('label', 'agend-mem-form__label');
      labelEl.setAttribute('for', 'mem-field-' + field.field_key);
      labelEl.textContent = field.name || field.field_key;
      if (field.is_required) {
        var req = el('span', 'agend-mem-form__required', ' *');
        labelEl.appendChild(req);
      }
      group.appendChild(labelEl);

      var input;
      var fieldType = field.field_type;

      if ('select' === fieldType || 'radio' === fieldType) {
        input = document.createElement('select');
        input.className = 'agend-mem-form__select';
        if (!field.is_required) {
          var emptyOpt = document.createElement('option');
          emptyOpt.value = '';
          emptyOpt.textContent = '— Select an option —';
          input.appendChild(emptyOpt);
        }
        if (field.options && Array.isArray(field.options)) {
          field.options.forEach(function (opt) {
            var optEl = document.createElement('option');
            optEl.value = opt.value;
            optEl.textContent = opt.label;
            input.appendChild(optEl);
          });
        }
      } else if ('multi_select' === fieldType || 'checkbox_group' === fieldType) {
        input = document.createElement('div');
        input.className = 'agend-mem-form__checkbox-group';
        if (field.options && Array.isArray(field.options)) {
          field.options.forEach(function (opt, idx) {
            var checkId = 'mem-field-' + field.field_key + '-' + idx;
            var cbLabel = el('label', 'agend-mem-form__checkbox-label');
            var cb = document.createElement('input');
            cb.type = 'checkbox';
            cb.className = 'agend-mem-form__checkbox';
            cb.id = checkId;
            cb.value = opt.value;
            cb.setAttribute('data-field-key', field.field_key);
            cbLabel.appendChild(cb);
            cbLabel.appendChild(document.createTextNode(opt.label));
            input.appendChild(cbLabel);
          });
        }
      } else if ('toggle' === fieldType) {
        input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'agend-mem-form__toggle';
      } else if ('paragraph' === fieldType) {
        input = document.createElement('textarea');
        input.className = 'agend-mem-form__textarea';
        input.rows = 4;
      } else if ('date' === fieldType) {
        input = document.createElement('input');
        input.type = 'date';
        input.className = 'agend-mem-form__input';
      } else if ('datetime' === fieldType) {
        input = document.createElement('input');
        input.type = 'datetime-local';
        input.className = 'agend-mem-form__input';
      } else {
        // Default: text, email, number, url, etc.
        input = document.createElement('input');
        input.type = 'text' === fieldType ? 'text' : (fieldType || 'text');
        input.className = 'agend-mem-form__input';
      }

      if (!(input instanceof HTMLDivElement)) {
        input.id = 'mem-field-' + field.field_key;
        input.name = field.field_key;
        input.placeholder = field.placeholder || '';
        if (field.default_value && !('checkbox_group' === fieldType || 'multi_select' === fieldType)) {
          if ('checkbox' === input.type || 'toggle' === fieldType) {
            input.checked = !!field.default_value;
          } else {
            input.value = String(field.default_value);
          }
        }
        input.required = field.is_required;
      }

      group.appendChild(input);

      if (field.help_text) {
        var help = el('small', 'agend-mem-form__help', field.help_text);
        group.appendChild(help);
      }

      return group;
    }

    /**
     * Submits the form: creates a contact, then either shows a confirmation
     * (application) or redirects to checkout (direct).
     */
    function submitForm(tier, fields, cfg, form) {
      var isValid = form.checkValidity();
      if (!isValid) {
        form.reportValidity();
        return;
      }

      var formData = new FormData(form);
      var contactData = {
        first_name: formData.get('first_name') || '',
        last_name: formData.get('last_name') || '',
        email: formData.get('email') || '',
        phone: formData.get('phone') || '',
        tags: [],
        custom_fields: {},
      };

      var mode = resolveMode(tier, cfg);
      if ('application' === mode) {
        contactData.tags.push('membership-application');
        // Record which tier was requested so staff can action the application
        // (SPEC-CRM-20260721 US-3.4, Decision 2.5).
        contactData.custom_fields.requested_tier = tier.slug;
      }

      // Gather custom fields.
      fields.forEach(function (field) {
        var val = formData.get(field.field_key);
        if ('checkbox_group' === field.field_type || 'multi_select' === field.field_type) {
          var checkboxes = form.querySelectorAll('[data-field-key="' + field.field_key + '"]:checked');
          var checkedVals = [];
          checkboxes.forEach(function (cb) {
            checkedVals.push(cb.value);
          });
          if (checkedVals.length) {
            contactData.custom_fields[field.field_key] = checkedVals;
          }
        } else if ('phone' === field.field_type) {
          if (val) {
            contactData.custom_fields[field.field_key] = { number: val };
          }
        } else if (val) {
          contactData.custom_fields[field.field_key] = val;
        }
      });

      // Create contact.
      apiPost('/crm/contacts', contactData).then(function (body) {
        if (!body || body.success === false) {
          var msg = body && body.error && body.error.message
            ? body.error.message
            : 'Failed to submit application.';
          alert('Error: ' + msg);
          return;
        }

        var contact = unwrapOne(body);
        if (!contact || !contact.id) {
          alert('Error: Contact creation returned no ID.');
          return;
        }

        if ('application' === mode) {
          showApplicationConfirmation();
        } else if ('direct' === mode) {
          processPurchase(contact.id, tier, cfg);
        }
      }).catch(function (err) {
        alert('Error submitting application: ' + (err ? err.message : 'Unknown error'));
      });
    }

    /**
     * Shows an in-page confirmation after application submission.
     */
    function showApplicationConfirmation() {
      var formWrapper = root.querySelector('.agend-mem-form-wrapper');
      if (formWrapper) {
        formWrapper.innerHTML = '';
        var confirm = el('div', 'agend-mem-confirmation');
        var title = el('h2', 'agend-mem-confirmation__title', 'Application Submitted');
        var msg = el('p', 'agend-mem-confirmation__message', 'Thank you! We have received your application. We will be in touch soon.');
        confirm.appendChild(title);
        confirm.appendChild(msg);
        formWrapper.appendChild(confirm);
      }
    }

    /**
     * Processes direct purchase: creates a checkout session and redirects.
     */
    function processPurchase(contactId, tier, cfg) {
      var currentUrl = window.location.href;
      try {
        currentUrl = new URL(window.location.href).origin + window.location.pathname;
      } catch (e) {
        // Fallback
      }

      var successUrl = cfg.successUrl ? cfg.successUrl : (currentUrl + '?agend_membership=success');
      var cancelUrl = currentUrl + '?agend_membership=cancel';

      apiPost('/crm/memberships/purchase', {
        contact_id: contactId,
        tier_id: tier.id,
        success_url: successUrl,
        cancel_url: cancelUrl,
      }).then(function (body) {
        if (!body || body.success === false) {
          var msg = body && body.error && body.error.message
            ? body.error.message
            : 'Failed to create checkout session.';
          alert('Error: ' + msg);
          return;
        }

        var data = unwrapOne(body);
        var checkoutUrl = data && (data.checkoutUrl || data.checkout_url);
        if (!checkoutUrl) {
          alert('Error: No checkout URL returned.');
          return;
        }

        window.location.href = checkoutUrl;
      }).catch(function (err) {
        alert('Error processing purchase: ' + (err ? err.message : 'Unknown error'));
      });
    }

    /**
     * Shows a confirmation overlay when returning from a successful checkout.
     */
    function showCheckoutConfirmation() {
      var formWrapper = root.querySelector('.agend-mem-form-wrapper');
      if (formWrapper) {
        formWrapper.innerHTML = '';
      }

      // Redirect return only: payment settlement (and entitlement) is confirmed
      // by the gateway webhook, not by this return, so the copy does not assert
      // activation (SPEC-CRM-20260721 US-3.3 business rule).
      var confirm = el('div', 'agend-mem-confirmation agend-mem-confirmation--success');
      var title = el('h2', 'agend-mem-confirmation__title', 'Thank you');
      var msg = el('p', 'agend-mem-confirmation__message', 'We have received your payment. Your membership is being set up and you will receive a confirmation email shortly.');
      confirm.appendChild(title);
      confirm.appendChild(msg);

      if (container) {
        container.appendChild(confirm);
      }
      clearState();
    }

    /**
     * Shows a notice when checkout is cancelled.
     */
    function showCheckoutCancelled() {
      var formWrapper = root.querySelector('.agend-mem-form-wrapper');
      if (formWrapper) {
        formWrapper.innerHTML = '';
      }

      var notice = el('div', 'agend-mem-notice agend-mem-notice--cancelled');
      var msg = el('p', '', 'Checkout was cancelled. Select a membership tier again to continue.');
      notice.appendChild(msg);

      if (container) {
        container.appendChild(notice);
      }
      clearState();
    }

    /**
     * Initialises the widget: fetch tiers and fields, render.
     */
    function init() {
      apiGet('/crm/tiers', {}).then(function (body) {
        var result = unwrapList(body);
        if (!result.items.length) {
          if (status) {
            status.style.display = '';
            status.textContent = 'No membership tiers are available.';
          }
          return;
        }

        renderTiers(result.items);

        apiGet('/crm/fields', { entityType: 'contact' }).then(function (fieldsBody) {
          var fieldsResult = unwrapList(fieldsBody);
          state.fields = fieldsResult.items || [];

          var urlState = currentState();
          if (urlState === SUCCESS_MARKER) {
            showCheckoutConfirmation();
          } else if (urlState === CANCEL_MARKER) {
            showCheckoutCancelled();
          }
        }).catch(function () {
          // Fallback: no custom fields.
          state.fields = [];
        });
      }).catch(function () {
        if (status) {
          status.style.display = '';
          status.textContent = 'Unable to load membership options.';
        }
      });
    }

    init();
  }

  /**
   * Initialises all membership widgets on the page.
   */
  function initAll() {
    var nodes = document.querySelectorAll('.agend-memberships[data-agend-memberships-config]');
    Array.prototype.forEach.call(nodes, initWidget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
