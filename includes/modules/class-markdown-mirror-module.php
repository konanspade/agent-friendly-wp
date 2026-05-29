<?php
/**
 * Markdown Mirror module.
 *
 * Self-hosted markdown mirror: any published page on the site is accessible
 * as clean Markdown at example.com/md/{slug}/.
 *
 * @package AgentFriendlyWP\Modules
 */

namespace AgentFriendlyWP\Modules;

use AgentFriendlyWP\Module;
use AgentFriendlyWP\Plugin;
use AgentFriendlyWP\Admin_Page;
use AgentFriendlyWP\Html_To_Markdown;

defined( 'ABSPATH' ) || exit;

class Markdown_Mirror_Module extends Module {

	const VERSION      = '1.0.0';
	const URL_PREFIX   = 'md';
	const OPTION_FOOTER = 'afwp_md_footer';
	const NONCE_ACTION = 'afwp_md_mirror';

	public function get_id(): string    { return 'markdown-mirror'; }
	public function get_label(): string { return 'Markdown Mirror'; }
	public function get_version(): string { return self::VERSION; }
	public function is_enabled(): bool  { return true; }

	public function init(): void {
		add_action( 'init',          [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars',    [ $this, 'add_query_vars' ] );
		add_action( 'parse_request', [ $this, 'handle_request' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_menu',    function () { Admin_Page::register(); } );
		add_action( 'admin_post_afwp_md_mirror_save', [ $this, 'handle_save_footer' ] );
		add_action( 'post_submitbox_misc_actions', [ $this, 'render_view_md_link' ] );
		add_action( 'wp_head',            [ $this, 'render_md_link_tag' ], 5 );
		add_action( 'template_redirect',  [ $this, 'send_md_link_header' ], 1 );
	}

	/* ------------------------------------------------------------------
	 * Admin UI
	 * ---------------------------------------------------------------- */

	public function render_content(): void {
		if ( ! current_user_can( Admin_Page::CAPABILITY ) ) {
			return;
		}

		$base_url = home_url( '/' . self::URL_PREFIX . '/' );

		$sample_posts = get_posts( [
			'post_type'      => [ 'post', 'page' ],
			'post_status'    => 'publish',
			'posts_per_page' => 5,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		?>
		<p class="pane-intro">
			Every published post and page on this site is automatically available as clean
			Markdown at <code><?php echo esc_html( $base_url ); ?>{slug}/</code>. No configuration needed — the module
			converts rendered HTML (including Gutenberg blocks and shortcodes) to Markdown on the fly.
		</p>

			<h2>How it works</h2>
			<table class="widefat striped" style="max-width: 720px;">
				<tbody>
					<tr>
						<th style="width: 180px;">Public URL pattern</th>
						<td><code><?php echo esc_html( $base_url ); ?>{slug}/</code></td>
					</tr>
					<tr>
						<th>REST endpoint</th>
						<td><code>GET /wp-json/<?php echo esc_html( Plugin::REST_NAMESPACE ); ?>/markdown/{post_id}</code> <span style="color: #6b7280;">(requires authentication)</span></td>
					</tr>
					<tr>
						<th>Output format</th>
						<td>Markdown with YAML front matter (title, date, author, URL)</td>
					</tr>
					<tr>
						<th>Cache</th>
						<td>5 minutes (<code>Cache-Control: public, max-age=300</code>)</td>
					</tr>
					<tr>
						<th>Access</th>
						<td>Published posts only (returns 404 for drafts, private, and non-existent pages)</td>
					</tr>
				</tbody>
			</table>

			<?php if ( ! empty( $sample_posts ) ) : ?>
				<h2 style="margin-top: 24px;">Live examples</h2>
				<p class="description">Click any link to see the Markdown output in your browser.</p>
				<table class="widefat striped" style="max-width: 720px;">
					<thead>
						<tr>
							<th>Title</th>
							<th>Type</th>
							<th>Markdown URL</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sample_posts as $p ) :
							$permalink  = get_permalink( $p );
							$relative   = str_replace( home_url(), '', $permalink );
							$mirror_url = home_url( '/' . self::URL_PREFIX . '/' . ltrim( $relative, '/' ) );
						?>
							<tr>
								<td><?php echo esc_html( get_the_title( $p ) ); ?></td>
								<td><code><?php echo esc_html( $p->post_type ); ?></code></td>
								<td><a href="<?php echo esc_url( $mirror_url ); ?>" target="_blank"><?php echo esc_html( $mirror_url ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2 style="margin-top: 24px;">Usage with LLMs.txt</h2>
			<p style="max-width: 720px;">
				Combine this module with the <a href="<?php echo esc_url( Admin_Page::tab_url( 'llms' ) ); ?>">LLMs.txt module</a>
				to create a complete AI discovery setup: list your key pages in <code>llms.txt</code> with links
				to their <code>/md/</code> equivalents so LLMs can read clean Markdown instead of parsing HTML.
			</p>

			<hr />

			<h2 style="margin-top: 24px;">Footer</h2>
			<p class="description" style="max-width: 720px;">
				Appended to every markdown page as a closing section (after a <code>---</code> separator). Use it for contact details, office locations, and legal links. Markdown formatting supported.
			</p>

			<?php // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display param from redirect
			if ( isset( $_GET['footer_saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Footer saved.</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="afwp_md_mirror_save">
				<textarea name="md_footer" rows="14" class="large-text code" style="max-width: 720px; font-family: monospace;"><?php echo esc_textarea( get_option( self::OPTION_FOOTER, '' ) ); ?></textarea>
				<p class="description">Leave blank to use a default footer (site name + admin email).</p>
				<?php submit_button( 'Save Footer' ); ?>
			</form>

			<div style="background: #eff6ff; border: 1px solid #93c5fd; border-radius: 6px; padding: 16px; max-width: 720px; margin-top: 12px;">
				<h3 style="margin-top: 0;">Troubleshooting</h3>
				<p>
					If <code>/md/{slug}/</code> returns a 404, visit <strong>Settings &rarr; Permalinks</strong> and click
					<strong>Save Changes</strong> (no actual changes needed) to flush the rewrite rules.
					Alternatively, deactivate and reactivate the plugin.
				</p>
			</div>
		<?php
	}

	public function handle_save_footer(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', 'Agent Friendly', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION );

		$footer = isset( $_POST['md_footer'] ) ? sanitize_textarea_field( wp_unslash( $_POST['md_footer'] ) ) : '';
		update_option( self::OPTION_FOOTER, $footer, false );

		wp_safe_redirect( Admin_Page::tab_url( 'markdown', [ 'footer_saved' => '1' ] ) );
		exit;
	}

	public function render_view_md_link( \WP_Post $post ): void {
		if ( 'publish' !== $post->post_status ) {
			return;
		}

		$permalink  = get_permalink( $post );
		$relative   = str_replace( home_url(), '', $permalink );
		$mirror_url = home_url( '/' . self::URL_PREFIX . '/' . ltrim( $relative, '/' ) );
		?>
		<div class="misc-pub-section" style="border-top: 1px solid #f0f0f1; padding: 8px 10px;">
			<span class="dashicons dashicons-media-code" style="color: #50575e; margin-right: 4px; font-size: 16px; vertical-align: text-bottom;"></span>
			<a href="<?php echo esc_url( $mirror_url ); ?>" target="_blank" style="text-decoration: none;">View Markdown</a>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------
	 * Markdown alternate link discovery
	 * ---------------------------------------------------------------- */

	private function get_current_md_url(): string {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return '';
		}

		$request_uri = sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		if ( preg_match( '#^/' . preg_quote( self::URL_PREFIX, '#' ) . '/#', $request_uri ) ) {
			return '';
		}

		if ( is_singular() ) {
			$permalink = get_permalink();
			if ( ! $permalink ) {
				return '';
			}
			$relative = str_replace( home_url(), '', $permalink );
			return home_url( '/' . self::URL_PREFIX . '/' . ltrim( $relative, '/' ) );
		}

		$current_url = ( is_ssl() ? 'https' : 'http' ) . '://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ?? '' ) ) . sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ?? '' ) );
		$path        = wp_parse_url( $current_url, PHP_URL_PATH );
		if ( ! $path || '/' === $path ) {
			return home_url( '/' . self::URL_PREFIX . '/' );
		}
		return home_url( '/' . self::URL_PREFIX . '/' . ltrim( $path, '/' ) );
	}

	public function render_md_link_tag(): void {
		$md_url = $this->get_current_md_url();
		if ( '' === $md_url ) {
			return;
		}
		echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $md_url ) . '" />' . "\n";
	}

	public function send_md_link_header(): void {
		$md_url = $this->get_current_md_url();
		if ( '' === $md_url ) {
			return;
		}
		header( 'Link: <' . esc_url( $md_url ) . '>; rel="alternate"; type="text/markdown"', false );
	}

	/* ------------------------------------------------------------------
	 * Rewrite rules
	 * ---------------------------------------------------------------- */

	public function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^' . self::URL_PREFIX . '/?$',
			'index.php?afwp_md_path=__home__',
			'top'
		);
		add_rewrite_rule(
			'^' . self::URL_PREFIX . '/(.+?)/?$',
			'index.php?afwp_md_path=$matches[1]',
			'top'
		);
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'afwp_md_path';
		return $vars;
	}

	/* ------------------------------------------------------------------
	 * Public mirror request handler
	 * ---------------------------------------------------------------- */

	public function handle_request( \WP $wp ): void {
		if ( ! isset( $wp->query_vars['afwp_md_path'] ) ) {
			return;
		}

		// Re-entry guard: if this request was made by our own fetch_full_html(),
		// bail out so we don't create an infinite self-fetch loop.
		if ( ! empty( $_SERVER['HTTP_X_AFWP_MARKDOWN_MIRROR'] ) ) {
			return;
		}

		$path = sanitize_text_field( $wp->query_vars['afwp_md_path'] );

		if ( '__home__' === $path ) {
			$real = get_page_by_path(
				self::URL_PREFIX,
				OBJECT,
				get_post_types( [ 'public' => true ] )
			);
			if ( $real instanceof \WP_Post && 'publish' === $real->post_status ) {
				$permalink = get_permalink( $real );
				if ( $permalink ) {
					wp_safe_redirect( $permalink, 302 );
					exit;
				}
			}
			$this->serve_markdown( $this->render_archive_as_markdown( '' ) );
			return;
		}

		$post = $this->resolve_post_from_path( $path );

		if ( $post && 'publish' === $post->post_status ) {
			$markdown = $this->render_post_as_markdown( $post );
		} else {
			$markdown = $this->render_archive_as_markdown( $path );
		}

		$this->serve_markdown( $markdown );
	}

	private function serve_markdown( string $markdown ): void {
		if ( '' === $markdown ) {
			nocache_headers();
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo '# 404 — page not found';
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'Cache-Control: public, max-age=300, s-maxage=300' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- text/markdown response, not HTML context
		exit;
	}

	/* ------------------------------------------------------------------
	 * REST endpoints
	 * ---------------------------------------------------------------- */

	public function register_routes(): void {
		register_rest_route(
			Plugin::REST_NAMESPACE,
			'/markdown/(?P<post_id>\d+)',
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_markdown' ],
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'args' => [
					'post_id' => [
						'required'          => true,
						'validate_callback' => function ( $param ) {
							return is_numeric( $param );
						},
					],
				],
			]
		);

		register_rest_route( Plugin::REST_NAMESPACE, '/markdown-mirror', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_settings' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'rest_put_settings' ],
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			],
		] );
	}

	public function rest_get_settings(): \WP_REST_Response {
		return rest_ensure_response( [
			'footer'     => get_option( self::OPTION_FOOTER, '' ),
			'url_prefix' => self::URL_PREFIX,
			'base_url'   => home_url( '/' . self::URL_PREFIX . '/' ),
		] );
	}

	public function rest_put_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid JSON body' ], 400 );
		}

		if ( array_key_exists( 'footer', $params ) ) {
			update_option( self::OPTION_FOOTER, sanitize_textarea_field( $params['footer'] ), false );
		}

		return $this->rest_get_settings();
	}

	public function rest_get_markdown( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = get_post( $post_id );

		if ( ! $post ) {
			return new \WP_REST_Response( [ 'error' => 'Post not found' ], 404 );
		}

		$markdown   = $this->render_post_as_markdown( $post );
		$permalink  = get_permalink( $post );
		$mirror_url = home_url(
			'/' . self::URL_PREFIX . '/' . ltrim( str_replace( home_url(), '', $permalink ), '/' )
		);

		return rest_ensure_response( [
			'post_id'    => $post_id,
			'title'      => get_the_title( $post ),
			'markdown'   => $markdown,
			'url'        => $permalink,
			'mirror_url' => $mirror_url,
		] );
	}

	/* ------------------------------------------------------------------
	 * Post resolution
	 * ---------------------------------------------------------------- */

	private function resolve_post_from_path( string $path ): ?\WP_Post {
		$url     = home_url( '/' . ltrim( $path, '/' ) . '/' );
		$post_id = url_to_postid( $url );
		if ( $post_id ) {
			return get_post( $post_id );
		}

		$page = get_page_by_path( $path );
		if ( $page ) {
			return $page;
		}

		$public_types = get_post_types( [ 'public' => true ], 'names' );
		if ( empty( $public_types ) ) {
			return null;
		}
		$posts = get_posts( [
			'name'           => basename( $path ),
			'post_type'      => array_values( $public_types ),
			'post_status'    => 'publish',
			'posts_per_page' => 1,
		] );

		return $posts[0] ?? null;
	}

	/* ------------------------------------------------------------------
	 * Markdown rendering
	 * ---------------------------------------------------------------- */

	private function render_post_as_markdown( \WP_Post $post ): string {
		$author = get_the_author_meta( 'display_name', $post->post_author );
		$date   = get_the_date( 'Y-m-d', $post );
		$url    = get_permalink( $post );
		$title  = get_the_title( $post );

		$lines = [
			'---',
			'title: "' . str_replace( '"', '\\"', $title ) . '"',
			'date: ' . $date,
			'author: "' . str_replace( '"', '\\"', $author ) . '"',
			'url: ' . $url,
			'---',
			'',
		];

		$html = $this->fetch_rendered_html( $url );

		if ( '' === $html ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WP core filter
			$html = apply_filters( 'the_content', $post->post_content );
		}

		$converter = new Html_To_Markdown();
		$markdown  = $this->clean_markdown( $converter->convert( $html ) );

		$lines[] = $markdown;

		$footer = $this->get_footer();
		if ( '' !== $footer ) {
			$lines[] = '';
			$lines[] = '---';
			$lines[] = '';
			$lines[] = $footer;
		}

		return implode( "\n", $lines );
	}

	private function render_archive_as_markdown( string $path ): string {
		$url      = '' === $path ? home_url( '/' ) : home_url( '/' . ltrim( $path, '/' ) . '/' );
		$raw_html = $this->fetch_full_html( $url );

		if ( '' === $raw_html ) {
			return '';
		}

		$content_html = $this->extract_content( $raw_html );
		if ( '' === trim( $content_html ) ) {
			return '';
		}

		$converter = new Html_To_Markdown();
		$markdown  = $this->clean_markdown( $converter->convert( $content_html ) );

		if ( '' === trim( $markdown ) ) {
			return '';
		}

		$title = $this->extract_title( $raw_html, $path );

		$lines = [
			'---',
			'title: "' . str_replace( '"', '\\"', $title ) . '"',
			'url: ' . $url,
			'type: archive',
			'---',
			'',
			$markdown,
		];

		$footer = $this->get_footer();
		if ( '' !== $footer ) {
			$lines[] = '';
			$lines[] = '---';
			$lines[] = '';
			$lines[] = $footer;
		}

		return implode( "\n", $lines );
	}

	private function fetch_full_html( string $url ): string {
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( ! $url_host || strcasecmp( $url_host, (string) $home_host ) !== 0 ) {
			return '';
		}

		$response = wp_remote_get( $url, [
			'timeout' => 15,
			'headers' => [ 'X-AFWP-Markdown-Mirror' => '1' ],
			'user-agent' => 'AgentFriendlyWP-Markdown-Mirror/' . AFWP_VERSION,
		] );

		if ( is_wp_error( $response ) ) {
			return '';
		}
		if ( 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return '';
		}

		return wp_remote_retrieve_body( $response );
	}

	private function extract_title( string $html, string $path ): string {
		if ( preg_match( '/<title[^>]*>([^<]+)<\/title>/i', $html, $m ) ) {
			$raw = html_entity_decode( trim( $m[1] ), ENT_QUOTES, 'UTF-8' );
			$raw = preg_replace( '/\s*[\|–—-]\s*[^|–—-]+$/', '', $raw );
			return trim( $raw );
		}
		return ucwords( str_replace( [ '-', '/' ], [ ' ', ' ' ], trim( $path, '/' ) ) );
	}

	private function clean_markdown( string $md ): string {
		$patterns = [
			'/^!\[[^\]]*\]\(data:image\/svg\+xml[^)]*\)\s*$/mi',
			'/^!\[[^\]]*\]\(\s*\)\s*$/mi',
			'/^.*(?:enter(?:ing)?\s+(?:your\s+)?(?:the\s+)?email|subscribe|sign\s*up|join\s+our|get\s+(?:our\s+)?newsletter|opt[\s-]?in).*(?:privacy\s*(?:policy|statement)|terms).*$/mi',
			'/^.*(?:by\s+(?:entering|submitting|providing|sharing)\s+(?:your\s+)?(?:the\s+)?email).*$/mi',
			'/^.*(?:we\s+(?:will\s+)?(?:never|won\'t)\s+(?:share|spam|sell)\s+your\s+email).*$/mi',
			'/^.*(?:unsubscribe\s+(?:at\s+)?any\s*time).*$/mi',
		];
		foreach ( $patterns as $pattern ) {
			$md = preg_replace( $pattern, '', $md );
		}
		$md = preg_replace( "/\n{3,}/", "\n\n", $md );
		return trim( $md );
	}

	private function get_footer(): string {
		$stored = get_option( self::OPTION_FOOTER, '' );
		if ( '' !== $stored ) {
			return $stored;
		}
		return $this->get_default_footer();
	}

	private function get_default_footer(): string {
		$name = get_bloginfo( 'name' );
		return "## {$name}\n\n"
			. "- Website: " . home_url() . "\n"
			. "- Contact: " . get_option( 'admin_email' ) . "\n";
	}

	private function fetch_rendered_html( string $url ): string {
		$body = $this->fetch_full_html( $url );
		if ( '' === trim( $body ) ) {
			return '';
		}
		return $this->extract_content( $body );
	}

	private function extract_content( string $html ): string {
		$use_errors = libxml_use_internal_errors( true );
		$doc        = new \DOMDocument();
		$doc->loadHTML(
			'<?xml encoding="utf-8" ?>' . $html,
			LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NOERROR
		);
		libxml_clear_errors();
		libxml_use_internal_errors( $use_errors );

		$xpath = new \DOMXPath( $doc );

		$selectors = [
			'//main',
			'//*[@id="content"]',
			'//*[@id="main-content"]',
			'//*[@id="primary"]',
			'//*[contains(@class, "entry-content")]',
			'//*[contains(@class, "page-content")]',
			'//*[contains(@class, "post-content")]',
			'//*[contains(@class, "site-content")]',
			'//*[contains(@class, "brxe-content")]',
			'//*[contains(@class, "bricks-layout-wrapper")]',
			'//*[@role="main"]',
			'//article',
		];

		$content_node = null;
		foreach ( $selectors as $selector ) {
			$nodes = $xpath->query( $selector );
			if ( $nodes && $nodes->length > 0 ) {
				$content_node = $nodes->item( 0 );
				break;
			}
		}

		if ( ! $content_node ) {
			$bodies = $xpath->query( '//body' );
			if ( ! $bodies || 0 === $bodies->length ) {
				return $html;
			}
			$content_node = $bodies->item( 0 );
		}

		$remove_selectors = [
			'//header', '//footer', '//nav', '//aside',
			'//*[contains(@class, "sidebar")]',
			'//*[contains(@class, "site-header")]',
			'//*[contains(@class, "site-footer")]',
			'//*[contains(@class, "nav")]',
			'//*[contains(@class, "menu")]',
			'//*[contains(@class, "breadcrumb")]',
			'//*[contains(@class, "cookie")]',
			'//*[contains(@class, "popup")]',
			'//*[contains(@class, "modal")]',
			'//*[contains(@class, "overlay")]',
			'//*[contains(@class, "widget")]',
			'//*[contains(@class, "share")]',
			'//*[contains(@class, "social")]',
			'//*[contains(@class, "related-posts")]',
			'//*[contains(@class, "comments")]',
			'//*[@id="comments"]',
			'//*[contains(@class, "newsletter")]',
			'//*[contains(@class, "subscribe")]',
			'//*[contains(@class, "signup")]',
			'//*[contains(@class, "sign-up")]',
			'//*[contains(@class, "optin")]',
			'//*[contains(@class, "opt-in")]',
			'//*[contains(@class, "mailchimp")]',
			'//*[contains(@class, "mc4wp")]',
			'//*[contains(@class, "wpforms")]',
			'//*[contains(@class, "gform")]',
			'//*[contains(@class, "email-form")]',
			'//*[contains(@class, "cta-banner")]',
			'//*[@role="navigation"]',
			'//*[@role="banner"]',
			'//*[@role="contentinfo"]',
			'//*[@role="complementary"]',
			'//script', '//style', '//noscript',
			'//iframe', '//form', '//svg',
		];

		foreach ( $remove_selectors as $sel ) {
			$nodes = $xpath->query( $sel, $content_node );
			if ( $nodes ) {
				foreach ( $nodes as $node ) {
					if ( $node->parentNode ) {
						$node->parentNode->removeChild( $node );
					}
				}
			}
		}

		return $this->node_inner_html( $content_node, $doc );
	}

	private function node_inner_html( \DOMNode $node, \DOMDocument $doc ): string {
		$inner = '';
		foreach ( $node->childNodes as $child ) {
			$inner .= $doc->saveHTML( $child );
		}
		return trim( $inner );
	}
}
