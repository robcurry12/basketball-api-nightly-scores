<?php
defined( 'ABSPATH' ) || exit;

class BANS_API {

	/**
	 * API-Basketball base URL (API-SPORTS).
	 * This is the commonly documented base for API-Basketball v1.
	 *
	 * If you later need RapidAPI headers, you can extend this class.
	 */
	const BASE_URL = 'https://v1.basketball.api-sports.io';

	/**
	 * Perform a GET request to API-Basketball.
	 *
	 * @param string $endpoint Example: '/players'
	 * @param array  $query    Query args
	 * @return array           Decoded JSON
	 */
	public static function get( $endpoint, $query = array() ) {
		$opts    = get_option( 'bans_settings', array() );
		$api_key = isset( $opts['api_key'] ) ? trim( $opts['api_key'] ) : '';

		if ( empty( $api_key ) ) {
			return array(
				'errors' => array( 'Missing API key.' ),
			);
		}

		$url = self::BASE_URL . $endpoint;

		if ( ! empty( $query ) ) {
			$url = add_query_arg( $query, $url );
		}

		$args = array(
			'timeout' => 20,
			'headers' => array(
				// API-SPORTS key header.
				'x-apisports-key' => $api_key,
			),
		);

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			return array(
				'errors' => array( $response->get_error_message() ),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$data = json_decode( $body, true );

		if ( 200 !== (int) $code ) {
			return array(
				'errors' => array(
					'HTTP ' . $code,
					is_array( $data ) ? wp_json_encode( $data ) : (string) $body,
				),
			);
		}

		if ( null === $data ) {
			return array(
				'errors' => array( 'Invalid JSON response.' ),
			);
		}

		return $data;
	}

	/**
	 * Attempt to fetch a player's "most recent finished game" stats.
	 *
	 * Strategy (robust, avoids needing season config):
	 * - Look back day-by-day up to N days
	 * - Find the first finished game for the configured TEAM
	 * - Then request player stats for that game
	 *
	 * NOTE: API endpoints can vary slightly by plan/version; this method is defensive
	 * and will return partial info if some endpoints differ.
	 *
	 * @param int $team_id
	 * @param int $player_id
	 * @return array
	 */
	public static function get_player_most_recent_stats( $team_id, $player_id ) {
		$team_id   = (int) $team_id;
		$player_id = (int) $player_id;

		// 1) Get player and team names (best-effort).
		$player_name = '';
		$team_name   = '';

		$p = self::get( '/players', array( 'id' => $player_id ) );
		if ( empty( $p['errors'] ) && ! empty( $p['response'][0]['name'] ) ) {
			$player_name = (string) $p['response'][0]['name'];
		}

		$t = self::get( '/teams', array( 'id' => $team_id ) );
		if ( empty( $t['errors'] ) && ! empty( $t['response'][0]['name'] ) ) {
			$team_name = (string) $t['response'][0]['name'];
		}

		// 2) Find most recent finished game for this team by scanning recent dates.
		$tz   = wp_timezone(); // should be set in WP settings; we schedule ET separately.
		$now  = new DateTimeImmutable( 'now', $tz );
		$days = 30;

		$game = null;

		for ( $i = 0; $i < $days; $i++ ) {
			$date = $now->modify( '-' . $i . ' days' )->format( 'Y-m-d' );

			$games = self::get(
				'/games',
				array(
					'team' => $team_id,
					'date' => $date,
				)
			);

			if ( ! empty( $games['errors'] ) || empty( $games['response'] ) || ! is_array( $games['response'] ) ) {
				continue;
			}

			// Pick a finished game if possible, else just take the latest returned.
			foreach ( $games['response'] as $g ) {
				$status = '';
				if ( isset( $g['status']['short'] ) ) {
					$status = (string) $g['status']['short'];
				} elseif ( isset( $g['status'] ) && is_string( $g['status'] ) ) {
					$status = (string) $g['status'];
				}

				// Common “finished” indicators can vary; accept a few.
				$finished = in_array( $status, array( 'FT', 'AOT', 'FINAL', 'FIN' ), true );

				if ( $finished ) {
					$game = $g;
					break 2;
				}
			}

			// If none marked finished, still accept the first game result as fallback.
			if ( empty( $game ) && ! empty( $games['response'][0] ) ) {
				$game = $games['response'][0];
				break;
			}
		}

		if ( empty( $game ) || empty( $game['id'] ) ) {
			return array(
				'player_name' => $player_name,
				'team_name'   => $team_name,
				'errors'      => array( 'No recent game found for team in lookback window.' ),
			);
		}

		$game_id = (int) $game['id'];

		// 3) Fetch player statistics for that game.
		// The docs indicate a “players statistics” endpoint exists; parameters can differ by version.
		// We'll try the common pattern: game + player, else game only and filter.
		$stats = self::get(
			'/statistics/players',
			array(
				'id'   => $game_id,
				'player' => $player_id,
			)
		);

		$stat_row = null;

		if ( empty( $stats['errors'] ) && ! empty( $stats['response'] ) && is_array( $stats['response'] ) ) {
			// If endpoint returns a list, grab first.
			$stat_row = $stats['response'][0];
		} else {
			// Fallback: try without player param, then filter.
			$stats2 = self::get(
				'/statistics/players',
				array(
					'id' => $game_id,
				)
			);

			if ( empty( $stats2['errors'] ) && ! empty( $stats2['response'] ) && is_array( $stats2['response'] ) ) {
				foreach ( $stats2['response'] as $row ) {
					$pid = isset( $row['player']['id'] ) ? (int) $row['player']['id'] : 0;
					if ( $pid === $player_id ) {
						$stat_row = $row;
						break;
					}
				}
			}
		}

		if ( empty( $stat_row ) ) {
			return array(
				'player_name' => $player_name,
				'team_name'   => $team_name,
				'game_id'     => $game_id,
				'errors'      => array( 'No stats returned for player/game.' ),
			);
		}

		// Normalize stat shape (API can nest stats differently).
		$player_name_from_stats = '';
		if ( isset( $stat_row['player']['name'] ) ) {
			$player_name_from_stats = (string) $stat_row['player']['name'];
		}

		$team_name_from_stats = '';
		if ( isset( $stat_row['team']['name'] ) ) {
			$team_name_from_stats = (string) $stat_row['team']['name'];
		}

		// Often stats are in $stat_row['statistics'][0] or $stat_row['statistics'].
		$stat_blob = array();

		if ( isset( $stat_row['statistics'] ) && is_array( $stat_row['statistics'] ) ) {
			// Sometimes it is an array of one element; sometimes associative.
			if ( isset( $stat_row['statistics'][0] ) && is_array( $stat_row['statistics'][0] ) ) {
				$stat_blob = $stat_row['statistics'][0];
			} else {
				$stat_blob = $stat_row['statistics'];
			}
		} elseif ( isset( $stat_row['points'] ) || isset( $stat_row['rebounds'] ) ) {
			$stat_blob = $stat_row;
		}

		return array(
			'player_name' => $player_name_from_stats ? $player_name_from_stats : $player_name,
			'team_name'   => $team_name_from_stats ? $team_name_from_stats : $team_name,
			'game_id'     => $game_id,
			'stats'       => $stat_blob,
			'errors'      => array(),
		);
	}
}
