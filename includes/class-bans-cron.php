<?php
defined( 'ABSPATH' ) || exit;

class BANS_Cron {

	const HOOK = 'bans_nightly_job';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
	}

	public static function activate() {
		self::schedule();
	}

	public static function deactivate() {
		self::unschedule();
	}

	public static function reschedule() {
		self::unschedule();
		self::schedule();
	}

    public static function run_manual( $email ) {
		// Queue as an immediate single WP-Cron event to avoid admin request timeouts.
		wp_schedule_single_event( time() + 1, self::HOOK, array( array( $email ) ) );
    }
    

	private static function schedule() {
		if ( wp_next_scheduled( self::HOOK ) ) {
			return;
		}

		$timestamp = self::next_2am_eastern_timestamp();
		wp_schedule_event( $timestamp, 'daily', self::HOOK );
	}

	private static function unschedule() {
		$timestamp = wp_next_scheduled( self::HOOK );
		while ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::HOOK );
			$timestamp = wp_next_scheduled( self::HOOK );
		}
	}

	/**
	 * Next 2:00 AM in America/New_York, returned as UTC timestamp for WP-Cron.
	 */
	private static function next_2am_eastern_timestamp() {
		$tz = new DateTimeZone( 'America/New_York' );
		$now = new DateTimeImmutable( 'now', $tz );

		$target = $now->setTime( 2, 0, 0 );
		if ( $now >= $target ) {
			$target = $target->modify( '+1 day' );
		}

		// Convert to UTC timestamp.
		return $target->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
	}

    public static function run( $override_to = null ) {
		error_log('[BANS] Cron run() fired at ' . gmdate('c'));

		$to = null;

		if ( is_array( $override_to ) && ! empty( $override_to ) ) {
			$to = array_map( 'trim', $override_to );
		} else {
			$settings = get_option( 'bans_settings', array() );
			$to = isset( $settings['recipients'] )
				? array_map( 'trim', explode( ',', $settings['recipients'] ) )
				: array( get_option( 'admin_email' ) );
		}
    
        self::run_with_override_email( $to );
    }
    

	private static function run_with_override_email(array $to) {
		$settings = get_option( 'bans_settings', array() );
		$players  = isset( $settings['players'] ) && is_array( $settings['players'] ) ? $settings['players'] : array();

		if ( empty( $players ) ) {
			return;
		}

		$rows   = array();
		$errors = array();

		foreach ( $players as $row ) {
			$team_id   = isset( $row['team_id'] ) ? (int) $row['team_id'] : 0;
			$player_id = isset( $row['player_id'] ) ? (int) $row['player_id'] : 0;
			$label     = isset( $row['label'] ) ? (string) $row['label'] : '';

			if ( $team_id <= 0 || $player_id <= 0 ) {
				continue;
			}

			$result = BANS_API::get_player_most_recent_stats( $team_id, $player_id );

			if ( ! empty( $result['errors'] ) ) {
				$errors[] = array(
					'label'      => $label,
					'team_id'    => $team_id,
					'player_id'  => $player_id,
					'errors'     => implode( ' | ', (array) $result['errors'] ),
				);
				continue;
			}

			$stats = isset( $result['stats'] ) && is_array( $result['stats'] ) ? $result['stats'] : array();

			// Normalize some common stat keys (best effort).
			$rows[] = array(
				'player_name' => isset( $result['player_name'] ) ? $result['player_name'] : '',
				'team_name'   => isset( $result['team_name'] ) ? $result['team_name'] : '',
				'game_id'     => isset( $result['game_id'] ) ? (int) $result['game_id'] : 0,

				'points'      => self::pick_stat( $stats, array( 'points', 'pts' ) ),
				'rebounds'    => self::pick_stat( $stats, array( 'rebounds', 'reb' ) ),
				'assists'     => self::pick_stat( $stats, array( 'assists', 'ast' ) ),
				'steals'      => self::pick_stat( $stats, array( 'steals', 'stl' ) ),
				'blocks'      => self::pick_stat( $stats, array( 'blocks', 'blk' ) ),
				'turnovers'   => self::pick_stat( $stats, array( 'turnovers', 'tov' ) ),
				'minutes'     => self::pick_stat( $stats, array( 'min', 'minutes' ) ),

				'fgm'         => self::pick_stat( $stats, array( 'fgm' ) ),
				'fga'         => self::pick_stat( $stats, array( 'fga' ) ),
				'ftm'         => self::pick_stat( $stats, array( 'ftm' ) ),
				'fta'         => self::pick_stat( $stats, array( 'fta' ) ),
				'tpm'         => self::pick_stat( $stats, array( 'tpm', '3pm' ) ),
				'tpa'         => self::pick_stat( $stats, array( 'tpa', '3pa' ) ),
			);
		}

		if ( empty( $rows ) && empty( $errors ) ) {
			return;
		}

		$csv = self::build_csv( $rows, $errors );

		$subject = 'Nightly Player Scores (CSV)';
		$body    = "Attached is the nightly CSV.\n\n";

		if ( ! empty( $errors ) ) {
			$body .= "Some rows had errors. See the 'Errors' section in the CSV.\n";
		}

		$upload_dir = wp_upload_dir();
		$filename   = 'player-scores-' . gmdate( 'Y-m-d' ) . '.csv';
		$filepath   = trailingslashit( $upload_dir['basedir'] ) . $filename;

		// Write CSV to uploads for attachment.
		file_put_contents( $filepath, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

        $sent = wp_mail( $to, $subject, $body, $headers, array( $filepath ) );
        error_log('[BANS] wp_mail sent (with attachment): ' . ( $sent ? 'true' : 'false' ));


		// Optional cleanup: keep last N days instead of deleting immediately.
		// unlink( $filepath );
	}

	private static function pick_stat( $stats, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $stats[ $k ] ) ) {
				return is_scalar( $stats[ $k ] ) ? (string) $stats[ $k ] : wp_json_encode( $stats[ $k ] );
			}
		}
		return '';
	}

	private static function build_csv( $rows, $errors ) {
		$fh = fopen( 'php://temp', 'r+' );

		// Main report.
		fputcsv(
			$fh,
			array(
				'player_name',
				'team_name',
				'game_id',
				'points',
				'rebounds',
				'assists',
				'steals',
				'blocks',
				'turnovers',
				'minutes',
				'fgm',
				'fga',
				'ftm',
				'fta',
				'tpm',
				'tpa',
			)
		);

		foreach ( $rows as $r ) {
			fputcsv( $fh, $r );
		}

		// Errors section.
		if ( ! empty( $errors ) ) {
			fputcsv( $fh, array() );
			fputcsv( $fh, array( 'ERRORS' ) );
			fputcsv( $fh, array( 'label', 'team_id', 'player_id', 'error' ) );

			foreach ( $errors as $e ) {
				fputcsv(
					$fh,
					array(
						$e['label'],
						$e['team_id'],
						$e['player_id'],
						$e['errors'],
					)
				);
			}
		}

		rewind( $fh );
		$csv = stream_get_contents( $fh );
		fclose( $fh );

		return $csv;
	}
}
