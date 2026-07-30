/**
 * Agend Access panel behaviour.
 *
 * Presentation only. Every access decision is made by Agend, and the panel's
 * validation is a convenience: the save handler re-validates server-side and
 * refuses anything it cannot turn into a valid policy, so disabling JavaScript
 * cannot widen an audience.
 */
(function () {
	'use strict';

	function ready(fn) {
		if (document.readyState !== 'loading') {
			fn();
			return;
		}
		document.addEventListener('DOMContentLoaded', fn);
	}

	ready(function () {
		var panel = document.querySelector('.agend-access-panel');

		if (!panel) {
			return;
		}

		var plansBlock = panel.querySelector('[data-agend-access-plans]');
		var modeInputs = panel.querySelectorAll('input[name="agend_access_mode"]');
		var filter = panel.querySelector('[data-agend-access-filter]');

		function syncPlanVisibility() {
			if (!plansBlock) {
				return;
			}

			var selected = panel.querySelector('input[name="agend_access_mode"]:checked');
			var showPlans = selected && selected.value === 'selected_tiers';

			plansBlock.hidden = !showPlans;
		}

		Array.prototype.forEach.call(modeInputs, function (input) {
			input.addEventListener('change', syncPlanVisibility);
		});

		if (filter) {
			filter.addEventListener('input', function () {
				var needle = filter.value.trim().toLowerCase();

				Array.prototype.forEach.call(
					panel.querySelectorAll('[data-agend-access-plan]'),
					function (row) {
						var name = row.querySelector('.agend-access-plan-name');
						var checkbox = row.querySelector('input[type="checkbox"]');
						var haystack = name ? name.textContent.toLowerCase() : '';

						// A checked plan is never hidden by the filter. Hiding a
						// selection would let an editor lose track of what the
						// policy actually contains.
						var keep =
							needle === '' ||
							haystack.indexOf(needle) !== -1 ||
							(checkbox && checkbox.checked);

						row.hidden = !keep;
					}
				);
			});
		}

		syncPlanVisibility();
	});
})();
