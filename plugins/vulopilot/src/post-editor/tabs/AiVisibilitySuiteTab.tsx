import { __ } from '@wordpress/i18n';
import { PanelBody } from '@wordpress/components';
import OverviewTab from './OverviewTab';
import AiReadinessTab from './AiReadinessTab';
import AeoTab from './AeoTab';
import GeoTab from './GeoTab';
import AiVisibilityCrawlerTab from './AiVisibilityCrawlerTab';
import ContentAnalysisTab from './ContentAnalysisTab';

interface AiVisibilitySuiteTabProps {
	/** None of this suite's sub-tabs use highlight targets - accepted only so `PostSeoPanel.tsx` can pass the same props shape to every tab component uniformly. */
	highlightTarget?: string;
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
}

/**
 * Groups the six "AI Visibility Score" sidebar tabs (Overview, AI Readiness, AEO, GEO, AI
 * Visibility/crawler, Content Analysis) into one tab with accordion sections, instead of six
 * separate icon-only top-level tabs - the sidebar's tab bar had grown to 12 icons, most of them
 * unlabeled and indistinguishable at a glance. Each section is its own existing, unmodified
 * component; only the navigation chrome changed.
 */
export default function AiVisibilitySuiteTab( { onNavigate }: AiVisibilitySuiteTabProps ) {
	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--ai-visibility-suite">
			<PanelBody title={ __( 'Overview', 'vulopilot' ) } initialOpen>
				<OverviewTab onNavigate={ onNavigate } />
			</PanelBody>
			<PanelBody title={ __( 'AI Readiness', 'vulopilot' ) } initialOpen={ false }>
				<AiReadinessTab onNavigate={ onNavigate } />
			</PanelBody>
			<PanelBody title={ __( 'AEO', 'vulopilot' ) } initialOpen={ false }>
				<AeoTab onNavigate={ onNavigate } />
			</PanelBody>
			<PanelBody title={ __( 'GEO', 'vulopilot' ) } initialOpen={ false }>
				<GeoTab onNavigate={ onNavigate } />
			</PanelBody>
			<PanelBody title={ __( 'AI Crawler Visits', 'vulopilot' ) } initialOpen={ false }>
				<AiVisibilityCrawlerTab onNavigate={ onNavigate } />
			</PanelBody>
			<PanelBody title={ __( 'Content Analysis', 'vulopilot' ) } initialOpen={ false }>
				<ContentAnalysisTab onNavigate={ onNavigate } />
			</PanelBody>
		</div>
	);
}
