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
		$result   = BANS_GitHub::fetch_results( $settings );

		// A failed fetch means the pipeline is broken (scraper hasn't committed
		// yet, GitHub unreachable, bad JSON). Stay silent so a broken scrape is
		// obvious by the *absence* of any email — don't send a false "all clear".
		if ( empty( $result['ok'] ) ) {
			$msg = isset( $result['message'] ) ? $result['message'] : 'unknown error';
			error_log( '[BANS] DAILY: Results fetch failed (' . $msg . '). No email sent.' );
			return;
		}

		$raw  = isset( $result['data']['rows'] ) && is_array( $result['data']['rows'] )
			? $result['data']['rows']
			: array();
		$rows = self::format_rows( $raw );

		// Fetch succeeded but no tracked player had a game in the scraper's
		// window (off-season / no fixtures). Send a short heartbeat so a quiet
		// night is distinguishable from a broken scrape.
		if ( empty( $rows ) ) {
			error_log( '[BANS] DAILY: Fetch OK but 0 result rows. Sending off-season heartbeat.' );
			self::send_heartbeat_email( $settings, $result['data'] );
			return;
		}

		self::send_email_with_csv( $settings, $rows, false );
	}

	/**
	 * Run the "Send Test Email" flow. Mirrors nightly(): a failed fetch is an
	 * error, a successful fetch with no rows sends the off-season heartbeat
	 * (so a quiet night still confirms email delivery), and rows send the
	 * stats email + CSV. All sends target the test recipient.
	 *
	 * @return array{status:string,message:string} status one of
	 *         error|heartbeat|heartbeat_failed|sent|send_failed.
	 */
	public static function send_test( $settings ) {
		$result = BANS_GitHub::fetch_results( $settings );

		if ( empty( $result['ok'] ) ) {
			$msg = isset( $result['message'] ) ? $result['message'] : 'unknown error';
			return array( 'status' => 'error', 'message' => $msg );
		}

		$raw  = isset( $result['data']['rows'] ) && is_array( $result['data']['rows'] )
			? $result['data']['rows']
			: array();
		$rows = self::format_rows( $raw );

		if ( empty( $rows ) ) {
			$sent = self::send_heartbeat_email( $settings, $result['data'], true );
			return array(
				'status'  => $sent ? 'heartbeat' : 'heartbeat_failed',
				'message' => '',
			);
		}

		$sent = self::send_email_with_csv( $settings, $rows, true );
		return array(
			'status'  => $sent ? 'sent' : 'send_failed',
			'message' => '',
		);
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

	/**
	 * Resolve the recipient list: the test address for test sends, otherwise
	 * the comma-separated nightly list.
	 *
	 * @return string[] Trimmed, non-empty recipient addresses.
	 */
	private static function resolve_recipients( $settings, $is_test ) {
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

		return array_values( array_filter( $recipients ) );
	}

	/**
	 * Send a short "no games today" heartbeat when the scrape succeeded but had
	 * no rows, so a quiet night reads differently from a broken pipeline.
	 */
	public static function send_heartbeat_email( $settings, $data = array(), $is_test = false ) {
		$type = $is_test ? 'TEST' : 'DAILY';

		error_log( "[BANS] Starting {$type} heartbeat email (no games to report)..." );

		$recipients = self::resolve_recipients( $settings, $is_test );

		if ( empty( $recipients ) ) {
			error_log( "[BANS] {$type} heartbeat: No recipients configured. Email not sent." );
			return false;
		}

		$scraped_at = isset( $data['generated_at_utc'] ) && '' !== $data['generated_at_utc']
			? sanitize_text_field( (string) $data['generated_at_utc'] )
			: '';

		$body  = "No tracked players had a game in the last day, so there are no stats to report tonight.\n\n";
		$body .= '' !== $scraped_at
			? "The scraper ran successfully (results generated {$scraped_at}) — this is a quiet night, likely off-season or no fixtures, not an error.\n\n"
			: "The scraper ran but produced no results for the last day — likely off-season or no fixtures, not an error.\n\n";
		$body .= "You'll get the usual stats email automatically once games resume.";

		$subject = $is_test
			? 'Basketball Nightly Stats — no games today (Test)'
			: 'Basketball Nightly Stats — no games today';

		$sent = wp_mail(
			$recipients,
			$subject,
			$body,
			array( 'Content-Type: text/plain; charset=UTF-8' )
		);

		if ( $sent ) {
			error_log( "[BANS] {$type} heartbeat: wp_mail() returned TRUE - email handed off to mail system." );
		} else {
			error_log( "[BANS] {$type} heartbeat: wp_mail() returned FALSE - email failed to send." );
		}

		return (bool) $sent;
	}

	public static function send_email_with_csv( $settings, $rows, $is_test = false ) {
		$type = $is_test ? 'TEST' : 'DAILY';

		error_log( "[BANS] Starting {$type} email send..." );

		$recipients = self::resolve_recipients( $settings, $is_test );

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
