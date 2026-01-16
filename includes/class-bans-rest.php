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
				'permission_callback' => '__return_true', // We authenticate via shared secret header.
			)
		);
	}

	public static function handle_push( WP_REST_Request $request ) {
		$settings = BANS_Admin::get_settings();

		$secret_header = $request->get_header( 'x-bans-secret' );
		$expected      = isset( $settings['push_secret'] ) ? (string) $settings['push_secret'] : '';

		if ( empty( $expected ) || empty( $secret_header ) || ! hash_equals( $expected, $secret_header ) ) {
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
				'Player'     => $player,
				'Game Date'  => sanitize_text_field( $row['game_date'] ?? '' ),
				'Game URL'   => esc_url_raw( $row['game_url'] ?? '' ),
				'Minutes'    => sanitize_text_field( $row['minutes'] ?? '' ),
				'Points'     => (int) ( $row['points'] ?? 0 ),
				'Rebounds'   => (int) ( $row['rebounds'] ?? 0 ),
				'Assists'    => (int) ( $row['assists'] ?? 0 ),
				'Steals'     => (int) ( $row['steals'] ?? 0 ),
				'Turnovers'  => (int) ( $row['turnovers'] ?? 0 ),
			);
		}

		if ( empty( $sanitized_rows ) ) {
			error_log( '[BANS] Push received but contained zero usable rows.' );

			// Store last push anyway for visibility.
			update_option(
				'bans_last_push',
				array(
					'generated_at_utc' => sanitize_text_field( $payload['generated_at_utc'] ?? '' ),
					'rows'             => array(),
				),
				false
			);

			return new WP_REST_Response(
				array( 'ok' => true, 'sent' => false, 'reason' => 'No rows' ),
				200
			);
		}

		// Store last successful push (useful for debugging)
		update_option(
			'bans_last_push',
			array(
				'generated_at_utc' => sanitize_text_field( $payload['generated_at_utc'] ?? '' ),
				'rows'             => $sanitized_rows,
			),
			false
		);

		$sent = BANS_Cron::send_email_with_csv( $settings, $sanitized_rows, false );

		return new WP_REST_Response(
			array(
				'ok'   => true,
				'sent' => (bool) $sent,
				'rows' => count( $sanitized_rows ),
			),
			200
		);
	}
}
