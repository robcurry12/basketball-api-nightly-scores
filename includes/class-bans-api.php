<?php
defined( 'ABSPATH' ) || exit;

class BANS_API {

	const BASE_URL = 'https://v1.basketball.api-sports.io';

	private static function get( $endpoint, $params = array() ) {
		$endpoint = '/' . ltrim( (string) $endpoint, '/' );

		$api_key = get_option( 'bans_api_key', '' );
		$api_key = is_string( $api_key ) ? trim( $api_key ) : '';

		if ( empty( $api_key ) ) {
			error_log( '[BANS][API] Missing API key option bans_api_key.' );
			return array(
				'errors' => array( 'Missing API key.' ),
			);
		}

		$url = self::BASE_URL . $endpoint;

		if ( ! empty( $params ) && is_array( $params ) ) {
			$url = add_query_arg( $params, $url );
		}

		$args = array(
			'timeout' => 15,
			'headers' => array(
				'x-apisports-key' => $api_key,
			),
		);

		error_log( '[BANS][API] GET ' . $url );

		$response = wp_remote_get( $url, $args );

		if ( is_wp_error( $response ) ) {
			error_log( '[BANS][API] WP_Error: ' . $response->get_error_message() );
			return array(
				'errors' => array( $response->get_error_message() ),
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );

		if ( $code < 200 || $code >= 300 ) {
			error_log( '[BANS][API] Non-2xx response code=' . $code . ' body=' . substr( (string) $body, 0, 500 ) );
			return array(
				'errors' => array( 'Non-2xx response code: ' . $code ),
			);
		}

		$data = json_decode( (string) $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			error_log( '[BANS][API] Invalid JSON for ' . $endpoint );
			return array(
				'errors' => array( 'Invalid JSON response.' ),
			);
		}

		$count = isset( $data['response'] ) && is_array( $data['response'] ) ? count( $data['response'] ) : 0;
		error_log( '[BANS][API] OK ' . $endpoint . ' items=' . $count );
		return $data;
	}

	public static function get_player_most_recent_stats( $team_id, $player_id ) {
		$team_id   = (int) $team_id;
		$player_id = (int) $player_id;

		error_log( '[BANS][API] Start stats resolution team_id=' . $team_id . ' player_id=' . $player_id );

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

		// 2) Season-based query first: use '/games/statistics/players' with season(s), last 48h filter.
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );

		$seasons = self::compute_seasons_to_try( $now );

		foreach ( $seasons as $season ) {
			error_log( '[BANS][API] Season scan player=' . $player_id . ' season=' . $season . ' via /games/statistics/players' );
			$statsBySeason = self::get(
				'/games/statistics/players',
				array(
					'player' => $player_id,
					'season' => $season,
				)
			);

			if ( $statsBySeason['results'] == 0 ) {
				error_log( '[BANS][API] Empty/error on /games/statistics/players');
			}

			// FIXED: correct validation of response array and results.
			if (
				empty( $statsBySeason['errors'] )
				&& ! empty( $statsBySeason['response'] )
				&& is_array( $statsBySeason['response'] )
				&& (int) $statsBySeason['results'] > 0
			) {
				$rows = (array) $statsBySeason['response'];

				$best_row = null;
				$best_gid = 0;

				// Prefer rows matching the configured team_id when present.
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) || empty( $row['game']['id'] ) ) {
						continue;
					}

					if ( isset( $row['team']['id'] ) && (int) $row['team']['id'] !== (int) $team_id ) {
						continue;
					}

					$gid = (int) $row['game']['id'];
					if ( $gid > $best_gid ) {
						$best_gid = $gid;
						$best_row = $row;
					}
				}

				// If nothing matched team_id (or team.id is missing), fall back to any row.
				if ( empty( $best_row ) ) {
					foreach ( $rows as $row ) {
						if ( ! is_array( $row ) || empty( $row['game']['id'] ) ) {
							continue;
						}

						$gid = (int) $row['game']['id'];
						if ( $gid > $best_gid ) {
							$best_gid = $gid;
							$best_row = $row;
						}
					}
				}

				$stat_row = $best_row;
				$gid      = isset( $stat_row['game']['id'] ) ? (int) $stat_row['game']['id'] : 0;

				error_log( '[BANS][API] Using most recent row by highest game.id=' . $gid . ' (season=' . $season . ')' );

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
				} else {
					// In many API-Basketball responses for /games/statistics/players, stats are top-level.
					$stat_blob = $stat_row;
				}

				return array(
					'player_name' => $player_name,
					'team_name'   => $team_name_from_stats ? $team_name_from_stats : $team_name,
					'game_id'     => $game_id,
					'stats'       => $stat_blob,
					'errors'      => array(),
				);
			}
		}

		// No season-based results; continue to secondary strategy.

		// 3) Secondary: player-only query over last ~72h (today, yesterday, two days ago in site timezone).
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
						'id'   => $player_id,
						'date' => $date,
					)
				);
			}

			if ( empty( $statsByPlayerDate['errors'] ) && ! empty( $statsByPlayerDate['response'] ) && is_array( $statsByPlayerDate['response'] ) ) {
				foreach ( $statsByPlayerDate['response'] as $row ) {
					$ts = self::extract_game_timestamp( $row, $tz );
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

			$stat_blob = array();
			if ( isset( $stat_row['statistics'] ) && is_array( $stat_row['statistics'] ) ) {
				if ( isset( $stat_row['statistics'][0] ) && is_array( $stat_row['statistics'][0] ) ) {
					$stat_blob = $stat_row['statistics'][0];
				} else {
					$stat_blob = $stat_row['statistics'];
				}
			} else {
				$stat_blob = $stat_row;
			}

			return array(
				'player_name' => $player_name,
				'team_name'   => $team_name_from_stats ? $team_name_from_stats : $team_name,
				'game_id'     => $game_id,
				'stats'       => $stat_blob,
				'errors'      => array(),
			);
		}

		// 4) Final fallback: scan team games for last 30 days and fetch player stats per finished game.
		error_log( '[BANS][API] Fallback to team game scan last 30 days.' );

		for ( $d = 0; $d < 30; $d++ ) {
			$date = $now->modify( '-' . $d . ' days' )->format( 'Y-m-d' );

			$games = self::get(
				'/games',
				array(
					'team' => $team_id,
					'date' => $date,
				)
			);

			if ( ! empty( $games['errors'] ) || empty( $games['response'] ) ) {
				continue;
			}

			foreach ( (array) $games['response'] as $game ) {
				$game_id = isset( $game['id'] ) ? (int) $game['id'] : 0;
				if ( $game_id <= 0 ) {
					continue;
				}

				// Only consider finished games, if status is available.
				if ( isset( $game['status']['short'] ) ) {
					$short = (string) $game['status']['short'];
					if ( 'FT' !== $short && 'AOT' !== $short ) {
						continue;
					}
				}

				$stats = self::get(
					'/games/statistics/players',
					array(
						'game'   => $game_id,
						'player' => $player_id,
					)
				);

				if ( ! empty( $stats['errors'] ) || empty( $stats['response'] ) ) {
					$stats = self::get(
						'/statistics/players',
						array(
							'game'   => $game_id,
							'player' => $player_id,
						)
					);
				}

				if ( empty( $stats['errors'] ) && ! empty( $stats['response'] ) && is_array( $stats['response'] ) ) {
					$stat_row = $stats['response'][0];

					$team_name_from_stats = '';
					if ( isset( $stat_row['team']['name'] ) ) {
						$team_name_from_stats = (string) $stat_row['team']['name'];
					}

					$stat_blob = array();
					if ( isset( $stat_row['statistics'] ) && is_array( $stat_row['statistics'] ) ) {
						if ( isset( $stat_row['statistics'][0] ) && is_array( $stat_row['statistics'][0] ) ) {
							$stat_blob = $stat_row['statistics'][0];
						} else {
							$stat_blob = $stat_row['statistics'];
						}
					} else {
						$stat_blob = $stat_row;
					}

					return array(
						'player_name' => $player_name,
						'team_name'   => $team_name_from_stats ? $team_name_from_stats : $team_name,
						'game_id'     => $game_id,
						'stats'       => $stat_blob,
						'errors'      => array(),
					);
				}
			}
		}

		error_log( '[BANS][API] No stats found for player_id=' . $player_id . ' team_id=' . $team_id );
		return array(
			'player_name' => $player_name,
			'team_name'   => $team_name,
			'game_id'     => 0,
			'stats'       => array(),
			'errors'      => array( 'No recent stats found within lookback.' ),
		);
	}

	private static function compute_seasons_to_try( DateTimeImmutable $now ) {
		$year  = (int) $now->format( 'Y' );
		$month = (int) $now->format( 'n' );

		if ( $month >= 1 && $month <= 6 ) {
			$prev = $year - 1;

			return array(
				$prev . '-' . $year,
				(string) $prev,
				(string) $year,
			);
		}

		$next = $year + 1;
		return array(
			$year . '-' . $next,
			(string) $year,
			(string) $next,
		);
	}

	private static function extract_game_timestamp( $row, $tz ) {
		if ( ! is_array( $row ) ) {
			return 0;
		}

		$ts = 0;

		if ( isset( $row['game']['timestamp'] ) ) {
			$ts = (int) $row['game']['timestamp'];
		} elseif ( isset( $row['timestamp'] ) ) {
			$ts = (int) $row['timestamp'];
		}

		if ( $ts > 0 ) {
			return $ts;
		}

		$date_str = '';
		if ( isset( $row['game']['date'] ) ) {
			$date_str = (string) $row['game']['date'];
		} elseif ( isset( $row['date'] ) ) {
			$date_str = (string) $row['date'];
		}

		if ( empty( $date_str ) ) {
			return 0;
		}

		try {
			$dt = new DateTimeImmutable( $date_str, $tz );
			return (int) $dt->format( 'U' );
		} catch ( Exception $e ) {
			return 0;
		}
	}
}
