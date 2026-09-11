<?php
defined( 'ABSPATH' ) || exit;

class BANS_Admin {

	const OPTION_KEY = 'bans_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_bans_send_test_from_last_push', array( __CLASS__, 'send_test_from_last_push' ) );
		add_action( 'admin_init', array( __CLASS__, 'maybe_migrate_legacy_players' ) );
	}

	public static function add_menu() {
		add_options_page(
			'Basketball Nightly Scores',
			'Basketball Scores',
			'manage_options',
			'bans-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function get_settings() {
		$defaults = array(
			'emails'      => '',
			'test_email'  => get_option( 'admin_email' ),
			'push_secret' => '',
		);

		$settings = wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			$defaults
		);

		// Auto-generate secret if missing (safe; admin can regenerate too).
		if ( empty( $settings['push_secret'] ) ) {
			$settings['push_secret'] = wp_generate_password( 40, false, false );
			update_option( self::OPTION_KEY, $settings, false );
		}

		return $settings;
	}

	/**
	 * One-time migration: copy Flashscore fields from the old settings-based
	 * players[] array onto matching rba_player posts (matched by title), and
	 * flag those players for inclusion. Runs once, then records a marker.
	 */
	public static function maybe_migrate_legacy_players() {
		if ( get_option( 'bans_migrated_players' ) ) {
			return;
		}

		$raw            = get_option( self::OPTION_KEY, array() );
		$legacy_players = ( is_array( $raw ) && ! empty( $raw['players'] ) && is_array( $raw['players'] ) )
			? $raw['players']
			: array();

		if ( empty( $legacy_players ) ) {
			update_option( 'bans_migrated_players', 1, false );
			return;
		}

		foreach ( $legacy_players as $legacy ) {
			$label = trim( (string) ( $legacy['label'] ?? '' ) );
			$slug  = trim( (string) ( $legacy['flashscore_slug'] ?? '' ) );
			$id    = trim( (string) ( $legacy['flashscore_id'] ?? '' ) );

			if ( '' === $label || '' === $slug || '' === $id ) {
				continue;
			}

			$match_id = self::find_player_by_title( $label );
			if ( ! $match_id ) {
				continue;
			}

			// Don't clobber fields an editor may already have set on the post.
			if ( '' === (string) get_post_meta( $match_id, BANS_META_SLUG, true ) ) {
				update_post_meta( $match_id, BANS_META_SLUG, sanitize_title( $slug ) );
			}
			if ( '' === (string) get_post_meta( $match_id, BANS_META_ID, true ) ) {
				update_post_meta( $match_id, BANS_META_ID, sanitize_text_field( $id ) );
			}
			update_post_meta( $match_id, BANS_META_INCLUDE, '1' );
		}

		update_option( 'bans_migrated_players', 1, false );
	}

	/**
	 * Find a published player post by exact title. Returns the post ID or 0.
	 */
	private static function find_player_by_title( $title ) {
		$query = new WP_Query( array(
			'post_type'              => BANS_PLAYER_POST_TYPE,
			'post_status'            => 'publish',
			'title'                  => $title,
			'posts_per_page'         => 1,
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'fields'                 => 'ids',
		) );

		return ! empty( $query->posts ) ? (int) $query->posts[0] : 0;
	}

	public static function send_test_from_last_push() {
		check_admin_referer( 'bans_send_test_from_last_push' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$settings = self::get_settings();
		$last     = get_option( 'bans_last_push', array() );
		$rows     = isset( $last['rows'] ) && is_array( $last['rows'] ) ? $last['rows'] : array();

		if ( empty( $rows ) ) {
			wp_redirect( admin_url( 'options-general.php?page=bans-settings&msg=no_rows' ) );
			exit;
		}

		$sent = BANS_Cron::send_email_with_csv( $settings, $rows, true );

		$msg = $sent ? 'test_sent' : 'test_failed';
		wp_redirect( admin_url( 'options-general.php?page=bans-settings&msg=' . $msg ) );
		exit;
	}

	public static function render_page() {
		if ( isset( $_POST['save_bans'] ) ) {
			check_admin_referer( 'bans_save' );
			self::save_player_inclusion();
			update_option( self::OPTION_KEY, self::sanitize_settings(), false );
			echo '<div class="updated"><p>Settings saved.</p></div>';
		}

		if ( isset( $_GET['msg'] ) ) {
			if ( 'test_sent' === $_GET['msg'] ) {
				echo '<div class="updated"><p>Test email sent successfully (using last pushed data). Check your inbox.</p></div>';
			} elseif ( 'test_failed' === $_GET['msg'] ) {
				echo '<div class="notice notice-error"><p>Test email failed to send. Check the debug.log for details.</p></div>';
			} elseif ( 'no_rows' === $_GET['msg'] ) {
				echo '<div class="notice notice-warning"><p>No pushed rows exist yet. Run GitHub Actions once first.</p></div>';
			}
		}

		$settings = self::get_settings();
		$players  = BANS_Players::get_all_players();
		$push_url = home_url( '/wp-json/bans/v1/push' );
		$new_url  = admin_url( 'post-new.php?post_type=' . BANS_PLAYER_POST_TYPE );

		?>
		<style>
			#bans-players-table th,
			#bans-players-table td { padding: 8px 10px; vertical-align: middle; }
			#bans-players-table th:nth-child(1) { width: 60px; text-align: center; }
			#bans-players-table td:nth-child(1) { text-align: center; }
			.bans-missing { color: #b32d2e; font-weight: 600; }
			.bans-ok { color: #1a7f37; }
			.bans-fields { color: #646970; font-size: 12px; }
		</style>

		<div class="wrap">
			<h1>Basketball Nightly Scores</h1>

			<p><strong>Push URL (GitHub Secret: BANS_PUSH_URL)</strong><br>
				<code><?php echo esc_html( $push_url ); ?></code>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'bans_save' ); ?>

				<h2>Players in the Nightly Crawl</h2>
				<p style="max-width: 900px;">
					Tick each player you want scanned nightly. The Flashscore slug and ID
					are set on each
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BANS_PLAYER_POST_TYPE ) ); ?>">Player</a>
					post. A player with missing fields can't be scanned even if ticked.
				</p>

				<?php if ( empty( $players ) ) : ?>
					<p>
						No players found.
						<a href="<?php echo esc_url( $new_url ); ?>">Add a Player</a> to get started.
					</p>
				<?php else : ?>
					<table class="widefat striped" id="bans-players-table">
						<thead>
							<tr>
								<th>Include</th>
								<th>Player</th>
								<th>Flashscore Fields</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $players as $p ) : ?>
								<tr>
									<td>
										<input type="checkbox"
											name="bans_include[]"
											value="<?php echo (int) $p['id']; ?>"
											<?php checked( $p['include'] ); ?>>
									</td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $p['id'] ) ); ?>">
											<?php echo esc_html( $p['label'] ); ?>
										</a>
									</td>
									<td>
										<?php if ( $p['scannable'] ) : ?>
											<span class="bans-ok">&#10003; Ready</span>
											<span class="bans-fields">
												(<?php echo esc_html( $p['flashscore_slug'] ); ?> /
												<?php echo esc_html( $p['flashscore_id'] ); ?>)
											</span>
										<?php else : ?>
											<span class="bans-missing">Missing slug/ID</span>
											&mdash; <a href="<?php echo esc_url( get_edit_post_link( $p['id'] ) ); ?>">edit player</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>

				<h2>Email</h2>
				<p>
					<label><strong>Daily Recipients</strong> (comma-separated)</label><br>
					<textarea name="emails" rows="2" style="width:100%;max-width:900px;"><?php echo esc_textarea( $settings['emails'] ); ?></textarea>
				</p>

				<p>
					<label><strong>Test Email</strong></label><br>
					<input type="email" name="test_email" value="<?php echo esc_attr( $settings['test_email'] ); ?>" style="width:100%;max-width:420px;">
				</p>

				<h2>GitHub Actions Secret</h2>
				<p>
					<label><strong>Push Secret (GitHub Secret: BANS_SECRET)</strong></label><br>
					<input type="text" name="push_secret" value="<?php echo esc_attr( $settings['push_secret'] ); ?>" style="width:100%;max-width:600px;">
				</p>

				<p>
					<button class="button-primary" name="save_bans" value="1">Save Settings</button>
					<button class="button" type="submit" name="regen_secret" value="1">Regenerate Secret</button>
				</p>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'bans_send_test_from_last_push' ); ?>
				<input type="hidden" name="action" value="bans_send_test_from_last_push">
				<button class="button">Send Test Email (Using Last Push + CSV)</button>
			</form>
		</div>
		<?php
	}

	/**
	 * Persist the per-player "include in crawl" flag from the submitted
	 * checkboxes. Any published player not checked is set to excluded.
	 */
	private static function save_player_inclusion() {
		$checked = isset( $_POST['bans_include'] ) && is_array( $_POST['bans_include'] )
			? array_map( 'intval', $_POST['bans_include'] )
			: array();
		$checked = array_flip( $checked );

		foreach ( BANS_Players::get_all_players() as $p ) {
			$include = isset( $checked[ $p['id'] ] ) ? '1' : '';
			update_post_meta( $p['id'], BANS_META_INCLUDE, $include );
		}
	}

	private static function sanitize_settings() {
		$current = self::get_settings();

		// Regenerate secret if requested.
		if ( isset( $_POST['regen_secret'] ) ) {
			$current['push_secret'] = wp_generate_password( 40, false, false );
		} else {
			// Allow manual set (handy for copy/paste).
			$current['push_secret'] = sanitize_text_field( $_POST['push_secret'] ?? $current['push_secret'] );
		}

		return array(
			'emails'      => sanitize_textarea_field( $_POST['emails'] ?? '' ),
			'test_email'  => sanitize_email( $_POST['test_email'] ?? get_option( 'admin_email' ) ),
			'push_secret' => $current['push_secret'],
		);
	}
}
