<?php
/**
 * Fluent Forms integration module.
 *
 * Exposes Fluent Forms as structured tools for AI agents, with optional
 * Cloudflare Turnstile captcha protection for form submissions.
 *
 * @package AgentFriendlyWP\Modules
 */

namespace AgentFriendlyWP\Modules;

use AgentFriendlyWP\Module;
use AgentFriendlyWP\Plugin;
use AgentFriendlyWP\Admin_Page;
use AgentFriendlyWP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

class Fluent_Forms_Module extends Module {

	const VERSION                = '1.0.0';
	const OPTION_ENABLED         = 'afwp_forms_enabled';
	const OPTION_TURNSTILE_ENABLED    = 'afwp_turnstile_enabled';
	const OPTION_TURNSTILE_SITE_KEY   = 'afwp_turnstile_site_key';
	const OPTION_TURNSTILE_SECRET_KEY = 'afwp_turnstile_secret_key';

	public function get_id(): string      { return 'forms'; }
	public function get_label(): string   { return 'Forms'; }
	public function get_version(): string { return self::VERSION; }

	public function is_enabled(): bool {
		return class_exists( 'FluentForm\\App\\Modules\\Form\\Form' )
			&& (bool) get_option( self::OPTION_ENABLED, true );
	}

	public function get_disabled_reason(): ?string {
		if ( ! class_exists( 'FluentForm\\App\\Modules\\Form\\Form' ) ) {
			return 'Fluent Forms plugin is not active.';
		}
		if ( ! (bool) get_option( self::OPTION_ENABLED, true ) ) {
			return 'Disabled in Agent Friendly settings.';
		}
		return null;
	}

	public function boot(): void {
		add_action( 'rest_api_init', [ $this, 'register_settings_route' ] );
	}

	public function init(): void {
		$registry = Tool_Registry::instance();

		$registry->register( 'list_forms', [
			'description' => 'List all published Fluent Forms with their IDs, titles, and field counts.',
			'group'       => 'forms',
			'inputSchema' => [
				'type'       => 'object',
				'properties' => new \stdClass(),
			],
			'callback'    => [ $this, 'tool_list_forms' ],
			'annotations' => [ 'readOnlyHint' => true ],
			'protected'   => false,
			'turnstile'   => false,
		] );

		$registry->register( 'get_form_fields', [
			'description' => 'Get the fields and structure of a specific Fluent Form by its ID.',
			'group'       => 'forms',
			'inputSchema' => [
				'type'       => 'object',
				'properties' => [
					'form_id' => [
						'type'        => 'number',
						'description' => 'The form ID.',
					],
				],
				'required'   => [ 'form_id' ],
			],
			'callback'    => [ $this, 'tool_get_form_fields' ],
			'annotations' => [ 'readOnlyHint' => true ],
			'protected'   => false,
			'turnstile'   => false,
		] );

		$turnstile_on = (bool) get_option( self::OPTION_TURNSTILE_ENABLED, false );

		$registry->register( 'submit_form', [
			'description' => 'Submit data to a Fluent Form. Provide the form ID and field key-value pairs.',
			'group'       => 'forms',
			'inputSchema' => [
				'type'       => 'object',
				'properties' => [
					'form_id' => [
						'type'        => 'number',
						'description' => 'The form ID to submit to.',
					],
					'fields'  => [
						'type'        => 'object',
						'description' => 'Key-value pairs of field names and values.',
					],
				],
				'required'   => [ 'form_id', 'fields' ],
			],
			'callback'    => [ $this, 'tool_submit_form' ],
			'annotations' => [ 'readOnlyHint' => false ],
			'protected'   => true,
			'turnstile'   => $turnstile_on,
		] );

		add_action( 'admin_menu', function () { Admin_Page::register(); } );
	}

	/* ------------------------------------------------------------------
	 * Tool callbacks
	 * ---------------------------------------------------------------- */

	/**
	 * List all published Fluent Forms.
	 *
	 * @param array $input Tool input (unused).
	 * @return array
	 */
	public function tool_list_forms( array $input ): array {
		if ( ! function_exists( 'wpFluent' ) ) {
			return [ 'error' => 'Fluent Forms is not available.' ];
		}

		$forms = wpFluent()
			->table( 'fluentform_forms' )
			->where( 'status', 'published' )
			->select( [ 'id', 'title', 'form_fields', 'created_at' ] )
			->get();

		$results = [];

		foreach ( $forms as $form ) {
			$field_count = 0;
			$fields_data = json_decode( $form->form_fields, true );
			if ( is_array( $fields_data ) && isset( $fields_data['fields'] ) ) {
				$field_count = count( $fields_data['fields'] );
			}

			$results[] = [
				'id'          => (int) $form->id,
				'title'       => $form->title,
				'field_count' => $field_count,
				'created_at'  => $form->created_at,
			];
		}

		return $results;
	}

	/**
	 * Get field details for a specific form.
	 *
	 * @param array $input Tool input with form_id.
	 * @return array
	 */
	public function tool_get_form_fields( array $input ): array {
		if ( ! function_exists( 'wpFluent' ) ) {
			return [ 'error' => 'Fluent Forms is not available.' ];
		}

		$form_id = (int) ( $input['form_id'] ?? 0 );

		if ( $form_id <= 0 ) {
			return [ 'error' => 'Invalid form ID.' ];
		}

		$form = wpFluent()
			->table( 'fluentform_forms' )
			->where( 'id', $form_id )
			->first();

		if ( ! $form ) {
			return [ 'error' => 'Form not found.' ];
		}

		$fields_data = json_decode( $form->form_fields, true );
		$fields      = [];

		if ( is_array( $fields_data ) && isset( $fields_data['fields'] ) ) {
			foreach ( $fields_data['fields'] as $field ) {
				$parsed = [
					'name'     => $field['attributes']['name'] ?? '',
					'type'     => $field['attributes']['type'] ?? $field['element'] ?? 'unknown',
					'label'    => $field['settings']['label'] ?? '',
					'required' => ! empty( $field['settings']['validation_rules']['required']['value'] ),
				];

				// Include options for select, radio, checkbox fields.
				if ( ! empty( $field['settings']['advanced_options'] ) ) {
					$parsed['options'] = array_map( function ( $opt ) {
						return [
							'label' => $opt['label'] ?? '',
							'value' => $opt['value'] ?? '',
						];
					}, $field['settings']['advanced_options'] );
				} elseif ( ! empty( $field['options'] ) ) {
					$parsed['options'] = $field['options'];
				}

				$fields[] = $parsed;
			}
		}

		return [
			'form_id' => $form_id,
			'title'   => $form->title,
			'fields'  => $fields,
		];
	}

	/**
	 * Submit data to a Fluent Form.
	 *
	 * @param array $input Tool input with form_id and fields.
	 * @return array
	 */
	public function tool_submit_form( array $input ): array {
		if ( ! function_exists( 'wpFluent' ) ) {
			return [ 'error' => 'Fluent Forms is not available.' ];
		}

		global $wpdb;

		$form_id = (int) ( $input['form_id'] ?? 0 );

		if ( $form_id <= 0 ) {
			return [ 'error' => 'Invalid form ID.' ];
		}

		$form = wpFluent()
			->table( 'fluentform_forms' )
			->where( 'id', $form_id )
			->first();

		if ( ! $form ) {
			return [ 'error' => 'Form not found.' ];
		}

		if ( 'published' !== $form->status ) {
			return [ 'error' => 'Form is not published.' ];
		}

		$raw_fields = $input['fields'] ?? [];
		if ( ! is_array( $raw_fields ) || empty( $raw_fields ) ) {
			return [ 'error' => 'No field data provided.' ];
		}

		// Sanitize each field value.
		$sanitized = [];
		foreach ( $raw_fields as $key => $value ) {
			$key = sanitize_key( $key );
			if ( is_array( $value ) ) {
				$sanitized[ $key ] = array_map( 'sanitize_text_field', $value );
			} else {
				$sanitized[ $key ] = sanitize_text_field( (string) $value );
			}
		}

		$now = current_time( 'mysql' );

		$form_data = [
			'form_id'    => $form_id,
			'response'   => wp_json_encode( $sanitized ),
			'status'     => 'unread',
			'created_at' => $now,
			'updated_at' => $now,
			'ip'         => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'browser'    => 'AI Agent (Agent Friendly)',
			'source_url' => home_url(),
		];

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fluent Forms custom table, no WP API
		$wpdb->insert(
			$wpdb->prefix . 'fluentform_submissions',
			$form_data
		);

		$insert_id = $wpdb->insert_id;

		if ( ! $insert_id ) {
			return [ 'error' => 'Failed to insert submission.' ];
		}

		// Insert entry details for each field.
		foreach ( $sanitized as $field_name => $field_value ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Fluent Forms custom table, no WP API
			$wpdb->insert(
				$wpdb->prefix . 'fluentform_entry_details',
				[
					'form_id'       => $form_id,
					'submission_id' => $insert_id,
					'field_name'    => $field_name,
					'field_value'   => is_array( $field_value ) ? wp_json_encode( $field_value ) : $field_value,
					'sub_field_name' => '',
				]
			);
		}

		// Fire Fluent Forms submission action so notifications/integrations process.
		// FF expects: ($insertId, $formData, $form) where $formData is the flat
		// field key-value pairs, not the DB insert array.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Fluent Forms hook
		do_action( 'fluentform/submission_inserted', $insert_id, $sanitized, $form );

		return [
			'success'       => true,
			'submission_id' => $insert_id,
		];
	}

	/* ------------------------------------------------------------------
	 * Settings REST route
	 * ---------------------------------------------------------------- */

	public function register_settings_route(): void {
		$ns = Plugin::REST_NAMESPACE;

		register_rest_route( $ns, '/forms/settings', [
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
			'enabled'           => (bool) get_option( self::OPTION_ENABLED, true ),
			'turnstile_enabled' => (bool) get_option( self::OPTION_TURNSTILE_ENABLED, false ),
			'turnstile_site_key'   => get_option( self::OPTION_TURNSTILE_SITE_KEY, '' ),
			'turnstile_secret_key' => get_option( self::OPTION_TURNSTILE_SECRET_KEY, '' ),
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

		if ( array_key_exists( 'turnstile_enabled', $params ) ) {
			update_option( self::OPTION_TURNSTILE_ENABLED, (bool) $params['turnstile_enabled'], false );
		}

		if ( array_key_exists( 'turnstile_site_key', $params ) ) {
			update_option( self::OPTION_TURNSTILE_SITE_KEY, sanitize_text_field( $params['turnstile_site_key'] ), false );
		}

		if ( array_key_exists( 'turnstile_secret_key', $params ) ) {
			update_option( self::OPTION_TURNSTILE_SECRET_KEY, sanitize_text_field( $params['turnstile_secret_key'] ), false );
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

		// Check if Fluent Forms is installed.
		if ( ! class_exists( 'FluentForm\\App\\Modules\\Form\\Form' ) ) {
			?>
			<p class="pane-intro">
				The Forms module requires <strong>Fluent Forms</strong> to be installed and activated.
			</p>
			<div style="background: #fff8e1; border: 1px solid #ffe082; border-radius: 6px; padding: 20px; max-width: 720px;">
				<h3 style="margin-top: 0;">Fluent Forms not detected</h3>
				<p>
					Install and activate
					<a href="<?php echo esc_url( admin_url( 'plugin-install.php?s=fluentform&tab=search&type=term' ) ); ?>">Fluent Forms</a>
					to enable AI-powered form listing, field inspection, and submission tools.
				</p>
			</div>
			<?php
			return;
		}

		$enabled              = (bool) get_option( self::OPTION_ENABLED, true );
		$turnstile_enabled    = (bool) get_option( self::OPTION_TURNSTILE_ENABLED, false );
		$turnstile_site_key   = get_option( self::OPTION_TURNSTILE_SITE_KEY, '' );
		$turnstile_secret_key = get_option( self::OPTION_TURNSTILE_SECRET_KEY, '' );
		$settings_endpoint    = rest_url( Plugin::REST_NAMESPACE . '/forms/settings' );

		?>
		<p class="pane-intro">
			Expose Fluent Forms to AI agents as structured tools. Agents can list forms,
			inspect fields, and submit entries programmatically.
		</p>

		<form method="post" id="afwp-forms-form">

			<h2>Status</h2>
			<table class="form-table" style="max-width: 720px;">
				<tr>
					<th scope="row">Forms Module</th>
					<td>
						<label>
							<input type="checkbox" id="afwp-forms-enabled" <?php checked( $enabled ); ?>>
							Enable Fluent Forms tools for AI agents
						</label>
						<p class="description">
							When enabled, agents can discover and interact with your published forms
							via the <code>list_forms</code>, <code>get_form_fields</code>, and <code>submit_form</code> tools.
						</p>
					</td>
				</tr>
			</table>

			<h2>Turnstile Configuration</h2>
			<table class="form-table" style="max-width: 720px;">
				<tr>
					<th scope="row">Turnstile Protection</th>
					<td>
						<label>
							<input type="checkbox" id="afwp-turnstile-enabled" <?php checked( $turnstile_enabled ); ?>>
							Require Cloudflare Turnstile verification for form submissions
						</label>
						<p class="description">
							When enabled, the <code>submit_form</code> tool requires a valid Turnstile token.
							This helps prevent automated spam submissions while allowing legitimate AI agents to submit forms.
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Site Key</th>
					<td>
						<input type="text" id="afwp-turnstile-site-key" class="regular-text"
							value="<?php echo esc_attr( $turnstile_site_key ); ?>"
							placeholder="0x4AAAAAAA..."
							autocomplete="off">
						<p class="description">
							Your Cloudflare Turnstile site key (visible to clients).
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Secret Key</th>
					<td>
						<input type="password" id="afwp-turnstile-secret-key" class="regular-text"
							value="<?php echo esc_attr( $turnstile_secret_key ); ?>"
							placeholder="0x4AAAAAAA..."
							autocomplete="off">
						<p class="description">
							Your Cloudflare Turnstile secret key (used server-side for verification).
							Get keys at <a href="https://dash.cloudflare.com/turnstile" target="_blank" rel="noopener">Cloudflare Dashboard</a>.
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="button" id="afwp-forms-save" class="button button-primary">Save Forms Settings</button>
				<span id="afwp-forms-status" style="margin-left: 12px;"></span>
			</p>
		</form>

		<script>
		(function() {
			var saveBtn   = document.getElementById('afwp-forms-save');
			var statusEl  = document.getElementById('afwp-forms-status');
			var endpoint  = <?php echo wp_json_encode( $settings_endpoint ); ?>;

			saveBtn.addEventListener('click', function() {
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
						enabled:            document.getElementById('afwp-forms-enabled').checked,
						turnstile_enabled:  document.getElementById('afwp-turnstile-enabled').checked,
						turnstile_site_key:   document.getElementById('afwp-turnstile-site-key').value,
						turnstile_secret_key: document.getElementById('afwp-turnstile-secret-key').value
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
