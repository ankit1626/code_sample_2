<?php
/**
 * This file contains all the code related to Easypost.
 *
 * @package shipping-partners
 */

/**
 * This file contains all the code related to Easypost.
 */
trait WDM_Easypost {

	/**
	 * Base url for Easypost API.
	 *
	 * @var string
	 */
	private string $easypost_api_url;

	/**
	 * Easypost authentication token.
	 *
	 * @var string
	 */
	private string $easypost_auth_token;

	/**
	 * Easypost inbound carrier account id.
	 *
	 * @var string
	 */
	private string $easypost_inbound_carrier_account;

	/**
	 * Easypost inbound service level.
	 *
	 * @var string
	 */
	private string $easypost_inbound_service_level;

	/**
	 * Easypost label size.
	 *
	 * @var string
	 */
	private string $ep_label_size;

	/**
	 * Easypost label format.
	 *
	 * @var string
	 */
	private string $ep_label_format;

	/**
	 * Easypost_fedex_ca_account
	 *
	 * @var string
	 */
	private string $easypost_fedex_ca_account;

	/**
	 * Easypost_fedex_service_level
	 *
	 * @var string
	 */
	private string $easypost_fedex_service_level;

	/**
	 * Summary of easypost_test_mode
	 *
	 * @var string
	 */
	protected string $easypost_test_mode;
	/**
	 * Initializes the Easypost API endpoint URL, authentication token, and inbound carrier account and service level.
	 *
	 * @return void
	 */
	protected function initialize_easypost() {
		$this->easypost_api_url                 = 'https://api.easypost.com/v2/';
		$this->easypost_test_mode               = get_option( 'wdm_easypost_test_mode', 'Yes' );
		$this->easypost_auth_token              = 'Yes' === $this->easypost_test_mode ? $this->ss_options['wdm_easypost_test_auth_token'] ?? '' : $this->ss_options['wdm_easypost_auth_token'] ?? '';
		$this->easypost_inbound_carrier_account = $this->ss_options['wdm_easypost_inbound_carrier_account'] ?? '';
		$this->easypost_inbound_service_level   = $this->ss_options['wdm_easypost_inbound_service_level'] ?? '';
		$this->easypost_fedex_ca_account        = $this->ss_options['wdm_ep_inbound_fedex_carrier_account'] ?? '';
		$this->easypost_fedex_service_level     = $this->ss_options['wdm_ep_inbound_fedex_service_level'] ?? '';
		$this->ep_label_size                    = get_option( 'wdm_easypost_pdf_size', '4X6' );
		$this->ep_label_format                  = 'PDF';
	}

	/**
	 * This function generates a return shipping label using Easypost.
	 *
	 * @param WC_Order $order The order object.
	 *
	 * @return void
	 * @throws Exception When failed to create shipment using easypost.
	 */
	protected function generate_return_label_easypost( $order ) {
		$shipment_id = $this->wdm_create_shipment_easypost( $order );
		if ( true !== $shipment_id ) {
			$error = array(
				'errorCode' => 'Unable to create shipment using easypost',
				'message'   => 'Kindly check the error logs.',
			);
			if ( ! wp_doing_ajax() ) {
				throw new Exception( 'Unable to create shipment using easypost' );
			}
			wp_send_json_error( $error, 500 );
		}
	}

	/**
	 * This function sends a post request on /shipments endpoint to create a new shipment and then selects the desired rate and generates a shipping label.
	 *
	 * @param WC_Order $order The order object.
	 *
	 * @return bool
	 * @throws Exception When failed to create shipment using easypost.
	 */
	private function wdm_create_shipment_easypost( $order ) {
		$shipping_body = array(
			'shipment' => (object) array(
				'from_address' => $this->ep_set_address_from(),
				'to_address'   => $this->ep_set_address_to( $order ),
				'parcel'       => (object) array(
					'height' => $this->parcel_height,
					'length' => $this->parcel_length,
					'width'  => $this->parcel_width,
					'weight' => $this->parcel_weight_in_return,
				),
				'is_return'    => true,
				'options'      => (object) array(
					'label_size'     => $this->ep_label_size,
					'label_format'   => $this->ep_label_format,
					'print_custom_1' => 'Order Number: ' . $order->get_id(),
					'print_custom_2' => 'Product Number: ' . array_values( $order->get_items() )[0]->get_product_id(),
				),
			),
		);

		$body     = wp_json_encode( $shipping_body );
		$url      = $this->easypost_api_url . 'shipments';
		$args     = array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->easypost_auth_token . ':' ), // @codingStandardsIgnoreLine
				'content-type'  => 'application/json',
			),
			'body'    => $body,
		);
		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) || empty( $response ) || 201 !== wp_remote_retrieve_response_code( $response ) ) {
			wdm_error_log( $response );
			return false;
		}
		$rate_id       = '';
		$response_body = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $response_body );
		$rates         = $response_body->rates;
		$ca_ac         = 'fedex' === $order->get_meta( 'return_partner' ) ? $this->easypost_fedex_ca_account : $this->easypost_inbound_carrier_account;
		$sr_lv         = 'fedex' === $order->get_meta( 'return_partner' ) ? $this->easypost_fedex_service_level : $this->easypost_inbound_service_level;

		foreach ( $rates as $rate ) {
			if ( $ca_ac === $rate->carrier_account_id && $sr_lv === $rate->service ) {
				$rate_id = $rate->id;
				break;
			}
		}

		if ( empty( $rate_id ) ) {
			$error = array(
				'errorCode' => 'Unable to get the desired rate',
				'message'   => 'Kindly confirm the settings for inbound carriers and service_levels',
			);
			if ( ! wp_doing_ajax() ) {
				throw new Exception( 'Unable to get the desired rate' . print_r( $rates, true ) . '\nSelected Rate' . print_r( $this->easypost_inbound_carrier_account . ' - ' . $this->easypost_inbound_service_level, true ) ); // @codingStandardsIgnoreLine
			}
			wp_send_json_error( $error, 500 );
		}
		$newbody = (object) array(
			'rate' => array(
				'id' => $rate_id,
			),
		);
		$newbody = wp_json_encode( $newbody );
		$ship_id = $response_body->id;

		$url      = $url . '/' . $ship_id . '/buy';
		$args     = array(
			'timeout' => 45,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->easypost_auth_token . ':' ), // @codingStandardsIgnoreLine
				'content-type'  => 'application/json',
			),
			'body'    => $newbody,
		);
		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) || empty( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wdm_error_log( $response );
			return false;
		}
		$response_body = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $response_body );
		update_post_meta( $order->get_id(), 'easypost_tracking_id', $response_body->tracker->id );
		update_post_meta( $order->get_id(), 'easypost_inbound_carrier', $response_body->selected_rate->carrier );
		update_post_meta( $order->get_id(), 'easypost_shipment_id', $ship_id );
		$this->wdm_store_generated_label( $response_body->postage_label->label_url, $order->get_id() );
		return true;
	}

	/**
	 * This is a setter function for from_address property.
	 */
	private function ep_set_address_from() {
		$obj = new stdClass();
		if ( ! function_exists( 'WC' ) ) {
			return $obj;
		}
		$obj->city    = WC()->countries->get_base_city();
		$obj->country = WC()->countries->get_base_country();
		$obj->email   = $this->ss_options['wdm_shippo_sender_email'];
		$obj->name    = $this->ss_options['wdm_shippo_company_name'];
		$obj->state   = WC()->countries->get_base_state();
		$obj->street1 = WC()->countries->get_base_address();
		$obj->street2 = WC()->countries->get_base_address_2();
		$obj->zip     = WC()->countries->get_base_postcode();
		$obj->phone   = $this->ss_options['wdm_shippo_sender_contact'];
		return $obj;
	}
	/**
	 * This is a setter function for to_address property.
	 *
	 * @param WC_Order $order Woocommerce order object.
	 */
	private function ep_set_address_to( $order ) {
		$obj          = new stdClass();
		$obj->city    = $order->get_shipping_city();
		$obj->country = $order->get_shipping_country();
		$obj->name    = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
		$obj->state   = $order->get_shipping_state();
		$obj->street1 = $order->get_shipping_address_1();
		$obj->street2 = $order->get_shipping_address_2();
		$obj->zip     = $order->get_shipping_postcode();
		$obj->phone   = $order->get_billing_phone();
		$obj->email   = $order->get_billing_email();
		return $obj;
	}

	/**
	 * Retrieve tracking info for an order using Easypost.
	 *
	 * @param string $state Inbound tracking state.
	 * @param int    $order_id The order ID.
	 *
	 * @return string The tracking state.
	 */
	protected function easypost_api_tracking( string $state, int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || empty( $order_id ) || ! is_int( $order_id ) ) {
			return $state;
		}
		$order = wc_get_order( $order_id );
		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) || empty( $order->get_meta( 'easypost_tracking_id' ) ) ) {
			return $state;
		}
		$tracking_number = $order->get_meta( 'easypost_tracking_id' );
		$url             = $this->easypost_api_url . '/trackers/' . $tracking_number;
		$response        = wp_remote_get(
			$url,
			array(
				'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $this->easypost_auth_token . ':' ), // @codingStandardsIgnoreLine
					'content-type' => 'application/json',
				),
			)
		);
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $state;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()?->debug( wp_remote_retrieve_body( $response ) . PHP_EOL . PHP_EOL, array( 'source' => 'wdm_easypost_api_tracking_response' ) );
		}
		$m_body = (object) array(
			'result' => (object) array(
				'id'     => $body->id,
				'status' => $body->status,
			),
		);
		$this->wdm_add_tracking_info_to_orders_easypost( $m_body, true );
		return $body->status;
	}
}
