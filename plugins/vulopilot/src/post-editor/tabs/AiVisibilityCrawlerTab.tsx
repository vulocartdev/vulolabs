import { __, sprintf } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import { usePostData } from '../usePostData';
import { getCrawlerStatsForPost, CrawlerPostStatsResponse } from '../api';

interface AiVisibilityCrawlerTabProps {
	// eslint-disable-next-line no-unused-vars
	onNavigate?: ( tab: string, target?: string ) => void;
}

/** Logged AI-bot visits to this permalink - separate from GeoTab's content-derived score. Free (no Pro gate here). */
export default function AiVisibilityCrawlerTab( _props: AiVisibilityCrawlerTabProps ) {
	const { postId } = usePostData();
	const [ stats, setStats ] = useState< CrawlerPostStatsResponse | null >( null );
	const [ isLoading, setIsLoading ] = useState( true );

	useEffect( () => {
		if ( ! postId ) {
			setIsLoading( false );
			return;
		}

		getCrawlerStatsForPost( postId )
			.then( setStats )
			.catch( () => undefined )
			.finally( () => setIsLoading( false ) );
	}, [ postId ] );

	if ( isLoading ) {
		return (
			<div className="vulopilot-seo-tab vulopilot-seo-tab--ai-visibility-crawler">
				<Spinner />
			</div>
		);
	}

	if ( ! stats || stats.total === 0 ) {
		return (
			<div className="vulopilot-seo-tab vulopilot-seo-tab--ai-visibility-crawler">
				<p className="small desc">
					{ __( 'No AI crawler (GPTBot, ClaudeBot, PerplexityBot, and similar) has visited this page yet, in the last 90 days.', 'vulopilot' ) }
				</p>
			</div>
		);
	}

	return (
		<div className="vulopilot-seo-tab vulopilot-seo-tab--ai-visibility-crawler">
			<p className="small desc">
				{ sprintf(
					/* translators: %d: real total AI-bot visit count to this page over the last 90 days. */
					__( '%d AI bot visit(s) to this page in the last 90 days.', 'vulopilot' ),
					stats.total
				) }
			</p>

			{ null !== stats.is_404_rate_percent && stats.is_404_rate_percent > 0 && (
				<p className="small desc vulopilot-seo-checklist__error">
					{ sprintf(
						/* translators: %d: real percentage of those visits that hit a 404. */
						__( '%d%% of those visits resulted in a 404 - the URL AI crawlers are requesting may not match this page\'s current permalink.', 'vulopilot' ),
						stats.is_404_rate_percent
					) }
				</p>
			) }

			<div className="vulopilot-seo-checklist">
				<div className="vulopilot-seo-checklist__header">
					<span className="title vulopilot-seo-checklist__title">{ __( 'By bot', 'vulopilot' ) }</span>
				</div>
				<ul className="vulopilot-seo-checklist__list">
					{ stats.by_bot.map( ( bot ) => (
						<li key={ bot.bot_name } className="vulopilot-seo-checklist__item">
							<span className="vulopilot-seo-checklist__message">
								<strong>{ bot.bot_name }</strong>
								{ ` - ${ bot.total } ${ sprintf( __( 'visit(s), last seen %s', 'vulopilot' ), bot.last_seen_at ) }` }
							</span>
						</li>
					) ) }
				</ul>
			</div>
		</div>
	);
}
