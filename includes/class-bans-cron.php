<?php
defined( 'ABSPATH' ) || exit;

/**
 * Nightly email sender.
 *
 * Scraping happens externally (GitHub Actions) and is committed to the repo as
 * data/latest.json. This cron pulls that file from GitHub (BANS_GitHub),
 * formats the rows, and emails them (plus a CSV attachment) once per night.
 */
class BANS_Cron {

	public static function init() {
		add_action( 'bans_nightly_event', array( __CLASS__, 'nightly' ) );

		if ( ! wp_next_scheduled( 'bans_nightly_event' ) ) {
			wp_schedule_event( strtotime( '02:00 tomorrow' ), 'daily', 'bans_nightly_event' );
		}
	}

	public static function nightly() {
		$settings = BANS_Admin::get_settings();
		$rows     = self::get_result_rows( $settings );

		if ( empty( $rows ) ) {
			error_log( '[BANS] DAILY: No result rows available from GitHub. Email not sent.' );
			return;
		}

		self::send_email_with_csv( $settings, $rows, false );
	}

	/**
	 * Pull the latest results from GitHub and return formatted, labelled rows.
	 *
	 * @return array[] Labelled rows ready for the email/CSV (may be empty).
	 */
	public static function get_result_rows( $settings ) {
		$result = BANS_GitHub::fetch_results( $settings );

		if ( empty( $result['ok'] ) ) {
			return array();
		}

		$raw = isset( $result['data']['rows'] ) && is_array( $result['data']['rows'] )
			? $result['data']['rows']
			: array();

		return self::format_rows( $raw );
	}

	/**
	 * Convert the scraper's raw rows into labelled, cleaned rows for output.
	 */
	private static function format_rows( $rows ) {
		$out = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$player = sanitize_text_field( $row['player'] ?? '' );
			if ( '' === $player ) {
				continue;
			}

			$out[] = array(
				'Player'    => $player,
				'Game Date' => self::format_date_mdy( $row['game_date'] ?? '' ),
				'Game URL'  => self::clean_game_url( $row['game_url'] ?? '' ),
				'Minutes'   => sanitize_text_field( $row['minutes'] ?? '' ),
				'Points'    => (int) ( $row['points'] ?? 0 ),
				'Rebounds'  => (int) ( $row['rebounds'] ?? 0 ),
				'Assists'   => (int) ( $row['assists'] ?? 0 ),
				'Steals'    => (int) ( $row['steals'] ?? 0 ),
				'Turnovers' => (int) ( $row['turnovers'] ?? 0 ),
			);
		}

		return $out;
	}

	private static function clean_game_url( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $url;
		}

		// Strip query + fragment, keep scheme/host/path.
		return $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
	}

	private static function format_date_mdy( $iso_date ) {
		$iso_date = (string) $iso_date;

		$dt = DateTime::createFromFormat( 'Y-m-d', $iso_date );
		if ( $dt instanceof DateTime ) {
			return $dt->format( 'm/d/Y' );
		}

		return sanitize_text_field( $iso_date );
	}

	public static function send_email_with_csv( $settings, $rows, $is_test = false ) {
		$type = $is_test ? 'TEST' : 'DAILY';

		error_log( "[BANS] Starting {$type} email send..." );

		$recipients = array();

		if ( $is_test ) {
			if ( ! empty( $settings['test_email'] ) ) {
				$recipients[] = trim( (string) $settings['test_email'] );
			}
		} else {
			if ( ! empty( $settings['emails'] ) ) {
				$recipients = array_map( 'trim', explode( ',', (string) $settings['emails'] ) );
			}
		}

		$recipients = array_values( array_filter( $recipients ) );

		if ( empty( $recipients ) ) {
			error_log( "[BANS] {$type}: No recipients configured. Email not sent." );
			return false;
		}

		error_log( "[BANS] {$type}: Recipients: " . implode( ', ', $recipients ) );

		if ( empty( $rows ) || ! is_array( $rows ) ) {
			error_log( "[BANS] {$type}: No rows provided. Email not sent." );
			return false;
		}

		error_log( "[BANS] {$type}: Processing " . count( $rows ) . " row(s)..." );

		$csv_path = self::generate_csv( $rows );

		if ( ! file_exists( $csv_path ) ) {
			error_log( "[BANS] {$type}: Failed to generate CSV at {$csv_path}." );
			return false;
		}

		error_log( "[BANS] {$type}: CSV generated at {$csv_path}" );

		$subject = $is_test ? 'Basketball Stats (Test)' : 'Basketball Nightly Stats';
		$body    = self::format_email( $rows );

		$sent = wp_mail(
			$recipients,
			$subject,
			$body,
			array( 'Content-Type: text/plain; charset=UTF-8' ),
			array( $csv_path )
		);

		@unlink( $csv_path );

		if ( $sent ) {
			error_log( "[BANS] {$type}: wp_mail() returned TRUE - email handed off to mail system." );
		} else {
			error_log( "[BANS] {$type}: wp_mail() returned FALSE - email failed to send." );
		}

		return (bool) $sent;
	}

	private static function generate_csv( $rows ) {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . 'bans';

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$filename = 'bans-stats-' . gmdate( 'Y-m-d' ) . '.csv';
		$path     = trailingslashit( $dir ) . $filename;

		$fh = fopen( $path, 'w' );

		// Header
		fputcsv( $fh, array_keys( $rows[0] ) );

		foreach ( $rows as $row ) {
			fputcsv( $fh, $row );
		}

		fclose( $fh );

		return $path;
	}

	private static function format_email( $rows ) {
		$lines = array();

		foreach ( $rows as $row ) {
			// Keep email concise; URLs are now cleaned and dates formatted.
			$line = array();
			foreach ( $row as $k => $v ) {
				$line[] = "{$k}: {$v}";
			}
			$lines[] = implode( ' | ', $line );
		}

		return implode( "\n\n", $lines ) . "\n\n(See attached CSV.)";
	}
}
