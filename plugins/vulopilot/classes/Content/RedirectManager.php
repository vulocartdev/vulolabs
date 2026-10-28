<?php
namespace VuloPilot\Content;

use VuloPilot\Content\RedirectRepository;
use VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Readme.txt's "Redirects & 404s" - a real 301/302 redirect manager over the
 * `vulopilot_redirects` table.
 *
 * @class       RedirectManager class
 * @version     1.0.0
 * @author      VuloLabs
 */
class RedirectManager {

	/**
	 * RedirectManager constructor.
	 */
	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_apply_redirect' ), 1 );
		add_action( 'post_updated', array( $this, 'maybe_auto_create_redirect' ), 10, 3 );
	}

	/**
	 * Redirects the current request if its path matches an active row.
	 *
	 * @return void
	 */
	public function maybe_apply_redirect(): void {
		if ( empty( Utill::get_setting( 'enable_redirect_manager' ) ) ) {
			return;
		}

		global $wp;

		$path        = wp_parse_url( home_url( $wp->request ), PHP_URL_PATH ) ?? '/';
		$source_path = RedirectRepository::normalize_path( $path );

		$repository = new RedirectRepository();
		$redirect   = $repository->find_matching( $source_path );

		if ( ! $redirect || empty( $redirect['is_active'] ) ) {
			return;
		}

		$repository->increment_hit_count( (int) $redirect['id'] );

		// wp_safe_redirect() deliberately can't be used here.
		$target_host = wp_parse_url( (string) $redirect['target_url'], PHP_URL_HOST );

		if ( $target_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $target_host ) {
					$hosts[] = $target_host;

					return $hosts;
				}
			);
		}

		wp_safe_redirect( $redirect['target_url'], (int) $redirect['redirect_type'] );
		exit;
	}

	/**
	 * Creates or updates a redirect from a post's old permalink when its slug changes.
	 *
	 * @param int      $post_id    Post id (unused - $post_before/$post_after already carry everything needed).
	 * @param \WP_Post $post_after Post object after the save.
	 * @param \WP_Post $post_before Post object as it was before the save.
	 * @return void
	 */
	public function maybe_auto_create_redirect( $post_id, $post_after, $post_before ): void {
		if ( empty( Utill::get_setting( 'enable_redirect_manager' ) ) || empty( Utill::get_setting( 'auto_redirect_on_slug_change' ) ) ) {
			return;
		}

		// Only a slug change on a post that was ALREADY publicly published has a real, previously-
		// indexable URL worth protecting with a redirect.
		if ( 'publish' !== $post_before->post_status || $post_before->post_name === $post_after->post_name ) {
			return;
		}

		// Trashing a post renames its slug internally (appends '__trashed') to free it up for
		// reuse, which fires this same 'post_updated' hook with a "slug change" that isn't one -
		// without this guard, get_permalink($post_after) on an already-trashed post returns a
		// '?page_id=' fallback URL, which would overwrite TrashedPostRedirector's own
		// home-page redirect for the same path with that useless self-reference.
		if ( 'trash' === $post_after->post_status ) {
			return;
		}

		if ( ! is_post_type_viewable( $post_after->post_type ) ) {
			return;
		}

		$old_path = RedirectRepository::normalize_path( (string) wp_parse_url( get_permalink( $post_before ), PHP_URL_PATH ) );
		$new_path = RedirectRepository::normalize_path( (string) wp_parse_url( get_permalink( $post_after ), PHP_URL_PATH ) );

		if ( '' === $old_path || $old_path === $new_path ) {
			return;
		}

		$repository = new RedirectRepository();
		$existing   = $repository->find_by_source_path( $old_path );
		$target_url = get_permalink( $post_after );

		if ( $existing ) {
			$repository->update(
				(int) $existing['id'],
				array(
					'target_url'    => $target_url,
					'redirect_type' => 301,
					'is_active'     => 1,
				)
			);

			return;
		}

		$repository->insert(
			array(
				'source_path'   => $old_path,
				'target_url'    => $target_url,
				'redirect_type' => 301,
				'hit_count'     => 0,
				'is_active'     => 1,
				'created_by'    => get_current_user_id(),
			)
		);
	}
}
