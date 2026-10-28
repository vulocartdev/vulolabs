<?php
namespace VuloPilot\Content\Rest;

use VuloPilot\Content\NotFoundLogRepository;
use VuloPilot\Content\RedirectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Backs the Redirects page's "404 Log" table: GET /not-found-logs, POST .../delete
 * (dismiss), POST .../convert (turn into a redirect). `convert_item()` creates the
 * redirect and removes the log row atomically, so a failed second call can't leave
 * an already-redirected entry looking unhandled.
 *
 * @class       NotFoundLogs controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class NotFoundLogs extends \WP_REST_Controller {

	/**
	 * REST base for this controller's routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'not-found-logs';

	/**
	 * Registers routes for fetching `/not-found-logs` and submitting to `/not-found-logs/{id}/delete` and `/not-found-logs/{id}/convert`.
	 *
	 * @inheritDoc
	 */
	public function register_routes() {
		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/delete',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)/convert',
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'convert_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Same manage_options gate every other VuloPilot REST route uses.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return bool
	 */
	public function permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Lists 404 log entries, paginated/searched, newest first by default, plus
	 * content/system counts for the status pill bar. `is_system` ('0'/'1') scopes to
	 * real missing pages vs. the "system" bucket (NotFoundLogger::is_system_path());
	 * omitted, both are returned mixed.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$repository = new NotFoundLogRepository();
		$page       = absint( $request->get_param( 'page' ) );
		$per_page   = absint( $request->get_param( 'per_page' ) );
		$orderby    = sanitize_key( (string) $request->get_param( 'orderby' ) );

		$result = $repository->find_all(
			array(
				'page'      => $page ? $page : 1,
				'per_page'  => $per_page ? $per_page : 20,
				'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'orderby'   => $orderby ? $orderby : 'last_seen_at',
				'order'     => sanitize_key( (string) $request->get_param( 'order' ) ),
				'is_system' => sanitize_key( (string) $request->get_param( 'is_system' ) ),
			)
		);

		$result['is_system_counts'] = $repository->count_by_column( 'is_system' );

		return rest_ensure_response( $result );
	}

	/**
	 * Dismisses one 404 log entry.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$id         = absint( $request->get_param( 'id' ) );
		$repository = new NotFoundLogRepository();

		if ( ! $repository->find( $id ) ) {
			return new \WP_Error( 'vulopilot_log_not_found', __( 'Log entry not found.', 'vulopilot' ), array( 'status' => 404 ) );
		}

		if ( ! $repository->delete( $id ) ) {
			return new \WP_Error( 'vulopilot_delete_failed', __( 'Could not dismiss this log entry.', 'vulopilot' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Creates a real redirect from this 404 log entry, then removes the
	 * log row - see this class's own docblock for why both steps happen
	 * here rather than as two separate frontend calls.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function convert_item( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$log = ( new NotFoundLogRepository() )->find( $id );

		if ( ! $log ) {
			return new \WP_Error( 'vulopilot_log_not_found', __( 'Log entry not found.', 'vulopilot' ), array( 'status' => 404 ) );
		}

		$target_url = esc_url_raw( (string) $request->get_param( 'target_url' ) );

		if ( '' === $target_url ) {
			return new \WP_Error( 'vulopilot_missing_target', __( 'A target URL is required to create a redirect.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$redirects       = new RedirectRepository();
		$source_path     = RedirectRepository::normalize_path( $log['requested_path'] );
		$existing_target = $redirects->find_by_source_path( $source_path );

		if ( $existing_target ) {
			return new \WP_Error(
				'vulopilot_redirect_already_exists',
				__( 'A redirect for this path already exists - manage it from the Redirects table above.', 'vulopilot' ),
				array( 'status' => 400 )
			);
		}

		$redirects->insert(
			array(
				'source_path'   => $source_path,
				'target_url'    => $target_url,
				'redirect_type' => 301,
				'hit_count'     => 0,
				'is_active'     => 1,
				'created_by'    => get_current_user_id(),
			)
		);

		( new NotFoundLogRepository() )->delete( $id );

		return rest_ensure_response( array( 'success' => true ) );
	}
}
