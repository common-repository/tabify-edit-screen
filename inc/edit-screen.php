<?php

include 'tabs.php';

class Tabify_Edit_Screen_Edit_Screen {
	private $tab_location  = 'default';
	private $all_metaboxes = array();

	private $editscreen_tabs;
	private $settings;

	/**
	 * Set hooks for redirection and showing tabs
	 *
	 * @since 0.9.0
	 */
	public function __construct() {
		add_filter( 'redirect_post_location', array( $this, 'redirect_add_current_tab' ), 10 );
		add_action( 'admin_head', array( $this, 'show_tabs' ), 100 );
	}

	/**
	 * When a post is saved let it return to the current selected tab
	 *
	 * @param string $location The location the user will be sent to
	 *
	 * @return string $location The new location the user will be sent to
	 *
	 * @since 0.2.0
	 */
	public function redirect_add_current_tab( $location ) {
		if ( isset( $_REQUEST['tab'] ) ) {
			$location = esc_url_raw( add_query_arg( 'tab', $_REQUEST['tab'], $location ) );
		}

		return $location;
	}

	/**
	 * Show the tabs on the edit screens
	 * This will load the tab class, tab options and actions
	 * It will also will add the required classes to all the metaboxes
	 *
	 * @since 0.1.0
	 */
	public function show_tabs() {
		$screen = get_current_screen();

		if ( ! $screen || 'post' != $screen->base ) {
			return;
		}

		$this->tab_location = apply_filters( 'tabify_tab_location', $this->tab_location, 'posttype' );

		$post_type = $screen->post_type;
		$options   = get_option( 'tabify-edit-screen', array() );

		if ( ! isset( $options['posttypes'][ $post_type ] ) ) {
			return;
		}

		// Ability to change if the tabs should be showed or not.
		$display_tabs = apply_filters( 'tabify_tab_posttype_show', (bool) $options['posttypes'][ $post_type ]['show'] );

		// Check if this post type is enabled.
		if ( ! $display_tabs ) {
			return;
		}

		add_filter( 'admin_body_class', array( $this, 'add_admin_body_class' ) );
		add_action( 'admin_print_footer_scripts', array( $this, 'generate_javascript' ), 9 );

		$default_metaboxes   = $this->get_default_items( $post_type );
		$this->all_metaboxes = $this->get_meta_boxes( $post_type );

		// Filter the tabs
		$tabs = apply_filters( 'tabify_tab_posttype_tabs', $options['posttypes'][ $post_type ]['tabs'], $post_type );

		// Filter empty tabs
		$tabs = array_filter( $tabs, array( $this, 'filter_empty_tabs' ) );

		// Create Tabify_Edit_Screen_Tabs that is for displaying the UI.
		$this->editscreen_tabs = new Tabify_Edit_Screen_Tabs( $tabs );

		// Load the tabs on the edit screen.
		$this->load_tabs();

		$tab_index = 0;
		foreach ( $tabs as $tab_index => $tab ) {
			$class = 'tabifybox tabifybox-' . $tab_index;

			if ( $this->editscreen_tabs->get_current_tab() != $tab_index ) {
				$class .= ' tabifybox-hide';
			}

			if ( isset( $tab['items'] ) ) {
				foreach ( $tab['items'] as $metabox_id_fallback => $metabox_id ) {
					if ( intval( $metabox_id_fallback ) == 0 && $metabox_id_fallback !== 0 ) {
						$metabox_id = $metabox_id_fallback;
					}

					if ( ! in_array( $metabox_id, $default_metaboxes ) ) {
						if ( $metabox_id == 'titlediv' || $metabox_id == 'postdivrich' ) {
							add_action( 'tabify_custom_javascript', function() use ( $class, $metabox_id ) {
								echo 'jQuery(\'#' . $metabox_id . '\').addClass(\'' . $class . '\');';
							} );
						}
						else {
							add_action( 'postbox_classes_' . $post_type . '_' . $metabox_id, function( $args ) use ( $class ) {
								array_push( $args, $class );
								return $args;
							} );

							if ( isset( $this->all_metaboxes[ $metabox_id ] ) ) {
								unset( $this->all_metaboxes[ $metabox_id ] );
							}
						}
					}
				}
			}
		}

		$this->show_unattached_metaboxes( $tab_index );
	}

	/**
	 * Show unattached metaboxes
	 *
	 * @since 1.0.0
	 */
	private function show_unattached_metaboxes( $tab_index ) {
		$show = apply_filters( 'tabify_unattached_metaboxes_show', true, get_post_type() );

		do_action( 'tabify_unattached_metaboxes', $this->all_metaboxes, $show );

		// Check if unattached metaboxes should be showed
		if ( ! $show || empty( $this->all_metaboxes ) ) {
			return;
		}

		foreach ( $this->all_metaboxes as $metabox_id ) {
			$last_index                 = $tab_index;
			$unattached_metaboxes_index = apply_filters( 'tabify_unattached_metaboxes_index', $last_index, get_post_type() );

			if ( $unattached_metaboxes_index < 0 || $unattached_metaboxes_index > $last_index ) {
				$unattached_metaboxes_index = $last_index;
			}

			$class = 'tabifybox tabifybox-' . $unattached_metaboxes_index;

			if ( $this->editscreen_tabs->get_current_tab() != $unattached_metaboxes_index ) {
				$class .= ' tabifybox-hide';
			}

			add_action( 'postbox_classes_' . get_post_type() . '_' . $metabox_id, function( $args ) use ( $class ) {
				array_push( $args, $class );
				return $args;
			} );
		}
	}


	/**
	 * Get meta boxes from a post type
	 *
	 * @param string $post_type Post type name
	 *
	 * @return array $metaboxes List of metaboxes
	 *
	 * @since 1.0.0
	 */
	private function get_meta_boxes( $post_type ) {
		global $wp_meta_boxes;

		$metaboxes         = array();
		$default_metaboxes = $this->get_default_items( $post_type );

		foreach ( $wp_meta_boxes[ $post_type ] as $priorities ) {
			foreach ( $priorities as $priority => $_metaboxes ) {
				foreach ( $_metaboxes as $metabox ) {
					if ( ! in_array( $metabox['id'], $default_metaboxes ) ) {
						$metaboxes[ $metabox['id'] ] = $metabox['id'];
					}
				}
			}
		}

		return $metaboxes;
	}

	/**
	 * Adds tabity location class
	 *
	 * @param string $body List of classes
	 *
	 * @return string $body List of classes with addition of the tabify locatin class
	 *
	 * @since 0.5.0
	 */
	public function add_admin_body_class( $body ) {
		if ( $this->tab_location ) {
			$body .= ' tabify_tab' . $this->tab_location;
		}

		return $body;
	}

	/**
	 * Check where tabs should be loaded and fire the right action and callback for it
	 *
	 * @since 0.5.0
	 */
	private function load_tabs() {
		if ( 'after_title' == $this->tab_location ) {
			add_action( 'edit_form_after_title', array( $this, 'output_tabs' ), 9 );
		}
		else { //default
			$tabs  = $this->submit_button();
			$tabs .= $this->editscreen_tabs->get_tabs_with_container();

			add_action( 'tabify_custom_javascript', function() use ( $tabs ) {
				echo '$(\'#post\').prepend(\'' . addslashes( $tabs ) . '\');';
			} );
		}
	}

	/**
	 * Outputs the tabs
	 *
	 * @since 0.5.0
	 */
	public function output_tabs() {
		echo $this->submit_button();
		echo $this->editscreen_tabs->get_tabs_with_container();
	}

	/**
	 * Add submit button when the submitbox isn't showed on every tab
	 *
	 * @return string $text Return custom submit button
	 *
	 * @since 0.7.0
	 */
	private function submit_button() {
		$post    = get_post();
		$default = $this->get_default_items( $post->post_type );

		if ( in_array( 'submitdiv', $default ) ) {
			return;
		}

		$post_type_object = get_post_type_object( $post->post_type );
		$can_publish      = current_user_can( $post_type_object->cap->publish_posts );

		if ( ! in_array( $post->post_status, array( 'publish', 'future', 'private' ) ) || 0 == $post->ID ) {
			if ( $can_publish ) {
				if ( ! empty( $post->post_date_gmt ) && time() < strtotime( $post->post_date_gmt . ' +0000' ) ) {
					$text = __( 'Schedule' );
				}
				else {
					$text = __( 'Publish' );
				}
			}
			else {
				$text = __( 'Submit for Review' );
			}
		}
		else {
			$text = __('Update');
		}

		return get_submit_button( $text, 'secondary', 'second-submit', false );
	}

	/**
	 * Generate the javascript for the edit screen
	 *
	 * @since 0.1.0
	 */
	public function generate_javascript() {
		echo '<script type="text/javascript">';
		echo 'jQuery(function($) {';
		do_action( 'tabify_custom_javascript' );
		echo '});';
		echo '</script>';
	}

	/**
	 * Filter out tabs that don't have any meta boxes to show
	 *
	 * @param string $tab Tab information
	 *
	 * @since 0.9.6
	 */
	public function filter_empty_tabs( $tab ) {
		if ( isset( $tab['items'] ) ) {
			$tab['items'] = array_intersect( $tab['items'], $this->all_metaboxes );

			return $tab['items'];
		}

		return false;
	}

	/**
	 * Get list of items that are always displayed
	 *
	 * @param string $post_type The post type
	 *
	 * @return array List of default items
	 *
	 * @since 0.9.6
	 */
	private function get_default_items( $post_type ) {
		if ( ! $this->settings ) {
			$this->settings = new Tabify_Edit_Screen_Settings_Posttypes;
		}

		return $this->settings->get_default_items( $post_type );
	}

}
