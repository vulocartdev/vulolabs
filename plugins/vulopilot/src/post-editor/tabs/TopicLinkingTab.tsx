import { __ } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import type { ComponentType } from 'react';
import { usePostData } from '../usePostData';
import { useFilterSlot } from '../../services/useFilterSlot';

interface TopicLinkingTabProps {
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
}

/** Topic & Internal Linking - Pro-only. Card shell only; the real panel lives in Pro's `TopicLinkingPanel.tsx`. */
export default function TopicLinkingTab( _props: TopicLinkingTabProps ) {
	const { postId, content } = usePostData();
	const Panel = useFilterSlot< ComponentType< { postId: number; content: string } > >(
		'vulopilot_post_editor_topic_linking_panel'
	);

	if ( Panel && postId ) {
		return <Panel postId={ postId } content={ content } />;
	}

	if ( ! postId ) {
		return <Spinner />;
	}

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--topic-linking">
			<p className="small desc">
				{ __( 'Understand this post\'s topic in context of your whole site, find related articles to link to, and close content gaps.', 'vulopilot' ) }
			</p>
			<Button variant="primary" href={ window.vulopilotPostSeo.shopUrl } target="_blank" rel="noreferrer">
				{ __( 'Upgrade to Pro to Unlock Topic & Internal Linking', 'vulopilot' ) }
			</Button>
		</div>
	);
}
