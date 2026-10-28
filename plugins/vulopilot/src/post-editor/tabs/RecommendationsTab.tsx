import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Button, Spinner } from '@wordpress/components';
import { usePostData } from '../usePostData';
import { getPostSeoScore, getGeoScore, SeoScoreResponse, GeoScoreResponse } from '../api';

interface RecommendationsTabProps {
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
	/** Closes this tab for this post only - a postmeta flag, not a global setting (`PostSeoPanel.tsx`). */
	onDismiss?: () => void;
}

/**
 * Union of every real, already-computed recommendation this post has: SeoScoreEngine's own
 * fail/warning rules (same source ScoreImprovementCard's auto-fix list uses) and GeoAnalyzer's
 * own `suggestions[]`, if a GEO analysis has been run. No new recommendation logic - this tab
 * is pure aggregation of two existing arrays, so it never recommends anything the SEO Score or
 * GEO tabs wouldn't already show individually.
 */
export default function RecommendationsTab( { onNavigate, onDismiss }: RecommendationsTabProps ) {
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
			<div className="vulopilot-seo-tab vulopilot-seo-tab--recommendations">
				<Spinner />
			</div>
		);
	}

	const actionableRules = ( seoScore?.rules ?? [] ).filter( ( rule ) => rule.status === 'fail' || rule.status === 'warning' );
	const geoSuggestions = geoScore?.suggestions ?? [];

	if ( actionableRules.length === 0 && geoSuggestions.length === 0 ) {
		return (
			<div className="vulopilot-seo-tab vulopilot-seo-tab--recommendations">
				<p className="small desc">{ __( 'No outstanding recommendations - this post is in good shape.', 'vulopilot' ) }</p>
			</div>
		);
	}

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--recommendations">
			{ actionableRules.length > 0 && (
				<div className="vulopilot-seo-checklist">
					<div className="vulopilot-seo-checklist__header">
						<span className="title vulopilot-seo-checklist__title">{ __( 'SEO Score recommendations', 'vulopilot' ) }</span>
						{ onDismiss && (
							<Button
								className="vulopilot-seo-checklist__dismiss"
								icon="no-alt"
								label={ __( 'Close this tab for this post', 'vulopilot' ) }
								onClick={ onDismiss }
							/>
						) }
					</div>
					<ul className="vulopilot-seo-checklist__list">
						{ actionableRules.map( ( rule ) => (
							<li
								key={ rule.rule_id }
								className="vulopilot-seo-checklist__item vulopilot-seo-checklist__item--clickable"
								onClick={ () => onNavigate?.( 'content-analysis' ) }
							>
								<i className={ `dashicons dashicons-${ rule.status === 'fail' ? 'no' : 'warning' } vulopilot-seo-checklist__icon` } />
								<span className="vulopilot-seo-checklist__message">
									<strong>{ rule.category.replace( /_/g, ' ' ) }</strong>
									{ `: ${ rule.recommendation ?? rule.reason }` }
								</span>
							</li>
						) ) }
					</ul>
				</div>
			) }

			{ geoSuggestions.length > 0 && (
				<div className="vulopilot-seo-checklist">
					<div className="vulopilot-seo-checklist__header">
						<span className="title vulopilot-seo-checklist__title">{ __( 'GEO recommendations', 'vulopilot' ) }</span>
					</div>
					<ul className="vulopilot-seo-checklist__list">
						{ geoSuggestions.map( ( suggestion, index ) => (
							<li
								key={ index }
								className="vulopilot-seo-checklist__item vulopilot-seo-checklist__item--clickable"
								onClick={ () => onNavigate?.( 'geo' ) }
							>
								<i className="dashicons dashicons-lightbulb vulopilot-seo-checklist__icon" />
								<span className="vulopilot-seo-checklist__message">{ suggestion }</span>
							</li>
						) ) }
					</ul>
				</div>
			) }
		</div>
	);
}
