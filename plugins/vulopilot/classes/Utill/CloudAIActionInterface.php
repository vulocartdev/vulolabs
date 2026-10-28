<?php
/**
 * CloudAIActionInterface file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * An AI action that runs an AI-service feature. ActionRunner sends `build_cloud_context()`
 * to that feature instead of calling build_prompt(), which such an action never needs to
 * implement for real.
 *
 * @class       CloudAIActionInterface
 * @version     1.0.0
 * @author      VuloLabs
 */
interface CloudAIActionInterface extends AIActionInterface {

	/**
	 * The AI-service feature this action runs.
	 *
	 * @return array{0: string, 1: string} `[ featureId, action ]`, e.g. `[ 'rewrite_content', 'rewrite' ]`.
	 */
	public function get_cloud_feature(): array;

	/**
	 * The feature's structured input, built from validate_input()'s result.
	 *
	 * @param array $input Normalized input.
	 * @return array<string, mixed>
	 */
	public function build_cloud_context( array $input ): array;
}
