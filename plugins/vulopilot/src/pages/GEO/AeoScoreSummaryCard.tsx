import { __ } from '@wordpress/i18n';
import { COLOR_PALETTE } from '@zyra/core';
import { CardComponent, ChartComponent, TypographyComponent } from '@zyra/components';
import { useContentGate } from '../../services/useContentGate';
import { useFilterSlot } from '../../services/useFilterSlot';
import type { ComponentType } from 'react';
import type { FindingGroup } from '../../components/Issues/issuesTypes';
import type { TrendChange } from './geoTrendChange';

interface AeoTopic {
	key: string;
	title: string;
	titleIcon: string;
	scannerIds: string[];
}

interface AeoScoreSummaryPanelProps {
	isLoading: boolean;
	questionsAnswered: number;
	totalPages: number;
	pagesReady: number;
	trend: TrendChange | null;
	topics: AeoTopic[];
	groups: FindingGroup[];
	// eslint-disable-next-line no-unused-vars
	onSelectTopic?: (topicKey: string) => void;
}

interface AeoScoreSummaryCardProps extends AeoScoreSummaryPanelProps {
	/** Whether the Pro-only `answer-engine-optimization` module is active+licensed. */
	isActive: boolean;
}

/** Inert placeholder ring - real score comes from the `vulopilot_aeo_score_summary_panel` filter. */
const DUMMY_SCORE = 72;

const dummyContent = (
	<div className="aeo-score-summary">
		<div className="aeo-score-summary-gauge">
			<div className="geo-overall-visibility">
				<ChartComponent
					type="ring"
					height={200}
					color={COLOR_PALETTE.green}
					centerLabel={
						<>
							<TypographyComponent variant={'h1'} color="green">
								{DUMMY_SCORE}
							</TypographyComponent>
							<TypographyComponent variant={'h4'}>
								{__('Good', 'vulopilot')}
							</TypographyComponent>
						</>
					}
					data={[
						{ label: __('Score', 'vulopilot'), value: DUMMY_SCORE, color: COLOR_PALETTE.green },
						{ label: __('Remaining', 'vulopilot'), value: 100 - DUMMY_SCORE, color: '#e5e7eb' },
					]}
				/>
				<TypographyComponent variant={'h3'} color="text-green">
					{__('AEO Score', 'vulopilot')}
				</TypographyComponent>
			</div>
		</div>
	</div>
);

/**
 * "AEO Score" - card shell only, same `useContentGate`/`useFilterSlot` pattern
 * AeoCitationCoverageCard.tsx/AeoEngineTestingCard.tsx already use. Plugs in via the
 * `vulopilot_aeo_score_summary_panel` filter, else the inert ring above renders.
 */
const AeoScoreSummaryCard = ({ isActive, ...panelProps }: AeoScoreSummaryCardProps) => {
	const Panel = useFilterSlot<ComponentType<AeoScoreSummaryPanelProps>>(
		'vulopilot_aeo_score_summary_panel'
	);

	const { wrap } = useContentGate('answer-engine-optimization', isActive);

	return (
		<CardComponent
			title={__('AEO Score', 'vulopilot')}
			titleIcon="ai"
			desc={__(
				'How ready your content is to be extracted and quoted directly by AI answer engines.',
				'vulopilot'
			)}
			isLoading={panelProps.isLoading}
		>
			{wrap(Panel ? <Panel {...panelProps} /> : dummyContent, dummyContent)}
		</CardComponent>
	);
};

export default AeoScoreSummaryCard;
