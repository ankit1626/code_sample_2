<?php
/**
 * This file contains all the code related to Label Generation and Storing in the uploads/shipping directory along with automatic deletion and merging.
 *
 * @package custom-plugin
 */

/**
 * This file contains all the code related to WooCommerce Orders.
 */
class SS_Orders {

	/**
	 * Summary of instance
	 *
	 * @var SS_Orders $instance The instance of the class.
	 */
	private static $instance;

	/**
	 * The constructor.
	 */
	private function __construct() {
		add_action( 'wp_ajax_wdm_extend_return_period', array( $this, 'wdm_extend_return_period' ), 10 );
		add_action( 'wp_ajax_wdm_reset_order', array( $this, 'wdm_label_reset' ), 10 );
		add_action( 'woocommerce_payment_complete', array( $this, 'wdm_add_meta' ), 10, 1 );
		add_action( 'add_meta_boxes', array( $this, 'register_meta_box' ) );
		add_action( 'wp_ajax_wdm_clone_order', array( $this, 'wdm_clone_order' ) );
		add_action( 'woocommerce_order_details_after_order_table_items', array( $this, 'wdm_order_details_shipping_label_customized' ) );
		add_action( 'wp_ajax_send_email_with_pdf', array( $this, 'wdm_send_email_with_pdf' ) );
	}

	/**
	 * Gets the instance of the class.
	 *
	 * This method creates the instance of the class if it does not exist yet.
	 * If the instance already exists, it will be returned.
	 *
	 * @return SS_Orders The instance of the class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Registers the meta box
	 */
	public function register_meta_box() {
		add_meta_box(
			'wdm_ss_meta_box',
			__( 'SS Meta Box', 'code-sample' ),
			fn( $post ) => $this->output_meta_box( $post ),
			'shop_order',
			'normal',
			'high'
		);
	}

	/** This function add required meta to the orders.
	 *
	 * @param int $order_id Woocommerce order id.
	 */
	public function wdm_add_meta( $order_id ) {
		$order = wc_get_order( $order_id );

		$return_item_quantity = wdm_get_returnable_items_for_order( $order );

		$option = get_option( 'custom_plugin_options', array() );
		if ( isset( $option['wdm_return_fee'] ) && ! empty( $option['wdm_return_fee'] ) ) {
			$non_return_fee = intval( $option['wdm_return_fee'] ) * $return_item_quantity;
		} else {
			$non_return_fee = 2500 * $return_item_quantity;
		}
		$stripe_api_settings    = get_option( 'woocommerce_stripe_settings', array() );
		$is_stripe_in_test_mode = isset( $stripe_api_settings['testmode'] ) ? $stripe_api_settings['testmode'] : '';
		$order->update_meta_data( 'wdm_is_test_mode', $is_stripe_in_test_mode, true );
		$order->update_meta_data( 'wdm_returnable_item', $return_item_quantity );
		$order->update_meta_data( 'wdm_non_return_fee_when_order_placed', $non_return_fee );

		$order->save();
	}

	/**
	 * Extends the return period for a given order.
	 *
	 * This function extends the return period for a given order by the time specified in the settings.
	 * It will also reschedule the mail action if it is already scheduled.
	 *
	 * @since 1.0.0
	 *
	 * @throws Exception If the mail action not found or it is already in process.
	 * @throws Exception If unable to extend the time for scheduled mail.
	 * @throws Exception If unable to unschedule the previous non-return mail.
	 *
	 * @return void
	 */
	public function wdm_extend_return_period() {
		check_ajax_referer( 'secret', 'nonce' );
		$exception              = false;
		$non_critical_exception = array(
			'The mail action not found or it is already in process',
			'Unable to extend the time for scheduled mail',
			'Unable to unschedule the previous non-return mail.',
		);
		if ( ! isset( $_POST['order_id'] ) || empty( $_POST['order_id'] ) ) {
			wp_send_json_error( 'Invalid request', 500 );
		}
		$order = wc_get_order( sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			wp_send_json_error( 'Invalid request', 500 );
		}
		$order_id = $order->get_id();
		try {
			wdm_remove_scheduled_actions( $order_id, $order, true );
		} catch ( Exception  $e ) {
			if ( ! in_array( $e->getMessage(), $non_critical_exception, true ) ) {
				wp_send_json_error( $e->getMessage(), 500 );
			}
			$exception = $e;
		}
		$number = $order->get_meta( 'wdm_extend_returns' );
		$number = empty( $number ) ? 1 : intval( $number ) + 1;
		$order->update_meta_data( 'wdm_extend_returns', $number );
		$order->save();
		false === $exception ? wp_send_json_success( 'Extended', 200 ) : wp_send_json_error( 'The return period has been extended, but the mail action cannot be extended.', 500 );
	}

	/** The function wdm_label_reset will help the admin to dump the generated labels and generate new lables */
	public function wdm_label_reset() {
		check_ajax_referer( 'secret', 'nonce' );
		if ( ! isset( $_POST['order_id'] ) || empty( $_POST['order_id'] ) ) {
			return;
		}
		$order_id = sanitize_text_field( wp_unslash( $_POST['order_id'] ) );
		$order    = wc_get_order( $order_id );

		$refund_id     = $this->wdm_shippo_outbound_refunds( $order_id );
		$old_refund_id = get_post_meta( intval( $order_id ), 'wdm_shippo_outbound_refund_id', true );
		if ( ! empty( $old_refund_id ) && ! empty( $refund_id ) ) {
			$refund_id = $old_refund_id . ';' . $refund_id;
		}
		update_post_meta( intval( $order_id ), 'wdm_shippo_outbound_refund_id', $refund_id );
		if ( empty( $refund_id ) ) {
			$error = array(
				'errorCode' => 'Unable to raise refund request',
				'message'   => 'Please check error log.',
			);
			wp_send_json_error( $error, 500 );
		}
		$stripe_api_settings = get_option( 'woocommerce_stripe_settings', array() );
		if ( ! empty( $stripe_api_settings ) ) {
			$stripe_sec_key = $order->get_meta( 'wdm_is_test_mode' ) === 'no' ? $stripe_api_settings['secret_key'] : $stripe_api_settings['test_secret_key'];

			$arguments = array(
				'order_id'    => intval( $order_id ),
				'customer_id' => $order->get_meta( '_stripe_customer_id', true ),
				'secret_key'  => $stripe_sec_key,
			);
		}

		as_unschedule_action( 'wdm_charge_non_return_fee', $arguments );

		$arguments = array(
			'order_id' => intval( $order_id ),
		);

		as_unschedule_action( 'wdm_schedule_mail', $arguments );

		$path      = wp_get_upload_dir();
		$path      = $path['basedir'] . '/shipping';
		$filenames = array(
			$path . '/' . $order_id . ' outbound.pdf',
			$path . '/' . $order_id . ' inbound.pdf',
			$path . '/' . $order_id . '.pdf',
		);
		foreach ( $filenames as $index => $file ) {
			$arguments = array(
				'order' => intval( $order_id ),
				'file'  => $file,
			);
			if ( 2 === $index ) {
				$arguments['order'] = strval( $arguments['order'] );
			}
			as_unschedule_all_actions( 'wdm_delete_shipping_labels', $arguments );
		}

		if ( $order->has_status( array( 'shipped', 'processing' ) ) ) {
			$unnecessary_meta = array(
				'easypost_inbound_carrier',
				'easypost_shipment_id',
				'easypost_tracking_id',
				'inbound-shipping-label-generated',
				'merged-shipping-label-generated',
				'outbound-shipping-label-generated',
				'wdm_return_by',
				'wdm_return_by_ct',
				'wdm_shippo_outbound_tracking_number',
				'wdm_shippo_outbound_tracking_status',
				'wdm_shippo_outbound_tracking_url',
				'wdm_shippo_outbound_transaction_id',
				'wdm_shippo_outbound_carrier_token',
				'wdm_shippo_inbound_tracking_number',
				'wdm_shippo_inbound_tracking_status',
				'wdm_shippo_inbound_tracking_url',
				'wdm_shippo_inbound_transaction_id',
				'wdm_shippo_inbound_carrier_token',
				'pre_transit_time',
				'generating_return_label',
				'usps_tracking_id',
				'wdm_usps_inbound_tracking_status',
				'usps_tracking_url',
				'usps_routing_number',
				'wdm_order_converted',
			);
			foreach ( $unnecessary_meta as $single_unnecessary_meta ) {
				delete_post_meta( $order_id, $single_unnecessary_meta );
			}
			$order->update_status( 'processing' );

		}
	}

	/**
	 * Creates a woocommerce order
	 *
	 * @return void
	 */
	public function wdm_clone_order() {
		check_ajax_referer( 'secret', 'nonce' );
		$order_id = isset( $_POST['order_id'] ) ? sanitize_text_field( wp_unslash( $_POST['order_id'] ) ) : '';
		$prd_id   = isset( $_POST['product_id'] ) ? sanitize_text_field( wp_unslash( $_POST['product_id'] ) ) : '';
		$mac_id   = isset( $_POST['machine_type'] ) ? sanitize_text_field( wp_unslash( $_POST['machine_type'] ) ) : '';
		if ( empty( $prd_id ) || empty( $order_id ) || empty( $mac_id ) ) {
			wp_send_json_error( 'Please provide valid order id, machine type and product id ', 500 );
		}
		$prd                = wc_get_product( $prd_id );
		$og_order           = new WC_Order( $order_id );
		$shipping           = $og_order->get_address( 'shipping' );
		$billing            = $og_order->get_address( 'billing' );
		$customer_id        = $og_order->get_customer_id();
		$stripe_customer_id = $og_order->get_meta( '_stripe_customer_id', true );
		$non_return_fee     = $og_order->get_meta( 'wdm_non_return_fee', true );

		if ( empty( $shipping ) || empty( $billing ) ) {
			wp_send_json_error( 'Please make sure that order contains valid shipping and billing address', 500 );
		}
		$order_data = array(
			'created_via' => 'admin',
			'customer_id' => $customer_id,
		);

		$order = wc_create_order( $order_data );
		if ( is_wp_error( $order ) ) {
			wp_send_json_error( $order->get_error_message(), 500 );
		}
		$order->add_product( $prd );
		$order->set_total( 0 );
		$order->set_status( 'processing' );
		$order->set_address( $shipping, 'shipping' );
		$order->set_address( $billing, 'billing' );
		$order->add_meta_data( 'wdm_is_replacement_order', 'true', true );
		$order->add_meta_data( 'machine_type', $mac_id, true );
		$order->add_meta_data( '_billing_address_index', implode( ' ', $billing ), true );
		$order->add_meta_data( '_shipping_address_index', implode( ' ', $shipping ), true );
		$order->add_meta_data( '_stripe_customer_id', $stripe_customer_id, true );
		if ( ! empty( $non_return_fee ) ) {
			$order->add_meta_data( 'wdm_non_return_fee', $non_return_fee, true );
		}
		$order->save();
		wp_send_json_success( 'Order Generated', 201 );
	}

	/** This function will submit refund request to shippo.
	 *
	 *  @param int $order_id ID of order .
	 */
	private function wdm_shippo_outbound_refunds( $order_id ) {
		$order              = wc_get_order( $order_id );
		$order_id           = intval( $order_id );
		$transaction_number = get_post_meta( $order_id, 'wdm_shippo_outbound_transaction_id', true );
		$carrier_token      = get_post_meta( $order_id, 'wdm_shippo_outbound_carrier_token', true );
		$option             = get_option( 'custom_plugin_options' );
		$shippo_test_mode   = get_option( 'wdm_shippo_test_mode', 'Yes' );
		$token              = 'Yes' === $shippo_test_mode ? $option['wdm_shippo_test_auth_token'] ?? '' : $option['wdm_shippo_auth_token'] ?? '';
		if ( ! empty( $token ) ) {
			$token = 'ShippoToken ' . $token;
		}
		if ( empty( $transaction_number ) ) {
			$carrier_token      = empty( $carrier_token ) ? 'usps' : $carrier_token;
			$tracking_number    = get_post_meta( $order_id, 'wdm_shippo_outbound_tracking_number', true );
			$transaction_number = $this->wdm_get_transaction_number( $tracking_number, $carrier_token, $order, $token );
			if ( empty( $transaction_number ) ) {
				return;
			}
		}

		$url = 'https://api.goshippo.com/refunds/';

		$body              = new stdClass();
		$body->async       = false;
		$body->transaction = $transaction_number;

		$args = array(
			'headers' => array(
				'Authorization' => $token,
				'content-type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);

		$response      = wp_remote_post( $url, $args );
		$response_body = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $response_body );
		$refund_id     = $response_body->object_id;
		if ( empty( $refund_id ) ) {
			wdm_error_log( $response_body );
		}
		return $refund_id;
	}

	/** This function will retrieve the transaction number from tracking number.
	 *
	 *  @param string   $tracking_number Tracking number of the label.
	 *  @param string   $carrier_token name of the carrier.
	 *  @param WC_Order $order The order for which refund is raised.
	 *  @param string   $token token.
	 */
	private function wdm_get_transaction_number( $tracking_number, $carrier_token, $order, $token ) {
		if ( empty( $tracking_number ) ) {
			return;
		}

		$url = 'https://api.goshippo.com/tracks/';

		$body                  = new stdClass();
		$body->tracking_number = $order->get_meta( 'wdm_is_test_mode' ) === 'no' ? $tracking_number : 'SHIPPO_DELIVERED';
		$body->carrier         = $order->get_meta( 'wdm_is_test_mode' ) === 'no' ? $carrier_token : 'shippo';

		$args = array(
			'headers' => array(
				'Authorization' => $token,
				'content-type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		);

		$response           = wp_remote_post( $url, $args );
		$response_body      = wp_remote_retrieve_body( $response );
		$response_body      = json_decode( $response_body );
		$transaction_number = $response_body->transaction;
		return $transaction_number;
	}

	/**
	 * Outputs the meta box content
	 *
	 * @param WP_Post $post The current post object.
	 */
	private function output_meta_box( $post ) {
		$og_order        = new WC_Order( $post->ID );
		$og_status       = $og_order->get_status();
		$og_product      = $og_order->get_items();
		$og_machine_type = $og_order->get_meta( 'machine_type' );
		$og_prev_mt      = $og_order->get_meta( 'sodamachine' );
		if ( count( $og_product ) === 1 ) {
			$og_product_obj  = reset( $og_product );
			$og_variation_id = $og_product_obj->get_variation_id();
			$og_product_id   = $og_product_obj->get_product_id();

			// Building options for select box to select the replacement product.
			$options  = '';
			$products = wc_get_products(
				array(
					'limit'  => -1,
					'return' => 'ids',
				)
			);
			foreach ( $products as $product_id ) {
				$product      = wc_get_product( $product_id );
				$product_name = $product->get_formatted_name();
				if ( ! method_exists( $product, 'get_available_variations' ) ) {
					$options .= '<option value=' . $product_id . ' ' . selected( $product_id, $og_product_id, false ) . '>' . $product_name . '</option>';
				} else {
					$options .= $product_id === $og_product_id ? '<option value=' . $product_id . ' disabled style=background-color:green;color:white>' . $product_name . '</option>' : '<option value=' . $product_id . ' disabled>' . $product_name . '</option>';
				}
				if ( ! empty( $og_variation_id ) && method_exists( $product, 'get_available_variations' ) ) {
					$variations = $product->get_available_variations();
					foreach ( $variations as $variation ) {
						$variation_id   = $variation['variation_id'];
						$variation_name = implode( ', ', $variation['attributes'] );
						$ending_html    = $variation_id === $og_variation_id ? '(Selected)</option>' : '</option>';
						$options       .= '<option value=' . $variation_id . ' ' . selected( $variation_id, $og_variation_id, false ) . '>---' . $variation_name . $ending_html;
					}
				}
			}

			// Building options for select box to select the machine type.
			$machine_types = '';

			$terms = get_terms(
				array(
					'taxonomy'   => 'machine-type',
					'hide_empty' => false,
				)
			);
			foreach ( $terms as $term ) {
				$machine_types .= '<option value=' . $term->term_id . ' ' . selected( $term->term_id, $og_machine_type, false ) . '>' . $term->name . '</option>';
			}
		}
		?>
		<div class="wdm-ss-meta-box">
		<?php if ( is_array( $og_product ) && count( $og_product ) === 1 ) { ?>
			<button class="button-primary" id="wdm-regenerate-order" data-products="<?php echo esc_attr( $options ); ?>" data-machine-types="<?php echo esc_attr( $machine_types ); ?>" data-order-id="<?php echo esc_attr( $post->ID ); ?>" data-prev-mt="<?php echo esc_attr( $og_prev_mt ); ?>">Regenerate Order</button>
			<?php
		} if ( 'awaiting-returns' === $og_status && wdm_get_returnable_items_for_order( $og_order ) > 0 ) {
				$number = $og_order->get_meta( 'wdm_extend_returns' );
				$number = empty( $number ) ? 0 : intval( $number );
			?>
				<button class="button-primary" id="wdm-extend-return-period" data-order-id="<?php echo esc_attr( $post->ID ); ?>">Extend Return Period(<?php echo esc_attr( $number ); ?>)</button>
			<?php
		}
		?>
		<?php
		if ( in_array( $og_status, array( 'awaiting-returns', 'shipped' ), true ) && wdm_get_returnable_items_for_order( $og_order ) > 0 ) {
			?>
					<button class="button-primary" id="wdm-convert-to-no-exchange" data-order-id="<?php echo esc_attr( $post->ID ); ?>">Convert to No-Exchange</button>
				<?php
		}
		?>
			<?php if ( 'completed' === $og_status && wdm_get_returnable_items_for_order( $og_order ) > 0 && 'charged' !== $og_order->get_meta( 'wdm_partial_return_fee_charged', true ) && '' === $og_order->get_meta( 'wdm_charge_id', true ) ) { ?>
				<button class="button-primary" id="wdm-charge-partial-return-fee" data-order-id="<?php echo esc_attr( $post->ID ); ?>">Charge Partial Return Fee</button>
			<?php } ?>

		</div>
		<?php
	}

	/**
	 * Handles the AJAX request to send an email with the PDF file of the order.
	 *
	 * The PDF file should be located in the uploads directory under the path
	 * `/shipping/<order_id> inbound.pdf`.
	 *
	 * @since 1.0.0
	 */
	public function wdm_send_email_with_pdf() {
		check_ajax_referer( 'secret', 'nonce' );
		if ( ! isset( $_POST['order_id'] ) ) {
			wp_send_json_error( __( 'Invalid Order ID', 'code-sample' ), 500 );
		}
		$order_id   = intval( $_POST['order_id'] );
		$order      = wc_get_order( $order_id );
		$user_email = $order->get_billing_email();
		$upload_dir = wp_upload_dir();
		$pdf_path   = $upload_dir['basedir'] . '/shipping/' . $order_id . ' inbound.pdf';
		$option     = get_option( 'custom_plugin_options', array() );
		if ( file_exists( $pdf_path ) ) {
			$subject     = $option['wdm_return_label_via_email_subject'];
			$message     = wdm_replace_placeholders( $option['wdm_return_label_via_email_body'], $order );
			$headers     = array( 'Content-Type: text/html; charset=UTF-8' );
			$attachments = array( $pdf_path );
			wp_mail( $user_email, $subject, $message, $headers, $attachments );
			wp_send_json_success( __( 'Email sent successfully', 'code-sample' ), 200 );
		}
		wp_send_json_error( __( 'Order Label not found', 'code-sample' ), 404 );
	}


	/**
	 * Checks if the order is awaiting returns and has the inbound shipping label generated, and shows a button to send an email with the PDF file of the order.
	 *
	 * @param \WC_Order $order The WC_Order object.
	 *
	 * @since 1.0.0
	 */
	public function wdm_order_details_shipping_label_customized( $order ) {
		$order_id     = $order->get_id();
		$order_status = $order->get_status();
		$upload_dir   = wp_upload_dir();
		$file_path    = $upload_dir['basedir'] . '/shipping/' . $order_id . ' inbound.pdf';
		if ( ! file_exists( $file_path ) ) {
			return;
		}

		if ( 'awaiting-returns' === $order_status && '1' === get_post_meta( $order_id, 'inbound-shipping-label-generated', true ) ) {
			?>

			<label for="send_email_button">
			Get the Shipping Label by clicking here : 
			<button id="send_email_button" data-order-id="<?php echo esc_attr( $order_id ); ?>">Shipping Label</button>
			</label>

			<?php
		}
	}
}
