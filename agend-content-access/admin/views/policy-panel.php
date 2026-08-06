<?php
/**
 * Agend Access panel markup.
 *
 * Every value rendered here comes from Agend_Content_Access_Meta_Box::payload(),
 * which is built from the reduced plan catalogue and the stored policy. No API
 * key, gateway URL or raw tier payload is in scope, by construction rather than
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
	Agend_Content_Access_Policy::MODE_PUBLIC  => __( 'Everyone can read this.', 'agend-content-access' ),
	Agend_Content_Access_Policy::MODE_MEMBERS => __( 'Any member with a current membership.', 'agend-content-access' ),
	Agend_Content_Access_Policy::MODE_TIERS   => __( 'Only members on the plans you choose.', 'agend-content-access' ),
);
?>
<div class="agend-access-panel">

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

	<?php if ( ! empty( $data['stale'] ) ) : ?>
		<p class="agend-access-warning notice notice-warning notice-alt">
			<?php esc_html_e( 'The plan list could not be refreshed from Agend, so it may be out of date. Your existing settings are unaffected and can still be saved.', 'agend-content-access' ); ?>
		</p>
	<?php endif; ?>

	<?php foreach ( $agend_modes as $agend_mode => $agend_description ) : ?>
		<p class="agend-access-mode">
			<label>
				<input
					type="radio"
					name="<?php echo esc_attr( Agend_Content_Access_Meta_Box::FIELD_MODE ); ?>"
					value="<?php echo esc_attr( $agend_mode ); ?>"
					<?php checked( $data['mode'], $agend_mode ); ?>
					<?php disabled( Agend_Content_Access_Policy::MODE_TIERS === $agend_mode && empty( $data['can_select'] ) ); ?>
				/>
				<strong><?php echo esc_html( Agend_Content_Access_Policy::label( $agend_mode ) ); ?></strong>
			</label>
			<span class="description"><?php echo esc_html( $agend_description ); ?></span>
		</p>
	<?php endforeach; ?>

	<?php if ( empty( $data['can_select'] ) ) : ?>
		<p class="agend-access-warning notice notice-warning notice-alt">
			<?php esc_html_e( 'Membership plans are unavailable right now, so a plan-specific audience cannot be chosen. This does not mean the association has no plans. Public and all-active-members can still be saved.', 'agend-content-access' ); ?>
		</p>
	<?php endif; ?>

	<div
		class="agend-access-plans"
		data-agend-access-plans
		<?php echo Agend_Content_Access_Policy::MODE_TIERS === $data['mode'] ? '' : 'hidden'; ?>
	>
		<?php if ( $data['unavailable_count'] > 0 ) : ?>
			<p class="agend-access-warning notice notice-error notice-alt">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of plans. */
						_n(
							'%d selected plan is no longer available in Agend. It is kept below so you can repair this policy. Until you do, that plan grants nobody access.',
							'%d selected plans are no longer available in Agend. They are kept below so you can repair this policy. Until you do, those plans grant nobody access.',
							(int) $data['unavailable_count'],
							'agend-content-access'
						),
						(int) $data['unavailable_count']
					)
				);
				?>
			</p>
		<?php endif; ?>

		<?php if ( ! empty( $data['plans'] ) ) : ?>
			<p>
				<label class="screen-reader-text" for="agend-access-plan-filter">
					<?php esc_html_e( 'Filter plans', 'agend-content-access' ); ?>
				</label>
				<input
					type="search"
					id="agend-access-plan-filter"
					class="widefat"
					data-agend-access-filter
					placeholder="<?php esc_attr_e( 'Filter plans', 'agend-content-access' ); ?>"
				/>
			</p>
		<?php endif; ?>

		<ul class="agend-access-plan-list">
			<?php
			// Unavailable selections first: they are the ones needing action.
			foreach ( $data['selected'] as $agend_selected ) :
				if ( ! empty( $agend_selected['available'] ) ) {
					continue;
				}
				?>
				<li class="agend-access-plan agend-access-plan--unavailable" data-agend-access-plan>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( Agend_Content_Access_Meta_Box::FIELD_TIERS ); ?>[]"
							value="<?php echo esc_attr( $agend_selected['id'] ); ?>"
							checked
						/>
						<span class="agend-access-plan-name">
							<?php
							echo esc_html(
								'' !== $agend_selected['name']
									? $agend_selected['name']
									: __( 'Unknown plan', 'agend-content-access' )
							);
							?>
						</span>
						<span class="agend-access-plan-flag">
							<?php esc_html_e( 'unavailable', 'agend-content-access' ); ?>
						</span>
					</label>
				</li>
			<?php endforeach; ?>

			<?php
			$agend_selected_ids = wp_list_pluck( $data['selected'], 'id' );

			foreach ( $data['plans'] as $agend_plan ) :
				?>
				<li class="agend-access-plan" data-agend-access-plan>
					<label>
						<input
							type="checkbox"
							name="<?php echo esc_attr( Agend_Content_Access_Meta_Box::FIELD_TIERS ); ?>[]"
							value="<?php echo esc_attr( $agend_plan['id'] ); ?>"
							<?php checked( in_array( $agend_plan['id'], $agend_selected_ids, true ) ); ?>
						/>
						<span class="agend-access-plan-name"><?php echo esc_html( $agend_plan['name'] ); ?></span>
					</label>
				</li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>
