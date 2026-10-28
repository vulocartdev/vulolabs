import { __ } from '@wordpress/i18n';
import { Button, PanelBody } from '@wordpress/components';
import type { ComponentType } from 'react';
import { usePostData } from './usePostData';
import { useFilterSlot } from '../services/useFilterSlot';

/** Content Intelligence - Pro-only. Card shell only; the real panel lives in Pro's `ContentIntelligencePanel.tsx`. */
export default function ContentIntelligencePanel() {
	const { postId, title } = usePostData();
	const Panel = useFilterSlot< ComponentType< { postId: number; title: string } > >(
		'vulopilot_post_editor_content_intelligence_panel'
	);

	if ( Panel && postId ) {
		return <Panel postId={ postId } title={ title } />;
	}

	return (
		<PanelBody title={ __( 'Content Intelligence', 'vulopilot' ) } initialOpen>
			<p className="small desc">
				{ __( 'Know whether this title is a genuine content gap, overlaps an existing article, or needs rethinking - before you write it.', 'vulopilot' ) }
			</p>
			<Button variant="primary" href={ window.vulopilotPostSeo.shopUrl } target="_blank" rel="noreferrer">
				{ __( 'Upgrade to Pro to Unlock Content Intelligence', 'vulopilot' ) }
			</Button>
		</PanelBody>
	);
}
