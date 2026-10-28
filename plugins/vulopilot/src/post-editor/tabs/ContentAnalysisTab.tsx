import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { usePostData } from '../usePostData';
import { getPostSeoScore, SeoScoreResponse } from '../api';

interface ContentAnalysisTabProps {
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
}

const statusClass: Record< string, string > = {
	pass: 'pass',
	warning: 'warning',
	fail: 'fail',
	not_applicable: 'warning',
	unknown: 'warning',
};

/** Same SEO score engine PageAnalysisTab/ScoreImprovementCard call, filtered to the content_quality category. */
export default function ContentAnalysisTab( _props: ContentAnalysisTabProps ) {
	const { postId } = usePostData();
	const [ score, setScore ] = useState< SeoScoreResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		if ( ! postId ) {
			setIsLoading( false );
			return;
		}

		getPostSeoScore( postId )
			.then( setScore )
			.catch( () => undefined )
			.finally( () => setIsLoading( false ) );
	}, [ postId ] );

	if ( isLoading ) {
		return (
			<div className="vulopilot-seo-tab vulopilot-seo-tab--content-analysis">
				<Spinner />
			</div>
		);
	}

	const rules = ( score?.rules ?? [] ).filter( ( rule ) => rule.category === 'content_quality' );
	const category = score?.categories?.content_quality;

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--content-analysis">
			{ category && (
				<p className="small desc">
					{ `${ __( 'Content quality', 'vulopilot' ) }: ${ category.earned }/${ category.max }` }
				</p>
			) }

			<div className="vulopilot-seo-checklist">
				<div className="vulopilot-seo-checklist__header">
					<span className="title vulopilot-seo-checklist__title">{ __( 'Content depth & quality checks', 'vulopilot' ) }</span>
				</div>
				<ul className="vulopilot-seo-checklist__list">
					{ rules.map( ( rule ) => (
						<li key={ rule.rule_id } className={ `vulopilot-seo-checklist__item vulopilot-seo-checklist__item--${ statusClass[ rule.status ] }` }>
							<i className={ `dashicons dashicons-${ rule.status === 'pass' ? 'yes' : rule.status === 'fail' ? 'no' : 'warning' } vulopilot-seo-checklist__icon` } />
							<span className="vulopilot-seo-checklist__message">
								<strong>{ rule.rule_id.replace( /_/g, ' ' ) }</strong>
								{ ` - ${ rule.reason }` }
								{ rule.recommendation && <em> { rule.recommendation }</em> }
							</span>
						</li>
					) ) }
				</ul>
			</div>
		</div>
	);
}
