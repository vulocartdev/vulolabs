/**
 * A finding's `category` (scanner get_category(), e.g. 'seo', 'images', 'schema', 'links',
 * 'accessibility') isn't always the same string as the tab it actually lives on.
 */
const CATEGORY_TAB_LINKS: Record<string, string> = {
	// SEO & Visibility tab, "SEO" subtab - SeoTab.tsx's own SEO_SECTIONS groups the
	// 'seo'/'images'/'schema'/'links' categories into one tab already.
	seo: 'seo-visibility&subtab=seo',
	images: 'seo-visibility&subtab=seo',
	schema: 'seo-visibility&subtab=seo',
	// SEO & Visibility tab, "Broken Links" subtab (BrokenLinksTab.tsx).
	links: 'seo-visibility&subtab=broken-links',
	// SEO & Visibility tab, "GEO" subtab.
	geo: 'seo-visibility&subtab=geo',
	// Reports Overview's "AI Visibility" category tile (ReportsCategoryStatusGrid.tsx) is
	// `ai_visibility_now`/`ai_visibility_then`.
	ai_visibility: 'seo-visibility&subtab=geo',
	brand: 'seo-visibility&subtab=brand-visibility',
	// Its own top-level route (Accessibility.tsx, pages/Accessibility/) -
	// used to be "Protect My Site" → "Accessibility" subtab, moved out.
	accessibility: 'accessibility',
	security: 'security',
	// Site Health's own 5 real sections (SiteHealthTab.tsx, merged with Backups into one page, no
	// inner subtabs).
	wordpress: 'site-health',
	server: 'site-health',
	cron: 'site-health',
	database: 'site-health',
	updates: 'site-health',
	performance: 'performance',
	woocommerce: 'commerce',
	content: 'content',
};

/**
 * @param category A finding's `category` field. Unrecognized/empty
 * categories fall back to the Health page - the one real page that lists
 * every category's open findings unfiltered, so the link always lands
 * somewhere the finding genuinely appears.
 */
export const getCategoryTabHash = (category?: string): string => {
	const target = CATEGORY_TAB_LINKS[category ?? ''] ?? 'health';

	return `#&tab=${target}`;
};
