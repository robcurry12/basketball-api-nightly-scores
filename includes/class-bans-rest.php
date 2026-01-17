<?php
defined( 'ABSPATH' ) || exit;

class BANS_REST {

	const ROUTE_NS = 'bans/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {

		register_rest_route(
			self::ROUTE_NS,
			'/push',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_push' ),
				'permission_callback' => '__return_true', // Shared-secret auth header
			)
		);

		register_rest_route(
			self::ROUTE_NS,
			'/players',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle_players' ),
				'permission_callback' => '__return_true', // Shared-secret auth header
			)
		);
	}

	private static function is_authorized( WP_REST_Request $request ) {
		$settings      = BANS_Admin::get_settings();
		$expected      = isset( $settings['push_secret'] ) ? (string) $settings['push_secret'] : '';
		$secret_header = $request->get_header( 'x-bans-secret' );

		return ! empty( $expected ) && ! empty( $secret_header ) && hash_equals( $expected, (string) $secret_header );
	}

	private static function clean_game_url( $url ) {
		$url = (string) $url;
		$url = esc_url_raw( $url );

		if ( '' === $url ) {
			return '';
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) || empty( $parts['path'] ) ) {
			return $url;
		}

		// Strip query + fragment, keep scheme/host/path
		return $parts['scheme'] . '://' . $parts['host'] . $parts['path'];
	}

	private static function format_date_mdy( $iso_date ) {
		$iso_date = (string) $iso_date;

		$dt = DateTime::createFromFormat( 'Y-m-d', $iso_date );
		if ( $dt instanceof DateTime ) {
			return $dt->format( 'm/d/Y' );
		}

		// If already in some other format, return sanitized raw
		return sanitize_text_field( $iso_date );
	}

	public static function handle_players( WP_REST_Request $request ) {
		if ( ! self::is_authorized( $request ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => 'Unauthorized' ),
				401
			);
		}

		$settings = BANS_Admin::get_settings();
		$players  = isset( $settings['players'] ) && is_array( $settings['players'] ) ? $settings['players'] : array();

		$out = array();

		foreach ( $players as $p ) {
			$label = sanitize_text_field( $p['label'] ?? '' );
			$slug  = sanitize_title( $p['flashscore_slug'] ?? '' );
			$id    = sanitize_text_field( $p['flashscore_id'] ?? '' );

			if ( '' === $slug || '' === $id ) {
				continue;
			}

			$out[] = array(
				'label'          => $label ?: $slug,
				'flashscore_slug'=> $slug,
				'flashscore_id'  => $id,
			);
		}

		return new WP_REST_Response(
			array(
				'ok'      => true,
				'players' => $out,
			),
			200
		);
	}

	public static function handle_push( WP_REST_Request $request ) {

		if ( ! self::is_authorized( $request ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => 'Unauthorized' ),
				401
			);
		}

		$payload = $request->get_json_params();

		if ( ! is_array( $payload ) ) {
			return new WP_REST_Response(
				array( 'ok' => false, 'error' => 'Invalid JSON payload' ),
				400
			);
		}

		$rows = isset( $payload['rows'] ) && is_array( $payload['rows'] ) ? $payload['rows'] : array();

		$sanitized_rows = array();

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$player = sanitize_text_field( $row['player'] ?? '' );
			if ( '' === $player ) {
				continue;
			}

			$sanitized_rows[] = array(
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

		// Store data for the nightly cron to send at 2am.
		// Email is NOT sent here - it's sent by the WP cron (bans_nightly_event).
		update_option(
			'bans_last_push',
			array(
				'generated_at_utc' => sanitize_text_field( $payload['generated_at_utc'] ?? '' ),
				'rows'             => $sanitized_rows,
			),
			false
		);

		if ( empty( $sanitized_rows ) ) {
			error_log( '[BANS] Push received but contained zero usable rows.' );

			return new WP_REST_Response(
				array( 'ok' => true, 'stored' => false, 'reason' => 'No rows' ),
				200
			);
		}

		return new WP_REST_Response(
			array(
				'ok'     => true,
				'stored' => true,
				'rows'   => count( $sanitized_rows ),
			),
			200
		);
	}
}
