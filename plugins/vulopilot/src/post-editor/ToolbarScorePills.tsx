import { __ } from '@wordpress/i18n';
import { useSelect } from '@wordpress/data';
import { usePostScores } from './usePostScores';

/** Same 3-tier rating band `PageAnalysisTab.tsx`'s own `IssueSection` summary pill uses, in the colors Rank Math's own toolbar score pill uses (green/orange/red). */
const ratingModifier = ( score: number | null ): string => {
	if ( null === score ) {
		return 'loading';
	}
	if ( score >= 80 ) {
		return 'good';
	}
	if ( score >= 50 ) {
		return 'ok';
	}
	return 'bad';
};

interface ToolbarScorePillsProps {
	/** Opens the sidebar on the General tab (SEO title/description live there) - PostScoreBadges.tsx's own `onNavigate('general')` already uses the same target from inside the sidebar. */
	onClickSeo: () => void;
	/** Opens the sidebar on the AI Visibility tab (AEO/GEO/AI-readiness scoring). */
	onClickAeo: () => void;
}

/**
 * Toolbar score pills - same SEO/AEO scores PostScoreBadges.tsx shows in the sidebar, portaled
 * into the header via `useToolbarScorePillsPortal()` (PluginSidebar's `icon` clips anything
 * beyond a small dashicon). Each pill is its own button so clicking one opens straight to that
 * score's own tab.
 */
export default function ToolbarScorePills( { onClickSeo, onClickAeo }: ToolbarScorePillsProps ) {
	const postId = useSelect( ( select ) => ( select( 'core/editor' ) as any ).getCurrentPostId(), [] );
	const { seoScore, aeoScore } = usePostScores( postId ?? 0 );

	return (
		<div className="vulopilot-toolbar-score-pills">
			<button
				type="button"
				className={ `vulopilot-toolbar-score-pill vulopilot-toolbar-score-pill--${ ratingModifier( seoScore ) }` }
				onClick={ onClickSeo }
				title={ __( 'SEO title, description and on-page details', 'vulopilot' ) }
			>
				<i className="dashicons dashicons-awards" />
				{ null === seoScore ? '…' : seoScore }
				/100
			</button>
			<button
				type="button"
				className={ `vulopilot-toolbar-score-pill vulopilot-toolbar-score-pill--${ ratingModifier( aeoScore ) }` }
				onClick={ onClickAeo }
				title={ __( 'AI visibility (AEO/GEO)', 'vulopilot' ) }
			>
				<i className="dashicons dashicons-lightbulb" />
				{ null === aeoScore ? '…' : aeoScore }
				/100
			</button>
		</div>
	);
}
