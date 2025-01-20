<?php
/**
 * This file contains all the code related to Label Generation and Storing in the uploads/shipping directory along with automatic deletion and merging.
 *
 * @package custom-plugin
 */

/**
 * This file contains all the code related to WooCommerce Orders.
 */
class SS_Additional_Fees {
	/**
	 * Summary of instance
	 *
	 * @var SS_Additional_Fees
	 */
	public static $instance;

	/**
	 * The constructor.
	 *
	 * This method is private and should not be called directly. It is used to add
	 * all the required actions and filters.
	 */
	private function __construct() {
		add_action( 'wp_ajax_wdm_charge_partial_return_fee', array( $this, 'wdm_charge_partial_return_fee' ), 10 );
		add_action( 'wdm_schedule_mail', array( $this, 'wdm_schedule_mail' ), 10, 1 );
		add_action( 'wdm_charge_non_return_fee', array( $this, 'wdm_add_charges_on_customers_card' ), 10, 3 );
		add_action( 'woocommerce_order_refunded', array( $this, 'stripe_refunds' ), 10, 2 );
	}

	/**
	 * Gets the instance of the class.
	 *
	 * This method creates the instance of the class if it does not exist yet.
	 * If the instance already exists, it will be returned.
	 *
	 * @return SS_Additional_Fees The instance of the class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/** This function sends reminder mail to the customer to return items.
	 *
	 * @param int $order_id Woocommerce order_id.
	 */
	public function wdm_schedule_mail( $order_id ) {
		if ( empty( $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		if ( $order->has_status( 'completed' ) ) {
			return;
		}
		$state = wdm_get_inbound_tracking_status( $order );
		if ( 'easypost' === wdm_get_inbound_tracking_partner( $order ) ) {
			$state = apply_filters( 'wdm_internal_easypost_state', strval( $state ), intval( $order_id ) );
		}
		if ( 'usps' === wdm_get_inbound_tracking_partner( $order ) ) {
			$state = apply_filters( 'wdm_internal_usps_state', strval( $state ), intval( $order_id ) );
		}
		if ( ! empty( $state ) && 'unknown' !== $state && 'pre_transit' !== $state && 'UNKNOWN' !== $state && 'PRE_TRANSIT' !== $state ) {
			return;
		}
		$option  = get_option( 'custom_plugin_options', array() );
		$subject = $option['wdm_email_subject'];
		$body    = wdm_replace_placeholders( $option['wdm_email_body'], $order );

		$customer_email = $order->get_billing_email();
		wp_mail( $customer_email, $subject, $body );
	}

	/**
	 * This function is responsible for adding charges on customers saved card.
	 *
	 * @param int    $order_id Woocommerce order id.
	 * @param string $customer_id stripe customer_id.
	 * @param string $secret_key stripe secret key.
	 *  https://stripe.com/docs/api/payment_intents/create.
	 */
	public function wdm_add_charges_on_customers_card( $order_id, $customer_id, $secret_key ) {
		if ( empty( $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		if ( $order->has_status( 'completed' ) ) {
			return;
		}
		$state = wdm_get_inbound_tracking_status( $order );
		if ( 'easypost' === wdm_get_inbound_tracking_partner( $order ) ) {
			$state = apply_filters( 'wdm_internal_easypost_state', strval( $state ), intval( $order_id ) );
		} elseif ( 'usps' === wdm_get_inbound_tracking_partner( $order ) ) {
			$state = apply_filters( 'wdm_internal_usps_state', strval( $state ), intval( $order_id ) );
		}
		if ( ! empty( $state ) && 'unknown' !== $state && 'pre_transit' !== $state && 'UNKNOWN' !== $state && 'PRE_TRANSIT' !== $state ) {
			return;
		}
		$option        = get_option( 'custom_plugin_options', array() );
		$amount        = $order->get_meta( 'wdm_non_return_fee_when_order_placed' );
		$amount_in_usd = intval( $amount ) / 100;
		$cust_id       = $order->get_customer_id();
		if ( empty( $cust_id ) ) {
			$msg = 'Guest Orders cannot be charged with non return-fee';
			$order->add_order_note( $msg, 0 );
			return;
		}
		$default_token = WC_Payment_Tokens::get_customer_default_token( $cust_id );
		if ( empty( $default_token ) ) {
			$msg = 'No default payment method present for the customer at WooCommerce end.';
			$order->add_order_note( $msg, 0 );
			return;
		}
		$pm_updated = $this->sync_stripe_default( $default_token, $customer_id, $secret_key );
		if ( ! $pm_updated ) {
			$msg = 'Unable to sync the payment method with stripe.';
			$order->add_order_note( $msg, 0 );
			return;
		}
		$response = $this->wdm_add_charges_on_stripe( $secret_key, $amount, $customer_id, 'non-return fee for order ' . $order_id, $default_token->get_token() );

		$text = current_time( 'Y-m-d' ) . ' Order_id: ' . $order_id . PHP_EOL;
		$text = $text . print_r( $response['body'], true ) . PHP_EOL . PHP_EOL;  // @codingStandardsIgnoreLine
		wdm_error_log( $text, true );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_code() . ' ' . $response->get_error_message();
			$order->add_order_note( $msg, 0 );
			return;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( isset( $body->error ) ) {
			$msg = 'Failed to fetch the non-return fee' . $body->error->code . $body->error->message;
			$order->add_order_note( $msg, 0 );
			return;
		}
		if ( isset( $body->id ) && isset( $body->status ) && 'succeeded' === $body->status ) {
			$msg = 'Non return fee was charged on the clients default payment method with payment intent' . $body->id . ' and source ' . $body->source;
			$order->add_order_note( $msg, 0 );
			$order->add_meta_data( 'wdm_charge_id', $body->id );
			$order->save();
			if ( ! empty( $order->get_meta( 'wdm_non_return_fee_when_order_placed' ) ) && $this->wdm_add_non_taxable_additional_fees( $amount_in_usd, $order, 'Non return fee' ) ) {
				$order->update_status( 'completed' );
				$to      = $order->get_billing_email();
				$subject = $option['wdm_return_fee_charged_subject'];
				$message = wdm_replace_placeholders( $option['wdm_return_fee_charged_body'], $order );
				wp_mail( $to, $subject, $message );
			}
			return;
		}
		$msg = 'Failed to charge the Non return fee with payment intent ' . $body->id;
		$order->add_order_note( $msg, 0 );
	}

	/**
	 * Charge the customer with the partial non-return fee.
	 *
	 * This method is called via an AJAX call. It charges the customer with the partial non-return fee.
	 * If the order is converted to No-Exchange it will also refund the shipping label.
	 *
	 * @since 1.0.0
	 *
	 * @throws Exception If unable to obtain customer id from stripe.
	 * @throws Exception If unable to sync the payment method with stripe.
	 * @throws Exception If unable to request the refund for the easypost label.
	 * @throws Exception If unable to extend the time for scheduled mail.
	 * @throws Exception If unable to unschedule the previous non-return mail.
	 * @throws Exception If unable to fetch the non-return fee.
	 *
	 * @return void
	 */
	public function wdm_charge_partial_return_fee() {
		check_ajax_referer( 'secret', 'nonce' );
		if ( ! isset( $_POST['order_id'] ) || empty( $_POST['order_id'] ) || ! isset( $_POST['sub_action'] ) || empty( $_POST['sub_action'] ) ) {
			wp_send_json_error( 'Order ID or Sub-action not found', 500 );
		}
		$order_id = sanitize_text_field( wp_unslash( $_POST['order_id'] ) );
		$amt_key  = 'charge' === $_POST['sub_action'] ? 'wdm_partial_return_fee' : 'wdm_exchange_to_no_exchange_conversion_fee';
		$options  = get_option( 'custom_plugin_options' );
		if ( empty( $options ) || empty( $options[ $amt_key ] ) ) {
			wp_send_json_error( 'Please set the fee value in settings', 500 );
		}
		$amount        = $options[ $amt_key ];
		$amount_in_usd = $amount / 100;
		$order         = wc_get_order( $order_id );
		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			wp_send_json_error( 'Invalid order object', 500 );
		}

		$stripe_api_settings = get_option( 'woocommerce_stripe_settings', array() );
		if ( empty( $stripe_api_settings ) || empty( $stripe_api_settings['secret_key'] ) || empty( $stripe_api_settings['test_secret_key'] ) ) {
			wp_send_json_error( 'Stripe is not enabled', 500 );
		}

		$stripe_sec_key = $order->get_meta( 'wdm_is_test_mode' ) === 'no' ? $stripe_api_settings['secret_key'] : $stripe_api_settings['test_secret_key'];
		$cust_id        = $order->get_customer_id();

		if ( empty( $cust_id ) ) {
			$msg = 'Guest Orders cannot be charged with non return-fee';
			wp_send_json_error( $msg, 500 );
		}
		$customer_id = $order->get_meta( '_stripe_customer_id', true );
		if ( empty( $customer_id ) ) {
			$msg = 'Unable to obtain customer id from stripe';
			wp_send_json_error( $msg, 500 );
		}
		$default_token = WC_Payment_Tokens::get_customer_default_token( $cust_id );
		if ( empty( $default_token ) ) {
			$msg = 'No default payment method present for the customer at WooCommerce end.';
			wp_send_json_error( $msg, 500 );
		}
		$pm_updated = $this->sync_stripe_default( $default_token, $customer_id, $stripe_sec_key );
		if ( ! $pm_updated ) {
			$msg = 'Unable to sync the payment method with stripe.';
			wp_send_json_error( $msg, 500 );
		}

		$exception   = false;
		$description = 'charge' === $_POST['sub_action'] ? 'Partial non-return fee for order ' . $order_id : 'Conversion fee for order ' . $order_id;
		if ( 'convert' === $_POST['sub_action'] ) {
			$non_critical_exception = array(
				'The mail action not found or it is already in process',
				'Unable to extend the time for scheduled mail',
				'Unable to unschedule the previous non-return mail.',
			);
			if ( 'shipped' === $order->get_status() ) {
				$non_critical_exception[] = 'Unable to unschedule the previous non-return fee.';
			}
			try {
				if ( wdm_get_inbound_tracking_partner( $order ) === 'easypost' && $this->wdm_easypost_refunds( $order_id ) !== true ) {
					wp_send_json_error( 'Unable to request the refund for the easypost label', 500 );
				}
				wdm_remove_scheduled_actions( $order_id, $order, false );
			} catch ( Exception  $e ) {
				if ( ! in_array( $e->getMessage(), $non_critical_exception, true ) ) {
					wp_send_json_error( $e->getMessage(), 500 );
				}
				$exception = $e;
			}
		}
		$response = $this->wdm_add_charges_on_stripe( $stripe_sec_key, $amount, $customer_id, $description, $default_token->get_token() );

		if ( is_wp_error( $response ) ) {
			$msg = $response->get_error_code() . ' ' . $response->get_error_message();
			wp_send_json_error( $msg, 500 );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( isset( $body->error ) ) {
			$msg = 'Failed to fetch the non-return fee' . $body->error->code . $body->error->message;
			$order->add_order_note( $msg, 0 );
			wp_send_json_error( $msg, 500 );
		}
		if ( isset( $body->id ) && isset( $body->status ) && 'succeeded' === $body->status ) {
			$option   = get_option( 'custom_plugin_options', array() );
			$fee_type = 'charge' === $_POST['sub_action'] ? 'Partial Non return fee' : 'Conversion fee';
			$msg      = $fee_type . 'was charged on the clients default payment method with payment intent' . $body->id . ' and source ' . $body->source;
			$order->add_order_note( $msg, 0 );
			if ( ! empty( $order->get_meta( 'wdm_non_return_fee_when_order_placed' ) ) && $this->wdm_add_non_taxable_additional_fees( $amount_in_usd, $order, $fee_type ) ) {
				$to = $order->get_billing_email();
				if ( 'charge' === $_POST['sub_action'] ) {
					$subject = $option['wdm_partial_return_fee_charged_subject'];
					$message = wdm_replace_placeholders( $option['wdm_partial_return_fee_charged_body'], $order );
					$order->update_meta_data( 'wdm_partial_return_fee_charged', 'charged', true );
					$order->add_meta_data( 'wdm_charge_id', $body->id );
					$order->save();
				} else {
					$subject = $option['wdm_order_converted_from_exchange_to_no_exchange_subject'];
					$message = wdm_replace_placeholders( $option['wdm_order_converted_from_exchange_to_no_exchange_body'], $order );
					$order->update_meta_data( 'wdm_order_converted', 'converted', true );
					if ( 'DELIVERED' === $order->get_meta( 'wdm_shippo_outbound_tracking_status' ) ) {
						$order->update_status( 'completed' );
					}
					$order->save();
				}
				wp_mail( $to, $subject, $message );
			}
			false === $exception ? wp_send_json_success( 'Charged', 200 ) : wp_send_json_error( 'The order was converted but unable to unschedule the previous email.', 500 );
		}
		$msg = 'Failed to charge the' . $fee_type . ' with payment intent ' . $body->id;
		$order->add_order_note( $msg, 0 );
		wp_send_json_error( $msg, 500 );
	}

	/**
	 * Synchronizes the default payment method in stripe customer.
	 *
	 * @param WC_Payment_Token $default_token The default payment token.
	 * @param string           $customer_id   The stripe customer id.
	 * @param string           $secret_key    The stripe secret key.
	 *
	 * @return bool True on success, false on failure.
	 */
	private function sync_stripe_default( $default_token, $customer_id, $secret_key ) {
		$data     = array(
			'invoice_settings' => array(
				'default_payment_method' => $default_token->get_token(),
			),
		);
		$url      = 'https://api.stripe.com/v1/customers/' . $customer_id;
		$args     = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret_key,
				'Content-Type'  => 'application/x-www-form-urlencoded; charset=utf-8',
			),
			'body'    => http_build_query( $data ),
		);
		$response = wp_remote_post( $url, $args );
		$body     = json_decode( wp_remote_retrieve_body( $response ) );
		if ( isset( $body->invoice_settings ) && $body->invoice_settings->default_payment_method === $default_token->get_token() ) {
			return true;
		}
		wdm_error_log( $response );
		return false;
	}

	/** This function submits refund request in easypost for unused labels.
	 *
	 *  @param int $order_id ID of the order.
	 */
	private function wdm_easypost_refunds( $order_id ) {
		if ( empty( $order_id ) ) {
			return;
		}
		$option             = get_option( 'custom_plugin_options' );
		$easypost_test_mode = get_option( 'wdm_easypost_test_mode', 'Yes' );
		if ( 'Yes' === $easypost_test_mode ) {
			return true;
		}
		$token   = 'Yes' === $easypost_test_mode ? $option['wdm_easypost_test_auth_token'] ?? '' : $option['wdm_easypost_auth_token'] ?? '';
		$ship_id = get_post_meta( $order_id, 'easypost_shipment_id', true );
		if ( empty( $ship_id ) ) {
			return;
		}

		$url  = 'https://api.easypost.com/v2/shipments/' . $ship_id . '/refund';
		$args = array(
			'headers' => array(
		'Authorization' => 'Basic ' . base64_encode( $token . ':' ), // @codingStandardsIgnoreLine
				'content-type' => 'application/json',
			),
		);

		$response = wp_remote_post( $url, $args );
		if ( 200 !== $response['response']['code'] ) {
			return false;
		}
		$response_body = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $response_body );
		update_post_meta( $order_id, 'wdm_refund_status_inbound', $response_body->refund_status );
		update_post_meta( $order_id, 'wdm_refund_requested_on', time() );
		return true;
	}

	/**
	 * Makes a request to stripe to add a charge to the customer.
	 *
	 * @param string $secret_key Stripe secret key.
	 * @param int    $amount_in_cents Amount to be charged in cents.
	 * @param int    $stripe_customer_id Stripe customer ID.
	 * @param string $description Description for the charge.
	 * @param string $pm_token Stripe payment method token.
	 *
	 * @return array The response from the Stripe API.
	 */
	private function wdm_add_charges_on_stripe( $secret_key, $amount_in_cents, $stripe_customer_id, $description, $pm_token ) {
		$url      = 'https://api.stripe.com/v1/payment_intents';
		$args     = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $secret_key,
			),
			'body'    => array(
				'amount'         => $amount_in_cents,
				'currency'       => 'usd',
				'confirm'        => 'true',
				'customer'       => $stripe_customer_id,
				'description'    => $description,
				'off_session'    => 'true',
				'payment_method' => $pm_token,
			),
		);
		$response = wp_remote_post( $url, $args );
		return $response;
	}

	/**
	 * Adds a non-taxable fee to the order.
	 *
	 * @param float    $fee     The amount of the fee.
	 * @param WC_Order $order   The order to add the fee to.
	 * @param string   $fee_name The name of the fee.
	 *
	 * @return bool True if the fee was added, false otherwise.
	 */
	private function wdm_add_non_taxable_additional_fees( $fee, $order, $fee_name ) {
		if ( ! isset( $fee ) || ! is_a( $order, 'WC_Order' ) ) {
			return false;
		}
		try {
			$item = new WC_Order_Item_Fee();
			$item->set_name( $fee_name );
			$item->set_tax_status( 'none' );
			$item->set_amount( $fee );
			$item->set_total( $fee );
			$order->add_item( $item );
			$order->calculate_totals();
			$order->save();
		} catch ( Exception $e ) {
			return false;
		}
		return true;
	}

	/**
	 * This function replaces the placeholders in the subject.
	 *
	 * @param string   $subject Content containing terms which should be replaced.
	 * @param WC_Order $order Contains values with which the terms should be replaced.
	 */
	private function replace_placeholders( $subject, $order ) {
		$search_keys = array( '%customer_first_name%', '%customer_last_name%', '%customer_email%', '%customer_order_id%' );

		$link = '<a href="' . $order->get_view_order_url() . '">' . $order->get_id() . '</a>';

		$replacement_values = array( $order->get_billing_first_name(), $order->get_billing_last_name(), $order->get_billing_email(), $link );

		$subject = str_replace( $search_keys, $replacement_values, $subject );
		$subject = wpautop( $subject, true );
		return $subject;
	}

	/**
	 * It refunds the non-return fee.
	 *
	 * @param string $order_id The order ID.
	 * @param string $refund_id The refund ID.
	 *
	 * @return void
	 */
	public function stripe_refunds( $order_id, $refund_id ) {
		if ( ! isset( $_SESSION['refund_ret_fee'] ) || ! is_bool( $_SESSION['refund_ret_fee'] ) || ! boolval( $_SESSION['refund_ret_fee'] ) ) {
			return;
		}
		if ( empty( $order_id ) || ! is_int( $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( empty( $order ) || ! is_object( $order ) ) {
			return;
		}
		$charge_id = $order->get_meta( 'wdm_charge_id' );
		if ( empty( $charge_id ) ) {
			return;
		}
		$stripe_api_settings = get_option( 'woocommerce_stripe_settings', array() );
		if ( empty( $stripe_api_settings ) || empty( $stripe_api_settings['secret_key'] ) || empty( $stripe_api_settings['test_secret_key'] ) ) {
			wp_send_json_error( 'Stripe is not enabled', 500 );
		}

		$stripe_sec_key = $order->get_meta( 'wdm_is_test_mode' ) === 'no' ? $stripe_api_settings['secret_key'] : $stripe_api_settings['test_secret_key'];
		$url            = 'https://api.stripe.com/v1/refunds';
		$args           = array(
			'headers' => array(
				'Authorization' => 'Bearer ' . $stripe_sec_key,
			),
			'body'    => array(
				'payment_intent' => $charge_id,
			),
		);
		$response       = wp_remote_post( $url, $args );
		$response       = json_decode( wp_remote_retrieve_body( $response ) );
		if ( isset( $response->status ) && 'succeeded' === $response->status ) {
			$order->add_meta_data( 'wdm_non_ret_fee_refund_id', $response->id );
			$order->save();
		}
	}
}
