import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { usePostData } from '../usePostData';
import { getPostSeoScore, getGeoScore, SeoScoreResponse, GeoScoreResponse } from '../api';

interface OverviewTabProps {
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
}

const ratingColor = ( score: number ): string => {
	if ( score >= 80 ) {
		return 'good';
	}
	if ( score >= 50 ) {
		return 'ok';
	}
	return 'bad';
};

/**
 * Side-by-side summary of the three real, already-computed scores this post has: mechanical SEO
 * score (SeoScoreEngine), AI-judged GEO score (GeoAnalyzer, Pro-only), and whether AEO citation
 * coverage has ever been checked for this post. No new scoring - every number here is read from
 * an endpoint another tab in this panel already calls directly.
 */
export default function OverviewTab( { onNavigate }: OverviewTabProps ) {
	const { postId } = usePostData();
	const [ seoScore, setSeoScore ] = useState< SeoScoreResponse | null >( null );
	const [ geoScore, setGeoScore ] = useState< GeoScoreResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		if ( ! postId ) {
			setIsLoading( false );
			return;
		}

		const loaders: Array< Promise< unknown > > = [
			getPostSeoScore( postId ).then( setSeoScore ).catch( () => undefined ),
		];

		if ( window.vulopilotPostSeo.isPro ) {
			loaders.push( getGeoScore( postId ).then( setGeoScore ).catch( () => undefined ) );
		}

		Promise.all( loaders ).finally( () => setIsLoading( false ) );
	}, [ postId ] );

	if ( isLoading ) {
		return (
			<div className="vulopilot-seo-tab vulopilot-seo-tab--overview">
				<Spinner />
			</div>
		);
	}

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--overview">
			<div className="vulopilot-overview-scores">
				<button
					type="button"
					className={ `vulopilot-overview-score vulopilot-overview-score--${ seoScore ? ratingColor( seoScore.score ) : 'loading' }` }
					onClick={ () => onNavigate?.( 'content-analysis' ) }
				>
					<span className="vulopilot-overview-score__label">{ __( 'SEO Score', 'vulopilot' ) }</span>
					<span className="vulopilot-overview-score__value">{ seoScore ? seoScore.score : '-' }</span>
				</button>

				<button
					type="button"
					className={ `vulopilot-overview-score vulopilot-overview-score--${ geoScore ? ratingColor( geoScore.overall_score ) : 'loading' }` }
					onClick={ () => onNavigate?.( 'geo' ) }
				>
					<span className="vulopilot-overview-score__label">{ __( 'GEO Score', 'vulopilot' ) }</span>
					<span className="vulopilot-overview-score__value">{ geoScore ? geoScore.overall_score : __( 'N/A', 'vulopilot' ) }</span>
				</button>

				<button
					type="button"
					className="vulopilot-overview-score vulopilot-overview-score--neutral"
					onClick={ () => onNavigate?.( 'ai-readiness' ) }
				>
					<span className="vulopilot-overview-score__label">{ __( 'AI Readiness', 'vulopilot' ) }</span>
					<span className="vulopilot-overview-score__value">
						{ geoScore ? Math.round( ( geoScore.ai_scores.llm_readability + geoScore.ai_scores.answer_first_structure ) / 2 ) : __( 'N/A', 'vulopilot' ) }
					</span>
				</button>
			</div>

			{ ! window.vulopilotPostSeo.isPro && (
				<p className="small desc">
					{ __( 'GEO and AI Readiness scores require Pro - they run a real AI-backed analysis of this page.', 'vulopilot' ) }
				</p>
			) }

			{ seoScore && seoScore.insufficient_data && (
				<p className="small desc vulopilot-seo-checklist__error">
					{ __( 'Not enough data yet to compute a reliable SEO score for this post - add more content, a focus keyword, and an SEO title/description.', 'vulopilot' ) }
				</p>
			) }
		</div>
	);
}
