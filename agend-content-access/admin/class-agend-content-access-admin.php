<?php
/**
 * Connector settings screen.
 *
 * @package Agend_Content_Access
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Issues, shows and rotates the connector credential.
 *
 * The token is displayed exactly once, immediately after it is issued, and is
 * never recoverable afterwards. That is a deliberate cost to the operator: it
 * removes any place the plaintext could be read back from later.
 */
class Agend_Content_Access_Admin {

	const PAGE  = 'agend-content-access';
	const NONCE = 'agend_content_access_credential';

	/**
	 * Plaintext token to show once on this pageload, if one was just issued.
	 *
	 * @var string
	 */
	private string $new_token = '';

	/**
	 * Registers hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'handle_actions' ) );
	}

	/**
	 * Adds the settings page under Settings.
	 */
	public function register_page(): void {
		add_options_page(
			__( 'Agend Content Access', 'agend-content-access' ),
			__( 'Agend Content Access', 'agend-content-access' ),
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Handles issue and revoke submissions.
	 */
	public function handle_actions(): void {
		if ( ! isset( $_POST['agend_content_access_action'] ) ) {
			return;
		}

		// Capability first, then nonce. Both are required: the nonce proves the
		// request came from the form, the capability proves the person is
		// allowed to mint a credential that reads every restricted document.
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE );

		$action = sanitize_text_field( wp_unslash( $_POST['agend_content_access_action'] ) );

		if ( 'issue' === $action ) {
			$this->new_token = Agend_Content_Access_Credentials::issue();
			return;
		}

		if ( 'revoke' === $action ) {
			Agend_Content_Access_Credentials::revoke();
		}
	}

	/**
	 * Renders the screen.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$exists  = Agend_Content_Access_Credentials::exists();
		$created = Agend_Content_Access_Credentials::created_at();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Agend Content Access', 'agend-content-access' ); ?></h1>

			<h2><?php esc_html_e( 'Connector credential', 'agend-content-access' ); ?></h2>
			<p>
				<?php
				esc_html_e(
					'Agend uses this credential to read your authored content and its access policies. It grants content sync only: it cannot administer WordPress and cannot act as one of your members.',
					'agend-content-access'
				);
				?>
			</p>

			<?php if ( '' !== $this->new_token ) : ?>
				<div class="notice notice-success">
					<p><strong><?php esc_html_e( 'New credential issued. Copy it now: it will not be shown again.', 'agend-content-access' ); ?></strong></p>
					<p><code style="user-select:all"><?php echo esc_html( $this->new_token ); ?></code></p>
					<p>
						<?php
						printf(
							/* translators: %s: HTTP header name. */
							esc_html__( 'Agend sends it in the %s header.', 'agend-content-access' ),
							'<code>' . esc_html( Agend_Content_Access_Credentials::HEADER ) . '</code>'
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Status', 'agend-content-access' ); ?></th>
					<td>
						<?php if ( $exists ) : ?>
							<p>
								<?php
								printf(
									/* translators: %s: ISO 8601 timestamp. */
									esc_html__( 'A credential is active, issued %s.', 'agend-content-access' ),
									esc_html( $created )
								);
								?>
							</p>
						<?php else : ?>
							<p><?php esc_html_e( 'No credential is issued. Agend cannot sync content until one is.', 'agend-content-access' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
			</table>

			<form method="post">
				<?php wp_nonce_field( self::NONCE ); ?>
				<p>
					<button
						type="submit"
						name="agend_content_access_action"
						value="issue"
						class="button button-primary"
					>
						<?php
						echo $exists
							? esc_html__( 'Rotate credential', 'agend-content-access' )
							: esc_html__( 'Issue credential', 'agend-content-access' );
						?>
					</button>

					<?php if ( $exists ) : ?>
						<button
							type="submit"
							name="agend_content_access_action"
							value="revoke"
							class="button button-link-delete"
						>
							<?php esc_html_e( 'Revoke', 'agend-content-access' ); ?>
						</button>
					<?php endif; ?>
				</p>
				<?php if ( $exists ) : ?>
					<p class="description">
						<?php
						esc_html_e(
							'Rotating takes effect immediately and there is no grace period: the current credential stops working on the next request, and syncing will fail until Agend is given the new one.',
							'agend-content-access'
						);
						?>
					</p>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}
}
