/* global vulopilotAppLocalizer */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ColumnComponent, ContainerComponent } from '@zyra/components';
import { ButtonInput } from '@zyra/inputs';
import IssuesSection, { ContentModeConfig, ContentRowTab } from '../GEO/IssuesSection';
import type { PageRow } from '../GEO/seoIssuesShared';
import ContentQualityCard from './ContentQualityCard';

const LANDING_PAGE_META_KEY = '_vulopilot_landing_page';

/** The 3 scanners that flag per-post content-quality findings. */
const CONTENT_SCANNER_IDS = ['thin-content', 'readability', 'heading-structure'];

const CATEGORY_LABELS: Record<string, string> = {
	'blog-post': __('Blog Post', 'vulopilot'),
	'landing-page': __('Landing Page', 'vulopilot'),
	product: __('Product Description', 'vulopilot'),
	other: __('Page', 'vulopilot'),
};

const CATEGORY_ICONS: Record<string, string> = {
	'blog-post': 'document',
	'landing-page': 'web-page-website',
	product: 'cart',
	other: 'document',
};

const CATEGORIES: ContentModeConfig['categories'] = {
	'blog-post': { label: CATEGORY_LABELS['blog-post'], icon: CATEGORY_ICONS['blog-post'] },
	'landing-page': { label: CATEGORY_LABELS['landing-page'], icon: CATEGORY_ICONS['landing-page'] },
	product: { label: CATEGORY_LABELS.product, icon: CATEGORY_ICONS.product },
	other: { label: CATEGORY_LABELS.other, icon: CATEGORY_ICONS.other },
};

/** Real per-row category test, one per real resource type. */
const RESOURCE_TABS: ContentRowTab[] = [
	{ key: 'all', label: __('All resources', 'vulopilot'), matches: () => true },
	{
		key: 'blog-post',
		label: __('Blog Posts', 'vulopilot'),
		matches: (row: PageRow) => 'blog-post' === row.categoryKey,
	},
	{
		key: 'landing-page',
		label: __('Landing Pages', 'vulopilot'),
		matches: (row: PageRow) => 'landing-page' === row.categoryKey,
	},
	{
		key: 'product',
		label: __('Products', 'vulopilot'),
		matches: (row: PageRow) => 'product' === row.categoryKey,
	},
	{
		key: 'other',
		label: __('Other', 'vulopilot'),
		matches: (row: PageRow) => 'other' === row.categoryKey,
	},
];

/** "Recent Content" - a thin wrapper around the same IssuesSection the SEO/AEO/GEO "All Issues" tables use. */
const RecentContentCard = () => {
	/** Set by a real "Analyze" click in the "Pages & Posts" table below. */
	const [analyzingId, setAnalyzingId] = useState<number | null>(null);

	const content: ContentModeConfig = {
		landingPageMetaKey: LANDING_PAGE_META_KEY,
		categories: CATEGORIES,
		rowTabs: RESOURCE_TABS,
		// This card's own original bespoke toolbar (search + severity/ resource selects + Show
		// ignored + Export CSV).
		toolbarFilters: true,
	};

	return (
		<ContainerComponent>
			<ColumnComponent grid={8}>
				<IssuesSection
					id="content-audit-section"
					scannerIds={CONTENT_SCANNER_IDS}
					title={__('Recent Content', 'vulopilot')}
					titleIcon="edit"
					desc={__('Your most recently published or updated content.', 'vulopilot')}
					issuesColumnLabel={__('Content Issues', 'vulopilot')}
					content={content}
					onAnalyze={setAnalyzingId}
					activePostId={analyzingId}
					headerAction={
						<ButtonInput
							buttons={{
								text: __('View All', 'vulopilot'),
								rightIcon: 'arrow-right',
								color: 'text-purple',
								onClick: (e: { preventDefault: () => void }) => {
									e.preventDefault();
									window.location.href = `${vulopilotAppLocalizer.site_url}/wp-admin/edit.php`;
								},
							}}
						/>
					}
				/>
			</ColumnComponent>
			{analyzingId && (
				<ColumnComponent grid={4}>
					<ContentQualityCard
						postId={analyzingId}
						onClose={() => setAnalyzingId(null)}
					/>
				</ColumnComponent>
			)}
		</ContainerComponent>
	);
};

export default RecentContentCard;
