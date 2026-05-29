<?php
/**
 * Dedicated admin settings page with vertical tab navigation.
 *
 * @package AgentFriendlyWP
 */

namespace AgentFriendlyWP;

defined( 'ABSPATH' ) || exit;

class Admin_Page {

	const MENU_SLUG  = 'agent-friendly';
	const CAPABILITY = 'manage_options';

	/** @var bool */
	private static $registered = false;

	public static function tab_url( string $tab, array $extra = [] ): string {
		return add_query_arg(
			array_merge( [ 'page' => self::MENU_SLUG, 'tab' => $tab ], $extra ),
			admin_url( 'admin.php' )
		);
	}

	/** @return array<int, array{slug: string, label: string, icon: string, render: callable, module_id?: string}> */
	private static function tabs(): array {
		$tabs = [
			[ 'slug' => 'overview',  'label' => 'Overview',         'icon' => 'dashboard',       'render' => [ self::class, 'render_overview' ] ],
			[ 'slug' => 'llms',      'label' => 'LLMs.txt',         'icon' => 'superhero-alt',   'render' => [ self::class, 'render_module_content' ], 'module_id' => 'llms-txt' ],
			[ 'slug' => 'markdown',  'label' => 'Markdown Mirror',  'icon' => 'media-text',      'render' => [ self::class, 'render_module_content' ], 'module_id' => 'markdown-mirror' ],
			[ 'slug' => 'webmcp',    'label' => 'WebMCP',           'icon' => 'cloud',           'render' => [ self::class, 'render_module_content' ], 'module_id' => 'webmcp' ],
		];

		$plugin = Plugin::instance();

		if ( $plugin->get_module( 'forms' ) ) {
			$tabs[] = [ 'slug' => 'forms',     'label' => 'Forms',            'icon' => 'feedback',        'render' => [ self::class, 'render_module_content' ], 'module_id' => 'forms' ];
		}
		if ( $plugin->get_module( 'woocommerce' ) ) {
			$tabs[] = [ 'slug' => 'commerce',  'label' => 'Commerce',         'icon' => 'cart',            'render' => [ self::class, 'render_module_content' ], 'module_id' => 'woocommerce' ];
		}
		if ( $plugin->get_module( 'discovery' ) ) {
			$tabs[] = [ 'slug' => 'discovery', 'label' => 'Discovery',        'icon' => 'visibility',      'render' => [ self::class, 'render_module_content' ], 'module_id' => 'discovery' ];
		}

		$tabs[] = [ 'slug' => 'settings', 'label' => 'Settings', 'icon' => 'admin-generic', 'render' => [ self::class, 'render_settings' ] ];

		return $tabs;
	}

	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		self::$registered = true;

		// Call add_menu directly — this runs inside an admin_menu callback,
		// so the hook is already firing. Deferring to another admin_menu
		// callback at the same priority is unreliable.
		self::add_menu();
		add_filter( 'parent_file', [ self::class, 'fix_parent_file' ] );
		add_filter( 'submenu_file', [ self::class, 'fix_submenu_file' ], 10, 2 );
	}

	public static function add_menu(): void {
		add_menu_page(
			'Agent Friendly',
			'Agent Friendly',
			self::CAPABILITY,
			self::MENU_SLUG,
			[ self::class, 'render_page' ],
			'dashicons-superhero',
			81
		);

		add_submenu_page(
			self::MENU_SLUG,
			'Overview',
			'Overview',
			self::CAPABILITY,
			self::MENU_SLUG,
			[ self::class, 'render_page' ]
		);

		global $submenu;
		if ( ! isset( $submenu[ self::MENU_SLUG ] ) ) {
			$submenu[ self::MENU_SLUG ] = [];
		}
		foreach ( self::tabs() as $t ) {
			if ( 'overview' === $t['slug'] ) {
				continue;
			}
			$submenu[ self::MENU_SLUG ][] = [
				$t['label'],
				self::CAPABILITY,
				self::tab_url( $t['slug'] ),
			];
		}
	}

	public static function fix_parent_file( string $parent_file ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin menu highlight
		if ( isset( $_GET['page'] ) && self::MENU_SLUG === $_GET['page'] ) {
			return self::MENU_SLUG;
		}
		return $parent_file;
	}

	public static function fix_submenu_file( $submenu_file, $parent_file ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin menu highlight
		if ( self::MENU_SLUG === $parent_file && isset( $_GET['page'] ) && self::MENU_SLUG === $_GET['page'] ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin menu highlight
			$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
			return self::tab_url( $tab );
		}
		return $submenu_file;
	}

	public static function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$tabs       = self::tabs();
		$tab_slugs  = array_column( $tabs, 'slug' );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab routing
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		if ( ! in_array( $active_tab, $tab_slugs, true ) ) {
			$active_tab = 'overview';
		}

		?>
		<div class="wrap afwp-host">
			<h1 class="wp-heading-inline" style="display: flex; align-items: center; gap: 10px;">
				<img src="<?php echo esc_url( AFWP_URL . 'assets/logo.svg' ); ?>" alt="" width="28" height="28" style="vertical-align: middle;">
				Agent Friendly
			</h1>

			<?php settings_errors(); ?>

			<div class="afwp-layout">
				<nav class="afwp-vnav" aria-label="Agent Friendly sections">
					<?php foreach ( $tabs as $t ) :
						$url = self::tab_url( $t['slug'] );
						$cls = $t['slug'] === $active_tab ? 'active' : '';
						?>
						<a href="<?php echo esc_url( $url ); ?>" class="<?php echo esc_attr( $cls ); ?>" data-afwp-tab="<?php echo esc_attr( $t['slug'] ); ?>">
							<span class="dashicons dashicons-<?php echo esc_attr( $t['icon'] ); ?>"></span>
							<span class="afwp-vnav-label"><?php echo esc_html( $t['label'] ); ?></span>
						</a>
					<?php endforeach; ?>
				</nav>

				<?php
				$active = null;
				foreach ( $tabs as $t ) {
					if ( $t['slug'] === $active_tab ) { $active = $t; break; }
				}
				if ( $active ) {
					echo '<section class="afwp-pane">';
					$args = [];
					if ( isset( $active['module_id'] ) ) { $args[] = $active['module_id']; }
					call_user_func_array( $active['render'], $args );
					echo '</section>';
				}
				?>
			</div>
		</div>

		<style>
			.afwp-host .afwp-layout {
				display: flex;
				gap: 20px;
				align-items: flex-start;
				margin-top: 16px;
			}
			.afwp-host .afwp-vnav {
				flex: 0 0 220px;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 6px;
				padding: 6px 0;
				position: sticky;
				top: 46px;
			}
			.afwp-host .afwp-vnav a {
				display: flex;
				align-items: center;
				gap: 10px;
				padding: 10px 16px;
				color: #2c3338;
				text-decoration: none;
				border-left: 3px solid transparent;
				line-height: 1.4;
				font-size: 13px;
				transition: background-color 0.1s ease, color 0.1s ease;
			}
			.afwp-host .afwp-vnav a:hover {
				background: #f0f0f1;
				color: #2271b1;
			}
			.afwp-host .afwp-vnav a.active {
				background: #f6f7f7;
				color: #2271b1;
				border-left-color: #2271b1;
				font-weight: 600;
			}
			.afwp-host .afwp-vnav a .dashicons {
				font-size: 18px;
				width: 18px;
				height: 18px;
				color: inherit;
				flex-shrink: 0;
			}
			.afwp-host .afwp-vnav a:focus {
				outline: 2px solid #2271b1;
				outline-offset: -2px;
			}
			.afwp-host .afwp-pane {
				flex: 1;
				background: #fff;
				border: 1px solid #c3c4c7;
				border-radius: 6px;
				padding: 24px 28px;
				min-width: 0;
				max-width: none;
			}
			.afwp-host .afwp-pane h2 { margin-top: 0; padding-top: 0; }
			.afwp-host .afwp-pane h2:not(:first-child) { margin-top: 28px; border-top: 1px solid #e2e4e7; padding-top: 20px; }
			.afwp-host .afwp-pane .form-table { margin-top: 8px; }
			.afwp-host .afwp-pane .form-table th { width: 220px; padding-left: 0; }
			.afwp-host .afwp-pane .pane-intro { color: #50575e; margin-top: 0; margin-bottom: 16px; max-width: 800px; }
			@media (max-width: 783px) {
				.afwp-host .afwp-layout { flex-direction: column; }
				.afwp-host .afwp-vnav { flex: 0 0 auto; position: static; }
			}
		</style>
		<?php
	}

	public static function render_overview(): void {
		$plugin  = Plugin::instance();
		$modules = $plugin->get_all_modules();

		$dot_on  = '<span style="color:#46b450;">&#x25CF;</span>';
		$dot_off = '<span style="color:#999;">&#x25CB;</span>';

		$llms_txt_url = home_url( '/llms.txt' );
		$md_base_url  = home_url( '/md/' );
		$webmcp_url   = home_url( '/.well-known/webmcp.json' );

		?>
		<div style="margin-bottom: 24px;">
			<p style="font-size: 14px; color: #50575e; margin: 0;">
				Agent Friendly makes your site readable by AI agents. Modules provide
				structured content access, form submission, commerce tools, and rich discovery for LLMs and AI-powered browsers.
			</p>
		</div>

		<h2>Module Status</h2>
		<table class="widefat striped" style="max-width: 800px;">
			<tbody>
				<?php foreach ( $modules as $mod ) :
					$enabled = $mod->is_enabled();
					$reason  = ! $enabled ? $mod->get_disabled_reason() : '';
				?>
				<tr>
					<th style="width: 200px;"><?php echo esc_html( $mod->get_label() ); ?></th>
					<td>
						<?php echo wp_kses( $enabled ? $dot_on . ' Active' : $dot_off . ' Inactive', [ 'span' => [ 'style' => [] ] ] ); ?>
						<?php if ( $reason ) : ?>
							<br><span class="description"><?php echo esc_html( $reason ); ?></span>
						<?php endif; ?>
					</td>
					<td style="color: #646970; font-size: 12px;">v<?php echo esc_html( $mod->get_version() ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php
		$tools_url      = rest_url( Plugin::REST_NAMESPACE . '/tools' );
		$execute_url    = rest_url( Plugin::REST_NAMESPACE . '/tools/execute' );
		$server_card    = home_url( '/.well-known/mcp/server-card.json' );
		$api_catalog    = home_url( '/.well-known/api-catalog' );
		$agent_skills   = home_url( '/.well-known/agent-skills/index.json' );
		?>

		<h2>Quick Links</h2>
		<table class="widefat striped" style="max-width: 800px;">
			<tbody>
				<tr>
					<th style="width: 200px;">llms.txt</th>
					<td><a href="<?php echo esc_url( $llms_txt_url ); ?>" target="_blank"><?php echo esc_html( $llms_txt_url ); ?></a></td>
				</tr>
				<tr>
					<th>Markdown Mirror</th>
					<td><a href="<?php echo esc_url( $md_base_url ); ?>" target="_blank"><?php echo esc_html( $md_base_url ); ?></a></td>
				</tr>
				<tr>
					<th>WebMCP Manifest</th>
					<td><a href="<?php echo esc_url( $webmcp_url ); ?>" target="_blank"><?php echo esc_html( $webmcp_url ); ?></a></td>
				</tr>
				<tr>
					<th>Tool List (REST)</th>
					<td><a href="<?php echo esc_url( $tools_url ); ?>" target="_blank"><?php echo esc_html( $tools_url ); ?></a></td>
				</tr>
				<tr>
					<th>Execute Endpoint</th>
					<td><code><?php echo esc_html( $execute_url ); ?></code> <span style="color:#6b7280;">(POST)</span></td>
				</tr>
				<tr>
					<th>MCP Server Card</th>
					<td><a href="<?php echo esc_url( $server_card ); ?>" target="_blank"><?php echo esc_html( $server_card ); ?></a></td>
				</tr>
				<tr>
					<th>API Catalog</th>
					<td><a href="<?php echo esc_url( $api_catalog ); ?>" target="_blank"><?php echo esc_html( $api_catalog ); ?></a></td>
				</tr>
				<tr>
					<th>Agent Skills</th>
					<td><a href="<?php echo esc_url( $agent_skills ); ?>" target="_blank"><?php echo esc_html( $agent_skills ); ?></a></td>
				</tr>
			</tbody>
		</table>

		<?php
		$registry    = Tool_Registry::instance();
		$tool_count  = count( $registry->get_all() );
		$group_count = count( $registry->get_groups() );
		?>

		<h2>Registered Tools</h2>
		<p class="description">
			<strong><?php echo esc_html( $tool_count ); ?></strong> tools across
			<strong><?php echo esc_html( $group_count ); ?></strong> groups.
			Manage individual tools in each module's tab.
		</p>

		<p style="margin-top: 24px; color: #646970; font-size: 12px;">
			REST namespace: <code><?php echo esc_html( rest_url( Plugin::REST_NAMESPACE ) ); ?>/</code>
			&nbsp;&middot;&nbsp; Plugin v<?php echo esc_html( AFWP_VERSION ); ?>
		</p>
		<?php
	}

	public static function render_module_content( string $module_id ): void {
		$mod = Plugin::instance()->get_module( $module_id );
		if ( ! $mod || ! method_exists( $mod, 'render_content' ) ) {
			echo '<p>' . esc_html( $module_id ) . ' module not available.</p>';
			return;
		}
		$mod->render_content();
	}

	public static function render_settings(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		$rl_max    = (int) get_option( Rate_Limiter::OPTION_MAX, 120 );
		$rl_window = (int) get_option( Rate_Limiter::OPTION_WINDOW, 60 );
		$settings_endpoint = rest_url( Plugin::REST_NAMESPACE . '/settings' );

		$registry = Tool_Registry::instance();
		$groups   = $registry->get_groups();

		?>
		<p class="pane-intro">
			Global settings for rate limiting and the developer tool registry API.
		</p>

		<h2>Rate Limiting</h2>
		<p class="description" style="margin-bottom: 12px;">
			Limits requests to the <code>/tools/execute</code> endpoint to prevent abuse.
			Uses per-IP counters via WordPress transients.
		</p>
		<table class="form-table" style="max-width: 720px;">
			<tr>
				<th scope="row"><label for="afwp-rl-max">Max requests</label></th>
				<td>
					<input type="number" id="afwp-rl-max" value="<?php echo esc_attr( $rl_max ); ?>" min="1" max="10000" style="width: 100px;">
					<span class="description">per time window (default: 120)</span>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="afwp-rl-window">Time window</label></th>
				<td>
					<input type="number" id="afwp-rl-window" value="<?php echo esc_attr( $rl_window ); ?>" min="1" max="3600" style="width: 100px;">
					<span class="description">seconds (default: 60)</span>
				</td>
			</tr>
		</table>

		<p class="submit">
			<button type="button" id="afwp-settings-save" class="button button-primary">Save Settings</button>
			<span id="afwp-settings-status" style="margin-left: 12px;"></span>
		</p>

		<h2>Registered Tool Groups</h2>
		<table class="widefat striped" style="max-width: 720px;">
			<thead>
				<tr>
					<th>Group</th>
					<th>Tools</th>
					<th>Count</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $groups as $group ) :
					$tools = $registry->get_by_group( $group );
					$names = array_keys( $tools );
				?>
				<tr>
					<td><strong><?php echo esc_html( ucfirst( $group ) ); ?></strong></td>
					<td><?php echo wp_kses( implode( ', ', array_map( function( $n ) { return '<code>' . esc_html( $n ) . '</code>'; }, $names ) ), [ 'code' => [] ] ); ?></td>
					<td><?php echo count( $names ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2>Developer API</h2>
		<p class="description" style="max-width: 720px;">
			Third-party plugins can register custom tools using the <code>afwp_register_tools</code> action:
		</p>
		<pre style="background: #f6f7f7; border: 1px solid #dcdcde; border-radius: 4px; padding: 16px; max-width: 720px; font-size: 12px; overflow-x: auto;">add_action( 'afwp_register_tools', function( $registry ) {
    $registry->register( 'my_custom_tool', [
        'description' => 'Does something useful.',
        'group'       => 'custom',
        'inputSchema' => [ 'type' => 'object', 'properties' => [] ],
        'callback'    => function( $input ) {
            return [ 'result' => 'Hello from my tool!' ];
        },
    ] );
} );</pre>

		<script>
		(function() {
			var saveBtn  = document.getElementById('afwp-settings-save');
			var statusEl = document.getElementById('afwp-settings-status');

			saveBtn.addEventListener('click', function() {
				var rlMax    = parseInt(document.getElementById('afwp-rl-max').value, 10) || 120;
				var rlWindow = parseInt(document.getElementById('afwp-rl-window').value, 10) || 60;

				saveBtn.disabled = true;
				statusEl.textContent = 'Saving...';
				statusEl.style.color = '#646970';

				fetch(<?php echo wp_json_encode( rest_url( Plugin::REST_NAMESPACE . '/settings' ) ); ?>, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
					},
					body: JSON.stringify({
						rate_limit_max: rlMax,
						rate_limit_window: rlWindow
					})
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.rate_limit_max !== undefined) {
						statusEl.textContent = 'Saved.';
						statusEl.style.color = '#00a32a';
					} else {
						statusEl.textContent = 'Error: ' + (data.message || 'Unknown');
						statusEl.style.color = '#d63638';
					}
				})
				.catch(function() {
					statusEl.textContent = 'Network error.';
					statusEl.style.color = '#d63638';
				})
				.finally(function() {
					saveBtn.disabled = false;
					setTimeout(function() { statusEl.textContent = ''; }, 4000);
				});
			});
		})();
		</script>
		<?php
	}
}
