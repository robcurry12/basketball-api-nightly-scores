<?php
defined( 'ABSPATH' ) || exit;

class BANS_Cron {

	public static function init() {
		add_action( 'bans_nightly_event', array( __CLASS__, 'nightly_fallback' ) );

		if ( ! wp_next_scheduled( 'bans_nightly_event' ) ) {
			wp_schedule_event( strtotime( '02:00 tomorrow' ), 'daily', 'bans_nightly_event' );
		}
	}

	public static function nightly_fallback() {
		$settings = BANS_Admin::get_settings();
		$last     = get_option( 'bans_last_push', array() );
		$rows     = isset( $last['rows'] ) && is_array( $last['rows'] ) ? $last['rows'] : array();

		if ( empty( $rows ) ) {
			return;
		}

		self::send_email_with_csv( $settings, $rows, false );
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
