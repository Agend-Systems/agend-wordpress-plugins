/**
 * Category levels for the directory Category filter: what a Top level or a
 * Subcategories of selected filter offers and shows, worked out from the
 * category tree and the catalogue's one `categories` selection.
 *
 * Both levels write the same selection rather than a key each, so the query
 * builders and Clear need no change: a selected top-level category is shown by
 * the Top level filter and its children are offered by the other, and a
 * selected subcategory is shown by the Subcategories filter with its parent
 * shown by the Top level filter.
 *
 * Pure: no DOM, no requests. assets/js/filters.js draws the controls from what
 * this returns, and tests/js/category-levels.test.mjs exercises it under Node.
 *
 * Exposed as window.agendFilters.categoryLevels.
 */
(function (root) {
  'use strict';

  var ALL = 'all';
  var TOP = 'top';
  var SUB = 'sub';

  function level(value) {
    return value === TOP || value === SUB ? value : ALL;
  }

  function idOf(value) {
    return value === undefined || value === null ? '' : String(value);
  }

  /**
   * The categories as the levels read them, in the order given.
   *
   * @param {Array} items Categories as the API returns them: id, name,
   *                      parent_id, and is_active when the response carries it.
   * @return {Array<{id: string, name: string, parentId: string}>} `parentId`
   *         is '' for a category with no parent. A category marked inactive is
   *         left out even though the request already asks for active ones only.
   */
  function tree(items) {
    var nodes = [];
    var seen = {};
    (Array.isArray(items) ? items : []).forEach(function (item) {
      if (!item || typeof item !== 'object' || item.is_active === false) {
        return;
      }
      var id = idOf(item.id);
      if (id === '' || seen[id]) {
        return;
      }
      seen[id] = true;
      var name = idOf(item.name);
      nodes.push({ id: id, name: name !== '' ? name : id, parentId: idOf(item.parent_id) });
    });
    return nodes;
  }

  function index(nodes) {
    var byId = {};
    nodes.forEach(function (node) {
      byId[node.id] = node;
    });
    return byId;
  }

  function selectionOf(selection) {
    return (Array.isArray(selection) ? selection : []).map(idOf).filter(function (id) {
      return id !== '';
    });
  }

  // The top-level category a selected id belongs to: itself when it has no
  // parent, its parent when that parent is top level, '' otherwise (an unknown
  // id, or a third level, which is out of scope).
  function topOf(byId, id) {
    var node = byId[id];
    if (!node) {
      return '';
    }
    if (node.parentId === '') {
      return node.id;
    }
    var parent = byId[node.parentId];
    return parent && parent.parentId === '' ? parent.id : '';
  }

  // The one top-level category the whole selection sits under, or ''. Several
  // ids under one parent still name it; ids under two parents name none.
  function selectedTop(byId, selected) {
    var top = '';
    for (var i = 0; i < selected.length; i++) {
      var current = topOf(byId, selected[i]);
      if (current === '' || (top !== '' && current !== top)) {
        return '';
      }
      top = current;
    }
    return top;
  }

  function childrenOf(nodes, parentId) {
    return nodes.filter(function (node) {
      return parentId !== '' && node.parentId === parentId;
    });
  }

  function option(node) {
    return { id: node.id, name: node.name };
  }

  /**
   * What a filter at a level offers, and which of those it shows as chosen.
   *
   * @param {Array}  nodes     tree() output.
   * @param {Array}  selection The catalogue's `categories` selection.
   * @param {string} which     'all', 'top' or 'sub'.
   * @return {{options: Array<{id: string, name: string}>, selected: string}}
   *         `selected` is '' for the filter's any option. No options means the
   *         filter has nothing to offer and is hidden.
   */
  function view(nodes, selection, which) {
    var byId = index(nodes);
    var selected = selectionOf(selection);
    var one = selected.length === 1 ? selected[0] : '';
    var options;
    var shown = '';

    switch (level(which)) {
      case TOP:
        options = nodes.filter(function (node) {
          return node.parentId === '';
        });
        shown = one !== '' ? topOf(byId, one) : '';
        break;

      case SUB:
        options = childrenOf(nodes, selectedTop(byId, selected));
        shown = one !== '' && byId[one] && byId[one].parentId !== '' ? one : '';
        break;

      default:
        options = nodes;
        shown = one;
        break;
    }

    var offered = options.map(option);
    var listed = offered.some(function (entry) {
      return entry.id === shown;
    });
    return { options: offered, selected: listed ? shown : '' };
  }

  /**
   * The selection after a visitor picks a category at a level, or its any
   * option.
   *
   * @param {Array}  nodes     tree() output.
   * @param {Array}  selection The catalogue's `categories` selection.
   * @param {string} which     'all', 'top' or 'sub'.
   * @param {string} id        The category picked, or '' for the any option.
   * @return {string[]} A new selection; the one given is never changed.
   */
  function choose(nodes, selection, which, id) {
    var picked = idOf(id);
    if (picked !== '') {
      // A pick replaces the selection, so a subcategory of another parent, or
      // several ids left by another Category filter, never linger.
      return [picked];
    }
    if (level(which) === SUB) {
      // The subcategory's any option widens back to the parent the Top level
      // filter still shows.
      var top = selectedTop(index(nodes), selectionOf(selection));
      return top !== '' ? [top] : selectionOf(selection);
    }
    return [];
  }

  root.agendFilters = root.agendFilters || {};
  root.agendFilters.categoryLevels = {
    level: level,
    tree: tree,
    view: view,
    choose: choose,
  };
})(typeof window !== 'undefined' ? window : this);
