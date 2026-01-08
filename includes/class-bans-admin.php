<?php
defined( 'ABSPATH' ) || exit;

class BANS_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_save' ) );
	}

	public static function add_menu() {
		add_options_page(
			'Basketball API Nightly Scores',
			'Basketball Scores CSV',
			'manage_options',
			'bans-settings',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function get_settings() {
		$defaults = array(
			'api_key'     => '',
			'recipients'  => get_option( 'admin_email' ),
			'players'     => array(), // repeater rows.
		);

		$saved = get_option( 'bans_settings', array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = wp_parse_args( $saved, $defaults );

		if ( ! is_array( $settings['players'] ) ) {
			$settings['players'] = array();
		}

		return $settings;
	}

	public static function handle_save() {
        if (
            isset( $_POST['bans_action'] ) &&
            'send_now' === $_POST['bans_action']
        ) {
            check_admin_referer( 'bans_save_settings', 'bans_nonce' );
        
            $email = isset( $_POST['manual_email'] )
                ? sanitize_email( wp_unslash( $_POST['manual_email'] ) )
                : '';
        
            if ( ! is_email( $email ) ) {
                add_settings_error(
                    'bans_messages',
                    'bans_email_invalid',
                    'Please enter a valid email address.',
                    'error'
                );
                return;
            }
        
            BANS_Cron::run_manual( $email );
        
            add_settings_error(
                'bans_messages',
                'bans_queued_now',
                'CSV email queued. It will be sent shortly.',
                'updated'
            );
        
            return;
        }
        
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $_POST['bans_action'] ) || 'save' !== $_POST['bans_action'] ) {
			return;
		}

		check_admin_referer( 'bans_save_settings', 'bans_nonce' );

		$api_key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$recipients = isset( $_POST['recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['recipients'] ) ) : '';

		$players_in = isset( $_POST['players'] ) && is_array( $_POST['players'] ) ? $_POST['players'] : array();
		$players    = array();

		foreach ( $players_in as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$label     = isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '';
			$team_id   = isset( $row['team_id'] ) ? (int) $row['team_id'] : 0;
			$player_id = isset( $row['player_id'] ) ? (int) $row['player_id'] : 0;

			// Skip empty rows.
			if ( 0 === $team_id && 0 === $player_id && '' === $label ) {
				continue;
			}

			$players[] = array(
				'label'     => $label,
				'team_id'   => $team_id,
				'player_id' => $player_id,
			);
		}

		update_option(
			'bans_settings',
			array(
				'api_key'    => $api_key,
				'recipients' => $recipients,
				'players'    => $players,
			),
			false
		);

		// Reschedule cron in case WP timezone/settings changed.
		BANS_Cron::reschedule();

		add_settings_error( 'bans_messages', 'bans_saved', 'Settings saved.', 'updated' );
	}

	public static function render_page() {
		$settings = self::get_settings();
		$players  = $settings['players'];

		settings_errors( 'bans_messages' );
		?>
		<div class="wrap">
			<h1>Basketball API Nightly Scores</h1>

			<form method="post" action="">
				<?php wp_nonce_field( 'bans_save_settings', 'bans_nonce' ); ?>
				<input type="hidden" name="bans_action" value="save" />

				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><label for="api_key">API Key</label></th>
							<td>
								<input name="api_key" id="api_key" type="text" class="regular-text"
									value="<?php echo esc_attr( $settings['api_key'] ); ?>" />
								<p class="description">Used as <code>x-apisports-key</code> header.</p>
							</td>
						</tr>

						<tr>
							<th scope="row"><label for="recipients">Email Recipients</label></th>
							<td>
								<input name="recipients" id="recipients" type="text" class="regular-text"
									value="<?php echo esc_attr( $settings['recipients'] ); ?>" />
								<p class="description">Comma-separated emails (e.g. you@site.com, boss@site.com)</p>
							</td>
						</tr>
                        <tr>
                            <th scope="row">Send CSV Now</th>
                            <td>
                                <input type="email"
                                    name="manual_email"
                                    placeholder="someone@example.com"
                                    class="regular-text" />

                                <button type="submit"
                                    name="bans_action"
                                    value="send_now"
                                    class="button button-secondary"
                                    style="margin-left:8px;">
                                    Send Now
                                </button>

                                <p class="description">
                                    Send the CSV immediately to a one-off email address (does not save).
                                </p>
                            </td>
                        </tr>

					</tbody>
				</table>

				<hr />

				<h2>Players (Team ID + Player ID)</h2>
				<p class="description">Each row is used by the nightly cron. The Label is for admin reference only.</p>

				<table class="widefat striped" id="bans-repeater">
					<thead>
						<tr>
							<th style="width:40%;">Label (admin-only)</th>
							<th style="width:20%;">Team ID</th>
							<th style="width:20%;">Player ID</th>
							<th style="width:20%;">Actions</th>
						</tr>
					</thead>
					<tbody>
						<?php
						if ( empty( $players ) ) {
							$players = array(
								array(
									'label'     => '',
									'team_id'   => '',
									'player_id' => '',
								),
							);
						}

						foreach ( $players as $i => $row ) :
							?>
							<tr class="bans-row">
								<td>
									<input type="text" class="widefat"
										name="players[<?php echo esc_attr( $i ); ?>][label]"
										value="<?php echo esc_attr( $row['label'] ); ?>" />
								</td>
								<td>
									<input type="number" min="0" class="widefat"
										name="players[<?php echo esc_attr( $i ); ?>][team_id]"
										value="<?php echo esc_attr( $row['team_id'] ); ?>" />
								</td>
								<td>
									<input type="number" min="0" class="widefat"
										name="players[<?php echo esc_attr( $i ); ?>][player_id]"
										value="<?php echo esc_attr( $row['player_id'] ); ?>" />
								</td>
								<td>
									<button type="button" class="button bans-delete-row">Delete</button>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p style="margin-top:12px;">
					<button type="button" class="button button-secondary" id="bans-add-row">Add Row</button>
				</p>

				<?php submit_button( 'Save Settings' ); ?>
			</form>
		</div>

		<script>
		(function() {
			const table = document.getElementById('bans-repeater');
			const tbody = table.querySelector('tbody');
			const addBtn = document.getElementById('bans-add-row');

			function renumber() {
				const rows = tbody.querySelectorAll('tr.bans-row');
				rows.forEach((tr, index) => {
					tr.querySelectorAll('input').forEach((input) => {
						input.name = input.name.replace(/players\[\d+\]/, 'players[' + index + ']');
					});
				});
			}

			tbody.addEventListener('click', function(e) {
				if (!e.target.classList.contains('bans-delete-row')) return;
				const row = e.target.closest('tr.bans-row');
				if (row) row.remove();

				// Ensure at least one row remains.
				if (tbody.querySelectorAll('tr.bans-row').length === 0) {
					addRow();
				}

				renumber();
			});

			function addRow() {
				const index = tbody.querySelectorAll('tr.bans-row').length;
				const tr = document.createElement('tr');
				tr.className = 'bans-row';
				tr.innerHTML = `
					<td><input type="text" class="widefat" name="players[${index}][label]" value="" /></td>
					<td><input type="number" min="0" class="widefat" name="players[${index}][team_id]" value="" /></td>
					<td><input type="number" min="0" class="widefat" name="players[${index}][player_id]" value="" /></td>
					<td><button type="button" class="button bans-delete-row">Delete</button></td>
				`;
				tbody.appendChild(tr);
			}

			addBtn.addEventListener('click', function() {
				addRow();
				renumber();
			});
		})();
		</script>
		<?php
	}
}
