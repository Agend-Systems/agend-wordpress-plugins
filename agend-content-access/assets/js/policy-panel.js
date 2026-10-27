/**
 * Agend Access panel behaviour.
 *
 * Presentation only. Every access decision is made by Agend, and the panel's
 * validation is a convenience: the save handler re-validates server-side and
 * refuses anything it cannot turn into a valid policy, so disabling JavaScript
 * cannot widen an audience.
 *
 * Each set-mode audience (plans, segments, entitlements) has a list marked
 * `data-agend-access-set="<mode>"`, and the radio for that mode has the same
 * value. The list for the chosen audience shows and the rest hide. Nothing here
 * names a mode, so a further list needs markup and no change to this file.
 *
 * Listeners are delegated from the document rather than bound to the panel's
 * own elements. The block editor moves its meta boxes into its own layout after
 * load, and a listener on the document survives that where one tied to a node
 * the editor replaces would not.
 */
(function () {
	'use strict';

	var MODE_FIELD = 'agend_access_mode';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
			return;
		}
		document.addEventListener('DOMContentLoaded', fn);
	}

	function each(list, fn) {
		Array.prototype.forEach.call(list, fn);
	}

	/**
	 * The audience chosen in a panel, or an empty string when none is.
	 */
	function chosenMode(panel) {
		var checked = panel.querySelector('input[name="' + MODE_FIELD + '"]:checked');

		return checked ? checked.value : '';
	}

	/**
	 * Shows the list for the chosen audience and hides the others.
	 */
	function syncSets(panel) {
		var mode = chosenMode(panel);

		each(panel.querySelectorAll('[data-agend-access-set]'), function (set) {
			set.hidden = set.getAttribute('data-agend-access-set') !== mode;
		});
	}

	/**
	 * Filters the rows of the list a search box belongs to.
	 */
	function filterSet(input) {
		var set = input.closest('[data-agend-access-set]');

		if (!set) {
			return;
		}

		var needle = input.value.trim().toLowerCase();

		each(set.querySelectorAll('[data-agend-access-item]'), function (row) {
			var name = row.querySelector('.agend-access-item-name');
			var checkbox = row.querySelector('input[type="checkbox"]');
			var haystack = name ? name.textContent.toLowerCase() : '';

			// A checked item is never hidden by the filter. Hiding a selection
			// would let an editor lose track of what the policy actually
			// contains.
			var keep =
				needle === '' ||
				haystack.indexOf(needle) !== -1 ||
				(checkbox && checkbox.checked);

			row.hidden = !keep;
		});
	}

	ready(function () {
		var panels = document.querySelectorAll('.agend-access-panel');

		if (!panels.length) {
			return;
		}

		document.addEventListener('change', function (event) {
			var target = event.target;

			if (!target || target.name !== MODE_FIELD) {
				return;
			}

			var panel = target.closest('.agend-access-panel');

			if (panel) {
				syncSets(panel);
			}
		});

		document.addEventListener('input', function (event) {
			var target = event.target;

			if (target && target.hasAttribute && target.hasAttribute('data-agend-access-filter')) {
				filterSet(target);
			}
		});

		each(panels, syncSets);
	});
})();
