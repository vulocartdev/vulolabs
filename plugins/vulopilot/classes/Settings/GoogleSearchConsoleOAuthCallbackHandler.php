<?php
namespace VuloPilot\Settings;


defined( 'ABSPATH' ) || exit;

/**
 * Handles Google's real OAuth redirect back to this site
 * (`admin-post.php?action=vulopilot_gsc_oauth_callback` -
 * GoogleServicesConnection::get_redirect_uri()'s own exact URL). Kept as
 * its own tiny class (rather than folding this into
 * Controllers\GoogleServices) for the same reason
 * IndexNowKeyFileServer/LlmsTxtGenerator are their own classes: this hook
 * must be registered unconditionally at plugin boot (VuloPilot.php's
 * init_classes()), not lazily inside a REST controller that's only ever
 * instantiated on `rest_api_init` - a request to `admin-post.php` never
 * fires that hook at all, so a REST-controller-only registration would
 * silently 404 every real Google redirect.
 *
 * Class/action name kept as "gsc" (Search Console) even though the real
 * connection now also covers Analytics/AdSense - renaming would mean
 * every already-registered Google Cloud OAuth Client's "Authorized
 * redirect URI" (a site owner's own real, external Google Cloud config)
 * would silently stop matching. One connection, one redirect URI, for
 * the lifetime of this feature.
 *
 * @class       GoogleSearchConsoleOAuthCallbackHandler class
 * @version     1.0.0
 * @author      VuloLabs
 */
class GoogleSearchConsoleOAuthCallbackHandler {

    public function __construct() {
        add_action( 'admin_post_vulopilot_gsc_oauth_callback', array( $this, 'handle_callback' ) );
    }

    /**
     * Verifies the real `state` nonce, exchanges the real `code` for
     * tokens (GoogleServicesConnection::exchange_code_for_tokens(), an
     * actual `POST` to Google's token endpoint), then redirects back to
     * whichever real SPA tab actually started the connection - Settings'
     * own Google Services panel, or SEO & Visibility's Keywords tab
     * (GoogleServicesConnection::get_return_to_from_state(), read from
     * `state` regardless of whether the nonce inside it still checks out,
     * so even an error redirect lands back where the site owner was
     * rather than always defaulting to Settings) - with a real
     * success/error query flag. Never renders its own page, same
     * "redirect back into the SPA" shape every other admin-post-style
     * handler in this codebase (IndexNowKeyFileServer excluded - that one
     * serves a file, not a redirect) would use if one existed yet.
     *
     * @return void
     */
    public function handle_callback(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to do this.', 'vulopilot' ) );
        }

        $connection = new GoogleServicesConnection();

        $state         = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the nonce inside `state` (verified explicitly below via verify_state()) IS this flow's real CSRF guard; `return_to` is read from this same value regardless of nonce validity, but is itself just an allow-listed plain string (see get_return_to_from_state()'s own docblock), not something that needs the nonce check.
        $redirect_base = 'keywords' === $connection->get_return_to_from_state( $state )
            ? admin_url( 'admin.php?page=vulopilot#&tab=seo-visibility&subtab=keywords' )
            : admin_url( 'admin.php?page=vulopilot#&tab=settings&subtab=google-services' );

        $error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this is Google's own redirect back to us, not a form submission; the `state` param (verified below) is this flow's real CSRF guard.

        if ( '' !== $error ) {
            wp_safe_redirect( $redirect_base . '&gsc_status=error' );
            exit;
        }

        $code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- this whole request only carries a `code` because it came from a `state`-nonced authorize URL we generated ourselves; verified below via verify_state().

        if ( '' === $code || ! $connection->verify_state( $state ) ) {
            wp_safe_redirect( $redirect_base . '&gsc_status=error' );
            exit;
        }

        $result = $connection->has_broker()
            ? $connection->exchange_broker_code_for_tokens( $code )
            : $connection->exchange_code_for_tokens( $code );

        wp_safe_redirect( $redirect_base . '&gsc_status=' . ( is_wp_error( $result ) ? 'error' : 'connected' ) );
        exit;
    }
}
