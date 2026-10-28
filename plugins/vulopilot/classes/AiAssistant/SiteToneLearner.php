<?php
namespace VuloPilot\AiAssistant;

use VuloPilot\Dashboard\ActivityLogRepository;
use VuloPilot\Utill;
use VuloPilot\Utill\Severity;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps `vulopilot_site_tone` (the placeholder field added earlier this session - sent as
 * a `site_tone` hint on every direct AI request, see AiAssistant\AiRequestSender) learned
 * automatically from the site's own recent content instead of starting permanently empty.
 *
 * @class       SiteToneLearner class
 * @version     1.0.0
 * @author      VuloLabs
 */
class SiteToneLearner {

	private const RELEARN_HOOK = 'vulopilot_learn_site_tone';

	/** How many of the site's most recently modified published posts/pages to sample. */
	private const SAMPLE_SIZE = 10;

	private AiRequestSender $request_sender;
	private ActivityLogRepository $activity_logs;

	/**
	 * SiteToneLearner constructor.
	 *
	 * @param AiRequestSender            $request_sender Sends a prompt through the safety-validate → send → sanitize sequence.
	 * @param ActivityLogRepository|null $activity_logs  Defaults to a new instance (injectable for tests).
	 */
	public function __construct( AiRequestSender $request_sender, ?ActivityLogRepository $activity_logs = null ) {
		$this->request_sender = $request_sender;
		$this->activity_logs  = $activity_logs ?? new ActivityLogRepository();

		add_action( 'save_post', array( $this, 'maybe_schedule_relearn' ), 10, 2 );
		add_action( self::RELEARN_HOOK, array( $this, 'relearn' ) );
	}

	/**
	 * The `save_post` callback - guards, then defers. Never calls AI itself.
	 *
	 * @param int      $post_id Post being saved.
	 * @param \WP_Post $post    Same post object.
	 * @return void
	 */
	public function maybe_schedule_relearn( int $post_id, $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status || ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return;
		}

		if ( wp_next_scheduled( self::RELEARN_HOOK ) ) {
			// Already a real re-learn pending - several quick edits in a row (WordPress can fire
			// save_post more than once for one real edit, too) should still only trigger one
			// analysis.
			return;
		}

		// A short delay, not time() - lets a flurry of edits in the same real save settle before
		// the one job that actually runs samples the site's content.
		wp_schedule_single_event( time() + 30, self::RELEARN_HOOK );
	}

	/**
	 * Registered on `self::RELEARN_HOOK`, run via WP-Cron only - the real
	 * AI call happens here, never inline with the triggering save.
	 *
	 * @return void
	 */
	public function relearn(): void {
		$samples = $this->sample_recent_content();

		if ( count( $samples ) < 2 ) {
			// Nothing meaningful to learn from yet - quietly wait for more
			// content, not an error.
			return;
		}

		try {
			$response = $this->request_sender->send_feature( 'site_tone_learning', 'learn', array( 'samples' => $samples ), 'site_tone_learning', null, false );
		} catch ( \Throwable $exception ) {
			// No key configured, provider failure, rate limited, safety validation.
			$this->activity_logs->log(
				'site_tone.learn_failed',
				sprintf( 'Could not learn this site’s tone: %s', $exception->getMessage() ),
				Severity::INFO
			);
			return;
		}

		$this->maybe_store_learned_tone( trim( $response->get_content() ) );
	}

	/**
	 * Same query shape as ContentIntelligence\ContentGapAnalyzer::sample_own_titles(),
	 * extended to also carry each post's full body text.
	 *
	 * @return array<int, array{title: string, content: string}>
	 */
	private function sample_recent_content(): array {
		$posts = get_posts(
			array(
				'post_type'      => array( 'post', 'page' ),
				'post_status'    => 'publish',
				'posts_per_page' => self::SAMPLE_SIZE,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		return array_map(
			static fn( \WP_Post $post ) => array(
				'title'   => $post->post_title,
				'content' => wp_strip_all_tags( $post->post_content ),
			),
			$posts
		);
	}

	/**
	 * Never overwrites a site owner's own manually-saved tone.
	 *
	 * @param string $tone The AI's own real, freshly-learned phrase.
	 * @return void
	 */
	private function maybe_store_learned_tone( string $tone ): void {
		if ( '' === $tone ) {
			return;
		}

		$settings = wp_parse_args( (array) get_option( Utill::VULOPILOT_SETTINGS_KEY, array() ), Utill::VULOPILOT_SETTINGS_DEFAULTS );

		if ( 'manual' === $settings['site_tone_source'] ) {
			return;
		}

		$settings['site_tone']        = $tone;
		$settings['site_tone_source'] = 'auto';
		update_option( Utill::VULOPILOT_SETTINGS_KEY, $settings );
	}
}
