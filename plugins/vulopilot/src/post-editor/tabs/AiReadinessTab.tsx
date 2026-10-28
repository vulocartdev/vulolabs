import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import type { ComponentType } from 'react';
import { usePostData } from '../usePostData';
import { useFilterSlot } from '../../services/useFilterSlot';

interface AiReadinessTabProps {
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
}

/** AI Readiness score - Pro-only. Card shell only; the real score lives in Pro's `AiReadinessPanel.tsx`. */
export default function AiReadinessTab( _props: AiReadinessTabProps ) {
	const { postId } = usePostData();
	const Panel = useFilterSlot< ComponentType< { postId: number } > >(
		'vulopilot_post_editor_ai_readiness_panel'
	);

	if ( Panel && postId ) {
		return <Panel postId={ postId } />;
	}

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--ai-readiness">
			<p className="small desc">
				{ __( 'AI Readiness checks whether this page is structured the way an LLM can actually parse and reuse - answer-first structure, conversational phrasing, and plain-language clarity.', 'vulopilot' ) }
			</p>
			<Button variant="primary" href={ window.vulopilotPostSeo.shopUrl } target="_blank" rel="noreferrer">
				{ __( 'Upgrade to Pro to Unlock AI Readiness', 'vulopilot' ) }
			</Button>
		</div>
	);
}
