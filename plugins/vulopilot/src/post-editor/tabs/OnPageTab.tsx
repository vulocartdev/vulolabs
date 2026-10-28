import { __ } from '@wordpress/i18n';
import { PanelBody } from '@wordpress/components';
import PageAnalysisTab from './PageAnalysisTab';
import SocialTab from './SocialTab';
import SchemaTab from './SchemaTab';

import type { SeoIssueEditorTab } from '../../services/seoIssueEditorTarget';

interface OnPageTabProps {
	highlightTarget?: string;
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: SeoIssueEditorTab, target?: string ) => void;
}

/**
 * Groups the three on-page settings tabs (Page Analysis, Social, Schema) under one sidebar tab -
 * each sub-section is its own existing, unmodified component, just wrapped in a `PanelBody`
 * accordion instead of three separate top-level icon-only tabs. Reduces the sidebar's tab count
 * without touching any of the underlying analysis/save logic.
 */
export default function OnPageTab( props: OnPageTabProps ) {
	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--on-page">
			<PanelBody title={ __( 'Page Analysis', 'vulopilot' ) } initialOpen>
				<PageAnalysisTab { ...props } />
			</PanelBody>
			<PanelBody title={ __( 'Social', 'vulopilot' ) } initialOpen={ false }>
				<SocialTab { ...props } />
			</PanelBody>
			<PanelBody title={ __( 'Schema', 'vulopilot' ) } initialOpen={ false }>
				<SchemaTab { ...props } />
			</PanelBody>
		</div>
	);
}
