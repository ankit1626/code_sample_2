<?php
/**
 * This file contains custom taxonomies for shop-orders post type
 *
 * @package custom-plugin
 */

/**
 * Class SS_Taxonomies
 *
 * This class is used to create taxonomies for shop-orders post type
 *
 * @package    custom-plugin
 * @subpackage custom-plugin/admin
 * @author     Ankit Parekh
 */
class SS_Taxonomies {

	/**
	 * Instance of the SS_Taxonomies
	 *
	 * @var SS_Taxonomies $instance
	 */
	private static $instance = null;

	/**
	 * Gets the instance of the class.
	 *
	 * This method creates the instance of the class if it does not exist yet.
	 * If the instance already exists, it will be returned.
	 *
	 * @return SS_Taxonomies The instance of the class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The constructor.
	 *
	 * This method is private and should not be called directly. It is used to add
	 * all the required actions and filters for custom taxonomies.
	 */
	private function __construct() {
		add_action( 'init', array( $this, 'register_taxonomies' ), 10 );
		add_action( 'machine-type_add_form_fields', array( $this, 'wdm_add_form' ), 10, 1 );
		add_action( 'machine-type_edit_form_fields', array( $this, 'wdm_edit_form' ), 10, 2 );
		add_filter( 'manage_edit-machine-type_columns', array( $this, 'custom_taxonomy_add_column' ), 10, 1 );
		add_filter( 'manage_edit-machine-type_columns', array( $this, 'custom_taxonomy_remove_count_column' ), 10, 1 );
		add_filter( 'manage_edit-machine-type_sortable_columns', array( $this, 'custom_taxonomy_add_column' ), 10, 1 );
		add_filter( 'manage_edit-printing-line_columns', array( $this, 'custom_taxonomy_remove_count_column' ), 10, 1 );
		add_action( 'manage_machine-type_custom_column', array( $this, 'custom_taxonomy_column_content' ), 10, 3 );
		add_action( 'created_machine-type', array( $this, 'save_printing_line_term_meta' ), 10, 1 );
		add_action( 'edited_machine-type', array( $this, 'save_printing_line_term_meta' ), 10, 1 );
		add_filter( 'pre_insert_term', array( $this, 'wdm_pre_save_checks' ), 10, 3 );
		add_action( 'admin_menu', array( $this, 'wdm_add_menu' ), 10 );
	}

	/**
	 * Adds submenu pages for Printing Lines and Machine Types to the Custom Plugin Customizer.
	 *
	 * @return void
	 */
	public function wdm_add_menu() {
		add_submenu_page(
			'custom-plugin-customizer',
			'Home',
			'Home',
			'manage_options',
			'admin.php?page=custom-plugin-customizer',
		);

		add_submenu_page(
			'custom-plugin-customizer',
			'Printing Lines',
			'Printing Lines',
			'manage_options',
			'edit-tags.php?taxonomy=printing-line',
		);

		add_submenu_page(
			'custom-plugin-customizer',
			'Machine Types',
			'Machine Types',
			'manage_options',
			'edit-tags.php?taxonomy=machine-type',
		);
	}

	/**
	 * Checks if the term is of type 'machine-type' and if the 'printing_line' argument is set.
	 *
	 * @param mixed  $term The term to be saved.
	 * @param string $taxonomy The taxonomy of the term.
	 * @param array  $args The arguments for the term.
	 * @return mixed|WP_Error The saved term or a WP_Error object.
	 */
	public function wdm_pre_save_checks( $term, $taxonomy, $args ) {
		if ( 'machine-type' !== $taxonomy ) {
			return $term;
		}
		if ( 'machine-type' === $taxonomy && ( ! isset( $args['printing_line'] ) || '' === $args['printing_line'] ) ) {
			return new WP_Error( 'error', 'Please select a printing line' );
		}
		return $term;
	}

	/**
	 * Removes the 'posts' column from the given array of columns.
	 *
	 * @param array $columns The array of columns.
	 * @return array The modified array of columns.
	 */
	public function custom_taxonomy_remove_count_column( $columns ) {
		if ( isset( $columns['posts'] ) ) {
			unset( $columns['posts'] );
		}
		return $columns;
	}


	/**
	 * Adds a custom column to the taxonomy table.
	 *
	 * @param array $columns The current list of columns.
	 * @return array The updated list of columns.
	 */
	public function custom_taxonomy_add_column( $columns ) {
		$columns['printing_line'] = __( 'Printing Line', 'code-sample' );
		return $columns;
	}
	/**
	 * Register taxonomies for shop-orders post type
	 *
	 * @since    1.0.0
	 */
	public function register_taxonomies() {

		$labels = array(
			'name'              => _x( 'Machine Type', 'taxonomy general name', 'code-sample' ),
			'singular_name'     => _x( 'Machine Type', 'taxonomy singular name', 'code-sample' ),
			'search_items'      => __( 'Search Machine Type', 'code-sample' ),
			'all_items'         => __( 'All Machine Types', 'code-sample' ),
			'parent_item'       => __( 'Parent Machine Type', 'code-sample' ),
			'parent_item_colon' => __( 'Parent Machine Type:', 'code-sample' ),
			'edit_item'         => __( 'Edit Machine Type', 'code-sample' ),
			'update_item'       => __( 'Update Machine Type', 'code-sample' ),
			'add_new_item'      => __( 'Add New Machine Type', 'code-sample' ),
			'new_item_name'     => __( 'New Machine Type Name', 'code-sample' ),
			'menu_name'         => __( 'Machine Type', 'code-sample' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'This taxonomy is associated with orders and printing-lines', 'code-sample' ),
			'public'             => false,
			'publicly_queryable' => false,
			'hierarchical'       => false,
			'show_ui'            => true,
			'show_in_menu'       => 'woocommerce',
			'show_in_nav_menus'  => false,
			'show_admin_column'  => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'machine-type' ),
		);
		register_taxonomy( 'machine-type', 'shop-order', $args );

		$labels = array(
			'name'              => _x( 'Printing Line', 'taxonomy general name', 'code-sample' ),
			'singular_name'     => _x( 'Printing Line', 'taxonomy singular name', 'code-sample' ),
			'search_items'      => __( 'Search Printing Line', 'code-sample' ),
			'all_items'         => __( 'All Printing Lines', 'code-sample' ),
			'parent_item'       => __( 'Parent Printing Line', 'code-sample' ),
			'parent_item_colon' => __( 'Parent Printing Line:', 'code-sample' ),
			'edit_item'         => __( 'Edit Printing Line', 'code-sample' ),
			'update_item'       => __( 'Update Printing Line', 'code-sample' ),
			'add_new_item'      => __( 'Add New Printing Line', 'code-sample' ),
			'new_item_name'     => __( 'New Printing Line Name', 'code-sample' ),
			'menu_name'         => __( 'Printing Line', 'code-sample' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'This taxonomy is used to add printing lines which can be used to categorize the shipping labels', 'code-sample' ),
			'public'             => false,
			'publicly_queryable' => false,
			'hierarchical'       => false,
			'show_ui'            => true,
			'show_in_menu'       => 'woocommerce',
			'show_in_nav_menus'  => false,
			'show_admin_column'  => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'printing-line' ),

		);
		register_taxonomy( 'printing-line', 'shop-order', $args );
	}

	/**
	 * Generates a form field for selecting a printing line.
	 *
	 * @param string $taxonomy The taxonomy of the term.
	 * @return void
	 */
	public function wdm_add_form( $taxonomy ) {
			$allowded_html = array(
				'p'      => array(),
				'div'    => array(
					'class' => array(),
				),
				'label'  => array(
					'for' => array(),
				),
				'select' => array(
					'name'     => array(),
					'required' => array(),
				),
				'option' => array(
					'selected' => array(),
					'value'    => array(),
				),
			);
			if ( 'machine-type' === $taxonomy ) {
				$terms   = get_terms(
					array(
						'taxonomy'   => 'printing-line',
						'hide_empty' => false,
					)
				);
				$select  = '<div class="form-field"><label for="printing_line">' . __( 'Printing Line', 'code-sample' ) . '</label><select name="printing_line" required>';
				$select .= '<option value="">Select a printing line</option>';
				foreach ( $terms as $term ) {
					$select .= '<option value="' . $term->term_id . '"';
					$select .= '>' . $term->name . '</option>';
				}
				$select .= '</select><p>' . __( 'Select a printing line', 'code-sample' ) . '</p></div>';
				echo wp_kses( $select, $allowded_html );
			}
	}

	/**
	 * Generates a form field for editing a machine type.
	 *
	 * @param object $selected_term The selected term.
	 * @param string $taxonomy The taxonomy of the term.
	 * @return void
	 */
	public function wdm_edit_form( $selected_term, $taxonomy ) {
		$allowded_html = array(
			'tr'     => array(
				'class' => array(),
			),
			'td'     => array(),
			'th'     => array(),
			'p'      => array(),
			'div'    => array(
				'class' => array(),
			),
			'label'  => array(
				'for' => array(),
			),
			'select' => array(
				'name'     => array(),
				'required' => array(),
			),
			'option' => array(
				'selected' => array(),
				'value'    => array(),
			),
		);
		if ( 'machine-type' === $taxonomy ) {
			$associated_printing_line_id = get_term_meta( $selected_term->term_id, 'associated_printing_line_id', true );
			$terms                       = get_terms(
				array(
					'taxonomy'   => 'printing-line',
					'hide_empty' => false,
				)
			);

			$select = '<tr class="form-field"><th><label for="printing_line">' . __( 'Printing Line', 'code-sample' ) . '</label></th><td><select name="printing_line" required>';
			foreach ( $terms as $term ) {
				$select .= "<option value='{$term->term_id}'" . ( strval( $term->term_id ) === $associated_printing_line_id ? ' selected' : '' ) . ">{$term->name}</option>";
			}
			$select .= '</select></td></tr>';
			echo wp_kses( $select, $allowded_html );
		}
	}


	/**
	 * Display content in the custom column for each term
	 *
	 * @param string $content The current content of the custom column.
	 * @param string $column_name The name of the column.
	 * @param int    $term_id The ID of the term in the column.
	 * @return string The content to be displayed in the custom column.
	 */
	public function custom_taxonomy_column_content( $content, $column_name, $term_id ) {
		if ( 'printing_line' === $column_name ) {
			// Assume the custom field is stored as term meta.
			$value   = get_term_meta( $term_id, 'associated_printing_line', true );
			$content = ! empty( $value ) ? esc_html( $value ) : __( 'N/A', 'code-sample' );
		}

		return $content;
	}


	/**
	 * Saves the printing line term meta.
	 *
	 * @param int $term_id The ID of the term.
	 * @return void
	 */
	public function save_printing_line_term_meta( $term_id ) {
		if ( ! isset( $_POST['_wpnonce_add-tag'] ) && ! isset( $_POST['_wpnonce'] ) ) {
			return;
		}
		$nonce = isset( $_POST['_wpnonce_add-tag'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce_add-tag'] ) ) : sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) );
		if ( false === wp_verify_nonce( $nonce, 'add-tag', ) && false === wp_verify_nonce( $nonce, 'update-tag_' . $term_id, ) ) {
			return;
		}
		if ( isset( $_POST['printing_line'] ) && '' !== $_POST['printing_line'] ) {
			$term      = get_term( sanitize_text_field( wp_unslash( $_POST['printing_line'] ) ), 'printing-line' );
			$term_name = $term->name;
			update_term_meta( $term_id, 'associated_printing_line', sanitize_text_field( $term_name ) );
			update_term_meta( $term_id, 'associated_printing_line_id', sanitize_text_field( wp_unslash( $_POST['printing_line'] ) ) );
		}
	}
}
