<?php
/**
 * This file contains all the code related to Label Generation and Storing in the uploads/shipping directory along with automatic deletion and merging.
 *
 * @package custom-plugin
 */

/**
 * This file contains all the code related to AJAX.
 */
class SS_Label_Generator {

	use SS_Parcels;
	use WDM_Shippo;
	use WDM_Easypost;
	use WDM_USPS;

	/**
	 * The instance of the class.
	 *
	 * @var SS_Label_Generator The instance of the class.
	 */
	private static $instance = null;

	/**
	 * SS options.
	 *
	 * @var array
	 */
	protected array $ss_options;

	/**
	 * Shipping partner.
	 *
	 * @var string
	 */
	protected string $shipping_partner;

	/**
	 * Label path.
	 *
	 * @var string
	 */
	protected string $label_path;

	/**
	 * The constructor. It adds all the AJAX handlers.
	 */
	private function __construct() {
		$this->ss_options       = get_option( 'custom_plugin_options', array() );
		$this->shipping_partner = get_option( 'wdm_shipping_partner_selector', false );
		$this->label_path       = wp_get_upload_dir()['basedir'] . '/shipping/';
		$this->initialize_parcels();
		$this->initialize_shippo();
		$this->initialize_easypost();
		try {
			$this->initialize_usps();
		} catch ( Exception $e ) {
			add_action(
				'admin_notices',
				function () {
					?>
				<div class="notice notice-error is-dismissible">
				<p><?php esc_html_e( 'Unable to add the webhook for USPS.', 'code-sample' ); ?></p>
				</div>
					<?php
				}
			);
		}
		add_action( 'wp_ajax_wdm_gen_label', array( $this, 'wdm_order_created' ), 10 );
		add_action( 'rest_api_init', array( $this, 'wdm_add_endpoint' ), 10 );
		add_action( 'wdm_delete_shipping_labels', array( $this, 'wdm_remove_old_shipping_labels' ), 10, 2 );
		add_action( 'wdm_async_shippo_updates', array( $this, 'wdm_add_tracking_info_to_orders' ), 10, 2 );
		add_action( 'wdm_check_tracking_updates', array( $this, 'track_fedex_shipment' ), 10, 1 );
		add_action( 'wdm_delivery_failed_notification', array( $this, 'wdm_delivery_failed_notification' ), 10, 1 );
		add_filter( 'wdm_internal_easypost_state', fn( $state, $order_id ) => $this->easypost_api_tracking( $state, $order_id ), 10, 2 );
		add_filter( 'wdm_internal_usps_state', fn( $state, $order_id ) => $this->usps_api_tracking( $state, $order_id ), 10, 2 );
		add_action( 'wdm_confirm_inbound_status', array( $this, 'wdm_confirm_inbound_status' ), 10, 1 );
	}

	/**
	 * Check if the webhook is coming from a secure IP address.
	 *
	 * @param WP_REST_Request $req The request object.
	 * @param bool            $is_usps True if the webhook is coming from USPS, false otherwise.
	 *
	 * @return bool True if the webhook is secure, false otherwise.
	 */
	private function wdm_secure_shippo_webhook( WP_REST_Request $req, bool $is_usps = false ) {
		if ( 'Yes' === $this->shippo_test_mode && ! $is_usps ) {
			return true;
		}
		if ( 'Yes' === $this->usps_test_mode && $is_usps ) {
			return true;
		}
			$allowded_ips      = array(
				'52.4.41.98',
				'52.23.121.194',
				'52.44.110.80',
				'54.81.253.187',
				'54.81.255.221',
				'34.248.247.69',
				'34.253.119.130',
				'52.214.174.64',
				'54.72.179.250',
			);
			$allowded_usps_ips = array(
				'34.171.251.51',
				'146.148.45.72',
				'34.29.251.112',
			);
			$allowded_ips      = apply_filters( 'wdm_shippo_webhook_ip_addresses', $allowded_ips );
			$allowded_usps_ips = apply_filters( 'wdm_usps_webhook_ip_addresses', $allowded_usps_ips );
			if ( ! isset( $_SERVER['REMOTE_ADDR'] ) || empty( $_SERVER['REMOTE_ADDR'] ) ) {
				return false;
			}
			if ( $is_usps && ! in_array( $_SERVER['REMOTE_ADDR'], $allowded_usps_ips, true ) ) {
				return false;
			}
			if ( ! $is_usps && ! in_array( $_SERVER['REMOTE_ADDR'], $allowded_ips, true ) ) {
				return false;
			}
			$result = $is_usps ? $this->validate_usps_hmac( $req ) : $this->validate_shippo_hmac( $req );
			return $result;
	}

	/**
	 * Validate that the webhook is coming from Easypost.
	 *
	 * @param WP_REST_Request $req The request object.
	 *
	 * @return bool True if the webhook is secure, false otherwise.
	 */
	private function wdm_secure_easypost_webhook( $req ) {
		if ( 'Yes' === $this->easypost_test_mode ) {
			return true;
		}
		$webhook_secret          = 'your-secret-here';
		$event_body              = $req->get_body();
		$easypost_hmac_signature = $req->get_header( 'X-Hmac-Signature' );
		if ( null === $easypost_hmac_signature ) {
			return false;
		}
		$normalized_secret = Normalizer::normalize( $webhook_secret, Normalizer::FORM_KD );
		$encoded_secret    = mb_convert_encoding( $normalized_secret, 'UTF-8' );

		$expected_signature = hash_hmac( 'sha256', $event_body, $encoded_secret );
		$digest             = "hmac-sha256-hex=$expected_signature";

		if ( hash_equals( $digest, $easypost_hmac_signature ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Validate that the webhook is coming from USPS.
	 *
	 * @param WP_REST_Request $req The request object.
	 *
	 * @return bool True if the webhook is secure, false otherwise.
	 */
	private function validate_usps_hmac( WP_REST_Request $req ) {
		/** The claimed procedure to recreate the signature is invalid
		 * and will probably be modified in the future hence currently
		 * we are only returning true.
		 * */
		if ( ! is_a( $req, 'WP_REST_Request' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Validates that the webhook is coming from Shippo.
	 *
	 * @param WP_REST_Request $req The request object.
	 *
	 * @return bool True if the webhook is secure, false otherwise.
	 */
	private function validate_shippo_hmac( WP_REST_Request $req ) {
		if ( ! is_a( $req, 'WP_REST_Request' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Gets the instance of the class.
	 *
	 * This method creates the instance of the class if it does not exist yet.
	 * If the instance already exists, it will be returned.
	 *
	 * @return SS_Label_Generator The instance of the class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * This function generates the shipping label for the given order id.
	 * It only generates the outbound shipping label. If the order is eligible for return, it also generates the return shipping label.
	 * If the order is fully shipped, the order status is updated to 'shipped'.
	 * If both the outbound and inbound labels are generated, it merges them.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 * @throws Exception When failed to generate shipping label.
	 */
	public function wdm_order_created() {
		check_ajax_referer( 'secret', 'nonce' );
		if ( ! isset( $_POST['order_id'] ) || empty( $_POST['order_id'] ) ) {
			if ( ! wp_doing_ajax() ) {
				throw new Exception( 'Order ID not found' );
			}
			wp_send_json_error( 'Order ID not found' );
		}
		$order_id = sanitize_text_field( wp_unslash( $_POST['order_id'] ) );
		$flag1    = get_post_meta( $order_id, 'outbound-shipping-label-generated', true );
		if ( ! empty( $flag1 ) ) {
			if ( ! wp_doing_ajax() ) {
				throw new Exception( 'Outbound Shipping label already generated' );
			}
			wp_send_json_error( 'Outbound Shipping label already generated' );
		}
		$order = wc_get_order( $order_id );
		try {
			$this->wdm_generate_shipping_label_shippo( $order );
			$flag2 = get_post_meta( $order_id, 'inbound-shipping-label-generated', true );
			if ( empty( $flag2 ) ) {
				if ( ( 'Easypost' === $this->shipping_partner || 'fedex' === $order->get_meta( 'return_partner' ) ) && wdm_get_returnable_items_for_order( $order ) > 0 && 'usps' !== $order->get_meta( 'return_partner' ) ) {
					$this->generate_return_label_easypost( $order );
				} elseif ( ( 'USPS' === $this->shipping_partner || 'usps' === $order->get_meta( 'return_partner' ) || '' === $order->get_meta( 'return_partner' ) ) && wdm_get_returnable_items_for_order( $order ) > 0 ) {
					$this->generate_return_label_usps( $order );
				}
			}
		} catch ( Exception $e ) {
			if ( ! wp_doing_ajax() ) {
				throw $e;
			}
			wp_send_json_error( $e->getMessage() );
		}

		$flag1 = get_post_meta( $order_id, 'outbound-shipping-label-generated', true );
		$flag2 = get_post_meta( $order_id, 'inbound-shipping-label-generated', true );
		if ( '1' === $flag1 && wdm_check_order_statuses( 'wc-shipped' ) && 0 === wdm_get_returnable_items_for_order( $order ) ) {
			$order->update_status( 'shipped' );
		}
		if ( '1' === $flag1 && '1' === $flag2 && wdm_check_order_statuses( 'wc-shipped' ) && 0 < wdm_get_returnable_items_for_order( $order ) ) {
			$order->update_status( 'shipped' );
			$this->wdm_merge_labels( $order_id );
		}
	}

	/** This function merges the outbound and inound shipping labels based on order.
	 *
	 *  @param int $order_id ID of the order.
	 *  @throws Exception Will only throw exception if custom_exception_handeling is true.
	 */
	private function wdm_merge_labels( $order_id ) {
		if ( empty( $order_id ) ) {
			return;
		}
		require_once SS_PATH . 'vendor/PDFMerger-master/PDFMerger.php';
		$fn1 = $this->label_path . $order_id . ' outbound.pdf';
		$fn2 = $this->label_path . $order_id . ' inbound.pdf';
		try {
			$pdf2 = new PDFMerger\PDFMerger();
			$pdf2->addPDF( $fn1 );
			$pdf2->addPDF( $fn2 );
			$newfile = $this->label_path . $order_id . '.pdf';
			$pdf2->merge( 'file', $newfile );
		} catch ( Exception $e ) {
			wdm_error_log( $e->getMessage() );
			$error = array(
				'errorCode' => 'Unable to create the merged label',
				'message'   => $e->getMessage() . '<p>More details are added in the system error log</p>',
			);
			if ( ! wp_doing_ajax() ) {
				throw new Exception( 'Error merging pdf' );
			}
			wp_send_json_error( $error, 500 );
		}
		update_post_meta( $order_id, 'merged-shipping-label-generated', true );

		$wdm_delete_shipping_labels_time = ! empty( $this->ss_options['wdm_delete_shipping_labels'] ) ? intval( $this->ss_options['wdm_delete_shipping_labels'] ) * 86400 : 100 * 86400;
		$wdm_delete_shipping_labels_time = time() + $wdm_delete_shipping_labels_time;

		$arguments = array(
			'order' => $order_id,
			'file'  => $newfile,
		);

		as_schedule_single_action( $wdm_delete_shipping_labels_time, 'wdm_delete_shipping_labels', $arguments );
	}

	/**
	 * Stores the generated shipping label in the uploads/shipping directory.
	 *
	 * @param string $data_or_url The data or url of the shipping label.
	 * @param int    $order_id The ID of the order.
	 * @param string $type The type of shipping label. Defaults to 'inbound'.
	 * @param bool   $is_data Whether the data_or_url parameter contains data or a URL.
	 *
	 * @return void
	 */
	protected function wdm_store_generated_label( string $data_or_url, int $order_id, string $type = 'inbound', bool $is_data = false ) {
		global $wp_filesystem;
		$pdf_data = ( ! $is_data ) ? $wp_filesystem->get_contents( $data_or_url ) : $data_or_url;
		$path     = wp_get_upload_dir();
		$path     = $path['basedir'] . '/shipping';
		if ( ! $wp_filesystem->is_dir( $path ) ) {
			$wp_filesystem->mkdir( $path );
		}
		$new_filename = $path . '/' . $order_id . ' ' . $type . '.pdf';
		if ( file_exists( $new_filename ) ) {
			wp_delete_file( $new_filename );
		}
		$result = false;
		$result = $wp_filesystem->put_contents( $new_filename, $pdf_data );
		if ( true !== $result ) {
			return;
		}

		$wdm_delete_shipping_labels_time = ! empty( $this->ss_options['wdm_delete_shipping_labels'] ) ? intval( $this->ss_options['wdm_delete_shipping_labels'] ) * 86400 : 100 * 86400;
		$wdm_delete_shipping_labels_time = time() + $wdm_delete_shipping_labels_time;

		$arguments = array(
			'order' => $order_id,
			'file'  => $new_filename,
		);
		as_schedule_single_action( $wdm_delete_shipping_labels_time, 'wdm_delete_shipping_labels', $arguments );
		update_post_meta( $order_id, $type . '-shipping-label-generated', true );
	}

	/**
	 * This functions creates a webhook to receive tracking updates.
	 */
	public function wdm_add_endpoint() {
		register_rest_route(
			'shippo',
			'shippo-tracking',
			array(
				'methods'             => 'POST',
				'callback'            => fn( $req ) => $this->wdm_async_status_update( $req ),
				'permission_callback' => fn( $req ) => $this->wdm_secure_shippo_webhook( $req ),
			)
		);
		register_rest_route(
			'easypost',
			'easypost-tracking',
			array(
				'methods'             => 'POST',
				'callback'            => fn( $req ) => $this->wdm_add_tracking_info_to_orders_easypost( $req ),
				'permission_callback' => fn( $req ) => $this->wdm_secure_easypost_webhook( $req ),
			)
		);
		register_rest_route(
			'usps',
			'usps-tracking',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'wdm_add_tracking_info_to_orders_usps' ),
				'permission_callback' => fn( $req ) => $this->wdm_secure_shippo_webhook( $req, true ),
			)
		);
		register_rest_route(
			'ss',
			'label-gen',
			array(
				'methods'             => 'GET',
				'callback'            => function ( $request ) {
					$default_pl = get_option( 'default_printing-line', false );
					if ( false === $default_pl ) {
						return new WP_Error( 'error', 'Default printing line not set.' );
					}
					$page = $request->get_param( 'page' );
					if ( empty( $page ) ) {
						$page = 0;
						unset( $_SESSION['failed_orders'] );
						unset( $_SESSION['merged_failed_orders'] );
						delete_option( 'wdm_shipping_files' );
						unset( $_SESSION['successful_orders'] );
						unset( $_SESSION['wdm_time'] );
					}
					$response = $this->auto_generate_labels( $page );
					if ( is_a( $response, 'WP_REST_Response' ) && 302 === $response->get_status() ) {
						$headers = $response->get_headers();
						if ( ! is_array( $headers ) || empty( $headers ) || ! isset( $headers['Location'] ) ) {
							return;
						}
						wp_safe_redirect( $headers['Location'], 302 );
						exit;
					}
				},
				'permission_callback' => function ( $request ) {
					$page = $request->get_param( 'page' );
					$signature = $request->get_param( 'lb' ) ?? '';
					if ( empty( $page ) ) {
						$page = '0';
					}
					$secret_key = 'your-secret-here';
					$hash = hash_hmac( 'sha256', $page, $secret_key );
					return hash_equals( $signature, $hash );
				},
			)
		);
	}


	/**
	 * Verify the HMAC signature for the webhook sent by Shippo and Easypost.
	 *
	 * @param string $signature The signature sent by Shippo or Easypost.
	 * @param string $body The body of the request.
	 *
	 * @return bool True if the signature matches, false otherwise.
	 */
	private function wdm_verify_signature( $signature, $body ) {
		$secret     = 'your-secret-here';
		$calculated = hash_hmac( 'sha256', $body, $secret );
		return hash_equals( $signature, $calculated );
	}

	/**
	 * Handles the webhook sent by Shippo when a tracking update is received.
	 * Schedules an action to update the order with the new tracking status.
	 *
	 * @param WP_REST_Request|array $request The request object.
	 * @param bool                  $is_internal True if the request is internal, false otherwise.
	 *
	 * @return void
	 */
	protected function wdm_async_status_update( $request, $is_internal = false ) {
		if ( $is_internal ) {
			$body = (object) array(
				'data' => (object) array(
					'tracking_number' => $request['tracking_number'],
					'tracking_status' => (object) array(
						'status' => $request['tracking_status'],
					),
				),
			);
		} else {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()?->debug( $request?->get_body() . PHP_EOL . PHP_EOL, array( 'source' => 'wdm_shippo_webhook' ) );
			}
			if ( ! is_a( $request, 'WP_REST_Request' ) ) {
				return;
			}
			$body = $request->get_body();
			$body = json_decode( $body );
			if ( ! isset( $body->event ) || 'track_updated' !== $body->event ) {
				return;
			}
			if ( ! isset( $body->data->tracking_number ) || ! isset( $body->data->tracking_status->status ) ) {
				return;
			}
		}
		$tracking_number = $body->data->tracking_number;
		$tracking_status = $body->data->tracking_status->status;
		if ( empty( $tracking_number ) || empty( $tracking_status ) ) {
			return;
		}
		$args      = array(
			'tracking_number' => $tracking_number,
			'tracking_status' => $tracking_status,
		);
		$action_id = as_schedule_single_action( time() + 10, 'wdm_async_shippo_updates', $args );
		if ( 0 === $action_id ) {
			$err = 'Failed to schedule tracking status update for order with tracking number:' . $tracking_number;
			wdm_error_log( $err );
		}
	}

	/**
	 * This function stores the tracking info received in the required manner.
	 *
	 * @param string $tracking_number The tracking number.
	 * @param string $tracking_status The tracking status.
	 */
	public function wdm_add_tracking_info_to_orders( $tracking_number, $tracking_status ) {
		if ( empty( $tracking_number ) || empty( $tracking_status ) ) {
			return;
		}

		$query_args = array(
			'post_type'   => 'shop_order',
			'post_status' => 'any',
			'order'       => 'DESC',
			'orderby'     => 'ID',
			'meta_query'  => array(
				'relation' => 'OR',
				'0'        => array(
					'key'     => 'wdm_shippo_outbound_tracking_number',
					'value'   => $tracking_number,
					'compare' => '=',
				),
				'1'        => array(
					'key'     => 'wdm_shippo_inbound_tracking_number',
					'value'   => $tracking_number,
					'compare' => '=',
				),
			),
		);

		$the_query = new WP_Query( $query_args );

		if ( 1 === $the_query->post_count ) {
			$wdm_order_id = $the_query->posts[0]->ID;
			if ( get_post_meta( $wdm_order_id, 'wdm_shippo_inbound_tracking_number', true ) === $tracking_number ) {
				$prev_tracking_status = get_post_meta( $wdm_order_id, 'wdm_shippo_inbound_tracking_status', true );
				if ( $prev_tracking_status === $tracking_status ) {
					return;
				}
				update_post_meta( $wdm_order_id, 'wdm_shippo_inbound_tracking_status', $tracking_status );
				if ( 'TRANSIT' !== $tracking_status && 'DELIVERED' !== $tracking_status ) {
					return;
				}
				$order = new WC_Order( $wdm_order_id );
				if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
					return;
				}
				if ( 'DELIVERED' === $tracking_status ) {
					add_filter(
						'woocommerce_mail_callback',
						function ( $mailer, $obj ) {
							if ( is_a( $obj, 'WC_Email_Customer_Completed_Order' ) ) {
								return function () {
									// Do nothing since we don't want to send an email.
								};
							}
							return $mailer;
						},
						10,
						2
					);
					$order->update_status( 'completed' );
					return;
				}
				if ( wdm_check_order_statuses( 'wc-returned-in-trans' ) ) {
					$order->update_status( 'returned-in-trans' );
					if ( $prev_tracking_status !== $tracking_status ) {
						$refund_result = $this->process_scheduled_refunds( $order );
						if ( is_wp_error( $refund_result ) ) {
							$order->add_order_note( $refund_result->get_error_message(), 0 );
						}
					}
					return;
				}
				$order->add_order_note( 'Order status wc-returned-in-trans does not exist', 0 );
				return;
			}
			$prev_tracking_status = get_post_meta( $wdm_order_id, 'wdm_shippo_outbound_tracking_status', true );
			if ( $prev_tracking_status === $tracking_status ) {
				return;
			}
			update_post_meta( $wdm_order_id, 'wdm_shippo_outbound_tracking_status', $tracking_status );
			if ( 'PRE_TRANSIT' === $tracking_status ) {
				add_post_meta( $wdm_order_id, 'pre_transit_time', time(), true );
			}
			if ( 'DELIVERED' !== $tracking_status ) {
				return;
			}
			$order = new WC_Order( $wdm_order_id );
			if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
				return;
			}
			$flag1 = get_post_meta( $wdm_order_id, 'inbound-shipping-label-generated', true );
			if ( '1' === $flag1 ) {
				if ( wdm_check_order_statuses( 'wc-awaiting-returns' ) ) {
					if ( 'converted' !== $order->get_meta( 'wdm_order_converted' ) ) {
						$this->wdm_schedule_charges( $order );
						$order->update_status( 'awaiting-returns' );
						as_schedule_single_action( time() + 86400 * 15, 'wdm_confirm_inbound_status', array( intval( $wdm_order_id ) ) );
					} else {
						$order->update_status( 'completed' );
					}
					return;
				}
				$order->add_order_note( 'Order status wc-awaiting-returns does not exist', 0 );
			}
			$order->update_status( 'completed' );
		}
	}

	/**
	 * Handle Easypost webhooks, and update order meta with tracking and refund status.
	 *
	 * @since 1.0.0
	 * @param WP_Rest_Request $reqest The request object.
	 * @param bool            $is_internal True if the webhook is coming from Easypost, false otherwise.
	 */
	public function wdm_add_tracking_info_to_orders_easypost( $reqest, $is_internal = false ) {
		if ( $is_internal ) {
			$event = 'tracker.updated';
			$body  = $reqest;
		} else {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()?->debug( $reqest?->get_body() . PHP_EOL . PHP_EOL, array( 'source' => 'wdm_easypost_webhook' ) );
			}
			$body  = $reqest->get_body();
			$body  = json_decode( $body );
			$event = $body->description;
		}

		if ( 'refund.successful' === $event ) {
			$shipment_id   = $body->result->shipment_id;
			$refund_status = $body->result->status;

			$query_args = array(
				'post_type'   => 'shop_order',
				'post_status' => 'any',
				'order'       => 'DESC',
				'orderby'     => 'ID',
				'meta_query'  => array(
					'0' => array(
						'key'     => 'easypost_shipment_id',
						'value'   => $shipment_id,
						'compare' => '=',
					),
				),
			);

			$the_query = new WP_Query( $query_args );

			if ( 1 === $the_query->post_count ) {
				$wdm_order_id = $the_query->posts[0]->ID;
				update_post_meta( $wdm_order_id, 'wdm_refund_status_inbound', $refund_status );
			}
		} elseif ( 'tracker.updated' === $event ) {

			$tracking_id    = $body->result->id;
			$trackingstatus = $body->result->status;

			$query_args = array(
				'post_type'   => 'shop_order',
				'post_status' => 'any',
				'order'       => 'DESC',
				'orderby'     => 'ID',
				'meta_query'  => array(
					'0' => array(
						'key'     => 'easypost_tracking_id',
						'value'   => $tracking_id,
						'compare' => '=',
					),
				),
			);

			$the_query = new WP_Query( $query_args );

			if ( 1 === $the_query->post_count ) {
				$wdm_order_id = $the_query->posts[0]->ID;
				$order        = new WC_Order( $wdm_order_id );
				if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) || 'converted' === $order->get_meta( 'wdm_order_converted' ) ) {
					return;
				}
				$prev_tracking_status = get_post_meta( $wdm_order_id, 'wdm_tracking_status_inbound', true );
				if ( $prev_tracking_status === $trackingstatus ) {
					return;
				}
				update_post_meta( $wdm_order_id, 'wdm_tracking_status_inbound', $trackingstatus );
				if ( 'in_transit' !== $trackingstatus && 'delivered' !== $trackingstatus ) {
					return;
				}

				if ( 'delivered' === $trackingstatus ) {
					add_filter(
						'woocommerce_mail_callback',
						function ( $mailer, $obj ) {
							if ( is_a( $obj, 'WC_Email_Customer_Completed_Order' ) ) {
								return function () {
									// Do nothing since we don't want to send an email.
								};
							}
							return $mailer;
						},
						10,
						2
					);
					$order->update_status( 'completed' );
					return;
				}
				if ( wdm_check_order_statuses( 'wc-returned-in-trans' ) ) {
					$order->update_status( 'returned-in-trans' );
					if ( $prev_tracking_status !== $trackingstatus ) {
						$refund_result = $this->process_scheduled_refunds( $order );
						if ( is_wp_error( $refund_result ) ) {
							$order->add_order_note( $refund_result->get_error_message(), 0 );
						}
					}
					return;
				}
				$order->add_order_note( 'Order status wc-returned-in-trans does not exist', 0 );
			}
		}
	}

	/**
	 * Handle USPS webhooks, and update order meta with tracking updates.
	 *
	 * @since 1.0.0
	 * @param WP_Rest_Request $request The request object.
	 * @param bool            $is_internal True if the request is internal, false otherwise.
	 */
	public function wdm_add_tracking_info_to_orders_usps( $request, $is_internal = false ) {
		if ( $is_internal ) {
			$payload = $request;
		} else {
			if ( function_exists( 'wc_get_logger' ) ) {
				wc_get_logger()?->debug( $request?->get_body() . PHP_EOL . PHP_EOL, array( 'source' => 'wdm_usps_webhook' ) );
			}
			$body = $request->get_body();
			$body = json_decode( $body );
			if ( ! isset( $body->payload ) || empty( $body->payload ) ) {
				return;
			}
			$payload = json_decode( $body->payload );
		}
		if ( ! isset( $payload->TrackInfo->ID ) || empty( $payload->TrackInfo->ID ) || ! isset( $payload->TrackInfo->TrackSummary->EventCode ) || empty( $payload->TrackInfo->TrackSummary->EventCode ) ) { //@codingStandardsIgnoreLine
			return;
		}
		$tracking_id    = $payload->TrackInfo->ID; //@codingStandardsIgnoreLine
		$trackingstatus = $payload->TrackInfo->TrackSummary->EventCode; //@codingStandardsIgnoreLine
		$trackingstatus = $this->translate_usps_tracking_status( $trackingstatus );
		$query_args     = array(
			'post_type'   => 'shop_order',
			'post_status' => 'any',
			'order'       => 'DESC',
			'orderby'     => 'ID',
			'meta_query'  => array(
				'0' => array(
					'key'     => 'usps_tracking_id',
					'value'   => $tracking_id,
					'compare' => '=',
				),
			),
		);

		$the_query = new WP_Query( $query_args );

		if ( 1 === $the_query->post_count ) {
			$wdm_order_id = $the_query->posts[0]->ID;
			$order        = new WC_Order( $wdm_order_id );
			if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) || 'converted' === $order->get_meta( 'wdm_order_converted' ) ) {
				return;
			}
			$prev_tracking_status = get_post_meta( $wdm_order_id, 'wdm_usps_inbound_tracking_status', true );
			if ( $prev_tracking_status === $trackingstatus ) {
				return;
			}
			update_post_meta( $wdm_order_id, 'wdm_usps_inbound_tracking_status', $trackingstatus );
			if ( 'in_transit' !== $trackingstatus && 'delivered' !== $trackingstatus ) {
				return;
			}

			if ( 'delivered' === $trackingstatus ) {
					add_filter(
						'woocommerce_mail_callback',
						function ( $mailer, $obj ) {
							if ( is_a( $obj, 'WC_Email_Customer_Completed_Order' ) ) {
								return function () {
									// Do nothing since we don't want to send an email.
								};
							}
							return $mailer;
						},
						10,
						2
					);
					$order->update_status( 'completed' );
					return;
			}
			if ( wdm_check_order_statuses( 'wc-returned-in-trans' ) ) {
				$order->update_status( 'returned-in-trans' );
				if ( $prev_tracking_status !== $trackingstatus ) {
					$refund_result = $this->process_scheduled_refunds( $order );
					if ( is_wp_error( $refund_result ) ) {
						$order->add_order_note( $refund_result->get_error_message(), 0 );
					}
				}
				return;
			}
			$order->add_order_note( 'Order status wc-returned-in-trans does not exist', 0 );
		}
	}


	/** This function will schedule charges using action scheduler.
	 *
	 * @param WC_Order $order The order for which charges needs to be scheduled.
	 */
	private function wdm_schedule_charges( $order ) {

		$wdm_reminder_email_time = ! empty( $this->ss_options['wdm_email_time'] ) ? intval( $this->ss_options['wdm_email_time'] ) * 86400 : 30 * 86400;
		$wdm_reminder_email_time = time() + $wdm_reminder_email_time;
		$arguments               = array(
			'order_id' => $order->get_id(),
		);

		$wdm_prev_action_timestamp = as_next_scheduled_action( 'wdm_schedule_mail', $arguments );
		if ( is_int( $wdm_prev_action_timestamp ) && ( $wdm_reminder_email_time - $wdm_prev_action_timestamp ) > 86400 ) {
			$ac_id = as_unschedule_action( 'wdm_schedule_mail', $arguments ); // Remove existing scheduled action if any.
			if ( null !== $ac_id ) {
				$wdm_prev_action_timestamp = false;
			}
		}
		if ( false === $wdm_prev_action_timestamp ) {
			as_schedule_single_action( $wdm_reminder_email_time, 'wdm_schedule_mail', $arguments );
		}

		$wdm_return_fee_time = ! empty( $this->ss_options['wdm_charge_fee_time'] ) ? intval( $this->ss_options['wdm_charge_fee_time'] ) * 86400 : 45 * 86400;
		$wdm_return_fee_time = time() + $wdm_return_fee_time;
		$stripe_api_settings = get_option( 'woocommerce_stripe_settings', array() );
		if ( ! empty( $stripe_api_settings ) ) {
			$stripe_sec_key            = $order->get_meta( 'wdm_is_test_mode' ) === 'no' ? $stripe_api_settings['secret_key'] : $stripe_api_settings['test_secret_key'];
			$arguments2                = array(
				'order_id'    => $order->get_id(),
				'customer_id' => $order->get_meta( '_stripe_customer_id', true ),
				'secret_key'  => $stripe_sec_key,
			);
			$wdm_prev_action_timestamp = as_next_scheduled_action( 'wdm_charge_non_return_fee', $arguments2 );
			if ( is_int( $wdm_prev_action_timestamp ) && ( $wdm_return_fee_time - $wdm_prev_action_timestamp ) > 86400 ) {
				$ac_id = as_unschedule_action( 'wdm_charge_non_return_fee', $arguments2 ); // Remove existing scheduled action if any.
				if ( null !== $ac_id ) {
					$wdm_prev_action_timestamp = false;
				}
			}
			if ( false === $wdm_prev_action_timestamp ) {
				as_schedule_single_action( $wdm_return_fee_time, 'wdm_charge_non_return_fee', $arguments2 );
				as_schedule_single_action( $wdm_return_fee_time - 86400 * 2, 'wdm_confirm_inbound_status', array( intval( $order->get_id() ) ) );
				update_post_meta( $order->ID, 'wdm_return_by', $wdm_return_fee_time );
				update_post_meta( $order->ID, 'wdm_return_by_ct', $wdm_return_fee_time - 21600 );
			}
		}
	}

	/** This function removes the shipping labels after a certain time set by the user on setting page.
	 *
	 *  @param int    $order_id ID of the order.
	 *  @param string $file The path of file to delete.
	 */
	public function wdm_remove_old_shipping_labels( $order_id, $file ) {
		if ( wp_is_writable( dirname( $file ) ) ) {
			wp_delete_file( $file );
			update_post_meta( $order_id, 'wdm_labels_deleted', 'yes' );
		}
	}

	/**
	 * This function will remove pages which cannot be merged.
	 *
	 * @param PDFMerger $obj The Object containing pdf files.
	 */
	private function remove_last_added_pdf( $obj ) {
		try {
			$reflection = new ReflectionClass( $obj );
			$property   = $reflection->getProperty( '_files' );
			$property->setAccessible( true );
			$data = $property->getValue( $obj );
			if ( ! is_array( $data ) ) {
				return false;
			}
			array_pop( $data );
			if ( empty( $data ) ) {
				$data = null;
			}
			$property->setValue( $obj, $data );

		} catch ( Exception $e ) {
			return false;
		}
		return true;
	}

	/**
	 * This function will merge the pdf within an PDFMerger Object and generate a test-file to see if merging was successful or not.
	 *
	 * @param PDFMerger $file_obj Object.
	 * @param string    $path path to directory where all the generated labels are stored.
	 */
	private function trial_merging( $file_obj, $path ) {
		try {
			if ( file_exists( $path . '/wdm_temp.pdf' ) ) {
				wp_delete_file( $path . '/wdm_temp.pdf' );
			}
			$file_obj->merge( 'file', $path . '/wdm_temp.pdf' );
			if ( file_exists( $path . '/wdm_temp.pdf' ) ) {
				wp_delete_file( $path . '/wdm_temp.pdf' );
				return true;
			}
		} catch ( Throwable $t ) {
			if ( file_exists( $path . '/wdm_temp.pdf' ) ) {
				wp_delete_file( $path . '/wdm_temp.pdf' );
			}
			return false;
		}
	}

	/** This function will auto generate labels for all the orders via a cron job and merge them into one file based on order type and email the same.
	 *
	 * @param int $page The page.
	 */
	private function auto_generate_labels( $page = 0 ) {
		require_once SS_PATH . 'vendor/PDFMerger-master/PDFMerger.php';
		$unused_obj           = new PDFMerger\PDFMerger();
		$successful_orders    = empty( $_SESSION['successful_orders'] ) ? array() : array_map( 'intval', $_SESSION['successful_orders'] );
		$failed_orders        = empty( $_SESSION['failed_orders'] ) ? array() : array_map( 'intval', $_SESSION['failed_orders'] );
		$merged_failed_orders = empty( $_SESSION['merged_failed_orders'] ) ? array() : array_map( 'intval', $_SESSION['merged_failed_orders'] );
		$files                = get_option( 'wdm_shipping_files', array() );
		$time                 = empty( $_SESSION['wdm_time'] ) ? time() - 1200 : intval( $_SESSION['wdm_time'] );
		$path                 = wp_get_upload_dir();
		$path                 = $path['basedir'] . '/shipping';
		$args                 = array(
			'status'       => array( 'wc-processing' ),
			'type'         => array( 'shop_order' ),
			'date_created' => '<' . $time,
			'limit'        => 5,
			'exclude'      => array_unique( array_merge( $failed_orders, $merged_failed_orders ) ),
			'paginate'     => true,
		);
		$orders               = wc_get_orders( $args );
		$orders               = $orders->orders;
		foreach ( $orders as $order ) {
			$nonce             = wp_create_nonce( 'secret' );
			$_REQUEST['nonce'] = $nonce;
			$_POST['order_id'] = $order->get_id();
			try {
				$this->wdm_order_created();
			} catch ( Exception $e ) {
				wdm_error_log( $e->getMessage() );
				if ( 'Error merging pdf' === $e->getMessage() ) {
					array_push( $merged_failed_orders, $order->get_id() );
					continue;
				}
				array_push( $failed_orders, $order->get_id() );
				continue;
			}
			array_push( $successful_orders, $order->get_id() );
			$machine_type = $order->get_meta( 'machine_type' );
			if ( ! empty( $machine_type ) ) {
				$printing_line      = get_term_meta( intval( $machine_type ), 'associated_printing_line_id', true );
				$printing_line_term = get_term( intval( $printing_line ), 'printing-line' );
				$printing_line_name = $printing_line_term->name;
			} else {
				$printing_line      = get_option( 'default_printing-line', false );
				$printing_line_term = get_term( intval( $printing_line ), 'printing-line' );
				$printing_line_name = $printing_line_term->name;
			}
			$order_type = 'No-Exchange';
			if ( 0 !== intval( $order->get_meta( 'wdm_returnable_item' ) || wdm_get_returnable_items_for_order( $order ) > 0 ) ) {
				$order_type = 'Exchange';
			}
			if ( ! isset( $files[ $order_type . '-' . $printing_line_name ] ) ) {
				$files[ $order_type . '-' . $printing_line_name ] = new PDFMerger\PDFMerger();
			}
			$order_id = $order->get_id();
			$flag1    = get_post_meta( $order_id, 'merged-shipping-label-generated', true );
			if ( '1' === $flag1 ) {
				$label = $path . '/' . $order_id . '.pdf';
			} else {
				$label = $path . '/' . $order_id . ' outbound.pdf';
			}
			$files[ $order_type . '-' . $printing_line_name ]->addPDF( $label );
			$res = $this->trial_merging( $files[ $order_type . '-' . $printing_line_name ], $path );
			if ( ! $res ) {
				array_push( $merged_failed_orders, $order_id );
				$removed = $this->remove_last_added_pdf( $files[ $order_type . '-' . $printing_line_name ] );
			}
		}
		if ( empty( $orders ) ) {
			if ( empty( $merged_failed_orders ) && empty( $failed_orders ) && empty( $files ) ) {
				wdm_error_log( 'No orders were present during the time of label generation' );
				return;
			}
			$final_file_array = array();
			$file_urls        = array();
			foreach ( $files as $key => $file ) {
					$file_to_email = $path . '/' . $key . '.pdf';
				if ( file_exists( $file_to_email ) ) {
					wp_delete_file( $file_to_email );
				}
				try {
					$files[ $key ]->merge( 'file', $file_to_email );
				} catch ( Throwable $e ) {
					if ( $e->getMessage() !== 'Class "PDFMerger\exception" not found' ) {
						wdm_error_log( 'Something went wrong' );
						wdm_error_log( $e->getMessage() );
					}
				}
				array_push( $final_file_array, $file_to_email );
				$file_urls[ $key ] = wp_upload_dir()['baseurl'] . '/shipping/' . $key . '.pdf';
			}
			$this->send_email_with_attachments( $final_file_array, $file_urls, $failed_orders, $merged_failed_orders );
		} else {
			$_SESSION['failed_orders']        = $failed_orders;
			$_SESSION['merged_failed_orders'] = $merged_failed_orders;
			update_option( 'wdm_shipping_files', $files, false );
			$_SESSION['successful_orders'] = $successful_orders;
			$_SESSION['wdm_time']          = $time;
			$url                           = get_site_url( null, 'wp-json/ss/label-gen' );
			$page                          = ++$page;
			$signature                     = hash_hmac( 'sha256', $page, 'your-secret-here' );
			$url                           = $url . '?lb=' . $signature . '&page=' . $page;
			return new WP_REST_Response(
				'',
				302,
				array(
					'Location' => $url,
				)
			);
		}
	}


	/** This function will email the attachments.
	 *
	 *  @param array $final_file_array files to attach.
	 *  @param array $file_urls files urls.
	 *  @param array $failed_orders contains id of orders failed to generate label.
	 *  @param array $merged_failed_orders contains id of orders failed to merge.
	 */
	private function send_email_with_attachments( $final_file_array, $file_urls, $failed_orders, $merged_failed_orders ) {
		$option        = get_option( 'custom_plugin_options' );
		$lost_packages = get_option( 'ss_lost_packages' );
		$to            = $option['wdm_merged_label_email'];
		$subject       = 'Please find the labels in the email attachment';
		$message       = '';
		if ( empty( $merged_failed_orders ) && empty( $failed_orders ) ) {
			$message = 'Label generation for all the orders were successful';
		}
		if ( ! empty( $failed_orders ) ) {
			$message = 'Label generation failed for following orders:<br>' . implode( '<br>', $failed_orders );
		}
		if ( ! empty( $merged_failed_orders ) ) {
			$message .= '<br>Label merging failed for following orders:<br>' . implode( '<br>', $merged_failed_orders );
		}
		if ( ! empty( $lost_packages ) ) {
			$message .= '<br>Lost Outbound packages:<br>' . implode( '<br>', $lost_packages );
		}
		if ( ! empty( $file_urls ) && is_array( $file_urls ) ) {
			$time      = time();
			$file_urls = array_map( fn( $fkey, $file_url ) => "<a href='{$file_url}?time={$time}'>{$fkey}</a>", array_keys( $file_urls ), array_values( $file_urls ) );
			$message  .= '<br><br> Please find the labels via the following link:<br>' . implode( '<br>', $file_urls );
		}
		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$attachments = $final_file_array;
		wp_mail( $to, $subject, $message, $headers, $attachments );
	}


	/**
	 * Process scheduled refunds for an order.
	 *
	 * @param WC_Order $order The order object to process scheduled refunds for.
	 *
	 * @return void|WP_Error
	 */
	private function process_scheduled_refunds( $order ) {
		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			return new WP_Error( 500, 'Invalid order object provided.' );
		}
		$raised_refunds = $order->get_meta( 'scheduled_refund_obj' );
		if ( empty( $raised_refunds ) || ! is_array( $raised_refunds ) ) {
			return new WP_Error( 500, 'No raised refunds found.' );
		}
		foreach ( $raised_refunds as $key => $raised_refund ) {
			$refund_id = $raised_refund->refund_id;
			$args      = $raised_refund->args;
			try {
				$refund = new WC_Order_Refund( $refund_id );
			} catch ( Exception $e ) {
				$order->add_order_note( 'Refund ID ' . $refund_id . ' Not Found', 0 );
				continue;
			}
			if ( $refund->get_meta( '_refunded_payment' ) === true ) {
				continue;
			}
			$remaining_refund_amount = $order->get_remaining_refund_amount();
			try {
				if ( $args['refund_payment'] ) {
					$result = wc_refund_payment( $order, $refund->get_amount(), $refund->get_reason() );

					if ( is_wp_error( $result ) ) {
						$error = $result->get_error_message();
						update_post_meta( $refund_id, 'wdm_refund_failed', $error );
						continue;
					}

					$refund->set_refunded_payment( true );
					$refund->save();
				}
				if ( $args['restock_items'] ) {
					wc_restock_refunded_items( $order, $args['line_items'] );
				}
				if ( ( $remaining_refund_amount - $args['amount'] ) > 0 ) {
					$order->set_date_modified( time() );
					$order->save();
					try {
						wdm_remove_scheduled_actions( $order->get_id(), $order ); // Even in case of partially refunded, remove the scheduled actions.
					} catch ( Exception $e ) {
						$order->add_order_note( 'The scheduled refund was successfully processed but ' . $e->getMessage(), 0 );
					}
				} else {
					$order->update_status( 'refunded' );
					$order->set_date_modified( time() );
					$order->save();
					try {
						wdm_remove_scheduled_actions( $order->get_id(), $order );
					} catch ( Exception $e ) {
						$order->add_order_note( 'The scheduled refund was successfully processed but ' . $e->getMessage(), 0 );
					}
				}
			} catch ( Exception $e ) {
				$path = wp_get_upload_dir();
				if ( wp_is_writable( $path['basedir'] ) ) {
					$message = $e->getMessage();
					$path    = $path['basedir'] . '/scheduled_refunds.log';
					error_log( print_r( $message, true ), 3, $path ); // @codingStandardsIgnoreLine
				}
			}
		}
	}

	/**
	 * Confirm the inbound status of an order based on the tracking partner.
	 *
	 * @param int $order_id The order id.
	 *
	 * @return void
	 */
	public function wdm_confirm_inbound_status( int $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) || empty( $order_id ) || ! is_int( $order_id ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( empty( $order ) || ! is_a( $order, 'WC_Order' ) ) {
			return;
		}
		$tracking_partner = wdm_get_inbound_tracking_partner( $order );
		$tracking_status  = wdm_get_inbound_tracking_status( $order );
		if ( ! in_array( $tracking_partner, array( 'easypost', 'usps', 'shippo' ), true ) ) {
			return;
		}
		$this->{$tracking_partner . '_api_tracking'}( $tracking_status, $order_id );
	}
}
