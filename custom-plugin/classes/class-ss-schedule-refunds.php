<?php
/**
 * This file contains code related to schedule refunds for orders.
 *
 * @package custom-plugin
 */

session_start();
/**
 * Class SS_Schedule_Refunds
 */
class SS_Schedule_Refunds {

	/**
	 * Summary of instance
	 *
	 * @var SS_Schedule_Refunds
	 */
	private static $instance = null;

	/**
	 * Gets the instance of the class.
	 *
	 * This method creates the instance of the class if it does not exist yet.
	 * If the instance already exists, it will be returned.
	 *
	 * @return SS_Schedule_Refunds The instance of the class.
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor for the SS_Schedule_Refunds.
	 *
	 * This function sets up the necessary hooks for the class.
	 *
	 * @return void
	 */
	private function __construct() {
		add_action( 'woocommerce_order_item_add_action_buttons', array( $this, 'wdm_add_schedule_refund_btn' ), 10, 1 );
		add_action( 'wp_ajax_wdm_refund_order', array( $this, 'wdm_refund_order' ) );
		add_action( 'wp_ajax_wdm_check_session', array( $this, 'wdm_check_for_session' ) );
		add_action( 'wp_ajax_wdm_include_non_return_fee', array( $this, 'wdm_set_session_for_checkbox' ) );
		add_action( 'woocommerce_create_refund', array( $this, 'wdm_modify_args' ), 10, 2 );
		add_action( 'woocommerce_after_order_refund_item_name', array( $this, 'wdm_show_refund_status' ), 10, 1 );
	}


	/**
	 * Handles the AJAX request to store the status of the checkbox for excluding non-returnable items.
	 *
	 * @throws WP_Error If the order ID is not provided or is invalid.
	 * @return void
	 */
	public function wdm_set_session_for_checkbox() {
		check_ajax_referer( 'secret', 'nonce' );
		if ( ! isset( $_POST['order_id'] ) || empty( $_POST['order_id'] ) ) {
			wp_send_json_error( 'Please provide valid order id', 500 );
		}
		$order_id = absint( $_POST['order_id'] );
		if ( ! isset( $order_id ) || empty( $order_id ) ) {
			wp_send_json_error( 'Please provide valid order id', 500 );
		}
		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			wp_send_json_error( 'Please provide valid order id', 500 );
		}
		if ( isset( $_POST['wdm_checked'] ) && ! empty( $_POST['wdm_checked'] ) && 'true' === $_POST['wdm_checked'] ) {
			$_SESSION['wdm_checkbox_checked'] = true;
		} else {
			$_SESSION['wdm_checkbox_checked'] = false;
		}
	}
	/**
	 * Handles the AJAX request to check if a refund session exists for an order.
	 *
	 * @throws WP_Error If the order ID is not provided or is invalid.
	 * @return void
	 */
	public function wdm_check_for_session() {
		check_ajax_referer( 'secret', 'nonce' );
		if ( ! isset( $_POST['order_id'] ) || empty( $_POST['order_id'] ) ) {
			wp_send_json_error( 'Please provide valid order id', 500 );
		}
		$order_id = absint( $_POST['order_id'] );
		if ( ! isset( $order_id ) || empty( $order_id ) ) {
			wp_send_json_error( 'Please provide valid order id', 500 );
		}
		$order = wc_get_order( $order_id );
		if ( ! is_a( $order, 'WC_Order' ) ) {
			wp_send_json_error( 'Please provide valid order id', 500 );
		}
		if ( isset( $_SESSION[ 'scheduled_refund_' . $order_id ] ) ) {
			unset( $_SESSION[ 'scheduled_refund_' . $order_id ] );
		}
		if ( isset( $_SESSION['wdm_checkbox_checked'] ) && wdm_get_returnable_items_for_order( $order ) > 0 && true === $_SESSION['wdm_checkbox_checked'] ) {
			$_SESSION['refund_ret_fee'] = true;
		} else {
			$_SESSION['refund_ret_fee'] = false;
		}
		wp_send_json_success( '', 200 );
	}

	/**
	 * Displays the refund status of a given WC_Order_Refund object.
	 *
	 * @param WC_Order_Refund $refund The refund object to display status for.
	 * @return void
	 */
	public function wdm_show_refund_status( $refund ) {
		$refund_sts = $refund->get_meta( '_refunded_payment' );
		$refund_err = $refund->get_meta( 'wdm_refund_failed', true );
		if ( ! empty( $refund_err ) ) {
			echo '<mark class="order-status status-failed"><span>Error</span></mark>';
			return;
		}
		if ( isset( $refund_sts ) && empty( $refund_sts ) || false === $refund_sts ) {
			echo '<mark class="order-status status-processing"><span>Scheduled</span></mark>';
		} else {
			echo '<mark class="order-status status-processing"><span>Processed</span></mark>';
		}
	}
	/**
	 * Adds a schedule refund button based on the order details.
	 *
	 * @param object $order The order object to determine if the refund button should be displayed.
	 */
	public function wdm_add_schedule_refund_btn( $order ) {
		$order_status = $order->get_status();
		if ( in_array( $order_status, array( 'shipped', 'awaiting-returns' ), true ) && wdm_get_returnable_items_for_order( $order ) > 0 && ( 0 < $order->get_total() - $order->get_total_refunded() || 0 < absint( $order->get_item_count() - $order->get_item_count_refunded() ) ) ) {
			?>
				<button type="button" data-order_id="<?php echo esc_attr( $order->get_id() ); ?>" id="wdm-refund-order" class="button"><?php esc_html_e( 'Schedule Refund', ' code-sample' ); ?></button>
				<?php
		}
	}

	/**
	 * Handles the AJAX request to schedule a refund for an order.
	 *
	 * @throws Exception If the order ID is not provided.
	 * @return void
	 */
	public function wdm_refund_order() {
		check_ajax_referer( 'secret', 'nonce' );
		if ( ! isset( $_POST['order_id'] ) || empty( $_POST['order_id'] ) ) {
			wp_send_json_error( 'Please provide valid order id', 500 );
		}
		$order_id = absint( $_POST['order_id'] );
		if ( ! isset( $_SESSION[ 'scheduled_refund_' . $order_id ] ) ) {
			$_SESSION[ 'scheduled_refund_' . $order_id ] = true;
		}
		wp_send_json_success( '', 200 );
	}

	/**
	 * Modify the arguments for a refund and store the refund details in the session.
	 *
	 * @param WC_Order_Refund $refund The refund object.
	 * @param array           $args The arguments for the refund.
	 * @return void
	 */
	public function wdm_modify_args( $refund, $args ) {
		$order_id = $args['order_id'];
		if ( empty( $order_id ) ) {
			return;
		}

		if ( ! isset( $_SESSION[ 'scheduled_refund_' . $order_id ] ) && true !== $_SESSION[ 'scheduled_refund_' . $order_id ] ) {
			return;
		}
		$refund_id      = $refund->get_id();
		$obj            = new stdClass();
		$obj->refund_id = $refund_id;
		$obj->args      = $args;
		unset( $_SESSION[ 'scheduled_refund_' . $order_id ] );
		$raised_refunds = get_post_meta( $order_id, 'scheduled_refund_obj', true );
		if ( ! empty( $raised_refunds ) ) {
			$raised_refunds = array_merge( $raised_refunds, array( $obj ) );
		} else {
			$raised_refunds = array( $obj );
		}
		update_post_meta( $order_id, 'scheduled_refund_obj', $raised_refunds );
		wp_send_json_success( 'Refund Scheduled Successfully' );
	}
}
