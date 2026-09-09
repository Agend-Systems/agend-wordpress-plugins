<?php
/**
 * Field registry for the Agend template widgets.
 *
 * The one place that knows which values an event or course record exposes to
 * a template, how each is read from the gateway payload, and how each kind of
 * value is formatted and escaped. The Agend Field / Image / Link widgets, the
 * editor field picker and the unit tests all read this registry, so a payload
 * change is corrected here once.
 *
 * Getters read nested paths defensively: gateway payloads vary between the
 * list and single-item endpoints (a category may arrive as a string, an
 * object, or a `categories` array), and a missing field must render as empty
 * rather than warn.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Field kinds. `agend_apps_records_format_field()` switches on these.
 */
const AGEND_APPS_RECORDS_FIELD_KINDS = array( 'text', 'html', 'url', 'date', 'date_range', 'date_time', 'price', 'list', 'bool', 'number' );

/**
 * Reads the first non-empty string at the given paths of a record.
 *
 * @param array    $record The record.
 * @param string[] $paths  Dot paths, e.g. 'price_summary.member_from'.
 * @return mixed|null The first value found, or null.
 */
function agend_apps_records_record_path( array $record, array $paths ) {
	foreach ( $paths as $path ) {
		$cursor = $record;
		$found  = true;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $cursor ) || ! array_key_exists( $segment, $cursor ) ) {
				$found = false;
				break;
			}
			$cursor = $cursor[ $segment ];
		}
		if ( $found && null !== $cursor && '' !== $cursor ) {
			return $cursor;
		}
	}
	return null;
}

/**
 * A category name from the several shapes the gateway uses.
 *
 * @param array $record The record.
 * @return string The category name, or ''.
 */
function agend_apps_records_record_category( array $record ): string {
	if ( ! empty( $record['categories'] ) && is_array( $record['categories'] ) ) {
		$first = reset( $record['categories'] );
		if ( is_array( $first ) && ! empty( $first['name'] ) ) {
			return (string) $first['name'];
		}
		if ( is_string( $first ) && '' !== $first ) {
			return $first;
		}
	}
	if ( ! empty( $record['primary_category']['name'] ) ) {
		return (string) $record['primary_category']['name'];
	}
	if ( isset( $record['category'] ) ) {
		if ( is_array( $record['category'] ) ) {
			return isset( $record['category']['name'] ) ? (string) $record['category']['name'] : '';
		}
		if ( is_string( $record['category'] ) ) {
			return $record['category'];
		}
	}
	return '';
}

/**
 * All category names on a record, in order.
 *
 * @param array $record The record.
 * @return string[]
 */
function agend_apps_records_record_categories( array $record ): array {
	$names = array();
	if ( ! empty( $record['categories'] ) && is_array( $record['categories'] ) ) {
		foreach ( $record['categories'] as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['name'] ) ) {
				$names[] = (string) $entry['name'];
			} elseif ( is_string( $entry ) && '' !== $entry ) {
				$names[] = $entry;
			}
		}
	}
	if ( empty( $names ) ) {
		$single = agend_apps_records_record_category( $record );
		if ( '' !== $single ) {
			$names[] = $single;
		}
	}
	return $names;
}

/**
 * All tag names on a record, in order.
 *
 * Tags arrive as objects on the list payload and are absent from the single
 * item payload, so a detail template shows nothing rather than warning.
 *
 * @param array $record The record.
 * @return string[]
 */
function agend_apps_records_record_tags( array $record ): array {
	$names = array();
	if ( empty( $record['tags'] ) || ! is_array( $record['tags'] ) ) {
		return $names;
	}
	foreach ( $record['tags'] as $entry ) {
		if ( is_array( $entry ) && ! empty( $entry['name'] ) ) {
			$names[] = (string) $entry['name'];
		} elseif ( is_string( $entry ) && '' !== $entry ) {
			$names[] = $entry;
		}
	}
	return $names;
}

/**
 * The card location line: the venue name when there is one, otherwise Online
 * for a virtual event and TBA for anything else.
 *
 * Mirrors renderCard() in assets/js/events-catalogue.js, so a card template
 * can show the same line the built-in card shows.
 *
 * @param array $record The event record.
 * @return string
 */
function agend_apps_records_record_event_location( array $record ): string {
	$venue = agend_apps_records_record_path( $record, array( 'venue_name' ) );
	if ( is_string( $venue ) && '' !== trim( $venue ) ) {
		return trim( $venue );
	}
	$type = isset( $record['venue_type'] ) ? (string) $record['venue_type'] : '';
	return 'virtual' === $type ? __( 'Online', 'agend-apps-core' ) : __( 'TBA', 'agend-apps-core' );
}

/**
 * Names from a list of term objects or strings on a record.
 *
 * @param array  $record The record.
 * @param string $key    The record key holding the list.
 * @return string[]
 */
function agend_apps_records_record_names( array $record, string $key ): array {
	$names = array();
	if ( empty( $record[ $key ] ) || ! is_array( $record[ $key ] ) ) {
		return $names;
	}
	foreach ( $record[ $key ] as $entry ) {
		if ( is_array( $entry ) && ! empty( $entry['name'] ) ) {
			$names[] = (string) $entry['name'];
		} elseif ( is_string( $entry ) && '' !== $entry ) {
			$names[] = $entry;
		}
	}
	return $names;
}

/**
 * A listing's location as "City, STATE", from the card payload's
 * primary_location or the detail payload's locations list.
 *
 * @param array $record The listing record.
 * @return string
 */
function agend_apps_records_record_listing_location( array $record ): string {
	$source = array();
	if ( ! empty( $record['primary_location'] ) && is_array( $record['primary_location'] ) ) {
		$source = $record['primary_location'];
	} elseif ( ! empty( $record['locations'][0] ) && is_array( $record['locations'][0] ) ) {
		$source = $record['locations'][0];
	}
	$parts = array_filter(
		array(
			isset( $source['city'] ) ? trim( (string) $source['city'] ) : '',
			isset( $source['state'] ) ? trim( (string) $source['state'] ) : '',
		),
		'strlen'
	);
	return implode( ', ', $parts );
}

/**
 * One part of a listing's location.
 *
 * @param array  $record The listing record.
 * @param string $part   'city', 'state', 'postcode' or 'country'.
 * @return string
 */
function agend_apps_records_record_listing_location_part( array $record, string $part ): string {
	$value = agend_apps_records_record_path( $record, array( 'primary_location.' . $part, 'locations.0.' . $part ) );
	return is_scalar( $value ) ? trim( (string) $value ) : '';
}

/**
 * A listing's street address: address line 1, then line 2 when present.
 *
 * @param array $record The listing record.
 * @return string
 */
function agend_apps_records_record_listing_street( array $record ): string {
	$parts = array_filter(
		array(
			agend_apps_records_record_listing_location_part( $record, 'address_line_1' ),
			agend_apps_records_record_listing_location_part( $record, 'address_line_2' ),
		),
		'strlen'
	);
	return implode( ', ', $parts );
}

/**
 * A listing's full address on one line: "1 Example St, Sydney NSW 2000".
 *
 * Each part is optional, so a listing with only a suburb renders just that.
 *
 * @param array $record The listing record.
 * @return string
 */
function agend_apps_records_record_listing_address( array $record ): string {
	$street   = agend_apps_records_record_listing_street( $record );
	$locality = implode(
		' ',
		array_filter(
			array(
				agend_apps_records_record_listing_location_part( $record, 'city' ),
				agend_apps_records_record_listing_location_part( $record, 'state' ),
				agend_apps_records_record_listing_location_part( $record, 'postcode' ),
			),
			'strlen'
		)
	);
	return implode( ', ', array_filter( array( $street, $locality ), 'strlen' ) );
}

/**
 * A single custom-field value from a record, by its key.
 *
 * `custom_fields` is a list of `{key,label,type,value}`. Which entries the
 * gateway returns depends on the signed-in member's entitlements, so a key
 * present for one viewer can be absent for the next; a missing key renders as
 * empty rather than warning.
 *
 * @param array  $record The record.
 * @param string $key    The custom-field key.
 * @return string The value, or ''.
 */
function agend_apps_records_record_custom_field( array $record, string $key ): string {
	if ( '' === $key || empty( $record['custom_fields'] ) || ! is_array( $record['custom_fields'] ) ) {
		return '';
	}
	foreach ( $record['custom_fields'] as $entry ) {
		if ( ! is_array( $entry ) || ( $entry['key'] ?? '' ) !== $key ) {
			continue;
		}
		$value = $entry['value'] ?? '';
		if ( is_array( $value ) ) {
			return implode( ', ', array_map( 'strval', array_filter( $value, 'is_scalar' ) ) );
		}
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}
	return '';
}

/**
 * The label the gateway gave a custom field, by its key.
 *
 * @param array  $record The record.
 * @param string $key    The custom-field key.
 * @return string The label, or ''.
 */
function agend_apps_records_record_custom_field_label( array $record, string $key ): string {
	if ( '' === $key || empty( $record['custom_fields'] ) || ! is_array( $record['custom_fields'] ) ) {
		return '';
	}
	foreach ( $record['custom_fields'] as $entry ) {
		if ( is_array( $entry ) && ( $entry['key'] ?? '' ) === $key ) {
			return isset( $entry['label'] ) ? (string) $entry['label'] : '';
		}
	}
	return '';
}

/**
 * Whether the viewer of an event record is on member pricing.
 *
 * @param array $record The event record (bearer-enriched when signed in).
 * @return bool
 */
function agend_apps_records_record_is_member( array $record ): bool {
	$group = isset( $record['viewer_price_group'] ) ? (string) $record['viewer_price_group'] : '';
	return 'member' === $group || 'corporate' === $group;
}

/**
 * The "from" price for an event, chosen for the viewer's price group.
 *
 * @param array $record The event record.
 * @return mixed|null Numeric price, or null when the record carries none.
 */
function agend_apps_records_record_event_price_from( array $record ) {
	$paths = agend_apps_records_record_is_member( $record )
		? array( 'price_summary.member_from', 'price_summary.non_member_from' )
		: array( 'price_summary.non_member_from', 'price_summary.member_from' );
	$value = agend_apps_records_record_path( $record, $paths );
	return is_numeric( $value ) ? $value : null;
}

/**
 * A plain-text excerpt for a record: the short description when present,
 * otherwise the description with tags stripped.
 *
 * @param array $record The record.
 * @return string
 */
function agend_apps_records_record_excerpt( array $record ): string {
	$short = agend_apps_records_record_path( $record, array( 'short_description' ) );
	if ( is_string( $short ) && '' !== trim( $short ) ) {
		return trim( $short );
	}
	$long = agend_apps_records_record_path( $record, array( 'description' ) );
	return is_string( $long ) ? trim( preg_replace( '/\s+/', ' ', strip_tags( $long ) ) ) : '';
}

/**
 * The registry: field key => descriptor.
 *
 * Descriptor keys: `label`, `group` (picker group label), `kind` (one of
 * AGEND_APPS_RECORDS_FIELD_KINDS), `types` (record types the field applies to;
 * `common:*` fields apply to both and resolve per type), `get` (callable
 * `( array $record, string $type, array $extra )` returning the raw value).
 *
 * @return array<string, array<string, mixed>>
 */
function agend_apps_records_field_registry(): array {
	static $registry = null;
	if ( null !== $registry ) {
		return $registry;
	}

	$text = static function ( string ...$paths ): callable {
		return static function ( array $record ) use ( $paths ) {
			$value = agend_apps_records_record_path( $record, $paths );
			return is_scalar( $value ) ? (string) $value : '';
		};
	};
	$num  = static function ( string ...$paths ): callable {
		return static function ( array $record ) use ( $paths ) {
			$value = agend_apps_records_record_path( $record, $paths );
			return is_numeric( $value ) ? $value : null;
		};
	};
	$flag = static function ( string $path ): callable {
		return static function ( array $record ) use ( $path ) {
			return ! empty( agend_apps_records_record_path( $record, array( $path ) ) );
		};
	};

	$event_group   = __( 'Event', 'agend-apps-core' );
	$course_group  = __( 'Course', 'agend-apps-core' );
	$listing_group = __( 'Directory listing', 'agend-apps-core' );
	$common_group  = __( 'Common (any record)', 'agend-apps-core' );
	$event_types   = array( 'event' );
	$course_types  = array( 'course' );
	$listing_types = array( 'listing' );
	$all_types     = array( 'event', 'course', 'listing' );

	$event = array(
		'event:name'                  => array( 'label' => __( 'Name', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'name' ) ),
		'event:short_description'     => array( 'label' => __( 'Short description', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'short_description' ) ),
		'event:description'           => array( 'label' => __( 'Description (HTML)', 'agend-apps-core' ), 'kind' => 'html', 'get' => $text( 'description' ) ),
		'event:excerpt'               => array( 'label' => __( 'Excerpt', 'agend-apps-core' ), 'kind' => 'text', 'get' => 'agend_apps_records_record_excerpt' ),
		'event:hero_image_url'        => array( 'label' => __( 'Hero image', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'hero_image_url', 'image_url' ) ),
		'event:start_date'            => array( 'label' => __( 'Start date', 'agend-apps-core' ), 'kind' => 'date', 'get' => $text( 'start_date' ) ),
		'event:end_date'              => array( 'label' => __( 'End date', 'agend-apps-core' ), 'kind' => 'date', 'get' => $text( 'end_date' ) ),
		'event:date_range'            => array(
			'label' => __( 'Date range', 'agend-apps-core' ),
			'kind'  => 'date_range',
			'get'   => static function ( array $record ) {
				return array( 'start' => $record['start_date'] ?? '', 'end' => $record['end_date'] ?? '' );
			},
		),
		'event:date_time'             => array(
			'label' => __( 'Date and time', 'agend-apps-core' ),
			'kind'  => 'date_time',
			'get'   => static function ( array $record ) {
				return array( 'start' => $record['start_date'] ?? '', 'end' => $record['end_date'] ?? '' );
			},
		),
		'event:timezone'              => array( 'label' => __( 'Timezone', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'timezone' ) ),
		'event:venue_name'            => array( 'label' => __( 'Venue name', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'venue_name' ) ),
		'event:venue_address'         => array( 'label' => __( 'Venue address', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'venue_address' ) ),
		'event:venue_city'            => array( 'label' => __( 'Venue city', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'venue_city' ) ),
		'event:venue_type'            => array(
			'label' => __( 'Format (In-Person, Online, Hybrid)', 'agend-apps-core' ),
			'kind'  => 'text',
			'pill'  => true,
			'get'   => static function ( array $record ) {
				$type = isset( $record['venue_type'] ) ? (string) $record['venue_type'] : '';
				return '' === $type ? '' : agend_apps_records_ssr_ev_type_label( $type );
			},
		),
		'event:location'              => array( 'label' => __( 'Location (venue, or Online)', 'agend-apps-core' ), 'kind' => 'text', 'get' => 'agend_apps_records_record_event_location' ),
		'event:category'              => array( 'label' => __( 'Category', 'agend-apps-core' ), 'kind' => 'text', 'pill' => true, 'get' => 'agend_apps_records_record_category' ),
		'event:categories'            => array( 'label' => __( 'All categories', 'agend-apps-core' ), 'kind' => 'list', 'pill' => true, 'get' => 'agend_apps_records_record_categories' ),
		'event:tags'                  => array( 'label' => __( 'Tags', 'agend-apps-core' ), 'kind' => 'list', 'pill' => true, 'get' => 'agend_apps_records_record_tags' ),
		'event:price_from'            => array( 'label' => __( 'Price from (viewer)', 'agend-apps-core' ), 'kind' => 'price', 'get' => 'agend_apps_records_record_event_price_from' ),
		'event:price_member_from'     => array( 'label' => __( 'Member price from', 'agend-apps-core' ), 'kind' => 'price', 'get' => $num( 'price_summary.member_from' ) ),
		'event:price_non_member_from' => array( 'label' => __( 'Non-member price from', 'agend-apps-core' ), 'kind' => 'price', 'get' => $num( 'price_summary.non_member_from' ) ),
		'event:sold_out'              => array( 'label' => __( 'Sold out', 'agend-apps-core' ), 'kind' => 'bool', 'get' => $flag( 'sold_out' ) ),
		'event:is_registered'         => array( 'label' => __( 'Viewer is registered', 'agend-apps-core' ), 'kind' => 'bool', 'get' => $flag( 'my_registration' ) ),
		'event:viewer_price_group'    => array( 'label' => __( 'Viewer price group', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'viewer_price_group' ) ),
	);

	$course = array(
		'course:title'                  => array( 'label' => __( 'Title', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'title', 'name' ) ),
		'course:description'            => array( 'label' => __( 'Description (HTML)', 'agend-apps-core' ), 'kind' => 'html', 'get' => $text( 'description' ) ),
		'course:excerpt'                => array( 'label' => __( 'Excerpt', 'agend-apps-core' ), 'kind' => 'text', 'get' => 'agend_apps_records_record_excerpt' ),
		'course:image_url'              => array( 'label' => __( 'Image', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'image_url', 'hero_image_url' ) ),
		'course:category'               => array( 'label' => __( 'Category', 'agend-apps-core' ), 'kind' => 'text', 'pill' => true, 'get' => 'agend_apps_records_record_category' ),
		'course:difficulty'             => array(
			'label' => __( 'Level', 'agend-apps-core' ),
			'kind'  => 'text',
			'pill'  => true,
			'get'   => static function ( array $record ) {
				$value = isset( $record['difficulty'] ) ? (string) $record['difficulty'] : '';
				return '' === $value ? '' : agend_apps_records_ssr_lms_difficulty( $value );
			},
		),
		'course:delivery_mode'          => array(
			'label' => __( 'Delivery mode', 'agend-apps-core' ),
			'kind'  => 'text',
			'pill'  => true,
			'get'   => static function ( array $record ) {
				$value = isset( $record['delivery_mode'] ) ? (string) $record['delivery_mode'] : '';
				return '' === $value ? '' : agend_apps_records_ssr_lms_mode( $value );
			},
		),
		'course:duration'               => array(
			'label' => __( 'Duration', 'agend-apps-core' ),
			'kind'  => 'text',
			'get'   => static function ( array $record ) {
				return agend_apps_records_ssr_lms_duration( $record['total_duration_minutes'] ?? 0 );
			},
		),
		'course:total_duration_minutes' => array( 'label' => __( 'Duration (minutes)', 'agend-apps-core' ), 'kind' => 'number', 'get' => $num( 'total_duration_minutes' ) ),
		'course:lessons_count'          => array( 'label' => __( 'Lessons', 'agend-apps-core' ), 'kind' => 'number', 'get' => $num( 'lessons_count' ) ),
		'course:price'                  => array(
			'label' => __( 'Price', 'agend-apps-core' ),
			'kind'  => 'price',
			'get'   => static function ( array $record ) {
				if ( ! empty( $record['is_free'] ) ) {
					return 0;
				}
				$value = agend_apps_records_record_path( $record, array( 'base_price', 'price' ) );
				return is_numeric( $value ) ? $value : null;
			},
		),
		'course:is_free'                => array( 'label' => __( 'Is free', 'agend-apps-core' ), 'kind' => 'bool', 'get' => $flag( 'is_free' ) ),
		'course:instructor_name'        => array( 'label' => __( 'Instructor', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'instructor_name' ) ),
		'course:learning_outcomes'      => array(
			'label' => __( 'Learning outcomes', 'agend-apps-core' ),
			'kind'  => 'list',
			'get'   => static function ( array $record ) {
				$outcomes = $record['learning_outcomes'] ?? array();
				return is_array( $outcomes ) ? array_values( array_filter( array_map( 'strval', array_filter( $outcomes, 'is_scalar' ) ) ) ) : array();
			},
		),
		'course:progress_percent'       => array(
			'label' => __( 'Progress (%)', 'agend-apps-core' ),
			'kind'  => 'number',
			'get'   => static function ( array $record ) {
				$value = agend_apps_records_record_path( $record, array( 'my_enrollment.progress.percentage', 'my_enrollment.progress.percent' ) );
				return is_numeric( $value ) ? (float) $value : 0;
			},
		),
		'course:is_enrolled'            => array( 'label' => __( 'Viewer is enrolled', 'agend-apps-core' ), 'kind' => 'bool', 'get' => $flag( 'my_enrollment' ) ),
	);

	$listing = array(
		'listing:name'              => array( 'label' => __( 'Name', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'name' ) ),
		'listing:short_description' => array( 'label' => __( 'Short description', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'short_description' ) ),
		'listing:description'       => array( 'label' => __( 'Description (HTML)', 'agend-apps-core' ), 'kind' => 'html', 'get' => $text( 'description' ) ),
		'listing:excerpt'           => array( 'label' => __( 'Excerpt', 'agend-apps-core' ), 'kind' => 'text', 'get' => 'agend_apps_records_record_excerpt' ),
		'listing:logo_url'          => array( 'label' => __( 'Logo', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'logo_url' ) ),
		'listing:hero_image_url'    => array( 'label' => __( 'Hero image', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'hero_image_url', 'logo_url' ) ),
		'listing:category'          => array( 'label' => __( 'Category', 'agend-apps-core' ), 'kind' => 'text', 'pill' => true, 'get' => 'agend_apps_records_record_category' ),
		'listing:categories'        => array( 'label' => __( 'All categories', 'agend-apps-core' ), 'kind' => 'list', 'pill' => true, 'get' => 'agend_apps_records_record_categories' ),
		'listing:tags'              => array( 'label' => __( 'Tags', 'agend-apps-core' ), 'kind' => 'list', 'pill' => true, 'get' => 'agend_apps_records_record_tags' ),
		'listing:badges'            => array(
			'label' => __( 'Badges', 'agend-apps-core' ),
			'kind'  => 'list',
			'pill'  => true,
			'get'   => static function ( array $record ) {
				return agend_apps_records_record_names( $record, 'badges' );
			},
		),
		'listing:location'          => array( 'label' => __( 'Location (city, state)', 'agend-apps-core' ), 'kind' => 'text', 'get' => 'agend_apps_records_record_listing_location' ),
		'listing:city'              => array(
			'label' => __( 'City', 'agend-apps-core' ),
			'kind'  => 'text',
			'get'   => static function ( array $record ) {
				return agend_apps_records_record_listing_location_part( $record, 'city' );
			},
		),
		'listing:state'             => array(
			'label' => __( 'State', 'agend-apps-core' ),
			'kind'  => 'text',
			'pill'  => true,
			'get'   => static function ( array $record ) {
				return agend_apps_records_record_listing_location_part( $record, 'state' );
			},
		),
		'listing:postcode'          => array(
			'label' => __( 'Postcode', 'agend-apps-core' ),
			'kind'  => 'text',
			'get'   => static function ( array $record ) {
				return agend_apps_records_record_listing_location_part( $record, 'postcode' );
			},
		),
		'listing:street'            => array( 'label' => __( 'Street address', 'agend-apps-core' ), 'kind' => 'text', 'get' => 'agend_apps_records_record_listing_street' ),
		'listing:address'           => array( 'label' => __( 'Full address (one line)', 'agend-apps-core' ), 'kind' => 'text', 'get' => 'agend_apps_records_record_listing_address' ),
		'listing:updated_at'        => array( 'label' => __( 'Last updated', 'agend-apps-core' ), 'kind' => 'date', 'get' => $text( 'updated_at' ) ),
		'listing:rating'            => array( 'label' => __( 'Average rating', 'agend-apps-core' ), 'kind' => 'number', 'get' => $num( 'average_rating' ) ),
		'listing:review_count'      => array( 'label' => __( 'Review count', 'agend-apps-core' ), 'kind' => 'number', 'get' => $num( 'review_count' ) ),
		'listing:is_featured'       => array( 'label' => __( 'Featured', 'agend-apps-core' ), 'kind' => 'bool', 'get' => $flag( 'is_featured' ) ),
		'listing:phone'             => array( 'label' => __( 'Phone', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'phone' ) ),
		'listing:website'           => array( 'label' => __( 'Website', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'website' ) ),
		'listing:linkedin_url'      => array( 'label' => __( 'LinkedIn', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'linkedin_url' ) ),
		'listing:facebook_url'      => array( 'label' => __( 'Facebook', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'facebook_url' ) ),
		'listing:instagram_url'     => array( 'label' => __( 'Instagram', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'instagram_url' ) ),
		'listing:twitter_url'       => array( 'label' => __( 'X (Twitter)', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'twitter_url' ) ),
		'listing:youtube_url'       => array( 'label' => __( 'YouTube', 'agend-apps-core' ), 'kind' => 'url', 'get' => $text( 'youtube_url' ) ),
		'listing:hours'             => array(
			'label' => __( 'Opening hours', 'agend-apps-core' ),
			'kind'  => 'html',
			'get'   => static function ( array $record ) {
				// The same table the detail renders, without its section
				// heading: an Agend Field prints its own label when asked to.
				// Guarded because fields.php is loadable on its own, and the
				// renderer lives in the SSR detail file.
				return function_exists( 'agend_apps_records_ssr_hours' )
					? agend_apps_records_ssr_hours( $record['business_hours'] ?? null )
					: '';
			},
		),
	);

	// Common aliases resolve to the per-type field so one template can serve
	// any record type.
	$alias = static function ( string $event_key, string $course_key, string $listing_key = '' ): callable {
		return static function ( array $record, string $type, array $extra ) use ( $event_key, $course_key, $listing_key ) {
			if ( 'course' === $type ) {
				$key = $course_key;
			} elseif ( 'listing' === $type ) {
				$key = '' !== $listing_key ? $listing_key : $event_key;
			} else {
				$key = $event_key;
			}
			return agend_apps_records_field_value( $key, $type, $record, $extra );
		};
	};
	$common = array(
		'common:title'       => array( 'label' => __( 'Title', 'agend-apps-core' ), 'kind' => 'text', 'get' => $alias( 'event:name', 'course:title', 'listing:name' ) ),
		'common:description' => array( 'label' => __( 'Description (HTML)', 'agend-apps-core' ), 'kind' => 'html', 'get' => $alias( 'event:description', 'course:description', 'listing:description' ) ),
		'common:excerpt'     => array( 'label' => __( 'Excerpt', 'agend-apps-core' ), 'kind' => 'text', 'get' => 'agend_apps_records_record_excerpt' ),
		'common:image'       => array( 'label' => __( 'Image', 'agend-apps-core' ), 'kind' => 'url', 'get' => $alias( 'event:hero_image_url', 'course:image_url', 'listing:hero_image_url' ) ),
		'common:category'    => array( 'label' => __( 'Category', 'agend-apps-core' ), 'kind' => 'text', 'pill' => true, 'get' => 'agend_apps_records_record_category' ),
		'common:price_from'  => array( 'label' => __( 'Price from', 'agend-apps-core' ), 'kind' => 'price', 'types' => array( 'event', 'course' ), 'get' => $alias( 'event:price_from', 'course:price' ) ),
		'common:slug'         => array( 'label' => __( 'Slug', 'agend-apps-core' ), 'kind' => 'text', 'get' => $text( 'slug' ) ),
		'common:custom_field' => array(
			'label' => __( 'Custom field (by key)', 'agend-apps-core' ),
			'kind'  => 'text',
			'get'   => static function ( array $record, string $type, array $extra ) {
				return agend_apps_records_record_custom_field( $record, (string) ( $extra['custom_field_key'] ?? '' ) );
			},
		),
		'common:custom_field_label' => array(
			'label' => __( 'Custom field label (by key)', 'agend-apps-core' ),
			'kind'  => 'text',
			'get'   => static function ( array $record, string $type, array $extra ) {
				return agend_apps_records_record_custom_field_label( $record, (string) ( $extra['custom_field_key'] ?? '' ) );
			},
		),
		'common:detail_url'  => array(
			'label' => __( 'Detail URL', 'agend-apps-core' ),
			'kind'  => 'url',
			'get'   => static function ( array $record, string $type, array $extra ) {
				if ( ! empty( $extra['detail_url'] ) ) {
					return (string) $extra['detail_url'];
				}
				$slug = isset( $record['slug'] ) ? (string) $record['slug'] : '';
				if ( '' === $slug || ! class_exists( 'Agend_Apps_Records_Pages' ) ) {
					return '';
				}
				return Agend_Apps_Records_Pages::detail_url( $type, $slug, (int) ( $extra['host_page_id'] ?? 0 ) );
			},
		),
	);

	$registry = array();
	foreach ( $common as $key => $descriptor ) {
		$registry[ $key ] = $descriptor + array( 'group' => $common_group, 'types' => $all_types );
	}
	foreach ( $listing as $key => $descriptor ) {
		$registry[ $key ] = $descriptor + array( 'group' => $listing_group, 'types' => $listing_types );
	}
	foreach ( $event as $key => $descriptor ) {
		$registry[ $key ] = $descriptor + array( 'group' => $event_group, 'types' => $event_types );
	}
	foreach ( $course as $key => $descriptor ) {
		$registry[ $key ] = $descriptor + array( 'group' => $course_group, 'types' => $course_types );
	}

	return $registry;
}

/**
 * The kind of a field, or '' for an unknown key.
 *
 * @param string $key Field key.
 * @return string
 */
function agend_apps_records_field_kind( string $key ): string {
	$registry = agend_apps_records_field_registry();
	return isset( $registry[ $key ] ) ? (string) $registry[ $key ]['kind'] : '';
}

/**
 * The name a field is shown under when a widget is asked to print its label.
 *
 * The registry's label for an ordinary field. A custom field has no fixed
 * name -- it is whatever the account configured -- so its label is read off
 * the record, falling back to the registry's generic "Custom field" when the
 * record carries no entry for that key (a visitor who is not entitled to it,
 * or a key that does not exist).
 *
 * @param string $key    Field key.
 * @param array  $record The record.
 * @param array  $extra  Render context; `custom_field_key` when the field is a
 *                       custom one.
 * @return string The label, or '' for an unknown key.
 */
function agend_apps_records_field_label( string $key, array $record = array(), array $extra = array() ): string {
	$registry = agend_apps_records_field_registry();

	if ( ! isset( $registry[ $key ] ) ) {
		return '';
	}

	if ( 'common:custom_field' === $key || 'common:custom_field_label' === $key ) {
		$label = agend_apps_records_record_custom_field_label( $record, (string) ( $extra['custom_field_key'] ?? '' ) );

		if ( '' !== $label ) {
			return $label;
		}
	}

	return (string) $registry[ $key ]['label'];
}

/**
 * Whether a field applies to a record type.
 *
 * @param string $key  Field key.
 * @param string $type Record type.
 * @return bool
 */
function agend_apps_records_field_applies( string $key, string $type ): bool {
	$registry = agend_apps_records_field_registry();
	return isset( $registry[ $key ] ) && in_array( $type, $registry[ $key ]['types'], true );
}

/**
 * The record type a widget setting names, or '' when it names none.
 *
 * Both vocabularies a template widget picks from are prefixed with the record
 * type they belong to: field keys as `listing:name` (see the registry above)
 * and content-block keys as `listing_about` (see
 * agend_apps_records_schema_record_block()). A widget set to one of those has
 * therefore already told us which record it wants, which is what lets the
 * editor preview it against the right record type without the author also
 * setting `record_type` by hand.
 *
 * `common:` fields apply to every type and so name none.
 *
 * @param string $key Field key or content-block key.
 * @return string 'event', 'course', 'listing', or ''.
 */
function agend_apps_records_type_from_key( string $key ): string {
	$prefix = (string) ( preg_split( '/[:_]/', $key, 2 )[0] ?? '' );

	return in_array( $prefix, array( 'event', 'course', 'listing' ), true ) ? $prefix : '';
}

/**
 * The raw value of a field on a record.
 *
 * Returns null for an unknown key or a field that does not apply to the
 * record type, so a Course field inside an Event template is simply empty.
 *
 * @param string $key    Field key, e.g. 'event:name'.
 * @param string $type   Record type, 'event' or 'course'.
 * @param array  $record The record.
 * @param array  $extra  Render context (slug, detail_url, host_page_id ...).
 * @return mixed|null
 */
function agend_apps_records_field_value( string $key, string $type, array $record, array $extra = array() ) {
	if ( ! agend_apps_records_field_applies( $key, $type ) ) {
		return null;
	}
	$registry = agend_apps_records_field_registry();
	$value    = call_user_func( $registry[ $key ]['get'], $record, $type, $extra );
	return ( '' === $value ) ? null : $value;
}

/**
 * A field's value as a list of terms, for rendering one pill per term.
 *
 * A list field yields its items; a single-value text field yields one item, so
 * one pill widget can render "all categories" or just the primary one.
 *
 * @param string $key    Field key.
 * @param string $type   Record type.
 * @param array  $record The record.
 * @param array  $extra  Render context.
 * @return string[]
 */
function agend_apps_records_field_terms( string $key, string $type, array $record, array $extra = array() ): array {
	$value = agend_apps_records_field_value( $key, $type, $record, $extra );
	if ( null === $value ) {
		return array();
	}
	if ( is_array( $value ) ) {
		$terms = array_map( 'strval', array_filter( $value, 'is_scalar' ) );
	} elseif ( is_scalar( $value ) ) {
		$terms = array( (string) $value );
	} else {
		return array();
	}
	return array_values( array_filter( array_map( 'trim', $terms ), 'strlen' ) );
}

/**
 * Picker options for fields that make sense rendered as pills.
 *
 * @return array<int, array{label: string, options: array<string, string>}>
 */
function agend_apps_records_pill_field_options(): array {
	$groups = array();
	foreach ( agend_apps_records_field_registry() as $key => $descriptor ) {
		if ( empty( $descriptor['pill'] ) ) {
			continue;
		}
		$group = (string) $descriptor['group'];
		if ( ! isset( $groups[ $group ] ) ) {
			$groups[ $group ] = array( 'label' => $group, 'options' => array() );
		}
		$groups[ $group ]['options'][ $key ] = (string) $descriptor['label'];
	}
	return array_values( $groups );
}

/**
 * Field picker options grouped for an Elementor SELECT `groups` control.
 *
 * @param string[]|null $kinds Restrict to these kinds (e.g. array( 'url' ) for
 *                             the image widget); null for all.
 * @return array<int, array{label: string, options: array<string, string>}>
 */
function agend_apps_records_field_options( ?array $kinds = null ): array {
	$groups = array();
	foreach ( agend_apps_records_field_registry() as $key => $descriptor ) {
		if ( null !== $kinds && ! in_array( $descriptor['kind'], $kinds, true ) ) {
			continue;
		}
		$group = (string) $descriptor['group'];
		if ( ! isset( $groups[ $group ] ) ) {
			$groups[ $group ] = array( 'label' => $group, 'options' => array() );
		}
		$groups[ $group ]['options'][ $key ] = (string) $descriptor['label'];
	}
	return array_values( $groups );
}

/**
 * Formats and escapes a raw field value for output.
 *
 * Options (all optional): `date_format` (PHP date format; '' = site format),
 * `timezone` (DateTimeZone), `list_separator` (default ', '), `list_max`
 * (0 = all), `price_prefix`, `price_free_label` (default 'Free'),
 * `bool_true` / `bool_false` (default 'Yes' / ''), `number_suffix`,
 * `truncate` (characters, 0 = off; applies to text and stripped html).
 *
 * @param mixed  $value The raw value from agend_apps_records_field_value().
 * @param string $kind  The field kind.
 * @param array  $opts  Formatting options.
 * @return string Escaped HTML, or '' when the value is empty.
 */
function agend_apps_records_format_field( $value, string $kind, array $opts = array() ): string {
	if ( null === $value || '' === $value || ( is_array( $value ) && 'date_range' !== $kind && 'date_time' !== $kind && empty( $value ) ) ) {
		return 'bool' === $kind ? esc_html( (string) ( $opts['bool_false'] ?? '' ) ) : '';
	}

	$tz       = ( isset( $opts['timezone'] ) && $opts['timezone'] instanceof DateTimeZone ) ? $opts['timezone'] : null;
	$truncate = isset( $opts['truncate'] ) ? (int) $opts['truncate'] : 0;

	switch ( $kind ) {
		case 'html':
			if ( $truncate > 0 ) {
				return esc_html( agend_apps_records_truncate_text( trim( preg_replace( '/\s+/', ' ', strip_tags( (string) $value ) ) ), $truncate ) );
			}
			return wp_kses_post( (string) $value );

		case 'url':
			return esc_url( (string) $value );

		case 'date':
			$ts = strtotime( (string) $value );
			if ( ! $ts ) {
				return '';
			}
			$format = isset( $opts['date_format'] ) && '' !== (string) $opts['date_format']
				? (string) $opts['date_format']
				: (string) get_option( 'date_format', 'j M Y' );
			return esc_html( (string) wp_date( $format, $ts, $tz ) );

		case 'date_range':
			$start = is_array( $value ) ? ( $value['start'] ?? '' ) : $value;
			$end   = is_array( $value ) ? ( $value['end'] ?? '' ) : '';
			return esc_html( agend_apps_records_ssr_ev_date_range( $start, $end, $tz ) );

		case 'date_time':
			$start = is_array( $value ) ? ( $value['start'] ?? '' ) : $value;
			$end   = is_array( $value ) ? ( $value['end'] ?? '' ) : '';
			return esc_html( agend_apps_records_ssr_ev_date_time( $start, $end, $tz ) );

		case 'price':
			if ( ! is_numeric( $value ) ) {
				return '';
			}
			$num = (float) $value;
			if ( 0.0 === $num ) {
				return esc_html( (string) ( $opts['price_free_label'] ?? __( 'Free', 'agend-apps-core' ) ) );
			}
			return esc_html( (string) ( $opts['price_prefix'] ?? '' ) . '$' . number_format( $num, 2, '.', '' ) );

		case 'list':
			$items = is_array( $value ) ? array_values( array_filter( array_map( 'strval', array_filter( $value, 'is_scalar' ) ) ) ) : array( (string) $value );
			$max   = isset( $opts['list_max'] ) ? (int) $opts['list_max'] : 0;
			if ( $max > 0 ) {
				$items = array_slice( $items, 0, $max );
			}
			return implode( esc_html( (string) ( $opts['list_separator'] ?? ', ' ) ), array_map( 'esc_html', $items ) );

		case 'bool':
			return esc_html( (string) ( $value ? ( $opts['bool_true'] ?? __( 'Yes', 'agend-apps-core' ) ) : ( $opts['bool_false'] ?? '' ) ) );

		case 'number':
			if ( ! is_numeric( $value ) ) {
				return '';
			}
			$num      = (float) $value;
			$decimals = ( floor( $num ) === $num ) ? 0 : 2;
			return esc_html( number_format_i18n( $num, $decimals ) . (string) ( $opts['number_suffix'] ?? '' ) );

		case 'text':
		default:
			$text = is_scalar( $value ) ? (string) $value : '';
			if ( $truncate > 0 ) {
				$text = agend_apps_records_truncate_text( $text, $truncate );
			}
			return esc_html( $text );
	}
}

/**
 * Truncates text to a character budget on a word boundary, adding an ellipsis.
 *
 * @param string $text  The text.
 * @param int    $limit Maximum characters (excluding the ellipsis).
 * @return string
 */
function agend_apps_records_truncate_text( string $text, int $limit ): string {
	if ( $limit <= 0 || mb_strlen( $text ) <= $limit ) {
		return $text;
	}
	$cut   = mb_substr( $text, 0, $limit );
	$space = mb_strrpos( $cut, ' ' );
	if ( false !== $space && $space > (int) ( $limit * 0.6 ) ) {
		$cut = mb_substr( $cut, 0, $space );
	}
	return rtrim( $cut, " \t\n\r,.;:" ) . '…';
}

/**
 * Reads, formats and escapes a field in one step.
 *
 * Adds the record timezone to `$opts` for date kinds when none is given.
 *
 * @param string $key    Field key.
 * @param string $type   Record type.
 * @param array  $record The record.
 * @param array  $extra  Render context.
 * @param array  $opts   Formatting options for agend_apps_records_format_field().
 * @return string Escaped HTML, or ''.
 */
function agend_apps_records_render_field( string $key, string $type, array $record, array $extra = array(), array $opts = array() ): string {
	$kind = agend_apps_records_field_kind( $key );
	if ( '' === $kind ) {
		return '';
	}
	if ( ! isset( $opts['timezone'] ) && in_array( $kind, array( 'date', 'date_range', 'date_time' ), true ) ) {
		$opts['timezone'] = agend_apps_records_record_timezone( $record );
	}
	return agend_apps_records_format_field( agend_apps_records_field_value( $key, $type, $record, $extra ), $kind, $opts );
}
