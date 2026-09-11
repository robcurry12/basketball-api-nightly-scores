<?php
defined( 'ABSPATH' ) || exit;

/**
 * GitHub transport.
 *
 * WordPress on managed hosting can make outbound HTTPS requests but often
 * can't receive inbound API calls from datacenter IPs (edge WAF / bot
 * protection). So instead of GitHub Actions calling into WordPress, the data
 * flows through the repo:
 *
 *   - push_players(): WP writes the crawl list to data/players.json via the
 *     GitHub Contents API (outbound).
 *   - fetch_results(): WP reads data/latest.json from the repo's public raw
 *     URL (outbound). GitHub Actions commits that file after scraping.
 */
class BANS_GitHub {

	const API_BASE = 'https://api.github.com';
	const RAW_BASE = 'https://raw.githubusercontent.com';

	/**
	 * owner/repo/branch/paths for the configured repo, or null if incomplete.
	 */
	public static function repo_config( $settings ) {
		$owner  = trim( (string) ( $settings['gh_owner'] ?? '' ) );
		$repo   = trim( (string) ( $settings['gh_repo'] ?? '' ) );
		$branch = trim( (string) ( $settings['gh_branch'] ?? '' ) ) ?: 'main';
		$pp     = trim( (string) ( $settings['gh_players_path'] ?? '' ) ) ?: 'data/players.json';
		$rp     = trim( (string) ( $settings['gh_results_path'] ?? '' ) ) ?: 'data/latest.json';

		if ( '' === $owner || '' === $repo ) {
			return null;
		}

		return array(
			'owner'        => $owner,
			'repo'         => $repo,
			'branch'       => $branch,
			'players_path' => ltrim( $pp, '/' ),
			'results_path' => ltrim( $rp, '/' ),
		);
	}

	/**
	 * Write the current crawl list to data/players.json in the repo.
	 *
	 * @return array{ok:bool,message:string}
	 */
	public static function push_players( $settings ) {
		$cfg   = self::repo_config( $settings );
		$token = trim( (string) ( $settings['gh_token'] ?? '' ) );

		if ( ! $cfg ) {
			return array( 'ok' => false, 'message' => 'GitHub owner/repo not configured.' );
		}
		if ( '' === $token ) {
			return array( 'ok' => false, 'message' => 'GitHub token not configured.' );
		}

		$players = BANS_Players::get_crawl_players();

		$content = wp_json_encode(
			array(
				'generated_at_utc' => gmdate( 'c' ),
				'players'          => array_values( $players ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		) . "\n";

		$path     = $cfg['players_path'];
		$endpoint = self::API_BASE . '/repos/' . $cfg['owner'] . '/' . $cfg['repo'] . '/contents/' . $path;

		// Look up the existing file SHA (required to update; absent on create).
		$sha = self::get_file_sha( $endpoint, $cfg['branch'], $token );

		$body = array(
			'message' => 'BANS: sync players.json from WordPress',
			'content' => base64_encode( $content ),
			'branch'  => $cfg['branch'],
		);
		if ( $sha ) {
			$body['sha'] = $sha;
		}

		$res = wp_remote_request(
			$endpoint,
			array(
				'method'  => 'PUT',
				'timeout' => 20,
				'headers' => self::auth_headers( $token ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $res ) ) {
			$msg = 'GitHub request failed: ' . $res->get_error_message();
			error_log( '[BANS] ' . $msg );
			return array( 'ok' => false, 'message' => $msg );
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( $code < 200 || $code >= 300 ) {
			$snippet = substr( (string) wp_remote_retrieve_body( $res ), 0, 300 );
			$msg     = "GitHub push failed (HTTP {$code}): {$snippet}";
			error_log( '[BANS] ' . $msg );
			return array( 'ok' => false, 'message' => $msg );
		}

		$count = count( $players );
		return array(
			'ok'      => true,
			'message' => "Synced {$count} player" . ( 1 === $count ? '' : 's' ) . " to GitHub ({$cfg['owner']}/{$cfg['repo']}: {$path}).",
		);
	}

	/**
	 * Fetch and decode data/latest.json from the repo's public raw URL.
	 *
	 * @return array{ok:bool,message:string,data:array} data has generated_at_utc + rows.
	 */
	public static function fetch_results( $settings ) {
		$cfg = self::repo_config( $settings );
		if ( ! $cfg ) {
			return array( 'ok' => false, 'message' => 'GitHub owner/repo not configured.', 'data' => array() );
		}

		// Cache-bust so a fresh commit isn't hidden behind the raw CDN cache.
		$url = self::RAW_BASE . '/' . $cfg['owner'] . '/' . $cfg['repo'] . '/' . $cfg['branch'] . '/' . $cfg['results_path']
			. '?_=' . gmdate( 'YmdH' );

		$res = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'     => 'application/json',
					'User-Agent' => 'BANS-WordPress',
				),
			)
		);

		if ( is_wp_error( $res ) ) {
			$msg = 'Results fetch failed: ' . $res->get_error_message();
			error_log( '[BANS] ' . $msg );
			return array( 'ok' => false, 'message' => $msg, 'data' => array() );
		}

		$code = wp_remote_retrieve_response_code( $res );
		if ( 200 !== (int) $code ) {
			$msg = "Results fetch failed (HTTP {$code}) for {$cfg['results_path']}. Has the scraper run and committed results yet?";
			error_log( '[BANS] ' . $msg );
			return array( 'ok' => false, 'message' => $msg, 'data' => array() );
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $data ) ) {
			return array( 'ok' => false, 'message' => 'Results file was not valid JSON.', 'data' => array() );
		}

		return array( 'ok' => true, 'message' => 'OK', 'data' => $data );
	}

	private static function get_file_sha( $endpoint, $branch, $token ) {
		$res = wp_remote_get(
			add_query_arg( 'ref', rawurlencode( $branch ), $endpoint ),
			array(
				'timeout' => 20,
				'headers' => self::auth_headers( $token ),
			)
		);

		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return '';
		}

		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		return is_array( $data ) && ! empty( $data['sha'] ) ? (string) $data['sha'] : '';
	}

	private static function auth_headers( $token ) {
		return array(
			'Authorization'        => 'Bearer ' . $token,
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'BANS-WordPress',
			'Content-Type'         => 'application/json',
		);
	}
}
