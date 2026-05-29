<?php
/**
 * Abstract base class for plugin modules.
 *
 * @package AgentFriendlyWP
 */

namespace AgentFriendlyWP;

defined( 'ABSPATH' ) || exit;

abstract class Module {

	abstract public function get_id(): string;
	abstract public function get_label(): string;
	abstract public function get_version(): string;
	abstract public function is_enabled(): bool;
	abstract public function init(): void;

	public function boot(): void {}

	public function get_disabled_reason(): ?string {
		return null;
	}
}
