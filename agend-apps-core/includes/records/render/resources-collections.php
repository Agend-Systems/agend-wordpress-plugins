<?php
/**
 * Server render of the resource collections surface.
 *
 * Page-builder agnostic: the same markup whichever editor placed the surface.
 * An adapter passes the surface's settings (see
 * `agend_apps_records_surface_schema()`) and echoes the returned HTML.
 *
 * The page is the same for every viewer, so a full-page cache can hold it. It
 * holds only what an editor chose to publish: the collections the editor
 * picked by hand, with their title, description and topics. The shared
 * collection list carries no visibility (it has every collection, whoever it is
 * meant for), so it is never printed whole, and nothing here counts the pages
 * inside a collection, which would give away how much a members-only
 * collection holds.
 *
 * A signed-in member's own collections are not in the page. A small script
 * asks the site for them after the page loads (the member's view must never
 * enter a shared cache) and the same card markup is drawn on the server for
 * that answer too, by {@see agend_apps_records_resources_collections_member_html()}.
 *
 * @package Agend_Apps_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../surface-gate.php';
require_once __DIR__ . '/../schema/resources-collections.php';
require_once __DIR__ . '/resources-common.php';

/**
 * Builds the render config from the surface settings.
 *
 * @param array $s Surface settings (Elementor-shaped values: toggles are 'yes'/'').
 * @return array The values the renderer reads, typed and bounded.
 */
function agend_apps_records_resources_collections_build_config( array $s ): array {
	$colours = agend_apps_records_resources_colours( $s );
	$on      = static function ( string $key, string $fallback = 'yes' ) use ( $s ): bool {
		return agend_apps_records_resources_on( $s, $key, $fallback );
	};

	$text = static function ( string $key, string $fallback ) use ( $s ): string {
		return array_key_exists( $key, $s ) ? trim( (string) $s[ $key ] ) : $fallback;
	};

	return array(
		'heading'  => array(
			'show'     => $on( 'show_heading' ),
			'title'    => $text( 'heading_text', '' ),
			'subtitle' => $text( 'subheading_text', '' ),
		),
		'ids'      => agend_apps_records_resources_id_list( $s['collection_ids'] ?? array(), AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX ),
		'max'      => max( 1, min( AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX, (int) ( $s['max_collections'] ?? 6 ) ) ),
		'layout'   => array(
			'desktop' => max( 1, min( 4, (int) ( $s['columns_desktop'] ?? 3 ) ) ),
			'tablet'  => max( 1, min( 3, (int) ( $s['columns_tablet'] ?? 2 ) ) ),
			'mobile'  => max( 1, min( 2, (int) ( $s['columns_mobile'] ?? 1 ) ) ),
			'radius'  => max( 0, min( 48, (int) ( $s['card_radius'] ?? 10 ) ) ),
		),
		'card'     => array(
			'description'   => $on( 'show_description' ),
			'excerptLength' => max( 20, min( 400, (int) ( $s['excerpt_length'] ?? 140 ) ) ),
			'topics'        => $on( 'show_topics' ),
		),
		'visitor'  => array(
			'note'     => $text( 'visitor_note', '' ),
			'ctaLabel' => $text( 'visitor_cta_label', '' ),
			'ctaUrl'   => agend_apps_records_resources_url( $s['visitor_cta_url'] ?? '' ),
		),
		'members'  => array(
			'enabled' => $on( 'show_member_collections' ),
			'heading' => $text( 'member_heading', '' ),
			'replace' => 'replace' === ( $s['member_view'] ?? 'additional' ),
			'pages'   => max( 0, min( 12, (int) ( $s['member_pages_shown'] ?? 4 ) ) ),
		),
		'colours'  => $colours['colours'],
		'theme'    => array(
			'colourSource' => $colours['source'],
		),
	);
}

/**
 * One collection as the renderer draws it, from the gateway's collection row.
 *
 * Only the fields that are published on purpose are kept: the item count and
 * the dates are dropped here, so no later change to a card can print them.
 *
 * @param array $row A collection, from the list or a member's list.
 * @return array{id: string, title: string, description: string, topics: string[]}
 */
function agend_apps_records_resources_collections_normalise( array $row ): array {
	return array(
		'id'          => (string) ( $row['id'] ?? '' ),
		'title'       => trim( wp_strip_all_tags( is_scalar( $row['title'] ?? null ) ? (string) $row['title'] : '' ) ),
		'description' => is_scalar( $row['description'] ?? null ) ? (string) $row['description'] : '',
		'topics'      => agend_apps_records_resources_topic_names( $row['topics'] ?? array() ),
	);
}

/**
 * The collections an editor chose, in the order chosen.
 *
 * One read of the shared collection list (held in the shared cache, the same
 * for every visitor), then the chosen ones picked out of it. A chosen
 * collection that has since been deleted is left out. The list is not filtered
 * any other way, because it has nothing to filter on: the editor's choice is
 * the whole of what is published.
 *
 * @param array $config The surface config.
 * @return array{items: array, error: bool} Normalised collections, and whether the read failed.
 */
function agend_apps_records_resources_collections_fetch( array $config ): array {
	if ( array() === $config['ids'] ) {
		return array(
			'items' => array(),
			'error' => false,
		);
	}

	if ( ! function_exists( 'agend_apps_atlas_get_collections' ) ) {
		return array(
			'items' => array(),
			'error' => true,
		);
	}

	$list = agend_apps_records_unwrap_list( agend_apps_atlas_get_collections( array( 'limit' => 100 ) ) );

	if ( $list['error'] ) {
		return array(
			'items' => array(),
			'error' => true,
		);
	}

	$by_id = array();

	foreach ( $list['items'] as $row ) {
		$collection = agend_apps_records_resources_collections_normalise( $row );

		if ( '' !== $collection['id'] && '' !== $collection['title'] ) {
			$by_id[ $collection['id'] ] = $collection;
		}
	}

	$items = array();

	foreach ( $config['ids'] as $id ) {
		if ( isset( $by_id[ $id ] ) ) {
			$items[] = $by_id[ $id ];
		}
	}

	return array(
		'items' => array_slice( $items, 0, $config['max'] ),
		'error' => false,
	);
}

/**
 * The topics of a card, as small labels.
 *
 * @param string[] $topics Topic names.
 * @return string Escaped HTML, or ''.
 */
function agend_apps_records_resources_collections_render_topics( array $topics ): string {
	if ( array() === $topics ) {
		return '';
	}

	$html = '<ul class="agend-resources-collections__topics" role="list">';

	foreach ( array_slice( $topics, 0, 5 ) as $topic ) {
		$html .= '<li class="agend-resources-collections__topic">' . esc_html( $topic ) . '</li>';
	}

	return $html . '</ul>';
}

/**
 * The top of a card: the icon, the title and the description. Shared by a
 * visitor's card and a member's.
 *
 * @param array  $c       A normalised collection.
 * @param array  $card    The `card` config.
 * @param string $level   The title's tag: `h3` or `h4`.
 * @return string Escaped HTML.
 */
function agend_apps_records_resources_collections_render_card_head( array $c, array $card, string $level ): string {
	$excerpt = $card['description'] ? agend_apps_records_resources_excerpt( $c['description'], $card['excerptLength'] ) : '';

	$html  = '<span class="agend-resources-collections__icon" aria-hidden="true">' . agend_apps_records_resources_icon( 'stack' ) . '</span>';
	$html .= '<' . $level . ' class="agend-resources-collections__name">' . esc_html( $c['title'] ) . '</' . $level . '>';

	if ( '' !== $excerpt ) {
		$html .= '<p class="agend-resources-collections__description">' . esc_html( $excerpt ) . '</p>';
	}

	if ( $card['topics'] ) {
		$html .= agend_apps_records_resources_collections_render_topics( $c['topics'] );
	}

	return $html;
}

/**
 * A visitor's card: title, description and topics, and no link, because a
 * collection has no page of its own to open.
 *
 * @param array $c      A normalised collection.
 * @param array $config The surface config.
 * @return string Escaped HTML.
 */
function agend_apps_records_resources_collections_render_card( array $c, array $config ): string {
	return '<article class="agend-resources-collections__card">'
		. agend_apps_records_resources_collections_render_card_head( $c, $config['card'], 'h3' )
		. '</article>';
}

/**
 * A member's card: the same head, then the pages in the collection the member
 * can open.
 *
 * A page links by the rules in {@see agend_apps_atlas_page_link()}: a page found
 * through a member's own view is member-only. Where the sign-in mode gives no
 * working link the title is listed without one, and the caller says why once.
 *
 * @param array $c       A normalised collection, plus `pages`: list of `{id, title}`.
 * @param array $opts    `card` (the card config) and `pages` (how many to list).
 * @param bool  $unlinked Set to true when a page was listed without a link.
 * @return string Escaped HTML.
 */
function agend_apps_records_resources_collections_render_member_card( array $c, array $opts, bool &$unlinked ): string {
	$html = '<article class="agend-resources-collections__card agend-resources-collections__card--member">'
		. agend_apps_records_resources_collections_render_card_head( $c, $opts['card'], 'h3' === ( $opts['level'] ?? 'h4' ) ? 'h3' : 'h4' );

	$pages = array_slice( $c['pages'], 0, $opts['pages'] );

	if ( array() !== $pages ) {
		$html .= '<ul class="agend-resources-collections__pages" role="list">';

		foreach ( $pages as $page ) {
			$link = function_exists( 'agend_apps_atlas_page_link' ) ? agend_apps_atlas_page_link( $page['id'], false ) : array( 'url' => '' );

			if ( '' !== $link['url'] ) {
				$html .= '<li class="agend-resources-collections__page agend-resources-collections__page--link">'
					. '<span class="agend-resources-collections__page-main"><a class="agend-resources-collections__page-link exclude-agend-theme-link" href="' . esc_url( $link['url'] ) . '">' . esc_html( $page['title'] ) . '</a></span>'
					. agend_apps_records_resources_icon( 'chevron' )
					. '</li>';
			} else {
				$unlinked = true;
				$html    .= '<li class="agend-resources-collections__page"><span class="agend-resources-collections__page-main"><span class="agend-resources-collections__page-title">' . esc_html( $page['title'] ) . '</span></span></li>';
			}
		}

		$html .= '</ul>';

		$more = count( $c['pages'] ) - count( $pages );

		if ( $more > 0 ) {
			/* translators: %s: number of further pages in a collection. */
			$html .= '<p class="agend-resources-collections__more">' . esc_html( sprintf( _n( 'and %s more page', 'and %s more pages', $more, 'agend-apps-core' ), number_format_i18n( $more ) ) ) . '</p>';
		}
	}

	return $html . '</article>';
}

/**
 * A signed-in member's collections as the grid the page draws, from the
 * member's own list.
 *
 * Used by the REST fragment the script loads after the page. Descriptions come
 * from the shared collection list, matched by id, because the member's list
 * carries none.
 *
 * @param array $rows         The member's collections, as the gateway sends them.
 * @param array $descriptions Collection id => description, from the shared list.
 * @param array $opts         `card` (the card config), `pages` (pages to list per collection) and `level` (the card title's tag).
 * @return array{html: string, count: int} The grid (and a note when pages were listed without links), and how many collections it holds.
 */
function agend_apps_records_resources_collections_member_html( array $rows, array $descriptions, array $opts ): array {
	$items = array();

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}

		$c = agend_apps_records_resources_collections_normalise( $row );

		if ( '' === $c['id'] || '' === $c['title'] ) {
			continue;
		}

		if ( '' === $c['description'] && isset( $descriptions[ $c['id'] ] ) ) {
			$c['description'] = (string) $descriptions[ $c['id'] ];
		}

		$c['pages'] = array();

		foreach ( (array) ( $row['items'] ?? array() ) as $item ) {
			$title = is_array( $item ) && is_scalar( $item['title'] ?? null ) ? trim( wp_strip_all_tags( (string) $item['title'] ) ) : '';
			$id    = is_array( $item ) && is_scalar( $item['page_id'] ?? null ) ? (string) $item['page_id'] : '';

			if ( '' !== $title && '' !== $id ) {
				$c['pages'][] = array(
					'id'    => $id,
					'title' => $title,
				);
			}
		}

		$items[] = $c;
	}

	$items = array_slice( $items, 0, AGEND_APPS_RECORDS_RESOURCES_COLLECTIONS_MAX );

	if ( array() === $items ) {
		return array(
			'html'  => '',
			'count' => 0,
		);
	}

	$unlinked = false;
	$html     = '<ul class="agend-resources-collections__grid" role="list">';

	foreach ( $items as $c ) {
		$html .= '<li class="agend-resources-collections__item">' . agend_apps_records_resources_collections_render_member_card( $c, $opts, $unlinked ) . '</li>';
	}

	$html .= '</ul>';

	if ( $unlinked ) {
		$html .= '<p class="agend-res__note agend-resources-collections__sign-in-note">' . esc_html__( 'Sign in to Atlas to read these pages.', 'agend-apps-core' ) . '</p>';
	}

	return array(
		'html'  => $html,
		'count' => count( $items ),
	);
}

/**
 * Renders the surface on the front end, returned as HTML.
 *
 * @param array $settings Surface settings (Elementor-shaped values).
 * @param array $opts     Render opts: `preview` is true when an editor, not a
 *                        visitor, is looking at it in the page builder.
 * @return string
 */
function agend_apps_records_render_resources_collections( array $settings, array $opts = array() ): string {
	$preview = ! empty( $opts['preview'] );
	$gate    = agend_apps_records_surface_gate( 'resources-collections', $preview );

	if ( null !== $gate ) {
		return $gate;
	}

	// A list inside a card template would be fetched once per card.
	if ( Agend_Apps_Records_Record_Context::has() ) {
		return '';
	}

	$config  = agend_apps_records_resources_collections_build_config( $settings );
	$read    = agend_apps_records_resources_collections_fetch( $config );
	$items   = $read['items'];
	$members = $config['members']['enabled'];

	// Nothing to show a visitor, and no member view to add: draw nothing, as a
	// page for visitors should when an editor has chosen nothing. A failed read
	// is not drawn either, so a failure is never held in a page cache.
	if ( array() === $items && ! $members && ! $preview ) {
		return '';
	}

	$heading = $config['heading'];
	$classes = 'agend-res agend-resources-collections';
	$style   = agend_apps_records_resources_style( $config['colours'], $config['layout']['radius'], sprintf( '--agend-res-cols-desktop:%1$d;--agend-res-cols-tablet:%2$d;--agend-res-cols-mobile:%3$d;', $config['layout']['desktop'], $config['layout']['tablet'], $config['layout']['mobile'] ) );

	// The member heading sits one level under the page heading when both show.
	$member_level = ( array() !== $items && ! $config['members']['replace'] ) ? 'h3' : 'h2';

	// What the script needs to ask for a member's collections.
	$client = array(
		'endpoint'    => esc_url_raw( rest_url( 'agend-apps/v1/resources/collections/mine' ) ),
		'description' => $config['card']['description'],
		'excerpt'     => $config['card']['excerptLength'],
		'topics'      => $config['card']['topics'],
		'pages'       => $config['members']['pages'],
		'replace'     => $config['members']['replace'],
		'level'       => 'h3' === $member_level ? 4 : 3,
		'messages'    => array(
			'failed' => __( 'Your collections could not be loaded just now.', 'agend-apps-core' ),
		),
	);

	ob_start();
	?>
	<div class="<?php echo esc_attr( $classes ); ?>" style="<?php echo esc_attr( $style ); ?>"<?php echo ( array() === $items && ! $preview ) ? ' hidden' : ''; ?><?php echo $members ? ' data-agend-resources-collections="' . esc_attr( (string) wp_json_encode( $client ) ) . '"' : ''; ?>>
		<?php if ( array() !== $items || $preview ) : ?>
			<div class="agend-resources-collections__public">
				<?php echo agend_apps_records_resources_heading( $heading['show'], $heading['title'], $heading['subtitle'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper. ?>
				<?php if ( array() === $items && $preview ) : ?>
					<?php
					echo agend_apps_records_resources_editor_note( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the helper.
						array() === $config['ids']
							? __( 'Choose the collections to show in this widget\'s settings. Visitors see nothing here until you do.', 'agend-apps-core' )
							: __( 'The collections you chose could not be loaded just now, or no longer exist. Visitors see nothing here.', 'agend-apps-core' )
					);
					?>
				<?php elseif ( array() !== $items ) : ?>
					<ul class="agend-resources-collections__grid" role="list">
						<?php foreach ( $items as $collection ) : ?>
							<li class="agend-resources-collections__item">
								<?php echo agend_apps_records_resources_collections_render_card( $collection, $config ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the card renderer. ?>
							</li>
						<?php endforeach; ?>
					</ul>
					<?php if ( '' !== $config['visitor']['note'] ) : ?>
						<p class="agend-res__note agend-resources-collections__visitor">
							<span><?php echo esc_html( $config['visitor']['note'] ); ?></span>
							<?php if ( '' !== $config['visitor']['ctaUrl'] && '' !== $config['visitor']['ctaLabel'] ) : ?>
								<a class="agend-res__button exclude-agend-theme-link" href="<?php echo esc_url( $config['visitor']['ctaUrl'] ); ?>"><?php echo esc_html( $config['visitor']['ctaLabel'] ); ?></a>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<?php if ( $members ) : ?>
			<div class="agend-resources-collections__mine" hidden>
				<?php if ( '' !== $config['members']['heading'] ) : ?>
					<<?php echo esc_attr( $member_level ); ?> class="agend-res__title agend-resources-collections__mine-title"><?php echo esc_html( $config['members']['heading'] ); ?></<?php echo esc_attr( $member_level ); ?>>
				<?php endif; ?>
				<div class="agend-resources-collections__mine-body" aria-live="polite"></div>
			</div>
		<?php endif; ?>
	</div>
	<?php

	return (string) ob_get_clean();
}
