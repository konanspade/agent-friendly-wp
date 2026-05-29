<?php
/**
 * Plugin Name:       Agent Friendly
 * Plugin URI:        https://agentfriendly.dev
 * Description:       Make your WordPress site readable by AI agents. Serves LLMs.txt, Markdown Mirror, WebMCP tool discovery, Fluent Forms submission, WooCommerce tools, and rich AI discovery endpoints.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Konan & Spade
 * Author URI:        https://konanspade.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       agent-friendly
 *
 * @package AgentFriendlyWP
 */

defined( 'ABSPATH' ) || exit;

// Prevent conflict if Konan & Spade Helper is active — it already
// serves the same /llms.txt, /md/, and /.well-known/webmcp.json URLs.
if ( defined( 'KS_HELPER_VERSION' ) ) {
	add_action( 'admin_notices', function () {
		echo '<div class="notice notice-warning"><p><strong>Agent Friendly:</strong> '
			. 'Konan &amp; Spade Helper is active and already provides LLMs.txt, Markdown Mirror, and WebMCP. '
			. 'Please deactivate one of the two plugins to avoid conflicts.</p></div>';
	} );
	return;
}

define( 'AFWP_VERSION', '1.0.0' );
define( 'AFWP_FILE', __FILE__ );
define( 'AFWP_PATH', plugin_dir_path( __FILE__ ) );
define( 'AFWP_URL', plugin_dir_url( __FILE__ ) );

// Core framework.
require_once AFWP_PATH . 'includes/class-module.php';
require_once AFWP_PATH . 'includes/class-plugin.php';
require_once AFWP_PATH . 'includes/class-tool-registry.php';
require_once AFWP_PATH . 'includes/class-rate-limiter.php';
require_once AFWP_PATH . 'includes/class-admin-page.php';
require_once AFWP_PATH . 'includes/class-html-to-markdown.php';

// Modules.
require_once AFWP_PATH . 'includes/modules/class-llms-txt-module.php';
require_once AFWP_PATH . 'includes/modules/class-markdown-mirror-module.php';
require_once AFWP_PATH . 'includes/modules/class-webmcp-module.php';
require_once AFWP_PATH . 'includes/modules/class-fluent-forms-module.php';
require_once AFWP_PATH . 'includes/modules/class-woocommerce-module.php';
require_once AFWP_PATH . 'includes/modules/class-discovery-module.php';

register_activation_hook( AFWP_FILE, function () {
	set_transient( 'afwp_flush_rewrite_rules', 1, 60 );
} );

add_action( 'init', function () {
	if ( get_transient( 'afwp_flush_rewrite_rules' ) ) {
		delete_transient( 'afwp_flush_rewrite_rules' );
		flush_rewrite_rules( false );
	}
}, 999 );

add_action( 'plugins_loaded', function () {
	$plugin = \AgentFriendlyWP\Plugin::instance();
	\AgentFriendlyWP\Tool_Registry::instance();

	// Core modules (always registered).
	$plugin->register_module( new \AgentFriendlyWP\Modules\Llms_Txt_Module() );
	$plugin->register_module( new \AgentFriendlyWP\Modules\Markdown_Mirror_Module() );
	$plugin->register_module( new \AgentFriendlyWP\Modules\WebMCP_Module() );

	// Extension modules (always registered — is_enabled() checks dependency).
	$plugin->register_module( new \AgentFriendlyWP\Modules\Fluent_Forms_Module() );
	$plugin->register_module( new \AgentFriendlyWP\Modules\WooCommerce_Module() );
	$plugin->register_module( new \AgentFriendlyWP\Modules\Discovery_Module() );

	$plugin->init();
}, 20 );

// Tool Registry REST routes.
add_action( 'rest_api_init', function () {
	\AgentFriendlyWP\Tool_Registry::instance()->register_routes();
} );

// Developer hook for third-party tool registration.
add_action( 'init', function () {
	do_action( 'afwp_register_tools', \AgentFriendlyWP\Tool_Registry::instance() );
}, 99 );
