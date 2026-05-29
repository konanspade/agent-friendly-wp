<?php
/**
 * LLMs.txt module.
 *
 * Serves llms.txt and llms-full.txt at the site root for AI/LLM discovery.
 *
 * @package AgentFriendlyWP\Modules
 * @see     https://llmstxt.org/
 */

namespace AgentFriendlyWP\Modules;

use AgentFriendlyWP\Module;
use AgentFriendlyWP\Plugin;
use AgentFriendlyWP\Admin_Page;

defined( 'ABSPATH' ) || exit;

class Llms_Txt_Module extends Module {

	const VERSION              = '1.0.0';
	const OPTION_LLMS_TXT      = 'afwp_llms_txt';
	const OPTION_LLMS_FULL_TXT = 'afwp_llms_full_txt';
	const NONCE_ACTION         = 'afwp_llms_txt';

	public function get_id(): string    { return 'llms-txt'; }
	public function get_label(): string { return 'LLMs.txt'; }
	public function get_version(): string { return self::VERSION; }
	public function is_enabled(): bool  { return true; }

	public function init(): void {
		add_action( 'init',          [ $this, 'register_rewrite_rules' ] );
		add_filter( 'query_vars',    [ $this, 'add_query_vars' ] );
		add_action( 'parse_request', [ $this, 'handle_request' ] );
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'admin_menu',    function () { Admin_Page::register(); } );
		add_action( 'admin_post_afwp_llms_txt_save',     [ $this, 'handle_save' ] );
		add_action( 'admin_post_afwp_llms_txt_import',   [ $this, 'handle_import' ] );
		add_action( 'admin_post_afwp_llms_txt_generate', [ $this, 'handle_generate' ] );
		add_filter( 'robots_txt',    [ $this, 'append_robots_txt' ], 100, 2 );
	}

	/* ------------------------------------------------------------------
	 * Rewrite rules
	 * ---------------------------------------------------------------- */

	public function register_rewrite_rules(): void {
		add_rewrite_rule( '^llms\.txt$',                    'index.php?afwp_llms_txt=1',    'top' );
		add_rewrite_rule( '^llms-full\.txt$',               'index.php?afwp_llms_txt=full', 'top' );
		add_rewrite_rule( '^\.well-known/llms\.txt$',       'index.php?afwp_llms_txt=1',    'top' );
		add_rewrite_rule( '^\.well-known/llms-full\.txt$',  'index.php?afwp_llms_txt=full', 'top' );
	}

	public function add_query_vars( array $vars ): array {
		$vars[] = 'afwp_llms_txt';
		return $vars;
	}

	/* ------------------------------------------------------------------
	 * Request handler
	 * ---------------------------------------------------------------- */

	public function handle_request( \WP $wp ): void {
		if ( ! isset( $wp->query_vars['afwp_llms_txt'] ) ) {
			return;
		}

		$variant    = $wp->query_vars['afwp_llms_txt'];
		$option_key = ( 'full' === $variant )
			? self::OPTION_LLMS_FULL_TXT
			: self::OPTION_LLMS_TXT;

		$content = get_option( $option_key, '' );

		if ( '' === $content ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			$label = ( 'full' === $variant ) ? 'llms-full' : 'llms';
			echo '# No ' . esc_html( $label ) . '.txt configured.';
			exit;
		}

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'Cache-Control: public, max-age=3600, s-maxage=3600' );
		echo $content; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text response, not HTML context
		exit;
	}

	/* ------------------------------------------------------------------
	 * robots.txt
	 * ---------------------------------------------------------------- */

	public function append_robots_txt( string $output, bool $public ): string {
		$content = get_option( self::OPTION_LLMS_TXT, '' );
		if ( '' === $content ) {
			return $output;
		}
		$llms_url = home_url( '/llms.txt' );
		if ( false === strpos( $output, 'LLMs-Txt:' ) ) {
			$output = rtrim( $output ) . "\n\n# LLM-readable content\nLLMs-Txt: " . $llms_url . "\n";
		}
		return $output;
	}

	/* ------------------------------------------------------------------
	 * Admin form handlers
	 * ---------------------------------------------------------------- */

	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', 'Agent Friendly', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION );

		$content      = isset( $_POST['llms_txt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['llms_txt'] ) ) : '';
		$content_full = isset( $_POST['llms_full_txt'] ) ? sanitize_textarea_field( wp_unslash( $_POST['llms_full_txt'] ) ) : '';

		$content      = $this->rewrite_links_to_md( $content );
		$content_full = $this->rewrite_links_to_md( $content_full );

		$this->save_content( $content, $content_full );

		wp_safe_redirect( Admin_Page::tab_url( 'llms', [ 'updated' => '1' ] ) );
		exit;
	}

	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', 'Agent Friendly', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION . '_import' );

		$physical  = $this->detect_physical_files();
		$imported  = [];

		if ( $physical['has_file'] && '' !== $physical['content'] ) {
			update_option( self::OPTION_LLMS_TXT, $physical['content'], false );
			$imported[] = 'llms.txt';
		}
		if ( $physical['has_full'] && '' !== $physical['content_full'] ) {
			update_option( self::OPTION_LLMS_FULL_TXT, $physical['content_full'], false );
			$imported[] = 'llms-full.txt';
		}

		$msg = empty( $imported )
			? 'No physical files found to import.'
			: 'Imported: ' . implode( ', ', $imported );

		wp_safe_redirect( Admin_Page::tab_url( 'llms', [
			'afwp_notice'  => empty( $imported ) ? 'error' : 'success',
			'afwp_message' => $msg,
		] ) );
		exit;
	}

	public function handle_generate(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions.', 'Agent Friendly', [ 'response' => 403 ] );
		}
		check_admin_referer( self::NONCE_ACTION . '_generate' );

		$site_name = get_bloginfo( 'name' );
		$site_desc = get_bloginfo( 'description' );
		$base      = home_url();
		$md_base   = home_url( '/md' );

		$lines = [
			'# ' . $site_name,
			'',
			'> ' . ( $site_desc ?: 'No description set.' ),
			'',
		];

		$pages = get_posts( [
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'posts_per_page' => 50,
			'orderby'        => 'menu_order',
			'order'          => 'ASC',
		] );

		if ( ! empty( $pages ) ) {
			$lines[] = '## Pages';
			$lines[] = '';
			foreach ( $pages as $p ) {
				$permalink = get_permalink( $p );
				$relative  = str_replace( $base, '', $permalink );
				$md_url    = $md_base . '/' . ltrim( $relative, '/' );
				$lines[]   = '- [' . get_the_title( $p ) . '](' . $md_url . ')';
			}
			$lines[] = '';
		}

		$posts = get_posts( [
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 20,
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		if ( ! empty( $posts ) ) {
			$lines[] = '## Recent Posts';
			$lines[] = '';
			foreach ( $posts as $p ) {
				$permalink = get_permalink( $p );
				$relative  = str_replace( $base, '', $permalink );
				$md_url    = $md_base . '/' . ltrim( $relative, '/' );
				$lines[]   = '- [' . get_the_title( $p ) . '](' . $md_url . ')';
			}
			$lines[] = '';
		}

		$content = implode( "\n", $lines );
		$this->save_content( $content, null );

		wp_safe_redirect( Admin_Page::tab_url( 'llms', [
			'afwp_notice'  => 'success',
			'afwp_message' => 'Generated llms.txt from ' . count( $pages ) . ' pages and ' . count( $posts ) . ' posts.',
		] ) );
		exit;
	}

	/* ------------------------------------------------------------------
	 * Admin UI
	 * ---------------------------------------------------------------- */

	public function render_content(): void {
		if ( ! current_user_can( Admin_Page::CAPABILITY ) ) {
			return;
		}

		$physical     = $this->detect_physical_files();
		$content      = $physical['has_file'] ? $physical['content'] : get_option( self::OPTION_LLMS_TXT, '' );
		$content_full = $physical['has_full'] ? $physical['content_full'] : get_option( self::OPTION_LLMS_FULL_TXT, '' );
		$url          = home_url( '/llms.txt' );
		$url_full     = home_url( '/llms-full.txt' );
		$uses_files   = $physical['has_file'] || $physical['has_full'];

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display param from redirect
		if ( isset( $_GET['updated'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>LLMs.txt content saved.</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display param from redirect
		if ( isset( $_GET['afwp_notice'], $_GET['afwp_message'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display param from redirect
			$class = sanitize_text_field( wp_unslash( $_GET['afwp_notice'] ) ) === 'success' ? 'notice-success' : 'notice-error';
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display param from redirect
			echo '<div class="notice ' . esc_attr( $class ) . ' is-dismissible"><p>' . esc_html( sanitize_text_field( wp_unslash( $_GET['afwp_message'] ) ) ) . '</p></div>';
		}

		?>
		<p class="pane-intro">
			Serve <code>llms.txt</code> and <code>llms-full.txt</code> at your site root for
			AI / LLM discovery (per the <a href="https://llmstxt.org/" target="_blank" rel="noopener">llms.txt spec</a>).
			<?php if ( $uses_files ) : ?>
				Physical files detected at site root — saving edits them directly.
			<?php else : ?>
				Content is stored in the database and served via rewrite rules.
			<?php endif; ?>
		</p>

			<h2>Public URLs</h2>
			<table class="widefat striped" style="max-width: 720px;">
				<tbody>
					<tr>
						<th style="width: 160px;">llms.txt</th>
						<td>
							<a href="<?php echo esc_url( $url ); ?>" target="_blank"><?php echo esc_html( $url ); ?></a>
							<?php if ( '' === $content ) : ?>
								<span style="color: #b91c1c; margin-left: 8px;">(not configured — will return 404)</span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<th>llms-full.txt</th>
						<td>
							<a href="<?php echo esc_url( $url_full ); ?>" target="_blank"><?php echo esc_html( $url_full ); ?></a>
							<?php if ( '' === $content_full ) : ?>
								<span style="color: #6b7280; margin-left: 8px;">(not configured)</span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<?php if ( $uses_files ) : ?>
				<div style="background: #f0fdf4; border: 1px solid #86efac; border-radius: 6px; padding: 16px; max-width: 720px; margin: 20px 0;">
					<h3 style="margin-top: 0;">Editing physical files</h3>
					<p>
						<?php if ( $physical['has_file'] ) : ?>
							<code>llms.txt</code> at <code><?php echo esc_html( ABSPATH . 'llms.txt' ); ?></code>
							(<?php echo esc_html( strlen( $physical['content'] ) ); ?> bytes)<br />
						<?php endif; ?>
						<?php if ( $physical['has_full'] ) : ?>
							<code>llms-full.txt</code> at <code><?php echo esc_html( ABSPATH . 'llms-full.txt' ); ?></code>
							(<?php echo esc_html( strlen( $physical['content_full'] ) ); ?> bytes)
						<?php endif; ?>
					</p>
					<p style="font-size: 12px; color: #15803d;">
						Saving below will write directly to the physical file(s). Your web server serves these as static files.
					</p>
				</div>
			<?php endif; ?>

			<hr />

			<div style="display: flex; align-items: center; gap: 12px; margin: 20px 0 0;">
				<h2 style="margin: 0;">llms.txt</h2>
				<?php if ( '' === $content ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin: 0;">
						<?php wp_nonce_field( self::NONCE_ACTION . '_generate' ); ?>
						<input type="hidden" name="action" value="afwp_llms_txt_generate">
						<button type="submit" class="button button-primary">Generate from published pages</button>
					</form>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="action" value="afwp_llms_txt_save">

				<p class="description">
					The concise version — a brief overview of your site for LLMs. Uses Markdown formatting.
					Internal links are automatically converted to absolute <code>/md/</code> URLs on save.
				</p>
				<textarea name="llms_txt" rows="12" class="large-text code" style="max-width: 720px; font-family: monospace;"><?php echo esc_textarea( $content ); ?></textarea>

				<h2 style="margin-top: 24px;">llms-full.txt</h2>
				<p class="description">
					The full version — detailed information about your site, pages, and content for LLMs. Optional.
				</p>
				<textarea name="llms_full_txt" rows="18" class="large-text code" style="max-width: 720px; font-family: monospace;"><?php echo esc_textarea( $content_full ); ?></textarea>

				<?php submit_button( 'Save LLMs.txt Content' ); ?>
			</form>

			<?php if ( '' !== trim( $content ) ) : ?>
				<hr />
				<h2>Preview</h2>
				<div style="background: #fff; border: 1px solid #d1d5db; border-radius: 6px; padding: 20px; max-width: 720px;">
					<h3 style="margin-top: 0; color: #6b7280; font-size: 12px; text-transform: uppercase;">llms.txt</h3>
					<?php echo wp_kses_post( $this->markdown_to_html( $content ) ); ?>
				</div>
				<?php if ( '' !== trim( $content_full ) ) : ?>
					<div style="background: #fff; border: 1px solid #d1d5db; border-radius: 6px; padding: 20px; max-width: 720px; margin-top: 16px;">
						<h3 style="margin-top: 0; color: #6b7280; font-size: 12px; text-transform: uppercase;">llms-full.txt</h3>
						<?php echo wp_kses_post( $this->markdown_to_html( $content_full ) ); ?>
					</div>
				<?php endif; ?>
			<?php endif; ?>
		<?php
	}

	/* ------------------------------------------------------------------
	 * REST endpoints
	 * ---------------------------------------------------------------- */

	public function register_routes(): void {
		$ns = Plugin::REST_NAMESPACE;

		register_rest_route( $ns, '/llms-txt', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'rest_put' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'rest_delete' ],
				'permission_callback' => [ $this, 'can_manage' ],
			],
		] );

		register_rest_route( $ns, '/llms-txt/preview', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_preview' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );

		register_rest_route( $ns, '/llms-txt/import', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_import' ],
			'permission_callback' => [ $this, 'can_manage' ],
		] );
	}

	public function can_manage(): bool {
		return current_user_can( 'manage_options' );
	}

	public function rest_get(): \WP_REST_Response {
		$physical = $this->detect_physical_files();

		return rest_ensure_response( [
			'content'            => get_option( self::OPTION_LLMS_TXT, '' ),
			'content_full'       => get_option( self::OPTION_LLMS_FULL_TXT, '' ),
			'url'                => home_url( '/llms.txt' ),
			'url_full'           => home_url( '/llms-full.txt' ),
			'has_physical_file'  => $physical['has_file'],
			'has_physical_full'  => $physical['has_full'],
			'physical_content'   => $physical['content'],
			'physical_full'      => $physical['content_full'],
		] );
	}

	public function rest_put( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid JSON body' ], 400 );
		}

		$c  = array_key_exists( 'content', $params )
			? $this->rewrite_links_to_md( sanitize_textarea_field( $params['content'] ) )
			: null;
		$cf = array_key_exists( 'content_full', $params )
			? $this->rewrite_links_to_md( sanitize_textarea_field( $params['content_full'] ) )
			: null;

		$this->save_content( $c, $cf );

		return $this->rest_get();
	}

	public function rest_delete(): \WP_REST_Response {
		delete_option( self::OPTION_LLMS_TXT );
		delete_option( self::OPTION_LLMS_FULL_TXT );
		return rest_ensure_response( [ 'deleted' => true ] );
	}

	public function rest_preview(): \WP_REST_Response {
		$content      = get_option( self::OPTION_LLMS_TXT, '' );
		$content_full = get_option( self::OPTION_LLMS_FULL_TXT, '' );
		$physical     = $this->detect_physical_files();

		return rest_ensure_response( [
			'preview'               => $this->markdown_to_html( $content ),
			'preview_full'          => $this->markdown_to_html( $content_full ),
			'preview_physical'      => $this->markdown_to_html( $physical['content'] ),
			'preview_physical_full' => $this->markdown_to_html( $physical['content_full'] ),
			'raw'                   => $content,
			'raw_full'              => $content_full,
		] );
	}

	public function rest_import(): \WP_REST_Response {
		$physical = $this->detect_physical_files();

		if ( ! $physical['has_file'] && ! $physical['has_full'] ) {
			return new \WP_REST_Response(
				[ 'error' => 'No physical llms.txt or llms-full.txt found at site root' ],
				404
			);
		}

		$imported = [];

		if ( $physical['has_file'] && '' !== $physical['content'] ) {
			update_option( self::OPTION_LLMS_TXT, $physical['content'], false );
			$imported[] = 'llms.txt';
		}
		if ( $physical['has_full'] && '' !== $physical['content_full'] ) {
			update_option( self::OPTION_LLMS_FULL_TXT, $physical['content_full'], false );
			$imported[] = 'llms-full.txt';
		}

		return rest_ensure_response( [
			'imported'     => $imported,
			'content'      => get_option( self::OPTION_LLMS_TXT, '' ),
			'content_full' => get_option( self::OPTION_LLMS_FULL_TXT, '' ),
		] );
	}

	/* ------------------------------------------------------------------
	 * Helpers
	 * ---------------------------------------------------------------- */

	private function save_content( ?string $content, ?string $content_full ): void {
		$physical = $this->detect_physical_files();

		if ( null !== $content ) {
			update_option( self::OPTION_LLMS_TXT, $content, false );
			if ( $physical['has_file'] ) {
				$this->write_physical_file( ABSPATH . 'llms.txt', $content );
			}
		}

		if ( null !== $content_full ) {
			update_option( self::OPTION_LLMS_FULL_TXT, $content_full, false );
			if ( $physical['has_full'] ) {
				$this->write_physical_file( ABSPATH . 'llms-full.txt', $content_full );
			}
		}
	}

	private function write_physical_file( string $path, string $content ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- physical file at ABSPATH, not plugin file
		if ( ! is_writable( $path ) && ! is_writable( dirname( $path ) ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		return false !== file_put_contents( $path, $content );
	}

	private function rewrite_links_to_md( string $content ): string {
		if ( '' === $content ) {
			return $content;
		}

		$home     = home_url();
		$home_esc = preg_quote( $home, '/' );

		return preg_replace_callback(
			'/\[([^\]]+)\]\(([^)]+)\)/',
			function ( $m ) use ( $home, $home_esc ) {
				$text = $m[1];
				$url  = trim( $m[2] );

				if ( preg_match( '/^(#|mailto:|tel:|javascript:)/i', $url ) ) {
					return $m[0];
				}

				if ( preg_match( '/\/md\//', $url ) ) {
					return $m[0];
				}

				if ( preg_match( '/\/(wp-json|wp-admin|wp-content|wp-includes)\//', $url ) ) {
					return $m[0];
				}

				if ( '/' === $url[0] ) {
					return '[' . $text . '](' . $home . '/md' . $url . ')';
				}

				if ( preg_match( '/^' . $home_esc . '(.*)$/i', $url, $parts ) ) {
					$path = $parts[1];
					if ( '' === $path || '/' === $path ) {
						return $m[0];
					}
					return '[' . $text . '](' . $home . '/md' . $path . ')';
				}

				return $m[0];
			},
			$content
		);
	}

	private function detect_physical_files(): array {
		$result = [
			'has_file'     => false,
			'has_full'     => false,
			'content'      => '',
			'content_full' => '',
		];

		$path = ABSPATH . 'llms.txt';
		if ( file_exists( $path ) && is_readable( $path ) ) {
			$result['has_file'] = true;
			$result['content']  = (string) file_get_contents( $path );
		}

		$path_full = ABSPATH . 'llms-full.txt';
		if ( file_exists( $path_full ) && is_readable( $path_full ) ) {
			$result['has_full']     = true;
			$result['content_full'] = (string) file_get_contents( $path_full );
		}

		return $result;
	}

	private function markdown_to_html( string $md ): string {
		if ( '' === trim( $md ) ) {
			return '';
		}

		$md = esc_html( $md );

		$md = preg_replace( '/^######\s+(.+)$/m', '<h6>$1</h6>', $md );
		$md = preg_replace( '/^#####\s+(.+)$/m',  '<h5>$1</h5>', $md );
		$md = preg_replace( '/^####\s+(.+)$/m',   '<h4>$1</h4>', $md );
		$md = preg_replace( '/^###\s+(.+)$/m',    '<h3>$1</h3>', $md );
		$md = preg_replace( '/^##\s+(.+)$/m',     '<h2>$1</h2>', $md );
		$md = preg_replace( '/^#\s+(.+)$/m',      '<h1>$1</h1>', $md );

		$md = preg_replace( '/^&gt;\s?(.+)$/m', '<blockquote>$1</blockquote>', $md );
		$md = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $md );

		$md = preg_replace( '/\*\*\*(.+?)\*\*\*/', '<strong><em>$1</em></strong>', $md );
		$md = preg_replace( '/\*\*(.+?)\*\*/', '<strong>$1</strong>', $md );
		$md = preg_replace( '/\*(.+?)\*/', '<em>$1</em>', $md );

		$md = preg_replace_callback( '/\[([^\]]+)\]\(([^)]+)\)/', function ( $m ) {
			$href = esc_url( $m[2] );
			if ( '' === $href ) {
				return esc_html( $m[1] );
			}
			return '<a href="' . esc_attr( $href ) . '">' . esc_html( $m[1] ) . '</a>';
		}, $md );

		$md = preg_replace( '/^[-*]\s+(.+)$/m', '<li>$1</li>', $md );
		$md = preg_replace( '/(<li>.*<\/li>\n?)+/', '<ul>$0</ul>', $md );

		$lines  = explode( "\n\n", $md );
		$output = [];
		foreach ( $lines as $block ) {
			$block = trim( $block );
			if ( '' === $block ) {
				continue;
			}
			if ( preg_match( '/^<(h[1-6]|ul|ol|blockquote|li|pre|table)/', $block ) ) {
				$output[] = $block;
			} else {
				$output[] = '<p>' . nl2br( $block ) . '</p>';
			}
		}

		return implode( "\n", $output );
	}
}
