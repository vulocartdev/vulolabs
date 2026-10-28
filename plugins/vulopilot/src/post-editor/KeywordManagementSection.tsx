import { __ } from '@wordpress/i18n';
import { Button, PanelBody, Spinner } from '@wordpress/components';
import type { ComponentType } from 'react';
import { usePostData } from './usePostData';
import { useFilterSlot } from '../services/useFilterSlot';

/** Keyword Management - Pro-only. Card shell only; the real panel lives in Pro's `KeywordManagementPanel.tsx`. */
export default function KeywordManagementSection() {
	const { postId } = usePostData();
	const Panel = useFilterSlot< ComponentType< { postId: number } > >(
		'vulopilot_post_editor_keyword_management_panel'
	);

	if ( Panel && postId ) {
		return <Panel postId={ postId } />;
	}

	if ( ! postId ) {
		return (
			<PanelBody title={ __( 'Keyword Management', 'vulopilot' ) } initialOpen={ false }>
				<Spinner />
			</PanelBody>
		);
	}

	return (
		<PanelBody title={ __( 'Keyword Management', 'vulopilot' ) } initialOpen={ false }>
			<p className="small desc">
				{ __( 'Assign a primary keyword and related keywords to this post, and see whether another article already targets the same one.', 'vulopilot' ) }
			</p>
			<Button variant="primary" href={ window.vulopilotPostSeo.shopUrl } target="_blank" rel="noreferrer">
				{ __( 'Upgrade to Pro to Unlock Keyword Management', 'vulopilot' ) }
			</Button>
		</PanelBody>
	);
}
