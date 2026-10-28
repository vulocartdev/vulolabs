import { __, sprintf } from '@wordpress/i18n';

interface PostScoreBadgesProps {
	seoScore: number | null;
	aeoScore: number | null;
	/** `PostSeoPanel.tsx`'s own tab switch - both badges jump to "Page Analysis", the one tab that shows the checks/findings behind each score. */
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: 'page-analysis' ) => void;
}

/**
 * The two score badges shown at the top of every post/page's "VuloPilot SEO" sidebar. Reads
 * scores as props (one `usePostScores()` call lives in `PostSeoPanel.tsx` now, shared with
 * `ScoreImprovementCard.tsx`) rather than fetching its own copy.
 */
export default function PostScoreBadges( { seoScore, aeoScore, onNavigate }: PostScoreBadgesProps ) {
	const goToPageAnalysis = () => onNavigate?.( 'page-analysis' );

	return (
		<div className="vulopilot-seo-score-badges">
			<button
				type="button"
				className="vulopilot-seo-score-badge vulopilot-seo-score-badge--seo"
				onClick={ goToPageAnalysis }
				title={ __( 'View the checks behind this SEO score', 'vulopilot' ) }
			>
				<i className="dashicons dashicons-awards" />
				{ null === seoScore ? sprintf( '… / %d', 100 ) : sprintf( '%d / %d', seoScore, 100 ) }
			</button>
			<button
				type="button"
				className="vulopilot-seo-score-badge vulopilot-seo-score-badge--ai"
				onClick={ goToPageAnalysis }
				title={ __( 'View the AI/AEO findings behind this score', 'vulopilot' ) }
			>
				<i className="dashicons dashicons-lightbulb" />
				{ null === aeoScore ? __( 'Not scanned', 'vulopilot' ) : sprintf( '%d / %d', aeoScore, 100 ) }
			</button>
		</div>
	);
}
