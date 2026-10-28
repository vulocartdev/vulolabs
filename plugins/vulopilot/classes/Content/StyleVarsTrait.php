<?php
namespace VuloPilot\Content;

defined( 'ABSPATH' ) || exit;

/**
 * Shared `--name:value;` CSS custom-property collection helpers for block
 * `build_style_attribute()` methods (FaqRenderer, TableOfContentsRenderer) - an unset/empty
 * value is simply omitted so the stylesheet's own fallback applies.
 */
trait StyleVarsTrait {

	/**
	 * Records one `$name => $value` pair into `$vars` unless the value is unset/empty.
	 *
	 * @param array<string, mixed> $vars  Collected vars, by reference.
	 * @param string                $name  CSS custom-property name.
	 * @param mixed                 $value Its value, or '' / null to skip.
	 * @return void
	 */
	private static function set_style_var( array &$vars, string $name, $value ): void {
		if ( '' !== $value && null !== $value ) {
			$vars[ $name ] = $value;
		}
	}

	/**
	 * Expands a `{top,right,bottom,left}` box value into four `$prefix-$side` vars.
	 *
	 * @param array<string, mixed> $vars   Collected vars, by reference.
	 * @param string                $prefix CSS custom-property prefix.
	 * @param mixed                 $box    The box value, or anything non-array to skip.
	 * @return void
	 */
	private static function set_style_box_vars( array &$vars, string $prefix, $box ): void {
		if ( ! is_array( $box ) ) {
			return;
		}

		foreach ( array( 'top', 'right', 'bottom', 'left' ) as $side ) {
			if ( isset( $box[ $side ] ) ) {
				self::set_style_var( $vars, "{$prefix}-{$side}", $box[ $side ] );
			}
		}
	}
}
