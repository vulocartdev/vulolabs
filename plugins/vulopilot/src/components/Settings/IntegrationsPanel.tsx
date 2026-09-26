import type { ReactNode } from 'react';
import { __ } from '@wordpress/i18n';
import { SectionComponent, FormGroupComponent } from '@zyra/components';
import VuloCloudAiConnectionPanel from './VuloCloudAiConnectionPanel';
import GoogleServicesPanel from './GoogleServicesPanel';
import SiteVerificationPanel from './SiteVerificationPanel';
import PageSpeedStatusPanel from './PageSpeedStatusPanel';
import TagManagerPanel from './TagManagerPanel';

/** One `SectionComponent` (left) + arbitrary content (right) row - the same real `.settings-section-group`/`.settings-left-section`/`.settings-right-section` markup/CSS InputRenderer's own `groupBySections: true` layout uses (NavigatorComponent.scss), and that BackupStoragePanel.tsx/SecurityPanel.tsx already hand-replicate for their own `PanelComponent` tabs - "section, then content," left-to-right, not a title stacked directly on top of its own fields. */
const SectionRow = ({
	icon,
	title,
	desc,
	children,
}: {
	icon: string;
	title: string;
	desc: string;
	children: ReactNode;
}) => (
	<div className="settings-section-group">
		<div className="settings-left-section">
			<SectionComponent icon={icon} title={title} desc={desc} />
		</div>
		<div className="settings-right-section">{children}</div>
	</div>
);

/**
 * Settings → Get Started → Connections.
 *
 * Merges this folder's previous 5 separate sub-tabs (AI Providers, Google
 * Services, PageSpeed Insights, Site Verification, Preferences) into this
 * one tab per direct instruction ("merge all tabs into one tab under get
 * started called connections") - each section below is the same real
 * component its own old standalone tab already used
 * (GoogleServicesPanel.tsx/SiteVerificationPanel.tsx unchanged). No real
 * setting/backend changed shape; only where the UI for it lives.
 *
 * "Preferences" (`site_tone`) moved out to the Business Information sub-tab
 * per direct instruction ("move image 1 settings before image 2
 * settings") - it renders there (`BusinessInformation.ts`'s own
 * declarative `modal`, above its own "Business" fields) instead of here.
 *
 * Each `SectionRow` above is the real `.settings-section-group` two-column
 * layout - icon/title/desc on the left, that section's own real component
 * on the right - per direct instruction ("make this tab design good like
 * section then content"), replacing an earlier pass that stacked a plain
 * `CardHeader` title directly above each panel in one column.
 *
 * "Tag Manager" (TagManagerPanel.tsx) - moved in from Scanning → SEO &
 * Content (`SeoContent.ts`'s own former last section, removed) per direct
 * instruction, rendered above "Webmaster Tools." Same real
 * `tag_manager_enabled`/`tag_manager_container_id` keys, unchanged backend
 * (`Services\TagManagerService`) - only where the UI for it lives moved.
 *
 * "PageSpeed Insights" (PageSpeedStatusPanel.tsx) - briefly moved to the
 * Business Information sub-tab's own former hand-built `PanelComponent`
 * (`BusinessInformationPanel.tsx`, deleted - that sub-tab is a plain
 * declarative `modal` again now, see `BusinessInformation.ts`'s own
 * docblock), moved back here per a later direct instruction, this time
 * rendered last, after "Webmaster Tools." Kept bare (not wrapped in its
 * own `SectionRow`) since it already renders a complete self-contained
 * header (badge/description/"Test Connection" via `CardHeader`) -
 * wrapping it in a 2nd `SectionRow` would duplicate that title/description
 * a 2nd time.
 *
 * `Integrations.ts`'s own `modal` array now also lists `psi_api_key`/
 * `psi_daily_limit` alongside every other real flat key every section
 * below reads/writes (Google Services' 4 tracking toggles, Site
 * Verification's 10 webmaster keys) - purely so Settings.tsx's own
 * per-tab seeding logic (`fieldKeys` from `modal[].key`) populates
 * SettingContext with their current values before any of these components
 * mount and read them via `useSetting()` - same role every other
 * `PanelComponent` tab's own `modal` array already plays.
 */
const IntegrationsPanel = () => {
	return (
		<>
			<SectionRow
				icon="ai"
				title={__('VuloCloud AI', 'vulopilot')}
				desc={__(
					'Connect this site to VuloCloud to enable AI features across VuloPilot.',
					'vulopilot'
				)}
			>
				<VuloCloudAiConnectionPanel />
			</SectionRow>
			<SectionRow
				icon="google"
				title={__('Google Services', 'vulopilot')}
				desc={__(
					'Connect your Google account to allow VuloPilot to fetch real data from Google services.',
					'vulopilot'
				)}
			>
				<GoogleServicesPanel />
			</SectionRow>
			<SectionRow
				icon="Shortcode"
				title={__('Tag Manager', 'vulopilot')}
				desc={__(
					'Connect your Google account to access search performance, indexing information, and website traffic. Set up Google Analytics (https://support.google.com/analytics/answer/9304153)',
					'vulopilot'
				)}
			>
				<TagManagerPanel />
			</SectionRow>
			<SectionRow
				icon="web-page-website"
				title={__('PageSpeed Insights', 'vulopilot')}
				desc={__(
					'Get real-performance data and optimization insights directly from Google PageSpeed Insights.',
					'vulopilot'
				)}
			>
				<PageSpeedStatusPanel />
			</SectionRow>
			<SectionRow
				icon="check"
				title={__('Webmaster Tools', 'vulopilot')}
				desc={__(
					'Enter verification codes for third-party webmaster tools. Each one is rendered as its own <meta> tag on every page.',
					'vulopilot'
				)}
			>
				<SiteVerificationPanel />
			</SectionRow>
		</>
	);
};

export default IntegrationsPanel;
