<?php
defined( 'ABSPATH' ) || exit;

class BANS_Admin {

	const OPTION_KEY = 'bans_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_bans_send_test', array( __CLASS__, 'handle_send_test' ) );
		add_action( 'admin_post_bans_sync_players', array( __CLASS__, 'handle_sync_players' ) );
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
			'emails'          => '',
			'test_email'      => get_option( 'admin_email' ),
			'gh_token'        => '',
			'gh_owner'        => 'robcurry12',
			'gh_repo'         => 'basketball-api-nightly-scores',
			'gh_branch'       => 'main',
			'gh_players_path' => 'data/players.json',
			'gh_results_path' => 'data/latest.json',
		);

		return wp_parse_args( get_option( self::OPTION_KEY, array() ), $defaults );
	}

	/**
	 * Stash a one-time admin notice (survives the post/redirect cycle).
	 */
	private static function add_notice( $type, $text ) {
		set_transient( 'bans_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 60 );
	}

	private static function print_notice() {
		$key    = 'bans_notice_' . get_current_user_id();
		$notice = get_transient( $key );
		if ( ! $notice ) {
			return;
		}
		delete_transient( $key );

		$class = 'error' === $notice['type'] ? 'notice notice-error'
			: ( 'warning' === $notice['type'] ? 'notice notice-warning' : 'updated' );

		echo '<div class="' . esc_attr( $class ) . '"><p>' . esc_html( $notice['text'] ) . '</p></div>';
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

	/**
	 * Send a test email using the latest results pulled from GitHub.
	 */
	public static function handle_send_test() {
		check_admin_referer( 'bans_send_test' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$settings = self::get_settings();
		$rows     = BANS_Cron::get_result_rows( $settings );

		if ( empty( $rows ) ) {
			self::add_notice( 'warning', 'No results found in the GitHub results file yet. Run the scraper (GitHub Actions) once so it commits data/latest.json.' );
			self::redirect_back();
		}

		$sent = BANS_Cron::send_email_with_csv( $settings, $rows, true );

		if ( $sent ) {
			self::add_notice( 'updated', 'Test email sent successfully (using the latest results from GitHub). Check your inbox.' );
		} else {
			self::add_notice( 'error', 'Test email failed to send. Check debug.log for details.' );
		}
		self::redirect_back();
	}

	/**
	 * Push the current crawl list to GitHub on demand.
	 */
	public static function handle_sync_players() {
		check_admin_referer( 'bans_sync_players' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.' );
		}

		$result = BANS_GitHub::push_players( self::get_settings() );
		self::add_notice( $result['ok'] ? 'updated' : 'error', $result['message'] );
		self::redirect_back();
	}

	private static function redirect_back() {
		wp_safe_redirect( admin_url( 'options-general.php?page=bans-settings' ) );
		exit;
	}

	public static function render_page() {
		if ( isset( $_POST['save_bans'] ) ) {
			check_admin_referer( 'bans_save' );
			self::save_players();
			update_option( self::OPTION_KEY, self::sanitize_settings(), false );

			// Keep the repo's players.json in sync with what was just saved.
			$sync = BANS_GitHub::push_players( self::get_settings() );
			if ( $sync['ok'] ) {
				self::add_notice( 'updated', 'Settings saved. ' . $sync['message'] );
			} else {
				self::add_notice( 'warning', 'Settings saved, but the GitHub sync did not run: ' . $sync['message'] );
			}
			self::redirect_back();
		}

		self::print_notice();

		$settings = self::get_settings();
		$players  = BANS_Players::get_all_players();
		$new_url  = admin_url( 'post-new.php?post_type=' . BANS_PLAYER_POST_TYPE );
		$has_token = '' !== trim( (string) $settings['gh_token'] );

		?>
		<style>
			#bans-players-table th,
			#bans-players-table td { padding: 8px 10px; vertical-align: middle; }
			#bans-players-table th:nth-child(1) { width: 60px; text-align: center; }
			#bans-players-table td:nth-child(1) { text-align: center; }
			.bans-missing { color: #b32d2e; font-weight: 600; }
			.bans-ok { color: #1a7f37; }
			.bans-fields { color: #646970; font-size: 12px; }
			.bans-edit-fields { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
			.bans-edit-fields label { font-size: 12px; color: #646970; display: block; }
			.bans-edit-fields input { height: 30px; }
			.bans-check-all-label { font-weight: 400; font-size: 11px; display: block; }
			.bans-gh-grid { display: grid; grid-template-columns: 160px 1fr; gap: 10px 14px; max-width: 720px; align-items: center; }
			.bans-gh-grid input { width: 100%; }
		</style>

		<div class="wrap">
			<h1>Basketball Nightly Scores</h1>

			<p style="max-width: 900px;">
				WordPress syncs the crawl list to <code><?php echo esc_html( $settings['gh_players_path'] ); ?></code>
				in your GitHub repo. GitHub Actions scrapes nightly and commits results to
				<code><?php echo esc_html( $settings['gh_results_path'] ); ?></code>, which WordPress pulls in
				to send the nightly email. No inbound requests hit this site.
			</p>

			<form method="post">
				<?php wp_nonce_field( 'bans_save' ); ?>

				<h2>Players in the Nightly Crawl</h2>
				<p style="max-width: 900px;">
					Tick each player you want scanned nightly, and set their Flashscore
					slug and ID inline. A player with missing fields can't be scanned
					even if ticked. (These same fields also appear on each
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . BANS_PLAYER_POST_TYPE ) ); ?>">Player</a>
					post.) Saving syncs the list to GitHub.
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
								<th>
									<input type="checkbox" id="bans-check-all">
									<span class="bans-check-all-label">All</span>
								</th>
								<th>Player</th>
								<th>Flashscore Fields</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $players as $p ) : ?>
								<tr>
									<td>
										<input type="checkbox"
											class="bans-include-cb"
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
										<span class="bans-status" data-player="<?php echo (int) $p['id']; ?>">
											<?php if ( $p['scannable'] ) : ?>
												<span class="bans-ok">&#10003; Ready</span>
												<span class="bans-fields">
													(<?php echo esc_html( $p['flashscore_slug'] ); ?> /
													<?php echo esc_html( $p['flashscore_id'] ); ?>)
												</span>
											<?php else : ?>
												<span class="bans-missing">Missing slug/ID</span>
											<?php endif; ?>
											&mdash;
											<a href="#" class="bans-edit-toggle" data-player="<?php echo (int) $p['id']; ?>">edit</a>
										</span>
										<div class="bans-edit-fields" data-player="<?php echo (int) $p['id']; ?>" hidden>
											<span>
												<label>Flashscore Slug</label>
												<input type="text"
													name="bans_slug[<?php echo (int) $p['id']; ?>]"
													value="<?php echo esc_attr( $p['flashscore_slug'] ); ?>"
													placeholder="e.g. paolo-banchero">
											</span>
											<span>
												<label>Flashscore ID</label>
												<input type="text"
													name="bans_id[<?php echo (int) $p['id']; ?>]"
													value="<?php echo esc_attr( $p['flashscore_id'] ); ?>"
													placeholder="e.g. AbCdEf12">
											</span>
										</div>
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

				<h2>GitHub Repository</h2>
				<div class="bans-gh-grid">
					<label for="bans_gh_owner"><strong>Owner</strong></label>
					<input type="text" id="bans_gh_owner" name="gh_owner" value="<?php echo esc_attr( $settings['gh_owner'] ); ?>" placeholder="robcurry12">

					<label for="bans_gh_repo"><strong>Repository</strong></label>
					<input type="text" id="bans_gh_repo" name="gh_repo" value="<?php echo esc_attr( $settings['gh_repo'] ); ?>" placeholder="basketball-api-nightly-scores">

					<label for="bans_gh_branch"><strong>Branch</strong></label>
					<input type="text" id="bans_gh_branch" name="gh_branch" value="<?php echo esc_attr( $settings['gh_branch'] ); ?>" placeholder="main">

					<label for="bans_gh_players_path"><strong>Players file</strong></label>
					<input type="text" id="bans_gh_players_path" name="gh_players_path" value="<?php echo esc_attr( $settings['gh_players_path'] ); ?>" placeholder="data/players.json">

					<label for="bans_gh_results_path"><strong>Results file</strong></label>
					<input type="text" id="bans_gh_results_path" name="gh_results_path" value="<?php echo esc_attr( $settings['gh_results_path'] ); ?>" placeholder="data/latest.json">

					<label for="bans_gh_token"><strong>Access Token</strong></label>
					<input type="password" id="bans_gh_token" name="gh_token" value="" autocomplete="new-password"
						placeholder="<?php echo esc_attr( $has_token ? 'A token is saved - leave blank to keep it' : 'Fine-grained PAT with Contents: Read and write' ); ?>">
				</div>
				<p class="description" style="max-width:720px;">
					The token is used only for the outbound write of <code><?php echo esc_html( $settings['gh_players_path'] ); ?></code>.
					Use a fine-grained personal access token scoped to this one repository with
					<strong>Contents: Read and write</strong>. Leave the field blank to keep the saved token.
				</p>

				<p>
					<button class="button-primary" name="save_bans" value="1">Save Settings &amp; Sync</button>
				</p>
			</form>

			<h2>Actions</h2>
			<p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'bans_sync_players' ); ?>
					<input type="hidden" name="action" value="bans_sync_players">
					<button class="button">Sync Players to GitHub Now</button>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline;">
					<?php wp_nonce_field( 'bans_send_test' ); ?>
					<input type="hidden" name="action" value="bans_send_test">
					<button class="button">Send Test Email (Pull Latest from GitHub)</button>
				</form>
			</p>
		</div>

		<script>
		(function () {
			// Select / deselect all include checkboxes.
			const checkAll = document.getElementById('bans-check-all');
			const boxes = Array.prototype.slice.call(
				document.querySelectorAll('.bans-include-cb')
			);

			function syncCheckAll() {
				if (!checkAll || !boxes.length) return;
				const checked = boxes.filter(b => b.checked).length;
				checkAll.checked = checked === boxes.length;
				checkAll.indeterminate = checked > 0 && checked < boxes.length;
			}

			if (checkAll) {
				checkAll.addEventListener('change', () => {
					boxes.forEach(b => { b.checked = checkAll.checked; });
				});
			}
			boxes.forEach(b => b.addEventListener('change', syncCheckAll));
			syncCheckAll();

			// Toggle the inline slug / ID inputs for a player.
			document.querySelectorAll('.bans-edit-toggle').forEach(link => {
				link.addEventListener('click', (e) => {
					e.preventDefault();
					const id = link.getAttribute('data-player');
					const editor = document.querySelector(
						'.bans-edit-fields[data-player="' + id + '"]'
					);
					if (!editor) return;
					editor.hidden = !editor.hidden;
					if (!editor.hidden) {
						const first = editor.querySelector('input');
						if (first) first.focus();
					}
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Persist per-player crawl settings from the admin table: the "include in
	 * crawl" checkboxes plus the inline Flashscore slug / ID fields. Any
	 * published player not checked is set to excluded.
	 */
	private static function save_players() {
		$checked = isset( $_POST['bans_include'] ) && is_array( $_POST['bans_include'] )
			? array_map( 'intval', $_POST['bans_include'] )
			: array();
		$checked = array_flip( $checked );

		$slugs = isset( $_POST['bans_slug'] ) && is_array( $_POST['bans_slug'] ) ? $_POST['bans_slug'] : array();
		$ids   = isset( $_POST['bans_id'] ) && is_array( $_POST['bans_id'] ) ? $_POST['bans_id'] : array();

		foreach ( BANS_Players::get_all_players() as $p ) {
			$id = $p['id'];

			$include = isset( $checked[ $id ] ) ? '1' : '';
			update_post_meta( $id, BANS_META_INCLUDE, $include );

			// Only touch the fields when the inputs were actually submitted for
			// this player, so we never blank out meta for a row not on screen.
			if ( array_key_exists( $id, $slugs ) ) {
				$slug = sanitize_text_field( wp_unslash( $slugs[ $id ] ) );
				update_post_meta( $id, BANS_META_SLUG, '' !== $slug ? sanitize_title( $slug ) : '' );
			}
			if ( array_key_exists( $id, $ids ) ) {
				$fid = sanitize_text_field( wp_unslash( $ids[ $id ] ) );
				update_post_meta( $id, BANS_META_ID, $fid );
			}
		}
	}

	private static function sanitize_settings() {
		$current = self::get_settings();

		// Keep the saved token unless a new one was entered.
		$posted_token = isset( $_POST['gh_token'] ) ? trim( (string) wp_unslash( $_POST['gh_token'] ) ) : '';
		$token        = '' !== $posted_token ? sanitize_text_field( $posted_token ) : $current['gh_token'];

		return array(
			'emails'          => sanitize_textarea_field( $_POST['emails'] ?? '' ),
			'test_email'      => sanitize_email( $_POST['test_email'] ?? get_option( 'admin_email' ) ),
			'gh_token'        => $token,
			'gh_owner'        => sanitize_text_field( $_POST['gh_owner'] ?? '' ),
			'gh_repo'         => sanitize_text_field( $_POST['gh_repo'] ?? '' ),
			'gh_branch'       => sanitize_text_field( $_POST['gh_branch'] ?? 'main' ),
			'gh_players_path' => sanitize_text_field( $_POST['gh_players_path'] ?? 'data/players.json' ),
			'gh_results_path' => sanitize_text_field( $_POST['gh_results_path'] ?? 'data/latest.json' ),
		);
	}
}
