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
 */
if ( ! defined( 'VULOPILOT_GOOGLE_CLIENT_ID' ) ) {
	define( 'VULOPILOT_GOOGLE_CLIENT_ID', '' );
}
if ( ! defined( 'VULOPILOT_GOOGLE_CLIENT_SECRET' ) ) {
	define( 'VULOPILOT_GOOGLE_CLIENT_SECRET', '' );
}

if ( ! defined( 'VULOPILOT_GOOGLE_BROKER_URL' ) ) {
	define( 'VULOPILOT_GOOGLE_BROKER_URL', '' );
}

if ( ! defined( 'VULOPILOT_GOOGLE_APPLICATION_ID' ) ) {
	define( 'VULOPILOT_GOOGLE_APPLICATION_ID', '' );
}

if ( ! defined( 'VULOPILOT_VULOCLOUD_URL' ) ) {
	define( 'VULOPILOT_VULOCLOUD_URL', 'https://vulocloud-api.vercel.app' );
}

if ( ! defined( 'VULOPILOT_VULOCLOUD_PUBLIC_URL' ) ) {
	define( 'VULOPILOT_VULOCLOUD_PUBLIC_URL', '' );
}

if ( ! defined( 'VULOPILOT_VULOCLOUD_HOST_ORGANIZATION_ID' ) ) {
	define( 'VULOPILOT_VULOCLOUD_HOST_ORGANIZATION_ID', '9a1e8c91-bbb9-4f7b-b4f1-b5f1190287d3' );
}

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
