<?php
/**
 * Main plugin singleton.
 *
 * @package AgentFriendlyWP
 */

namespace AgentFriendlyWP;

defined( 'ABSPATH' ) || exit;

class Plugin {

	const REST_NAMESPACE = 'agent-friendly/v1';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Module[] */
	private $modules = [];

	/** @var bool */
	private $initialized = false;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {}

	public function register_module( Module $module ): void {
		$this->modules[ $module->get_id() ] = $module;
	}

	public function get_module( string $id ): ?Module {
		return $this->modules[ $id ] ?? null;
	}

	/** @return Module[] */
	public function get_all_modules(): array {
		return $this->modules;
	}

	public function init(): void {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		foreach ( $this->modules as $module ) {
			$module->boot();
			if ( $module->is_enabled() ) {
				$module->init();
			}
		}
	}

	public function get_module_state(): array {
		$state = [];
		foreach ( $this->modules as $module ) {
			$enabled = $module->is_enabled();
			$entry   = [
				'enabled' => $enabled,
				'version' => $module->get_version(),
			];
			if ( ! $enabled ) {
				$reason = $module->get_disabled_reason();
				if ( $reason ) {
					$entry['reason'] = $reason;
				}
			}
			$state[ $module->get_id() ] = $entry;
		}
		return $state;
	}
}
