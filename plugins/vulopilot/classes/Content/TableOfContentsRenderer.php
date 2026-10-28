<?php
namespace VuloPilot\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Render logic for the `vulopilot/table-of-contents` block. Mirrors `index.js`'s own
 * filter/tree/numbering logic by hand; the real anchor is only ever computed here
 * (`HeadingAnchorResolver::collect()`), so `heading_key()`'s `level:text` pair is what
 * both sides key an exclusion on.
 *
 * @class       TableOfContentsRenderer class
 * @version     1.0.0
 * @author      VuloLabs
 */
class TableOfContentsRenderer {

	use StyleVarsTrait;

	/**
	 * @param array<string, mixed> $attributes Real block attributes.
	 * @param int                  $post_id    The post this block instance is rendering on.
	 * @return string Real HTML, or '' if the post has no matching headings (no empty shell rendered).
	 */
	public static function render( array $attributes, int $post_id ): string {
		if ( ! $post_id ) {
			return '';
		}

		$title          = ! empty( $attributes['title'] ) ? (string) $attributes['title'] : __( 'Table of Contents', 'vulopilot' );
		$show_title     = ! isset( $attributes['showTitle'] ) || ! empty( $attributes['showTitle'] );
		$nested         = ! isset( $attributes['nestedList'] ) || ! empty( $attributes['nestedList'] );
		$collapsible    = ! empty( $attributes['collapsible'] );
		$initially_open = 'collapsed' !== ( $attributes['collapseInitialState'] ?? 'expanded' );
		$marker_style   = ! empty( $attributes['contentListStyle'] ) ? (string) $attributes['contentListStyle'] : 'disc';
		$hierarchical   = 'hierarchical' === $marker_style;
		$smooth_scroll  = ! isset( $attributes['smoothScroll'] ) || ! empty( $attributes['smoothScroll'] );
		$scroll_offset  = isset( $attributes['scrollOffset'] ) ? (int) $attributes['scrollOffset'] : 0;

		$headings = self::filter_headings( HeadingAnchorResolver::collect( $post_id ), $attributes );

		if ( empty( $headings ) ) {
			return '';
		}

		$list_id   = wp_unique_id( 'vulopilot-toc-list-' );
		$list_html = $nested
			? self::build_nested_list( self::build_tree( $headings ), $hierarchical, '', $list_id )
			: self::build_flat_list( $headings, $hierarchical, $list_id );

		$style = self::build_style_attribute(
			array_merge(
				$attributes,
				// 'hierarchical' isn't a real CSS `list-style-type` - numbers render as literal
				// text instead (`build_number_prefix()`), so the native marker is hidden either way.
				array( 'contentListStyle' => $hierarchical ? 'none' : $marker_style )
			)
		);

		$wrapper_attributes = get_block_wrapper_attributes(
			array(
				'class'              => $nested ? 'vulopilot-toc vulopilot-toc--nested' : 'vulopilot-toc',
				'style'              => $style,
				'data-smooth-scroll' => $smooth_scroll ? 'true' : 'false',
				'data-scroll-offset' => (string) $scroll_offset,
			)
		);

		$title_html = '';
		if ( $show_title ) {
			$icon_html  = $collapsible ? '<i class="adminfont-keyboard-arrow-down vulopilot-toc-toggle-icon" aria-hidden="true"></i>' : '';
			$title_html = $collapsible
				? sprintf(
					'<summary class="vulopilot-toc-title" aria-controls="%1$s">%2$s%3$s</summary>',
					esc_attr( $list_id ),
					esc_html( $title ),
					$icon_html
				)
				: sprintf( '<p class="vulopilot-toc-title">%s</p>', esc_html( $title ) );
		}

		if ( $collapsible ) {
			return sprintf(
				'<nav %1$s aria-label="%2$s"><details class="vulopilot-toc-details"%3$s>%4$s%5$s</details></nav>',
				$wrapper_attributes,
				esc_attr( $title ),
				$initially_open ? ' open' : '',
				$title_html,
				$list_html
			);
		}

		return sprintf(
			'<nav %1$s aria-label="%2$s">%3$s%4$s</nav>',
			$wrapper_attributes,
			esc_attr( $title ),
			$title_html,
			$list_html
		);
	}

	/**
	 * Same `level:text` identifier `headingTree.js`'s `headingKey()` computes - what both
	 * sides key an `excludedHeadings` entry on.
	 *
	 * @param array{level: int, text: string} $heading A single real heading.
	 * @return string
	 */
	private static function heading_key( array $heading ): string {
		return $heading['level'] . ':' . wp_strip_all_tags( $heading['text'] );
	}

	/**
	 * Real mirror of `headingTree.js`'s own `filterHeadings()`.
	 *
	 * @param array<int, array{level: int, text: string, anchor: string}> $headings   Every real heading, unfiltered.
	 * @param array<string, mixed>                                        $attributes Real block attributes.
	 * @return array<int, array{level: int, text: string, anchor: string}>
	 */
	private static function filter_headings( array $headings, array $attributes ): array {
		$included_levels = array_map( 'intval', $attributes['includedLevels'] ?? array() );
		$min_level       = isset( $attributes['minLevel'] ) ? (int) $attributes['minLevel'] : 2;
		$max_level       = isset( $attributes['maxLevel'] ) ? (int) $attributes['maxLevel'] : 6;
		$excluded        = array_flip( $attributes['excludedHeadings'] ?? array() );

		return array_values(
			array_filter(
				$headings,
				static function ( array $heading ) use ( $included_levels, $min_level, $max_level, $excluded ): bool {
					$level_allowed = ! empty( $included_levels )
						? in_array( $heading['level'], $included_levels, true )
						: ( $heading['level'] >= $min_level && $heading['level'] <= $max_level );

					return $level_allowed && ! isset( $excluded[ self::heading_key( $heading ) ] );
				}
			)
		);
	}

	/**
	 * Real mirror of `headingTree.js`'s own `buildHeadingTree()` - a heading nests under the
	 * nearest *preceding* heading with a shallower level.
	 *
	 * @param array<int, array{level: int, text: string, anchor: string}> $headings Already filtered, document order.
	 * @return array<int, array<string, mixed>> Each node is `$heading + ['children' => [...]]`.
	 */
	private static function build_tree( array $headings ): array {
		$root  = array(
			'level'    => 0,
			'children' => array(),
		);
		$stack = array( &$root );

		foreach ( $headings as $heading ) {
			$node             = $heading;
			$node['children'] = array();

			$stack_size = count( $stack );
			while ( $stack_size > 1 && $stack[ $stack_size - 1 ]['level'] >= $heading['level'] ) {
				array_pop( $stack );
				--$stack_size;
			}

			$parent_index                         = $stack_size - 1;
			$stack[ $parent_index ]['children'][] = $node;
			$new_child_index                      = count( $stack[ $parent_index ]['children'] ) - 1;
			$stack[]                              = &$stack[ $parent_index ]['children'][ $new_child_index ];
		}

		return $root['children'];
	}

	/**
	 * Real mirror of `headingTree.js`'s own `assignHierarchicalNumbers()` + list render.
	 *
	 * @param array<int, array<string, mixed>> $nodes        `build_tree()` output (or one node's own 'children').
	 * @param bool                             $hierarchical Whether to compute "1"/"1.1" numbering.
	 * @param string                           $prefix       Already-resolved parent number, e.g. '1' - '' at the root.
	 * @param string                           $list_id      Real `id` attribute - only the top-level `render()` call
	 *                                                        passes one, so only the outermost `<ul>` gets it.
	 * @return string
	 */
	private static function build_nested_list( array $nodes, bool $hierarchical, string $prefix = '', string $list_id = '' ): string {
		if ( empty( $nodes ) ) {
			return '';
		}

		$items = '';

		foreach ( $nodes as $index => $node ) {
			$position      = $index + 1;
			$number        = $hierarchical ? ( '' !== $prefix ? "{$prefix}.{$position}" : (string) $position ) : '';
			$number_html   = '' !== $number ? sprintf( '<span class="vulopilot-toc-number">%s</span>', esc_html( $number ) ) : '';
			$children_html = ! empty( $node['children'] )
				? self::build_nested_list( $node['children'], $hierarchical, $number )
				: '';

			$items .= sprintf(
				'<li class="vulopilot-toc-item vulopilot-toc-item--level-%1$d"><a href="#%2$s">%3$s%4$s</a>%5$s</li>',
				$node['level'],
				esc_attr( $node['anchor'] ),
				$number_html,
				wp_kses_post( $node['text'] ),
				$children_html
			);
		}

		$class        = '' !== $list_id ? 'vulopilot-toc-list' : 'vulopilot-toc-list vulopilot-toc-sublist';
		$id_attribute = '' !== $list_id ? sprintf( ' id="%s"', esc_attr( $list_id ) ) : '';

		return sprintf( '<ul class="%1$s"%2$s>%3$s</ul>', $class, $id_attribute, $items );
	}

	/**
	 * Flat (non-nested) list render - every heading at the same `<li>` depth.
	 *
	 * @param array<int, array{level: int, text: string, anchor: string}> $headings     Already level/exclusion-filtered.
	 * @param bool                                                        $hierarchical Whether to number items sequentially (no real nesting here, so always a flat 1/2/3 count).
	 * @param string                                                      $list_id      Real `id` attribute for the `<ul>` (`aria-controls` target).
	 * @return string
	 */
	private static function build_flat_list( array $headings, bool $hierarchical, string $list_id = '' ): string {
		$items = '';

		foreach ( $headings as $index => $heading ) {
			$number_html = $hierarchical
				? sprintf( '<span class="vulopilot-toc-number">%d</span>', $index + 1 )
				: '';

			$items .= sprintf(
				'<li class="vulopilot-toc-item vulopilot-toc-item--level-%1$d"><a href="#%2$s">%3$s%4$s</a></li>',
				$heading['level'],
				esc_attr( $heading['anchor'] ),
				$number_html,
				wp_kses_post( $heading['text'] )
			);
		}

		$id_attribute = '' !== $list_id ? sprintf( ' id="%s"', esc_attr( $list_id ) ) : '';

		return sprintf( '<ul class="vulopilot-toc-list"%1$s>%2$s</ul>', $id_attribute, $items );
	}

	/**
	 * Real PHP mirror of `styleVars.js`'s own `buildTocStyleVars()` - same attribute → CSS custom property mapping `public/styles/blocks.scss`'s own `.vulopilot-toc` rules read (`var(--toc-*, <fallback>)`), so a style set in the editor renders identically on the live page.
	 *
	 * @param array<string, mixed> $attributes Real block attributes.
	 * @return string A `--name: value;` CSS custom-property declaration list, or '' if nothing is set.
	 */
	private static function build_style_attribute( array $attributes ): string {
		$vars = array();

		self::set_style_var( $vars, '--toc-content-font-size', $attributes['contentFontSize'] ?? '' );
		self::set_style_var( $vars, '--toc-content-color', $attributes['contentColor'] ?? '' );
		self::set_style_var( $vars, '--toc-content-line-height', $attributes['contentLineHeight'] ?? null );
		self::set_style_var( $vars, '--toc-content-list-style', $attributes['contentListStyle'] ?? '' );
		self::set_style_var( $vars, '--toc-content-gap', $attributes['contentGap'] ?? '' );

		self::set_style_var( $vars, '--toc-title-font-size', $attributes['titleFontSize'] ?? '' );
		self::set_style_var( $vars, '--toc-title-color', $attributes['titleColor'] ?? '' );
		self::set_style_var( $vars, '--toc-title-line-height', $attributes['titleLineHeight'] ?? null );
		self::set_style_box_vars( $vars, '--toc-title-padding', $attributes['titlePadding'] ?? null );
		self::set_style_box_vars( $vars, '--toc-title-margin', $attributes['titleMargin'] ?? null );

		self::set_style_var( $vars, '--toc-section-background', $attributes['sectionBackground'] ?? '' );
		self::set_style_box_vars( $vars, '--toc-section-padding', $attributes['sectionPadding'] ?? null );
		self::set_style_box_vars( $vars, '--toc-section-margin', $attributes['sectionMargin'] ?? null );

		$declarations = array();

		foreach ( $vars as $name => $value ) {
			$declarations[] = sprintf( '%s:%s', $name, $value );
		}

		return implode( ';', $declarations );
	}
}
