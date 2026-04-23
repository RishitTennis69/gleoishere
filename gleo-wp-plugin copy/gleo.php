<?php
/**
 * Plugin Name: Gleo
 * Description: Generative Engine Optimization (GEO) platform to analyze and optimize content for AI-powered search.
 * Version: 1.0.0
 * Author: Gleo
 * License: GPL2
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GLEO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GLEO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-frontend.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-batch-scanner.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-api-client.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-analytics.php';
require_once GLEO_PLUGIN_DIR . 'includes/class-gleo-tracking.php';

new Gleo_Frontend();
new Gleo_Batch_Scanner();

add_action( 'admin_menu', function() {
	add_menu_page(
		'Gleo',
		'Gleo',
		'manage_options',
		'gleo',
		function() {
			echo '<div id="gleo-admin-app"></div>';
		},
		'dashicons-chart-line',
		25
	);
} );

add_action( 'admin_enqueue_scripts', function( $hook ) {
	if ( $hook !== 'toplevel_page_gleo' ) return;
	$asset = require GLEO_PLUGIN_DIR . 'build/index.asset.php';
	wp_enqueue_script(
		'gleo-admin',
		GLEO_PLUGIN_URL . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);
	wp_enqueue_style(
		'gleo-admin-style',
		GLEO_PLUGIN_URL . 'build/index.css',
		array(),
		$asset['version']
	);
	wp_localize_script( 'gleo-admin', 'gleoData', array(
		'restUrl'            => rest_url( 'gleo/v1' ),
		'nonce'              => wp_create_nonce( 'wp_rest' ),
		'siteUrl'            => get_site_url(),
		'supabaseConfigured' => ! empty( getenv( 'SUPABASE_SERVICE_ROLE_KEY' ) ),
	) );
} );

// Activation: create DB table
register_activation_hook( __FILE__, function() {
	global $wpdb;
	$table = $wpdb->prefix . 'gleo_scans';
	$charset = $wpdb->get_charset_collate();
	$sql = "CREATE TABLE IF NOT EXISTS {$table} (
		id bigint(20) NOT NULL AUTO_INCREMENT,
		post_id bigint(20) NOT NULL,
		scan_status varchar(50) NOT NULL DEFAULT 'pending',
		scan_result longtext,
		created_at datetime DEFAULT CURRENT_TIMESTAMP,
		updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
		PRIMARY KEY (id),
		KEY post_id (post_id)
	) {$charset};";
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
} );

