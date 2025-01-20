<?php
/**
 * This file contains all the code related to USPS.
 *
 * @package custom-plugin.
 */

/**
 * This file contains all the code related to USPS.
 */
trait WDM_USPS {

	/**
	 * Is test mode ?
	 *
	 * @var string
	 */
	protected string $usps_test_mode;
	/**
	 * USPS consumer id.
	 *
	 * @var string
	 */
	private string $consumer_id;
	/**
	 * USPS consumer secret.
	 *
	 * @var string
	 */
	private string $consumer_secret;
	/**
	 * USPS Base Url.
	 *
	 * @var string
	 */
	private string $usps_api_url;


	/**
	 * Initializes the USPS API.
	 *
	 * Retrieves the USPS API's consumer id, consumer secret, and whether it is in test mode.
	 * Sets the USPS API URL accordingly.
	 */
	protected function initialize_usps() {
		$this->usps_test_mode  = get_option( 'wdm_usps_test_mode', 'Yes' );
		$this->consumer_id     = $this->ss_options['wdm_usps_consumer_id'] ?? '';
		$this->consumer_secret = $this->ss_options['wdm_usps_consumer_secret'] ?? '';
		$this->usps_api_url    = 'Yes' === $this->usps_test_mode ? 'https://apis-tem.usps.com/' : 'https://api.usps.com/';
		$webhook_id            = get_option( 'wdm_usps_webhook_id', false );
		if ( false === $webhook_id || empty( $webhook_id ) ) {
			$this->setup_usps_webhook();
		}
	}

	/**
	 * Generate return shipping label using USPS API.
	 *
	 * @param WC_Order $order Order object.
	 *
	 * @throws Exception When failed to generate required tokens.
	 */
	protected function generate_return_label_usps( $order ) {
		try {
			$access_token  = $this->generate_authentication_token();
			$payment_token = $this->generate_payment_token( $access_token );
		} catch ( Exception $e ) {
			if ( ! wp_doing_ajax() ) {
				throw $e;
			}
			return;
		}

		if ( empty( $access_token ) || empty( $payment_token ) ) {
			if ( wp_doing_ajax() ) {
				wdm_error_log( 'USPS: Failed to generate/access authentication token or payment token or both.' );
			}
			throw new Exception( 'Failed to generate required tokens.' );
		}

		$products   = $order->get_items();
		$product_id = '';
		if ( is_array( $products ) && 1 === count( $products ) ) {
			$products   = array_values( $products );
			$product_id = $products[0]->get_product_id();
		}

		$shipping_body = (object) array(
			'imageInfo'          => (object) array(
				'imageType' => 'PDF',
				'labelType' => '4X6LABEL',
			),
			'toAddress'          => $this->usps_set_from_address(),
			'fromAddress'        => $this->usps_set_to_address( $order ),
			'packageDescription' => (object) array(
				'weight'             => intval( $this->parcel_weight_in_return ) / 16,
				'length'             => intval( $this->parcel_length ),
				'width'              => intval( $this->parcel_width ),
				'height'             => intval( $this->parcel_height ),
				'mailClass'          => 'USPS_GROUND_ADVANTAGE_RETURN_SERVICE',
				'processingCategory' => 'MACHINABLE',
				'rateIndicator'      => 'CP',
				'customerReference'  => array(
					(object) array(
						'referenceNumber'      => 'Order:' . $order->get_order_number(),
						'printReferenceNumber' => true,
					),
					(object) array(
						'referenceNumber'      => 'Product:' . $product_id,
						'printReferenceNumber' => true,
					),
				),
				'extraServices'      => array(
					857,
					828,
				),
			),
		);

		$url = $this->usps_api_url . 'labels/v3/return-label';

		$response = wp_remote_post(
			$url,
			array(
				'method'  => 'POST',
				'headers' => array(
					'Content-Type'                  => 'application/json',
					'Authorization'                 => 'Bearer ' . $access_token,
					'X-Payment-Authorization-Token' => $payment_token,
				),
				'body'    => wp_json_encode( $shipping_body ),
			)
		);

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			if ( wp_doing_ajax() ) {
				wdm_error_log( 'USPS: Failed to generate return label from USPS ' . print_r( $response, true ) );
			}
			throw new Exception( 'Failed to generate return label from USPS.' );
		}
		$response_body = wp_remote_retrieve_body( $response );

		$pattern = '/^"?--.*?name="[^"]+"\s*$/sm';

		$multipart_response_body = preg_split( $pattern, $response_body, -1, PREG_SPLIT_NO_EMPTY );
		$json_data               = json_decode( trim( $multipart_response_body[0] ) );
		update_post_meta( $order->get_id(), 'usps_tracking_id', $json_data->trackingNumber ); // @codingStandardsIgnoreLine 
		update_post_meta( $order->get_id(), 'usps_tracking_url', $json_data->links[0]->href );
		update_post_meta( $order->get_id(), 'usps_routing_number', $json_data->routingInformation ); // @codingStandardsIgnoreLine

		$label_data = trim( $multipart_response_body[1] );
		$label_data = explode( '--', $label_data );
		$label_data = $label_data[0];
		$label      = base64_decode( $label_data ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$this->wdm_store_generated_label( $label, $order->get_id(), 'inbound', true );
	}

	/**
	 * Generate authentication token to access USPS API.
	 *
	 * @return string Authentication token.
	 * @throws Exception When Client ID and Client Secret are not set.
	 */
	private function generate_authentication_token() {
		$token = get_option( 'wdm_usps_auth_token' );
		if ( ! empty( $token->access_token ) && ! empty( $token->expires_at ) && $token->expires_at > time() ) {
			return $token->access_token;
		}
		if ( empty( $this->consumer_id ) || empty( $this->consumer_secret ) ) {
			throw new Exception( 'Client ID and Client Secret are required to generate authentication token.' );
		}
		$auth_url      = $this->usps_api_url . 'oauth2/v3/token';
		$args          = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type' => 'application/x-www-form-urlencoded',
			),
			'body'    => http_build_query(
				array(
					'grant_type'    => 'client_credentials',
					'client_id'     => $this->consumer_id,
					'client_secret' => $this->consumer_secret,
				)
			),
		);
		$response      = wp_remote_post( $auth_url, $args );
		$response_body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! isset( $response_body->access_token ) || empty( $response_body->access_token ) ) {
			if ( wp_doing_ajax() ) {
				wdm_error_log( 'USPS: Failed to generate authentication token ' . print_r( $response, true ) );
			}
			throw new Exception( 'Failed to generate authentication token.' );
		}
		$obj               = new stdClass();
		$obj->access_token = $response_body->access_token;
		$obj->expires_at   = time() + ( $response_body->expires_in - 120 );
		update_option( 'wdm_usps_auth_token', $obj );
		return $response_body->access_token;
	}

	/**
	 * Generate payment token to use in USPS API.
	 *
	 * @param string $access_token Authentication token to access USPS API.
	 * @throws Exception When authentication token is empty or not string.
	 * @return string Payment token.
	 */
	private function generate_payment_token( string $access_token ) {
		$payment_token = get_option( 'wdm_usps_payment_token' );
		if ( ! empty( $payment_token->payment_token ) && ! empty( $payment_token->expires_at ) && $payment_token->expires_at > time() ) {
			return $payment_token->payment_token;
		}
		if ( empty( $access_token ) || ! is_string( $access_token ) ) {
			throw new Exception( 'Failed to generate payment token.' );
		}
		$crid       = isset( $this->ss_options['wdm_usps_crid'] ) ? $this->ss_options['wdm_usps_crid'] : '';
		$mid        = isset( $this->ss_options['wdm_usps_mid'] ) ? $this->ss_options['wdm_usps_mid'] : '';
		$mmid       = isset( $this->ss_options['wdm_usps_mmid'] ) ? $this->ss_options['wdm_usps_mmid'] : '';
		$acc_type   = isset( $this->ss_options['wdm_usps_acc_type'] ) ? $this->ss_options['wdm_usps_acc_type'] : '';
		$acc_number = isset( $this->ss_options['wdm_usps_acc_number'] ) ? $this->ss_options['wdm_usps_acc_number'] : '';

		$payer_role    = (object) array(
			'roleName'      => 'PAYER',
			'CRID'          => $crid,
			'MID'           => $mid,
			'manifestMID'   => $mmid,
			'accountType'   => $acc_type,
			'accountNumber' => $acc_number,
		);
		$label_owner   = (object) array(
			'roleName'    => 'LABEL_OWNER',
			'CRID'        => $crid,
			'MID'         => $mid,
			'manifestMID' => $mmid,
		);
		$url           = $this->usps_api_url . 'payments/v3/payment-authorization';
		$args          = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			),
			'body'    => wp_json_encode(
				array(
					'roles' => array(
						$payer_role,
						$label_owner,
					),
				)
			),
		);
		$response      = wp_remote_post( $url, $args );
		$response_body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! isset( $response_body->paymentAuthorizationToken ) || empty( $response_body->paymentAuthorizationToken ) ) { // @codingStandardsIgnoreLine
			if ( wp_doing_ajax() ) {
				wdm_error_log( 'USPS: Failed to generate payment token ' . print_r( $response, true ) );
			}
			throw new Exception( 'Failed to generate payment token.' );
		}

		$obj                = new stdClass();
		$obj->payment_token = $response_body->paymentAuthorizationToken; // @codingStandardsIgnoreLine
		$obj->expires_at    = time() + ( 60 * 60 * 8 );
		update_option( 'wdm_usps_payment_token', $obj );
		return $obj->payment_token;
	}

	/**
	 * This is a setter function for from_address property.
	 *
	 * @return object stdClass with all the details of sender.
	 */
	private function usps_set_from_address() {
		$obj                   = new stdClass();
		$obj->streetAddress    = WC()->countries->get_base_address(); // @codingStandardsIgnoreLine
		$obj->secondaryAddress = WC()->countries->get_base_address_2(); // @codingStandardsIgnoreLine
		$obj->city             = WC()->countries->get_base_city();
		$obj->state            = WC()->countries->get_base_state();
		$obj->ZIPCode          = WC()->countries->get_base_postcode(); // @codingStandardsIgnoreLine
		$obj->firstName        = $this->ss_options['wdm_shippo_company_name']; // @codingStandardsIgnoreLine
		$obj->firm             = $this->ss_options['wdm_shippo_company_name'];
		$obj->email            = $this->ss_options['wdm_shippo_sender_email'];
		$obj->phone            = '8336227555';
		$obj->ignoreBadAddress = false; // @codingStandardsIgnoreLine
		return $obj;
	}

	/**
	 * This is a setter function for to_address property.
	 *
	 * @param WC_Order $order The order object for which to address object is being set.
	 *
	 * @return object stdClass with all the details of recipient.
	 */
	private function usps_set_to_address( $order ) {
		if ( ! is_object( $order ) ) {
			return new stdClass();
		}
		$obj                   = new stdClass();
		$obj->streetAddress    = $order->get_shipping_address_1(); // @codingStandardsIgnoreLine
		$obj->secondaryAddress = $order->get_shipping_address_2(); // @codingStandardsIgnoreLine
		$obj->city             = $order->get_shipping_city();
		$obj->state            = $order->get_shipping_state();
		$obj->ZIPCode          = $order->get_shipping_postcode(); // @codingStandardsIgnoreLine
		$obj->firstName        = $order->get_billing_first_name(); // @codingStandardsIgnoreLine
		$obj->lastName         = $order->get_billing_last_name(); // @codingStandardsIgnoreLine
		$obj->phone            = '7700996809'; // Updated to avoid issues with the phone validation done by usps during label generation as it is not a required feature.
		$obj->email            = 'ankitparekh96.ap@gmail.com'; // Updated to avoid issues with the email validation done by usps during label generation as it is not a required feature.
		$obj->ignoreBadAddress = true; // @codingStandardsIgnoreLine
		$obj->ZIPPlus4         = null; // @codingStandardsIgnoreLine
		if( is_string( $obj->ZIPCode ) && strlen( trim( $obj->ZIPCode ) ) > 5 ) { //@codingStandardsIgnoreLine
			$obj->ZIPCode = substr( trim( $obj->ZIPCode ), 0, 5 ); // @codingStandardsIgnoreLine	 
		}
		return $obj;
	}

	/**
	 * This function sets up the webhook on USPS server which will be used to receive tracking updates.
	 *
	 * It will make a POST request to the subscriptions endpoint with the listener URL which will receive the tracking updates.
	 * The listener URL is the REST endpoint of the WordPress site which will receive the tracking updates and update the order status accordingly.
	 *
	 * @throws Exception When failed to set up the webhook.
	 */
	private function setup_usps_webhook() {
		$access_token = $this->generate_authentication_token();
		$url          = 'https://api.usps.com/subscriptions-tracking/v3/subscriptions'; // Always setup the webhook for tracking updates in live mode.
		$args         = array(
			'method'  => 'POST',
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			),
			'body'    => wp_json_encode(
				(object) array(
					'listenerURL'       => get_site_url( null, '/wp-json/usps/usps-tracking' ),
					'secret'            => 'your-secret-here',
					'adminNotification' => array(
						(object) array( 'email' => get_bloginfo( 'admin_email' ) ),
						(object) array( 'email' => 'ankitparekh96.ap@gmail.com' ),
					),
					'filterProperties'  => (object) array(
						'MID'                => $this->ss_options['wdm_usps_mid'],
						'trackingEventTypes' => array( 'ALL' ),
					),
				)
			),
		);
		$response     = wp_remote_post( $url, $args );
		if ( 201 !== wp_remote_retrieve_response_code( $response ) ) {
			throw new Exception( 'Failed to setup the webhook for usps tracking updates' );
		}
		$body = json_decode( wp_remote_retrieve_body( $response ) );
		update_option( 'wdm_usps_webhook_id', $body->subscriptionId ); //@codingStandardsIgnoreLine
	}

	/**
	 * Retrieve tracking info for an order using USPS.
	 *
	 * @param string $state Inbound tracking state.
	 * @param int    $order_id The order ID.
	 *
	 * @return string The tracking state.
	 */
	protected function usps_api_tracking( string $state, int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || empty( $order_id ) || ! is_int( $order_id ) ) {
			return $state;
		}
		$order = wc_get_order( $order_id );
		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) || empty( $order->get_meta( 'usps_tracking_id' ) ) ) {
			return $state;
		}
		$tracking_number = $order->get_meta( 'usps_tracking_id' );
		$url             = $this->usps_api_url . 'tracking/v3/tracking/' . $tracking_number;
		try {
			$access_token = $this->generate_authentication_token();
		} catch ( Exception $e ) {
			return $state;
		}
		$args     = array(
			'method'  => 'GET',
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $access_token,
			),
			'query'   => array(
				'expand' => 'DETAIL',
			),
		);
		$response = wp_remote_get( $url, $args );
		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return $state;
		}
		$body = json_decode( wp_remote_retrieve_body( $response ) );
		if ( ! isset( $body->trackingNumber ) || empty( $body->trackingNumber ) || ! isset( $body->trackingEvents[0]->eventCode ) || empty( $body->trackingEvents[0]->eventCode ) ) { //@codingStandardsIgnoreLine
			return $state;
		}
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()?->debug( wp_remote_retrieve_body( $response ) . PHP_EOL . PHP_EOL, array( 'source' => 'wdm_usps_api_tracking_response' ) );
		}
		$m_body = (object) array(
			'TrackInfo' => (object) array(
				'ID'           => $body->trackingNumber, //@codingStandardsIgnoreLine
				'TrackSummary' => (object) array(
					'EventCode' => $body->trackingEvents[0]->eventCode, //@codingStandardsIgnoreLine
				),
			),
		);
		$this->wdm_add_tracking_info_to_orders_usps( $m_body, true );
		$state = $this->translate_usps_tracking_status( $body->trackingEvents[0]->eventCode ); //@codingStandardsIgnoreLine
		return $state;
	}

	/**
	 * Translate USPS tracking status to a more readable format.
	 *
	 * @param string $event_code The event code returned by USPS.
	 *
	 * @return string One of 'delivered', 'failure', 'pre_transit', 'in_transit', 'unknown'.
	 */
	protected function translate_usps_tracking_status( string $event_code ) {
		switch ( $event_code ) {
			case '01':
			case '41':
			case '43':
			case '63':
				return 'delivered';
			case '02':
			case '53':
			case '54':
			case '55':
			case '56':
			case '04':
			case '05':
			case '09':
			case '21':
			case '22':
			case '23':
			case '24':
			case '25':
			case '26':
			case '27':
			case '28':
			case '29':
			case '11':
			case '12':
			case '':
			case '30':
			case '31':
			case '32':
			case '33':
			case '44':
			case '46':
			case '51':
			case '57':
			case '71':
			case '72':
			case 'DX':
			case 'LX':
			case 'MU':
			case 'MX':
			case 'OX':
			case 'TX':
			case 'VC':
			case 'VH':
			case 'VJ':
			case 'VS':
			case 'VX':
			case 'WX':
			case '64':
				return 'failure';
			case 'GC':
			case 'MA':
			case 'GX':
			case '89':
				return 'pre_transit';
			case '03':
			case '14':
			case 'VF':
			case '52':
			case 'VP':
			case '06':
			case '07':
			case '08':
			case '10':
			case '15':
			case '16':
			case '17':
			case '34':
			case '35':
			case '36':
			case '38':
			case '39':
			case '40':
			case '42':
			case '45':
			case '58':
			case '59':
			case '60':
			case 'A1':
			case 'AD':
			case 'AE':
			case 'AX':
			case 'B1':
			case 'B5':
			case 'DE':
			case 'E1':
			case 'EF':
			case 'L1':
			case 'LD':
			case 'MR':
			case 'NT':
			case 'OA':
			case 'OD':
			case 'OF':
			case 'PC':
			case 'RB':
			case 'RC':
			case 'SF':
			case 'T1':
			case 'TM':
			case 'UA':
			case 'VR':
			case 'WN':
			case '61':
			case '62':
			case '80':
			case '81':
			case '82':
			case '83':
			case '84':
			case '85':
			case '86':
			case '87':
				return 'in_transit';
			default:
				return 'unknown';
		}
	}
}
