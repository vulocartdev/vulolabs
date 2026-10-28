<?php
/**
 * TriggerInterface file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * @class       TriggerInterface interface
 * @version     1.0.0
 * @author      VuloLabs
 */
interface TriggerInterface {

	/**
	 * @return string Unique, stable trigger id (also the `trigger_type` value
	 *                stored on an automation row).
	 */
	public function get_id(): string;

	/**
	 * @return string Human-readable label.
	 */
	public function get_label(): string;

	/**
	 * Hooks whatever WordPress/cron mechanism this trigger needs, calling $on_fire whenever it fires.
	 *
	 * @param callable $on_fire function( string $trigger_id, string $object_type, ?string $object_ref ): void.
	 * @return void
	 */
	public function register( callable $on_fire ): void;
}
