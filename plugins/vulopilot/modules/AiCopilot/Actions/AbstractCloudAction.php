<?php
/**
 * AbstractCloudAction class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot\Actions;

use VuloPilot\Utill\CloudAIActionInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Base for free-tier actions that run an AI-service feature instead of building a prompt.
 *
 * @class       AbstractCloudAction class
 * @version     1.0.0
 * @author      VuloLabs
 */
abstract class AbstractCloudAction extends AbstractBasicAction implements CloudAIActionInterface {

	/**
	 * Never called: ActionRunner sends build_cloud_context() instead.
	 *
	 * @param array $input Normalized input.
	 * @return array
	 *
	 * @throws \LogicException Always.
	 */
	public function build_prompt( array $input ): array {
		throw new \LogicException( esc_html( static::class . ' runs an AI-service feature and has no prompt.' ) );
	}
}
