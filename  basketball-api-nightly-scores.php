<?php
/**
 * Plugin Name: Basketball API Nightly Scores
 * Description: Emails nightly basketball stat summaries via CSV. Scraping is performed externally (e.g. GitHub Actions) and pushed into WordPress via REST.
 * Version: 2.2.0
 * Author: Rob Curry
 */

defined( 'ABSPATH' ) || exit;

define( 'BANS_PLUGIN_VERSION', '2.2.0' );
define( 'BANS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BANS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once BANS_PLUGIN_DIR . 'includes/class-bans-admin.php';
require_once BANS_PLUGIN_DIR . 'includes/class-bans-cron.php';
require_once BANS_PLUGIN_DIR . 'includes/class-bans-rest.php';

BANS_Admin::init();
BANS_Cron::init();
BANS_REST::init();
