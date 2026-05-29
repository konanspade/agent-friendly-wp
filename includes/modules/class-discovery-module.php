<?php
/**
 * Discovery module.
 *
 * Serves rich discovery endpoints for AI agents including MCP Server Card,
 * RFC 9727 API Catalog, and Agent Skills Index. Injects Link headers and
 * <link> tags to make discovery automatic.
 *
 * @package AgentFriendlyWP\Modules
 */

namespace AgentFriendlyWP\Modules;

use AgentFriendlyWP\Module;
use AgentFriendlyWP\Plugin;
use AgentFriendlyWP\Admin_Page;
use AgentFriendlyWP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

class Discovery_Module extends Module {

	const VERSION = '1.0.0';

	public function get_id(): string      { return 'discovery'; }
	public function get_label(): string   { return 'Discovery'; }
	public function get_version(): string { return self::VERSION; }
	public function is_enabled(): bool    { return true; }

	public function init(): void {
		add_action( 'init',          [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars',    [ $this, 'add_query_vars' ] );
		add_action( 'parse_request', [ $this, 'handle_wellknown' ] );
		add_action( 'wp_head',       [ $this, 'render_link_tags' ], 2 );
		add_action( 'send_headers',  [ $this, 'send_link_headers' ] );
		add_action( 'admin_menu',    function () { Admin_Page::register(); } );
	}

	/* ------------------------------------------------------------------
	 * Rewrite rules
	 * ---------------------------------------------------------------- */

	public function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^\.well-known/mcp/server-card\.json$',
			'index.php?afwp_discovery=server-card',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/api-catalog$',
			'index.php?afwp_discovery=api-catalog',
			'top'
		);
		add_rewrite_rule(
			'^\.well-known/agent-skills/index\.json$',
			'index.php?afwp_discovery=agent-skills',
			'top'
		);
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'afwp_discovery';
		return $vars;
	}

	/* ------------------------------------------------------------------
	 * Well-known request handler
	 * ---------------------------------------------------------------- */

	public function handle_wellknown( \WP $wp ): void {
		if ( ! isset( $wp->query_vars['afwp_discovery'] ) ) {
			return;
		}

		$variant = $wp->query_vars['afwp_discovery'];

		switch ( $variant ) {
			case 'server-card':
				$this->serve_server_card();
				break;
			case 'api-catalog':
				$this->serve_api_catalog();
				break;
			case 'agent-skills':
				$this->serve_agent_skills();
				break;
			default:
				status_header( 404 );
				header( 'Content-Type: application/json; charset=utf-8' );
				echo wp_json_encode( [ 'error' => 'Unknown discovery endpoint.' ] );
				exit;
		}
	}

	/**
	 * MCP Server Card: /.well-known/mcp/server-card.json
	 */
	private function serve_server_card(): void {
		$card = [
			'name'             => get_bloginfo( 'name' ),
			'description'      => get_bloginfo( 'description' ),
			'url'              => home_url(),
			'version'          => AFWP_VERSION,
			'protocol'         => 'webmcp/0.1',
			'tools_endpoint'   => rest_url( Plugin::REST_NAMESPACE . '/tools' ),
			'execute_endpoint' => rest_url( Plugin::REST_NAMESPACE . '/tools/execute' ),
			'manifest_url'     => home_url( '/.well-known/webmcp.json' ),
			'capabilities'     => [
				'tools'         => true,
				'rate_limiting' => true,
			],
		];

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600, s-maxage=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * RFC 9727 API Catalog: /.well-known/api-catalog
	 */
	private function serve_api_catalog(): void {
		$catalog = [
			'linkset' => [
				[
					'anchor'           => home_url( '/' ),
					'service-desc'     => [
						[
							'href' => rest_url( Plugin::REST_NAMESPACE ),
							'type' => 'application/json',
						],
					],
					'webmcp-manifest'  => [
						[
							'href' => home_url( '/.well-known/webmcp.json' ),
							'type' => 'application/json',
						],
					],
					'mcp-server-card'  => [
						[
							'href' => home_url( '/.well-known/mcp/server-card.json' ),
							'type' => 'application/json',
						],
					],
				],
			],
		];

		status_header( 200 );
		header( 'Content-Type: application/linkset+json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600, s-maxage=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Agent Skills Index: /.well-known/agent-skills/index.json
	 */
	private function serve_agent_skills(): void {
		$registry = Tool_Registry::instance();
		$groups   = $registry->get_groups();
		$skills   = [];

		foreach ( $groups as $group ) {
			$group_tools = $registry->get_by_group( $group );
			if ( empty( $group_tools ) ) {
				continue;
			}

			$skills[] = [
				'name'        => $group,
				'description' => 'Tools for ' . $group,
				'tools'       => array_keys( $group_tools ),
			];
		}

		$index = [
			'version' => '1.0',
			'site'    => [
				'name'        => get_bloginfo( 'name' ),
				'url'         => home_url(),
				'description' => get_bloginfo( 'description' ),
			],
			'skills'  => $skills,
		];

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600, s-maxage=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Discovery: <link> tags
	 * ---------------------------------------------------------------- */

	public function render_link_tags(): void {
		$server_card  = home_url( '/.well-known/mcp/server-card.json' );
		$api_catalog  = home_url( '/.well-known/api-catalog' );
		$agent_skills = home_url( '/.well-known/agent-skills/index.json' );

		echo '<link rel="mcp-server-card" href="' . esc_url( $server_card ) . '">' . "\n";
		echo '<link rel="api-catalog" href="' . esc_url( $api_catalog ) . '">' . "\n";
		echo '<link rel="agent-skills" href="' . esc_url( $agent_skills ) . '">' . "\n";
	}

	/* ------------------------------------------------------------------
	 * Discovery: Link headers
	 * ---------------------------------------------------------------- */

	public function send_link_headers(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
			return;
		}

		$server_card  = home_url( '/.well-known/mcp/server-card.json' );
		$api_catalog  = home_url( '/.well-known/api-catalog' );
		$agent_skills = home_url( '/.well-known/agent-skills/index.json' );

		header( 'Link: <' . esc_url( $server_card ) . '>; rel="mcp-server-card"; type="application/json"', false );
		header( 'Link: <' . esc_url( $api_catalog ) . '>; rel="api-catalog"; type="application/linkset+json"', false );
		header( 'Link: <' . esc_url( $agent_skills ) . '>; rel="agent-skills"; type="application/json"', false );
	}

	/* ------------------------------------------------------------------
	 * Admin UI
	 * ---------------------------------------------------------------- */

	public function render_content(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$home = home_url();

		$endpoints = [
			[
				'label'       => '/.well-known/webmcp.json',
				'url'         => home_url( '/.well-known/webmcp.json' ),
				'description' => 'WebMCP manifest — lists all available tools with schemas and annotations.',
			],
			[
				'label'       => '/.well-known/mcp/server-card.json',
				'url'         => home_url( '/.well-known/mcp/server-card.json' ),
				'description' => 'MCP Server Card — site identity, protocol version, and endpoint URLs.',
			],
			[
				'label'       => '/.well-known/api-catalog',
				'url'         => home_url( '/.well-known/api-catalog' ),
				'description' => 'RFC 9727 API Catalog — linkset document pointing to all API descriptions.',
			],
			[
				'label'       => '/.well-known/agent-skills/index.json',
				'url'         => home_url( '/.well-known/agent-skills/index.json' ),
				'description' => 'Agent Skills Index — grouped listing of all available tool capabilities.',
			],
			[
				'label'       => '/wp-json/' . Plugin::REST_NAMESPACE . '/tools',
				'url'         => rest_url( Plugin::REST_NAMESPACE . '/tools' ),
				'description' => 'Tool list — all registered tools with schemas, annotations, and protection status.',
			],
			[
				'label'       => '/wp-json/' . Plugin::REST_NAMESPACE . '/tools/execute',
				'url'         => rest_url( Plugin::REST_NAMESPACE . '/tools/execute' ),
				'description' => 'Execute endpoint — invoke any registered tool by name with input parameters.',
			],
			[
				'label'       => '/wp-json/' . Plugin::REST_NAMESPACE . '/tools/nonce',
				'url'         => rest_url( Plugin::REST_NAMESPACE . '/tools/nonce' ),
				'description' => 'Nonce endpoint — obtain a WP REST nonce for protected tool calls (requires login).',
			],
		];

		$server_card_url  = home_url( '/.well-known/mcp/server-card.json' );
		$api_catalog_url  = home_url( '/.well-known/api-catalog' );
		$agent_skills_url = home_url( '/.well-known/agent-skills/index.json' );
		$webmcp_url       = home_url( '/.well-known/webmcp.json' );

		?>
		<p class="pane-intro">
			AI discovery endpoints help agents automatically find and understand your site's capabilities.
			These follow emerging standards for machine-readable service description, including
			the MCP Server Card, RFC 9727 API Catalog, and Agent Skills Index.
		</p>

		<h2>Discovery Endpoints</h2>
		<table class="widefat striped" style="max-width: 860px;">
			<thead>
				<tr>
					<th style="width: 320px;">Endpoint</th>
					<th>Description</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $endpoints as $ep ) : ?>
					<tr>
						<td>
							<a href="<?php echo esc_url( $ep['url'] ); ?>" target="_blank">
								<code><?php echo esc_html( $ep['label'] ); ?></code>
							</a>
						</td>
						<td><?php echo esc_html( $ep['description'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Link Headers</h2>
		<p class="description" style="margin-bottom: 12px;">
			The following HTTP <code>Link</code> headers are sent on every frontend page response,
			allowing agents to discover capabilities from any page.
		</p>
		<table class="widefat striped" style="max-width: 860px;">
			<thead>
				<tr>
					<th style="width: 160px;">Rel</th>
					<th>Header Value</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>webmcp</code></td>
					<td><code>Link: &lt;<?php echo esc_html( $webmcp_url ); ?>&gt;; rel="webmcp"</code></td>
				</tr>
				<tr>
					<td><code>mcp-server-card</code></td>
					<td><code>Link: &lt;<?php echo esc_html( $server_card_url ); ?>&gt;; rel="mcp-server-card"; type="application/json"</code></td>
				</tr>
				<tr>
					<td><code>api-catalog</code></td>
					<td><code>Link: &lt;<?php echo esc_html( $api_catalog_url ); ?>&gt;; rel="api-catalog"; type="application/linkset+json"</code></td>
				</tr>
				<tr>
					<td><code>agent-skills</code></td>
					<td><code>Link: &lt;<?php echo esc_html( $agent_skills_url ); ?>&gt;; rel="agent-skills"; type="application/json"</code></td>
				</tr>
			</tbody>
		</table>

		<h2>HTML Link Tags</h2>
		<p class="description" style="margin-bottom: 12px;">
			The following <code>&lt;link&gt;</code> tags are injected into the <code>&lt;head&gt;</code>
			of every frontend page for in-document discovery.
		</p>
		<table class="widefat striped" style="max-width: 860px;">
			<thead>
				<tr>
					<th style="width: 160px;">Rel</th>
					<th>Tag</th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td><code>webmcp</code></td>
					<td><code>&lt;link rel="webmcp" href="<?php echo esc_html( $webmcp_url ); ?>"&gt;</code></td>
				</tr>
				<tr>
					<td><code>mcp-server-card</code></td>
					<td><code>&lt;link rel="mcp-server-card" href="<?php echo esc_html( $server_card_url ); ?>"&gt;</code></td>
				</tr>
				<tr>
					<td><code>api-catalog</code></td>
					<td><code>&lt;link rel="api-catalog" href="<?php echo esc_html( $api_catalog_url ); ?>"&gt;</code></td>
				</tr>
				<tr>
					<td><code>agent-skills</code></td>
					<td><code>&lt;link rel="agent-skills" href="<?php echo esc_html( $agent_skills_url ); ?>"&gt;</code></td>
				</tr>
			</tbody>
		</table>

		<p style="margin-top: 24px; color: #646970; font-size: 12px;">
			Discovery module is always active. All endpoints are publicly accessible and cached for 1 hour.
		</p>
		<?php
	}
}
