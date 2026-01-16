<?php
defined( 'ABSPATH' ) || exit;

class BANS_Cron {

	public static function init() {
		add_action( 'bans_nightly_event', array( __CLASS__, 'nightly' ) );

		if ( ! wp_next_scheduled( 'bans_nightly_event' ) ) {
			wp_schedule_event( strtotime( '02:00 tomorrow' ), 'daily', 'bans_nightly_event' );
		}
	}

	public static function nightly() {
		$settings = BANS_Admin::get_settings();
		self::execute( $settings, false );
	}

	public static function execute( $settings, $is_test = false ) {
		$rows = array();

		foreach ( $settings['players'] as $player ) {
			$script = BANS_PLUGIN_DIR . 'scraper/flashscore-cli.mjs';

			$cmd = escapeshellcmd( $settings['node_path'] ) . ' ' .
				escapeshellarg( $script ) . ' ' .
				escapeshellarg( $player['flashscore_slug'] ) . ' ' .
				escapeshellarg( $player['flashscore_id'] );

			$descriptors = array(
				1 => array( 'pipe', 'w' ),
				2 => array( 'pipe', 'w' ),
			);

			$process = proc_open( $cmd, $descriptors, $pipes );
			$output  = stream_get_contents( $pipes[1] );
			proc_close( $process );

			$data = json_decode( $output, true );

			if ( empty( $data['ok'] ) || ! empty( $data['ignored'] ) ) {
				continue;
			}

			$rows[] = array_merge(
				array( 'Player' => $player['label'] ),
				$data['stats']
			);
		}

		if ( empty( $rows ) ) {
			return;
		}

		$to = $is_test
			? array( $settings['test_email'] )
			: array_map( 'trim', explode( ',', $settings['emails'] ) );

		wp_mail(
			$to,
			'Basketball Nightly Stats',
			self::format_email( $rows )
		);
	}

	private static function format_email( $rows ) {
		$lines = array();

		foreach ( $rows as $row ) {
			$lines[] = implode( ' | ', array_map(
				fn( $k, $v ) => "$k: $v",
				array_keys( $row ),
				$row
			) );
		}

		return implode( "\n\n", $lines );
	}
}
