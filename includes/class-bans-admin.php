<?php
defined( 'ABSPATH' ) || exit;

class BANS_Admin {

	const OPTION_KEY = 'bans_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_bans_send_test_from_last_push', array( __CLASS__, 'send_test_from_last_push' ) );
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
			'players'     => array(),
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

	public static function send_test_from_last_push() {
		check_admin_referer( 'bans_send_test_from_last_push' );

		$settings = self::get_settings();
		$last     = get_option( 'bans_last_push', array() );
		$rows     = isset( $last['rows'] ) && is_array( $last['rows'] ) ? $last['rows'] : array();

		if ( empty( $rows ) ) {
			wp_redirect( admin_url( 'options-general.php?page=bans-settings&msg=no_rows' ) );
			exit;
		}

		BANS_Cron::send_email_with_csv( $settings, $rows, true );

		wp_redirect( admin_url( 'options-general.php?page=bans-settings&msg=test_sent' ) );
		exit;
	}

	public static function render_page() {
		if ( isset( $_POST['save_bans'] ) ) {
			check_admin_referer( 'bans_save' );
			update_option( self::OPTION_KEY, self::sanitize_settings(), false );
			echo '<div class="updated"><p>Settings saved.</p></div>';
		}

		if ( isset( $_GET['msg'] ) ) {
			if ( 'test_sent' === $_GET['msg'] ) {
				echo '<div class="updated"><p>Test email sent (using last pushed data).</p></div>';
			} elseif ( 'no_rows' === $_GET['msg'] ) {
				echo '<div class="notice notice-warning"><p>No pushed rows exist yet. Run GitHub Actions once first.</p></div>';
			}
		}

		$settings = self::get_settings();
		$players  = isset( $settings['players'] ) && is_array( $settings['players'] ) ? $settings['players'] : array();

		$push_url = home_url( '/wp-json/bans/v1/push' );

		?>
		<style>
			#bans-players-table input {
				height: 28px;
				font-size: 13px;
				width: 100%;
				max-width: 420px;
			}
			#bans-players-table th,
			#bans-players-table td {
				padding: 6px 8px;
				vertical-align: middle;
			}
			#bans-players-table th:nth-child(1) { width: 22%; }
			#bans-players-table th:nth-child(2) { width: 32%; }
			#bans-players-table th:nth-child(3) { width: 32%; }
			#bans-players-table th:nth-child(4) { width: 14%; }
		</style>

		<div class="wrap">
			<h1>Basketball Nightly Scores</h1>

			<p><strong>Push URL (GitHub Secret: BANS_PUSH_URL)</strong><br>
				<code><?php echo esc_html( $push_url ); ?></code>
			</p>

			<form method="post">
				<?php wp_nonce_field( 'bans_save' ); ?>

				<h2>Players (for reference)</h2>
				<p style="max-width: 900px;">
					This list is stored in WordPress for reference, but the GitHub Actions script currently defines the players it scrapes.
					(If you want, we can make GitHub fetch players from WP securely in a follow-up.)
				</p>

				<table class="widefat" id="bans-players-table">
					<thead>
						<tr>
							<th>Label</th>
							<th>Flashscore Slug</th>
							<th>Flashscore ID</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $players as $i => $p ) : ?>
							<tr class="bans-player-row">
								<td><input type="text" name="players[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $p['label'] ?? '' ); ?>"></td>
								<td><input type="text" name="players[<?php echo (int) $i; ?>][flashscore_slug]" value="<?php echo esc_attr( $p['flashscore_slug'] ?? '' ); ?>"></td>
								<td><input type="text" name="players[<?php echo (int) $i; ?>][flashscore_id]" value="<?php echo esc_attr( $p['flashscore_id'] ?? '' ); ?>"></td>
								<td><button type="button" class="button bans-remove-player">Remove</button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:10px;">
					<button type="button" class="button" id="bans-add-player">Add Player</button>
				</p>

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

		<script>
		(function () {
			const tableBody = document.querySelector('#bans-players-table tbody');
			const addBtn = document.getElementById('bans-add-player');

			if (!tableBody || !addBtn) return;

			function rowTemplate(index) {
				return `
					<tr class="bans-player-row">
						<td><input type="text" name="players[${index}][label]"></td>
						<td><input type="text" name="players[${index}][flashscore_slug]"></td>
						<td><input type="text" name="players[${index}][flashscore_id]"></td>
						<td><button type="button" class="button bans-remove-player">Remove</button></td>
					</tr>
				`;
			}

			function reindex() {
				const rows = tableBody.querySelectorAll('tr.bans-player-row');
				rows.forEach((row, i) => {
					row.querySelectorAll('input').forEach(input => {
						input.name = input.name.replace(/players\[\d+\]/, 'players[' + i + ']');
					});
				});
			}

			addBtn.addEventListener('click', () => {
				const index = tableBody.querySelectorAll('tr.bans-player-row').length;
				tableBody.insertAdjacentHTML('beforeend', rowTemplate(index));
			});

			tableBody.addEventListener('click', (e) => {
				if (e.target && e.target.classList.contains('bans-remove-player')) {
					const row = e.target.closest('tr');
					if (row) row.remove();
					reindex();
				}
			});
		})();
		</script>
		<?php
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

		$players = array();
		$posted  = $_POST['players'] ?? array();

		if ( is_array( $posted ) ) {
			foreach ( $posted as $row ) {
				$label = trim( (string) ( $row['label'] ?? '' ) );
				$slug  = trim( (string) ( $row['flashscore_slug'] ?? '' ) );
				$id    = trim( (string) ( $row['flashscore_id'] ?? '' ) );

				if ( '' === $label && '' === $slug && '' === $id ) {
					continue;
				}
				if ( '' === $slug || '' === $id ) {
					continue;
				}

				$players[] = array(
					'label'           => sanitize_text_field( $label ),
					'flashscore_slug' => sanitize_title( $slug ),
					'flashscore_id'   => sanitize_text_field( $id ),
				);
			}
		}

		return array(
			'players'     => $players,
			'emails'      => sanitize_textarea_field( $_POST['emails'] ?? '' ),
			'test_email'  => sanitize_email( $_POST['test_email'] ?? get_option( 'admin_email' ) ),
			'push_secret' => $current['push_secret'],
		);
	}
}
