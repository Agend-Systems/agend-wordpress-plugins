<?php
/**
 * Page-builder-agnostic content-settings schema for the record surfaces
 * (events catalogue, courses catalogue, directory catalogue, and so on).
 *
 * A surface declares its editable settings ONCE here, as plain PHP data, and
 * every presentation adapter (Elementor today, the block editor later) renders
 * its own controls from the same declaration instead of hand-declaring the
 * same settings a second time. This is the single definition of the schema
 * vocabulary both adapters build from:
 *
 * Schema = `array( 'sections' => Section[] )`.
 *
 * Section = `array( 'id' => string, 'label' => string, 'condition' => ?Condition, 'tab' => ?string, 'fields' => Field[] )`.
 * `tab` accepts only `'style'`; a section without the key, or with any other
 * value, is a content section. A style section renders on the Style tab in
 * Elementor and under the block inspector's `InspectorControls group="styles"`
 * slot.
 *
 * Field keys (per-type keys noted under each type):
 * - `name` (string): the setting key. PERSISTED in every saved page that uses
 *   this surface — never change one once shipped.
 * - `label` (string).
 * - `type` (string): one of the types below.
 * - `default`: the type's default value.
 * - `description` (string, optional): helper text under the control.
 * - `condition` (?Condition, optional): when the field is shown.
 * - `label_block` (bool, optional): render the label on its own line.
 *
 * Types:
 * - `toggle`: boolean `default`. Stored by Elementor as `'yes'`/`''`.
 *   A field transcribed from a control whose original default was
 *   already a literal Elementor value (e.g. `'no'`) may give that string
 *   directly instead; it passes through unconverted.
 * - `colour`: string `default`, a CSS colour. Maps to Elementor's COLOR
 *   control and the block inspector's `ColorPalette`.
 * - `text`, `textarea`: string `default`. Optional `placeholder` (string),
 *   passed through to the control unchanged.
 * - `number`: `min`, `max`, `step` (all optional); numeric `default`.
 * - `select`: `options` (array value => label, OR a callable string resolved
 *   at render time, so an option list sourced from the gateway is only
 *   fetched when an editor actually opens), or `groups` (Elementor SELECT
 *   `groups` shape, also array or callable string). String `default`.
 * - `multiselect`: same `options` shape as `select`; array `default`, optional
 *   — omit it to leave the control's default unset, matching Elementor's own
 *   SELECT2 behaviour when no default is declared.
 * - `template`: a saved-template picker. `placeholder` is the label of the
 *   "no template" entry; options come from
 *   `Agend_Apps_Templates::options( $placeholder )`. String `default` (`''`).
 * - `url`: a URL field. Optional `placeholder` (string) and `show_external`
 *   (bool). `default` is a plain string or Elementor's own
 *   `array( 'url' => ..., 'is_external' => ..., 'nofollow' => ... )` shape;
 *   a renderer reads either through
 *   `agend_apps_records_normalise_url_setting()` (format.php) rather than
 *   assuming which one it got. Maps to Elementor's URL control and the block
 *   inspector's own URL text field.
 * - `media`: an image picker. `default`, Elementor's own
 *   `array( 'url' => ..., 'id' => ... )` shape. Maps to Elementor's MEDIA
 *   control and the block inspector's `MediaUpload`.
 * - `repeater`: a list of rows. `fields` (a nested `Field[]` using this same
 *   vocabulary) shapes one row; `default` (array of rows). `row_label` names
 *   the nested field whose value labels a row, plainly, e.g.
 *   `'row_label' => 'choice_label'`; the Elementor adapter builds its own
 *   `title_field` Mustache template from it
 *   (`'{{{ ' . $row_label . ' }}}'`), so Elementor's template syntax never
 *   appears in the schema for the common case. Optional `title_field`
 *   (string): Elementor's own row-title template, carried verbatim, for the
 *   rare row title `row_label` cannot describe, for example a conditional
 *   expression rather than a bare `{{{ field }}}` wrapper around one field.
 *   An adapter with no equivalent of its own ignores it and falls back to
 *   `row_label`. `row_label` stays the preferred, builder-agnostic way to say
 *   this; reach for `title_field` only when it genuinely cannot.
 * - `note`: `content` (string), no `name` needed but give one for stability.
 *   Renders as static help text.
 * - `heading`: a labelled divider between groups of fields in one
 *   section. `name` for stability only (no value is stored); `label`;
 *   optional `separator` (`'before'`/`'after'`/`'none'`, passed through
 *   as-is); optional `condition`.
 * - `adapter`: a builder-specific control the vocabulary cannot describe (a
 *   repeater, a media picker, a URL field, a colour, or a control that needs
 *   a builder-specific key like `selectors`). The schema records only its
 *   `name` and position so the section keeps its order; the widget supplies
 *   the control's own declaration for that name via
 *   `register_adapter_control( string $name ): void`. Optional `label` and
 *   `description` are for documentation only and are not rendered. Optional
 *   `block` (a Field, using this same vocabulary, e.g. `select`, `number`,
 *   `colour`): describes the control a BLOCK should render for this field,
 *   for the rarer adapter field whose stored VALUE is perfectly describable
 *   even though HOW it is applied is builder-specific. Elementor drives such
 *   a value through CSS `selectors` written by its own control; a block has
 *   no stylesheet of its own to write into, so it applies the same value as
 *   an inline style at render time instead (see the `inline_style` render
 *   opt on record-image's renderer for an example). Elementor ignores this
 *   key entirely and keeps registering its own control via
 *   `register_adapter_control()`, exactly as before;
 *   `agend_apps_records_block_attributes()` is the only reader, deriving one
 *   ordinary attribute from it the same way it derives one from any other
 *   Field, and `schema-inspector.js` renders it the same way too. Reach for
 *   `block` only when the field's stored value needs no adapter-specific
 *   shape of its own (a plain string, number or colour); a field whose value
 *   itself is builder-specific, not just its application, stays adapter-only
 *   with no `block` key.
 *
 * Condition = Elementor's `condition` array shape:
 * `array( 'other_field' => $value )`, or `array( 'other_field!' => $value )`
 * for not-equal; `$value` may be a scalar or a list. Kept as-is because it is
 * simple and both adapters can evaluate it directly.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The content-settings schema for a surface id.
 *
 * Looks up `agend_apps_records_schema_<surface with - as _>()` and, when it
 * exists, runs its result through the `agend_apps_records_surface_schema`
 * filter so a site can add or amend a field once and have it appear in every
 * adapter's editor.
 *
 * @param string $surface Surface id, e.g. 'events-catalogue'.
 * @return array Schema, or `array()` for a surface with no schema yet.
 */
function agend_apps_records_surface_schema( string $surface ): array {
	$function = 'agend_apps_records_schema_' . str_replace( '-', '_', $surface );

	$schema = function_exists( $function ) ? $function() : array();

	/**
	 * Filters a surface's content-settings schema.
	 *
	 * @param array  $schema  The surface's schema (possibly `array()`).
	 * @param string $surface The surface id.
	 */
	return apply_filters( 'agend_apps_records_surface_schema', $schema, $surface );
}

require_once __DIR__ . '/schema/events-catalogue.php';
require_once __DIR__ . '/schema/record-block.php';
require_once __DIR__ . '/schema/record-pills.php';
require_once __DIR__ . '/schema/account-link.php';
require_once __DIR__ . '/schema/header-auth.php';
require_once __DIR__ . '/schema/member-login.php';
require_once __DIR__ . '/schema/record-link.php';
require_once __DIR__ . '/schema/record-image.php';
require_once __DIR__ . '/schema/record-field.php';
require_once __DIR__ . '/schema/filter.php';
require_once __DIR__ . '/schema/memberships-catalogue.php';
require_once __DIR__ . '/schema/export-reports.php';
require_once __DIR__ . '/schema/courses-catalogue.php';
require_once __DIR__ . '/schema/directory-catalogue.php';
