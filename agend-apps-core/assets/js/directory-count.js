/**
 * Agend Directory Count: keeps the number in step with the Directory
 * Catalogue on the page when the widget is set to follow its filtered total.
 * The server has already drawn the whole directory's total, so without a
 * catalogue, or without the setting, there is nothing to do.
 */
(function () {
  'use strict';

  function init(box) {
    var cfg;
    try {
      cfg = JSON.parse(box.getAttribute('data-agend-dc-config'));
    } catch (e) {
      return;
    }
    var number = box.querySelector('.agend-dc__number');
    var label = box.querySelector('.agend-dc__label');
    if (!cfg || !cfg.follow || !number || !label) {
      return;
    }

    document.addEventListener('agend:directory-results', function (event) {
      var pagination = event.detail && event.detail.pagination;
      var total = pagination ? parseInt(pagination.total, 10) : NaN;
      if (!isFinite(total) || total < 0) {
        return;
      }
      number.textContent = total.toLocaleString(document.documentElement.lang || undefined);
      label.textContent = total === 1 && cfg.one ? cfg.one : cfg.other;
    });
  }

  function initAll() {
    Array.prototype.forEach.call(document.querySelectorAll('[data-agend-dc-config]'), init);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }
})();
