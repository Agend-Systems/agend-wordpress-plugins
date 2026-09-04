/**
 * Agend Export Report widget.
 *
 * A button that downloads one report, or a dropdown of several with one button
 * beside it. Parameters are resolved when the visitor presses the button, so a
 * row reading from the Directory Catalogue picks up whatever they have
 * filtered it down to by then.
 *
 * The download goes through the Agend Apps Core proxy: the API key and the
 * member's bearer stay on the server, and the gateway decides whether this
 * caller may reach the report at all.
 */
(function () {
  'use strict';

  function restBase(cfg) {
    var base = cfg.restBase || (window.agendApps && window.agendApps.restUrl) || '/wp-json/agend-apps/v1';
    return String(base).replace(/\/$/, '');
  }

  function nonce() {
    return (window.agendApps && window.agendApps.nonce) || '';
  }

  // Report names are fetched once per page so a dropdown shows current names
  // rather than whatever they were called when the template was saved.
  var reportIndex = null;

  function loadReportIndex(cfg) {
    if (!reportIndex) {
      reportIndex = fetch(restBase(cfg) + '/directory/export-reports', {
        headers: nonce() ? { 'X-WP-Nonce': nonce() } : {},
        credentials: 'same-origin',
      })
        .then(function (res) { return res.json(); })
        .then(function (body) {
          var list = (body && Array.isArray(body.data)) ? body.data : [];
          var byId = {};
          list.forEach(function (r) { byId[r.id] = r; });
          return byId;
        })
        .catch(function () { return {}; });
    }
    return reportIndex;
  }

  // The catalogue script publishes its live filter state; a parameter row
  // reading from the catalogue takes its value from there at click time.
  function catalogueState() {
    var registry = window.agendCatalogues || {};
    return (registry.listing && registry.listing.state) || null;
  }

  function stateValue(row) {
    var state = catalogueState();
    if (!state) {
      return '';
    }
    var keysByFilter = {
      search: 'search',
      category: 'categories',
      tag: 'tag_ids',
      badge: 'badge_ids',
      rating: 'rating',
      featured: 'featured',
    };

    if (row.filter === 'custom_field') {
      var map = state.custom_fields || {};
      var picked = map[row.customKey];
      return Array.isArray(picked) ? picked.join(',') : String(picked === undefined || picked === null ? '' : picked);
    }

    var key = keysByFilter[row.filter];
    if (!key) {
      return '';
    }
    var value = state[key];
    if (Array.isArray(value)) {
      return value.join(',');
    }
    // The category filter writes a list, but falls back to a single value.
    if (key === 'categories' && (!value || !value.length)) {
      value = state.category;
    }
    return String(value === undefined || value === null ? '' : value);
  }

  // A report names its parameters by an internal condition id and declares the
  // field each one filters on. The mapping rows are written against the field,
  // so this pairs them up per report at click time.
  function parametersFor(report, cfg) {
    var out = {};
    if (!report || !Array.isArray(report.parameters)) {
      return out;
    }
    report.parameters.forEach(function (param) {
      var row = cfg.parameters.filter(function (r) { return r.field === param.field; })[0];
      if (!row) {
        return;
      }
      var value = row.source === 'catalogue' ? stateValue(row) : row.value;
      if (value !== '' && value !== undefined && value !== null) {
        out[param.name] = value;
      }
    });
    return out;
  }

  function download(root, cfg) {
    var button = root.querySelector('[data-agend-export-submit]');
    var select = root.querySelector('[data-agend-export-select]');
    var formatSelect = root.querySelector('[data-agend-export-format]');
    var reportId = select ? select.value : (cfg.reports[0] && cfg.reports[0].id);
    if (!reportId) {
      return;
    }
    var format = formatSelect ? formatSelect.value : (cfg.formats === 'xlsx' ? 'xlsx' : 'csv');

    var original = button.textContent;
    button.disabled = true;
    button.textContent = cfg.labels.working;
    var note = root.querySelector('.agend-export__error');
    if (note) {
      note.textContent = '';
    }

    loadReportIndex(cfg)
      .then(function (byId) {
        var url = restBase(cfg) + '/directory/export-reports/' + encodeURIComponent(reportId) + '?format=' + encodeURIComponent(format);
        var params = parametersFor(byId[reportId], cfg);
        Object.keys(params).forEach(function (name) {
          url += '&' + encodeURIComponent(name) + '=' + encodeURIComponent(params[name]);
        });
        return fetch(url, {
          headers: nonce() ? { 'X-WP-Nonce': nonce() } : {},
          credentials: 'same-origin',
        });
      })
      .then(function (res) {
        if (!res.ok) {
          throw new Error('export failed: ' + res.status);
        }
        var disposition = res.headers.get('Content-Disposition') || '';
        var match = /filename="?([^";]+)"?/i.exec(disposition);
        var name = match ? match[1] : 'export.' + format;
        return res.blob().then(function (blob) { return { blob: blob, name: name }; });
      })
      .then(function (file) {
        // Handed over as a blob rather than navigated to: a plain link would
        // drop the REST nonce, and an error would replace the page with JSON.
        var href = window.URL.createObjectURL(file.blob);
        var link = document.createElement('a');
        link.href = href;
        link.download = file.name;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        window.URL.revokeObjectURL(href);
      })
      .catch(function () {
        if (!note) {
          note = document.createElement('p');
          note.className = 'agend-export__error';
          root.appendChild(note);
        }
        note.textContent = cfg.labels.failed;
      })
      .then(function () {
        button.disabled = false;
        button.textContent = original;
      });
  }

  function initWidget(root) {
    var cfg;
    try {
      cfg = JSON.parse(root.getAttribute('data-agend-export-config'));
    } catch (e) {
      return;
    }
    if (!cfg || !cfg.reports || !cfg.reports.length) {
      return;
    }

    var select = root.querySelector('[data-agend-export-select]');
    if (select) {
      // Fill in any option the designer left unlabelled with the report's
      // current name.
      loadReportIndex(cfg).then(function (byId) {
        Array.prototype.forEach.call(select.options, function (option) {
          var configured = cfg.reports.filter(function (r) { return r.id === option.value; })[0];
          if (configured && configured.label) {
            return;
          }
          var report = byId[option.value];
          if (report && report.name) {
            option.textContent = report.name;
          }
        });
      });
    }

    root.querySelector('[data-agend-export-submit]').addEventListener('click', function () {
      download(root, cfg);
    });
  }

  function initAll() {
    var nodes = document.querySelectorAll('.agend-export-report[data-agend-export-config]');
    Array.prototype.forEach.call(nodes, initWidget);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
