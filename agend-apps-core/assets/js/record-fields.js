/**
 * Agend template widgets: progressive enhancement.
 *
 * The Agend Image widget's "parent" placement cannot paint a per-record URL
 * onto its parent Elementor container from PHP (the container's markup is
 * rendered by Elementor, not by us), so the widget emits a placeholder box
 * carrying the style and this script copies it onto the nearest container.
 * Exposed as window.agendRecordFields.apply(root) so the catalogue scripts can
 * re-run it after inserting REST-rendered card fragments.
 */
(function () {
  'use strict';

  function paintParent(el) {
    if (el.getAttribute('data-agend-bg-applied') === '1') {
      return;
    }
    var widget = el.closest('.elementor-widget');
    var host = widget && widget.parentElement
      ? widget.parentElement.closest('.e-con, .elementor-column, .elementor-section, .agend-ev-card-link, .agend-lms-card-link')
      : null;
    if (!host) {
      return;
    }
    var style = el.getAttribute('data-agend-bg-style') || '';
    style.split(';').forEach(function (decl) {
      var idx = decl.indexOf(':');
      if (idx === -1) {
        return;
      }
      var prop = decl.slice(0, idx).trim();
      var value = decl.slice(idx + 1).trim();
      if (prop && value) {
        host.style.setProperty(prop, value);
      }
    });
    if (!host.style.backgroundRepeat) {
      host.style.backgroundRepeat = 'no-repeat';
    }
    el.setAttribute('data-agend-bg-applied', '1');
  }

  function apply(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var nodes = scope.querySelectorAll('[data-agend-bg-target="parent"]');
    for (var i = 0; i < nodes.length; i++) {
      paintParent(nodes[i]);
    }
  }

  window.agendRecordFields = window.agendRecordFields || {};
  window.agendRecordFields.apply = apply;

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function () { apply(document); });
  } else {
    apply(document);
  }

  // Editor live preview re-renders a widget in place; re-apply for that element.
  if (window.jQuery) {
    window.jQuery(window).on('elementor/frontend/init', function () {
      if (window.elementorFrontend && window.elementorFrontend.hooks) {
        window.elementorFrontend.hooks.addAction('frontend/element_ready/agend-record-image.default', function ($scope) {
          apply($scope && $scope[0] ? $scope[0].parentElement || document : document);
        });
      }
    });
  }
})();
