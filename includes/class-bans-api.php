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
			error_log( '[BANS][API] Missing API key for endpoint ' . $endpoint );
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

		// Log outbound request (without API key).
		$qs = ! empty( $query ) ? wp_json_encode( $query ) : '{}';
		error_log( '[BANS][API] GET ' . $endpoint . ' query=' . $qs );

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( '[BANS][API] HTTP error ' . $endpoint . ': ' . $response->get_error_message() );
			return array(
				'errors' => array( $response->get_error_message() ),
			);
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		$data = json_decode( $body, true );

		if ( 200 !== (int) $code ) {
			$preview = is_string( $body ) ? substr( $body, 0, 300 ) : '';
			error_log( '[BANS][API] Non-200 ' . $endpoint . ' code=' . $code . ' body=' . $preview );
			return array(
				'errors' => array(
					'HTTP ' . $code,
					is_array( $data ) ? wp_json_encode( $data ) : (string) $body,
				),
			);
		}

		if ( null === $data ) {
			error_log( '[BANS][API] Invalid JSON for ' . $endpoint );
			return array(
				'errors' => array( 'Invalid JSON response.' ),
			);
		}

		$count = isset( $data['response'] ) && is_array( $data['response'] ) ? count( $data['response'] ) : 0;
		error_log( '[BANS][API] OK ' . $endpoint . ' items=' . $count );
		return $data;
	}

	/**
	 * Attempt to fetch a player's "most recent finished game" stats.
	 *
	 * Preferred strategy (team-agnostic, last ~72h by calendar days):
	 * - Try fetching statistics by player + date for today, yesterday, and two days ago
	 * - If found, return the first stats row
	 *
	 * Fallback strategy (legacy, team-scoped):
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

		error_log( '[BANS][API] Start stats resolution team_id=' . $team_id . ' player_id=' . $player_id );

		// 1) Get player and team names (best-effort).
		$player_name = '';
		$team_name   = '';

		$p = self::get( '/players', array( 'id' => $player_id ) );
		if ( empty( $p['errors'] ) && ! empty( $p['response'][0]['name'] ) ) {
			$player_name = (string) $p['response'][0]['name'];
			error_log( '[BANS][API] Player name=' . $player_name );
		} else {
			error_log( '[BANS][API] Unable to resolve player name for id=' . $player_id );
		}

		$t = self::get( '/teams', array( 'id' => $team_id ) );
		if ( empty( $t['errors'] ) && ! empty( $t['response'][0]['name'] ) ) {
			$team_name = (string) $t['response'][0]['name'];
			error_log( '[BANS][API] Team name=' . $team_name );
		} else {
			error_log( '[BANS][API] Unable to resolve team name for id=' . $team_id );
		}

		// 2) Prefer player-only query over last ~72h (today, yesterday, two days ago in site timezone).
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		$best_row = null;
		$best_ts  = 0;

		for ( $i = 0; $i < 3; $i++ ) {
			$date = $now->modify( '-' . $i . ' days' )->format( 'Y-m-d' );

			error_log( '[BANS][API] Try player-only stats date=' . $date );
			$statsByPlayerDate = self::get(
				'/statistics/players',
				array(
					'player' => $player_id,
					'date'   => $date,
				)
			);

			// Fallback to alternative endpoint naming if empty/errors.
			if ( ! empty( $statsByPlayerDate['errors'] ) || empty( $statsByPlayerDate['response'] ) ) {
				error_log( '[BANS][API] Empty or error on /statistics/players, trying /players/statistics for date=' . $date );
				$statsByPlayerDate = self::get(
					'/players/statistics',
					array(
						'player' => $player_id,
						'date'   => $date,
					)
				);
			}

			if ( empty( $statsByPlayerDate['errors'] ) && ! empty( $statsByPlayerDate['response'] ) && is_array( $statsByPlayerDate['response'] ) ) {
				error_log( '[BANS][API] Found ' . count( $statsByPlayerDate['response'] ) . ' rows for player/date' );
				foreach ( $statsByPlayerDate['response'] as $row ) {
					$ts = self::extract_game_timestamp( $row, $tz );
					// Fallback to end-of-day for the queried date if no timestamp exists.
					if ( $ts <= 0 ) {
						$eod = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $date . ' 23:59:59', $tz );
						if ( $eod instanceof DateTimeImmutable ) {
							$ts = $eod->getTimestamp();
						}
					}
					$gid = isset( $row['game']['id'] ) ? (int) $row['game']['id'] : 0;
					error_log( '[BANS][API] Candidate row ts=' . $ts . ' game_id=' . $gid );
					if ( $ts > $best_ts ) {
						$best_ts  = $ts;
						$best_row = $row;
					}
				}
			}
		}

		if ( ! empty( $best_row ) ) {
			error_log( '[BANS][API] Using best player-only row ts=' . $best_ts );
			$stat_row = $best_row;

			// Extract best-effort team and game info.
			$team_name_from_stats = '';
			if ( isset( $stat_row['team']['name'] ) ) {
				$team_name_from_stats = (string) $stat_row['team']['name'];
			}

			$game_id = 0;
			if ( isset( $stat_row['game']['id'] ) ) {
				$game_id = (int) $stat_row['game']['id'];
			} elseif ( isset( $stat_row['id'] ) ) {
				$game_id = (int) $stat_row['id'];
			}

			// Normalize statistics payload.
			$stat_blob = array();
			if ( isset( $stat_row['statistics'] ) && is_array( $stat_row['statistics'] ) ) {
				if ( isset( $stat_row['statistics'][0] ) && is_array( $stat_row['statistics'][0] ) ) {
					$stat_blob = $stat_row['statistics'][0];
				} else {
					$stat_blob = $stat_row['statistics'];
				}
			} elseif ( isset( $stat_row['points'] ) || isset( $stat_row['rebounds'] ) ) {
				$stat_blob = $stat_row;
			}

			$player_name_from_stats = '';
			if ( isset( $stat_row['player']['name'] ) ) {
				$player_name_from_stats = (string) $stat_row['player']['name'];
			}

			error_log( '[BANS][API] Resolved via player-only path: game_id=' . $game_id );
			return array(
				'player_name' => $player_name_from_stats ? $player_name_from_stats : $player_name,
				'team_name'   => $team_name_from_stats ? $team_name_from_stats : $team_name,
				'game_id'     => $game_id,
				'stats'       => $stat_blob,
				'errors'      => array(),
			);
		}

		// 3) Fallback: Find most recent finished game for this team by scanning recent dates.
		$days = 30;

		$game = null;

		for ( $i = 0; $i < $days; $i++ ) {
			$date = $now->modify( '-' . $i . ' days' )->format( 'Y-m-d' );

			error_log( '[BANS][API] Team-scan date=' . $date );
			$games = self::get(
				'/games',
				array(
					'team' => $team_id,
					'date' => $date,
				)
			);

			if ( ! empty( $games['errors'] ) || empty( $games['response'] ) || ! is_array( $games['response'] ) ) {
				error_log( '[BANS][API] No games found for team/date' );
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
					error_log( '[BANS][API] Selected finished game id=' . ( isset( $g['id'] ) ? (int) $g['id'] : 0 ) );
					break 2;
				}
			}

			// If none marked finished, still accept the first game result as fallback.
			if ( empty( $game ) && ! empty( $games['response'][0] ) ) {
				$game = $games['response'][0];
				error_log( '[BANS][API] Selected fallback game id=' . ( isset( $game['id'] ) ? (int) $game['id'] : 0 ) );
				break;
			}
		}

		if ( empty( $game ) || empty( $game['id'] ) ) {
			error_log( '[BANS][API] No recent game found for team in lookback window.' );
			return array(
				'player_name' => $player_name,
				'team_name'   => $team_name,
				'errors'      => array( 'No recent game found for team in lookback window.' ),
			);
		}

		$game_id = (int) $game['id'];

		// 3) Fetch player statistics for that game using resilient endpoint attempts.
		$stat_row = self::get_player_stats_for_game( $game_id, $player_id );

		if ( empty( $stat_row ) ) {
			error_log( '[BANS][API] No stats returned for player/game. player_id=' . $player_id . ' game_id=' . $game_id );
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

	/**
	 * Try multiple endpoints/param shapes to fetch player stats for a specific game.
	 *
	 * @param int $game_id
	 * @param int $player_id
	 * @return array|null
	 */
	private static function get_player_stats_for_game( $game_id, $player_id ) {
		// Attempt 1: '/statistics/players' with id + player
		error_log( '[BANS][API] Try /statistics/players id+player game_id=' . $game_id . ' player_id=' . $player_id );
		$stats = self::get(
			'/statistics/players',
			array(
				'id'     => $game_id,
				'player' => $player_id,
			)
		);
		if ( empty( $stats['errors'] ) && ! empty( $stats['response'][0] ) ) {
			return $stats['response'][0];
		}

		// Attempt 2: '/players/statistics' with game + player
		error_log( '[BANS][API] Try /players/statistics game+player' );
		$stats = self::get(
			'/players/statistics',
			array(
				'game'   => $game_id,
				'player' => $player_id,
			)
		);
		if ( empty( $stats['errors'] ) && ! empty( $stats['response'][0] ) ) {
			return $stats['response'][0];
		}

		// Attempt 3: '/players/statistics' with game only, then filter by player
		error_log( '[BANS][API] Try /players/statistics game-only then filter' );
		$stats = self::get(
			'/players/statistics',
			array(
				'game' => $game_id,
			)
		);
		if ( empty( $stats['errors'] ) && ! empty( $stats['response'] ) && is_array( $stats['response'] ) ) {
			foreach ( $stats['response'] as $row ) {
				$pid = isset( $row['player']['id'] ) ? (int) $row['player']['id'] : 0;
				if ( $pid === (int) $player_id ) {
					return $row;
				}
			}
		}

		// Attempt 4: '/statistics/players' with id only, then filter
		error_log( '[BANS][API] Try /statistics/players id-only then filter' );
		$stats = self::get(
			'/statistics/players',
			array(
				'id' => $game_id,
			)
		);
		if ( empty( $stats['errors'] ) && ! empty( $stats['response'] ) && is_array( $stats['response'] ) ) {
			foreach ( $stats['response'] as $row ) {
				$pid = isset( $row['player']['id'] ) ? (int) $row['player']['id'] : 0;
				if ( $pid === (int) $player_id ) {
					return $row;
				}
			}
		}

		return null;
	}

	/**
	 * Best-effort extraction of a game timestamp from a stats row.
	 *
	 * @param array            $row
	 * @param DateTimeZone|mixed $tz
	 * @return int Unix timestamp or 0 if unknown
	 */
	private static function extract_game_timestamp( $row, $tz ) {
		// Common shapes
		if ( isset( $row['game']['timestamp'] ) && is_numeric( $row['game']['timestamp'] ) ) {
			return (int) $row['game']['timestamp'];
		}
		if ( isset( $row['game']['date'] ) && is_string( $row['game']['date'] ) ) {
			$ts = strtotime( $row['game']['date'] );
			return $ts ? (int) $ts : 0;
		}
		if ( isset( $row['timestamp'] ) && is_numeric( $row['timestamp'] ) ) {
			return (int) $row['timestamp'];
		}
		if ( isset( $row['date'] ) && is_string( $row['date'] ) ) {
			$ts = strtotime( $row['date'] );
			return $ts ? (int) $ts : 0;
		}
		return 0;
	}
}
