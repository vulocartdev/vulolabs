/* global vulopilotAppLocalizer */
import { useState, type ComponentType } from 'react';
import { __ } from '@wordpress/i18n';
import { CardComponent, ListComponent, PopupComponent } from '@zyra/components';
import ContentToolPopup from './ContentToolPopup';
import { useFilterSlot } from '../../services/useFilterSlot';
import ShowProPopup, { VuloCloudInlineNotice } from '../../components/Popup/Popup';
import { useAiCredits } from '../../services/useAiCredits';
import { useContentToolsEnabled } from '../../services/useContentToolsEnabled';

export type ToolFieldType =
	| 'post-picker'
	| 'text'
	| 'textarea'
	| 'media-picker'
	| 'duplicate-finding-picker'
	| 'select';

export interface ToolField {
	key: string;
	label: string;
	type: ToolFieldType;
	/** Only for 'post-picker' - restricts which real post types are offered. */
	postTypes?: ('post' | 'page')[];
	/** Only for 'select' - a fixed, static option list rendered directly (no network fetch), unlike the other picker types. */
	options?: { value: string; label: string }[];
}

export interface ContentTool {
	id: string;
	icon: string;
	color: string;
	title: string;
	desc: string;
	/** The real AIActionInterface id this tool runs (classes/AIActions/Actions/*.php). */
	actionId: string;
	fields: ToolField[];
	pro?: boolean;
}

/**
 * The 12 tool tiles each run a real AI action end-to-end: pick the real input it needs (an
 * existing post, an image, a topic - see `fields`).
 */
export const CONTENT_TOOLS: ContentTool[] = [
	{
		id: 'ai-writer',
		icon: 'edit',
		color: 'purple',
		title: __('AI Writer', 'vulopilot'),
		desc: __('Write engaging content with AI in seconds.', 'vulopilot'),
		actionId: 'write-post-content',
		fields: [
			{
				key: 'brief',
				label: __('What should it write about?', 'vulopilot'),
				type: 'textarea',
			},
		],
	},
	{
		id: 'blog-generator',
		icon: 'document',
		color: 'green',
		title: __('Blog Generator', 'vulopilot'),
		desc: __('Generate SEO-optimized blog posts instantly.', 'vulopilot'),
		actionId: 'generate-blog',
		fields: [
			{ key: 'topic', label: __('Topic', 'vulopilot'), type: 'text' },
		],
	},
	{
		id: 'landing-pages',
		icon: 'web-page-website',
		color: 'blue',
		title: __('Landing Pages', 'vulopilot'),
		desc: __('Create high-converting landing pages.', 'vulopilot'),
		actionId: 'generate-landing-page',
		pro: true,
		fields: [],
	},
	{
		id: 'product-descriptions',
		icon: 'cart',
		color: 'orange',
		title: __('Product Descriptions', 'vulopilot'),
		desc: __('Write persuasive product descriptions that sell.', 'vulopilot'),
		actionId: 'generate-product-description',
		pro: true,
		fields: [],
	},
	{
		id: 'faq-generator',
		icon: 'question',
		color: 'red',
		title: __('FAQ Generator', 'vulopilot'),
		desc: __('Generate FAQs that answer customer questions.', 'vulopilot'),
		actionId: 'generate-faq',
		pro: true,
		fields: [],
	},
	{
		id: 'schema-generator',
		icon: 'Shortcode',
		color: 'indigo',
		title: __('Schema Generator', 'vulopilot'),
		desc: __('Create structured data schema markup.', 'vulopilot'),
		actionId: 'generate-schema',
		pro: true,
		fields: [],
	},
	{
		id: 'image-alt-text',
		icon: 'image',
		color: 'green',
		title: __('Image Alt Text', 'vulopilot'),
		desc: __('Generate SEO-friendly alt text for images.', 'vulopilot'),
		actionId: 'generate-alt',
		pro: true,
		fields: [],
	},
	{
		id: 'meta-generator',
		icon: 'price',
		color: 'orange',
		title: __('Meta Generator', 'vulopilot'),
		desc: __('Create meta titles that rank.', 'vulopilot'),
		actionId: 'write-meta-title',
		pro: true,
		fields: [],
	},
	{
		id: 'content-optimizer',
		icon: 'bar-chart',
		color: 'teal',
		title: __('Content Optimizer', 'vulopilot'),
		desc: __('Optimize content for SEO and readability.', 'vulopilot'),
		actionId: 'optimize-content',
		pro: true,
		fields: [],
	},
	{
		id: 'content-refresh',
		icon: 'refresh',
		color: 'blue',
		title: __('Content Refresh', 'vulopilot'),
		desc: __('Update and improve existing content with AI.', 'vulopilot'),
		actionId: 'refresh-content',
		pro: true,
		fields: [],
	},
	{
		id: 'duplicate-content',
		icon: 'copy',
		color: 'pink',
		title: __('Duplicate Content', 'vulopilot'),
		desc: __('Find and fix duplicate content issues.', 'vulopilot'),
		actionId: 'differentiate-duplicate-title',
		fields: [
			{
				key: 'post_ids',
				label: __('Duplicate title group', 'vulopilot'),
				type: 'duplicate-finding-picker',
			},
		],
	},
	{
		id: 'media-library-ai',
		icon: 'media-library',
		color: 'purple',
		title: __('Media Library AI', 'vulopilot'),
		desc: __('Optimize alt text, titles, and captions for an image.', 'vulopilot'),
		actionId: 'optimize-media',
		pro: true,
		fields: [],
	},
];

export interface ProToolsSlot {
	Popup: ComponentType<{
		tool: ContentTool | null;
		onClose: () => void;
		VuloCloudInlineNotice: ComponentType;
		creditsStatus: { connected?: boolean } | null;
	}>;
	tools: ContentTool[];
}

const ContentToolsGrid = () => {
	const [activeTool, setActiveTool] = useState<ContentTool | null>(null);
	const proSlot = useFilterSlot<ProToolsSlot>('vulopilot_content_tools_pro');
	const { status: creditsStatus } = useAiCredits();
	const isContentToolsEnabled = useContentToolsEnabled();
	const [isCloudConnectPromptOpen, setIsCloudConnectPromptOpen] = useState(false);
	const [isProLocked, setIsProLocked] = useState(false);
	const dismissProLocked = () => setIsProLocked(false);

	const handleToolClick = (tool: ContentTool) => {
		if (tool.pro && (!isContentToolsEnabled || !proSlot)) {
			setIsProLocked(true);
			return;
		}

		if (!tool.pro && creditsStatus && !creditsStatus.connected) {
			setIsCloudConnectPromptOpen(true);
			return;
		}

		setActiveTool(tool.pro ? (proSlot?.tools.find((t) => t.id === tool.id) ?? tool) : tool);
	};

	return (
		<>
			<CardComponent
				id="content-tools-grid"
				className="ai-card"
				title={__('Content Tools', 'vulopilot')}
				titleIcon='tools'
				desc={__('AI tools to help you create and improve content.', 'vulopilot')}
			>
				<ListComponent
					className="tool-grid"
					items={CONTENT_TOOLS.map((tool) => ({
						id: tool.id,
						// "{adminfont name} {color-palette key}" - same icon-name-plus-palette- key
						// convention MetricsGrid.tsx/ SecurityMetricsGrid.tsx already use.
						icon: `${tool.icon} ${tool.color}`,
						title: tool.title,
						desc: tool.desc,
						tags: (
							<>
								{tool.pro && (!isContentToolsEnabled || !proSlot) && (
									<span className="admin-tag pro-tag">
										<i className="adminfont-pro-tag" />
										{__('Pro', 'vulopilot')}
									</span>
								)}
								<i className="adminfont-arrow-right" />
							</>
						),
						action: () => handleToolClick(tool),
					}))}
				/>
				{activeTool?.pro && proSlot ? (
					<proSlot.Popup
						tool={activeTool}
						onClose={() => setActiveTool(null)}
						VuloCloudInlineNotice={VuloCloudInlineNotice}
						creditsStatus={creditsStatus}
					/>
				) : (
					<ContentToolPopup
						tool={activeTool?.pro ? null : activeTool}
						onClose={() => setActiveTool(null)}
					/>
				)}
			</CardComponent>
			<PopupComponent
				open={isCloudConnectPromptOpen}
				onClose={() => setIsCloudConnectPromptOpen(false)}
				width={22}
				height="auto"
				position="lightbox"
			>
				<ShowProPopup vulocloud />
			</PopupComponent>
			<PopupComponent
				open={isProLocked}
				onClose={dismissProLocked}
				width={31.25}
				height="auto"
				position="lightbox"
			>
				{vulopilotAppLocalizer.khali_dabba ? (
					<ShowProPopup moduleName="content-optimization" />
				) : (
					<ShowProPopup />
				)}
			</PopupComponent>
		</>
	);
};

export default ContentToolsGrid;
