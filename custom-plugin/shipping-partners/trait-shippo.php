<?php
/**
 * This file contains all the code related to Shippo.
 *
 * @package custom-plugin.
 */

/**
 * This file contains all the code related to Shippo.
 */
trait WDM_Shippo {

	/**
	 * Base url for Shippo API.
	 *
	 * @var string
	 */
	private string $shippo_api_url;

	/**
	 * Shippo Auth token.
	 *
	 * @var string
	 */
	private string $shippo_auth_token;
	/**
	 * Summary of shippo_outbound_carrier_token
	 *
	 * @var string
	 */
	private string $shippo_outbound_carrier_token;
	/**
	 * Shippo Outbound carrier account.
	 *
	 * @var string
	 */
	private string $shippo_outbound_carrier_account;
	/**
	 * Shippo Inbound carrier account.
	 *
	 * @var string
	 */

	private string $shippo_inbound_carrier_account;
	/**
	 * Shippo Outbound service level.
	 *
	 * @var string
	 */
	private string $shippo_outbound_service_level;

	/**
	 * Shippo Inbound service level.
	 *
	 * @var string
	 */
	private string $shippo_inbound_service_level;

	/**
	 * Fedex base url.
	 *
	 * @var string
	 */
	private string $fedex_base_url;
	/**
	 * The Fedex Client ID.
	 *
	 * @var string
	 */
	private string $fedex_client_id;

	/**
	 * Summary of Fedex client secret.
	 *
	 * @var string
	 */
	private string $fedex_client_secret;

	/**
	 * Shippo test mode.
	 *
	 * @var string
	 */
	protected string $shippo_test_mode;

	/**
	 * Initializes the Shippo class.
	 *
	 * This function is responsible for setting the following Shippo properties:
	 * - API url
	 * - Auth token
	 * - Outbound carrier account
	 * - Inbound carrier account
	 * - Outbound service level
	 * - Inbound service level
	 *
	 * @since 1.0.0
	 */
	protected function initialize_shippo() {
		$this->shippo_api_url    = 'https://api.goshippo.com/';
		$this->shippo_test_mode  = get_option( 'wdm_shippo_test_mode', 'Yes' );
		$this->shippo_auth_token = 'Yes' === $this->shippo_test_mode ? $this->ss_options['wdm_shippo_test_auth_token'] ?? '' : $this->ss_options['wdm_shippo_auth_token'] ?? '';
		if ( ! empty( $this->shippo_auth_token ) ) {
			$this->shippo_auth_token = 'ShippoToken ' . $this->shippo_auth_token;
		}
		$this->shippo_outbound_carrier_account = $this->ss_options['wdm_shippo_outbound_carrier_account'] ?? '';
		$this->shippo_outbound_carrier_token   = $this->ss_options['wdm_shippo_outbound_carrier_token'] ?? '';
		$this->shippo_inbound_carrier_account  = $this->ss_options['wdm_shippo_inbound_carrier_account'] ?? '';
		$this->shippo_outbound_service_level   = $this->ss_options['wdm_shippo_outbound_service_level'] ?? '';
		$this->shippo_inbound_service_level    = $this->ss_options['wdm_shippo_inbound_service_level'] ?? '';
		$this->fedex_base_url                  = 'Yes' === $this->shippo_test_mode ? 'https://apis-sandbox.fedex.com/' : 'https://apis.fedex.com/';
		$this->fedex_client_id                 = 'Yes' === $this->shippo_test_mode ? $this->ss_options['wdm_fedex_test_client_id'] ?? '' : $this->ss_options['wdm_fedex_client_id'] ?? '';
		$this->fedex_client_secret             = 'Yes' === $this->shippo_test_mode ? $this->ss_options['wdm_fedex_test_client_secret'] ?? '' : $this->ss_options['wdm_fedex_client_secret'] ?? '';
	}

	/**
	 * Generate shipping label using Shippo.
	 *
	 * @param WC_Order $order The order object.
	 * @param stdClass $extra The extra information.
	 *
	 * @return void
	 * @throws Exception If unable to generate shipping label.
	 */
	protected function wdm_generate_shipping_label_shippo( $order, $extra = null ) {
		if ( null === $extra ) {
			$carrier_acc   = $this->shippo_outbound_carrier_account;
			$service_level = $this->shippo_outbound_service_level;
		} else {
			$carrier_acc   = $this->shippo_inbound_carrier_account;
			$service_level = $this->shippo_inbound_service_level;
		}

		$shipment_id = $this->wdm_create_shipment_shippo( $order, $extra );

		if ( empty( $shipment_id ) ) {
			$error = array(
				'errorCode' => 'Unable to create shipment',
				'message'   => 'Kindly check the error logs.',
			);
			if ( ! wp_doing_ajax() ) {
				throw new Exception( 'Unable to create shipment' );
			}
			wp_send_json_error( $error, 500 );
		}
		sleep( 2 );
		$rate_id = $this->get_rates( $shipment_id, $carrier_acc, $service_level );

		if ( empty( $rate_id ) ) {
			if ( null === $extra ) {
				$error = array(
					'errorCode' => 'Unable to get the desired rate',
					'message'   => 'Kindly confirm the settings for outbound carriers and service_levels',
				);
			} else {
				$error = array(
					'errorCode' => 'Unable to get the desired rate',
					'message'   => 'Kindly confirm the settings for inbound carriers and service_levels',
				);
			}
			if ( ! wp_doing_ajax() ) {
				throw new Exception( esc_html( $error['errorCode'] ) );
			}
			wp_send_json_error( $error, 500 );
		}

		$body = wp_json_encode(
			array(
				'async' => false,
				'rate'  => $rate_id,
			)
		);

		$url      = $this->shippo_api_url . 'transactions';
		$args     = array(
			'timeout' => 900,
			'headers' => array(
				'Authorization' => $this->shippo_auth_token,
				'content-type'  => 'application/json',
			),
			'body'    => $body,
		);
		$response = wp_remote_post( $url, $args );
		if ( empty( $response ) ) {
			return;
		}
		$response_body = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $response_body );

		if ( empty( $response_body->status ) || 'SUCCESS' !== $response_body->status || empty( $response_body->label_url ) ) {
			wdm_error_log( $response );
			$error = array(
				'errorCode' => 'Unable to create the shipping label',
				'message'   => 'Kindly check the error log',
			);
			if ( ! wp_doing_ajax() ) {
				throw new Exception( esc_html( $error['errorCode'] ) );
			}
			wp_send_json_error( $error, 500 );
		}

		if ( null === $extra ) {
			update_post_meta( $order->ID, 'wdm_shippo_outbound_tracking_url', $response_body->tracking_url_provider );
			update_post_meta( $order->ID, 'wdm_shippo_outbound_tracking_number', $response_body->tracking_number );
			update_post_meta( $order->ID, 'wdm_shippo_outbound_tracking_status', $response_body->tracking_status );
			update_post_meta( $order->ID, 'wdm_shippo_outbound_transaction_id', $response_body->object_id );
			update_post_meta( $order->ID, 'wdm_shippo_outbound_carrier_token', $this->shippo_outbound_carrier_token );
			add_post_meta( $order->ID, 'pre_transit_time', time(), true );
			as_schedule_single_action( time() + 86400 * 7, 'wdm_check_tracking_updates', array( $order->ID ) );
			as_schedule_single_action( time() + 86400 * 15, 'wdm_delivery_failed_notification', array( $order->ID ) );
			$this->wdm_store_generated_label( $response_body->label_url, $order->ID, 'outbound' );
		} else {
			update_post_meta( $order->ID, 'wdm_shippo_inbound_tracking_url', $response_body->tracking_url_provider );
			update_post_meta( $order->ID, 'wdm_shippo_inbound_tracking_number', $response_body->tracking_number );
			update_post_meta( $order->ID, 'wdm_shippo_inbound_tracking_status', $response_body->tracking_status );
			update_post_meta( $order->ID, 'wdm_shippo_inbound_transaction_id', $response_body->object_id );
			$this->wdm_store_generated_label( $response_body->label_url, $order->ID );
		}
		if ( ( 'Shippo' === $this->shipping_partner || 'shippo' === $order->get_meta( 'return_partner' ) ) && intval( $order->get_meta( 'wdm_returnable_item' ) ) > 0 && empty( get_post_meta( $order->ID, 'generating_return_label', true ) ) && 'fedex' !== $order->get_meta( 'return_partner' ) && 'usps' !== $order->get_meta( 'return_partner' ) ) {
			$obj            = new stdClass();
			$obj->is_return = true;
			add_post_meta( $order->ID, 'generating_return_label', 'generating' );
			$this->wdm_generate_shipping_label_shippo( $order, $obj );
		}
	}

	/**
	 * Creates a shipment on the Shippo server.
	 *
	 * @param WC_Order $order The order object.
	 * @param string   $extra The extra information.
	 *
	 * @return string|void The shipment object ID.
	 */
	private function wdm_create_shipment_shippo( $order, $extra = '' ) {
		$shipping_body = array(
			'shipment_date' => gmdate( 'Y-m-d\TH:i:s.u\Z', time() + 18000 ),
			'extra'         => $this->set_extras( $order, $extra ),
			'address_from'  => $this->shippo_set_address_from(),
			'address_to'    => $this->shippo_set_address_to( $order ),
			'parcels'       => (object) array(
				'distance_unit' => 'in',
				'mass_unit'     => 'oz',
				'height'        => $this->parcel_height,
				'width'         => $this->parcel_width,
				'length'        => $this->parcel_length,
				'weight'        => ( ! isset( $shipping_body['extra']->is_return ) ) ? $this->parcel_weight : $this->parcel_weight_in_return,
			),
			'async'         => false,
		);

		$body     = wp_json_encode( (object) $shipping_body );
		$url      = $this->shippo_api_url . 'shipments';
		$args     = array(
			'timeout' => 900,
			'headers' => array(
				'Authorization' => $this->shippo_auth_token,
				'content-type'  => 'application/json',
			),
			'body'    => $body,
		);
		$response = wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) || empty( $response ) || 201 !== wp_remote_retrieve_response_code( $response ) ) {
			wdm_error_log( $response );
			return;
		}
		$response_body = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $response_body );
		return $response_body->object_id;
	}

	/**
	 * This function fetches the available rates of diffrent carriers and service_levels for a shipment.
	 *
	 * @param string $shipment_id Id of the shipment for which the rate is being fetched.
	 * @param string $carrier_acc Id of the carrier account whose rate needs to be fetched.
	 * @param string $service_level Desired service level(Fedex_ground,ups_ground).
	 */
	private function get_rates( $shipment_id, $carrier_acc, $service_level ) {
		$rate_id  = '';
		$url      = $this->shippo_api_url . 'shipments/' . $shipment_id . '/rates';
		$args     = array(
			'timeout' => 900,
			'headers' => array(
				'Authorization' => $this->shippo_auth_token,
				'content-type'  => 'application/json',
			),
		);
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) || empty( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
			wdm_error_log( $response );
			return;
		}
		$response_body = wp_remote_retrieve_body( $response );
		$response_body = json_decode( $response_body );
		$rates         = array();
		$rates         = array_merge( $rates, $response_body->results );
		while ( null !== $response_body->next ) {
			$response      = wp_remote_get( $response_body->next, $args );
			$response_body = wp_remote_retrieve_body( $response );
			$response_body = json_decode( $response_body );
			if ( isset( $response_body->results ) && ! is_array( $response_body->results ) ) {
				$response_body->results = array( $response_body->results );
			}
			$rates = array_merge( $rates, $response_body->results );
		}
		foreach ( $rates as $rate ) {
			if ( ! is_object( $rate ) ) {
				continue;
			}
			if ( $carrier_acc === $rate->carrier_account && $service_level === $rate->servicelevel->token ) {
				$rate_id = $rate->object_id;
				break;
			}
		}
		if ( empty( $rate_id ) ) {
			wdm_error_log( $rates );
			return;
		}
		return $rate_id;
	}

	/**
	 * Set extra details in the Shippo package.
	 *
	 * @param WC_Order $order The order object.
	 * @param mixed    $extra The extra object.
	 *
	 * @return stdClass The updated extra object.
	 */
	private function set_extras( $order, $extra ) {
		$products = $order->get_items();
		if ( is_array( $products ) && 1 === count( $products ) ) {
			$products = array_values( $products );
			if ( empty( $extra ) ) {
				$extra              = new stdClass();
				$extra->reference_1 = 'Order Number: ' . $order->get_id();
				$extra->reference_2 = 'Product Number: ' . $products[0]->get_product_id();
			} else {
				$extra->reference_1 = 'Order Number: ' . $order->get_id();
				$extra->reference_2 = 'Product Number: ' . $products[0]->get_product_id();
			}
		}
		return $extra;
	}

	/**
	 * This is a setter function for address_from property.
	 */
	private function shippo_set_address_from() {
		$obj          = new stdClass();
		$obj->city    = WC()->countries->get_base_city();
		$obj->company = $this->ss_options['wdm_shippo_company_name'];
		$obj->country = WC()->countries->get_base_country();
		$obj->email   = $this->ss_options['wdm_shippo_sender_email'];
		$obj->name    = $this->ss_options['wdm_shippo_sender_name'];
		$obj->state   = WC()->countries->get_base_state();
		$obj->street1 = WC()->countries->get_base_address();
		$obj->street2 = WC()->countries->get_base_address_2();
		$obj->zip     = WC()->countries->get_base_postcode();
		$obj->phone   = $this->ss_options['wdm_shippo_sender_contact'];
		return $obj;
	}

	/**
	 * This is a setter function for address_to property.
	 *
	 * @param WC_Order $order Woocommerce order object.
	 */
	private function shippo_set_address_to( $order ) {
		$obj          = new stdClass();
		$obj->city    = $order->get_shipping_city();
		$obj->country = $order->get_shipping_country();
		$obj->name    = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
		$obj->state   = $order->get_shipping_state();
		$obj->street1 = $order->get_shipping_address_1();
		$obj->street2 = $order->get_shipping_address_2();
		$obj->zip     = $order->get_shipping_postcode();
		$obj->phone   = $order->get_billing_phone();
		return $obj;
	}

	/**
	 * Tracks a fedex shipment.
	 *
	 * @param int $order_id The order id.
	 *
	 * @return void
	 */
	public function track_fedex_shipment( int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || empty( $order_id ) || ! is_int( $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) || 'FedEx' !== $order->get_meta( 'wdm_shippo_outbound_carrier_token' ) || empty( $order->get_meta( 'wdm_shippo_outbound_tracking_number' ) ) ) {
			return;
		}
		$tracking_number = $order->get_meta( 'wdm_shippo_outbound_tracking_number' );
		$url             = $this->fedex_base_url . 'track/v1/trackingnumbers';
		try {
			$auth_token = $this->get_fedex_auth_token();
		} catch ( Exception $e ) {
			return;
		}
		$body = (object) array(
			'includeDetailedScans' => true,
			'trackingInfo'         => array(
				(object) array(
					'trackingNumberInfo' => (object) array(
						'trackingNumber' => $tracking_number,
					),
				),
			),
		);
		$body = wp_json_encode( $body );
		$args = array(
			'body'    => $body,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'authorization' => $auth_token,
			),
		);

		$response = wp_remote_post( $url, $args );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! isset( $body['output']['completeTrackResults'][0]['trackResults'][0]['latestStatusDetail']['statusByLocale'] ) ) {
			return;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()?->debug( wp_remote_retrieve_body( $response ) . PHP_EOL . PHP_EOL, array( 'source' => 'wdm_shippo_via_fedex_api_response' ) );
		}
		$tracking_status          = $body['output']['completeTrackResults'][0]['trackResults'][0]['latestStatusDetail']['statusByLocale'];
		$shippo_compatible_status = array(
			'Initiated'          => 'PRE_TRANSIT',
			'In transit'         => 'TRANSIT',
			'Picked up'          => 'TRANSIT',
			'Delivered'          => 'DELIVERED',
			'Delivery exception' => 'FAILURE',
			'Clearance Delay'    => 'FAILURE',
			'Ready for pickup'   => 'FAILURE',
			'Cancelled'          => 'FAILURE',

		);
		$tracking_status      = isset( $shippo_compatible_status[ $tracking_status ] ) ? $shippo_compatible_status[ $tracking_status ] : 'UNKNOWN';
		$tracking_status_data = array(
			'tracking_number' => $tracking_number,
			'tracking_status' => $tracking_status,
		);
		$this->wdm_async_status_update( $tracking_status_data, true );
		if ( 'DELIVERED' !== $tracking_status ) {
			as_schedule_single_action( time() + 86400 * 5, 'wdm_check_tracking_updates', array( $order->ID ) );
		}
	}

	/**
	 * This function will send an email to the customer if a delivery fails.
	 *
	 * This function is triggered when the webhook is called for a delivery failure.
	 *
	 * @param int $order_id The order ID.
	 *
	 * @return void
	 */
	public function wdm_delivery_failed_notification( int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || empty( $order_id ) || ! is_int( $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$status = $order->get_status();
		if ( in_array( $status, array( 'completed', 'awaiting-returns', 'returned-in-trans' ) ) ) {
			return;
		}
		wp_mail(
			$this->ss_options['wdm_merged_label_email'],
			'Delivery Failed',
			"Hi Team, <br><br>There is a outbound delivery failure for the order number {$order_id} kindly look into it and take the necessary action. <br><br>Thanks."
		);
	}

	/**
	 * Retrieves the authentication token to use in FedEx API requests.
	 *
	 * It does a POST request to the authorization endpoint with the client ID and client secret.
	 * The response is expected to be a JSON object containing the authentication token.
	 *
	 * @return string The authentication token.
	 *
	 * @throws Exception When something went wrong while getting the authentication token for fedex.
	 */
	private function get_fedex_auth_token() {
		$access_token = get_option( 'wdm_fedex_access_token', array() );
		if (
			! empty( $access_token ) &&
			is_array( $access_token ) &&
			isset( $access_token['access_token'] ) &&
			! empty( $access_token['access_token'] ) &&
			isset( $access_token['expires_at'] ) &&
			$access_token['expires_at'] - 500 > time()
		) {
			return 'Bearer ' . $access_token['access_token'];
		}
		$url      = $this->fedex_base_url . 'oauth/token';
		$args     = array(
			'body'    => array(
				'grant_type'    => 'client_credentials',
				'client_id'     => $this->fedex_client_id,
				'client_secret' => $this->fedex_client_secret,
			),
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
		);
		$response = wp_remote_post( $url, $args );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new Exception( 'Something went wrong while getting the authentication token for fedex.' );
		}
		$body         = json_decode( wp_remote_retrieve_body( $response ), true );
		$access_token = array(
			'access_token' => $body['access_token'],
			'expires_at'   => time() + $body['expires_in'],
		);
		if ( ! empty( $access_token ) ) {
			update_option( 'wdm_fedex_access_token', $access_token );
		}
		return 'Bearer ' . $access_token['access_token'];
	}

	/**
	 * Retrieve tracking info for an order using Shippo.
	 *
	 * @param string $state Inbound tracking state.
	 * @param int    $order_id The order ID.
	 *
	 * @return string The tracking state.
	 */
	protected function shippo_api_tracking( string $state, int $order_id ) {
		/**
		 * This function is currently unnecessary because Shippo is not being used as an inbound shipping partner.
		 * As a result, this section has been omitted.
		 * */
		return;
	}
}
