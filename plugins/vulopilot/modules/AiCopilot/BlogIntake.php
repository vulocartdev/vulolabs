<?php
/**
 * BlogIntake class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot\AiCopilot;

defined( 'ABSPATH' ) || exit;

/**
 * Collects a blog post's fields inside a chat with fixed questions - topic, then word count,
 * then tone - and hands back one complete `generate-blog` request. No AI is involved until
 * every field is known. Progress is kept per user and chat in a short-lived transient.
 *
 * @class       BlogIntake class
 * @version     1.0.0
 * @author      VuloLabs
 */
class BlogIntake {

	/**
	 * An unfinished intake is forgotten after this long.
	 */
	private const TTL = 30 * MINUTE_IN_SECONDS;

	/**
	 * Fields in the order they are asked for.
	 */
	private const FIELDS = array( 'topic', 'word_count', 'tone' );

	/**
	 * Same bounds GenerateBlogAction::validate_input() applies.
	 */
	private const MIN_TOPIC_LENGTH = 5;
	private const MIN_WORDS        = 100;
	private const MAX_WORDS        = 5000;
	private const MAX_TONE_LENGTH  = 60;

	/**
	 * Tone words recognized when the first message already names one.
	 */
	private const TONES = array( 'professional', 'friendly', 'informative', 'casual', 'formal', 'conversational', 'persuasive', 'technical', 'humorous', 'enthusiastic' );

	/**
	 * Which chat this intake belongs to, e.g. 'copilot'.
	 *
	 * @var string
	 */
	private string $chat;

	/**
	 * @param string $chat Chat id, so the two chats never share an intake.
	 */
	public function __construct( string $chat ) {
		$this->chat = $chat;
	}

	/**
	 * Handles a chat message if it starts or continues a blog request.
	 *
	 * @param string $message The user's message.
	 * @return array<string, mixed>|null A decision - `question`/`respond` with a `message`, or
	 *                                   `ready_action` with `generate-blog`'s complete `input` -
	 *                                   or null when the message isn't part of a blog request.
	 */
	public function handle( string $message ): ?array {
		$state = $this->load();

		if ( null === $state ) {
			if ( ! $this->is_blog_request( $message ) ) {
				return null;
			}

			$state = array( 'fields' => $this->prefill( $message ) );
		} elseif ( $this->is_cancel( $message ) ) {
			$this->clear();

			return array(
				'status'  => 'respond',
				'message' => __( 'Okay, I won\'t create that blog post.', 'vulopilot' ),
			);
		} else {
			$state = $this->apply_answer( $state, $message );
		}

		$next = $this->next_field( $state['fields'] );

		if ( null === $next ) {
			$this->clear();

			return array(
				'status'    => 'ready_action',
				'action_id' => 'generate-blog',
				'input'     => array(
					'topic'      => $state['fields']['topic'],
					'word_count' => (int) $state['fields']['word_count'],
					'tone'       => (string) $state['fields']['tone'],
				),
			);
		}

		$hint            = $state['hint'] ?? '';
		$state['asking'] = $next;
		unset( $state['hint'] );
		$this->save( $state );

		return array(
			'status'  => 'question',
			'message' => ( '' !== $hint ? $hint . ' ' : '' ) . $this->question( $next ),
		);
	}

	/**
	 * `/blog ...`, or a request to write/create a blog or article that isn't a how/what/why question.
	 *
	 * @param string $message The user's message.
	 * @return bool
	 */
	private function is_blog_request( string $message ): bool {
		if ( preg_match( '#^\s*/blog\b#i', $message ) ) {
			return true;
		}

		return 1 === preg_match( '/\b(write|create|generate|draft|make)\b/i', $message )
			&& 1 === preg_match( '/\b(blog|article)s?\b/i', $message )
			&& 1 !== preg_match( '/^\s*(how|what|why|when|where|which|who|should|is|are|do|does)\b/i', $message );
	}

	/**
	 * @param string $message The user's message.
	 * @return bool
	 */
	private function is_cancel( string $message ): bool {
		return 1 === preg_match( '/^\s*(cancel|stop|never\s*mind|forget it)\b/i', $message );
	}

	/**
	 * Fields the first message already states; null for each one still to ask.
	 *
	 * @param string $message The user's first message.
	 * @return array{topic: string|null, word_count: int|null, tone: string|null}
	 */
	private function prefill( string $message ): array {
		$fields = array_fill_keys( self::FIELDS, null );

		if ( preg_match( '/(\d{1,3}(?:,\d{3})+|\d+)\s*-?\s*words?\b/i', $message, $match ) ) {
			$words = (int) str_replace( ',', '', $match[1] );

			if ( $words >= self::MIN_WORDS && $words <= self::MAX_WORDS ) {
				$fields['word_count'] = $words;
			}
		}

		if ( preg_match( '/\b(' . implode( '|', self::TONES ) . ')\b/i', $message, $match ) ) {
			$fields['tone'] = ucfirst( strtolower( $match[1] ) );
		}

		$topic = '';

		if ( preg_match( '#^\s*/blog\b\s*(.*)$#is', $message, $match ) ) {
			$topic = $match[1];
		} elseif ( preg_match( '/\b(?:about|on)\s+(.+)$/is', $message, $match ) ) {
			$topic = $match[1];
		}

		$topic = trim( sanitize_text_field( $topic ), " \t.!?" );

		if ( mb_strlen( $topic ) >= self::MIN_TOPIC_LENGTH ) {
			$fields['topic'] = $topic;
		}

		return $fields;
	}

	/**
	 * Records the answer to the field that was asked, or a hint to ask again.
	 *
	 * @param array<string, mixed> $state   Stored intake state.
	 * @param string               $message The user's answer.
	 * @return array<string, mixed>
	 */
	private function apply_answer( array $state, string $message ): array {
		$field  = $state['asking'] ?? $this->next_field( $state['fields'] );
		$answer = trim( sanitize_text_field( $message ) );
		$skip   = 1 === preg_match( '/^(skip|any|none|no preference|default|doesn\'?t matter)\.?$/i', $answer );

		if ( 'topic' === $field ) {
			if ( mb_strlen( $answer ) >= self::MIN_TOPIC_LENGTH ) {
				$state['fields']['topic'] = $answer;
			} else {
				/* translators: %d: minimum number of characters. */
				$state['hint'] = sprintf( __( 'Please give a topic of at least %d characters.', 'vulopilot' ), self::MIN_TOPIC_LENGTH );
			}
		} elseif ( 'word_count' === $field ) {
			$words = preg_match( '/\d{1,3}(?:,\d{3})+|\d+/', $answer, $match ) ? (int) str_replace( ',', '', $match[0] ) : 0;

			if ( $skip ) {
				$state['fields']['word_count'] = 0;
			} elseif ( $words >= self::MIN_WORDS && $words <= self::MAX_WORDS ) {
				$state['fields']['word_count'] = $words;
			} else {
				/* translators: 1: minimum word count, 2: maximum word count. */
				$state['hint'] = sprintf( __( 'Please give a number between %1$d and %2$d, or say "skip".', 'vulopilot' ), self::MIN_WORDS, self::MAX_WORDS );
			}
		} elseif ( 'tone' === $field ) {
			$state['fields']['tone'] = $skip ? '' : mb_substr( $answer, 0, self::MAX_TONE_LENGTH );
		}

		return $state;
	}

	/**
	 * @param array<string, mixed> $fields Collected fields.
	 * @return string|null The first field still unanswered.
	 */
	private function next_field( array $fields ): ?string {
		foreach ( self::FIELDS as $field ) {
			if ( null === ( $fields[ $field ] ?? null ) ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * @param string $field Field to ask for.
	 * @return string
	 */
	private function question( string $field ): string {
		switch ( $field ) {
			case 'topic':
				return __( 'Sure! What should the blog post be about?', 'vulopilot' );
			case 'word_count':
				return __( 'How many words would you like? (You can say "skip".)', 'vulopilot' );
			default:
				return __( 'What tone would you prefer? For example: Professional, Friendly, Informative, or Casual. (You can say "skip".)', 'vulopilot' );
		}
	}

	/**
	 * @return string
	 */
	private function key(): string {
		return 'vulopilot_blog_intake_' . $this->chat . '_' . get_current_user_id();
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private function load(): ?array {
		$state = get_transient( $this->key() );

		return is_array( $state ) && isset( $state['fields'] ) ? $state : null;
	}

	/**
	 * @param array<string, mixed> $state Intake state.
	 * @return void
	 */
	private function save( array $state ): void {
		set_transient( $this->key(), $state, self::TTL );
	}

	/**
	 * @return void
	 */
	private function clear(): void {
		delete_transient( $this->key() );
	}
}
