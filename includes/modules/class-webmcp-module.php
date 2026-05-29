<?php
/**
 * WebMCP module.
 *
 * Exposes site capabilities as structured tools to AI agents via the
 * Web Model Context Protocol (navigator.modelContext).
 *
 * Each tool's logic lives in a pure `tool_*($input): array` method. The REST
 * handlers delegate to these methods, and the same methods are registered as
 * callbacks with Tool_Registry so the central `/tools/execute` endpoint can
 * invoke them too.
 *
 * @package AgentFriendlyWP\Modules
 * @see     https://webmcp.dev/
 */

namespace AgentFriendlyWP\Modules;

use AgentFriendlyWP\Module;
use AgentFriendlyWP\Plugin;
use AgentFriendlyWP\Admin_Page;
use AgentFriendlyWP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

class WebMCP_Module extends Module {

	const VERSION        = '1.0.0';
	const OPTION_ENABLED = 'afwp_webmcp_enabled';
	const OPTION_TOOLS   = 'afwp_webmcp_tools';

	private static function default_tools(): array {
		return [
			'search_site'      => true,
			'get_page'         => true,
			'get_site_info'    => true,
			'get_navigation'   => true,
			'list_posts'       => true,
			'get_contact_info' => true,
		];
	}

	public function get_id(): string    { return 'webmcp'; }
	public function get_label(): string { return 'WebMCP'; }
	public function get_version(): string { return self::VERSION; }

	public function is_enabled(): bool {
		return (bool) get_option( self::OPTION_ENABLED, true );
	}

	public function get_disabled_reason(): ?string {
		if ( ! $this->is_enabled() ) {
			return 'Disabled in Agent Friendly settings.';
		}
		return null;
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_settings_route' ] );
	}

	public function init(): void {
		$this->register_tools_with_registry();

		add_action( 'init',            [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars',      [ $this, 'add_query_vars' ] );
		add_action( 'parse_request',   [ $this, 'handle_wellknown' ] );
		add_action( 'rest_api_init',   [ $this, 'register_routes' ] );
		add_action( 'admin_menu',      function () { Admin_Page::register(); } );
		add_action( 'wp_head',         [ $this, 'render_link_tags' ], 1 );
		add_action( 'send_headers',    [ $this, 'send_link_headers' ] );
		add_action( 'wp_footer',       [ $this, 'render_bootstrap_script' ], 100 );
		add_filter( 'robots_txt',      [ $this, 'append_robots_txt' ], 110, 2 );
	}

	/* ------------------------------------------------------------------
	 * Enabled tools helper
	 * ---------------------------------------------------------------- */

	private function get_enabled_tools(): array {
		$saved = get_option( self::OPTION_TOOLS, null );
		if ( ! is_array( $saved ) ) {
			return self::default_tools();
		}
		return array_merge( self::default_tools(), $saved );
	}

	private function is_tool_on( string $name ): bool {
		$tools = $this->get_enabled_tools();
		return ! empty( $tools[ $name ] );
	}

	/* ------------------------------------------------------------------
	 * Tool Registry integration
	 * ---------------------------------------------------------------- */

	/**
	 * Register all enabled tools with the central Tool_Registry.
	 */
	private function register_tools_with_registry(): void {
		$registry = Tool_Registry::instance();

		if ( $this->is_tool_on( 'get_site_info' ) ) {
			$registry->register( 'get_site_info', [
				'description' => 'Get basic information about this website: name, description, URL, language, and available content types.',
				'group'       => 'content',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'callback'    => [ $this, 'tool_site_info' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'search_site' ) ) {
			$registry->register( 'search_site', [
				'description' => 'Search this website for pages and posts matching a keyword query. Returns titles, URLs, excerpts, and dates.',
				'group'       => 'content',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'query' => [
							'type'        => 'string',
							'description' => 'The search keyword or phrase.',
						],
						'post_type' => [
							'type'        => 'string',
							'description' => 'Filter by content type. Default: any.',
						],
						'per_page' => [
							'type'        => 'number',
							'description' => 'Number of results (1-20). Default: 10.',
						],
					],
					'required'   => [ 'query' ],
				],
				'callback'    => [ $this, 'tool_search' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'get_page' ) ) {
			$registry->register( 'get_page', [
				'description' => 'Get the full content and metadata of a specific page or post by its URL path or ID.',
				'group'       => 'content',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'path' => [
							'type'        => 'string',
							'description' => 'URL path relative to site root (e.g. "/about/" or "/blog/my-post/").',
						],
						'id' => [
							'type'        => 'number',
							'description' => 'Post/page ID. Use path OR id, not both.',
						],
					],
				],
				'callback'    => [ $this, 'tool_get_page' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'get_navigation' ) ) {
			$registry->register( 'get_navigation', [
				'description' => 'Get the site navigation menu structure with page titles and URLs.',
				'group'       => 'content',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'menu' => [
							'type'        => 'string',
							'description' => 'Menu location slug (e.g. "primary", "footer"). Omit for the primary menu.',
						],
					],
				],
				'callback'    => [ $this, 'tool_navigation' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'list_posts' ) ) {
			$registry->register( 'list_posts', [
				'description' => 'List recent published posts, optionally filtered by category or tag.',
				'group'       => 'content',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'category' => [
							'type'        => 'string',
							'description' => 'Category slug to filter by.',
						],
						'tag' => [
							'type'        => 'string',
							'description' => 'Tag slug to filter by.',
						],
						'per_page' => [
							'type'        => 'number',
							'description' => 'Number of posts (1-20). Default: 10.',
						],
					],
				],
				'callback'    => [ $this, 'tool_list_posts' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'get_contact_info' ) ) {
			$registry->register( 'get_contact_info', [
				'description' => 'Get this site\'s contact information: name, URL, and email.',
				'group'       => 'content',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'callback'    => [ $this, 'tool_contact_info' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}
	}

	/* ------------------------------------------------------------------
	 * Pure tool methods — used by REST handlers and Tool_Registry
	 * ---------------------------------------------------------------- */

	/**
	 * Get basic site information.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public function tool_site_info( array $input ): array {
		$post_types = get_post_types( [ 'public' => true ], 'objects' );
		$types      = [];
		foreach ( $post_types as $pt ) {
			if ( 'attachment' === $pt->name ) {
				continue;
			}
			$count = (int) wp_count_posts( $pt->name )->publish;
			if ( $count > 0 ) {
				$types[] = [
					'slug'  => $pt->name,
					'label' => $pt->label,
					'count' => $count,
				];
			}
		}

		return [
			'name'          => get_bloginfo( 'name' ),
			'description'   => get_bloginfo( 'description' ),
			'url'           => home_url(),
			'language'      => get_bloginfo( 'language' ),
			'content_types' => $types,
			'has_search'    => true,
		];
	}

	/**
	 * Search the site for matching content.
	 *
	 * @param array $input {query, post_type?, per_page?}
	 * @return array
	 */
	public function tool_search( array $input ): array {
		$query    = $input['query'] ?? '';
		$pt       = $input['post_type'] ?? '';
		$per_page = min( 20, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );

		$args = [
			's'              => $query,
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $per_page,
			'orderby'        => 'relevance',
		];

		if ( $pt && post_type_exists( $pt ) ) {
			$pt_obj = get_post_type_object( $pt );
			if ( $pt_obj && $pt_obj->public ) {
				$args['post_type'] = $pt;
			}
		}

		if ( ! isset( $args['post_type'] ) ) {
			$args['post_type'] = array_values( array_diff(
				get_post_types( [ 'public' => true ] ),
				[ 'attachment' ]
			) );
		}

		$wp_query = new \WP_Query( $args );
		$results  = [];

		foreach ( $wp_query->posts as $post ) {
			$results[] = $this->format_post_summary( $post );
		}

		return [
			'query'   => $query,
			'total'   => $wp_query->found_posts,
			'results' => $results,
		];
	}

	/**
	 * Get full content and metadata for a page or post.
	 *
	 * @param array $input {path?, id?}
	 * @return array
	 */
	public function tool_get_page( array $input ): array {
		$path = $input['path'] ?? '';
		$id   = $input['id'] ?? 0;
		$post = null;

		if ( $id ) {
			$post = get_post( (int) $id );
		} elseif ( $path ) {
			$post_id = url_to_postid( home_url( $path ) );
			if ( $post_id ) {
				$post = get_post( $post_id );
			}
			if ( ! $post ) {
				$post = get_page_by_path( trim( $path, '/' ), OBJECT, [ 'page', 'post' ] );
			}
		}

		if ( ! $post || 'publish' !== $post->post_status ) {
			return [ 'error' => 'Page not found.' ];
		}

		if ( ! empty( $post->post_password ) ) {
			return [ 'error' => 'Page not found.' ];
		}

		$pt_obj = get_post_type_object( $post->post_type );
		if ( ! $pt_obj || ! $pt_obj->public ) {
			return [ 'error' => 'Page not found.' ];
		}

		$prev_post       = $GLOBALS['post'] ?? null;
		$GLOBALS['post'] = $post;
		setup_postdata( $post );

		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core filter
		$content = apply_filters( 'the_content', $post->post_content );

		$GLOBALS['post'] = $prev_post;
		if ( $prev_post instanceof \WP_Post ) {
			setup_postdata( $prev_post );
		}

		$content = wp_strip_all_tags( $content );
		$content = preg_replace( '/\s+/', ' ', $content );
		$content = trim( $content );

		// Fallback for page builders (Bricks, Elementor, etc.) that store
		// content in meta rather than post_content. Fetch the rendered page
		// and extract readable text.
		if ( '' === $content ) {
			$content = $this->fetch_rendered_text( get_permalink( $post ) );
		}

		$max_chars = 50000;
		if ( mb_strlen( $content ) > $max_chars ) {
			$content = mb_substr( $content, 0, $max_chars ) . '... [truncated]';
		}

		$data = $this->format_post_summary( $post );
		$data['content']        = $content;
		$data['featured_image'] = get_the_post_thumbnail_url( $post, 'large' ) ?: null;

		$categories = wp_get_post_categories( $post->ID, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
			$data['categories'] = $categories;
		}

		$tags = wp_get_post_tags( $post->ID, [ 'fields' => 'names' ] );
		if ( ! is_wp_error( $tags ) && ! empty( $tags ) ) {
			$data['tags'] = $tags;
		}

		return $data;
	}

	/**
	 * Get site navigation menu structure.
	 *
	 * @param array $input {menu?}
	 * @return array
	 */
	public function tool_navigation( array $input ): array {
		$location  = $input['menu'] ?? '';
		$locations = get_nav_menu_locations();

		if ( '' !== $location && isset( $locations[ $location ] ) ) {
			$menu_id = $locations[ $location ];
		} else {
			$menu_id = ! empty( $locations ) ? reset( $locations ) : 0;
			if ( ! $location ) {
				foreach ( [ 'primary', 'main', 'header', 'main-menu' ] as $try ) {
					if ( isset( $locations[ $try ] ) ) {
						$menu_id  = $locations[ $try ];
						$location = $try;
						break;
					}
				}
			}
		}

		if ( ! $menu_id ) {
			return [
				'menu'      => $location,
				'items'     => [],
				'available' => array_keys( $locations ),
			];
		}

		$items = wp_get_nav_menu_items( $menu_id );
		if ( ! $items ) {
			$items = [];
		}

		$flat = [];
		foreach ( $items as $item ) {
			$flat[] = [
				'id'        => (int) $item->ID,
				'title'     => $item->title,
				'url'       => $item->url,
				'parent_id' => (int) $item->menu_item_parent,
			];
		}

		$tree = $this->build_menu_tree( $flat, 0 );

		return [
			'menu'      => $location ?: 'default',
			'items'     => $tree,
			'available' => array_keys( $locations ),
		];
	}

	/**
	 * List recent published posts.
	 *
	 * @param array $input {category?, tag?, per_page?}
	 * @return array
	 */
	public function tool_list_posts( array $input ): array {
		$category = $input['category'] ?? '';
		$tag      = $input['tag'] ?? '';
		$per_page = min( 20, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );

		$args = [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'has_password'   => false,
			'posts_per_page' => $per_page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		if ( $category ) {
			$args['category_name'] = $category;
		}
		if ( $tag ) {
			$args['tag'] = $tag;
		}

		$wp_query = new \WP_Query( $args );
		$results  = [];

		foreach ( $wp_query->posts as $post ) {
			$results[] = $this->format_post_summary( $post );
		}

		return [
			'total'   => $wp_query->found_posts,
			'results' => $results,
		];
	}

	/**
	 * Get site contact information.
	 *
	 * @param array $input Unused.
	 * @return array
	 */
	public function tool_contact_info( array $input ): array {
		$data = [
			'name' => get_bloginfo( 'name' ),
			'url'  => home_url(),
		];

		$admin_email = get_option( 'admin_email' );
		if ( $admin_email ) {
			$data['email'] = $admin_email;
		}

		return $data;
	}

	/* ------------------------------------------------------------------
	 * Rewrite rules for /.well-known/webmcp.json
	 * ---------------------------------------------------------------- */

	public function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^\.well-known/webmcp\.json$',
			'index.php?afwp_webmcp_manifest=1',
			'top'
		);
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'afwp_webmcp_manifest';
		return $vars;
	}

	/* ------------------------------------------------------------------
	 * /.well-known/webmcp.json handler
	 * ---------------------------------------------------------------- */

	public function handle_wellknown( \WP $wp ): void {
		if ( ! isset( $wp->query_vars['afwp_webmcp_manifest'] ) ) {
			return;
		}

		$manifest = $this->build_manifest();

		status_header( 200 );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600, s-maxage=3600' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Build the WebMCP manifest from Tool_Registry definitions.
	 */
	private function build_manifest(): array {
		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );
		$home      = home_url();

		$manifest = [
			'spec'  => 'webmcp/0.1',
			'site'  => [
				'name'        => $site_name,
				'url'         => $home,
				'description' => $site_desc ?: $site_name,
				'version'     => AFWP_VERSION,
			],
			'tools' => [],
		];

		$registry = Tool_Registry::instance();
		$tool_defs = $registry->get_tool_definitions();

		foreach ( $tool_defs as $tool ) {
			$manifest['tools'][] = [
				'name'        => $tool['name'],
				'description' => $tool['description'],
				'inputSchema' => $tool['inputSchema'] ?? null,
				'annotations' => $tool['annotations'] ?? [ 'readOnlyHint' => true ],
			];
		}

		return $manifest;
	}

	/* ------------------------------------------------------------------
	 * Discovery: <link> tags, Link headers, robots.txt
	 * ---------------------------------------------------------------- */

	public function render_link_tags(): void {
		$manifest_url = home_url( '/.well-known/webmcp.json' );
		echo '<link rel="webmcp" href="' . esc_url( $manifest_url ) . '">' . "\n";
	}

	public function send_link_headers(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || defined( 'REST_REQUEST' ) ) {
			return;
		}
		$manifest_url = home_url( '/.well-known/webmcp.json' );
		header( 'Link: <' . esc_url( $manifest_url ) . '>; rel="webmcp"', false );
	}

	public function append_robots_txt( string $output, bool $public ): string {
		$manifest_url = home_url( '/.well-known/webmcp.json' );
		if ( false === strpos( $output, 'WebMCP:' ) ) {
			$output = rtrim( $output ) . "\n\n# WebMCP tool manifest\nWebMCP: " . $manifest_url . "\n";
		}
		return $output;
	}

	/* ------------------------------------------------------------------
	 * Frontend bootstrap script
	 * ---------------------------------------------------------------- */

	public function render_bootstrap_script(): void {
		if ( is_admin() ) {
			return;
		}

		$registry = Tool_Registry::instance();
		$tools    = $registry->get_tool_definitions();

		if ( empty( $tools ) ) {
			return;
		}

		$execute_url = rest_url( Plugin::REST_NAMESPACE . '/tools/execute' );
		$nonce_url   = rest_url( Plugin::REST_NAMESPACE . '/tools/nonce' );

		$turnstile_site_key = get_option( 'afwp_turnstile_site_key', '' );
		$has_turnstile      = false;
		foreach ( $tools as $t ) {
			if ( ! empty( $t['turnstile'] ) ) {
				$has_turnstile = true;
				break;
			}
		}

		// Prepare tool definitions for JS (includes protected/turnstile flags).
		$tool_defs_for_js = $tools;

		if ( $has_turnstile && $turnstile_site_key ) {
			// phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent -- Cloudflare Turnstile must load from their CDN; it cannot be self-hosted
			wp_enqueue_script( 'afwp-turnstile', 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit', [], null, [ 'strategy' => 'defer', 'in_footer' => true ] );
		}

		?>
<script id="afwp-webmcp-bootstrap">
(function() {
	var EXECUTE = <?php echo wp_json_encode( $execute_url ); ?>;
	var NONCE_URL = <?php echo wp_json_encode( $nonce_url ); ?>;
	var TURNSTILE_KEY = <?php echo wp_json_encode( $turnstile_site_key ); ?>;
	var cachedNonce = '';

	function getNonce() {
		if (cachedNonce) return Promise.resolve(cachedNonce);
		return fetch(NONCE_URL, {headers: {'Accept': 'application/json'}})
			.then(function(r) { return r.json(); })
			.then(function(d) { cachedNonce = d.nonce; return cachedNonce; })
			.catch(function() { return ''; });
	}

	function getTurnstileToken() {
		return new Promise(function(resolve, reject) {
			var container = document.createElement('div');
			container.style.cssText = 'position:fixed;bottom:20px;right:20px;z-index:999999;';
			document.body.appendChild(container);
			try {
				turnstile.render(container, {
					sitekey: TURNSTILE_KEY,
					callback: function(token) {
						if (container.parentNode) container.parentNode.removeChild(container);
						resolve(token);
					},
					'error-callback': function() {
						if (container.parentNode) container.parentNode.removeChild(container);
						reject(new Error('Turnstile verification failed'));
					}
				});
			} catch(e) {
				if (container.parentNode) container.parentNode.removeChild(container);
				reject(e);
			}
		});
	}

	function executeCall(toolName, input, toolDef) {
		var promise = Promise.resolve(input);
		var headers = {'Content-Type': 'application/json', 'Accept': 'application/json'};

		if (toolDef && toolDef.turnstile && TURNSTILE_KEY) {
			promise = promise.then(function(inp) {
				return getTurnstileToken().then(function(token) {
					inp = Object.assign({}, inp, {_turnstile_token: token});
					return inp;
				});
			});
		}

		if (toolDef && toolDef['protected']) {
			promise = promise.then(function(inp) {
				return getNonce().then(function(nonce) {
					if (nonce) headers['X-WP-Nonce'] = nonce;
					return inp;
				});
			});
		}

		return promise.then(function(inp) {
			return fetch(EXECUTE, {
				method: 'POST',
				headers: headers,
				body: JSON.stringify({tool: toolName, input: inp})
			});
		}).then(function(r) {
			if (!r.ok) {
				return r.json().catch(function(){return {};}).then(function(b) {
					return {content: [{type:'text', text: JSON.stringify({error: b.error || r.statusText, status: r.status})}]};
				});
			}
			return r.json();
		}).then(function(data) {
			if (data && data.content) return data;
			return {content: [{type:'text', text: JSON.stringify(data)}]};
		}).catch(function(err) {
			return {content: [{type:'text', text: JSON.stringify({error: err.message || 'Network error'})}]};
		});
	}

	var tools = <?php echo wp_json_encode( $tool_defs_for_js, JSON_UNESCAPED_SLASHES ); ?>;
	var toolMap = {};
	tools.forEach(function(t) { toolMap[t.name] = t; });

	// Try provideContext first (newer WebMCP spec)
	var registered = false;
	if ('modelContext' in navigator) {
		if (typeof navigator.modelContext.provideContext === 'function') {
			try {
				navigator.modelContext.provideContext({
					tools: tools.map(function(def) {
						return {
							name: def.name,
							description: def.description,
							inputSchema: def.inputSchema || undefined,
							annotations: def.annotations || {readOnlyHint: true},
							execute: function(input) { return executeCall(def.name, input || {}, def); }
						};
					})
				});
				registered = true;
			} catch(e) {}
		}

		if (!registered && typeof navigator.modelContext.registerTool === 'function') {
			tools.forEach(function(def) {
				try {
					navigator.modelContext.registerTool({
						name: def.name,
						description: def.description,
						inputSchema: def.inputSchema || undefined,
						annotations: def.annotations || {readOnlyHint: true},
						execute: function(input) { return executeCall(def.name, input || {}, def); }
					});
				} catch(e) {}
			});
			registered = true;
		}
	}

	// Public API fallback
	window.afwpTools = {
		version: <?php echo wp_json_encode( AFWP_VERSION ); ?>,
		execute: function(name, input) { return executeCall(name, input || {}, toolMap[name]); },
		list: function() { return tools.map(function(t) { return t.name; }); },
		tools: toolMap
	};
})();
</script>
		<?php
	}

	/* ------------------------------------------------------------------
	 * REST API endpoints (backward-compatible)
	 * ---------------------------------------------------------------- */

	public function register_settings_route(): void {
		$ns = Plugin::REST_NAMESPACE;

		register_rest_route( $ns, '/webmcp/settings', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_settings' ],
				'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'rest_update_settings' ],
				'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			],
		] );
	}

	public function register_routes(): void {
		$ns = Plugin::REST_NAMESPACE;

		register_rest_route( $ns, '/webmcp/site-info', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_site_info' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/webmcp/search', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_search' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'query'     => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'post_type' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
				'per_page'  => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 20 ],
			],
		] );

		register_rest_route( $ns, '/webmcp/page', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_get_page' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'path' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
				'id'   => [ 'type' => 'integer' ],
			],
		] );

		register_rest_route( $ns, '/webmcp/navigation', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_navigation' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'menu' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
			],
		] );

		register_rest_route( $ns, '/webmcp/posts', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_list_posts' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'category' => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
				'tag'      => [ 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
				'per_page' => [ 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 20 ],
			],
		] );

		register_rest_route( $ns, '/webmcp/contact-info', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_contact_info' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/webmcp/tools', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_tools_list' ],
			'permission_callback' => '__return_true',
		] );
	}

	/* ------------------------------------------------------------------
	 * REST handlers — delegate to tool_*() methods
	 * ---------------------------------------------------------------- */

	public function rest_site_info(): \WP_REST_Response {
		return rest_ensure_response( $this->tool_site_info( [] ) );
	}

	public function rest_search( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->tool_search( [
			'query'     => $request->get_param( 'query' ),
			'post_type' => $request->get_param( 'post_type' ),
			'per_page'  => $request->get_param( 'per_page' ),
		] ) );
	}

	public function rest_get_page( \WP_REST_Request $request ): \WP_REST_Response {
		$result = $this->tool_get_page( [
			'path' => $request->get_param( 'path' ),
			'id'   => $request->get_param( 'id' ),
		] );

		// Preserve 404 status for backward compatibility.
		if ( isset( $result['error'] ) && 'Page not found.' === $result['error'] ) {
			return new \WP_REST_Response( $result, 404 );
		}

		return rest_ensure_response( $result );
	}

	public function rest_navigation( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->tool_navigation( [
			'menu' => $request->get_param( 'menu' ),
		] ) );
	}

	public function rest_list_posts( \WP_REST_Request $request ): \WP_REST_Response {
		return rest_ensure_response( $this->tool_list_posts( [
			'category' => $request->get_param( 'category' ),
			'tag'      => $request->get_param( 'tag' ),
			'per_page' => $request->get_param( 'per_page' ),
		] ) );
	}

	public function rest_contact_info(): \WP_REST_Response {
		return rest_ensure_response( $this->tool_contact_info( [] ) );
	}

	public function rest_tools_list(): \WP_REST_Response {
		$registry = Tool_Registry::instance();
		return rest_ensure_response( [
			'tools' => $registry->get_tool_definitions(),
		] );
	}

	/* ------------------------------------------------------------------
	 * Settings REST endpoints
	 * ---------------------------------------------------------------- */

	public function rest_get_settings(): \WP_REST_Response {
		return rest_ensure_response( [
			'enabled' => (bool) get_option( self::OPTION_ENABLED, true ),
			'tools'   => $this->get_enabled_tools(),
		] );
	}

	public function rest_update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid JSON body.' ], 400 );
		}

		if ( array_key_exists( 'enabled', $params ) ) {
			update_option( self::OPTION_ENABLED, (bool) $params['enabled'], false );
		}

		if ( isset( $params['tools'] ) && is_array( $params['tools'] ) ) {
			$allowed = array_keys( self::default_tools() );
			$clean   = [];
			foreach ( $params['tools'] as $name => $val ) {
				if ( in_array( $name, $allowed, true ) ) {
					$clean[ $name ] = (bool) $val;
				}
			}
			update_option( self::OPTION_TOOLS, $clean, false );
		}

		return $this->rest_get_settings();
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	private function format_post_summary( \WP_Post $post ): array {
		$excerpt = get_the_excerpt( $post );
		if ( ! $excerpt ) {
			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 30, '...' );
		}

		return [
			'id'       => $post->ID,
			'title'    => get_the_title( $post ),
			'url'      => get_permalink( $post ),
			'excerpt'  => $excerpt,
			'type'     => $post->post_type,
			'date'     => get_the_date( 'c', $post ),
			'modified' => get_the_modified_date( 'c', $post ),
			'author'   => get_the_author_meta( 'display_name', $post->post_author ),
		];
	}

	private function build_menu_tree( array $items, int $parent_id ): array {
		$tree = [];
		foreach ( $items as $item ) {
			if ( (int) $item['parent_id'] !== $parent_id ) {
				continue;
			}
			$node = [
				'title' => $item['title'],
				'url'   => $item['url'],
			];
			$children = $this->build_menu_tree( $items, (int) $item['id'] );
			if ( ! empty( $children ) ) {
				$node['children'] = $children;
			}
			$tree[] = $node;
		}
		return $tree;
	}

	/**
	 * Fetch a page's rendered HTML and return plain text. Used as a
	 * fallback when apply_filters('the_content') returns empty (page
	 * builders like Bricks / Elementor store content in meta).
	 */
	private function fetch_rendered_text( string $url ): string {
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $url_host || strcasecmp( $url_host, (string) $home_host ) !== 0 ) {
			return '';
		}

		$response = wp_remote_get( $url, [
			'timeout'    => 15,
			'headers'    => [ 'X-AFWP-Internal' => '1' ],
			'user-agent' => 'AgentFriendlyWP/' . AFWP_VERSION,
		] );

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		$html = wp_remote_retrieve_body( $response );
		if ( '' === trim( $html ) ) {
			return '';
		}

		$use_errors = libxml_use_internal_errors( true );
		$doc        = new \DOMDocument();
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );

		$xpath = new \DOMXPath( $doc );

		// Find main content container.
		$selectors = [
			'//main', '//*[@id="content"]', '//*[@id="primary"]',
			'//*[contains(@class, "entry-content")]',
			'//*[contains(@class, "bricks-layout-wrapper")]',
			'//*[contains(@class, "brxe-content")]',
			'//*[@role="main"]', '//article',
		];

		$node = null;
		foreach ( $selectors as $sel ) {
			$nodes = $xpath->query( $sel );
			if ( $nodes && $nodes->length > 0 ) {
				$node = $nodes->item( 0 );
				break;
			}
		}
		if ( ! $node ) {
			$bodies = $xpath->query( '//body' );
			$node   = ( $bodies && $bodies->length > 0 ) ? $bodies->item( 0 ) : null;
		}
		if ( ! $node ) {
			return '';
		}

		// Strip chrome elements.
		foreach ( [ '//script', '//style', '//nav', '//header', '//footer', '//aside', '//noscript', '//iframe', '//form', '//svg' ] as $sel ) {
			$remove = $xpath->query( $sel, $node );
			if ( $remove ) {
				foreach ( $remove as $r ) {
					if ( $r->parentNode ) {
						$r->parentNode->removeChild( $r );
					}
				}
			}
		}

		$text = $node->textContent;
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		$max_chars = 50000;
		if ( mb_strlen( $text ) > $max_chars ) {
			$text = mb_substr( $text, 0, $max_chars ) . '... [truncated]';
		}

		return $text;
	}

	/* ------------------------------------------------------------------
	 * Admin settings UI
	 * ---------------------------------------------------------------- */

	public function render_content(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$enabled   = (bool) get_option( self::OPTION_ENABLED, true );
		$tools     = $this->get_enabled_tools();
		$manifest  = home_url( '/.well-known/webmcp.json' );
		$rest_base = rest_url( Plugin::REST_NAMESPACE . '/webmcp/tools' );
		$execute_endpoint  = rest_url( Plugin::REST_NAMESPACE . '/tools/execute' );
		$settings_endpoint = rest_url( Plugin::REST_NAMESPACE . '/webmcp/settings' );

		// Get all tools from the registry, grouped by module/group.
		$registry      = Tool_Registry::instance();
		$all_tools     = $registry->get_tool_definitions();
		$groups        = $registry->get_groups();
		$tools_by_group = [];
		foreach ( $all_tools as $tool_def ) {
			$group = $tool_def['group'] ?? 'custom';
			if ( ! isset( $tools_by_group[ $group ] ) ) {
				$tools_by_group[ $group ] = [];
			}
			$tools_by_group[ $group ][] = $tool_def;
		}

		// Labels for built-in tools; other tools use their registry description.
		$tool_labels = [
			'search_site'      => [ 'label' => 'Search Site',      'desc' => 'Full-text search across published content.' ],
			'get_page'         => [ 'label' => 'Get Page',         'desc' => 'Retrieve full page/post content by URL path or ID.' ],
			'get_site_info'    => [ 'label' => 'Site Info',        'desc' => 'Site name, description, language, content types.' ],
			'get_navigation'   => [ 'label' => 'Navigation',      'desc' => 'Menu structure with nested links.' ],
			'list_posts'       => [ 'label' => 'List Posts',       'desc' => 'Recent posts filtered by category or tag.' ],
			'get_contact_info' => [ 'label' => 'Contact Info',     'desc' => 'Business name and contact email.' ],
		];

		$group_labels = [
			'content'     => 'Content',
			'forms'       => 'Forms',
			'woocommerce' => 'WooCommerce',
			'custom'      => 'Custom',
		];

		?>
		<p class="pane-intro">
			Expose your site's content as structured tools for AI agents via the
			<a href="https://webmcp.dev/" target="_blank" rel="noopener">Web Model Context Protocol</a>.
			Chrome 149+ (Origin Trial) and Edge support <code>navigator.modelContext</code>.
		</p>

		<form method="post" id="afwp-webmcp-form">

			<h2>Status</h2>
			<table class="form-table" style="max-width: 720px;">
				<tr>
					<th scope="row">WebMCP</th>
					<td>
						<label>
							<input type="checkbox" id="afwp-webmcp-enabled" <?php checked( $enabled ); ?>>
							Enable WebMCP tool registration on the frontend
						</label>
						<p class="description">
							When enabled, a lightweight <code>&lt;script&gt;</code> registers read-only tools
							with <code>navigator.modelContext</code> so AI agents in Chrome/Edge can interact
							with your site's content.
						</p>
					</td>
				</tr>
			</table>

			<h2>Discovery endpoints</h2>
			<table class="widefat striped" style="max-width: 720px;">
				<tbody>
					<tr>
						<th style="width: 200px;">Manifest</th>
						<td><a href="<?php echo esc_url( $manifest ); ?>" target="_blank"><?php echo esc_html( $manifest ); ?></a></td>
					</tr>
					<tr>
						<th>Tool list (REST)</th>
						<td><a href="<?php echo esc_url( $rest_base ); ?>" target="_blank"><?php echo esc_html( $rest_base ); ?></a></td>
					</tr>
					<tr>
						<th>Execute endpoint</th>
						<td><code><?php echo esc_html( $execute_endpoint ); ?></code></td>
					</tr>
					<tr>
						<th>Link header</th>
						<td><code>Link: &lt;<?php echo esc_html( $manifest ); ?>&gt;; rel="webmcp"</code></td>
					</tr>
					<tr>
						<th>HTML &lt;link&gt;</th>
						<td><code>&lt;link rel="webmcp" href="<?php echo esc_html( $manifest ); ?>"&gt;</code></td>
					</tr>
				</tbody>
			</table>

			<h2>Exposed tools</h2>
			<p class="description" style="margin-bottom: 12px;">
				Toggle which tools AI agents can discover and invoke. All tools are read-only
				and only access published content. Tools from other modules are shown below
				when registered with the Tool Registry.
			</p>

			<?php foreach ( $tools_by_group as $group => $group_tools ) :
				$group_label = $group_labels[ $group ] ?? ucfirst( $group );
			?>
			<h3 style="margin-top: 16px; margin-bottom: 8px;"><?php echo esc_html( $group_label ); ?></h3>
			<table class="widefat striped" style="max-width: 720px;">
				<thead>
					<tr>
						<th style="width: 40px;"></th>
						<th style="width: 180px;">Tool</th>
						<th>Description</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $group_tools as $tool_def ) :
						$name = $tool_def['name'];
						// Built-in tools can be toggled; others are always shown as enabled.
						$is_builtin = isset( $tool_labels[ $name ] );
						$on         = $is_builtin ? ! empty( $tools[ $name ] ) : true;
						$desc       = $is_builtin
							? $tool_labels[ $name ]['desc']
							: $tool_def['description'];
						$label      = $is_builtin
							? $tool_labels[ $name ]['label']
							: $name;
					?>
					<tr>
						<td style="text-align: center;">
							<?php if ( $is_builtin ) : ?>
								<input type="checkbox" class="afwp-webmcp-tool" data-tool="<?php echo esc_attr( $name ); ?>" <?php checked( $on ); ?>>
							<?php else : ?>
								<input type="checkbox" checked disabled title="Managed by its module">
							<?php endif; ?>
						</td>
						<td><code><?php echo esc_html( $name ); ?></code></td>
						<td><?php echo esc_html( $desc ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endforeach; ?>

			<?php if ( empty( $tools_by_group ) ) : ?>
			<table class="widefat striped" style="max-width: 720px;">
				<thead>
					<tr>
						<th style="width: 40px;"></th>
						<th style="width: 180px;">Tool</th>
						<th>Description</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tool_labels as $name => $info ) :
						$on = ! empty( $tools[ $name ] );
					?>
					<tr>
						<td style="text-align: center;">
							<input type="checkbox" class="afwp-webmcp-tool" data-tool="<?php echo esc_attr( $name ); ?>" <?php checked( $on ); ?>>
						</td>
						<td><code><?php echo esc_html( $name ); ?></code></td>
						<td><?php echo esc_html( $info['desc'] ); ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php endif; ?>

			<p class="submit">
				<button type="button" id="afwp-webmcp-save" class="button button-primary">Save WebMCP Settings</button>
				<span id="afwp-webmcp-status" style="margin-left: 12px;"></span>
			</p>
		</form>

		<script>
		(function() {
			var saveBtn   = document.getElementById('afwp-webmcp-save');
			var statusEl  = document.getElementById('afwp-webmcp-status');
			var enabledCb = document.getElementById('afwp-webmcp-enabled');
			var endpoint  = <?php echo wp_json_encode( $settings_endpoint ); ?>;

			saveBtn.addEventListener('click', function() {
				var tools = {};
				document.querySelectorAll('.afwp-webmcp-tool').forEach(function(cb) {
					tools[cb.getAttribute('data-tool')] = cb.checked;
				});

				saveBtn.disabled = true;
				statusEl.textContent = 'Saving...';
				statusEl.style.color = '#646970';

				fetch(endpoint, {
					method: 'PUT',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>
					},
					body: JSON.stringify({
						enabled: enabledCb.checked,
						tools:   tools
					})
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (data.enabled !== undefined) {
						statusEl.textContent = 'Saved.';
						statusEl.style.color = '#00a32a';
					} else {
						statusEl.textContent = 'Error: ' + (data.message || 'Unknown');
						statusEl.style.color = '#d63638';
					}
				})
				.catch(function(e) {
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
