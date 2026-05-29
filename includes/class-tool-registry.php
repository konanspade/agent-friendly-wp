<?php
/**
 * Central tool registry. All modules register their tools here;
 * the WebMCP module reads from it for manifests and browser registration.
 *
 * @package AgentFriendlyWP
 */

namespace AgentFriendlyWP;

defined( 'ABSPATH' ) || exit;

class Tool_Registry {

	/** @var Tool_Registry|null */
	private static $instance = null;

	/** @var array<string, array> */
	private $tools = [];

	/** @var array<string, string[]> */
	private $groups = [];

	public static function instance(): Tool_Registry {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	/**
	 * Register a tool.
	 *
	 * @param string $name   Unique tool name (snake_case).
	 * @param array  $config {
	 *     @type string   $description  Human-readable description.
	 *     @type string   $group        Group slug: content, forms, woocommerce, custom.
	 *     @type array    $inputSchema  JSON Schema for input parameters.
	 *     @type callable $callback     function(array $input): array — returns result data.
	 *     @type array    $annotations  WebMCP annotations. Default: ['readOnlyHint' => true].
	 *     @type bool     $protected    Requires WP REST nonce. Default: false.
	 *     @type bool     $turnstile    Requires Turnstile token. Default: false.
	 * }
	 */
	public function register( string $name, array $config ): void {
		if ( isset( $this->tools[ $name ] ) ) {
			return;
		}

		$config = wp_parse_args( $config, [
			'description' => '',
			'group'       => 'custom',
			'inputSchema' => [ 'type' => 'object', 'properties' => new \stdClass() ],
			'callback'    => null,
			'annotations' => [ 'readOnlyHint' => true ],
			'protected'   => false,
			'turnstile'   => false,
		] );

		$this->tools[ $name ] = $config;
		$group = $config['group'];
		if ( ! isset( $this->groups[ $group ] ) ) {
			$this->groups[ $group ] = [];
		}
		$this->groups[ $group ][] = $name;
	}

	public function unregister( string $name ): void {
		if ( ! isset( $this->tools[ $name ] ) ) {
			return;
		}
		$group = $this->tools[ $name ]['group'];
		unset( $this->tools[ $name ] );
		if ( isset( $this->groups[ $group ] ) ) {
			$this->groups[ $group ] = array_values( array_diff( $this->groups[ $group ], [ $name ] ) );
		}
	}

	public function get_tool( string $name ): ?array {
		return $this->tools[ $name ] ?? null;
	}

	/** @return array<string, array> */
	public function get_all(): array {
		return $this->tools;
	}

	/** @return array<string, array> */
	public function get_by_group( string $group ): array {
		$result = [];
		foreach ( $this->groups[ $group ] ?? [] as $name ) {
			if ( isset( $this->tools[ $name ] ) ) {
				$result[ $name ] = $this->tools[ $name ];
			}
		}
		return $result;
	}

	/** @return string[] */
	public function get_groups(): array {
		return array_keys( $this->groups );
	}

	/**
	 * Get tool definitions suitable for manifests and bootstrap scripts.
	 * Strips callbacks (not serializable) and adds name key.
	 *
	 * @return array[]
	 */
	public function get_tool_definitions(): array {
		$protected_tools = apply_filters( 'afwp_protected_tools', [] );

		$defs = [];
		foreach ( $this->tools as $name => $config ) {
			$is_protected = $config['protected'] || in_array( $name, $protected_tools, true );
			$defs[] = [
				'name'        => $name,
				'description' => $config['description'],
				'group'       => $config['group'],
				'inputSchema' => $config['inputSchema'],
				'annotations' => $config['annotations'],
				'protected'   => $is_protected,
				'turnstile'   => $config['turnstile'],
			];
		}
		return $defs;
	}

	/**
	 * Execute a tool by name.
	 *
	 * @return array {success: bool, data?: mixed, error?: string}
	 */
	public function execute( string $name, array $input ): array {
		$tool = $this->get_tool( $name );
		if ( ! $tool ) {
			return [ 'success' => false, 'error' => "Tool '{$name}' not found." ];
		}

		if ( ! is_callable( $tool['callback'] ) ) {
			return [ 'success' => false, 'error' => "Tool '{$name}' has no valid callback." ];
		}

		try {
			$result = call_user_func( $tool['callback'], $input );
			return [ 'success' => true, 'data' => $result ];
		} catch ( \Throwable $e ) {
			return [ 'success' => false, 'error' => $e->getMessage() ];
		}
	}

	/* ------------------------------------------------------------------
	 * REST API
	 * ---------------------------------------------------------------- */

	public function register_routes(): void {
		$ns = Plugin::REST_NAMESPACE;

		register_rest_route( $ns, '/tools', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_list_tools' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $ns, '/tools/execute', [
			'methods'             => \WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'rest_execute' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'tool'  => [ 'required' => true, 'type' => 'string', 'sanitize_callback' => 'sanitize_key' ],
				'input' => [ 'required' => false, 'type' => 'object', 'default' => [] ],
			],
		] );

		register_rest_route( $ns, '/tools/nonce', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ $this, 'rest_nonce' ],
			'permission_callback' => function () {
				return is_user_logged_in();
			},
		] );

		register_rest_route( $ns, '/settings', [
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

	public function rest_list_tools(): \WP_REST_Response {
		return rest_ensure_response( [ 'tools' => $this->get_tool_definitions() ] );
	}

	public function rest_execute( \WP_REST_Request $request ): \WP_REST_Response {
		if ( ! Rate_Limiter::check() ) {
			$headers  = Rate_Limiter::get_headers();
			$response = new \WP_REST_Response( [ 'error' => 'Too many requests.' ], 429 );
			foreach ( $headers as $key => $val ) {
				$response->header( $key, $val );
			}
			return $response;
		}

		// Increment immediately to narrow the burst window.
		Rate_Limiter::increment();

		$tool_name = $request->get_param( 'tool' );
		$input     = (array) $request->get_param( 'input' );
		$tool      = $this->get_tool( $tool_name );

		if ( ! $tool ) {
			return new \WP_REST_Response( [ 'error' => "Tool '{$tool_name}' not found." ], 404 );
		}

		$protected_tools = apply_filters( 'afwp_protected_tools', [] );
		$is_protected    = $tool['protected'] || in_array( $tool_name, $protected_tools, true );

		if ( $is_protected ) {
			$nonce = $request->get_header( 'X-WP-Nonce' );
			if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
				return new \WP_REST_Response( [ 'error' => 'Valid nonce required for this tool. Fetch one from /tools/nonce.' ], 403 );
			}
		}

		if ( $tool['turnstile'] ) {
			$turnstile_token = $input['_turnstile_token'] ?? ( $request->get_param( 'turnstile_token' ) ?? '' );
			unset( $input['_turnstile_token'] );

			if ( '' === $turnstile_token ) {
				return new \WP_REST_Response( [ 'error' => 'Turnstile verification required.' ], 403 );
			}

			$valid = $this->verify_turnstile( $turnstile_token );
			if ( ! $valid ) {
				return new \WP_REST_Response( [ 'error' => 'Turnstile verification failed.' ], 403 );
			}
		}

		$result = $this->execute( $tool_name, $input );

		$status  = $result['success'] ? 200 : 400;
		$body    = $result['success'] ? $result['data'] : [ 'error' => $result['error'] ];
		$response = new \WP_REST_Response( $body, $status );

		foreach ( Rate_Limiter::get_headers() as $key => $val ) {
			$response->header( $key, $val );
		}

		return $response;
	}

	public function rest_nonce(): \WP_REST_Response {
		return rest_ensure_response( [ 'nonce' => wp_create_nonce( 'wp_rest' ) ] );
	}

	public function rest_get_settings(): \WP_REST_Response {
		return rest_ensure_response( [
			'rate_limit_max'    => Rate_Limiter::get_max(),
			'rate_limit_window' => Rate_Limiter::get_window(),
		] );
	}

	public function rest_update_settings( \WP_REST_Request $request ): \WP_REST_Response {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			return new \WP_REST_Response( [ 'error' => 'Invalid JSON body.' ], 400 );
		}

		if ( isset( $params['rate_limit_max'] ) ) {
			update_option( Rate_Limiter::OPTION_MAX, max( 1, (int) $params['rate_limit_max'] ), false );
		}
		if ( isset( $params['rate_limit_window'] ) ) {
			update_option( Rate_Limiter::OPTION_WINDOW, max( 1, (int) $params['rate_limit_window'] ), false );
		}

		return $this->rest_get_settings();
	}

	/* ------------------------------------------------------------------
	 * Turnstile verification
	 * ---------------------------------------------------------------- */

	private function verify_turnstile( string $token ): bool {
		$secret = get_option( 'afwp_turnstile_secret_key', '' );
		if ( '' === $secret ) {
			return false;
		}

		$response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', [
			'body'    => [
				'secret'   => $secret,
				'response' => $token,
				'remoteip' => sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ),
			],
			'timeout' => 10,
		] );

		if ( is_wp_error( $response ) ) {
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		return ! empty( $body['success'] );
	}
}
