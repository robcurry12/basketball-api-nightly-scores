<?php
defined( 'ABSPATH' ) || exit;

/**
 * Player-level Flashscore scanning fields.
 *
 * Adds a "Nightly Scores (Flashscore)" meta box to each Player (rba_player)
 * post holding the Flashscore slug + ID used by the nightly crawl. The
 * "include in crawl" flag lives in the same post meta but is edited from the
 * plugin's admin page (see BANS_Admin), not here.
 */
class BANS_Players {

	const NONCE_ACTION = 'bans_save_player_fields';
	const NONCE_NAME   = 'bans_player_fields_nonce';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'save_post_' . BANS_PLAYER_POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
	}

	public static function add_meta_box() {
		add_meta_box(
			'bans_player_flashscore',
			'Nightly Scores (Flashscore)',
			array( __CLASS__, 'render_meta_box' ),
			BANS_PLAYER_POST_TYPE,
			'side',
			'default'
		);
	}

	public static function render_meta_box( $post ) {
		$slug = (string) get_post_meta( $post->ID, BANS_META_SLUG, true );
		$id   = (string) get_post_meta( $post->ID, BANS_META_ID, true );

		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		?>
		<p>
			<label for="bans_flashscore_slug"><strong>Flashscore Slug</strong></label><br>
			<input type="text" id="bans_flashscore_slug" name="bans_flashscore_slug"
				value="<?php echo esc_attr( $slug ); ?>" style="width:100%;"
				placeholder="e.g. paolo-banchero">
		</p>
		<p>
			<label for="bans_flashscore_id"><strong>Flashscore ID</strong></label><br>
			<input type="text" id="bans_flashscore_id" name="bans_flashscore_id"
				value="<?php echo esc_attr( $id ); ?>" style="width:100%;"
				placeholder="e.g. AbCdEf12">
		</p>
		<p class="description">
			Both fields are required for this player to be scanned. Choose which
			players run in the nightly crawl on
			<a href="<?php echo esc_url( admin_url( 'options-general.php?page=bans-settings' ) ); ?>">Basketball Scores</a>.
		</p>
		<?php
	}

	public static function save( $post_id, $post ) {
		// Nonce / autosave / capability guards.
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$slug = isset( $_POST['bans_flashscore_slug'] )
			? sanitize_text_field( wp_unslash( $_POST['bans_flashscore_slug'] ) )
			: '';
		$id   = isset( $_POST['bans_flashscore_id'] )
			? sanitize_text_field( wp_unslash( $_POST['bans_flashscore_id'] ) )
			: '';

		// Store the slug in Flashscore's own form (lowercase, hyphenated).
		$slug = $slug !== '' ? sanitize_title( $slug ) : '';

		update_post_meta( $post_id, BANS_META_SLUG, $slug );
		update_post_meta( $post_id, BANS_META_ID, $id );
	}

	/**
	 * All published players, with their scanning fields and include flag.
	 *
	 * @return array[] Each: id, label, flashscore_slug, flashscore_id, include (bool), scannable (bool).
	 */
	public static function get_all_players() {
		$query = new WP_Query( array(
			'post_type'      => BANS_PLAYER_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'no_found_rows'  => true,
			'fields'         => 'ids',
		) );

		$players = array();

		foreach ( $query->posts as $pid ) {
			$slug = (string) get_post_meta( $pid, BANS_META_SLUG, true );
			$id   = (string) get_post_meta( $pid, BANS_META_ID, true );

			$players[] = array(
				'id'              => (int) $pid,
				'label'           => get_the_title( $pid ),
				'flashscore_slug' => $slug,
				'flashscore_id'   => $id,
				'include'         => '1' === (string) get_post_meta( $pid, BANS_META_INCLUDE, true ),
				'scannable'       => ( '' !== $slug && '' !== $id ),
			);
		}

		return $players;
	}

	/**
	 * Players that should run in the nightly crawl: flagged for inclusion AND
	 * having both Flashscore fields set.
	 *
	 * @return array[] Each: label, flashscore_slug, flashscore_id.
	 */
	public static function get_crawl_players() {
		$out = array();

		foreach ( self::get_all_players() as $p ) {
			if ( ! $p['include'] || ! $p['scannable'] ) {
				continue;
			}
			$out[] = array(
				'label'           => $p['label'],
				'flashscore_slug' => $p['flashscore_slug'],
				'flashscore_id'   => $p['flashscore_id'],
			);
		}

		return $out;
	}
}
