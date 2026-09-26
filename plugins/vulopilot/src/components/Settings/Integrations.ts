import { __ } from '@wordpress/i18n';
import IntegrationsPanel from './IntegrationsPanel';

/**
 * Settings → Integrations. Standalone top-level tab (moved out of the old
 * "Get Started"/Business Visibility folder, which is gone now that every
 * one of its sub-tabs moved elsewhere) - renamed from "Connections"
 * (`id: 'connections'`) to "Integrations" (`id: 'integrations'`) as part
 * of that same restructure.
 *
 * `IntegrationsPanel.tsx` and the real per-provider panel components it
 * composes (`GoogleServicesPanel.tsx`/
 * `PageSpeedStatusPanel.tsx`/`SiteVerificationPanel.tsx`/
 * `TagManagerPanel.tsx`) moved alongside this file into `Settings/`
 * directly - they're plain `.tsx` components, not settings-tab configs
 * (`templateService.ts`'s own `require.context` only scans `.ts$` files),
 * so this file moving changes nothing about how those resolve; the
 * relative import above is `./IntegrationsPanel` (same folder).
 *
 * Every real deep link to this tab's old `subtab=connections` value
 * (`VisibilityBySourceCard.tsx`, `CrawlRobotsSitemapSection.tsx`,
 * `PerformanceScoreCard.tsx`, `SlowPagesTab.tsx`, `Modules/index.ts`'s own
 * `settingsLink`) has been updated to `subtab=integrations` alongside this
 * rename - `getSettingById()` (`@zyra/core`) resolves a `subtab` by real
 * `id` alone, recursing through folders, so those links only work once
 * they carry the new id.
 *
 * `modal` below is the same union of real flat setting keys the old
 * Connections.ts carried - still needed even though `PanelComponent`
 * bypasses InputRenderer entirely, purely so Settings.tsx's own per-tab
 * seeding logic (`fieldKeys` from `modal[].key`) populates SettingContext
 * with their current values before IntegrationsPanel.tsx's own components
 * mount and read them via `useSetting()`.
 */
export default {
	id: 'integrations',
	priority: 7,
	headerTitle: __('Integrations', 'vulopilot'),
	headerDescription: __(
		'Connect VuloPilot to AI services, Google services, and verify your site ownership.',
		'vulopilot'
	),
	hideSettingHeader: true,
	groupBySections: true,
	headerIcon: 'link',
	submitUrl: 'settings',
	modal: [
		// Google Services.
		{ key: 'ga_install_tracking_code', type: 'checkbox', label: '', options: [] },
		{ key: 'ga_anonymize_ip', type: 'checkbox', label: '', options: [] },
		{ key: 'ga_self_hosted_js', type: 'checkbox', label: '', options: [] },
		{ key: 'ga_exclude_logged_in_users', type: 'checkbox', label: '', options: [] },
		// Tag Manager (TagManagerPanel.tsx) - moved in from Scanning → SEO
		// & Content per direct instruction, rendered above Webmaster Tools.
		{ key: 'tag_manager_enabled', type: 'checkbox', label: '', options: [] },
		{ key: 'tag_manager_container_id', type: 'text', label: '' },
		// Site Verification.
		{ key: 'webmaster_google_verification', type: 'text', label: '' },
		{ key: 'webmaster_google_verified_at', type: 'text', label: '' },
		{ key: 'webmaster_bing_verification', type: 'text', label: '' },
		{ key: 'webmaster_bing_verified_at', type: 'text', label: '' },
		{ key: 'webmaster_pinterest_verification', type: 'text', label: '' },
		{ key: 'webmaster_pinterest_verified_at', type: 'text', label: '' },
		{ key: 'webmaster_baidu_verification', type: 'text', label: '' },
		{ key: 'webmaster_yandex_verification', type: 'text', label: '' },
		{ key: 'webmaster_norton_verification', type: 'text', label: '' },
		{ key: 'webmaster_custom_tags', type: 'textarea', label: '' },
		// PageSpeed Insights (PageSpeedStatusPanel.tsx) - moved here per
		// direct instruction, rendered after Webmaster Tools above.
		{ key: 'psi_api_key', type: 'text', label: '' },
		{ key: 'psi_daily_limit', type: 'text', label: '' },
	],
	PanelComponent: IntegrationsPanel,
};
