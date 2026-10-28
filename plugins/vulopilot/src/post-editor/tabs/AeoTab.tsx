import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import type { ComponentType } from 'react';
import { usePostData } from '../usePostData';
import { useFilterSlot } from '../../services/useFilterSlot';

interface AeoTabProps {
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
}

/** AEO citation check - Pro-only. Card shell only; the real check is Pro's `AeoCitationCheckPanel.tsx`. */
export default function AeoTab( _props: AeoTabProps ) {
	const { postId } = usePostData();
	const Panel = useFilterSlot< ComponentType< { postId: number } > >(
		'vulopilot_post_editor_aeo_citation_panel'
	);

	if ( Panel && postId ) {
		return <Panel postId={ postId } />;
	}

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--aeo">
			<p className="small desc">
				{ __( 'AEO tests whether an AI model already cites this site, unprompted, when asked this page\'s own question.', 'vulopilot' ) }
			</p>
			<Button variant="primary" href={ window.vulopilotPostSeo.shopUrl } target="_blank" rel="noreferrer">
				{ __( 'Upgrade to Pro to Unlock AEO', 'vulopilot' ) }
			</Button>
		</div>
	);
}
