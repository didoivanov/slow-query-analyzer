<?php
/**
 * Removes all plugin data when the plugin is deleted from the Plugins screen.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sqa_queries" );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}sqa_requests" );

delete_option( 'sqa_settings' );
delete_option( 'sqa_secret' );
delete_option( 'sqa_db_version' );
delete_transient( 'sqa_pruned' );
