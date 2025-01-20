<?php
/**
 * This file contains all the code related to Label Generation and Storing in the uploads/shipping directory along with automatic deletion and merging.
 *
 * @package custom-plugin
 */

/**
 * This file contains all the code related to WooCommerce Orders.
 */
class SS_Settings {
	/**
	 * The instance of the class.
	 *
	 * @var SS_Settings The instance of the class.
	 */
	private static $instance;

	/**
	 * The constructor.
	 *
	 * This method is private and should not be called directly. It is used to add
	 * all the required actions and filters.
	 */
	private function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'admin_init', fn() => $this->wdm_settings_init() );
		add_action( 'admin_menu', array( $this, 'wdm_create_menu' ) );
		add_action( 'wp_ajax_wdm_save_eligible_order_status', array( $this, 'wdm_save_eligible_order_status' ), 10 );
	}

	/**
	 * Gets the instance of the class.
	 *
	 * This method creates the instance of the class if it does not exist yet.
	 * If the instance already exists, it will be returned.
	 *
	 * @return SS_Settings The instance of the class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Enqueues all the required styles for the admin interface.
	 *
	 * This method is hooked to the `admin_enqueue_scripts` action.
	 *
	 * @see https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
	 */
	public function enqueue_styles() {
		global $ss_wdm_version;
		if ( ! is_numeric( $ss_wdm_version ) ) {
			$ss_wdm_version = '1.0.0';
		}
		wp_enqueue_style( 'wdm_sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11.7.1/dist/sweetalert2.min.css', array(), $ss_wdm_version, 'all' );
		wp_enqueue_style( 'wdm_select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', array(), $ss_wdm_version, 'all' );
		wp_enqueue_style( 'wdm-admin-styles', SS_URL . 'assets/admin.css', array(), $ss_wdm_version, 'all' );
	}

	/**
	 * Enqueues all the required scripts for the admin interface.
	 *
	 * This method is hooked to the `admin_enqueue_scripts` action.
	 *
	 * @see https://developer.wordpress.org/reference/hooks/admin_enqueue_scripts/
	 */
	public function enqueue_scripts() {
		global $ss_wdm_version;
		if ( ! is_numeric( $ss_wdm_version ) ) {
			$ss_wdm_version = '1.0.0';
		}
		wp_enqueue_script( 'wdm_sweetalert2_js', 'https://cdn.jsdelivr.net/npm/sweetalert2@11.7.1/dist/sweetalert2.all.min.js', array( 'jquery' ), $ss_wdm_version, true );
		wp_enqueue_script( 'wdm_select2_js', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', array( 'jquery' ), $ss_wdm_version, true );
		wp_enqueue_script( 'wdm-admin-scripts', SS_URL . 'assets/admin.js', array( 'jquery' ), $ss_wdm_version, true );
		wp_localize_script(
			'wdm-admin-scripts',
			'ajaxobj',
			array(
				'url'                    => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( 'secret' ),
				'is_exchange_order_page' => $this->is_exchange_order_page(),
			)
		);
	}

	/**
	 * Checks if the current page is an exchange order page.
	 *
	 * @return bool If the current page is an exchange order page.
	 */
	private function is_exchange_order_page() {
		$current_screen = get_current_screen();
		if (
			is_admin() &&
			'post' === $current_screen->base &&
			'shop_order' === $current_screen->post_type &&
			isset( $_GET['action'] ) &&
			'edit' === $_GET['action'] &&
			isset( $_GET['post'] )
		) {
			$order = wc_get_order( sanitize_text_field( wp_unslash( $_GET['post'] ) ) );
			if ( empty( $order ) || ! is_object( $order ) || ! is_a( $order, 'WC_Order' ) || wdm_get_returnable_items_for_order( $order ) < 1 ) {
				return 'false';
			}
			return 'true';
		}
		return 'false';
	}

	/** This function will create a top level menu */
	public function wdm_create_menu() {
		add_menu_page(
			'Custom Plugin Customizer',
			'Custom Plugin Customizer',
			'manage_options',
			'custom-plugin-customizer',
			function () {
				?>
				<form action='options.php' method='post'>
					<?php
						settings_fields( 'custom-plugin-customizer' );
						do_settings_sections( 'custom-plugin-customizer' );
						submit_button( 'Save Changes', 'primary', 'wdm_submit_button' );
					?>
				</form>
				<?php
			},
			'',
			4,
		);
	}

	/** This function defines which order status and shipping classes must be eligible to generate labels. */
	public function wdm_save_eligible_order_status() {
		check_admin_referer( 'secret', 'nonce' );
		$field1 = ( ! isset( $_POST['wdm_selected_order_status'] ) || empty( $_POST['wdm_selected_order_status'] ) ) ? array() : $_POST['wdm_selected_order_status']; // phpcs:ignore
		$field2 = ( ! isset( $_POST['wdm_eligible_shipping_classes'] ) || empty( $_POST['wdm_eligible_shipping_classes'] ) ) ? array() : $_POST['wdm_eligible_shipping_classes']; // phpcs:ignore
		$field3 = ( ! isset( $_POST['wdm_shipping_partner_select'] ) || empty( $_POST['wdm_shipping_partner_select'] ) ) ? '' : sanitize_text_field( wp_unslash( $_POST['wdm_shipping_partner_select'] ) );
		$field4 = ( ! isset( $_POST['wdm_easypost_pdf_size'] ) || empty( $_POST['wdm_easypost_pdf_size'] ) ) ? '' : sanitize_text_field( wp_unslash( $_POST['wdm_easypost_pdf_size'] ) );
		$field5 = ( ! isset( $_POST['wdm_default_printing_line'] ) || empty( $_POST['wdm_default_printing_line'] ) ) ? '' : sanitize_text_field( wp_unslash( $_POST['wdm_default_printing_line'] ) );
		$field6 = ( ! isset( $_POST['wdm_usps_test_mode'] ) || empty( $_POST['wdm_usps_test_mode'] ) ) ? '' : sanitize_text_field( wp_unslash( $_POST['wdm_usps_test_mode'] ) );
		$field7 = ( ! isset( $_POST['wdm_shippo_test_mode'] ) || empty( $_POST['wdm_shippo_test_mode'] ) ) ? '' : sanitize_text_field( wp_unslash( $_POST['wdm_shippo_test_mode'] ) );
		$field8 = ( ! isset( $_POST['wdm_easypost_test_mode'] ) || empty( $_POST['wdm_easypost_test_mode'] ) ) ? '' : sanitize_text_field( wp_unslash( $_POST['wdm_easypost_test_mode'] ) );

		$available_printing_lines = get_terms(
			array(
				'taxonomy'   => 'printing-line',
				'hide_empty' => false,
			)
		);

		if ( ! is_array( $available_printing_lines ) ) {
			$available_printing_lines = array();
		}

		$available_printing_lines = array_map(
			function ( $printing_line ) {
				if ( ! is_object( $printing_line ) || ! isset( $printing_line->term_id ) ) {
						return '-1';
				}
				return strval( $printing_line->term_id );
			},
			$available_printing_lines
		);

		$available_shipping_classes = array();
		if ( class_exists( 'WC_Shipping' ) ) {
			$instance                   = WC_Shipping::instance();
			$available_shipping_classes = $instance->get_shipping_classes();
			if ( ! is_array( $available_shipping_classes ) ) {
				$available_shipping_classes = array();
			} else {
				$available_shipping_classes = array_map(
					function ( $shipping_class ) {
						if ( ! is_object( $shipping_class ) || ! isset( $shipping_class->slug ) ) {
							return '-1';
						} else {
							return strval( $shipping_class->slug );
						}
					},
					$available_shipping_classes
				);
			}
		}

		$available_order_status = array();

		if ( class_exists( 'Automattic\WooCommerce\Internal\Admin\Loader' ) &&
			method_exists( 'Automattic\WooCommerce\Internal\Admin\Loader', 'get_order_statuses' ) &&
			function_exists( 'wc_get_order_statuses' )
		) {
			$available_order_status = Automattic\WooCommerce\Internal\Admin\Loader::get_order_statuses( wc_get_order_statuses() );
		}

		foreach ( $field1 as $value ) {
			if ( ! in_array( $value, array_keys( $available_order_status ), true ) ) {
				wp_send_json_error( 'Invalid Order Status', 500 );
			}
		}

		foreach ( $field2 as $value ) {
			if ( ! in_array( $value, $available_shipping_classes, true ) ) {
				wp_send_json_error( 'Invalid Shipping Class', 500 );
			}
		}

		if ( ! in_array( $field3, array( 'Easypost', 'Shippo', 'USPS' ), true ) ) {
			wp_send_json_error( 'Invalid Shipping Partner', 500 );
		}
		if ( ! in_array( $field4, array( '4X4', '4X6', '8.5X11' ), true ) ) {
			wp_send_json_error( 'Invalid Easypost PDF Size', 500 );
		}
		if ( ! in_array( $field5, $available_printing_lines, true ) ) {
			wp_send_json_error( 'Invalid Printing Line', 500 );
		}
		if ( ! in_array( $field6, array( 'Yes', 'No' ), true ) ) {
			wp_send_json_error( 'Invalid USPS Mode', 500 );
		}
		if ( ! in_array( $field7, array( 'Yes', 'No' ), true ) ) {
			wp_send_json_error( 'Invalid Shippo Mode', 500 );
		}
		if ( ! in_array( $field8, array( 'Yes', 'No' ), true ) ) {
			wp_send_json_error( 'Invalid Easypost Mode', 500 );
		}

		update_option( 'wdm_eligible_order_status', $field1 );
		update_option( 'wdm_eligible_shipping_classes', $field2 );
		update_option( 'wdm_shipping_partner_selector', $field3 );
		update_option( 'wdm_easypost_pdf_size', $field4 );
		update_option( 'default_printing-line', $field5 );
		update_option( 'wdm_usps_test_mode', $field6 );
		update_option( 'wdm_shippo_test_mode', $field7 );
		update_option( 'wdm_easypost_test_mode', $field8 );
		wp_send_json_success( 'Saved', 200 );
	}


	/**
	 * This function is responsible to register and render the settings.
	 */
	private function wdm_settings_init() {
		$shipping_classes = array();
		if ( class_exists( 'WC_Shipping' ) ) {
			$instance = WC_Shipping::instance();

			$shipping_classes = $instance->get_shipping_classes();
		}
		$sections_array = array(
			array(
				'id'          => 'wdm_email_config',
				'title'       => __( 'Non Return Fee Remainder Email', 'code-sample' ),
				'description' => __( 'The Email will be sent after x days from the day when the shipping label was generated. "x" can be defined in the settings given below.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_return_period_extended',
				'title'       => __( 'Return Period Extended Email', 'code-sample' ),
				'description' => __( 'This email will notify the customer that there return period has been extended.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_return_fee_charged',
				'title'       => __( 'Return Fee Charged Email', 'code-sample' ),
				'description' => __( 'This email will notify the customer that the return fee has been charged.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_partial_return_fee_charged',
				'title'       => __( 'Partial Return Fee Charged Email', 'code-sample' ),
				'description' => __( 'This email will notify the customer that the partial return fee has been charged.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_order_converted_from_exchange_to_no_exchange',
				'title'       => __( 'Order Converted from Exchange to No Exchange', 'code-sample' ),
				'description' => __( 'This email will notify the customer that the order has been converted from exchange to no exchange.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_return_label_via_email',
				'title'       => __( 'Return Label Email', 'code-sample' ),
				'description' => __( 'This email will be send to the customer with an attachment of their return label.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_return_fee_section',
				'title'       => __( 'Return Fee Configuration', 'code-sample' ),
				'description' => __( 'The Return fee will be charged after x days from the day when the shipping label was generated. "x" can be defined in the settings given below.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_label_generation_section',
				'title'       => __( 'Label Generation Settings', 'code-sample' ),
				'description' => __( 'The Generate Shipping Label button will depend on the order status. But the download link will be available for all orders if generated and not trashed.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_delete_labels_section',
				'title'       => __( 'Removal of shipping label configuration', 'code-sample' ),
				'description' => __( 'This sections helps you configure the removal of shipping labels from the system.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_shipping_partners_section',
				'title'       => __( 'Shippo API Settings', 'code-sample' ),
				'description' => __( 'This section allows you to switch between shipping partners for return labels.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_shippo_api_settings_section',
				'title'       => __( 'Shippo API Settings', 'code-sample' ),
				'description' => __( 'This section defines all the settings related to shippo API.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_easypost_api_settings_section',
				'title'       => __( 'Easypost API Settings', 'code-sample' ),
				'description' => __( 'This section defines all the settings related to easypost API.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_shippo_parcel_setting_section',
				'title'       => __( 'Parcel Settings', 'code-sample' ),
				'description' => __( 'This section defines values for parcel object for shippo API.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_default_printing_line_section',
				'title'       => __( 'Default Printing Line', 'code-sample' ),
				'description' => __( 'The labels will be merged in the respective printing line file if it doesn\'t contains any order meta.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_extend_returns_time_section',
				'title'       => __( 'Extend Return Period', 'code-sample' ),
				'description' => __( 'Please specify the number of days by which you wish to extend the original return period of a exchange order.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_partial_return_fee_section',
				'title'       => __( 'Partial Return Fee', 'code-sample' ),
				'description' => __( 'Please specify the fee you wish to charge if customers has only returned only a single cylinder.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_exchange_to_no_exchange_conversion_section',
				'title'       => __( 'Exchange to No Exchange Conversion', 'code-sample' ),
				'description' => __( 'Please specify the fee you wish to charge if customers wish to change an exchange order to No-exchange.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_usps_config_section',
				'title'       => __( 'USPS Configuration', 'code-sample' ),
				'description' => __( 'This Sections contains all the settings related to USPS.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_fedex_config_section',
				'title'       => __( 'FedEx Configuration', 'code-sample' ),
				'description' => __( 'This Sections contains all the settings related to FedEx.', 'code-sample' ),
			),
			array(
				'id'          => 'wdm_developer_settings',
				'title'       => __( 'Developer Settings', 'code-sample' ),
				'description' => __( 'This Sections contains all the settings related to developer. Please do not change these settings unless you know what you are doing.', 'code-sample' ) . PHP_EOL . __( 'Also, do make sure that with every switch between modes you delete the following 3 options manually from the options table. 1. wdm_usps_auth_token, 2. wdm_usps_payment_token, 3. wdm_fedex_access_token', 'code-sample' ),
			),
		);

		$fields_array = array(
			// Non Return Fee Remainder Email.
			array(
				'id'              => 'wdm_email_time',
				'title'           => __( 'Email Time In Days', 'code-sample' ),
				'section'         => 'wdm_email_config',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_email_time',
					'placeholder' => 'Time in days',
					'description' => __( 'The email time is in days.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_email_subject',
				'title'           => __( 'Email Subject', 'code-sample' ),
				'section'         => 'wdm_email_config',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_email_subject',
				),
			),
			array(
				'id'              => 'wdm_email_body',
				'title'           => __( 'Email Body', 'code-sample' ),
				'section'         => 'wdm_email_config',
				'additional_info' => array(
					'type'        => 'textarea',
					'wdm_id'      => 'wdm_email_body',
					'cols'        => '40',
					'rows'        => '10',
					'description' => __( 'You can use the following placeholders', 'code-sample' ) .
					'<br> %customer_first_name% => customer first name <br> %customer_last_name% => customer last name <br> %customer_order_id% => customer order id <br>',
				),
			),
			// Return Period Extended Email.
			array(
				'id'              => 'wdm_return_period_extended_subject',
				'title'           => __( 'Email Subject', 'code-sample' ),
				'section'         => 'wdm_return_period_extended',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_return_period_extended_subject',
				),
			),
			array(
				'id'              => 'wdm_return_period_extended_body',
				'title'           => __( 'Email Body', 'code-sample' ),
				'section'         => 'wdm_return_period_extended',
				'additional_info' => array(
					'type'        => 'textarea',
					'wdm_id'      => 'wdm_return_period_extended_body',
					'cols'        => '40',
					'rows'        => '10',
					'description' => __( 'You can use the following placeholders', 'code-sample' ) .
					'<br> %customer_first_name% => customer first name <br> %customer_last_name% => customer last name <br> %customer_order_id% => customer order id <br> %new_return_date% => new return date <br> %days_extended_by% => days extended by <br>',
				),
			),
			// Return Fee Charged Email.
				array(
					'id'              => 'wdm_return_fee_charged_subject',
					'title'           => __( 'Email Subject', 'code-sample' ),
					'section'         => 'wdm_return_fee_charged',
					'additional_info' => array(
						'type'   => 'text',
						'wdm_id' => 'wdm_return_fee_charged_subject',
					),
				),
			array(
				'id'              => 'wdm_return_fee_charged_body',
				'title'           => __( 'Email Body', 'code-sample' ),
				'section'         => 'wdm_return_fee_charged',
				'additional_info' => array(
					'type'        => 'textarea',
					'wdm_id'      => 'wdm_return_fee_charged_body',
					'cols'        => '40',
					'rows'        => '10',
					'description' => __( 'You can use the following placeholders', 'code-sample' ) .
					'<br> %customer_first_name% => customer first name <br> %customer_last_name% => customer last name <br> %customer_order_id% => customer order id <br>',
				),
			),
			// Partial Return Fee Charged Email.
			array(
				'id'              => 'wdm_partial_return_fee_charged_subject',
				'title'           => __( 'Email Subject', 'code-sample' ),
				'section'         => 'wdm_partial_return_fee_charged',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_partial_return_fee_charged_subject',
				),
			),
			array(
				'id'              => 'wdm_partial_return_fee_charged_body',
				'title'           => __( 'Email Body', 'code-sample' ),
				'section'         => 'wdm_partial_return_fee_charged',
				'additional_info' => array(
					'type'        => 'textarea',
					'wdm_id'      => 'wdm_partial_return_fee_charged_body',
					'cols'        => '40',
					'rows'        => '10',
					'description' => __( 'You can use the following placeholders', 'code-sample' ) .
					'<br> %customer_first_name% => customer first name <br> %customer_last_name% => customer last name <br> %customer_order_id% => customer order id <br>',
				),
			),
			// Order Converted from Exchange to No Exchange.
			array(
				'id'              => 'wdm_order_converted_from_exchange_to_no_exchange_subject',
				'title'           => __( 'Email Subject', 'code-sample' ),
				'section'         => 'wdm_order_converted_from_exchange_to_no_exchange',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_order_converted_from_exchange_to_no_exchange_subject',
				),
			),
			array(
				'id'              => 'wdm_order_converted_from_exchange_to_no_exchange_body',
				'title'           => __( 'Email Body', 'code-sample' ),
				'section'         => 'wdm_order_converted_from_exchange_to_no_exchange',
				'additional_info' => array(
					'type'        => 'textarea',
					'wdm_id'      => 'wdm_order_converted_from_exchange_to_no_exchange_body',
					'cols'        => '40',
					'rows'        => '10',
					'description' => __( 'You can use the following placeholders', 'code-sample' ) .
					'<br> %customer_first_name% => customer first name <br> %customer_last_name% => customer last name <br> %customer_order_id% => customer order id <br>',
				),
			),
			// Return Label Download Email.
						array(
							'id'              => 'wdm_return_label_via_email_subject',
							'title'           => __( 'Email Subject', 'code-sample' ),
							'section'         => 'wdm_return_label_via_email',
							'additional_info' => array(
								'type'   => 'text',
								'wdm_id' => 'wdm_return_label_via_email_subject',
							),
						),
			array(
				'id'              => 'wdm_return_label_via_email_body',
				'title'           => __( 'Email Body', 'code-sample' ),
				'section'         => 'wdm_return_label_via_email',
				'additional_info' => array(
					'type'        => 'textarea',
					'wdm_id'      => 'wdm_return_label_via_email_body',
					'cols'        => '40',
					'rows'        => '10',
					'description' => __( 'You can use the following placeholders', 'code-sample' ) .
					'<br> %customer_first_name% => customer first name <br> %customer_last_name% => customer last name <br> %customer_order_id% => customer order id <br>',
				),
			),
			// Return Fee Section.
			array(
				'id'              => 'wdm_return_fee',
				'title'           => __( 'Return Fee', 'code-sample' ),
				'section'         => 'wdm_return_fee_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_return_fee',
					'description' => __( 'Kindly make sure you enter the value in cents', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_charge_fee_time',
				'title'           => __( 'Charge fee on', 'code-sample' ),
				'section'         => 'wdm_return_fee_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_charge_fee_time',
					'placeholder' => 'Time in days',
					'description' => __( 'Time in days(Inclusive)', 'code-sample' ),
				),
			),

			// Label Generation Section.
			array(
				'id'              => 'wdm_merged_label_email',
				'title'           => __( 'Email Id', 'code-sample' ),
				'section'         => 'wdm_label_generation_section',
				'additional_info' => array(
					'type'        => 'email',
					'wdm_id'      => 'wdm_merged_label_email',
					'description' => __( 'The merged labels will be send to the above email id', 'code-sample' ),
				),
			),
			array(
				'id'       => 'wdm_eligible_order_status',
				'title'    => __( 'Select Woocommerce Order status for which inbound shipping label can be generated', 'code-sample' ),
				'callback' => function () {
					$options = get_option( 'wdm_eligible_order_status', array() );
					if ( empty( $options ) && ! is_array( $options ) ) {
						$options = array();
					}
					if ( ! class_exists( 'Automattic\WooCommerce\Internal\Admin\Loader' ) || ! method_exists( 'Automattic\WooCommerce\Internal\Admin\Loader', 'get_order_statuses' ) || ! function_exists( 'wc_get_order_statuses' ) ) {
						echo 'Please install and activate the <a href="https://wordpress.org/plugins/automattic-woocommerce/" target="_blank">WooCommerce plugin</a>';
						return;
					}
					$order_statuses = Automattic\WooCommerce\Internal\Admin\Loader::get_order_statuses( wc_get_order_statuses() );
					$this->wdm_select(
						'wdm_order_status_select',
						'',
						$options,
						$order_statuses,
						10,
						true,
						true
					);
				},
				'section'  => 'wdm_label_generation_section',
			),
			array(
				'id'              => 'wdm_eligible_shipping_classes',
				'title'           => __( 'Select Woocommerce Shipping classes for which inbound shipping label can be generated', 'code-sample' ),
				'callback'        => function ( $args ) {
					$shipping_classes = $args['shipping-classes'];
					$shipping_classes = array_map(
						function ( $shipping_class ) {
							return $shipping_class->slug;
						},
						$shipping_classes
					);
					$options = get_option( 'wdm_eligible_shipping_classes', array() );
					if ( empty( $options ) && ! is_array( $options ) ) {
						$options = array();
					}
					$this->wdm_select(
						'wdm_eligible_shipping_class_select',
						'',
						$options,
						$shipping_classes,
						10,
						true,
						false
					);
				},
				'section'         => 'wdm_label_generation_section',
				'additional_info' => array(
					'shipping-classes' => $shipping_classes,
				),
			),

			// Delete Labels Section.
			array(
				'id'              => 'wdm_delete_shipping_labels',
				'title'           => __( 'Delete shipping labels', 'code-sample' ),
				'section'         => 'wdm_delete_labels_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_delete_shipping_labels',
					'placeholder' => 'Time in days',
					'description' => __( 'Time in days(Inclusive)', 'code-sample' ),
				),
			),

			// Shipping Partners Section.
			array(
				'id'       => 'wdm_shipping_partner_selector',
				'title'    => __( 'Shipping Partner Selector', 'code-sample' ),
				'callback' => function () {
					$available_shipping_partners = array( 'Shippo', 'Easypost', 'USPS' );
					$options                     = get_option( 'wdm_shipping_partner_selector', false );
					$this->wdm_select(
						'wdm_shipping_partner_select',
						'wdm_shipping_partner',
						$options,
						$available_shipping_partners,
						0,
						false,
						false,
					);
				},
				'section'  => 'wdm_shipping_partners_section',

			),

			// Shippo API Section.
			/** Shippo Outbound Settings.... */
			array(
				'id'              => 'wdm_shippo_auth_token',
				'title'           => __( 'Shippo Authentication Token', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_shippo_auth_token',
					'description' => __( 'Kindly refer following page <a href="https://apps.goshippo.com/settings/api">here</a>', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_shippo_outbound_carrier_token',
				'title'           => __( 'Outbound Carrier Name', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_shippo_outbound_carrier_token',
				),
			),
			array(
				'id'              => 'wdm_shippo_outbound_carrier_account',
				'title'           => __( 'Oubound Carrier', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_shippo_outbound_carrier_account',
				),
			),
			array(
				'id'              => 'wdm_shippo_outbound_service_level',
				'title'           => __( 'Outbound Shipping Service Level', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_shippo_outbound_service_level',
					'description' => __( 'Checkout service levels <a href="https://docs.goshippo.com/shippoapi/public-api/#tag/Service-Levels">here</a>', 'code-sample' ),
				),
			),
			/** Shippo Inbound Settings.... */
			array(
				'id'              => 'wdm_shippo_inbound_carrier_account',
				'title'           => __( 'Inbound Carrier', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_shippo_inbound_carrier_account',
				),
			),
			array(
				'id'              => 'wdm_shippo_inbound_service_level',
				'title'           => __( 'Inbound Shipping Service Level', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_shippo_inbound_service_level',
					'description' => __( 'Checkout service levels <a href="https://docs.goshippo.com/shippoapi/public-api/#tag/Service-Levels">here</a>', 'code-sample' ),
				),
			),
			/** Shippo Information */
			array(
				'id'              => 'wdm_shippo_company_name',
				'title'           => __( 'Company Name', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_shippo_company_name',
				),
			),
			array(
				'id'              => 'wdm_shippo_sender_name',
				'title'           => __( 'Sender Name', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_shippo_sender_name',
				),
			),
			array(
				'id'              => 'wdm_shippo_sender_contact',
				'title'           => __( 'Sender Contact', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_shippo_sender_contact',
				),
			),
			array(
				'id'              => 'wdm_shippo_sender_email',
				'title'           => __( 'Sender Email', 'code-sample' ),
				'section'         => 'wdm_shippo_api_settings_section',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_shippo_sender_email',
				),
			),

			/** Easypost Settings */
			array(
				'id'              => 'wdm_easypost_auth_token',
				'title'           => __( 'EasyPost Authentication Token', 'code-sample' ),
				'section'         => 'wdm_easypost_api_settings_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_easypost_auth_token',
					'description' => __( 'Kindly refer following page <a href="https://www.easypost.com/account/api-keys">here</a>', 'code-sample' ),
				),
			),
			/**Easypost Inbound Settings */
			array(
				'id'              => 'wdm_easypost_inbound_carrier_account',
				'title'           => __( 'Inbound Carrier', 'code-sample' ),
				'section'         => 'wdm_easypost_api_settings_section',
				'additional_info' => array(
					'type'   => 'text',
					'wdm_id' => 'wdm_easypost_inbound_carrier_account',
				),
			),
			array(
				'id'              => 'wdm_easypost_inbound_service_level',
				'title'           => __( 'Inbound Shipping Service Level', 'code-sample' ),
				'section'         => 'wdm_easypost_api_settings_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_easypost_inbound_service_level',
					'description' => __( 'Checkout service levels <a href="https://docs.goshippo.com/shippoapi/public-api/#tag/Service-Levels">here</a>', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_ep_inbound_fedex_carrier_account',
				'title'           => __( 'EasyPost Fedex Carrier Account', 'code-sample' ),
				'section'         => 'wdm_easypost_api_settings_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_ep_inbound_fedex_carrier_account',
					'description' => __( 'Enter your EasyPost Fedex Carrier Account.This will only be used if the customer has selected their return partner as Fedex.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_ep_inbound_fedex_service_level',
				'title'           => __( 'EasyPost Fedex Service Level', 'code-sample' ),
				'section'         => 'wdm_easypost_api_settings_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_ep_inbound_fedex_service_level',
					'description' => __( 'Enter your EasyPost Fedex Service Level.This will only be used if the customer has selected their return partner as Fedex.', 'code-sample' ),
				),
			),
			array(
				'id'       => 'wdm_shipping_label_size',
				'title'    => __( 'Select Shipping label size', 'code-sample' ),
				'callback' => function () {
					$available_sizes = array( '4X4', '4X6', '8.5X11' );
					$options = get_option( 'wdm_easypost_pdf_size', false );
					$this->wdm_select(
						'wdm_easypost_pdf_size',
						'',
						$options,
						$available_sizes,
						0,
						false,
						false
					);
				},
				'section'  => 'wdm_easypost_api_settings_section',
			),

			/**Parcel Settings */
			array(
				'id'              => 'wdm_shippo_parcel_height',
				'title'           => __( 'Height', 'code-sample' ),
				'section'         => 'wdm_shippo_parcel_setting_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_shippo_parcel_height',
					'description' => __( 'Must be in inches.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_shippo_parcel_width',
				'title'           => __( 'Width', 'code-sample' ),
				'section'         => 'wdm_shippo_parcel_setting_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_shippo_parcel_width',
					'description' => __( 'Must be in inches.', 'code-sample' ),
				),

			),
			array(
				'id'              => 'wdm_shippo_parcel_length',
				'title'           => __( 'Length', 'code-sample' ),
				'section'         => 'wdm_shippo_parcel_setting_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_shippo_parcel_length',
					'description' => __( 'Must be in inches.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_shippo_parcel_weight',
				'title'           => __( 'Weight', 'code-sample' ),
				'section'         => 'wdm_shippo_parcel_setting_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_shippo_parcel_weight',
					'description' => __( 'Must be in ounce.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_shippo_parcel_weight_in_return',
				'title'           => __( 'Weight During Return', 'code-sample' ),
				'section'         => 'wdm_shippo_parcel_setting_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_shippo_parcel_weight_in_return',
					'description' => __( 'Must be in ounce.', 'code-sample' ),
				),
			),
			/**Default Printing Line */
			array(
				'id'       => 'wdm_default_printing_line',
				'title'    => __( 'Select Default Printing Line', 'code-sample' ),
				'callback' => function () {
					$default = intval( get_option( 'default_printing-line', false ) );
					$terms   = get_terms(
						array(
							'taxonomy'   => 'printing-line',
							'hide_empty' => false,
						)
					);
					if ( empty( $terms ) || ! is_array( $terms ) ) {
						echo esc_html__( 'No printing lines found. Kindly create an printing line', 'code-sample' );
						return;
					}
					$terms = array_map( fn( $term ) => array( $term->term_id => $term->name ), $terms );
					$terms = array_replace( ...$terms );
					$this->wdm_select(
						'wdm_default_printing_line',
						'wdm_default_printing_line',
						$default,
						$terms,
						0,
						false,
						true,
					);
				},
				'section'  => 'wdm_default_printing_line_section',
			),

			array(
				'id'              => 'wdm_extend_returns_time',
				'title'           => __( 'Extend Return Period', 'code-sample' ),
				'section'         => 'wdm_extend_returns_time_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_extend_returns_time',
					'description' => __( 'Must be in days.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_partial_return_fee',
				'title'           => __( 'Partial Return Fee', 'code-sample' ),
				'section'         => 'wdm_partial_return_fee_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_partial_return_fee',
					'description' => __( 'Must be in cents.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_exchange_to_no_exchange_conversion_fee',
				'title'           => __( 'Exchange to No Exchange Conversion fee', 'code-sample' ),
				'section'         => 'wdm_exchange_to_no_exchange_conversion_section',
				'additional_info' => array(
					'type'        => 'number',
					'wdm_id'      => 'wdm_exchange_to_no_exchange_conversion_fee',
					'description' => __( 'Must be in cents.', 'code-sample' ),
				),
			),
			/** USPS Settings */
			array(
				'id'              => 'wdm_usps_consumer_id',
				'title'           => __( 'Consumer ID', 'code-sample' ),
				'section'         => 'wdm_usps_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_usps_consumer_id',
					'description' => __( 'Enter your consumer ID.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_usps_consumer_secret',
				'title'           => __( 'Consumer Secret', 'code-sample' ),
				'section'         => 'wdm_usps_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_usps_consumer_secret',
					'description' => __( 'Enter your consumer secret.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_usps_crid',
				'title'           => __( 'CRID', 'code-sample' ),
				'section'         => 'wdm_usps_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_usps_crid',
					'description' => __( 'Enter Your Customer Registration ID.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_usps_mid',
				'title'           => __( 'MID', 'code-sample' ),
				'section'         => 'wdm_usps_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_usps_mid',
					'description' => __( 'Enter your MID.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_usps_mmid',
				'title'           => __( 'Manifest MID', 'code-sample' ),
				'section'         => 'wdm_usps_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_usps_mmid',
					'description' => __( 'Enter your Manifest MID.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_usps_acc_type',
				'title'           => __( 'Account Type', 'code-sample' ),
				'section'         => 'wdm_usps_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_usps_acc_type',
					'description' => __( 'Enter your Account Type.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_usps_acc_number',
				'title'           => __( 'Account Number', 'code-sample' ),
				'section'         => 'wdm_usps_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_usps_acc_number',
					'description' => __( 'Enter your Account Number.', 'code-sample' ),
				),
			),
			/**Fedex Settings */
			array(
				'id'              => 'wdm_fedex_client_id',
				'title'           => __( 'Fedex Client ID', 'code-sample' ),
				'section'         => 'wdm_fedex_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_fedex_client_id',
					'description' => __( 'Enter your Fedex Client ID.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_fedex_client_secret',
				'title'           => __( 'Fedex Client Secret', 'code-sample' ),
				'section'         => 'wdm_fedex_config_section',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_fedex_client_secret',
					'description' => __( 'Enter your Fedex Client Secret.', 'code-sample' ),
				),
			),
			/**Developer Settings */
			array(
				'id'       => 'wdm_usps_test_mode',
				'title'    => __( 'Enable USPS Test Mode', 'code-sample' ),
				'callback' => function () {
					$haystack = array(
						__( 'Yes', 'code-sample' ),
						__( 'No', 'code-sample' ),
					);
					$this->wdm_select(
						'wdm_usps_test_mode',
						'',
						get_option( 'wdm_usps_test_mode', 'No' ),
						$haystack,
						0,
						false,
						false
					);
				},
				'section'  => 'wdm_developer_settings',
			),
			array(
				'id'       => 'wdm_shippo_test_mode',
				'title'    => __( 'Enable Shippo Test Mode', 'code-sample' ),
				'callback' => function () {
					$haystack = array(
						__( 'Yes', 'code-sample' ),
						__( 'No', 'code-sample' ),
					);
					$this->wdm_select(
						'wdm_shippo_test_mode',
						'',
						get_option( 'wdm_shippo_test_mode', 'No' ),
						$haystack,
						0,
						false,
						false
					);
				},
				'section'  => 'wdm_developer_settings',
			),
			array(
				'id'       => 'wdm_easypost_test_mode',
				'title'    => __( 'Enable Easypost Test Mode', 'code-sample' ),
				'callback' => function () {
					$haystack = array(
						__( 'Yes', 'code-sample' ),
						__( 'No', 'code-sample' ),
					);
					$this->wdm_select(
						'wdm_easypost_test_mode',
						'',
						get_option( 'wdm_easypost_test_mode', 'No' ),
						$haystack,
						0,
						false,
						false
					);
				},
				'section'  => 'wdm_developer_settings',
			),
			array(
				'id'              => 'wdm_shippo_test_auth_token',
				'title'           => __( 'Shippo Authentication Test Token', 'code-sample' ),
				'section'         => 'wdm_developer_settings',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_shippo_test_auth_token',
					'description' => __( 'Kindly refer following page <a href="https://apps.goshippo.com/settings/api">here</a>', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_easypost_test_auth_token',
				'title'           => __( 'EasyPost Authentication Test Token', 'code-sample' ),
				'section'         => 'wdm_developer_settings',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_easypost_test_auth_token',
					'description' => __( 'Kindly refer following page <a href="https://www.easypost.com/account/api-keys">here</a>', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_fedex_test_client_id',
				'title'           => __( 'Fedex Test Client ID', 'code-sample' ),
				'section'         => 'wdm_developer_settings',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_fedex_test_client_id',
					'description' => __( 'Enter your Fedex Test Client ID.', 'code-sample' ),
				),
			),
			array(
				'id'              => 'wdm_fedex_test_client_secret',
				'title'           => __( 'Fedex Test Client Secret', 'code-sample' ),
				'section'         => 'wdm_developer_settings',
				'additional_info' => array(
					'type'        => 'text',
					'wdm_id'      => 'wdm_fedex_test_client_secret',
					'description' => __( 'Enter your Fedex Test Client Secret.', 'code-sample' ),
				),
			),
		);

		register_setting( 'custom-plugin-customizer', 'custom_plugin_options' );

		foreach ( $sections_array as $section ) {
			add_settings_section(
				$section['id'],
				$section['title'],
				fn( $args ) => $this->wdm_render_section( $args ),
				'custom-plugin-customizer',
				array(
					'description' => $section['description'],
				)
			);
		}

		foreach ( $fields_array as $field ) {
			if ( isset( $field['callback'] ) ) {
				add_settings_field(
					$field['id'],
					$field['title'],
					fn( $args ) => call_user_func( $field['callback'], $args ),
					'custom-plugin-customizer',
					$field['section'],
					isset( $field['additional_info'] ) ? $field['additional_info'] : array()
				);
			} else {
				add_settings_field(
					$field['id'],
					$field['title'],
					fn( $args ) => $this->field_generator( $args ),
					'custom-plugin-customizer',
					$field['section'],
					isset( $field['additional_info'] ) ? $field['additional_info'] : array()
				);
			}
		}
	}

	/**
	 * This function is responsible for rendering sections.
	 *
	 * @param array $args desc.
	 */
	private function wdm_render_section( $args ) {
		if ( isset( $args['id'] ) ) {
			echo '<div id="' . esc_attr( $args['id'] ) . '">';
			if ( isset( $args['description'] ) ) {
				echo '<p>' . esc_html( $args['description'] ) . '</p>';
			}
			echo '</div>';
		}
	}
	/**
	 * This function is responsible for rendering text fields and textareas.
	 *
	 * @param array $args desc.
	 */
	private function field_generator( $args ) {
		$option = get_option( 'custom_plugin_options', array() );
		$option = isset( $option[ $args['wdm_id'] ] ) ? $option[ $args['wdm_id'] ] : '';

		$placeholder = isset( $args['placeholder'] ) ? $args['placeholder'] : '';
		if ( 'textarea' !== $args['type'] ) {
			echo '<input type = "' . esc_attr( $args['type'] ) . '"name="custom_plugin_options[' . esc_attr( $args['wdm_id'] ) . ']" value="' . esc_attr( $option ) . '" placeholder = "' . esc_attr( $placeholder ) . '">';
			if ( isset( $args['description'] ) ) {
				echo '<p>' . wp_kses_post( $args['description'] ) . '</p>';
			}
		} else {
			echo '<textarea name="custom_plugin_options[' . esc_attr( $args['wdm_id'] ) . ']" cols="' . esc_attr( $args['cols'] ) . '" rows="' . esc_attr( $args['rows'] ) . '">' . esc_html( $option ) . '</textarea>';
			if ( isset( $args['description'] ) ) {
				echo '<p>' . wp_kses_post( $args['description'] ) . '</p>';
			}
		}

		$option = get_option( 'custom_plugin_options', array() );
	}

	/**
	 * A helper function for generating select fields.
	 *
	 * @param string $id          The id attribute for the select element.
	 * @param string $name        The name attribute for the select element.
	 * @param mixed  $needle      The value or array of values to select.
	 * @param array  $haystack    An array of options.
	 * @param int    $size        The size attribute for the select element.
	 * @param bool   $multiple    Whether the select element should allow multiple values.
	 * @param bool   $use_array_keys Whether to use the keys of $haystack as the values of the options.
	 */
	private function wdm_select( string $id, string $name, mixed $needle, array $haystack, int $size, bool $multiple, bool $use_array_keys ) {
		echo false === $multiple ? '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '">' : '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '" multiple size="' . esc_attr( $size ) . '">';

		if ( ! $multiple ) {
			echo '<option value="">' . esc_html__( 'Select Value', 'code-sample' ) . '</option>';
		}
		if ( $use_array_keys ) {
			if ( is_array( $needle ) ) {
				foreach ( $haystack as $key => $value ) {
					$selected = in_array( $key, $needle, true ) ? 'selected' : '';
					echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $value ) . '</option>';
				}
			} else {
				foreach ( $haystack as $key => $value ) {
					$selected = $key === $needle ? 'selected' : '';
					echo '<option value="' . esc_attr( $key ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $value ) . '</option>';
				}
			}
		} elseif ( is_array( $needle ) ) {
			foreach ( $haystack as $value ) {
				$selected = in_array( $value, $needle, true ) ? 'selected' : '';
				echo '<option value="' . esc_attr( $value ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $value ) . '</option>';
			}
		} else {
			foreach ( $haystack as $value ) {
				$selected = $value === $needle ? 'selected' : '';
				echo '<option value="' . esc_attr( $value ) . '" ' . esc_attr( $selected ) . '>' . esc_html( $value ) . '</option>';
			}
		}
		echo '</select>';
	}
}
