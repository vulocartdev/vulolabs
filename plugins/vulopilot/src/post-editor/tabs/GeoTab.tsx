import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import type { ComponentType } from 'react';
import { usePostData } from '../usePostData';
import { useFilterSlot } from '../../services/useFilterSlot';

interface GeoTabProps {
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
}

/** AI Visibility (GEO) score - Pro-only. Card shell only; the real score lives in Pro's `GeoVisibilityPanel.tsx`. */
export default function GeoTab( _props: GeoTabProps ) {
	const { postId } = usePostData();
	const Panel = useFilterSlot< ComponentType< { postId: number } > >(
		'vulopilot_post_editor_geo_panel'
	);

	if ( Panel && postId ) {
		return <Panel postId={ postId } />;
	}

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--ai-visibility">
			<p className="small desc">
				{ __(
					'AI Visibility scores this page the way an AI answer engine (ChatGPT, Perplexity, AI Overviews) would read it - entity coverage, question coverage, citation readiness, and more.',
					'vulopilot'
				) }
			</p>
			<Button variant="primary" href={ window.vulopilotPostSeo.shopUrl } target="_blank" rel="noreferrer">
				{ __( 'Upgrade to Pro to Unlock AI Visibility', 'vulopilot' ) }
			</Button>
		</div>
	);
}
