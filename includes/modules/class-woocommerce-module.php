<?php
/**
 * WooCommerce tools module.
 *
 * Exposes WooCommerce product browsing and cart operations as structured
 * tools for AI agents.
 *
 * @package AgentFriendlyWP\Modules
 */

namespace AgentFriendlyWP\Modules;

use AgentFriendlyWP\Module;
use AgentFriendlyWP\Plugin;
use AgentFriendlyWP\Admin_Page;
use AgentFriendlyWP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

class WooCommerce_Module extends Module {

	const VERSION        = '1.0.0';
	const OPTION_ENABLED = 'afwp_woo_enabled';
	const OPTION_TOOLS   = 'afwp_woo_tools';

	private static function default_tools(): array {
		return [
			'woo_search_products'       => true,
			'woo_get_product'           => true,
			'woo_get_product_categories' => true,
			'woo_add_to_cart'           => true,
			'woo_get_cart'              => true,
			'woo_remove_from_cart'      => true,
			'woo_apply_coupon'          => true,
		];
	}

	public function get_id(): string      { return 'woocommerce'; }
	public function get_label(): string   { return 'WooCommerce'; }
	public function get_version(): string { return self::VERSION; }

	public function is_enabled(): bool {
		return class_exists( 'WooCommerce' )
			&& (bool) get_option( self::OPTION_ENABLED, true );
	}

	public function get_disabled_reason(): ?string {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return 'WooCommerce plugin is not active.';
		}
		if ( ! (bool) get_option( self::OPTION_ENABLED, true ) ) {
			return 'Disabled in Agent Friendly settings.';
		}
		return null;
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_settings_route' ] );
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
	 * Init: register tools
	 * ---------------------------------------------------------------- */

	public function init(): void {
		$registry = Tool_Registry::instance();

		/* --- Read-only tools --- */

		if ( $this->is_tool_on( 'woo_search_products' ) ) {
			$registry->register( 'woo_search_products', [
				'description' => 'Search WooCommerce products by keyword, category, or price range.',
				'group'       => 'woocommerce',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'keyword'   => [
							'type'        => 'string',
							'description' => 'Search keyword or phrase.',
						],
						'category'  => [
							'type'        => 'string',
							'description' => 'Product category slug to filter by.',
						],
						'min_price' => [
							'type'        => 'number',
							'description' => 'Minimum price filter.',
						],
						'max_price' => [
							'type'        => 'number',
							'description' => 'Maximum price filter.',
						],
						'per_page'  => [
							'type'        => 'number',
							'description' => 'Number of results (1-50). Default: 10.',
						],
					],
				],
				'callback'    => [ $this, 'tool_search_products' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'woo_get_product' ) ) {
			$registry->register( 'woo_get_product', [
				'description' => 'Get detailed information about a specific WooCommerce product by ID or slug.',
				'group'       => 'woocommerce',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'id'   => [
							'type'        => 'number',
							'description' => 'Product ID.',
						],
						'slug' => [
							'type'        => 'string',
							'description' => 'Product slug. Use id OR slug, not both.',
						],
					],
				],
				'callback'    => [ $this, 'tool_get_product' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'woo_get_product_categories' ) ) {
			$registry->register( 'woo_get_product_categories', [
				'description' => 'List all WooCommerce product categories with counts.',
				'group'       => 'woocommerce',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'callback'    => [ $this, 'tool_get_product_categories' ],
				'annotations' => [ 'readOnlyHint' => true ],
				'protected'   => false,
				'turnstile'   => false,
			] );
		}

		/* --- Write tools (protected) --- */

		if ( $this->is_tool_on( 'woo_add_to_cart' ) ) {
			$registry->register( 'woo_add_to_cart', [
				'description' => 'Add a product to the WooCommerce cart.',
				'group'       => 'woocommerce',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'product_id'   => [
							'type'        => 'number',
							'description' => 'Product ID to add.',
						],
						'quantity'     => [
							'type'        => 'number',
							'description' => 'Quantity to add. Default: 1.',
						],
						'variation_id' => [
							'type'        => 'number',
							'description' => 'Variation ID for variable products.',
						],
					],
					'required'   => [ 'product_id' ],
				],
				'callback'    => [ $this, 'tool_add_to_cart' ],
				'annotations' => [ 'readOnlyHint' => false ],
				'protected'   => true,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'woo_get_cart' ) ) {
			$registry->register( 'woo_get_cart', [
				'description' => 'Get the current WooCommerce cart contents and totals.',
				'group'       => 'woocommerce',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => new \stdClass(),
				],
				'callback'    => [ $this, 'tool_get_cart' ],
				'annotations' => [ 'readOnlyHint' => false ],
				'protected'   => true,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'woo_remove_from_cart' ) ) {
			$registry->register( 'woo_remove_from_cart', [
				'description' => 'Remove an item from the WooCommerce cart by its cart item key.',
				'group'       => 'woocommerce',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'cart_item_key' => [
							'type'        => 'string',
							'description' => 'The cart item key to remove.',
						],
					],
					'required'   => [ 'cart_item_key' ],
				],
				'callback'    => [ $this, 'tool_remove_from_cart' ],
				'annotations' => [ 'readOnlyHint' => false ],
				'protected'   => true,
				'turnstile'   => false,
			] );
		}

		if ( $this->is_tool_on( 'woo_apply_coupon' ) ) {
			$registry->register( 'woo_apply_coupon', [
				'description' => 'Apply a coupon code to the WooCommerce cart.',
				'group'       => 'woocommerce',
				'inputSchema' => [
					'type'       => 'object',
					'properties' => [
						'coupon_code' => [
							'type'        => 'string',
							'description' => 'The coupon code to apply.',
						],
					],
					'required'   => [ 'coupon_code' ],
				],
				'callback'    => [ $this, 'tool_apply_coupon' ],
				'annotations' => [ 'readOnlyHint' => false ],
				'protected'   => true,
				'turnstile'   => false,
			] );
		}

		add_action( 'admin_menu', function () { Admin_Page::register(); } );
	}

	/* ------------------------------------------------------------------
	 * WC session helper
	 * ---------------------------------------------------------------- */

	private function ensure_wc_available(): void {
		if ( ! function_exists( 'WC' ) || null === WC() ) {
			throw new \RuntimeException( 'WooCommerce is not fully loaded.' );
		}
	}

	private function ensure_wc_session(): void {
		$this->ensure_wc_available();

		if ( null === WC()->session ) {
			WC()->session = new \WC_Session_Handler();
			WC()->session->init();
		}
		if ( null === WC()->customer ) {
			WC()->customer = new \WC_Customer( get_current_user_id(), true );
		}
		if ( null === WC()->cart ) {
			WC()->cart = new \WC_Cart();
			WC()->cart->get_cart();
		}
	}

	/* ------------------------------------------------------------------
	 * Read-only tool callbacks
	 * ---------------------------------------------------------------- */

	/**
	 * Search products.
	 *
	 * @param array $input Tool input.
	 * @return array
	 */
	public function tool_search_products( array $input ): array {
		$this->ensure_wc_available();
		$keyword   = sanitize_text_field( $input['keyword'] ?? '' );
		$category  = sanitize_key( $input['category'] ?? '' );
		$min_price = isset( $input['min_price'] ) ? (float) $input['min_price'] : null;
		$max_price = isset( $input['max_price'] ) ? (float) $input['max_price'] : null;
		$per_page  = min( 50, max( 1, (int) ( $input['per_page'] ?? 10 ) ) );

		$args = [
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
		];

		if ( '' !== $keyword ) {
			$args['s']       = $keyword;
			$args['orderby'] = 'relevance';
		} else {
			$args['orderby'] = 'date';
			$args['order']   = 'DESC';
		}

		if ( '' !== $category ) {
			$args['tax_query'] = [ // phpcs:ignore WordPress.DB.SlowDBQuery
				[
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => $category,
				],
			];
		}

		$meta_query = [];
		if ( null !== $min_price ) {
			$meta_query[] = [
				'key'     => '_price',
				'value'   => $min_price,
				'compare' => '>=',
				'type'    => 'DECIMAL',
			];
		}
		if ( null !== $max_price ) {
			$meta_query[] = [
				'key'     => '_price',
				'value'   => $max_price,
				'compare' => '<=',
				'type'    => 'DECIMAL',
			];
		}
		if ( ! empty( $meta_query ) ) {
			$meta_query['relation'] = 'AND';
			$args['meta_query']     = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery
		}

		$wp_query = new \WP_Query( $args );
		$results  = [];

		foreach ( $wp_query->posts as $post ) {
			$product = wc_get_product( $post->ID );
			if ( ! $product ) {
				continue;
			}

			$image_id  = $product->get_image_id();
			$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : '';

			$results[] = [
				'id'                => $product->get_id(),
				'name'              => $product->get_name(),
				'slug'              => $product->get_slug(),
				'price'             => $product->get_price(),
				'regular_price'     => $product->get_regular_price(),
				'sale_price'        => $product->get_sale_price(),
				'image_url'         => $image_url ?: null,
				'url'               => get_permalink( $product->get_id() ),
				'short_description' => wp_strip_all_tags( $product->get_short_description() ),
				'stock_status'      => $product->get_stock_status(),
			];
		}

		return [
			'total'   => $wp_query->found_posts,
			'results' => $results,
		];
	}

	/**
	 * Get a single product's details.
	 *
	 * @param array $input Tool input.
	 * @return array
	 */
	public function tool_get_product( array $input ): array {
		$this->ensure_wc_available();
		$id   = isset( $input['id'] ) ? (int) $input['id'] : 0;
		$slug = sanitize_title( $input['slug'] ?? '' );

		$product = null;

		if ( $id > 0 ) {
			$product = wc_get_product( $id );
		} elseif ( '' !== $slug ) {
			$post = get_page_by_path( $slug, OBJECT, 'product' );
			if ( $post ) {
				$product = wc_get_product( $post->ID );
			}
		}

		if ( ! $product || 'publish' !== get_post_status( $product->get_id() ) ) {
			return [ 'error' => 'Product not found.' ];
		}

		$image_id  = $product->get_image_id();
		$image_url = $image_id ? wp_get_attachment_image_url( $image_id, 'large' ) : '';

		// Categories.
		$categories = [];
		foreach ( $product->get_category_ids() as $cat_id ) {
			$term = get_term( $cat_id, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				$categories[] = [
					'id'   => $term->term_id,
					'name' => $term->name,
					'slug' => $term->slug,
				];
			}
		}

		// Attributes.
		$attributes = [];
		foreach ( $product->get_attributes() as $attr ) {
			if ( is_a( $attr, 'WC_Product_Attribute' ) ) {
				$attributes[] = [
					'name'    => wc_attribute_label( $attr->get_name() ),
					'options' => $attr->get_options(),
					'visible' => $attr->get_visible(),
				];
			}
		}

		$data = [
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'slug'              => $product->get_slug(),
			'type'              => $product->get_type(),
			'price'             => $product->get_price(),
			'regular_price'     => $product->get_regular_price(),
			'sale_price'        => $product->get_sale_price(),
			'image_url'         => $image_url ?: null,
			'url'               => get_permalink( $product->get_id() ),
			'short_description' => wp_strip_all_tags( $product->get_short_description() ),
			'description'       => wp_strip_all_tags( $product->get_description() ),
			'stock_status'      => $product->get_stock_status(),
			'categories'        => $categories,
			'attributes'        => $attributes,
			'weight'            => $product->get_weight(),
			'dimensions'        => [
				'length' => $product->get_length(),
				'width'  => $product->get_width(),
				'height' => $product->get_height(),
			],
		];

		// Include variations for variable products.
		if ( $product->is_type( 'variable' ) ) {
			$variations = [];
			foreach ( $product->get_available_variations() as $var ) {
				$variations[] = [
					'variation_id' => $var['variation_id'],
					'attributes'   => $var['attributes'],
					'price'        => $var['display_price'],
					'regular_price' => $var['display_regular_price'],
					'in_stock'     => $var['is_in_stock'],
					'image_url'    => $var['image']['url'] ?? null,
				];
			}
			$data['variations'] = $variations;
		}

		return $data;
	}

	/**
	 * Get product categories.
	 *
	 * @param array $input Tool input (unused).
	 * @return array
	 */
	public function tool_get_product_categories( array $input ): array {
		$terms = get_terms( [
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
		] );

		if ( is_wp_error( $terms ) ) {
			return [ 'error' => $terms->get_error_message() ];
		}

		$categories = [];
		foreach ( $terms as $term ) {
			$categories[] = [
				'id'          => $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'count'       => $term->count,
				'parent'      => $term->parent,
				'description' => $term->description,
			];
		}

		return $categories;
	}

	/* ------------------------------------------------------------------
	 * Write tool callbacks
	 * ---------------------------------------------------------------- */

	/**
	 * Add a product to the cart.
	 *
	 * @param array $input Tool input.
	 * @return array
	 */
	public function tool_add_to_cart( array $input ): array {
		$product_id   = (int) ( $input['product_id'] ?? 0 );
		$quantity     = max( 1, (int) ( $input['quantity'] ?? 1 ) );
		$variation_id = (int) ( $input['variation_id'] ?? 0 );

		if ( $product_id <= 0 ) {
			return [ 'error' => 'Invalid product ID.' ];
		}

		$this->ensure_wc_session();

		$cart_item_key = WC()->cart->add_to_cart( $product_id, $quantity, $variation_id );

		if ( ! $cart_item_key ) {
			return [ 'error' => 'Failed to add product to cart. The product may be out of stock or invalid.' ];
		}

		return [
			'cart_item_key' => $cart_item_key,
			'cart_total'    => WC()->cart->get_cart_contents_total(),
			'item_count'    => WC()->cart->get_cart_contents_count(),
		];
	}

	/**
	 * Get the current cart contents.
	 *
	 * @param array $input Tool input (unused).
	 * @return array
	 */
	public function tool_get_cart( array $input ): array {
		$this->ensure_wc_session();

		$items = [];

		foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
			$product  = $cart_item['data'];
			$image_id = $product->get_image_id();

			$items[] = [
				'cart_item_key' => $cart_item_key,
				'product_id'    => $cart_item['product_id'],
				'name'          => $product->get_name(),
				'quantity'      => $cart_item['quantity'],
				'price'         => $product->get_price(),
				'subtotal'      => $cart_item['line_subtotal'],
				'image_url'     => $image_id ? wp_get_attachment_image_url( $image_id, 'woocommerce_thumbnail' ) : null,
			];
		}

		return [
			'items'      => $items,
			'subtotal'   => WC()->cart->get_subtotal(),
			'total'      => WC()->cart->get_cart_contents_total(),
			'item_count' => WC()->cart->get_cart_contents_count(),
		];
	}

	/**
	 * Remove an item from the cart.
	 *
	 * @param array $input Tool input.
	 * @return array
	 */
	public function tool_remove_from_cart( array $input ): array {
		$cart_item_key = sanitize_text_field( $input['cart_item_key'] ?? '' );

		if ( '' === $cart_item_key ) {
			return [ 'error' => 'Cart item key is required.' ];
		}

		$this->ensure_wc_session();

		$removed = WC()->cart->remove_cart_item( $cart_item_key );

		if ( ! $removed ) {
			return [ 'error' => 'Cart item not found or could not be removed.' ];
		}

		return [
			'removed'    => true,
			'subtotal'   => WC()->cart->get_subtotal(),
			'total'      => WC()->cart->get_cart_contents_total(),
			'item_count' => WC()->cart->get_cart_contents_count(),
		];
	}

	/**
	 * Apply a coupon to the cart.
	 *
	 * @param array $input Tool input.
	 * @return array
	 */
	public function tool_apply_coupon( array $input ): array {
		$coupon_code = sanitize_text_field( $input['coupon_code'] ?? '' );

		if ( '' === $coupon_code ) {
			return [ 'error' => 'Coupon code is required.' ];
		}

		$this->ensure_wc_session();

		$applied = WC()->cart->apply_coupon( $coupon_code );

		if ( ! $applied ) {
			$notices = wc_get_notices( 'error' );
			wc_clear_notices();
			$message = ! empty( $notices ) ? wp_strip_all_tags( $notices[0]['notice'] ?? 'Coupon could not be applied.' ) : 'Coupon could not be applied.';
			return [ 'error' => $message ];
		}

		// Clear success notices to prevent them from rendering on frontend.
		wc_clear_notices();

		return [
			'applied'        => true,
			'coupon_code'    => $coupon_code,
			'discount_total' => WC()->cart->get_discount_total(),
			'cart_total'     => WC()->cart->get_cart_contents_total(),
		];
	}

	/* ------------------------------------------------------------------
	 * Settings REST route
	 * ---------------------------------------------------------------- */

	public function register_settings_route(): void {
		$ns = Plugin::REST_NAMESPACE;

		register_rest_route( $ns, '/woo/settings', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get_settings' ],
				'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			],
			[
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => [ $this, 'rest_update_settings' ],
				'permission_callback' => function () { return current_user_can( 'manage_options' ); },
			],
		] );
	}

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
	 * Admin UI
	 * ---------------------------------------------------------------- */

	public function render_content(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Check if WooCommerce is installed.
		if ( ! class_exists( 'WooCommerce' ) ) {
			?>
			<p class="pane-intro">
				The WooCommerce module requires <strong>WooCommerce</strong> to be installed and activated.
			</p>
			<div style="background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 20px; max-width: 720px;">
				<h3 style="margin-top: 0;">WooCommerce not detected</h3>
				<p>
					Install and activate
					<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=woocommerce&tab=search&type=term' ) ); ?>">WooCommerce</a>
					to enable AI-powered product search, product details, and cart management tools.
				</p>
			</div>
			<?php
			return;
		}

		$enabled           = (bool) get_option( self::OPTION_ENABLED, true );
		$tools             = $this->get_enabled_tools();
		$settings_endpoint = rest_url( Plugin::REST_NAMESPACE . '/woo/settings' );

		$tool_labels = [
			'woo_search_products'        => [ 'label' => 'Search Products',      'desc' => 'Search products by keyword, category, or price range.' ],
			'woo_get_product'            => [ 'label' => 'Get Product',          'desc' => 'Retrieve full product details by ID or slug.' ],
			'woo_get_product_categories' => [ 'label' => 'Product Categories',   'desc' => 'List product categories with counts.' ],
			'woo_add_to_cart'            => [ 'label' => 'Add to Cart',          'desc' => 'Add a product to the shopping cart. (Protected)' ],
			'woo_get_cart'               => [ 'label' => 'Get Cart',             'desc' => 'Get current cart contents and totals. (Protected)' ],
			'woo_remove_from_cart'       => [ 'label' => 'Remove from Cart',     'desc' => 'Remove an item from the cart. (Protected)' ],
			'woo_apply_coupon'           => [ 'label' => 'Apply Coupon',         'desc' => 'Apply a discount coupon to the cart. (Protected)' ],
		];

		?>
		<p class="pane-intro">
			Expose WooCommerce product browsing and cart operations as structured tools for AI agents.
			Read-only tools are publicly accessible; cart operations require authentication.
		</p>

		<form method="post" id="afwp-woo-form">

			<h2>Status</h2>
			<table class="form-table" style="max-width: 720px;">
				<tr>
					<th scope="row">WooCommerce Module</th>
					<td>
						<label>
							<input type="checkbox" id="afwp-woo-enabled" <?php checked( $enabled ); ?>>
							Enable WooCommerce tools for AI agents
						</label>
						<p class="description">
							When enabled, agents can search products, view details, and manage cart items.
						</p>
					</td>
				</tr>
			</table>

			<h2>Exposed Tools</h2>
			<p class="description" style="margin-bottom: 12px;">
				Toggle which WooCommerce tools AI agents can discover and invoke.
				Tools marked <em>(Protected)</em> require a WP REST nonce for authentication.
			</p>
			<table class="widefat striped" style="max-width: 720px;">
				<thead>
					<tr>
						<th style="width: 40px;"></th>
						<th style="width: 200px;">Tool</th>
						<th>Description</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $tool_labels as $name => $info ) :
						$on = ! empty( $tools[ $name ] );
						?>
						<tr>
							<td style="text-align: center;">
								<input type="checkbox" class="afwp-woo-tool" data-tool="<?php echo esc_attr( $name ); ?>" <?php checked( $on ); ?>>
							</td>
							<td><code><?php echo esc_html( $name ); ?></code></td>
							<td><?php echo esc_html( $info['desc'] ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p class="submit">
				<button type="button" id="afwp-woo-save" class="button button-primary">Save WooCommerce Settings</button>
				<span id="afwp-woo-status" style="margin-left: 12px;"></span>
			</p>
		</form>

		<script>
		(function() {
			var saveBtn   = document.getElementById('afwp-woo-save');
			var statusEl  = document.getElementById('afwp-woo-status');
			var enabledCb = document.getElementById('afwp-woo-enabled');
			var endpoint  = <?php echo wp_json_encode( $settings_endpoint ); ?>;

			saveBtn.addEventListener('click', function() {
				var tools = {};
				document.querySelectorAll('.afwp-woo-tool').forEach(function(cb) {
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
