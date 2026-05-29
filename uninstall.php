<?php
/**
 * Uninstall cleanup for Agent Friendly.
 *
 * Runs when the plugin is deleted from wp-admin.
 *
 * @package AgentFriendlyWP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// LLMs.txt module options.
delete_option( 'afwp_llms_txt' );
delete_option( 'afwp_llms_full_txt' );

// Markdown Mirror module options.
delete_option( 'afwp_md_footer' );

// WebMCP module options.
delete_option( 'afwp_webmcp_enabled' );
delete_option( 'afwp_webmcp_tools' );

// Fluent Forms module options.
delete_option( 'afwp_forms_enabled' );
delete_option( 'afwp_turnstile_enabled' );
delete_option( 'afwp_turnstile_site_key' );
delete_option( 'afwp_turnstile_secret_key' );

// WooCommerce module options.
delete_option( 'afwp_woo_enabled' );
delete_option( 'afwp_woo_tools' );

// Rate limiter options.
delete_option( 'afwp_rate_limit_max' );
delete_option( 'afwp_rate_limit_window' );

// Clean up rate limiter transients.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cleanup of plugin transients on uninstall
$wpdb->query(
	"DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_afwp_rl_%' OR option_name LIKE '_transient_timeout_afwp_rl_%'"
);

// Activation transient.
delete_transient( 'afwp_flush_rewrite_rules' );

// Flush rewrite rules so our custom rules are removed.
flush_rewrite_rules( false );
