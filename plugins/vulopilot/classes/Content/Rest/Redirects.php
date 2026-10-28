<?php
namespace VuloPilot\Content\Rest;

use VuloPilot\Content\RedirectRepository;

defined( 'ABSPATH' ) || exit;

/**
 * GET/POST /redirects, POST /redirects/{id}, POST /redirects/{id}/delete.
 *
 * @class       Redirects controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class Redirects extends \WP_REST_Controller {

	/**
	 * REST base for this controller's routes.
	 *
	 * @var string
	 */
	protected $rest_base = 'redirects';

	/**
	 * Real per-check results cache for get_health() - see that method's
	 * own docblock.
	 */
	private const HEALTH_OPTION = 'vulopilot_redirect_health';

	private const HEALTH_CACHE_SECONDS           = HOUR_IN_SECONDS;
	private const MAX_HEALTH_CHECKS              = 50;
	private const HEALTH_REQUEST_TIMEOUT_SECONDS = 5;

	/**
	 * Registers GET/POST /redirects, POST /redirects/{id}, POST /redirects/{id}/delete.
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
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => array( \WP_REST_Server::EDITABLE, \WP_REST_Server::CREATABLE ),
					'callback'            => array( $this, 'update_item' ),
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
			'/' . $this->rest_base . '/health',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_health' ),
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
	 * Lists redirects, paginated/filtered/searched, plus active/inactive counts for the status pill bar.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$repository = new RedirectRepository();

		$page     = absint( $request->get_param( 'page' ) );
		$per_page = absint( $request->get_param( 'per_page' ) );

		$result                     = $repository->find_all(
			array(
				'page'      => $page ? $page : 1,
				'per_page'  => $per_page ? $per_page : 20,
				'is_active' => sanitize_key( (string) $request->get_param( 'is_active' ) ),
				'search'    => sanitize_text_field( (string) $request->get_param( 'search' ) ),
				'orderby'   => sanitize_key( (string) $request->get_param( 'orderby' ) ),
				'order'     => sanitize_key( (string) $request->get_param( 'order' ) ),
			)
		);
		$result['is_active_counts'] = $repository->count_by_column( 'is_active' );

		return rest_ensure_response( $result );
	}

	/**
	 * Creates a new redirect, rejecting a source path that already has one.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$source_path = RedirectRepository::normalize_path( sanitize_text_field( (string) $request->get_param( 'source_path' ) ) );
		$target_url  = esc_url_raw( (string) $request->get_param( 'target_url' ) );

		if ( '/' === $source_path || '' === $target_url ) {
			return new \WP_Error( 'vulopilot_invalid_redirect', __( 'Both a source path and a target URL are required.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		$repository = new RedirectRepository();

		if ( $repository->find_by_source_path( $source_path ) ) {
			return new \WP_Error(
				'vulopilot_redirect_already_exists',
				__( 'A redirect for this path already exists - edit or delete the existing one instead.', 'vulopilot' ),
				array( 'status' => 400 )
			);
		}

		$redirect_type = absint( $request->get_param( 'redirect_type' ) );
		$match_type    = (string) $request->get_param( 'match_type' );

		$id = $repository->insert(
			array(
				'source_path'   => $source_path,
				'target_url'    => $target_url,
				'redirect_type' => in_array( $redirect_type, array( 301, 302, 307 ), true ) ? $redirect_type : 301,
				'match_type'    => in_array( $match_type, array( 'exact', 'contains', 'start', 'end', 'regex' ), true ) ? $match_type : 'exact',
				'hit_count'     => 0,
				'is_active'     => 1,
				'created_by'    => get_current_user_id(),
			)
		);

		return rest_ensure_response( $repository->find( $id ) );
	}

	/**
	 * Partially updates a redirect - only the fields actually present in the request body change.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$id         = absint( $request->get_param( 'id' ) );
		$repository = new RedirectRepository();

		if ( ! $repository->find( $id ) ) {
			return new \WP_Error( 'vulopilot_redirect_not_found', __( 'Redirect not found.', 'vulopilot' ), array( 'status' => 404 ) );
		}

		$update = array();

		if ( null !== $request->get_param( 'target_url' ) ) {
			$update['target_url'] = esc_url_raw( (string) $request->get_param( 'target_url' ) );
		}

		if ( null !== $request->get_param( 'redirect_type' ) ) {
			$redirect_type           = absint( $request->get_param( 'redirect_type' ) );
			$update['redirect_type'] = in_array( $redirect_type, array( 301, 302, 307 ), true ) ? $redirect_type : 301;
		}

		if ( null !== $request->get_param( 'is_active' ) ) {
			$update['is_active'] = $request->get_param( 'is_active' ) ? 1 : 0;
		}

		if ( null !== $request->get_param( 'match_type' ) ) {
			$match_type           = (string) $request->get_param( 'match_type' );
			$update['match_type'] = in_array( $match_type, array( 'exact', 'contains', 'start', 'end', 'regex' ), true ) ? $match_type : 'exact';
		}

		if ( empty( $update ) ) {
			return new \WP_Error( 'vulopilot_no_changes', __( 'Nothing to update.', 'vulopilot' ), array( 'status' => 400 ) );
		}

		if ( ! $repository->update( $id, $update ) ) {
			return new \WP_Error( 'vulopilot_update_failed', __( 'Could not update this redirect.', 'vulopilot' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( $repository->find( $id ) );
	}

	/**
	 * Deletes a redirect.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$id         = absint( $request->get_param( 'id' ) );
		$repository = new RedirectRepository();

		if ( ! $repository->find( $id ) ) {
			return new \WP_Error( 'vulopilot_redirect_not_found', __( 'Redirect not found.', 'vulopilot' ), array( 'status' => 404 ) );
		}

		if ( ! $repository->delete( $id ) ) {
			return new \WP_Error( 'vulopilot_delete_failed', __( 'Could not delete this redirect.', 'vulopilot' ), array( 'status' => 500 ) );
		}

		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Checks whether each redirect's `target_url` resolves. Cached for `HEALTH_CACHE_SECONDS`
	 * (`force=1` bypasses it), bounded to `MAX_HEALTH_CHECKS` redirects per call.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_health( $request ) {
		$cached = get_option( self::HEALTH_OPTION, array() );
		$force  = (bool) $request->get_param( 'force' );

		if ( ! $force && ! empty( $cached['checked_at'] ) && ( time() - (int) $cached['checked_at'] ) < self::HEALTH_CACHE_SECONDS ) {
			return rest_ensure_response( $cached );
		}

		$repository = new RedirectRepository();
		$active     = $repository->find_all(
			array(
				'is_active' => '1',
				'page'      => 1,
				'per_page'  => self::MAX_HEALTH_CHECKS,
			)
		);

		$results = array();

		foreach ( $active['data'] as $redirect ) {
			$results[ (int) $redirect['id'] ] = $this->check_target( (string) $redirect['target_url'] );
		}

		$payload = array(
			'checked_at' => time(),
			'results'    => $results,
		);

		update_option( self::HEALTH_OPTION, $payload, false );

		return rest_ensure_response( $payload );
	}

	/**
	 * Same real HEAD-request check BrokenLinksScanner::check_link() uses for in-content
	 * links, applied here to a redirect row's own `target_url` instead.
	 *
	 * @param string $url Real target URL to check.
	 * @return array{broken: bool, status: int|string}
	 */
	private function check_target( string $url ): array {
		$response = wp_remote_head(
			$url,
			array(
				'timeout'     => self::HEALTH_REQUEST_TIMEOUT_SECONDS,
				'redirection' => 5,
				'sslverify'   => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'broken' => true,
				'status' => 'unverified',
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		return array(
			'broken' => ! ( $status_code >= 200 && $status_code < 400 ),
			'status' => $status_code,
		);
	}
}
