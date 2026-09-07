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

/** Surface ids that ship as blocks, in inserter order. */
const AGEND_APPS_RECORDS_BLOCK_SURFACES = array( 'events-catalogue' );

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
					$attributes[ $name ] = array(
						'type'    => 'string',
						'default' => (string) ( $field['default'] ?? '' ),
					);
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
 * Registers every built block. The compiled block directories live under
 * build/blocks/, produced by `npm run build` and committed, so a deploy that
 * only copies files still has them.
 */
function agend_apps_records_register_blocks(): void {
	foreach ( AGEND_APPS_RECORDS_BLOCK_SURFACES as $surface ) {
		$dir = AGEND_APPS_CORE_DIR . 'build/blocks/' . $surface;

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

					return rest_ensure_response( $schema );
				},
			),
		)
	);
}
add_action( 'rest_api_init', 'agend_apps_records_register_surface_schema_route' );
