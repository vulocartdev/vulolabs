<?php
namespace VuloPilot\Utill;

defined( 'ABSPATH' ) || exit;

/**
 * Fetches `/scans` to list past scan runs.
 *
 * @class       Scans controller
 * @version     1.0.0
 * @author      VuloLabs
 */
class Scans extends \WP_REST_Controller {

	/**
	 * @var string
	 */
	protected $rest_base = 'scans';

	/**
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
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'create_item_permissions_check' ),
				),
			)
		);

		register_rest_route(
			VuloPilot()->rest_namespace,
			'/' . $this->rest_base . '/status',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_status' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
				),
			)
		);
	}

	/**
	 * @inheritDoc
	 */
	public function get_items_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @inheritDoc
	 */
	public function create_item_permissions_check( $request ) {
		return current_user_can( 'manage_options' );
	}

	/**
	 * @inheritDoc
	 *
	 * Accepts comma-separated `scanner_id` and/or `category` params (merged together)
	 * plus `orderby`/`order`, so callers can scope results without paging client-side.
	 */
	public function get_items( $request ) {
		$repository  = new ScanRepository();
		$scanner_ids = $this->parse_comma_separated_list( $request->get_param( 'scanner_id' ) ) ?? array();
		$categories  = $this->parse_comma_separated_list( $request->get_param( 'category' ) );

		if ( $categories ) {
			// The shared, already-populated registry - a fresh `new ScannerRegistry()` has no
			// scanner sources registered (those are merged in once, onto VuloPilot()'s own
			// instance, via the `vulopilot_scanner_sources` filter during init_classes()), so
			// every category lookup against it would silently resolve to zero scanners.
			$registry = VuloPilot()->scanner_registry;

			foreach ( $categories as $category ) {
				$scanner_ids = array_merge( $scanner_ids, array_keys( $registry->get_scanners_by_category( $category ) ) );
			}
		}

		$scanner_ids = array_values( array_unique( $scanner_ids ) );

		return rest_ensure_response(
			$repository->find_all(
				array(
					'page'       => absint( $request->get_param( 'page' ) ) ? absint( $request->get_param( 'page' ) ) : 1,
					'per_page'   => absint( $request->get_param( 'per_page' ) ) ? absint( $request->get_param( 'per_page' ) ) : 20,
					'status'     => sanitize_key( (string) $request->get_param( 'status' ) ),
					'scanner_id' => $scanner_ids ? $scanner_ids : '',
					'orderby'    => sanitize_key( (string) $request->get_param( 'orderby' ) ),
					'order'      => sanitize_key( (string) $request->get_param( 'order' ) ),
				)
			)
		);
	}

	/**
	 * Comma-separated `category` scopes the run and takes precedence over `scanner_id`. Treated as a forced, user-initiated run unless the caller sends `trigger_type=scheduled`, so rate-limited scanners still run on demand.
	 *
	 * @inheritDoc
	 */
	public function create_item( $request ) {
		$force      = 'scheduled' !== sanitize_key( (string) $request->get_param( 'trigger_type' ) );
		$categories = $this->parse_comma_separated_list( $request->get_param( 'category' ) );
		$registry   = VuloPilot()->scanner_registry;

		if ( $categories ) {
			$scanner_ids = array();

			foreach ( $categories as $category ) {
				$scanner_ids = array_merge( $scanner_ids, array_keys( $registry->get_scanners_by_category( $category ) ) );
			}
		} else {
			$scanner_id = sanitize_key( (string) $request->get_param( 'scanner_id' ) );

			$scanner_ids = ( '' === $scanner_id || 'all' === $scanner_id )
				? array_keys( $registry->get_all_scanners() )
				: array( $scanner_id );
		}

		$queued = VuloPilot()->scan_runner->run_queued( $scanner_ids, $force );

		return rest_ensure_response(
			array(
				'success' => true,
				'queued'  => true,
				'total'   => $queued['total'],
			)
		);
	}

	/**
	 * Real status of the current (if any) queued scan run - the frontend polls this
	 * instead of waiting on `create_item()` to block until every scanner finishes.
	 *
	 * @param \WP_REST_Request $request Full request object.
	 * @return \WP_REST_Response
	 */
	public function get_status( $request ) {
		return rest_ensure_response( VuloPilot()->scan_runner->get_queue_status() );
	}

	/**
	 * Parses a comma-separated request param.
	 *
	 * @param mixed $raw_param Raw comma-separated request param.
	 * @return string[]|null Sanitized values, or null when the param was empty/absent.
	 */
	private function parse_comma_separated_list( $raw_param ): ?array {
		if ( empty( $raw_param ) ) {
			return null;
		}

		$values = array_filter( array_map( 'sanitize_key', explode( ',', (string) $raw_param ) ) );

		return $values ? array_values( $values ) : null;
	}
}
