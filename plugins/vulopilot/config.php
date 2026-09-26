<?php
/**
 * VuloPilot config file.
 *
 * @package VuloPilot
 */

defined( 'ABSPATH' ) || exit;

define( 'VULOPILOT_PLUGIN_TEXTDOMAIN', 'vulopilot' );
define( 'VULOPILOT_PLUGIN_VERSION', '1.0.0' );
define( 'VULOPILOT_PLUGIN_SLUG', 'vulopilot' );
// The one place this plugin's own human-readable name lives - read by
// Services\SiteTelemetryReporter for the "Plugin" field it reports to
// VuloCloud, so that field is this plugin's own real name (whatever a
// fork/rebrand sets it to here), never a hardcoded literal naming a
// different, unrelated plugin.
define( 'VULOPILOT_PLUGIN_NAME', 'VuloPilot' );
define( 'VULOPILOT_PRO_SHOP_URL', 'https://vulopilot.com/pricing/?utm_source=wpadmin&utm_medium=pluginsettings&utm_campaign=vulopilot' );

/**
 * VuloPilot's OWN shared Google Cloud OAuth Client - ONE Client ID/Secret
 * used by every install's "Connect Google Services" button whenever
 * VULOPILOT_GOOGLE_BROKER_URL below isn't set, so a site owner never
 * enters their own Client ID/Secret.
 *
 * wp-config.php-only (not defined here): config.php ships in the plugin
 * zip and is committed to git, so a real secret here would be permanently
 * recoverable from git history. Dev values live in
 * a gitignored local override file.
 *
 * Two trade-offs this embedded-Client path still has even with the
 * secret kept out of committed files:
 * 1. Confidentiality - still only as safe as the customer server's file
 *    access (inherent to any embedded/installed-app OAuth client).
 *    VULOPILOT_GOOGLE_BROKER_URL closes this for real since VuloCloud's
 *    secret never reaches the customer server.
 * 2. Redirect URI scaling - Google OAuth only accepts a fixed
 *    pre-registered redirect URI allowlist, but each site has its own
 *    domain-specific redirect URI, so only allowlisted domains complete
 *    the handshake. VULOPILOT_GOOGLE_BROKER_URL replaces this path
 *    entirely once VuloCloud implements GOOGLE_CONNECT_BROKER.md.
 */
if ( ! defined( 'VULOPILOT_GOOGLE_CLIENT_ID' ) ) {
	define( 'VULOPILOT_GOOGLE_CLIENT_ID', '' );
}
if ( ! defined( 'VULOPILOT_GOOGLE_CLIENT_SECRET' ) ) {
	define( 'VULOPILOT_GOOGLE_CLIENT_SECRET', '' );
}

/**
 * Optional dynamic "Google Connect" broker on the VuloCloud platform -
 * the real fix for trade-off #2 above (redirect URI scaling). When set,
 * GoogleServicesConnection routes through `{this url}/plugin/google/authorize`
 * instead of straight to accounts.google.com: VuloCloud holds the one
 * registered OAuth Client and hands this site a short-lived code to
 * redeem server-to-server, so any customer domain works without a
 * Google-side allowlist entry. See GOOGLE_CONNECT_BROKER.md for the
 * `/plugin/google/*` contract.
 *
 * Empty by default - GoogleServicesConnection::has_broker() then falls
 * back to the embedded shared-Client flow above.
 */
if ( ! defined( 'VULOPILOT_GOOGLE_BROKER_URL' ) ) {
	define( 'VULOPILOT_GOOGLE_BROKER_URL', '' );
}

/**
 * This site's registered VuloCloud `LicenseApplication` id - reused (not a
 * new registration concept) as the broker's own resolution key: VuloCloud's
 * `/plugin/google/*` endpoints have no session/auth of their own, so they
 * resolve `applicationId` -> Organization -> that Organization's own Google
 * Cloud OAuth Client (`OrganizationGoogleSettings`) the exact same way
 * `/plugin/license/validate` already resolves it to an Organization's
 * License data. See `vulocloud`'s `GOOGLE_CONNECT_INTEGRATION.md` for the
 * server-side contract. Same non-secret, admin-UI-visible identifier
 * VULOPILOT_PRO_APPLICATION_ID already is on the Licensing side - not
 * secret, but still wp-config.php-only for now since there's no
 * settings-panel field for it yet (GoogleServicesConnection::has_broker()
 * requires this to be non-empty in addition to VULOPILOT_GOOGLE_BROKER_URL).
 *
 * Empty by default - GoogleServicesConnection::has_broker() honestly
 * reports false without it, and "Connect Google Services" falls back to
 * the embedded shared-Client flow above, exactly as it worked before this
 * constant existed.
 */
if ( ! defined( 'VULOPILOT_GOOGLE_APPLICATION_ID' ) ) {
	define( 'VULOPILOT_GOOGLE_APPLICATION_ID', '' );
}

/**
 * Base URL of the VuloCloud platform's own public API (its
 * `identity-access` bounded context - `POST {url}/auth/login`, same
 * "one dedicated server, no per-site registration needed" shape
 * VULOPILOT_PRO_LICENSE_SERVER_URL already has for Licensing, just a
 * different bounded context - this is a *person* logging into
 * their own VuloCloud account (VuloCloudAccountConnection), never a
 * per-site Product ID/License Key pair. See useContentGate.tsx/
 * useVuloCloudAccountLogin.ts (both in src/services/) for the real
 * feature this backs - the "log in" tier every one of that hook's own 3
 * gates checks first.
 *
 * Unlike VULOPILOT_GOOGLE_CLIENT_ID above, this isn't a per-site secret -
 * it's VuloCloud's own public API base, the same for every install of
 * this plugin, so (unlike that constant) it's safe to default to the
 * real production value here rather than requiring every site to define
 * it themselves. wp-config.php can still override it (the `! defined()`
 * guard) - local/Docker dev does exactly that, pointing this at
 * `host.docker.internal` instead (see VULOPILOT_VULOCLOUD_PUBLIC_URL's
 * own docblock immediately below for why dev needs a second, browser-
 * facing override too).
 */
if ( ! defined( 'VULOPILOT_VULOCLOUD_URL' ) ) {
	define( 'VULOPILOT_VULOCLOUD_URL', 'https://vulocloud-api.vercel.app' );
}

/**
 * Browser-facing override of VULOPILOT_VULOCLOUD_URL above, used only when
 * the two differ - a real deployment serves both the API this site's own
 * PHP calls server-to-server AND the hosted pages a human's browser is
 * ever redirected to (AiCreditsConnection::get_broker_authorize_url()) from the
 * one public domain, so VULOPILOT_VULOCLOUD_URL alone is already correct
 * and this constant stays empty/unused there. Local Docker dev is the one
 * place they legitimately differ: WordPress's own container resolves
 * VuloCloud via `host.docker.internal` (only reachable from inside a
 * container, never from the host machine's own browser), while a human's
 * browser needs the real `localhost` port instead. Empty by default -
 * falls back to VULOPILOT_VULOCLOUD_URL wherever it's read.
 */
if ( ! defined( 'VULOPILOT_VULOCLOUD_PUBLIC_URL' ) ) {
	define( 'VULOPILOT_VULOCLOUD_PUBLIC_URL', '' );
}

/**
 * The one, fixed VuloLabs-owned Organization id solo site owners register
 * under when they pick "I'm a solo site owner" in the AI Credits connect
 * panel (AiCreditsIndicator.tsx) instead of "I manage multiple client
 * sites" - VuloCloud's Customer Portal auth
 * (`organizations/{id}/portal/auth/register|login`) always lives under a
 * specific Organization, unlike the agency path's self-service
 * `POST /organizations` (AiCreditsConnection::resolve_organization_id()),
 * which creates a brand-new one per account. Same "single deploy-time
 * constant, wp-config.php-only for now" shape as
 * VULOPILOT_PRO_APPLICATION_ID/VULOPILOT_GOOGLE_CLIENT_ID.
 *
 * Defaults to VuloLabs' own real production Organization - every fresh
 * install of this plugin can offer the "solo site owner" free-credit
 * path out of the box, with nothing to configure. Override via
 * wp-config.php only if this build should register solo site owners
 * under some other Organization instead (e.g. a white-label fork).
 */
if ( ! defined( 'VULOPILOT_VULOCLOUD_HOST_ORGANIZATION_ID' ) ) {
	define( 'VULOPILOT_VULOCLOUD_HOST_ORGANIZATION_ID', '9a1e8c91-bbb9-4f7b-b4f1-b5f1190287d3' );
}

/**
 * Generic "connect this plugin to a pre-known VuloCloud Organization +
 * Brand" config - VuloCloudConnection's own config source (a plain
 * sibling to AiCreditsConnection above, not a modification of it: that
 * class's connection is unconditionally AI-Credits-shaped - credit
 * balance fields baked into its stored option - and this one carries
 * none of that).
 *
 * Deliberately array-shaped rather than four more flat constants like
 * every other value in this file: this is the one config block meant to
 * be copy/pasted into another plugin's own config.php basically
 * unchanged (only the values differ, never the shape) - see
 * VuloCloudConnection's own class docblock for why nothing downstream of
 * this array ever hardcodes 'vulopilot' anywhere.
 *
 * `plugin_id` becomes ConnectedSite.pluginSlug on the VuloCloud side.
 * `organization_id`/`brand_id` are the one Organization + (optional)
 * Brand this build's Connect button always connects to - never a
 * site-owner choice, unlike VULOPILOT_VULOCLOUD_HOST_ORGANIZATION_ID's
 * own "solo site owner" picker above.
 *
 * `domain` is that Organization's own public storefront/custom domain
 * (e.g. a real store's own "store.example.com") - reference/display
 * data only, NOT the VuloCloud platform's own API base URL. The actual
 * broker/API calls this connection makes still go to the existing
 * VULOPILOT_VULOCLOUD_URL/VULOPILOT_VULOCLOUD_PUBLIC_URL constants
 * above, exactly like AiCreditsConnection's own calls do - see
 * VuloCloudConnection::get_broker_authorize_url()'s own doc comment for
 * why these must not be conflated.
 *
 * `offering_id` is intentionally NOT part of this shape yet - a
 * connection can serve multiple Offerings (fetched later for the
 * pricing page), so it isn't fixed config the way Organization/Brand
 * are.
 *
 * Defaults to VuloLabs' own real production Organization + "VuloPilot"
 * Brand - same "works out of the box, no per-site config needed"
 * reasoning VULOPILOT_VULOCLOUD_HOST_ORGANIZATION_ID's own docblock
 * above gives. A fork of this plugin under a different `plugin_id`
 * overrides the whole array via wp-config.php with its own values -
 * the shape itself (see this constant's own doc comment above) is what's
 * meant to be reused unchanged, not these particular values.
 */
if ( ! defined( 'VULOPILOT_VULOCLOUD_CONFIG' ) ) {
	define(
		'VULOPILOT_VULOCLOUD_CONFIG',
		array(
			'plugin_id'       => 'vulopilot',
			'organization_id' => '9a1e8c91-bbb9-4f7b-b4f1-b5f1190287d3',
			'brand_id'        => '506dde24-bdff-425e-a501-a7df14ed80b5',
			'domain'          => 'https://store.vulolabs.com',
		)
	);
}
