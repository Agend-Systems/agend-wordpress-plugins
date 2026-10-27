<?php
/**
 * Agend Access panel markup.
 *
 * Every value rendered here comes from Agend_Content_Access_Meta_Box::payload(),
 * which is built from the reduced catalogue and the stored policy. No API key,
 * gateway URL or raw gateway payload is in scope, by construction rather than
 * by care.
 *
 * @package Agend_Content_Access
 *
 * @var array $data Panel payload.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$agend_modes = array(
	Agend_Content_Access_Policy::MODE_PUBLIC       => __( 'Everyone can read this.', 'agend-content-access' ),
	Agend_Content_Access_Policy::MODE_MEMBERS      => __( 'Any member with a current membership.', 'agend-content-access' ),
	Agend_Content_Access_Policy::MODE_TIERS        => __( 'Only members on the plans you choose.', 'agend-content-access' ),
	Agend_Content_Access_Policy::MODE_SEGMENTS     => __( 'Only people in the segments you choose.', 'agend-content-access' ),
	Agend_Content_Access_Policy::MODE_ENTITLEMENTS => __( 'Only people who hold the entitlements you choose.', 'agend-content-access' ),
);

/*
 * Wording that differs by list. Each sentence is written out whole rather than
 * built from a noun so that it can be translated as a sentence.
 *
 * `none` is shown when the list cannot be used (it could not be read, or it is
 * empty) and `empty` when it loaded fine and the tenant simply has none. The
 * list's own `error` follows either, because it says what to do about it.
 */
$agend_plans_unavailable = __( 'Membership plans are unavailable right now, so a plan-specific audience cannot be chosen. This does not mean the association has no plans. Public and all-active-members can still be saved.', 'agend-content-access' );

$agend_copy = array(
	Agend_Content_Access_Policy::MODE_TIERS        => array(
		'filter'  => __( 'Filter plans', 'agend-content-access' ),
		'unknown' => __( 'Unknown plan', 'agend-content-access' ),
		'stale'   => __( 'The plan list could not be refreshed from Agend, so it may be out of date. Your existing settings are unaffected and can still be saved.', 'agend-content-access' ),
		// Plans keep their long-standing sentence for both: it already says an
		// empty picker is not evidence that there are no plans.
		'none'    => $agend_plans_unavailable,
		'empty'   => $agend_plans_unavailable,
	),
	Agend_Content_Access_Policy::MODE_SEGMENTS     => array(
		'filter'  => __( 'Filter segments', 'agend-content-access' ),
		'unknown' => __( 'Unknown segment', 'agend-content-access' ),
		'stale'   => __( 'The segment list could not be refreshed from Agend, so it may be out of date. Your existing settings are unaffected and can still be saved.', 'agend-content-access' ),
		'none'    => __( 'Segments are unavailable right now, so a segment audience cannot be chosen. This does not mean there are no segments. The other audiences can still be saved.', 'agend-content-access' ),
		'empty'   => __( 'There are no segments in Agend yet, so a segment audience cannot be chosen. The other audiences can still be saved.', 'agend-content-access' ),
	),
	Agend_Content_Access_Policy::MODE_ENTITLEMENTS => array(
		'filter'  => __( 'Filter entitlements', 'agend-content-access' ),
		'unknown' => __( 'Unknown entitlement', 'agend-content-access' ),
		'stale'   => __( 'The entitlement list could not be refreshed from Agend, so it may be out of date. Your existing settings are unaffected and can still be saved.', 'agend-content-access' ),
		'none'    => __( 'Entitlements are unavailable right now, so an entitlement audience cannot be chosen. This does not mean there are no entitlements. The other audiences can still be saved.', 'agend-content-access' ),
		'empty'   => __( 'There are no entitlements in Agend yet, so an entitlement audience cannot be chosen. The other audiences can still be saved.', 'agend-content-access' ),
	),
);
?>
<div class="agend-access-panel">

	<?php if ( ! empty( $data['unreadable'] ) ) : ?>
		<p class="agend-access-warning agend-access-unreadable notice notice-error notice-alt">
			<?php
			esc_html_e(
				'The audience saved for this page could not be read, so the page is hidden from all visitors until it is set again. Choose who can read it below, then update the page.',
				'agend-content-access'
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! empty( $data['unmodelled_types'] ) ) : ?>
		<p class="agend-access-warning notice notice-info notice-alt">
			<?php
			esc_html_e(
				'This page uses Elementor\'s new editor. Individual sections on it cannot be restricted separately, because those elements do not accept the Agend Access control. The setting below still applies to the WHOLE page.',
				'agend-content-access'
			);
			?>
		</p>
	<?php endif; ?>

	<?php
	foreach ( $agend_modes as $agend_mode => $agend_description ) :
		$agend_is_set = isset( $data['lists'][ $agend_mode ] );
		$agend_list   = $agend_is_set ? $data['lists'][ $agend_mode ] : array();

		/*
		 * The stored mode's radio is ALWAYS checked, even when its list is
		 * unavailable and the radio is therefore disabled. A disabled control is
		 * not submitted, so the save keeps the stored policy rather than reading
		 * "no mode" as "cleared". Leaving the radio unchecked, or not rendering
		 * it, would be the opposite: a restricted page that looks unset.
		 */
		?>
		<p class="agend-access-mode">
			<label>
				<input
					type="radio"
					name="<?php echo esc_attr( Agend_Content_Access_Meta_Box::FIELD_MODE ); ?>"
					value="<?php echo esc_attr( $agend_mode ); ?>"
					<?php checked( $data['mode'], $agend_mode ); ?>
					<?php disabled( $agend_is_set && empty( $agend_list['can_select'] ) ); ?>
				/>
				<strong><?php echo esc_html( Agend_Content_Access_Policy::label( $agend_mode ) ); ?></strong>
			</label>
			<span class="description"><?php echo esc_html( $agend_description ); ?></span>
		</p>

		<?php
		if ( ! $agend_is_set ) {
			continue;
		}

		$agend_text = $agend_copy[ $agend_mode ];

		// What is wrong with this list, shown under its radio and always visible:
		// a disabled radio cannot be chosen, so a message that lived with the
		// list would never be seen by somebody who needs it.
		$agend_notice = '';

		if ( empty( $agend_list['can_select'] ) ) {
			$agend_notice = '' === $agend_list['error'] ? $agend_text['empty'] : $agend_text['none'];
		} elseif ( ! empty( $agend_list['stale'] ) ) {
			$agend_notice = $agend_text['stale'];
		}
		?>

		<?php if ( '' !== $agend_notice || '' !== $agend_list['error'] ) : ?>
			<p class="agend-access-warning agend-access-list-notice notice notice-warning notice-alt" data-agend-access-notice="<?php echo esc_attr( $agend_mode ); ?>">
				<?php echo esc_html( trim( $agend_notice . ' ' . $agend_list['error'] ) ); ?>
			</p>
		<?php endif; ?>

		<div
			class="agend-access-set"
			data-agend-access-set="<?php echo esc_attr( $agend_mode ); ?>"
			<?php echo $agend_mode === $data['mode'] ? '' : 'hidden'; ?>
		>
			<?php if ( $agend_list['unavailable_count'] > 0 && ! empty( $agend_list['can_select'] ) ) : ?>
				<p class="agend-access-warning notice notice-error notice-alt">
					<?php
					switch ( $agend_mode ) {
						case Agend_Content_Access_Policy::MODE_SEGMENTS:
							$agend_unavailable = _n(
								'%d selected segment is no longer available in Agend. It is kept below so you can repair this policy. Until you do, that segment admits nobody.',
								'%d selected segments are no longer available in Agend. They are kept below so you can repair this policy. Until you do, those segments admit nobody.',
								(int) $agend_list['unavailable_count'],
								'agend-content-access'
							);
							break;
						case Agend_Content_Access_Policy::MODE_ENTITLEMENTS:
							$agend_unavailable = _n(
								'%d selected entitlement is no longer available in Agend. It is kept below so you can repair this policy. Until you do, nobody qualifies through that entitlement.',
								'%d selected entitlements are no longer available in Agend. They are kept below so you can repair this policy. Until you do, nobody qualifies through those entitlements.',
								(int) $agend_list['unavailable_count'],
								'agend-content-access'
							);
							break;
						default:
							$agend_unavailable = _n(
								'%d selected plan is no longer available in Agend. It is kept below so you can repair this policy. Until you do, that plan grants nobody access.',
								'%d selected plans are no longer available in Agend. They are kept below so you can repair this policy. Until you do, those plans grant nobody access.',
								(int) $agend_list['unavailable_count'],
								'agend-content-access'
							);
							break;
					}

					echo esc_html( sprintf( $agend_unavailable, (int) $agend_list['unavailable_count'] ) );
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $agend_list['options'] ) ) : ?>
				<p>
					<label class="screen-reader-text" for="agend-access-filter-<?php echo esc_attr( $agend_mode ); ?>">
						<?php echo esc_html( $agend_text['filter'] ); ?>
					</label>
					<input
						type="search"
						id="agend-access-filter-<?php echo esc_attr( $agend_mode ); ?>"
						class="widefat"
						data-agend-access-filter
						placeholder="<?php echo esc_attr( $agend_text['filter'] ); ?>"
					/>
				</p>
			<?php endif; ?>

			<?php if ( ! empty( $agend_list['options'] ) || $agend_list['unavailable_count'] > 0 ) : ?>
			<ul class="agend-access-list">
				<?php
				// Unavailable selections first: they are the ones needing action.
				foreach ( $agend_list['selected'] as $agend_selected ) :
					if ( ! empty( $agend_selected['available'] ) ) {
						continue;
					}
					?>
					<li class="agend-access-item agend-access-item--unavailable" data-agend-access-item>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $agend_list['field'] ); ?>[]"
								value="<?php echo esc_attr( $agend_selected['value'] ); ?>"
								checked
							/>
							<span class="agend-access-item-name">
								<?php echo esc_html( '' !== $agend_selected['label'] ? $agend_selected['label'] : $agend_text['unknown'] ); ?>
							</span>
							<span class="agend-access-item-flag">
								<?php esc_html_e( 'unavailable', 'agend-content-access' ); ?>
							</span>
						</label>
					</li>
				<?php endforeach; ?>

				<?php
				$agend_selected_values = wp_list_pluck( $agend_list['selected'], 'value' );

				foreach ( $agend_list['options'] as $agend_option ) :
					?>
					<li class="agend-access-item" data-agend-access-item>
						<label>
							<input
								type="checkbox"
								name="<?php echo esc_attr( $agend_list['field'] ); ?>[]"
								value="<?php echo esc_attr( $agend_option['value'] ); ?>"
								<?php checked( in_array( $agend_option['value'], $agend_selected_values, true ) ); ?>
							/>
							<span class="agend-access-item-name"><?php echo esc_html( $agend_option['label'] ); ?></span>
						</label>
						<?php if ( '' !== $agend_option['description'] ) : ?>
							<span class="agend-access-item-description"><?php echo esc_html( $agend_option['description'] ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ul>
			<?php endif; ?>
		</div>
	<?php endforeach; ?>
</div>
