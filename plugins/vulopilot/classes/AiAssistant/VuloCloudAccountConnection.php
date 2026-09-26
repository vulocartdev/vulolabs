<?php
namespace VuloPilot\AiAssistant;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only account connection state.
 *
 * @version     1.0.0
 * @author      VuloLabs
 */
class VuloCloudAccountConnection {

    private const OPTION_KEY = 'vulopilot_vulocloud_account';

    /**
     * @return array<string, mixed>
     */
    private function get_connection(): array {
        return wp_parse_args(
            get_option( self::OPTION_KEY, array() ),
            array(
                'refresh_token_enc' => '',
                'email'             => '',
                'connected_at'      => '',
            )
        );
    }

    /**
     * @return bool
     */
    public function is_connected(): bool {
        return '' !== $this->get_connection()['refresh_token_enc'];
    }

    /**
     * Account status; never includes a token.
     *
     * @return array{connected: bool, email: string, connected_at: string}
     */
    public function get_status(): array {
        $connection = $this->get_connection();

        return array(
            'connected'    => $this->is_connected(),
            'email'        => $connection['email'],
            'connected_at' => $connection['connected_at'],
        );
    }
}
