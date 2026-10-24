<?php
/**
 * Plugin Name:       Slow Query Analyzer
 * Description:       Finds slow and repeated MySQL queries and explains why they are slow. Profiles only your own admin session after you switch it on — visitors are never affected.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Plugin URI:        https://github.com/didoivanov/slow-query-analyzer
 * Update URI:        https://github.com/didoivanov/slow-query-analyzer
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       slow-query-analyzer
 */

defined( 'ABSPATH' ) || exit;

define( 'SQA_VERSION', '1.0.0' );
define( 'SQA_FILE', __FILE__ );
define( 'SQA_DIR', plugin_dir_path( __FILE__ ) );
define( 'SQA_URL', plugin_dir_url( __FILE__ ) );
define( 'SQA_GITHUB_REPO', 'didoivanov/slow-query-analyzer' );

require_once SQA_DIR . 'includes/class-sqa-session.php';

/**
 * Gate: the profiler only wakes up when the request carries a valid, signed
 * profiling cookie that an administrator explicitly switched on from
 * Tools → Slow Queries. Visitors never have that cookie, so for them the
 * plugin does nothing beyond this one array lookup.
 */
if ( SQA_Session::get_cookie_user_id() ) {
	require_once SQA_DIR . 'includes/class-sqa-store.php';
	require_once SQA_DIR . 'includes/class-sqa-analyzer.php';
	require_once SQA_DIR . 'includes/class-sqa-collector.php';
	SQA_Collector::boot();
}

if ( is_admin() ) {
	require_once SQA_DIR . 'includes/class-sqa-store.php';
	require_once SQA_DIR . 'includes/class-sqa-analyzer.php';
	require_once SQA_DIR . 'includes/class-sqa-admin.php';
	SQA_Admin::init();
}

// Update checks also run from WP-Cron and WP-CLI, not only in wp-admin.
if ( is_admin() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
	require_once SQA_DIR . 'includes/class-sqa-updater.php';
	SQA_Updater::init();
}

add_action( 'wp_logout', array( 'SQA_Session', 'clear_cookie' ) );

register_activation_hook( __FILE__, 'sqa_activate' );

function sqa_activate() {
	require_once SQA_DIR . 'includes/class-sqa-store.php';
	SQA_Store::install();
	SQA_Session::ensure_secret();
}
