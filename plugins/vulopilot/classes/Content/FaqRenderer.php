<?php
namespace VuloPilot\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Render logic for the `vulopilot/faq` block. Uses native `<details name="…">` exclusive-group
 * accordion markup when `allowMultipleOpen` is false; `layoutMode: 'expanded'` renders plain
 * always-visible `<div>`s instead, matching `FaqAccordion.js`'s editor branch.
 *
 * @class       FaqRenderer class
 * @version     1.0.0
 * @author      VuloLabs
 */
class FaqRenderer {

	use StyleVarsTrait;

	const ICON_GLYPHS = array(
		'plus-minus' => array(
			'closed' => '+',
			'open'   => '&#8722;',
		),
		'chevron'    => array(
			'closed' => '&#8963;',
			'open'   => '&#8964;',
		),
		'arrow'      => array(
			'closed' => '&#8594;',
			'open'   => '&#8595;',
		),
	);

	/**
	 * @param array<string, mixed> $attributes Real block attributes - `questions: array<{question,answer}>`.
	 * @return string Visible HTML (details UI, safe to pass through `wp_kses_post()`), or '' if every row was blank.
	 */
	public static function render( array $attributes ): string {
		$questions = self::sanitize_questions( $attributes['questions'] ?? array() );

		if ( empty( $questions ) ) {
			return '';
		}

		$layout_mode         = 'expanded' === ( $attributes['layoutMode'] ?? 'accordion' ) ? 'expanded' : 'accordion';
		$allow_multiple_open = ! isset( $attributes['allowMultipleOpen'] ) || ! empty( $attributes['allowMultipleOpen'] );
		$initial_open_index  = isset( $attributes['initialOpenIndex'] ) && is_numeric( $attributes['initialOpenIndex'] )
			? (int) $attributes['initialOpenIndex']
			: null;
		$icon_style          = isset( self::ICON_GLYPHS[ $attributes['iconStyle'] ?? '' ] ) ? $attributes['iconStyle'] : 'plus-minus';
		$icon_position       = 'left' === ( $attributes['iconPosition'] ?? 'right' ) ? 'left' : 'right';
		$heading_level       = isset( $attributes['headingLevel'] ) ? (int) $attributes['headingLevel'] : 0;
		$heading_level       = ( $heading_level >= 2 && $heading_level <= 6 ) ? $heading_level : 0;
		// Real exclusive-group name, unique per block instance so independent FAQ blocks on the
		// same page (or two instances of this same block) never fight over which item is open.
		$group_name = wp_unique_id( 'vulopilot-faq-' );

		$wrapper_class      = 'vulopilot-faq vulopilot-faq--icon-' . $icon_position
			. ( 'expanded' === $layout_mode ? ' vulopilot-faq--expanded' : '' )
			. ( empty( $attributes['animationEnabled'] ) ? ' vulopilot-faq--no-animation' : '' );
		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class' => $wrapper_class,
				'style' => self::build_style_attribute( $attributes ),
			)
		);

		$html = '<div ' . $wrapper_attributes . '>';

		foreach ( $questions as $index => $item ) {
			$question_html = wp_kses_post( $item['question'] );

			if ( $heading_level ) {
				$question_html = sprintf(
					'<h%1$d class="vulopilot-faq__question-text">%2$s</h%1$d>',
					$heading_level,
					$question_html
				);
			}

			$icon_html = self::build_icon_html( $icon_style );

			if ( 'expanded' === $layout_mode ) {
				$html .= sprintf(
					'<div class="vulopilot-faq__item vulopilot-faq__item--static"><div class="vulopilot-faq__question vulopilot-faq__question--static">%1$s</div><div class="vulopilot-faq__answer">%2$s</div></div>',
					$question_html,
					wp_kses_post( $item['answer'] )
				);
				continue;
			}

			$is_open   = null !== $initial_open_index && $index === $initial_open_index;
			$name_attr = $allow_multiple_open ? '' : sprintf( ' name="%s"', esc_attr( $group_name ) );

			$html .= sprintf(
				'<details class="vulopilot-faq__item"%1$s%2$s><summary class="vulopilot-faq__question">%3$s%4$s</summary><div class="vulopilot-faq__answer">%5$s</div></details>',
				$name_attr,
				$is_open ? ' open' : '',
				$question_html,
				$icon_html,
				wp_kses_post( $item['answer'] )
			);
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * Both glyphs always render; CSS toggles which one shows via `[open]`, no JS needed.
	 *
	 * @param string $icon_style One of `self::ICON_GLYPHS`'s own keys.
	 * @return string The `.vulopilot-faq__icon` markup.
	 */
	private static function build_icon_html( string $icon_style ): string {
		$glyph = self::ICON_GLYPHS[ $icon_style ] ?? self::ICON_GLYPHS['plus-minus'];

		return sprintf(
			'<span class="vulopilot-faq__icon" aria-hidden="true"><span class="vulopilot-faq__icon-closed">%1$s</span><span class="vulopilot-faq__icon-open">%2$s</span></span>',
			$glyph['closed'],
			$glyph['open']
		);
	}

	/**
	 * PHP mirror of `faqStyleVars.js`'s `buildFaqStyleVars()`. Keeps the legacy
	 * `questionColor`/`questionFontSize`/`answerColor`/`answerFontSize` fallback for FAQ
	 * blocks saved before the `style` attribute existed.
	 *
	 * @param array<string, mixed> $attributes Real block attributes.
	 * @return string A `--name:value;` CSS custom-property declaration list, or '' if nothing is set.
	 */
	private static function build_style_attribute( array $attributes ): string {
		$style      = is_array( $attributes['style'] ?? null ) ? $attributes['style'] : array();
		$layout     = is_array( $style['layout'] ?? null ) ? $style['layout'] : array();
		$container  = is_array( $style['container'] ?? null ) ? $style['container'] : array();
		$item       = is_array( $style['item'] ?? null ) ? $style['item'] : array();
		$question   = is_array( $style['question'] ?? null ) ? $style['question'] : array();
		$answer     = is_array( $style['answer'] ?? null ) ? $style['answer'] : array();
		$icon       = is_array( $style['icon'] ?? null ) ? $style['icon'] : array();
		$states     = is_array( $style['states'] ?? null ) ? $style['states'] : array();
		$responsive = is_array( $style['responsive'] ?? null ) ? $style['responsive'] : array();
		$tablet     = is_array( $responsive['tablet'] ?? null ) ? $responsive['tablet'] : array();
		$mobile     = is_array( $responsive['mobile'] ?? null ) ? $responsive['mobile'] : array();

		$vars = array();

		$set_border = static function ( string $prefix, $border ) use ( &$vars ): void {
			if ( ! is_array( $border ) ) {
				return;
			}

			self::set_style_var( $vars, "{$prefix}-color", $border['color'] ?? '' );
			self::set_style_var( $vars, "{$prefix}-width", $border['width'] ?? '' );
			self::set_style_var( $vars, "{$prefix}-style", $border['style'] ?? '' );
		};

		// Layout.
		self::set_style_var( $vars, '--faq-item-gap', $layout['itemGap'] ?? '' );
		self::set_style_var( $vars, 'width', $layout['width'] ?? '' );
		self::set_style_var( $vars, 'max-width', $layout['maxWidth'] ?? '' );
		if ( ! empty( $layout['maxWidth'] ) ) {
			if ( 'center' === ( $layout['align'] ?? '' ) ) {
				$vars['margin-left']  = 'auto';
				$vars['margin-right'] = 'auto';
			} elseif ( 'right' === ( $layout['align'] ?? '' ) ) {
				$vars['margin-left'] = 'auto';
			}
		}

		// Container.
		self::set_style_var( $vars, '--faq-container-background', $container['background'] ?? '' );
		self::set_style_box_vars( $vars, '--faq-container-padding', $container['padding'] ?? null );
		$set_border( '--faq-container-border', $container['border'] ?? null );
		self::set_style_var( $vars, '--faq-container-radius', $container['radius'] ?? '' );
		self::set_style_var( $vars, '--faq-container-shadow', $container['shadow'] ?? '' );

		// FAQ item.
		self::set_style_var( $vars, '--faq-item-background', $item['background'] ?? '' );
		$set_border( '--faq-item-border', $item['border'] ?? null );
		self::set_style_var( $vars, '--faq-item-radius', $item['radius'] ?? '' );
		self::set_style_var( $vars, '--faq-item-shadow', $item['shadow'] ?? '' );

		// Question - legacy `questionColor`/`questionFontSize` fallback.
		self::set_style_var( $vars, '--faq-question-font-family', $question['fontFamily'] ?? '' );
		self::set_style_var( $vars, '--faq-question-font-size', $question['fontSize'] ?? ( $attributes['questionFontSize'] ?? '' ) );
		self::set_style_var( $vars, '--faq-question-font-weight', $question['fontWeight'] ?? '' );
		self::set_style_var( $vars, '--faq-question-line-height', $question['lineHeight'] ?? null );
		self::set_style_var( $vars, '--faq-question-letter-spacing', $question['letterSpacing'] ?? '' );
		self::set_style_var( $vars, '--faq-question-text-align', $question['textAlign'] ?? '' );
		self::set_style_var( $vars, '--faq-question-color', $question['color'] ?? ( $attributes['questionColor'] ?? '' ) );
		self::set_style_var( $vars, '--faq-question-background', $question['background'] ?? '' );
		self::set_style_box_vars( $vars, '--faq-question-padding', $question['padding'] ?? null );

		// Answer - legacy `answerColor`/`answerFontSize` fallback.
		self::set_style_var( $vars, '--faq-answer-font-family', $answer['fontFamily'] ?? '' );
		self::set_style_var( $vars, '--faq-answer-font-size', $answer['fontSize'] ?? ( $attributes['answerFontSize'] ?? '' ) );
		self::set_style_var( $vars, '--faq-answer-font-weight', $answer['fontWeight'] ?? '' );
		self::set_style_var( $vars, '--faq-answer-line-height', $answer['lineHeight'] ?? null );
		self::set_style_var( $vars, '--faq-answer-color', $answer['color'] ?? ( $attributes['answerColor'] ?? '' ) );
		self::set_style_var( $vars, '--faq-answer-background', $answer['background'] ?? '' );
		self::set_style_box_vars( $vars, '--faq-answer-padding', $answer['padding'] ?? null );
		self::set_style_var( $vars, '--faq-answer-link-color', $answer['linkColor'] ?? '' );

		// Icon.
		self::set_style_var( $vars, '--faq-icon-size', $icon['size'] ?? '' );
		self::set_style_var( $vars, '--faq-icon-color', $icon['color'] ?? '' );
		self::set_style_var( $vars, '--faq-icon-spacing', $icon['spacing'] ?? '' );
		self::set_style_var( $vars, '--faq-icon-background', $icon['background'] ?? '' );
		self::set_style_var( $vars, '--faq-icon-radius', $icon['radius'] ?? '' );

		// Interaction states.
		self::set_style_var( $vars, '--faq-hover-border-color', $states['hoverBorderColor'] ?? '' );
		self::set_style_var( $vars, '--faq-expanded-border-color', $states['expandedBorderColor'] ?? '' );
		self::set_style_var( $vars, '--faq-expanded-background', $states['expandedBackground'] ?? '' );
		self::set_style_var( $vars, '--faq-expanded-shadow', $states['expandedShadow'] ?? '' );
		self::set_style_var( $vars, '--faq-expanded-question-color', $states['expandedQuestionColor'] ?? '' );
		self::set_style_var( $vars, '--faq-expanded-icon-background', $states['expandedIconBackground'] ?? '' );
		self::set_style_var( $vars, '--faq-expanded-icon-color', $states['expandedIconColor'] ?? '' );
		self::set_style_var( $vars, '--faq-focus-color', $states['focusColor'] ?? '' );

		// Responsive overrides.
		self::set_style_var( $vars, '--faq-tablet-question-font-size', $tablet['questionFontSize'] ?? '' );
		self::set_style_var( $vars, '--faq-tablet-answer-font-size', $tablet['answerFontSize'] ?? '' );
		self::set_style_var( $vars, '--faq-tablet-item-gap', $tablet['itemGap'] ?? '' );
		self::set_style_var( $vars, '--faq-tablet-icon-size', $tablet['iconSize'] ?? '' );

		self::set_style_var( $vars, '--faq-mobile-question-font-size', $mobile['questionFontSize'] ?? '' );
		self::set_style_var( $vars, '--faq-mobile-answer-font-size', $mobile['answerFontSize'] ?? '' );
		self::set_style_var( $vars, '--faq-mobile-item-gap', $mobile['itemGap'] ?? '' );
		self::set_style_var( $vars, '--faq-mobile-icon-size', $mobile['iconSize'] ?? '' );

		// Animation.
		if ( isset( $attributes['animationEnabled'] ) && ! $attributes['animationEnabled'] ) {
			$vars['--faq-animation-duration'] = '0s';
		} elseif ( isset( $attributes['animationDuration'] ) && is_numeric( $attributes['animationDuration'] ) ) {
			$vars['--faq-animation-duration'] = $attributes['animationDuration'] . 'ms';
		}

		$declarations = array();

		foreach ( $vars as $name => $value ) {
			$declarations[] = sprintf( '%s:%s', $name, $value );
		}

		return implode( ';', $declarations );
	}

	/**
	 * Prints the FAQPage JSON-LD for the same rows render() shows - nothing when
	 * `enableSchema` is off, or every row was blank.
	 *
	 * @param array<string, mixed> $attributes Real block attributes - `questions: array<{question,answer}>`.
	 * @return void
	 */
	public static function print_schema( array $attributes ): void {
		if ( isset( $attributes['enableSchema'] ) && ! $attributes['enableSchema'] ) {
			return;
		}

		$questions = self::sanitize_questions( $attributes['questions'] ?? array() );

		if ( ! empty( $questions ) ) {
			self::print_schema_tag( $questions );
		}
	}

	/**
	 * Never lets a blank question/answer row reach EITHER the visible markup or the JSON-
	 * LD.
	 *
	 * @param mixed $raw The block's own `questions` attribute value.
	 * @return array<int, array{question: string, answer: string}>
	 */
	private static function sanitize_questions( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$clean = array();

		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$question = isset( $row['question'] ) ? trim( wp_kses_post( (string) $row['question'] ) ) : '';
			$answer   = isset( $row['answer'] ) ? trim( wp_kses_post( (string) $row['answer'] ) ) : '';

			if ( '' === wp_strip_all_tags( $question ) || '' === wp_strip_all_tags( $answer ) ) {
				continue;
			}

			$clean[] = array(
				'question' => $question,
				'answer'   => $answer,
			);
		}

		return $clean;
	}

	/**
	 * @param array<int, array{question: string, answer: string}> $questions Already sanitized, never empty.
	 * @return void
	 */
	private static function print_schema_tag( array $questions ): void {
		$entities = array();

		foreach ( $questions as $item ) {
			$entities[] = array(
				'@type'          => 'Question',
				'name'           => wp_strip_all_tags( $item['question'] ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => wp_strip_all_tags( $item['answer'] ),
				),
			);
		}

		$schema = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $entities,
		);

		wp_print_inline_script_tag( (string) wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), array( 'type' => 'application/ld+json' ) );
	}
}
