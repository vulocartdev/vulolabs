<?php
/**
 * Admin class file.
 *
 * @package VuloPilot
 */

namespace VuloPilot;

defined( 'ABSPATH' ) || exit;

/**
 * VuloPilot Admin class.
 *
 * @class       Admin class
 * @version     1.0.0
 * @author      VuloLabs
 */
class Admin {

	/**
	 * Dashicon class per collapsible group id (matches each submenu entry's own 'group'
	 * value below).
	 *
	 * @var array<string, string>
	 */
	const GROUPS = array(
		'ai-visibility' => 'dashicons-lightbulb',
		'seo-content'   => 'dashicons-search',
		'site-health'   => 'dashicons-shield',
		'tools'         => 'dashicons-admin-tools',
	);

	/**
	 * Admin constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menus' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_script' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_menu_grouping_assets' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_user_edit_highlight_script' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_to_setup_wizard' ) );
	}

	/**
	 * Consumes `activate()`'s one-shot `vulopilot_activation_redirect` flag: redirects straight to
	 * the Migration setup wizard, but only when there's real migratable data waiting (a Rank
	 * Math/Yoast/AIOSEO install) - no point interrupting a fresh install's first admin page load
	 * with a migration wizard that would find nothing to do.
	 *
	 * @return void
	 */
	public function maybe_redirect_to_setup_wizard() {
		if ( ! get_option( 'vulopilot_activation_redirect' ) ) {
			return;
		}

		delete_option( 'vulopilot_activation_redirect' );

		if ( wp_doing_ajax() || defined( 'REST_REQUEST' ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$sources             = ( new \VuloPilot\Migration\MigrationManager() )->list_sources();
		$has_migratable_data = (bool) array_filter(
			$sources,
			static function ( $source ) {
				return ( $source['count'] ?? 0 ) > 0;
			}
		);

		if ( ! $has_migratable_data ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=vulopilot#&tab=setup-wizard' ) );
		exit;
	}

	/**
	 * Registers the VuloPilot top-level menu and its submenu tabs.
	 *
	 * @return void
	 */
	public function add_menus() {
		if ( ! is_admin() ) {
			return;
		}

		add_menu_page(
			'VuloPilot',
			'VuloPilot',
			'manage_options',
			'vulopilot',
			array( $this, 'create_admin_page' ),
			'dashicons-shield',
			56
		);

		// Flat top-level IA - each entry reuses an existing tab slug/route (src/routes.ts) so no
		// new pages were needed, just new labels.
		$submenus = apply_filters(
			'vulopilot_submenus',
			array(
				'dashboard'       => array(
					'name'     => __( 'Dashboard', 'vulopilot' ),
					'priority' => 10,
					'icon'     => 'dashicons-dashboard',
				),
				'ai-assistant'    => array(
					'name'     => __( 'AI Copilot', 'vulopilot' ),
					'priority' => 20,
					'icon'     => 'dashicons-lightbulb',
				),
				'seo-visibility'  => array(
					'name'     => __( 'SEO & Visibility', 'vulopilot' ),
					'priority' => 30,
					'icon'     => 'dashicons-chart-line',
				),
				'content'         => array(
					'name'     => __( 'Content', 'vulopilot' ),
					'priority' => 40,
					'icon'     => 'dashicons-media-text',
				),
				'performance'     => array(
					'name'     => __( 'Performance', 'vulopilot' ),
					'priority' => 50,
					'icon'     => 'dashicons-performance',
				),
				// Promoted out of "Protect My Site"'s own former 3-tab shell (Security.tsx) along
				// with Backups, which is now merged into this page.
				'site-health'     => array(
					'name'     => __( 'Site Health', 'vulopilot' ),
					'priority' => 55,
					'icon'     => 'dashicons-heart',
				),
				'accessibility'   => array(
					'name'     => __( 'Accessibility', 'vulopilot' ),
					'priority' => 65,
					'icon'     => 'dashicons-universal-access',
				),
				// "Protect My Site"'s own remaining tab, now standalone -
				// see pages/Security/Security.tsx's own docblock.
				'security'        => array(
					'name'     => __( 'Security', 'vulopilot' ),
					'priority' => 67,
					'icon'     => 'dashicons-shield',
				),
				'commerce'        => array(
					'name'     => __( 'Commerce', 'vulopilot' ),
					'priority' => 70,
					'icon'     => 'dashicons-cart',
				),
				'automations'     => array(
					'name'     => __( 'Automations', 'vulopilot' ),
					'priority' => 80,
					'icon'     => 'dashicons-update',
				),
				// 'divider' draws a thin rule before this item in the native submenu (admin-menu-
				// groups.js's addDividers()).
				'reports'         => array(
					'name'     => __( 'Reports', 'vulopilot' ),
					'priority' => 90,
					'icon'     => 'dashicons-chart-bar',
					'divider'  => true,
				),
				'settings'        => array(
					'name'     => __( 'Settings', 'vulopilot' ),
					'priority' => 100,
					'icon'     => 'dashicons-admin-generic',
				),
				// 'modules' used to sit here too (always last, pinned via PHP_INT_MAX so a filter
				// adding a higher-priority item later couldn't push it out of the last slot).
			)
		);

		if ( ! class_exists( 'WooCommerce' ) ) {
			unset( $submenus['commerce'] );
		}

		uasort(
			$submenus,
			function ( $a, $b ) {
				return ( $a['priority'] ?? 0 ) <=> ( $b['priority'] ?? 0 );
			}
		);

		foreach ( $submenus as $slug => $submenu ) {
			add_submenu_page(
				'vulopilot',
				$submenu['name'],
				$submenu['name'],
				'manage_options',
				'vulopilot#&tab=' . $slug,
				'__return_null'
			);
		}

		// The top-level menu click target duplicates the "Dashboard" submenu it was auto-registered
		// as by add_menu_page().
		remove_submenu_page( 'vulopilot', 'vulopilot' );

		// Stash the same $submenus this method just built.
		$this->registered_submenus = $submenus;
	}

	/**
	 * Renders the empty mount point the React app renders into.
	 *
	 * @return void
	 */
	public function create_admin_page() {
		echo '<div id="admin-main-wrapper" class="admin-main-wrapper"></div>';
	}

	/**
	 * Loads the admin app on VuloPilot's own admin screen only.
	 *
	 * @return void
	 */
	public function enqueue_admin_script() {
		$screen = get_current_screen();

		if ( ! $screen || 'toplevel_page_vulopilot' !== $screen->id ) {
			return;
		}

		// Needed so zyra's FileInput component (AI Copilot's "Attach" button, ChatTab.tsx) can open
		// WordPress's real media library picker/uploader (wp.media()) instead of silently falling
		// back to a local-only blob preview that the server could never read back.
		wp_enqueue_media();

		wp_enqueue_script( 'wp-element' );

		FrontendScripts::admin_load_scripts();
		FrontendScripts::enqueue_script( 'vulopilot-vendor-script' );
		FrontendScripts::enqueue_script( 'vulopilot-admin-script' );
		FrontendScripts::enqueue_style( 'vulopilot-admin-style' );
		FrontendScripts::localize_scripts( 'vulopilot-admin-script' );
	}

	/**
	 * Scrolls to and briefly highlights WP core's own "Email" field on its native user-
	 * edit.php screen when linked here with `?highlight=email`.
	 */
	public function enqueue_user_edit_highlight_script() {
		$screen = get_current_screen();

		if ( ! $screen || 'user-edit' !== $screen->id ) {
			return;
		}

		$highlight = sanitize_key( (string) filter_input( INPUT_GET, 'highlight' ) );

		if ( 'email' !== $highlight ) {
			return;
		}

		wp_add_inline_script(
			'jquery-core',
			"document.addEventListener( 'DOMContentLoaded', function () {"
			. "var field = document.getElementById( 'email' );"
			. 'if ( ! field ) { return; }'
			. "field.scrollIntoView( { behavior: 'smooth', block: 'center' } );"
			. "field.style.outline = '2px solid #7c3aed';"
			. "field.style.transition = 'outline 0.2s ease';"
			. 'field.focus();'
			. 'setTimeout( function () { field.style.outline = ""; }, 4000 );'
			. '} );'
		);
	}

	/**
	 * Grafts a grouped/collapsible look onto the native `#toplevel_page_vulopilot` admin
	 * menu.
	 *
	 * @return void
	 */
	public function enqueue_menu_grouping_assets() {
		if ( ! is_admin() || empty( $this->registered_submenus ) ) {
			return;
		}

		$tab_to_group   = array();
		$tab_to_icon    = array();
		$divider_before = array();

		foreach ( $this->registered_submenus as $slug => $submenu ) {
			if ( ! empty( $submenu['group'] ) ) {
				$tab_to_group[ $slug ] = $submenu['group'];
			}

			if ( ! empty( $submenu['icon'] ) ) {
				$tab_to_icon[ $slug ] = $submenu['icon'];
			}

			if ( ! empty( $submenu['divider'] ) ) {
				$divider_before[] = $slug;
			}
		}

		// Built from literal __() calls (never GROUPS's own label strings fed through __()
		// dynamically) so the i18n string-extraction tooling can actually find these.
		$translated_labels = array(
			'ai-visibility' => __( 'AI Visibility', 'vulopilot' ),
			'seo-content'   => __( 'SEO & Content', 'vulopilot' ),
			'site-health'   => __( 'Site Health', 'vulopilot' ),
			'tools'         => __( 'Tools', 'vulopilot' ),
		);

		$groups = array();

		foreach ( self::GROUPS as $group_id => $icon ) {
			$groups[] = array(
				'id'    => $group_id,
				'label' => $translated_labels[ $group_id ] ?? $group_id,
				'icon'  => $icon,
			);
		}

		wp_enqueue_script(
			'vulopilot-admin-menu-groups',
			VuloPilot()->plugin_url . 'assets/js/public/vulopilot-admin-menu-groups.min.js',
			array(),
			VuloPilot()->version,
			true
		);

		wp_enqueue_style(
			'vulopilot-admin-menu-groups',
			VuloPilot()->plugin_url . 'assets/styles/public/vulopilot-admin-menu-groups.min.css',
			array(),
			VuloPilot()->version
		);

		wp_localize_script(
			'vulopilot-admin-menu-groups',
			'vulopilotMenuGroups',
			array(
				'groups'        => $groups,
				'tabToGroup'    => $tab_to_group,
				'tabToIcon'     => $tab_to_icon,
				'dividerBefore' => $divider_before,
			)
		);
	}

	/**
	 * The $submenus list add_menus() built, stashed for enqueue_menu_grouping_assets() to
	 * read.
	 *
	 * @var array
	 */
	private $registered_submenus = array();
}
