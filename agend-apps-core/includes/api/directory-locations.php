<?php
/**
 * Directory location normalisation.
 *
 * Kept apart from directory.php so it can be loaded and tested without the
 * request functions that file declares.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maximum locations the gateway accepts on one listing (bulkUpsertListingItemSchema).
 */
if ( ! defined( 'AGEND_APPS_DIRECTORY_MAX_LOCATIONS' ) ) {
	define( 'AGEND_APPS_DIRECTORY_MAX_LOCATIONS', 50 );
}

/**
 * Normalises a listing's `locations[]` before it is sent to the gateway, so a
 * caller that builds the array loosely still sends a clean one. The gateway is
 * not trusted to do this and callers are not trusted to have done it:
 * - Entries that are not arrays are dropped.
 * - An address repeating an earlier one is dropped. Identity is the street
 *   lines, city and postcode lower-cased with everything but letters and
 *   digits removed; an entry with no address text is identified by its
 *   rounded coordinates, and an entry with neither is kept.
 * - Exactly one location is primary: the first marked `is_primary`, or the
 *   first location when none is. If a dropped duplicate was the one marked
 *   primary, the entry it duplicated becomes primary.
 * - The list is cut at the gateway's per-listing cap.
 *
 * @param array $locations A listing's locations array.
 * @return array The normalised, re-indexed locations.
 */
function agend_apps_directory_normalize_locations( array $locations ): array {
	$kept       = array();
	$index_of   = array();
	$primary_at = null;

	foreach ( $locations as $location ) {
		if ( ! is_array( $location ) ) {
			continue;
		}

		$parts = array();
		foreach ( array( 'address_line_1', 'address_line_2', 'city', 'postcode' ) as $key ) {
			$value   = isset( $location[ $key ] ) && is_scalar( $location[ $key ] ) ? (string) $location[ $key ] : '';
			$parts[] = strtolower( (string) preg_replace( '/[^A-Za-z0-9]/', '', $value ) );
		}
		$identity = implode( '|', $parts );

		if ( '|||' === $identity ) {
			$identity = ( isset( $location['latitude'], $location['longitude'] ) && is_numeric( $location['latitude'] ) && is_numeric( $location['longitude'] ) )
				? sprintf( 'geo:%.5f,%.5f', (float) $location['latitude'], (float) $location['longitude'] )
				: 'unique:' . count( $kept );
		}

		$is_primary = ! empty( $location['is_primary'] );

		if ( isset( $index_of[ $identity ] ) ) {
			if ( $is_primary && null === $primary_at ) {
				$primary_at = $index_of[ $identity ];
			}
			continue;
		}

		$index_of[ $identity ] = count( $kept );
		if ( $is_primary && null === $primary_at ) {
			$primary_at = count( $kept );
		}
		$kept[] = $location;
	}

	$kept = array_slice( $kept, 0, AGEND_APPS_DIRECTORY_MAX_LOCATIONS );
	if ( empty( $kept ) ) {
		return array();
	}

	if ( null === $primary_at || $primary_at >= count( $kept ) ) {
		$primary_at = 0;
	}
	foreach ( $kept as $i => $location ) {
		$kept[ $i ]['is_primary'] = ( $i === $primary_at );
	}

	return $kept;
}
