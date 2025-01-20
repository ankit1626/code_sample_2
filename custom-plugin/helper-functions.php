<?php
/**
 * This file contains all the helper functions.
 *
 * @package helper-functions
 */

if ( ! function_exists( 'wdm_error_log' ) ) {
	/**
	 * Writes a message to a log file.
	 *
	 * The file is located in the uploads directory and is named 'api.log'.
	 *
	 * @param mixed $message The message to write to the log file.
	 * @param bool  $is_return_log Is the log file for returns.
	 */
	function wdm_error_log( mixed $message, bool $is_return_log = false ) {
		$path = wp_get_upload_dir();
		if ( wp_is_writable( $path['basedir'] ) ) {
			$path = $is_return_log ? $path['basedir'] . '/returns.log' : $path['basedir'] . '/api.log';
			error_log( print_r( $message, true ), 3, $path ); // @codingStandardsIgnoreLine
		}
	}
}
if ( ! function_exists( 'wdm_get_returnable_items_for_order' ) ) {
	/**
	 * This function counts the number of returnable items in a order.
	 *
	 * @param WC_Order $order woocommerce order.
	 */
	function wdm_get_returnable_items_for_order( $order ) {
		if ( empty( $order ) ) {
			return 0;
		}
		if ( 'converted' === $order->get_meta( 'wdm_order_converted' ) ) {
			return 0;
		}
		$eligible_shipping_classes = get_option( 'wdm_eligible_shipping_classes', array() );

		$quantity = 0;
		if ( empty( $eligible_shipping_classes ) && ! is_array( $eligible_shipping_classes ) ) {
			$eligible_shipping_classes = array();
		}
		$items = $order->get_items( 'line_item' );

		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( in_array( $product->get_shipping_class(), $eligible_shipping_classes, true ) ) {
				$quantity = $quantity + $item->get_quantity();
			}
		}

		return $quantity;
	}


	if ( ! function_exists( 'wdm_check_order_statuses' ) ) {

		/** This function check the if the mentioned order status exists ot not.
		 *
		 *  @param string $status status we are checking.
		 */
		function wdm_check_order_statuses( $status ) {
			$available_statuses = wc_get_order_statuses();
			if ( array_key_exists( $status, $available_statuses ) ) {
				return true;
			}
			return false;
		}
	}

	if ( ! function_exists( 'wdm_remove_scheduled_actions' ) ) {
		/**
		 * Removes scheduled actions for non-return fee and mail notification.
		 *
		 * If $reschedule is true, this function will reschedule the actions if they are already scheduled.
		 * If the actions are already scheduled, it will unschedule them and throw an exception.
		 *
		 * @param int    $order_id ID of the order.
		 * @param object $order    WooCommerce order object.
		 * @param bool   $reschedule Whether to reschedule the actions or not.
		 *
		 * @throws Exception If unable to unschedule the previous non-return fee.
		 * @throws Exception If unable to extend the time for scheduled mail.
		 * @throws Exception If unable to unschedule the previous non-return mail.
		 */
		function wdm_remove_scheduled_actions( $order_id, $order, $reschedule = false ) {
			$option              = get_option( 'custom_plugin_options' );
			$extended_time       = ! empty( $option['wdm_extend_returns_time'] ) ? intval( $option['wdm_extend_returns_time'] ) * 86400 : 30 * 86400;
			$stripe_api_settings = get_option( 'woocommerce_stripe_settings', array() );
			if ( ! empty( $stripe_api_settings ) ) {
				$stripe_sec_key = $order->get_meta( 'wdm_is_test_mode' ) === 'no' ? $stripe_api_settings['secret_key'] : $stripe_api_settings['test_secret_key'];

				$arguments = array(
					'order_id'    => intval( $order_id ),
					'customer_id' => $order->get_meta( '_stripe_customer_id', true ),
					'secret_key'  => $stripe_sec_key,
				);
			}

			if ( true === $reschedule ) {
				$timestamp = as_next_scheduled_action( 'wdm_charge_non_return_fee', $arguments );
				if ( is_bool( $timestamp ) ) {
					throw new Exception( 'The charge action not found or it is already in process' );
				}
				if ( is_int( $timestamp ) ) {
					$timestamp = $timestamp + $extended_time;
					$action_id = as_schedule_single_action( $timestamp, 'wdm_charge_non_return_fee', $arguments );
					if ( 0 === $action_id ) {
						throw new Exception( 'Unable to extend return period' );
					}
					update_post_meta( $order_id, 'wdm_return_by', $timestamp );
					update_post_meta( $order_id, 'wdm_return_by_ct', $timestamp - 21600 );
					$customer_email = $order->get_billing_email();
					$subject        = $option['wdm_return_period_extended_subject'];
					$body           = $option['wdm_return_period_extended_body'];
					$body           = wdm_replace_placeholders( $body, $order );
					wp_mail( $customer_email, $subject, $body );
				}
			}
			$action_id = as_unschedule_action( 'wdm_charge_non_return_fee', $arguments );
			if ( null === $action_id ) {
				throw new Exception( 'Unable to unschedule the previous non-return fee.' );
			}
			$arguments = array(
				'order_id' => intval( $order_id ),
			);
			if ( true === $reschedule ) {
				$timestamp = as_next_scheduled_action( 'wdm_schedule_mail', $arguments );
				if ( is_bool( $timestamp ) ) {
					throw new Exception( 'The mail action not found or it is already in process' );
				}
				if ( is_int( $timestamp ) ) {
					$timestamp = $timestamp + $extended_time;
					$action_id = as_schedule_single_action( $timestamp, 'wdm_schedule_mail', $arguments );
					if ( 0 === $action_id ) {
						throw new Exception( 'Unable to extend the time for scheduled mail' );
					}
				}
			}
			$action_id = as_unschedule_action( 'wdm_schedule_mail', $arguments );
			if ( null === $action_id ) {
				throw new Exception( 'Unable to unschedule the previous non-return mail.' );
			}
		}
	}

	if ( ! function_exists( 'wdm_get_inbound_tracking_status' ) ) {
		/**
		 * Gets the inbound tracking status of a given order.
		 *
		 * This function will try to get the inbound tracking status
		 * from the order's meta data. It will first try to get it from
		 * the key 'wdm_tracking_status_inbound', then from
		 * 'wdm_usps_inbound_tracking_status', and finally from
		 * 'wdm_shippo_inbound_tracking_status'.
		 *
		 * @param WC_Order $order The order object.
		 *
		 * @return string The inbound tracking status.
		 */
		function wdm_get_inbound_tracking_status( WC_Order $order ) {
			$keys = array(
				'wdm_tracking_status_inbound',
				'wdm_usps_inbound_tracking_status',
				'wdm_shippo_inbound_tracking_status',
			);
			foreach ( $keys as $key ) {
				if ( $order->meta_exists( $key ) ) {
					return $order->get_meta( $key );
				}
			}
			return '';
		}
	}

	if ( ! function_exists( 'wdm_get_inbound_tracking_partner' ) ) {
		/**
		 * Gets the inbound tracking status of a given order.
		 *
		 * This function will try to get the inbound tracking status
		 * from the order's meta data. It will first try to get it from
		 * the key 'wdm_tracking_status_inbound', then from
		 * 'wdm_usps_inbound_tracking_status', and finally from
		 * 'wdm_shippo_inbound_tracking_status'.
		 *
		 * @param WC_Order $order The order object.
		 *
		 * @return string The inbound tracking status.
		 */
		function wdm_get_inbound_tracking_partner( WC_Order $order ) {
			$keys = array(
				'easypost_tracking_id'               => 'easypost',
				'usps_tracking_id'                   => 'usps',
				'wdm_shippo_inbound_tracking_number' => 'shippo',
			);
			foreach ( $keys as $key => $value ) {
				if ( $order->meta_exists( $key ) ) {
					return $value;
				}
			}
			return '';
		}
	}
	if ( ! function_exists( 'wdm_replace_placeholders' ) ) {
		/**
		 * This function replaces the placeholders in the subject.
		 *
		 * @param string   $subject Content containing terms which should be replaced.
		 * @param WC_Order $order Contains values with which the terms should be replaced.
		 */
		function wdm_replace_placeholders( $subject, $order ) {
			$option          = get_option( 'custom_plugin_options' );
			$new_return_date = get_post_meta( intval( $order->get_id() ), 'wdm_return_by_ct', true );
			if ( empty( $new_return_date ) ) {
				$new_return_date = '';
			} else {
				$new_return_date = date( 'jS F Y', intval( $new_return_date ) );//@codingStandardsIgnoreLine
			}
			$search_keys = array(
				'%customer_first_name%',
				'%customer_last_name%',
				'%customer_email%',
				'%customer_order_id%',
				'%new_return_date%',
				'%days_extended_by%',
			);

			$link = '<a href="' . $order->get_view_order_url() . '">' . $order->get_id() . '</a>';

			$replacement_values = array(
				$order->get_billing_first_name(),
				$order->get_billing_last_name(),
				$order->get_billing_email(),
				$link,
				$new_return_date,
				$option['wdm_extend_returns_time'],
			);

			$subject = str_replace( $search_keys, $replacement_values, $subject );
			$subject = wpautop( $subject, true );
			return $subject;
		}
	}
}
