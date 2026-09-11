<?php
/**
 * The block editor surface for the record catalogues.
 *
 * A block here is a thin adapter over the same two core seams the Elementor
 * widgets use: its attributes are derived from the surface's content-settings
 * schema, and its render callback is the surface's core renderer. Nothing
 * about a catalogue is declared a second time for the block editor.
 *
 * Attribute values are block-shaped (a toggle is a boolean) while the core
 * renderers take Elementor-shaped settings (a toggle is 'yes' or ''), because
 * the renderers were moved out of the widgets unchanged and their fixtures pin
 * that contract. The two converters below are the whole of that seam.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// `agend_apps_records_block_attributes()`'s `url` case reads a field default
// through the shared normaliser below.
require_once __DIR__ . '/format.php';

/**
 * Surface ids that ship as blocks, in inserter order: catalogues, then the
 * auth and account surfaces, then export and the filter control, then the
 * templated surfaces used inside a card or detail template.
 *
 * A surface named here whose compiled block directory does not exist yet is
 * skipped by agend_apps_records_register_surface_blocks(), so this list can
 * name a surface before its block is built.
 */
const AGEND_APPS_RECORDS_BLOCK_SURFACES = array(
	'events-catalogue',
	'courses-catalogue',
	'directory-catalogue',
	'memberships-catalogue',
	'member-login',
	'header-auth',
	'account-link',
	'export-reports',
	'filter',
	'record-field',
	'record-pills',
	'record-image',
	'record-link',
	'record-block',
);

/**
 * Block attribute definitions derived from a surface schema. Fields that store
 * no value (note, heading) and adapter placeholders are skipped.
 *
 * @param array $schema A surface schema.
 * @return array<string, array{type: string, default?: mixed, items?: array}>
 */
function agend_apps_records_block_attributes( array $schema ): array {
	$attributes = array();

	foreach ( $schema['sections'] ?? array() as $section ) {
		foreach ( $section['fields'] ?? array() as $field ) {
			$type = $field['type'] ?? '';
			$name = $field['name'] ?? '';

			if ( '' === $name ) {
				continue;
			}

			switch ( $type ) {
				case 'toggle':
					$attributes[ $name ] = array(
						'type'    => 'boolean',
						'default' => agend_apps_records_toggle_is_on( $field['default'] ?? false ),
					);
					break;
				case 'number':
					$attributes[ $name ] = array(
						'type'    => 'number',
						'default' => $field['default'] ?? 0,
					);
					break;
				case 'multiselect':
					$attributes[ $name ] = array(
						'type'    => 'array',
						'items'   => array( 'type' => 'string' ),
						'default' => array_key_exists( 'default', $field ) ? (array) $field['default'] : array(),
					);
					break;
				case 'text':
				case 'textarea':
				case 'select':
				case 'template':
				case 'colour':
					$attributes[ $name ] = array(
						'type'    => 'string',
						'default' => (string) ( $field['default'] ?? '' ),
					);
					break;
				case 'url':
					// A field default may already be a plain string, or
					// Elementor's own array( 'url' => ... ) shape; the same
					// normaliser a renderer uses reads either one.
					$attributes[ $name ] = array(
						'type'    => 'string',
						'default' => agend_apps_records_normalise_url_setting( $field['default'] ?? '' ),
					);
					break;
				case 'media':
					$default = is_array( $field['default'] ?? null ) ? $field['default'] : array();

					$attributes[ $name ] = array(
						'type'    => 'object',
						'default' => array(
							'url' => (string) ( $default['url'] ?? '' ),
							'id'  => (int) ( $default['id'] ?? 0 ),
						),
					);
					break;
				case 'repeater':
					// A row's own attributes are derived the same way a
					// top-level field's are, by feeding this same function a
					// one-field-list schema built from the repeater's nested
					// `fields`; recursion is what keeps the two in lockstep as
					// the vocabulary grows.
					$attributes[ $name ] = array(
						'type'    => 'array',
						'items'   => array(
							'type'       => 'object',
							'properties' => agend_apps_records_block_attributes(
								array( 'sections' => array( array( 'fields' => $field['fields'] ?? array() ) ) )
							),
						),
						'default' => array_key_exists( 'default', $field ) ? (array) $field['default'] : array(),
					);
					break;
				case 'adapter':
					// Most adapter fields describe no attribute at all (the
					// docblock above), but one carrying a `block` declaration
					// opts a block into a control of its own. Recursing into
					// this same function, fed a synthetic one-field schema
					// built from that declaration under the adapter field's
					// own name, derives the attribute the same way any other
					// Field type does, with no second copy of this switch.
					if ( isset( $field['block'] ) && is_array( $field['block'] ) ) {
						$derived = agend_apps_records_block_attributes(
							array( 'sections' => array( array( 'fields' => array( array_merge( $field['block'], array( 'name' => $name ) ) ) ) ) )
						);
						if ( isset( $derived[ $name ] ) ) {
							$attributes[ $name ] = $derived[ $name ];
						}
					}
					break;
			}
		}
	}

	return $attributes;
}

/**
 * Whether a toggle default, in either representation, means "on".
 *
 * @param mixed $default A boolean, or the literal 'yes'/'no'/'' Elementor stores.
 * @return bool
 */
function agend_apps_records_toggle_is_on( $default ): bool {
	return true === $default || 'yes' === $default;
}

/**
 * Converts block attributes to the Elementor-shaped settings the core
 * renderers take. Attributes not in the schema pass through untouched.
 *
 * @param array $schema     A surface schema.
 * @param array $attributes Block attributes (block-shaped values).
 * @return array Settings with 'yes'/'' toggles.
 */
function agend_apps_records_settings_from_attributes( array $schema, array $attributes ): array {
	$settings = $attributes;

	foreach ( $schema['sections'] ?? array() as $section ) {
		foreach ( $section['fields'] ?? array() as $field ) {
			if ( 'toggle' !== ( $field['type'] ?? '' ) || ! isset( $field['name'] ) ) {
				continue;
			}
			$name = $field['name'];
			if ( array_key_exists( $name, $attributes ) ) {
				$settings[ $name ] = $attributes[ $name ] ? 'yes' : '';
			}
		}
	}

	return $settings;
}

/**
 * A surface schema with option callables resolved, for the block editor.
 *
 * @param string $surface Surface id.
 * @return array
 */
function agend_apps_records_block_editor_schema( string $surface ): array {
	$schema = agend_apps_records_surface_schema( $surface );

	if ( empty( $schema['sections'] ) ) {
		return array();
	}

	foreach ( $schema['sections'] as &$section ) {
		foreach ( $section['fields'] as &$field ) {
			foreach ( array( 'options', 'groups' ) as $key ) {
				if ( isset( $field[ $key ] ) && is_string( $field[ $key ] ) ) {
					$field[ $key ] = function_exists( $field[ $key ] ) ? $field[ $key ]() : array();
				}
			}
			if ( 'template' === ( $field['type'] ?? '' ) ) {
				$field['options'] = Agend_Apps_Templates::options( (string) ( $field['placeholder'] ?? '' ) );
			}
			if ( 'toggle' === ( $field['type'] ?? '' ) ) {
				$field['default'] = agend_apps_records_toggle_is_on( $field['default'] ?? false );
			}
		}
		unset( $field );
	}
	unset( $section );

	return $schema;
}

/**
 * Renders a surface's block from its attributes.
 *
 * @param string $surface    Surface id.
 * @param array  $attributes Block attributes.
 * @return string
 */
function agend_apps_records_render_block( string $surface, array $attributes ): string {
	$render = 'agend_apps_records_render_' . str_replace( '-', '_', $surface );

	if ( ! function_exists( $render ) ) {
		return '';
	}

	return $render( agend_apps_records_settings_from_attributes( agend_apps_records_surface_schema( $surface ), $attributes ) );
}

/**
 * Registers a list of surfaces' built blocks from one build directory.
 *
 * Takes the directory and the surface list rather than reading core's own,
 * because a sibling plugin owns surfaces core knows nothing about: Agend Apps
 * Shop's cart surfaces declare their schemas and renderers in that plugin, and
 * their compiled blocks live under its own build/blocks/. The surface lookups
 * either side of this (`agend_apps_records_surface_schema()` and
 * `agend_apps_records_render_block()`) are both global function-name lookups
 * already, so a sibling plugin needs nothing from core but this call.
 *
 * @param string        $build_dir Absolute path to a build/blocks/ directory,
 *                                  with a trailing slash.
 * @param array<string> $surfaces  Surface ids to register, in inserter order.
 * @return void
 */
function agend_apps_records_register_surface_blocks( string $build_dir, array $surfaces ): void {
	foreach ( $surfaces as $surface ) {
		$dir = $build_dir . $surface;

		if ( ! file_exists( $dir . '/block.json' ) ) {
			continue;
		}

		// Output comes from the block's render.php, which calls
		// agend_apps_records_render_block(); only the attributes are added here.
		register_block_type(
			$dir,
			array( 'attributes' => agend_apps_records_block_attributes( agend_apps_records_surface_schema( $surface ) ) )
		);
	}
}

/**
 * Registers every built block core itself owns. The compiled block directories
 * live under build/blocks/, produced by `npm run build` and committed, so a
 * deploy that only copies files still has them.
 */
function agend_apps_records_register_blocks(): void {
	agend_apps_records_register_surface_blocks( AGEND_APPS_CORE_DIR . 'build/blocks/', AGEND_APPS_RECORDS_BLOCK_SURFACES );
}
add_action( 'init', 'agend_apps_records_register_blocks' );

/**
 * Adds the "Agend" inserter category immediately before Widgets, so the Agend
 * blocks are easy to find rather than sorted among Widgets entries. Idempotent
 * because block_categories_all can run more than once per request.
 *
 * @param array $categories Existing block categories.
 * @return array
 */
function agend_apps_records_block_categories( array $categories ): array {
	foreach ( $categories as $category ) {
		if ( 'agend' === ( $category['slug'] ?? '' ) ) {
			return $categories;
		}
	}

	$agend_category = array(
		'slug'  => 'agend',
		'title' => 'Agend',
	);

	foreach ( $categories as $index => $category ) {
		if ( 'widgets' === ( $category['slug'] ?? '' ) ) {
			array_splice( $categories, $index, 0, array( $agend_category ) );
			return $categories;
		}
	}

	$categories[] = $agend_category;

	return $categories;
}
add_filter( 'block_categories_all', 'agend_apps_records_block_categories' );

/**
 * Editor-only notices for a surface, shown above the block placeholder
 * (US-4.2 AC3). Empty for every surface except member-login and header-auth,
 * where the editor has no other way to know that the block's behaviour
 * depends on the site's member sign-in mode
 * (docs/PLAN-wordpress-idp-option-b.md section 4.5): each renders nothing at
 * all in `sso` mode, and points at WordPress's own sign-in rather than the
 * Agend credential form in `wordpress` mode.
 *
 * @param string $surface Surface id.
 * @return array<int, array{status: string, text: string}>
 */
function agend_apps_records_block_surface_notices( string $surface ): array {
	$notices = array();

	if ( class_exists( 'Agend_Apps_Settings' ) && ! Agend_Apps_Settings::credential_login_enabled() ) {
		// `wordpress` mode is a real, member-facing sign-in mode: these blocks
		// still render, they just point at WordPress's own sign-in instead of
		// the credential form. Only `sso` mode leaves them with nothing to show
		// on the front end, so it alone warns.
		$wordpress_mode = Agend_Apps_Settings::wordpress_idp_enabled();

		if ( 'member-login' === $surface ) {
			if ( $wordpress_mode ) {
				$notices[] = array(
					'status' => 'info',
					'text'   => __( 'Member sign-in is set to WordPress in Agend Apps settings. This block shows a WordPress sign-in prompt instead of the Agend credential form.', 'agend-apps-core' ),
				);
			} else {
				$notices[] = array(
					'status' => 'warning',
					'text'   => __( 'Member sign-in is set to SSO in Agend Apps settings. This block renders nothing until credential sign-in is enabled.', 'agend-apps-core' ),
				);
			}
		}

		if ( 'header-auth' === $surface ) {
			if ( $wordpress_mode ) {
				$notices[] = array(
					'status' => 'info',
					'text'   => __( 'Member sign-in is set to WordPress in Agend Apps settings. This block links to the WordPress sign-in page instead of the Agend credential form.', 'agend-apps-core' ),
				);
			} else {
				$notices[] = array(
					'status' => 'warning',
					'text'   => __( 'Member sign-in is set to SSO in Agend Apps settings. This block renders no Log In / My Portal link until credential sign-in is enabled.', 'agend-apps-core' ),
				);
			}
		}
	}

	/**
	 * Filters the editor-only notices shown above a surface's block placeholder.
	 *
	 * Core answers only for the surfaces it owns. A sibling plugin owning its
	 * own surfaces (Agend Apps Shop's cart surfaces) has nowhere else to say
	 * why one of them will render nothing on the front end, which is the whole
	 * point of these notices.
	 *
	 * @param array<int, array{status: string, text: string}> $notices The notices so far.
	 * @param string                                          $surface The surface id.
	 */
	return (array) apply_filters( 'agend_apps_records_block_surface_notices', $notices, $surface );
}

/**
 * Serves a surface's editor schema so the block inspector renders its
 * controls from the same declaration the Elementor widget uses.
 */
function agend_apps_records_register_surface_schema_route(): void {
	register_rest_route(
		'agend-apps/v1',
		'/surfaces/(?P<surface>[a-z-]+)/schema',
		array(
			array(
				'methods'             => WP_REST_Server::READABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$surface = (string) $request['surface'];
					$schema  = agend_apps_records_block_editor_schema( $surface );

					if ( array() === $schema ) {
						return new WP_Error( 'agend_apps_records_unknown_surface', __( 'No such surface.', 'agend-apps-core' ), array( 'status' => 404 ) );
					}

					$schema['notices'] = agend_apps_records_block_surface_notices( $surface );

					return rest_ensure_response( $schema );
				},
			),
		)
	);
}
add_action( 'rest_api_init', 'agend_apps_records_register_surface_schema_route' );

/**
 * Serves the filter block's editor-only preview: the stand-in control markup
 * agend_apps_records_render_filter() draws with its 'preview' opt on, plus the
 * reason it renders nothing when one applies.
 *
 * The filter surface is the one block whose front end depends on a catalogue
 * context (Agend_Apps_Records_Filter_Context::type()) a standalone block
 * editor has no way to supply, and whose render-nothing reasons
 * (agend_apps_records_filter_render_reason()) are keyed by per-instance
 * settings that agend_apps_records_block_surface_notices() cannot express (it
 * is keyed by surface id alone, with no attributes). Rather than widen either
 * of those two shared contracts, this route answers both questions for the
 * one caller that needs them: the filter block's own edit component. It
 * mirrors export-reports/render.php's precedent of calling a surface's own
 * renderer directly for the one need the generic
 * agend_apps_records_render_block() helper does not serve, just at a
 * different call site (this route, rather than the block's render.php, since
 * the front end never needs a preview).
 */
function agend_apps_records_register_surface_preview_route(): void {
	register_rest_route(
		'agend-apps/v1',
		'/surfaces/(?P<surface>[a-z-]+)/preview',
		array(
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'callback'            => static function ( WP_REST_Request $request ) {
					$surface = (string) $request['surface'];
					$schema  = agend_apps_records_surface_schema( $surface );

					if ( array() === $schema ) {
						return new WP_Error( 'agend_apps_records_unknown_surface', __( 'No such surface.', 'agend-apps-core' ), array( 'status' => 404 ) );
					}

					$render = 'agend_apps_records_render_' . str_replace( '-', '_', $surface );

					if ( ! function_exists( $render ) ) {
						return new WP_Error( 'agend_apps_records_surface_not_previewable', __( 'That surface has no preview.', 'agend-apps-core' ), array( 'status' => 404 ) );
					}

					$settings = agend_apps_records_settings_from_attributes( $schema, (array) $request->get_param( 'attributes' ) );
					$opts     = array(
						'preview'      => true,
						// Inferred here rather than in the editor: the rule is
						// agend_apps_records_type_from_key()'s, and a copy of it
						// in JS would be the same rule in two languages.
						'preview_type' => agend_apps_records_surface_preview_type(
							$settings,
							(int) $request->get_param( 'post_id' )
						),
					);

					return rest_ensure_response(
						array(
							'html'   => $render( $settings, $opts ),
							'reason' => agend_apps_records_surface_render_reason( $surface, $settings, $opts ),
						)
					);
				},
			),
		)
	);
}
add_action( 'rest_api_init', 'agend_apps_records_register_surface_preview_route' );

/**
 * The record type a templated surface's editor preview should render against.
 *
 * Resolved on the server, not in the editor. The rule for step one is
 * `agend_apps_records_type_from_key()`, and a copy of it in JS would be the
 * same rule stated in two languages, which is the class of drift this whole
 * schema seam exists to prevent. The preview route already receives the
 * surface's attributes, so it can answer this itself.
 *
 * @param array  $settings Surface settings, already Elementor-shaped.
 * @param int    $post_id  The post open in the editor, when the caller knows
 *                          it. A `wp_block` template records the type its
 *                          record surfaces imply; anything else has no type
 *                          to offer and is ignored.
 * @return string 'event', 'course', 'listing', or '' to let the resolver
 *                default.
 */
function agend_apps_records_surface_preview_type( array $settings, int $post_id = 0 ): string {
	// The surface's own field or panel key first: a key like `listing:name`
	// names its type by itself, which is why these surfaces have no record-type
	// setting to reconcile. Same order of preference as the Elementor side's
	// Agend_Elementor_Field_Widget_Trait::preview_type().
	foreach ( array( 'field', 'block' ) as $name ) {
		$type = agend_apps_records_type_from_key( (string) ( $settings[ $name ] ?? '' ) );

		if ( '' !== $type ) {
			return $type;
		}
	}

	// Then the template being edited, which the rest of its surfaces have named
	// the same way. That is what lets a `common:title` in a directory template
	// preview a listing's name rather than an event's.
	if ( $post_id > 0 && class_exists( 'Agend_Apps_Block_Template_Source' ) ) {
		$type = (string) get_post_meta( $post_id, Agend_Apps_Block_Template_Source::TYPE_META_KEY, true );

		if ( in_array( $type, array( 'event', 'course', 'listing' ), true ) ) {
			return $type;
		}
	}

	// Else nothing, and the resolver falls back to its own default.
	return '';
}

/**
 * A surface's "renders nothing, and why" reason code, or '' when it renders or
 * declares no reasons.
 *
 * The templated surfaces each expose a companion
 * `agend_apps_records_<surface>_render_reason()` beside their renderer, so the
 * conditions behind an editor notice live with the render they belong to
 * rather than being restated per editor. They do not share one signature: the
 * filter surface takes the catalogue context in scope, while the record
 * surfaces take the same `$opts` their renderer does. This resolves whichever
 * shape the surface actually declares, so the preview route above stays one
 * route rather than one per surface.
 *
 * @param string $surface  Surface id.
 * @param array  $settings Surface settings, already Elementor-shaped.
 * @param array  $opts     Render opts, carrying at least 'preview'.
 * @return string
 */
function agend_apps_records_surface_render_reason( string $surface, array $settings, array $opts = array() ): string {
	$reason = 'agend_apps_records_' . str_replace( '-', '_', $surface ) . '_render_reason';

	if ( ! function_exists( $reason ) ) {
		return '';
	}

	if ( 'filter' === $surface ) {
		return (string) $reason( $settings, Agend_Apps_Records_Filter_Context::type() );
	}

	return (string) $reason( $settings, $opts );
}
