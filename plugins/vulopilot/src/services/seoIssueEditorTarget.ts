/**
 * Single source of truth for the "All SEO Issues" table's "Fix with AI" deep link
 * (`SeoIssuesByPageTable.tsx`) and the block-editor "VuloPilot SEO" sidebar's own handling of it
 * (`post-editor/index.tsx`).
 */

export type SeoIssueEditorTab =
	| 'general'
	| 'social'
	| 'schema'
	| 'page-analysis'
	| 'overview'
	| 'ai-readiness'
	| 'aeo'
	| 'geo'
	| 'ai-visibility'
	| 'content-analysis'
	| 'recommendations'
	| 'topic-linking';

export interface SeoIssueEditorTarget {
	tab: SeoIssueEditorTab;
	/** OnPageAnalyzer check id (General tab) or field key (Social/Schema, or a General-tab field). */
	target?: string;
}

export const SEO_ISSUE_EDITOR_TARGETS: Record<string, SeoIssueEditorTarget> = {
	seo: { tab: 'page-analysis', target: 'title_length' },
	'meta-description': { tab: 'page-analysis', target: 'description_length' },
	'thin-content': { tab: 'page-analysis', target: 'content_length' },
	'heading-structure': { tab: 'page-analysis', target: 'has_subheadings' },
	'seo-images': { tab: 'page-analysis', target: 'image_alt' },
	images: { tab: 'page-analysis', target: 'image_alt' },
	'internal-linking': { tab: 'page-analysis', target: 'has_links' },
	'canonical-url': { tab: 'general', target: 'canonical_url' },
	'open-graph': { tab: 'social', target: 'social_title' },
	'twitter-card': { tab: 'social', target: 'social_title' },
	schema: { tab: 'schema', target: 'schema_json' },
	'structured-data': { tab: 'schema', target: 'schema_json' },
	'sitewide-structured-data': { tab: 'schema', target: 'schema_json' },
	/** Brand Intelligence's Person/Organization schema checks. */
	'author-schema': { tab: 'schema', target: 'schema_json' },
	'organization-schema': { tab: 'schema', target: 'schema_json' },
	/** AEO's own "Schema Markup" section (AeoTab.tsx) - same real Schema tab every other schema-flavored scanner id above already resolves to. */
	'aeo-schema': { tab: 'schema', target: 'schema_json' },
};

export const getEditorTargetForScanner = (
	scannerId: string
): SeoIssueEditorTarget | null => SEO_ISSUE_EDITOR_TARGETS[scannerId] ?? null;

/** Query-string param name the table and the editor both agree on. */
export const SEO_ISSUE_QUERY_PARAM = 'vulopilot_seo_issue';

/**
 * Separate deep-link param `GEO/PageAnalysisPanel.tsx`'s own checklist uses instead of
 * `SEO_ISSUE_QUERY_PARAM` above.
 */
export const PAGE_ANALYSIS_CHECK_QUERY_PARAM = 'vulopilot_page_analysis_check';

/**
 * A third deep-link param used by the GEO and AEO open-findings tables.
 */
export const FINDING_ID_QUERY_PARAM = 'vulopilot_finding_id';
