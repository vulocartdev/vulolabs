import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { useDispatch } from '@wordpress/data';
import { TabPanel } from '@wordpress/components';
import GeneralTab from './tabs/GeneralTab';
import OnPageTab from './tabs/OnPageTab';
import AiVisibilitySuiteTab from './tabs/AiVisibilitySuiteTab';
import RecommendationsTab from './tabs/RecommendationsTab';
import TopicLinkingTab from './tabs/TopicLinkingTab';
import PostScoreBadges from './PostScoreBadges';
import ScoreImprovementCard from './ScoreImprovementCard';
import KeywordManagementSection from './KeywordManagementSection';
import ContentIntelligencePanel from './ContentIntelligencePanel';
import { usePostData } from './usePostData';
import { usePostScores } from './usePostScores';

import type { SeoIssueEditorTab } from '../services/seoIssueEditorTarget';

/**
 * Reduced from 12 icon-only tabs to 5 - Social/Schema/Page Analysis now live inside OnPageTab's
 * accordion, and the 6-tab "AI Visibility Score" suite lives inside AiVisibilitySuiteTab's.
 * Every deep-link target below still resolves to one of these 5.
 */
const TABS = [
	{ name: 'general', title: __( 'General', 'vulopilot' ), icon: 'admin-generic', Component: GeneralTab },
	{ name: 'on-page', title: __( 'On-Page & Social', 'vulopilot' ), icon: 'share', Component: OnPageTab },
	// "AI Visibility Score" suite - composes real data existing engines already compute, no new scoring rules.
	{ name: 'ai-visibility-suite', title: __( 'AI Visibility', 'vulopilot' ), icon: 'visibility', Component: AiVisibilitySuiteTab },
	{ name: 'recommendations', title: __( 'Recommendations', 'vulopilot' ), icon: 'lightbulb', Component: RecommendationsTab },
	// Topic Intelligence & Internal Linking - composes real deterministic and AI-backed data, see TopicLinkingTab.tsx.
	{ name: 'topic-linking', title: __( 'Topic & Linking', 'vulopilot' ), icon: 'networking', Component: TopicLinkingTab },
];

/** Every original deep-link target (`SEO_ISSUE_EDITOR_TARGETS`) still resolves to one of the 5 tabs above. */
const DEEP_LINK_TAB_GROUP: Record< SeoIssueEditorTab, string > = {
	general: 'general',
	social: 'on-page',
	schema: 'on-page',
	'page-analysis': 'on-page',
	overview: 'ai-visibility-suite',
	'ai-readiness': 'ai-visibility-suite',
	aeo: 'ai-visibility-suite',
	geo: 'ai-visibility-suite',
	'ai-visibility': 'ai-visibility-suite',
	'content-analysis': 'ai-visibility-suite',
	recommendations: 'recommendations',
	'topic-linking': 'topic-linking',
};

/** Postmeta key for "close" on the Recommendations tab. */
const RECOMMENDATIONS_DISMISSED_META_KEY = '_vulopilot_recommendations_dismissed';

interface PostSeoPanelProps {
	/** "All SEO Issues" table's "Fix with AI" deep link (post-editor/index.tsx) - which tab to land on. */
	initialTabName?: SeoIssueEditorTab;
	/** Same deep link's specific field/checklist-item id to scroll to and highlight, within whichever tab it names. */
	highlightTarget?: string;
	/** Bumped on every pill click so this re-navigates even to the same tab, without remounting (which used to flicker ContentIntelligencePanel's fetched analysis). */
	navigationNonce?: number;
	/** The specific field/section `navigationNonce` should land on within `initialTabName`, e.g. 'snippet' for the SEO pill. */
	navigationTarget?: string;
}

/**
 * The metabox's tab shell. "Page Analysis" is VuloPilot's own addition.
 */
export default function PostSeoPanel( { initialTabName, highlightTarget, navigationNonce, navigationTarget }: PostSeoPanelProps ) {
	const [ navTarget, setNavTarget ] = useState< { tab: SeoIssueEditorTab; target?: string } | null >( null );
	const { postId, meta, setMeta } = usePostData();
	const { seoScore, aeoScore, seoScoreRules, refetch } = usePostScores( postId ?? 0 );
	const { savePost } = useDispatch( 'core/editor' ) as any;

	// Per-post postmeta flag, saved immediately rather than waiting for the editor's next manual Update.
	const dismissRecommendationsTab = () => {
		setMeta( { [ RECOMMENDATIONS_DISMISSED_META_KEY ]: true } );
		savePost();
	};
	const visibleTabs = meta[ RECOMMENDATIONS_DISMISSED_META_KEY ]
		? TABS.filter( ( tab ) => 'recommendations' !== tab.name )
		: TABS;

	useEffect( () => {
		if ( undefined !== navigationNonce && initialTabName ) {
			// Nonce suffix keeps the target distinct even if the same pill is clicked twice in a row.
			setNavTarget( {
				tab: initialTabName,
				target: navigationTarget ? `${ navigationTarget }-${ navigationNonce }` : undefined,
			} );
		}
		// Re-navigate only when the nonce changes - initialTabName is read fresh via closure, not a dependency.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ navigationNonce ] );

	const deepLinkTab = navTarget?.tab ?? initialTabName;
	// deepLinkTab is one of the 12 fine-grained ids; TabPanel only knows the 5 merged names, so it's translated here.
	const activeTabName = deepLinkTab ? DEEP_LINK_TAB_GROUP[ deepLinkTab ] : undefined;
	const activeHighlight = navTarget ? navTarget.target : highlightTarget;

	const navigateTo = ( tab: SeoIssueEditorTab, target?: string ) => {
		setNavTarget( { tab, target } );
	};

	return (
		<div className="vulopilot-seo-panel">
			{ postId && (
				<>
					<ContentIntelligencePanel />
					<PostScoreBadges
						seoScore={ seoScore }
						aeoScore={ aeoScore }
						onNavigate={ ( tab ) => navigateTo( tab ) }
					/>
					<ScoreImprovementCard
						score={ seoScore }
						rules={ seoScoreRules }
						postId={ postId }
						onScoreUpdated={ refetch }
					/>
					<KeywordManagementSection />
				</>
			) }
			<TabPanel
				key={ activeTabName ?? 'general' }
				tabs={ visibleTabs.map( ( { name, title, icon } ) => ( { name, title, icon } ) ) }
				initialTabName={ activeTabName }
			>
				{ ( tab ) => {
					const active = visibleTabs.find( ( candidate ) => candidate.name === tab.name );
					const ActiveComponent = active ? active.Component : GeneralTab;

					return (
						<ActiveComponent
							highlightTarget={ activeHighlight }
							onNavigate={ navigateTo }
							onDismiss={ 'recommendations' === active?.name ? dismissRecommendationsTab : undefined }
						/>
					);
				} }
			</TabPanel>
		</div>
	);
}
