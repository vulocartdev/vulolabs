import type { ComponentType } from 'react';

export {};

declare global {
	/**
	 * Shape of the `vulopilotAppLocalizer` object localized by
	 * FrontendScripts::localize_scripts() - keep this in sync with that
	 * method's wp_localize_script() payload.
	 */
	interface AppLocalizer {
		apiUrl: string;
		restUrl: string;
		nonce: string;
		plugin_url: string;
		admin_url: string;
		site_url: string;
		/** `get_bloginfo('name')` - Settings → Get Started → Title Formats' own Live Title Preview reads this directly rather than round-tripping a REST call. */
		site_title: string;
		/** `get_bloginfo('description')` - see `site_title` above. */
		site_description: string;
		/** Homepage thumbnail: front page featured image, else custom logo, else site icon - `''` when none exist (FrontendScripts::get_home_preview_image()). */
		home_preview_image: string;
		/** The real logged-in WP user's own display name (`wp_get_current_user()->display_name`) - e.g. AiContentAssistantSidebar.tsx's own "Hi {name}!" greeting. */
		current_user_display_name: string;
		version: string;
		plugin_slug: string;
		text_domain: string;
		date_format: string;
		/** Settings → General → Date Format, translated into zyra's own token syntax (YYYY/MM/DD/…) - pass this as TableCard's `format` prop, or through services/formatWpDate.ts for a raw date string outside a table. */
		date_format_js: string;
		/** Settings → General → Time Format, same real token conversion as `date_format_js` above - use through services/formatWpDate.ts's own `formatWpTime()`. No am/pm token exists in zyra's syntax, so a 12-hour format renders without the AM/PM suffix, same already-accepted limitation `date_format_js` itself carries. */
		time_format_js: string;
		/** Settings → General → Timezone, as this site's current UTC offset in minutes (`wp_timezone()`, DST-aware for a real `timezone_string`) - every raw timestamp this plugin's REST layer returns is UTC, so services/formatWpDate.ts's own `formatWpDate()`/`formatWpTime()` add this before reading date/time parts, rather than leaving the browser to guess (and silently apply its own local zone instead of this site's configured one). */
		gmt_offset_minutes: number;
		khali_dabba: boolean;
		active_modules: string[];
		vulocloud_connected: boolean;
		vulocloud_account_email: string;
		shop_url: string;
		pro_data: {
			version: string | false;
			manage_plan_url: string;
		};
		/** Every real public post type this site has registered beyond the 4 Settings → Sitemap's own "Post types in sitemap" checkbox list already hardcodes (post/page/attachment/product) - a theme/plugin-registered custom post type, so it shows up there as a real, checkable option (`FrontendScripts::get_sitemap_custom_post_types()`). Empty array on a site with no custom post types. */
		sitemap_custom_post_types: { value: string; label: string }[];
		/** Whether WooCommerce is active on this site (`class_exists('WooCommerce')`) - Settings → Instant Indexing's own "Products" post-type option (IndexNowPanel.tsx) hides itself when this is false, same real gate Settings → Sitemap's own "Products"/"Product Categories"/"Product Tags" options already document (just not yet enforced there client-side). */
		has_woocommerce: boolean;
	}


	var vulopilotAppLocalizer: AppLocalizer;

	/**
	 * Shape of the `vulopilotPostSeo` object localized by
	 * Services\PostEditorAssets::enqueue_assets() - the post-editor SEO
	 * metabox's own script handle, separate from `vulopilotAppLocalizer` since the
	 * Block Editor screen doesn't guarantee the dashboard's own localized
	 * script has run.
	 */
	interface VuloPilotPostSeoLocalizer {
		apiUrl: string;
		nonce: string;
		isPro: boolean;
		shopUrl: string;
		/** Postmeta key strings, keyed by field name - Services\PostSeoMetaFields::META_KEYS plus 'schema_json' (AIActions\Actions\GenerateSchemaAction::META_KEY), so this bundle never hand-copies the literal strings. */
		metaKeys: Record<string, string>;
	}

	var vulopilotPostSeo: VuloPilotPostSeoLocalizer;

	/* eslint-disable no-unused-vars */
	interface Window {
		VULOPILOT_ROUTES: {
			tab: string;
			component: ComponentType<Record<string, unknown>>;
		}[];
		registerVuloPilotRoute: (route: {
			tab: string;
			component: ComponentType<Record<string, unknown>>;
		}) => void;
		vulopilotPostSeo: VuloPilotPostSeoLocalizer;
	}
	/* eslint-enable no-unused-vars */
}
