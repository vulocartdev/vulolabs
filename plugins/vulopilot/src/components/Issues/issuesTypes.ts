/**
 * Shared `FindingGroup`/`Finding` types and helpers (`formatAffected()`, `issueIconFor()`,
 * `CATEGORY_LABELS`, `CATEGORY_TABS`, …) behind every "Issues" table in this plugin.
 */
export interface FindingSample {
	id: number;
	title: string;
	description: string;
	object_type: string | null;
	object_ref: string | null;
	created_at: string;
	/** When this finding was last reconfirmed by a scan. */
	last_seen_at?: string;
	/** Resolved page path or 'Site-wide' - added server-side. */
	page?: string;
	/** Raw JSON-encoded meta column. */
	meta?: string | null;
}

export interface FindingGroup {
	scanner_id: string;
	category: string;
	count: number;
	severity: 'critical' | 'high' | 'medium' | 'low' | 'info';
	object_type: string | null;
	/** Scanner's real get_label(), e.g. "Weak Password Detection". */
	label: string;
	/**
	 * One real, most-recent open finding from this group.
	 */
	sample: FindingSample | null;
	/** Set by the issues table for a group fixed this session, so it stays listed as Fixed. */
	fixed?: boolean;
	/** Finding ids whose fix can still be undone, when the group came from the fixed-issues list. */
	undo_ids?: number[];
	/** Set when this scanner has a mapped fix; null/absent when it doesn't or the feature isn't unlocked. */
	fix_action_id?: string | null;
	/** Set when fix_action_id is null - why this scanner's findings need a manual/admin decision instead. */
	no_fix_reason?: string | null;
	/** The real admin screen no_fix_reason points at, when one exists. */
	no_fix_link?: { url: string; label: string } | null;
	/** Step-by-step version of no_fix_reason, shown as a numbered "Recommended fix" list when present. */
	no_fix_steps?: string[] | null;
}

/**
 * Real category strings (Scanners/*::get_category()) mapped to display labels.
 */
export const CATEGORY_LABELS: Record<string, string> = {
	seo: 'SEO & Visibility',
	images: 'SEO & Visibility',
	schema: 'SEO & Visibility',
	links: 'SEO & Visibility',
	geo: 'AI Visibility',
	brand: 'AI Visibility',
	accessibility: 'Accessibility',
	security: 'Security',
	'rest-api': 'Security',
	ssl: 'Security',
	performance: 'Performance',
	woocommerce: 'WooCommerce',
	content: 'Content',
	plugins: 'Site Health',
	themes: 'Site Health',
	'php-warnings': 'Site Health',
};

/** The Issues table's category tab bar - each tab folds several `category` DB values into one tab. */
export const CATEGORY_TABS: { id: string; label: string; categories: string[] }[] = [
	{ id: 'seo', label: 'SEO & Visibility', categories: ['seo', 'images', 'schema', 'links'] },
	{ id: 'ai-visibility', label: 'AI Visibility', categories: ['geo', 'brand'] },
	{ id: 'security', label: 'Security', categories: ['security', 'rest-api', 'ssl'] },
	{ id: 'performance', label: 'Performance', categories: ['performance'] },
	{ id: 'woocommerce', label: 'WooCommerce', categories: ['woocommerce'] },
	{ id: 'content', label: 'Content', categories: ['content'] },
	{ id: 'accessibility', label: 'Accessibility', categories: ['accessibility'] },
	{ id: 'site-health', label: 'Site Health', categories: ['plugins', 'themes', 'php-warnings'] },
];

/** Which CATEGORY_TABS entry a real `category` value belongs to. */
export const findTabIdForCategory = (category: string): string =>
	CATEGORY_TABS.find((tab) => tab.categories.includes(category))?.id ?? 'all';

export const CATEGORY_ICONS: Record<string, string> = {
	seo: 'search-discovery yellow',
	images: 'search-discovery blue',
	schema: 'search-discovery blue',
	links: 'search-discovery blue',
	geo: 'geo-location pink',
	brand: 'star teal',
	accessibility: 'security lime',
	security: 'security lime',
	'rest-api': 'security lime',
	ssl: 'security lime',
	performance: 'bar-chart rose',
	woocommerce: 'woocommerce rose',
	content: 'document orange',
	plugins: 'coding lime',
	themes: 'coding lime',
	'php-warnings': 'coding lime',
};

/**
 * Per-`scanner_id` icons for categories (such as Performance) where `CATEGORY_ICONS` would give
 * every check the same icon.
 */
export const SCANNER_ICONS: Record<string, string> = {
	'cache-detection': 'refresh-bold blue',
	cdn: 'global-community indigo',
	'css-optimization': 'coding sky',
	'javascript-optimization': 'shortcode yellow',
	'large-images': 'image green',
	fonts: 'text-fields red',
	'lazy-loading': 'eye teal',
	'database-cleanup': 'database orange',
	'heavy-plugins': 'module orange',
	'slow-pages': 'analytics violet',
};

/** Prefers SCANNER_ICONS[scannerId]; several scanners share a category and would otherwise render the same glyph. */
export const issueIconFor = (
	category: string,
	scannerId: string
): string => SCANNER_ICONS[scannerId] ?? CATEGORY_ICONS[category] ?? 'issue';

/** count + a real object_type field → a display noun for the "Affected" column, e.g. "8 images"/"1 endpoint". */
const OBJECT_TYPE_NOUNS: Record<string, [string, string]> = {
	post: ['page', 'pages'],
	attachment: ['image', 'images'],
	product: ['product', 'products'],
	user: ['account', 'accounts'],
	url: ['endpoint', 'endpoints'],
	plugin: ['plugin', 'plugins'],
	theme: ['theme', 'themes'],
	table: ['table', 'tables'],
	file: ['file', 'files'],
	site: ['site-wide check', 'site-wide checks'],
};

/** Just the noun half of `formatAffected` (no count prefix) - for a caller that renders the count and noun as two separate pieces. */
export const getObjectTypeNoun = (count: number, objectType: string | null): string => {
	const [singular, plural] = OBJECT_TYPE_NOUNS[objectType ?? ''] ?? ['issue', 'issues'];

	return 1 === count ? singular : plural;
};

export const formatAffected = (count: number, objectType: string | null): string =>
	`${count} ${getObjectTypeNoun(count, objectType)}`;

/**
 * A single scanner_id can report several unrelated finding groups - this is the real unique
 * group identity (`scanner_id, category, object_type`), used instead of bare `scanner_id` for
 * every row-selection/table-row id in this plugin's issue tables.
 */
export const groupKey = (
	group: Pick<FindingGroup, 'scanner_id' | 'object_type'>
): string => (group.object_type ? `${group.scanner_id}::${group.object_type}` : group.scanner_id);
