/**
 * Member Jobs: a signed-in member's job credits, their own job submissions,
 * and the "Post a job" form.
 *
 * Every change goes through the agend-apps/v1/jobs/me routes, which spend or
 * return the member's credit; nothing here decides that. The page markup is
 * the source of truth for the settings (data-agend-member-jobs-config).
 * Everything from the gateway is written as text, never as HTML.
 */
(function () {
  'use strict';

  var EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  var URL_PATTERN = /^https?:\/\/[^\/\s]+\.[^\/\s]+/i;
  var LIMITS = {
    title: 200,
    reference_number: 100,
    short_description: 500,
    description: 9000,
    company_name: 200,
    company_website: 1000,
    location_city: 100,
    location_state: 100,
    location_postcode: 10,
    location_country: 100,
    application_email: 320,
    application_url: 1000,
    application_instructions: 5000,
    contact_name: 200,
    contact_phone: 50,
  };
  // Answers the routes give that are not about a stale page.
  var OWN_CODES = {
    sign_in: true,
    unavailable: true,
    not_connected: true,
    not_contact: true,
    disabled: true,
    no_credits: true,
    no_member: true,
    busy: true,
    credits_unavailable: true,
    validation_failed: true,
    rate_limited: true,
    not_found: true,
    not_editable: true,
    unconfirmed: true,
    failed: true,
  };
  // Answers that mean the member cannot use the board from here at all.
  var BLOCKING_CODES = { unavailable: true, not_connected: true, not_contact: true, disabled: true, sign_in: true };

  function el(tag, className, text) {
    var node = document.createElement(tag);
    if (className) {
      node.className = className;
    }
    if (text !== undefined && text !== null && text !== '') {
      node.textContent = String(text);
    }
    return node;
  }

  function nonce() {
    return (window.agendApps && window.agendApps.nonce) || '';
  }

  function format(template, value) {
    return String(template || '').replace('%s', value).replace('%d', value);
  }

  function formatDate(iso) {
    if (!iso) {
      return '';
    }
    var date = new Date(iso);
    if (isNaN(date.getTime())) {
      return '';
    }
    try {
      return date.toLocaleDateString(undefined, { day: 'numeric', month: 'long', year: 'numeric' });
    } catch (e) {
      return date.toDateString();
    }
  }

  /** A stored description (paragraphs of HTML) back to the plain text it was typed as. */
  function htmlToText(html) {
    var marked = String(html || '')
      .replace(/<br\s*\/?>/gi, '\n')
      .replace(/<\/(p|div|li|h[1-6])>/gi, '\n\n');
    // A template's content is inert: nothing in it loads or runs.
    var holder = document.createElement('template');
    holder.innerHTML = marked;
    return (holder.content ? holder.content.textContent : holder.textContent || '')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  function idsOf(list) {
    var out = [];
    (Array.isArray(list) ? list : []).forEach(function (entry) {
      var id = entry && typeof entry === 'object' ? entry.id : entry;
      if (id) {
        out.push(String(id).toLowerCase());
      }
    });
    return out;
  }

  function request(cfg, method, path, body) {
    var options = {
      method: method,
      credentials: 'same-origin',
      headers: { Accept: 'application/json', 'X-WP-Nonce': nonce() },
    };
    if (body !== undefined) {
      options.headers['Content-Type'] = 'application/json';
      options.body = JSON.stringify(body);
    }
    return fetch(cfg.endpoint + path, options)
      .then(function (res) {
        return res
          .json()
          .catch(function () {
            return {};
          })
          .then(function (json) {
            json = json || {};
            return { status: res.status, ok: res.ok && json.success === true, json: json };
          });
      })
      .catch(function () {
        return { status: 0, ok: false, json: {} };
      });
  }

  function errorText(result, m) {
    var json = result.json || {};
    if ((result.status === 401 || result.status === 403) && !OWN_CODES[json.code]) {
      return m.form.session;
    }
    return json.message || m.form.failed;
  }

  function jobUrl(cfg, slug) {
    if (!cfg.jobsPage || !slug) {
      return '';
    }
    if (cfg.prettyLinks) {
      return cfg.jobsPage.replace(/\/?$/, '/') + 'job/' + encodeURIComponent(slug) + '/';
    }
    try {
      var url = new URL(cfg.jobsPage, window.location.href);
      url.searchParams.set('agend_job', slug);
      return url.toString();
    } catch (e) {
      return '';
    }
  }

  function sampleState(m) {
    var now = new Date();
    var later = new Date(now.getTime() + 30 * 86400000);
    return {
      credits: { available: 2, next_expiry: later.toISOString() },
      items: [
        { id: 'sample-1', title: 'SMSF Accountant', status: 'pending', submitted_at: now.toISOString() },
        { id: 'sample-2', title: 'Senior Auditor', status: 'published', submitted_at: now.toISOString(), expires_at: later.toISOString() },
        { id: 'sample-3', title: 'Paraplanner', status: 'rejected', submitted_at: now.toISOString(), rejection_reason: m.reason },
      ],
    };
  }

  function MemberJobs(root, cfg) {
    this.root = root;
    this.cfg = cfg;
    this.m = cfg.messages || { form: {}, status: {}, actions: {} };
    this.preview = root.getAttribute('data-agend-member-jobs-preview') === '1';
    this.credits = null;
    this.creditsFailed = false;
    this.items = [];
    this.page = 1;
    this.hasMore = false;
    this.blocked = '';
    this.notice = null;
  }

  MemberJobs.prototype.start = function () {
    if (this.preview) {
      var sample = sampleState(this.m);
      this.credits = sample.credits;
      this.items = sample.items;
      this.drawList();
      return;
    }
    this.load();
  };

  MemberJobs.prototype.load = function () {
    var self = this;
    self.page = 1;
    return Promise.all([request(self.cfg, 'GET', '?page=1'), request(self.cfg, 'GET', '/credits')]).then(function (results) {
      var list = results[0];
      var credits = results[1];

      self.blocked = '';
      if (list.ok) {
        self.items = (list.json.data && list.json.data.items) || [];
        self.hasMore = !!(list.json.data && list.json.data.pagination && list.json.data.pagination.has_next);
      } else if (BLOCKING_CODES[list.json.code]) {
        self.blocked = list.json.message || self.m.loadFailed;
        self.items = [];
      } else {
        self.items = null;
      }

      self.credits = credits.ok ? credits.json.data : null;
      self.creditsFailed = !credits.ok;
      if (!credits.ok && BLOCKING_CODES[credits.json.code] && !self.blocked) {
        self.blocked = credits.json.message || self.m.loadFailed;
      }

      self.drawList();
    });
  };

  MemberJobs.prototype.loadMore = function (button) {
    var self = this;
    button.disabled = true;
    request(self.cfg, 'GET', '?page=' + (self.page + 1)).then(function (result) {
      button.disabled = false;
      if (!result.ok) {
        self.say(errorText(result, self.m), true);
        return;
      }
      self.page += 1;
      self.items = self.items.concat((result.json.data && result.json.data.items) || []);
      self.hasMore = !!(result.json.data && result.json.data.pagination && result.json.data.pagination.has_next);
      self.drawList();
    });
  };

  /** Shows a message in the status line, kept across the next redraw. */
  MemberJobs.prototype.say = function (text, isError) {
    this.notice = text ? { text: text, error: !!isError } : null;
    var status = this.root.querySelector('[data-agend-mj-status]');
    if (status) {
      this.paintStatus(status);
      if (text) {
        status.focus();
      }
    }
  };

  MemberJobs.prototype.paintStatus = function (status) {
    status.textContent = this.notice ? this.notice.text : '';
    status.hidden = !this.notice;
    status.classList.toggle('agend-member-jobs__status--error', !!(this.notice && this.notice.error));
  };

  MemberJobs.prototype.statusNode = function () {
    var status = el('p', 'agend-member-jobs__status');
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('tabindex', '-1');
    status.setAttribute('data-agend-mj-status', '');
    this.paintStatus(status);
    return status;
  };

  MemberJobs.prototype.drawList = function () {
    var self = this;
    var cfg = self.cfg;
    var m = self.m;
    var root = self.root;

    root.textContent = '';

    if (cfg.text.heading) {
      root.appendChild(el('h2', 'agend-member-jobs__heading', cfg.text.heading));
    }
    if (cfg.text.intro) {
      root.appendChild(el('p', 'agend-member-jobs__intro', cfg.text.intro));
    }
    root.appendChild(self.statusNode());

    if (self.blocked) {
      root.appendChild(el('p', 'agend-member-jobs__notice', self.blocked));
      return;
    }

    var bar = el('div', 'agend-member-jobs__credits');
    var canPost = true;

    if (self.credits) {
      var count = parseInt(self.credits.available, 10) || 0;
      var line = el('p', 'agend-member-jobs__credits-text', format(count === 1 ? m.creditsOne : m.creditsMany, count));
      if (count > 0 && self.credits.next_expiry) {
        line.appendChild(document.createTextNode(' ' + format(m.creditsExpiry, formatDate(self.credits.next_expiry))));
      }
      bar.appendChild(line);
      if (count === 0) {
        canPost = false;
        bar.appendChild(el('p', 'agend-member-jobs__credits-none', cfg.text.noCredits));
      }
    } else if (self.creditsFailed) {
      bar.appendChild(el('p', 'agend-member-jobs__credits-text', m.creditsUnknown));
    }

    var actions = el('div', 'agend-member-jobs__toolbar');
    if (canPost) {
      var post = el('button', 'agend-member-jobs__button', cfg.text.post);
      post.type = 'button';
      post.disabled = self.preview;
      post.addEventListener('click', function () {
        self.say('');
        self.drawForm(null);
      });
      actions.appendChild(post);
    }
    if (cfg.buyUrl && cfg.text.buy) {
      var buy = el('a', 'agend-member-jobs__link', cfg.text.buy);
      buy.href = cfg.buyUrl;
      actions.appendChild(buy);
    }
    bar.appendChild(actions);
    root.appendChild(bar);

    if (self.items === null) {
      root.appendChild(el('p', 'agend-member-jobs__notice', m.loadFailed));
      return;
    }

    if (!self.items.length) {
      root.appendChild(el('p', 'agend-member-jobs__empty', m.none));
      return;
    }

    var list = el('ul', 'agend-member-jobs__list');
    self.items.forEach(function (item) {
      list.appendChild(self.drawItem(item));
    });
    root.appendChild(list);

    if (self.hasMore) {
      var more = el('button', 'agend-member-jobs__more', m.more);
      more.type = 'button';
      more.addEventListener('click', function () {
        self.loadMore(more);
      });
      root.appendChild(more);
    }
  };

  MemberJobs.prototype.drawItem = function (item) {
    var self = this;
    var m = self.m;
    var status = String(item.status || '');
    var li = el('li', 'agend-member-jobs__item agend-member-jobs__item--' + status.replace(/[^a-z-]/g, ''));

    var head = el('div', 'agend-member-jobs__item-head');
    head.appendChild(el('h3', 'agend-member-jobs__title', item.title || ''));
    head.appendChild(el('span', 'agend-member-jobs__badge agend-member-jobs__badge--' + status.replace(/[^a-z-]/g, ''), (m.status && m.status[status]) || status));
    li.appendChild(head);

    var meta = [];
    if (item.submitted_at) {
      meta.push(format(m.submittedOn, formatDate(item.submitted_at)));
    }
    if (status === 'published' && item.expires_at) {
      meta.push(format(m.expiresOn, formatDate(item.expires_at)));
    }
    if (meta.length) {
      li.appendChild(el('p', 'agend-member-jobs__meta', meta.join(' · ')));
    }
    if (status === 'rejected' && item.rejection_reason) {
      var reason = el('p', 'agend-member-jobs__reason');
      reason.appendChild(el('strong', '', m.reason));
      reason.appendChild(document.createTextNode(' ' + item.rejection_reason));
      li.appendChild(reason);
    }

    var a = m.actions || {};
    var buttons = el('div', 'agend-member-jobs__actions');

    function button(label, onClick) {
      var b = el('button', 'agend-member-jobs__action', label);
      b.type = 'button';
      b.disabled = self.preview;
      b.addEventListener('click', onClick);
      buttons.appendChild(b);
      return b;
    }

    function confirmThen(question, run) {
      buttons.textContent = '';
      buttons.appendChild(el('span', 'agend-member-jobs__confirm', question));
      var yes = button(a.yes, function () {
        yes.disabled = true;
        no.disabled = true;
        run();
      });
      var no = button(a.no, function () {
        li.parentNode.replaceChild(self.drawItem(item), li);
      });
      yes.focus();
    }

    if (status === 'pending') {
      button(a.edit, function () {
        self.say('');
        self.drawForm(item);
      });
      button(a.withdraw, function () {
        confirmThen(a.withdrawConfirm, function () {
          self.act(item, '/withdraw', {}, a.withdrawn);
        });
      });
    } else if (status === 'published') {
      var url = jobUrl(self.cfg, item.slug);
      if (url) {
        var view = el('a', 'agend-member-jobs__action agend-member-jobs__action--link', a.view);
        view.href = url;
        buttons.appendChild(view);
      }
      button(a.filled, function () {
        confirmThen(a.filledConfirm, function () {
          self.act(item, '/close', { filled: true }, a.closed);
        });
      });
      button(a.close, function () {
        confirmThen(a.closeConfirm, function () {
          self.act(item, '/close', { filled: false }, a.closed);
        });
      });
    }

    if (buttons.childNodes.length) {
      li.appendChild(buttons);
    }
    return li;
  };

  MemberJobs.prototype.act = function (item, suffix, body, done) {
    var self = this;
    request(self.cfg, 'POST', '/' + encodeURIComponent(item.id) + suffix, body).then(function (result) {
      if (result.ok) {
        self.notice = { text: done, error: false };
      } else {
        self.notice = { text: errorText(result, self.m), error: true };
      }
      self.load().then(function () {
        var status = self.root.querySelector('[data-agend-mj-status]');
        if (status) {
          status.focus();
        }
      });
    });
  };

  /* ---------------------------------------------------------------- form -- */

  MemberJobs.prototype.drawForm = function (item) {
    var self = this;
    var cfg = self.cfg;
    var f = self.m.form;
    var form = cfg.form || {};
    var hidden = form.hidden || [];
    var required = form.required || [];
    var editing = !!item;
    var root = self.root;
    var uid = 'agend-mj-' + Math.random().toString(36).slice(2, 8);
    var controls = {};

    root.textContent = '';
    root.appendChild(el('h2', 'agend-member-jobs__heading', editing ? f.editHeading : f.newHeading));
    root.appendChild(self.statusNode());

    var formNode = el('form', 'agend-member-jobs__form');
    formNode.noValidate = true;

    function shown(key) {
      return hidden.indexOf(key) === -1;
    }

    function isRequired(key) {
      return required.indexOf(key) !== -1;
    }

    function value(name) {
      return item && item[name] !== undefined && item[name] !== null ? item[name] : '';
    }

    function wrap(name, label, control, need, hint) {
      var box = el('div', 'agend-member-jobs__field agend-member-jobs__field--' + name);
      var labelNode = el('label', 'agend-member-jobs__label', label);
      labelNode.setAttribute('for', control.id);
      if (need) {
        var star = el('span', 'agend-member-jobs__required', ' *');
        star.setAttribute('aria-hidden', 'true');
        labelNode.appendChild(star);
      }
      box.appendChild(labelNode);
      var describedBy = [];
      if (hint) {
        var hintNode = el('p', 'agend-member-jobs__hint', hint);
        hintNode.id = control.id + '-hint';
        describedBy.push(hintNode.id);
        box.appendChild(hintNode);
      }
      box.appendChild(control);
      var error = el('p', 'agend-member-jobs__error');
      error.id = control.id + '-error';
      error.hidden = true;
      describedBy.push(error.id);
      control.setAttribute('aria-describedby', describedBy.join(' '));
      if (need) {
        control.setAttribute('aria-required', 'true');
      }
      box.appendChild(error);
      controls[name] = { input: control, error: error, required: need };
      return box;
    }

    function input(name, type, need, hint) {
      var control = el(type === 'textarea' ? 'textarea' : 'input', 'agend-member-jobs__input');
      control.id = uid + '-' + name;
      control.name = name;
      if (type === 'textarea') {
        control.rows = name === 'description' ? 10 : 3;
      } else {
        control.type = type;
      }
      if (LIMITS[name]) {
        control.maxLength = LIMITS[name];
      }
      var current = value(name);
      control.value = name === 'description' ? htmlToText(current) : String(current);
      return wrap(name, f.labels[name], control, need, hint || (f.hints && f.hints[name]));
    }

    function select(name, options, need, current, label) {
      var control = el('select', 'agend-member-jobs__input');
      control.id = uid + '-' + name;
      control.name = name;
      var blank = el('option', '', f.choose);
      blank.value = '';
      control.appendChild(blank);
      options.forEach(function (option) {
        var node = el('option', '', option.label);
        node.value = option.value;
        if (String(current) === String(option.value)) {
          node.selected = true;
        }
        control.appendChild(node);
      });
      return wrap(name, label || f.labels[name], control, need);
    }

    function optionsOf(map) {
      return Object.keys(map || {}).map(function (key) {
        return { value: key, label: map[key] };
      });
    }

    function section(key) {
      var fieldset = el('fieldset', 'agend-member-jobs__section');
      fieldset.appendChild(el('legend', 'agend-member-jobs__legend', f.sections[key]));
      formNode.appendChild(fieldset);
      return fieldset;
    }

    function row(parent) {
      var r = el('div', 'agend-member-jobs__row');
      parent.appendChild(r);
      return r;
    }

    // The role.
    var role = section('role');
    var r1 = row(role);
    r1.appendChild(input('title', 'text', true));
    if (shown('reference_number')) {
      r1.appendChild(input('reference_number', 'text', isRequired('reference_number')));
    }
    var r2 = row(role);
    r2.appendChild(select('employment_type', optionsOf(f.options.employment_type), true, value('employment_type')));
    r2.appendChild(select('location_type', optionsOf(f.options.location_type), true, value('location_type')));
    if (shown('experience_level')) {
      r2.appendChild(select('experience_level', optionsOf(f.options.experience_level), isRequired('experience_level'), value('experience_level')));
    }

    var categoryIds = idsOf(item && item.categories);
    var r3 = row(role);
    if (shown('category') && (form.categories || []).length) {
      r3.appendChild(
        select(
          'category',
          form.categories.map(function (c) {
            return { value: c.id, label: c.name };
          }),
          isRequired('category'),
          categoryIds[0] || ''
        )
      );
    }

    // Tag questions. Tags a job carries that no question offers are kept.
    var itemTags = idsOf(item && item.tags);
    var offered = {};
    var tagGroups = [];
    (form.tagGroups || []).forEach(function (group, index) {
      var name = 'tags_' + index;
      group.tags.forEach(function (tag) {
        offered[String(tag.id).toLowerCase()] = true;
      });
      if (group.style === 'checkboxes') {
        var box = el('fieldset', 'agend-member-jobs__field agend-member-jobs__field--checks');
        box.appendChild(el('legend', 'agend-member-jobs__label', group.label));
        var boxes = [];
        group.tags.forEach(function (tag) {
          var label = el('label', 'agend-member-jobs__check');
          var check = document.createElement('input');
          check.type = 'checkbox';
          check.value = String(tag.id).toLowerCase();
          check.checked = itemTags.indexOf(check.value) !== -1;
          label.appendChild(check);
          label.appendChild(document.createTextNode(' ' + tag.name));
          box.appendChild(label);
          boxes.push(check);
        });
        r3.appendChild(box);
        tagGroups.push({ checks: boxes });
      } else {
        var current = '';
        group.tags.forEach(function (tag) {
          if (itemTags.indexOf(String(tag.id).toLowerCase()) !== -1 && !current) {
            current = String(tag.id).toLowerCase();
          }
        });
        r3.appendChild(
          select(
            name,
            group.tags.map(function (tag) {
              return { value: String(tag.id).toLowerCase(), label: tag.name };
            }),
            !!group.required,
            current,
            group.label
          )
        );
        tagGroups.push({ name: name });
      }
    });
    var keptTags = itemTags.filter(function (id) {
      return !offered[id];
    });

    if (shown('short_description')) {
      role.appendChild(input('short_description', 'textarea', isRequired('short_description')));
    }
    if (shown('description')) {
      role.appendChild(input('description', 'textarea', isRequired('description')));
    }

    // Employer.
    if (shown('company_name') || shown('company_website')) {
      var employer = row(section('employer'));
      if (shown('company_name')) {
        employer.appendChild(input('company_name', 'text', isRequired('company_name')));
      }
      if (shown('company_website')) {
        employer.appendChild(input('company_website', 'url', isRequired('company_website')));
      }
    }

    // Location.
    var locationKeys = ['location_city', 'location_state', 'location_postcode', 'location_country'].filter(shown);
    if (locationKeys.length) {
      var place = row(section('location'));
      locationKeys.forEach(function (key) {
        if (key === 'location_state' && (form.states || []).length) {
          var states = form.states.slice();
          var currentState = String(value('location_state'));
          if (currentState && states.indexOf(currentState) === -1) {
            states.push(currentState);
          }
          place.appendChild(
            select(
              key,
              states.map(function (s) {
                return { value: s, label: s };
              }),
              isRequired(key),
              currentState
            )
          );
        } else {
          place.appendChild(input(key, 'text', isRequired(key)));
        }
      });
    }

    // Salary.
    if (shown('salary')) {
      var pay = section('salary');
      var payRow = row(pay);
      payRow.appendChild(input('salary_min', 'number', isRequired('salary')));
      payRow.appendChild(input('salary_max', 'number', false));
      payRow.appendChild(select('salary_period', optionsOf(f.options.salary_period), false, value('salary_period') || 'annual'));
      ['salary_min', 'salary_max'].forEach(function (name) {
        controls[name].input.min = '0';
        controls[name].input.step = '1';
        controls[name].input.inputMode = 'numeric';
      });
      var showLabel = el('label', 'agend-member-jobs__check');
      var show = document.createElement('input');
      show.type = 'checkbox';
      show.checked = item ? item.show_salary !== false : true;
      showLabel.appendChild(show);
      showLabel.appendChild(document.createTextNode(' ' + f.labels.show_salary));
      pay.appendChild(showLabel);
      controls.show_salary = { checkbox: show };
    }

    // How to apply.
    var applyKeys = [];
    if (shown('application')) {
      applyKeys.push('application_email', 'application_url');
    }
    ['application_instructions', 'contact_name', 'contact_phone'].forEach(function (key) {
      if (shown(key)) {
        applyKeys.push(key);
      }
    });
    if (applyKeys.length) {
      var apply = section('apply');
      var applyRow = null;
      var contactRow = null;
      applyKeys.forEach(function (key) {
        if (key === 'application_instructions') {
          apply.appendChild(input(key, 'textarea', isRequired(key)));
          return;
        }
        var type = key === 'application_email' ? 'email' : key === 'application_url' ? 'url' : key === 'contact_phone' ? 'tel' : 'text';
        // Email and link are one requirement, checked together in validate().
        var need = key === 'application_email' || key === 'application_url' ? false : isRequired(key);
        var target;
        if (key === 'contact_name' || key === 'contact_phone') {
          contactRow = contactRow || row(apply);
          target = contactRow;
        } else {
          applyRow = applyRow || row(apply);
          target = applyRow;
        }
        target.appendChild(input(key, type, need));
      });
    }

    var footer = el('div', 'agend-member-jobs__footer');
    if (!editing) {
      footer.appendChild(el('p', 'agend-member-jobs__note', f.submitNote));
    }
    var submit = el('button', 'agend-member-jobs__button', editing ? f.save : f.submit);
    submit.type = 'submit';
    var cancel = el('button', 'agend-member-jobs__link', f.cancel);
    cancel.type = 'button';
    cancel.addEventListener('click', function () {
      self.say('');
      self.drawList();
    });
    footer.appendChild(submit);
    footer.appendChild(cancel);
    formNode.appendChild(footer);
    root.appendChild(formNode);

    function text(name) {
      return controls[name] && controls[name].input ? String(controls[name].input.value || '').trim() : '';
    }

    function setError(name, message) {
      var c = controls[name];
      if (!c || !c.input) {
        return;
      }
      c.error.textContent = message || '';
      c.error.hidden = !message;
      if (message) {
        c.input.setAttribute('aria-invalid', 'true');
      } else {
        c.input.removeAttribute('aria-invalid');
      }
    }

    Object.keys(controls).forEach(function (name) {
      if (controls[name].input) {
        controls[name].input.addEventListener('input', function () {
          setError(name, '');
        });
      }
    });

    function validate() {
      var errors = {};
      Object.keys(controls).forEach(function (name) {
        var c = controls[name];
        if (!c.input) {
          return;
        }
        var v = text(name);
        if (c.required && !v) {
          errors[name] = f.required;
        } else if (v && LIMITS[name] && v.length > LIMITS[name]) {
          errors[name] = f.tooLong;
        }
      });
      if (text('application_email') && !EMAIL_PATTERN.test(text('application_email'))) {
        errors.application_email = f.email;
      }
      ['application_url', 'company_website'].forEach(function (name) {
        if (text(name) && !URL_PATTERN.test(text(name))) {
          errors[name] = f.url;
        }
      });
      ['salary_min', 'salary_max'].forEach(function (name) {
        if (text(name) && !/^\d+$/.test(text(name))) {
          errors[name] = f.number;
        }
      });
      if (!errors.salary_min && !errors.salary_max && text('salary_min') && text('salary_max') && parseInt(text('salary_min'), 10) > parseInt(text('salary_max'), 10)) {
        errors.salary_max = f.salaryRange;
      }
      if (isRequired('application') && shown('application') && !text('application_email') && !text('application_url')) {
        errors.application_email = f.applyNeeded;
      }
      return errors;
    }

    function payload() {
      var body = {};
      Object.keys(controls).forEach(function (name) {
        if (controls[name].input && name !== 'category' && name.indexOf('tags_') !== 0) {
          body[name] = text(name);
        }
      });
      if (controls.show_salary) {
        body.show_salary = !!controls.show_salary.checkbox.checked;
      }
      if (controls.category) {
        // One category from the form; any others the job had are kept.
        var chosen = text('category');
        body.category_ids = (chosen ? [chosen] : []).concat(
          categoryIds.filter(function (id) {
            return id !== chosen && !(form.categories || []).some(function (c) {
              return String(c.id).toLowerCase() === id;
            });
          })
        );
      }
      if (tagGroups.length || keptTags.length) {
        var tags = keptTags.slice();
        tagGroups.forEach(function (group) {
          if (group.checks) {
            group.checks.forEach(function (check) {
              if (check.checked) {
                tags.push(check.value);
              }
            });
          } else if (text(group.name)) {
            tags.push(text(group.name));
          }
        });
        body.tag_ids = tags;
      }
      return body;
    }

    function showErrors(errors) {
      var first = null;
      Object.keys(errors).forEach(function (name) {
        setError(name, errors[name]);
        if (!first && controls[name] && controls[name].input) {
          first = controls[name].input;
        }
      });
      if (first) {
        first.focus();
      }
    }

    formNode.addEventListener('submit', function (event) {
      event.preventDefault();
      if (formNode.getAttribute('aria-busy') === 'true') {
        return;
      }
      Object.keys(controls).forEach(function (name) {
        setError(name, '');
      });

      var errors = validate();
      if (Object.keys(errors).length) {
        self.say(f.fix, true);
        showErrors(errors);
        return;
      }

      formNode.setAttribute('aria-busy', 'true');
      submit.disabled = true;
      self.say(editing ? f.saving : f.sending);

      var send = editing
        ? request(cfg, 'PATCH', '/' + encodeURIComponent(item.id), payload())
        : request(cfg, 'POST', '', payload());

      send.then(function (result) {
        formNode.setAttribute('aria-busy', 'false');
        submit.disabled = false;
        var json = result.json || {};

        if (result.ok || json.code === 'unconfirmed') {
          self.notice = result.ok
            ? { text: editing ? self.m.actions.saved : cfg.text.success, error: false }
            : { text: json.message, error: true };
          self.load().then(function () {
            var status = self.root.querySelector('[data-agend-mj-status]');
            if (status) {
              status.focus();
            }
          });
          return;
        }

        if (json.code === 'no_credits') {
          // The balance changed since the page loaded: back to the list.
          self.notice = { text: json.message, error: true };
          self.load();
          return;
        }

        if (json.fields) {
          showErrors(json.fields);
        }
        self.say(errorText(result, self.m), true);
      });
    });

    var first = controls.title && controls.title.input;
    if (first) {
      first.focus();
    }
  };

  function parseConfig(root) {
    try {
      return JSON.parse(root.getAttribute('data-agend-member-jobs-config') || '{}');
    } catch (e) {
      return {};
    }
  }

  function init(root) {
    if (root.getAttribute('data-agend-member-jobs-ready') === '1') {
      return;
    }
    var cfg = parseConfig(root);
    if (!cfg.endpoint || !cfg.text) {
      return;
    }
    root.setAttribute('data-agend-member-jobs-ready', '1');
    new MemberJobs(root, cfg).start();
  }

  function initAll() {
    var roots = document.querySelectorAll('.agend-member-jobs[data-agend-member-jobs-config]');
    for (var i = 0; i < roots.length; i++) {
      init(roots[i]);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  window.addEventListener('elementor/frontend/init', function () {
    if (window.elementorFrontend && window.elementorFrontend.hooks) {
      window.elementorFrontend.hooks.addAction('frontend/element_ready/agend-member-jobs.default', function ($el) {
        var node = $el && $el[0] ? $el[0] : $el;
        var root = node && node.querySelector ? node.querySelector('.agend-member-jobs[data-agend-member-jobs-config]') : null;
        if (root) {
          init(root);
        }
      });
    }
  });
})();
