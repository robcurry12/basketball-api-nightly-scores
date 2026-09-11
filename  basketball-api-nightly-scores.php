<?php
/**
 * Plugin Name: Basketball API Nightly Scores
 * Description: Emails nightly basketball stat summaries via CSV. Flashscore scanning fields live on each Player (rba_player) post; the admin page chooses which players are included in the nightly crawl. WordPress syncs that list out to a GitHub repo, GitHub Actions scrapes and commits results back, and WordPress pulls the results in for the nightly email — no inbound requests to the site.
 * Version: 4.0.0
 * Author: Rob Curry
 */

defined( 'ABSPATH' ) || exit;

define( 'BANS_PLUGIN_VERSION', '4.0.0' );
define( 'BANS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BANS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Player post type (registered by the rba-blocks plugin) and the meta keys we
// attach to it. Centralised here so a future post-type change is a one-liner.
define( 'BANS_PLAYER_POST_TYPE', 'rba_player' );
define( 'BANS_META_SLUG', 'bans_flashscore_slug' );
define( 'BANS_META_ID', 'bans_flashscore_id' );
define( 'BANS_META_INCLUDE', 'bans_include_in_crawl' );

require_once BANS_PLUGIN_DIR . 'includes/class-bans-players.php';
require_once BANS_PLUGIN_DIR . 'includes/class-bans-github.php';
require_once BANS_PLUGIN_DIR . 'includes/class-bans-admin.php';
require_once BANS_PLUGIN_DIR . 'includes/class-bans-cron.php';

BANS_Players::init();
BANS_Admin::init();
BANS_Cron::init();
