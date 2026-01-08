<?php
/**
 * Plugin Name: Basketball API Nightly Scores (CSV Email)
 * Description: Nightly 2am ET cron pulls most recent player game stats from API-Basketball and emails a CSV report.
 * Version: 1.0.6
 * Author: Rob Curry
 */

defined( 'ABSPATH' ) || exit;

define( 'BANS_PLUGIN_VERSION', '1.0.6' );
define( 'BANS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'BANS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once BANS_PLUGIN_DIR . 'includes/class-bans-api.php';
require_once BANS_PLUGIN_DIR . 'includes/class-bans-admin.php';
require_once BANS_PLUGIN_DIR . 'includes/class-bans-cron.php';

register_activation_hook( __FILE__, array( 'BANS_Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'BANS_Cron', 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function() {
		BANS_Admin::init();
		BANS_Cron::init();
	}
);
