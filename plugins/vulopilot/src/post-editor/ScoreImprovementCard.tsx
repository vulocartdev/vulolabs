import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { dispatch } from '@wordpress/data';
import { Button, Spinner } from '@wordpress/components';
import { autoFixScore, AutoFixScoreResult, SeoScoreRule } from './api';

interface ScoreImprovementCardProps {
	score: number | null;
	rules: SeoScoreRule[];
	postId: number;
	/** Full refetch, called once auto-fix finishes so the toolbar/sidebar badges update immediately. */
	onScoreUpdated?: () => void;
}

const CATEGORY_LABELS: Record< string, string > = {
	on_page: __( 'On-page SEO', 'vulopilot' ),
	content_quality: __( 'Content quality', 'vulopilot' ),
	keyword_optimization: __( 'Keyword optimization', 'vulopilot' ),
	links: __( 'Links', 'vulopilot' ),
	images_media: __( 'Images and media', 'vulopilot' ),
	technical_seo: __( 'Technical SEO', 'vulopilot' ),
};

const RESULT_ICON: Record< AutoFixScoreResult[ 'status' ], string > = {
	fixed: 'yes-alt',
	failed: 'warning',
	skipped: 'minus',
};

/**
 * Lists every actually-scored fail/warning rule, so fixing all of them genuinely reaches 100.
 * Free shows a marketing CTA only; Pro's button runs a real auto-fix with per-issue outcomes.
 */
export default function ScoreImprovementCard( { score, rules, postId, onScoreUpdated }: ScoreImprovementCardProps ) {
	const [ isFixing, setIsFixing ] = useState( false );
	const [ results, setResults ] = useState< AutoFixScoreResult[] | null >( null );
	const [ isOpen, setIsOpen ] = useState( true );

	const actionable = rules.filter(
		( rule ) => 'fail' === rule.status || 'warning' === rule.status
	);

	if ( null === score || 0 === actionable.length ) {
		return null;
	}

	const resultFor = ( ruleId: string ) => results?.find( ( result ) => result.rule_id === ruleId );

	const runAutoFix = () => {
		setIsFixing( true );
		setResults( null );

		autoFixScore( postId )
			.then( ( response ) => {
				setResults( response.results );
				onScoreUpdated?.();

				if ( response.after_score !== null ) {
					( dispatch( 'core/notices' ) as any ).createNotice(
						'success',
						sprintf(
							/* translators: 1: score before auto-fix, 2: score after auto-fix. */
							__( 'Auto-fix ran: score went from %1$d to %2$d.', 'vulopilot' ),
							response.before_score ?? 0,
							response.after_score
						),
						{ type: 'snackbar', isDismissible: true }
					);
				}
			} )
			.catch( () => {
				( dispatch( 'core/notices' ) as any ).createNotice(
					'error',
					__( 'Auto-fix could not run. Try again in a moment.', 'vulopilot' ),
					{ type: 'snackbar', isDismissible: true }
				);
			} )
			.finally( () => setIsFixing( false ) );
	};

	return (
		<div className="vulopilot-seo-score-improvement">
			<button
				type="button"
				className="vulopilot-seo-score-improvement__headline"
				onClick={ () => setIsOpen( ( open ) => ! open ) }
				aria-expanded={ isOpen }
			>
				<span>
					{ sprintf(
						/* translators: 1: current score out of 100, 2: number of fixable issues. */
						__( 'Your SEO score is %1$d/100 - you can reach 100 by fixing these %2$d issue(s):', 'vulopilot' ),
						score,
						actionable.length
					) }
				</span>
				<i className={ `dashicons dashicons-arrow-${ isOpen ? 'up' : 'down' }-alt2` } />
			</button>

			{ isOpen && (
				<>
					<ul className="vulopilot-seo-score-improvement__list">
						{ actionable.map( ( rule ) => {
							const result = resultFor( rule.rule_id );

							return (
								<li key={ rule.rule_id } className={ `vulopilot-seo-score-improvement__item vulopilot-seo-score-improvement__item--${ rule.status }` }>
									<span className="vulopilot-seo-score-improvement__category">{ CATEGORY_LABELS[ rule.category ] ?? rule.category }</span>
									<span className="vulopilot-seo-score-improvement__text">{ rule.recommendation || rule.reason }</span>
									{ result && (
										<span className={ `vulopilot-seo-score-improvement__result vulopilot-seo-score-improvement__result--${ result.status }` }>
											<i className={ `dashicons dashicons-${ RESULT_ICON[ result.status ] }` } />
											{ result.message }
										</span>
									) }
								</li>
							);
						} ) }
					</ul>

					{ window.vulopilotPostSeo.isPro ? (
						<Button variant="primary" onClick={ runAutoFix } disabled={ isFixing }>
							{ isFixing ? <Spinner /> : __( 'Auto-Fix All Issues', 'vulopilot' ) }
						</Button>
					) : (
						<Button
							variant="primary"
							href={ window.vulopilotPostSeo.shopUrl }
							target="_blank"
							rel="noreferrer"
						>
							{ __( 'Upgrade to Pro to Auto-Fix', 'vulopilot' ) }
						</Button>
					) }
				</>
			) }
		</div>
	);
}
