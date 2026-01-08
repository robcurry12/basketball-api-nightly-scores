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

	private static function next_2am_eastern_timestamp() {
		$tz  = new DateTimeZone( 'America/New_York' );
		$now = new DateTimeImmutable( 'now', $tz );

		$target = $now->setTime( 2, 0, 0 );
		if ( $now >= $target ) {
			$target = $target->modify( '+1 day' );
		}

		return $target->setTimezone( new DateTimeZone( 'UTC' ) )->getTimestamp();
	}

	public static function run( $override_to = null ) {
		error_log( '[BANS] Cron run() fired at ' . gmdate( 'c' ) );

		if ( is_array( $override_to ) && ! empty( $override_to ) ) {
			$to = array_map( 'trim', $override_to );
		} else {
			$settings = get_option( 'bans_settings', array() );
			$to       = isset( $settings['recipients'] )
				? array_map( 'trim', explode( ',', $settings['recipients'] ) )
				: array( get_option( 'admin_email' ) );
		}

		self::run_with_override_email( $to );
	}

	private static function run_with_override_email( array $to ) {
		$settings = get_option( 'bans_settings', array() );
		$players  = isset( $settings['players'] ) && is_array( $settings['players'] ) ? $settings['players'] : array();

		if ( empty( $players ) ) {
			error_log( '[BANS] No players configured; aborting.' );
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
					'label'     => $label,
					'team_id'   => $team_id,
					'player_id' => $player_id,
					'errors'    => implode( ' | ', (array) $result['errors'] ),
				);
				continue;
			}

			$stats = isset( $result['stats'] ) && is_array( $result['stats'] ) ? $result['stats'] : array();

			$rebounds_total = self::pick_rebounds_total( $stats );

			$fg = self::pick_shooting_line( $stats, 'field_goals', 'fgm', 'fga' );
			$tp = self::pick_shooting_line( $stats, 'threepoint_goals', 'tpm', 'tpa' );
			$ft = self::pick_shooting_line( $stats, 'freethrows_goals', 'ftm', 'fta' );

			// NOTE: Removed steals/blocks/turnovers.
			$rows[] = array(
				'player_name' => isset( $result['player_name'] ) ? $result['player_name'] : '',
				'team_name'   => isset( $result['team_name'] ) ? $result['team_name'] : '',
				'game_id'     => isset( $result['game_id'] ) ? (int) $result['game_id'] : 0,

				'points'      => self::pick_stat( $stats, array( 'points', 'pts' ) ),
				'rebounds'    => $rebounds_total,
				'assists'     => self::pick_stat( $stats, array( 'assists', 'ast' ) ),
				'minutes'     => self::pick_stat( $stats, array( 'min', 'minutes' ) ),

				'fg'          => $fg['line'],
				'fg_pct'      => $fg['pct'],
				'3pt'         => $tp['line'],
				'3pt_pct'     => $tp['pct'],
				'ft'          => $ft['line'],
				'ft_pct'      => $ft['pct'],

				'fgm'         => $fg['made'],
				'fga'         => $fg['att'],
				'3pm'         => $tp['made'],
				'3pa'         => $tp['att'],
				'ftm'         => $ft['made'],
				'fta'         => $ft['att'],
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

		file_put_contents( $filepath, $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_file_put_contents

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		wp_mail( $to, $subject, $body, $headers, array( $filepath ) );
	}

	private static function pick_stat( $stats, $keys ) {
		foreach ( $keys as $k ) {
			if ( isset( $stats[ $k ] ) ) {
				return is_scalar( $stats[ $k ] ) ? (string) $stats[ $k ] : wp_json_encode( $stats[ $k ] );
			}
		}
		return '';
	}

	private static function pick_rebounds_total( $stats ) {
		if ( ! is_array( $stats ) ) {
			return '';
		}

		if ( isset( $stats['rebounds'] ) ) {
			if ( is_array( $stats['rebounds'] ) && isset( $stats['rebounds']['total'] ) ) {
				return is_scalar( $stats['rebounds']['total'] ) ? (string) $stats['rebounds']['total'] : '';
			}
			if ( is_scalar( $stats['rebounds'] ) ) {
				return (string) $stats['rebounds'];
			}
		}

		if ( isset( $stats['reb'] ) && is_scalar( $stats['reb'] ) ) {
			return (string) $stats['reb'];
		}

		return '';
	}

	private static function pick_shooting_line( $stats, $group_key, $made_key, $att_key ) {
		$made = '';
		$att  = '';
		$pct  = '';

		if ( isset( $stats[ $made_key ] ) && is_scalar( $stats[ $made_key ] ) ) {
			$made = (string) $stats[ $made_key ];
		}
		if ( isset( $stats[ $att_key ] ) && is_scalar( $stats[ $att_key ] ) ) {
			$att = (string) $stats[ $att_key ];
		}

		if ( isset( $stats[ $group_key ] ) && is_array( $stats[ $group_key ] ) ) {
			$g = $stats[ $group_key ];

			if ( '' === $made && isset( $g['total'] ) && is_scalar( $g['total'] ) ) {
				$made = (string) $g['total'];
			}
			if ( '' === $att && isset( $g['attempts'] ) && is_scalar( $g['attempts'] ) ) {
				$att = (string) $g['attempts'];
			}

			if ( isset( $g['percentage'] ) && is_scalar( $g['percentage'] ) && '' !== (string) $g['percentage'] ) {
				$pct = (string) $g['percentage'];
			}
		}

		$line = '';
		if ( '' !== $made && '' !== $att ) {
			$line = $made . '/' . $att;
		}

		if ( '' === $pct && '' !== $made && '' !== $att ) {
			$m = (float) $made;
			$a = (float) $att;
			if ( $a > 0 ) {
				$pct = (string) round( ( $m / $a ) * 100, 1 );
			}
		}

		if ( '' !== $pct ) {
			$pct = rtrim( $pct, '%' ) . '%';
		}

		return array(
			'made' => $made,
			'att'  => $att,
			'line' => $line,
			'pct'  => $pct,
		);
	}

	private static function build_csv( $rows, $errors ) {
		$fh = fopen( 'php://temp', 'r+' );

		// Human-readable headers (labels).
		fputcsv(
			$fh,
			array(
				'Player',
				'Team',
				'Game ID',
				'Points',
				'Rebounds',
				'Assists',
				'Minutes',

				'Field Goals',
				'Field Goal Percentage',
				'3 Points',
				'3 Point Percentage',
				'Free Throws',
				'Free Throw Percentage',

				'FGM',
				'FGA',
				'3 Points Made',
				'3 Points Attempt',
				'FTM',
				'FTA',
			)
		);

		foreach ( $rows as $r ) {
			// Ensure order matches the header labels above.
			fputcsv(
				$fh,
				array(
					$r['player_name'],
					$r['team_name'],
					$r['game_id'],
					$r['points'],
					$r['rebounds'],
					$r['assists'],
					$r['minutes'],

					$r['fg'],
					$r['fg_pct'],
					$r['3pt'],
					$r['3pt_pct'],
					$r['ft'],
					$r['ft_pct'],

					$r['fgm'],
					$r['fga'],
					$r['3pm'],
					$r['3pa'],
					$r['ftm'],
					$r['fta'],
				)
			);
		}

		if ( ! empty( $errors ) ) {
			fputcsv( $fh, array() );
			fputcsv( $fh, array( 'ERRORS' ) );
			fputcsv( $fh, array( 'Label', 'Team ID', 'Player ID', 'Error' ) );

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
