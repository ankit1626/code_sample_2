<?php
/**
 * This file contains all the code related to WooCommerce.
 *
 * @package custom-plugin
 */

/**
 * SS_WC
 */
class SS_WC {
	/**
	 * The instance of the class.
	 *
	 * @var SS_WC The instance of the class.
	 */
	private static $instance;

	/**
	 * Gets the instance of the class.
	 *
	 * This method creates the instance of the class if it does not exist yet.
	 * If the instance already exists, it will be returned.
	 *
	 * @return SS_WC The instance of the class.
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
	 * all the required actions and filters.
	 */
	private function __construct() {
		add_filter( 'wc_stripe_force_save_source', fn( $card_save, $source = 'wdm_none' ) => 'wdm_none' === $source ? false : true, 10, 2 );
		add_filter( 'wc_stripe_display_save_payment_method_checkbox', fn() => false, 10 );
		add_action( 'woocommerce_view_order', array( $this, 'wdm_inbound_tracking' ), 10, 1 );
		add_action( 'woocommerce_checkout_fields', array( $this, 'wdm_add_dropdown' ), 99, 1 );
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'save_custom_field_in_order_meta' ), 10, 2 );
		add_action( 'woocommerce_account_wdm-soda-machine_endpoint', array( $this, 'wdm_display_soda_machine_content' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( $this, 'wdm_add_custom_column_data' ), 20, 2 );
		add_filter( 'manage_edit-shop_order_columns', array( $this, 'wdm_add_custom_column' ), 10, 1 );
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'wdm_handle_custom_query_var' ), 10, 2 );
		add_action(
			'init',
			function () {
				add_rewrite_endpoint( 'wdm-soda-machine', EP_NONE );
			}
		);
	}


	/**
	 * Shows the tracking number and the tracking status of the inbound shipment of an order on the order view page.
	 *
	 * @param int $order_id The ID of the order to show the tracking information for.
	 */
	public function wdm_inbound_tracking( $order_id ) {
		$order                    = wc_get_order( $order_id );
		$outbound_tracking_number = $order->get_meta( 'wdm_shippo_outbound_tracking_number' );
		$outbound_tracking_url    = $order->get_meta( 'wdm_shippo_outbound_tracking_url' );
		$tracking_status          = wdm_get_inbound_tracking_status( $order );
		if ( ! empty( $outbound_tracking_number ) && ! empty( $outbound_tracking_url ) ) {
			echo '<p>' . esc_html( __( 'Tracking Number:', 'code-sample' ) ) . ' <a target="__blank" href="' . esc_url( $outbound_tracking_url ) . '">' . esc_html( $outbound_tracking_number ) . '</a></p>';
		}
		if ( empty( $tracking_status ) ) {
			return;
		}
		echo '<p>' . esc_html( __( 'Return Label\'s Tracking Status:', 'code-sample' ) ) . ' ' . esc_html( $tracking_status ) . '</p>';
	}


	/**
	 * Adds a dropdown field on the checkout page to select the soda machine brand/cylinder type.
	 *
	 * @param array $fields The checkout fields.
	 *
	 * @return array The modified checkout fields.
	 */
	public function wdm_add_dropdown( $fields ) {
		$user_id                    = get_current_user_id();
		$current_soda_machine_value = get_user_meta( $user_id, 'wdm_default_machine_type', true );
		$current_return_partner     = get_user_meta( $user_id, 'wdm_return_partner', true );
		$current_return_partner     = empty( $current_return_partner ) ? 'usps' : $current_return_partner;
		$options_array              = array();
		$terms                      = get_terms(
			array(
				'taxonomy'   => 'machine-type',
				'hide_empty' => false,
			)
		);
		$options_array['']          = __( 'Please Select', 'code-sample' );
		foreach ( $terms as $term ) {
			$options_array[ $term->term_id ] = $term->name;
		}
		$cst_field = array(
			'machine_type' => array(
				'type'     => 'select',
				'id'       => 'wdm_machine_type',
				'label'    => __( 'SET YOUR SODA MACHINE BRAND/CYLINDER TYPE', 'code-sample' ),
				'options'  => $options_array,
				'required' => false,
				'default'  => $current_soda_machine_value,
			),
		);

		/**
		 * Until and unless fedex label size issue isn't resolved please do no uncomment the below code.
		if ( $this->wdm_check_shipping_class() ) {
			$cst_field['return_partner'] = array(
				'type'     => 'select',
				'id'       => 'wdm_return_partner',
				'label'    => __( 'RETURN PARTNER', 'code-sample' ),
				'options'  => array(
					''      => __( 'Please Select', 'code-sample' ),
					'usps'  => __( 'USPS', 'code-sample' ),
					'fedex' => __( 'FedEx', 'code-sample' ),
				),
				'required' => true,
				'default'  => $current_return_partner,
			);
		}
		*/
		$fields['billing'] = array_merge( $cst_field, $fields['billing'] );
		return $fields;
	}

	/**
	 * Saves the custom field value in the order meta.
	 *
	 * This method saves the machine type selected by the user in the order meta.
	 * If the machine type is not set in the request, it will retrieve the default
	 * machine type from the user meta and save it in the order meta.
	 *
	 * @param int   $order_id The ID of the order.
	 * @param array $data The data of the custom field.
	 * @return void
	 */
	public function save_custom_field_in_order_meta( $order_id, $data ) {
		$user_id = get_current_user_id();
		if ( 0 === $user_id ) {
			return;
		}
		if ( isset( $data['machine_type'] ) && ! empty( $data['machine_type'] ) ) {
			$custom_field_value = sanitize_text_field( wp_unslash( $data['machine_type'] ) );
			update_post_meta( $order_id, 'machine_type', $custom_field_value );
			update_user_meta( $user_id, 'wdm_default_machine_type', $custom_field_value );
		} else {
			$default_machine_type = get_user_meta( $user_id, 'wdm_default_machine_type', true );
			if ( ! is_string( $default_machine_type ) ) {
				$default_machine_type = '';
			}
			update_post_meta( $order_id, 'machine_type', $default_machine_type );
		}
		/**
		 * Until and unless fedex label size issue isn't resolved please do no uncomment the below code.
		if ( isset( $data['return_partner'] ) && $this->wdm_check_shipping_class() ) {
			$custom_field_value = sanitize_text_field( wp_unslash( $data['return_partner'] ) );
			if ( ! in_array( $custom_field_value, array( 'usps', 'fedex' ), true ) ) {
				$custom_field_value = 'usps';
			}
			update_post_meta( $order_id, 'return_partner', $custom_field_value );
			update_user_meta( $user_id, 'wdm_return_partner', $custom_field_value );
		}
		*/
		/** Remove the below code once the fedex label size issue is resolved. */
		if ( $this->wdm_check_shipping_class() ) {
			update_post_meta( $order_id, 'return_partner', 'usps' );
			update_user_meta( $user_id, 'wdm_return_partner', 'usps' );
		}
	}

	/**
	 * Callback function to display content on 'soda-machine' endpoint.
	 * */
	public function wdm_display_soda_machine_content() {
		global $woocommerce;
		$user_id                    = get_current_user_id();
		$current_soda_machine_value = get_user_meta( $user_id, 'wdm_default_machine_type', true );

		$terms = get_terms(
			array(
				'taxonomy'   => 'machine-type',
				'hide_empty' => false,
			)
		);

		if (
			isset( $_POST['nonce'] ) &&
			! empty( $_POST['nonce'] ) &&
			false !== wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'sec' ) &&
			isset( $_POST['sodamachine_update'] ) &&
			isset( $_POST['sodamachine'] ) &&
			! empty( $_POST['sodamachine'] )
		) {
			$new_soda_machine_value = sanitize_text_field( wp_unslash( $_POST['sodamachine'] ) );
			update_user_meta( $user_id, 'wdm_default_machine_type', $new_soda_machine_value );
			$current_soda_machine_value = $new_soda_machine_value;
			wc_add_notice( 'Soda machine updated successfully.', 'success' );
		}

		?>
	<form method="post">
		<?php wc_print_notices(); ?>

		<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">
			<label for="sodamachine"><?php esc_html_e( 'Soda Machine Brand:', 'code-sample' ); ?></label>
			<select name="sodamachine" id="sodamachine">
			<option value=""><?php esc_html_e( 'Select Soda Machine', 'code-sample' ); ?></option>
			<?php foreach ( $terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $current_soda_machine_value, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
			</select>
		</p>
		<?php wp_nonce_field( 'sec', 'nonce' ); ?>
		<p>
			<button type="submit"  name="sodamachine_update"><?php esc_html_e( 'Update Soda Machine', 'code-sample' ); ?></button>
		</p>
	</form>
		<?php
	}

		/** This function adds an additional column in woocommers orders page(admin).
		 *
		 * @param array $columns Array of columns.
		 */
	public function wdm_add_custom_column( $columns ) {
		$columns['wdm_shipping_label']                  = __( 'Shipping Label', 'code-sample' );
		$columns['wdm_reset_orders']                    = __( 'Reset Orders', 'code-sample' );
		$columns['wdm_shippo_outbound_tracking_status'] = __( 'Tracking Status For Outbound', 'code-sample' );
		$columns['wdm_tracking_status_inbound']         = __( 'Tracking Status For Inbound', 'code-sample' );
		return $columns;
	}

	/** This function adds data to custom column Shipping Label
	 *
	 * @param string $column The name of column.
	 * @param int    $order_id The id of woocommerce order for which the data is being collected.
	 */
	public function wdm_add_custom_column_data( $column, $order_id ) {
		if ( 'wdm_shipping_label' === $column ) {
			$order = wc_get_order( $order_id );
			$path  = wp_upload_dir();
			$url   = $path['baseurl'];
			$html  = '';
			if ( ! empty( $order->get_meta( 'merged-shipping-label-generated' ) ) && empty( $order->get_meta( 'wdm_labels_deleted' ) ) ) {
				$mergedpath = $url . '/shipping/' . $order_id . '.pdf';
				$html       = '<a target="_blank" href="' . esc_url( $mergedpath ) . '">Download labels</a>';
				echo wp_kses_post( $html );
				return;
			}
			if ( ! empty( $order->get_meta( 'outbound-shipping-label-generated' ) ) ) {
				$outboundpath = $url . '/shipping/' . $order_id . ' outbound.pdf';
				$html         = '<a target="_blank" href="' . esc_url( $outboundpath ) . '">Download outbound labels</a>';
			}
			if ( ! empty( $order->get_meta( 'inbound-shipping-label-generated' ) ) ) {
				$inboundpath = $url . '/shipping/' . $order_id . ' inbound.pdf';
				$html        = $html . '<br><a target="_blank" href="' . esc_url( $inboundpath ) . '">Download inbound labels</a>';
			}
			if ( ! empty( $html ) ) {
				if ( 'yes' === $order->get_meta( 'wdm_labels_deleted' ) ) {
					echo wp_kses_post( __( 'Labels has been removed from the system', 'code-sample' ) );
					return;
				}
				echo wp_kses_post( $html );
				return;
			}
			$order_status = wc_get_order( $order_id );
			$order_items  = count( $order->get_items() );
			$order_status = $order->get_status();
			$options      = get_option( 'wdm_eligible_order_status', array() );
			if ( empty( $options ) && ! is_array( $options ) ) {
				$options = array();
			}
			if ( in_array( $order_status, $options, true ) && 1 === $order_items ) {
				echo '<button class = "wdm_gen_label button button-primary" data-id="' . esc_attr( $order_id ) . '">Generate Label</button>';
			} else {
				echo '-';
			}
		}

		if ( 'wdm_tracking_status_inbound' === $column || 'wdm_shippo_outbound_tracking_status' === $column ) {
			$order = wc_get_order( $order_id );
			if ( 'wdm_tracking_status_inbound' === $column ) {
				$tracking_status = wdm_get_inbound_tracking_status( $order );
			} else {
				$tracking_status = $order->get_meta( $column );
			}

			switch ( $tracking_status ) {
				case 'PRE_TRANSIT':
				case 'TRANSIT':
				case 'DELIVERED':
				case 'pre_transit':
				case 'in_transit':
				case 'out_for_delivery':
				case 'delivered':
					echo '<mark class="order-status status-processing"><span>' . esc_html( $tracking_status ) . '</span></mark>';
					break;
				case 'RETURNED':
				case 'FAILURE':
				case 'failure':
				case 'cancelled':
				case 'error':
					echo '<mark class="order-status status-failed"><span>' . esc_html( $tracking_status ) . '</span></mark>';
					break;
				default:
					echo '<mark class="order-status"><span>' . esc_html( $tracking_status ) . '</span></mark>';
					break;
			}
		}

		if ( 'wdm_reset_orders' === $column ) {
			$order                      = wc_get_order( $order_id );
			$order_status               = $order->has_status( 'shipped' );
			$failed_inbound_generattion = $order->has_status( 'processing' ) && ! empty( $order->get_meta( 'wdm_shippo_outbound_tracking_number' ) ) && wdm_get_returnable_items_for_order( $order ) > 0 && empty( wdm_get_inbound_tracking_partner( $order ) );
			if ( $order_status || $failed_inbound_generattion ) {
				echo '<button class = "wdm_reset_order button button-primary" data-id="' . esc_attr( $order_id ) . '">Reset Order</button>';
			} else {
				echo '<button class = "wdm_reset_order button button-primary" disabled data-id="' . esc_attr( $order_id ) . '">Reset Order</button>';
			}
		}
	}

	/**
	 * This function is a filter for WC_Order_Query and is used to get orders that have not been mentioned in a pre transit email.
	 * It is used to get orders that have not been mentioned in a pre transit email and sends a pre transit email to the customer.
	 *
	 * @param array $query The query array.
	 * @param array $query_vars The query vars.
	 *
	 * @return array The modified query array.
	 */
	public function wdm_handle_custom_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['pre_transit_time'] ) ) {
			$query['meta_query'][] = array(
				'relation' => 'AND',
				array(
					'key'     => 'pre_transit_time',
					'value'   => $query_vars['pre_transit_time'],
					'compare' => '<=',
					'type'    => 'numeric',
				),
				array(
					'key'     => 'mentioned_in_pre_transit_email',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => 'wdm_shippo_outbound_tracking_status',
					'value'   => 'PRE_TRANSIT',
					'compare' => '=',
				),
			);
		}

		return $query;
	}

	/**
	 * Checks if all the products in the cart have the 'Returnable' shipping class.
	 *
	 * @return bool True if all products have the 'Returnable' shipping class, false otherwise.
	 */
	protected function wdm_check_shipping_class() {
		// Check if all the products in the cart has the 'Returnable' shipping class.
		$all_products_have_soda_machine_shipping_class = true;

		$cart_items = WC()->cart->get_cart();

		if ( ! is_array( $cart_items ) || empty( $cart_items ) ) {
			return false;
		}

		$eligible_shipping_classes = get_option( 'wdm_eligible_shipping_classes', array() );
		foreach ( $cart_items as $cart_item ) {
			if ( ! in_array( $cart_item['data']->get_shipping_class(), $eligible_shipping_classes ) ) {
				$all_products_have_soda_machine_shipping_class = false;
				break;
			}
		}

		return $all_products_have_soda_machine_shipping_class;
	}
}
