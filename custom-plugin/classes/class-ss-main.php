<?php
/**
 * This file is the starting point of the plugin.
 *
 * @package custom-plugin
 */

/**
 * SS_Main Class
 */
class SS_Main {
	/**
	 * The instance of the class.
	 *
	 * @var SS_Main The instance of the class.
	 */
	private static $instance;

	/**
	 * Gets the instance of the class.
	 *
	 * This method creates the instance of the class if it does not exist yet.
	 * If the instance already exists, it will be returned.
	 *
	 * @return SS_Main The instance of the class.
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
		add_action( 'wp', array( $this, 'wdm_check_payment_methods' ), 10 );
		add_action( 'init', array( $this, 'wdm_check_required_order_status' ), 10 );
		add_filter( 'wp_mail_content_type', fn() => 'text/html', 10 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'show_user_profile', array( $this, 'wdm_add_soda_machine_field_to_edit_user_profile' ) );
		add_action( 'edit_user_profile', array( $this, 'wdm_add_soda_machine_field_to_edit_user_profile' ) );
		add_action( 'personal_options_update', array( $this, 'wdm_save_soda_machine_field_from_edit_user_profile' ) );
		add_action( 'edit_user_profile_update', array( $this, 'wdm_save_soda_machine_field_from_edit_user_profile' ) );
	}

	/**
	 * This function restricts the deletion of default payment method.
	 */
	public function wdm_check_payment_methods() {
		global $wp;
		if ( isset( $wp->query_vars['delete-payment-method'] ) ) {
			$customer_id = WC()->session->get_customer_id();

			$all_payment_methods = WC_Payment_Tokens::get_customer_tokens( $customer_id );
			if ( count( $all_payment_methods ) > 1 ) {
				$token_id = absint( $wp->query_vars['delete-payment-method'] );
				$token    = WC_Payment_Tokens::get( $token_id );
				if ( $token->is_default() ) {
					wc_add_notice( __( 'Kindly change the default payment method before deleting the current one', 'woocommerce' ), 'error' );
					wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
					exit();
				}
				return;
			} else {
				wc_add_notice( __( 'Mandatory to have one payment method active', 'woocommerce' ), 'error' );
				wp_safe_redirect( wc_get_account_endpoint_url( 'payment-methods' ) );
				exit();
			}
		}
	}

	/**
	 * This function checks if the custom order statuses wc-returned-in-trans, wc-shipped, and wc-awaiting-returns exist.
	 * If any of them do not exist, it will display an admin notice.
	 *
	 * @since 2.0
	 */
	public function wdm_check_required_order_status() {
		$custom_statuses = array(
			'wc-returned-in-trans',
			'wc-shipped',
			'wc-awaiting-returns',
		);
		foreach ( $custom_statuses as $status ) {
			if ( ! wdm_check_order_statuses( $status ) ) {
				add_action(
					'admin_notices',
					function () use ( $status ) {
						echo '<div class="error"><p>' . sprintf( 'Required custom woocommerce status %s not found', esc_html( $status ) ) . '</p></div>';
					}
				);
				break;
			}
		}
	}

	/**
	 * Register the stylesheets for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_styles() {
		global $ss_wdm_version;
		if ( ! is_numeric( $ss_wdm_version ) ) {
			$ss_wdm_version = '1.0.0';
		}
		wp_enqueue_style( 'wdm-custom-style', SS_URL . 'assets/style.css', array(), $ss_wdm_version, 'all' );
	}


	/**
	 * Register the JavaScript for the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function enqueue_scripts() {
		global $ss_wdm_version;
		if ( ! is_numeric( $ss_wdm_version ) ) {
			$ss_wdm_version = '1.0.0';
		}
		wp_enqueue_script( 'wdm-custom-js', SS_URL . 'assets/script.js', array( 'jquery' ), $ss_wdm_version, false );

		wp_localize_script(
			'wdm-custom-js',
			'ajaxobj',
			array(
				'url'   => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'secret' ),
			)
		);
	}

	/**
	 * Adds a field to the edit user profile page to select the soda machine brand/cylinder type.
	 *
	 * This method adds a dropdown field to the edit user profile page to select the soda machine
	 * brand/cylinder type. The selected value is then saved in the user meta.
	 *
	 * @param WP_User $user The user object.
	 */
	public function wdm_add_soda_machine_field_to_edit_user_profile( $user ) {
		$current_soda_machine_value = get_user_meta( $user->ID, 'wdm_default_machine_type', true );
		$current_return_partner     = get_user_meta( $user->ID, 'wdm_return_partner', true );
		$terms                      = get_terms(
			array(
				'taxonomy'   => 'machine-type',
				'hide_empty' => false,
			)
		);
		?>
		<h3><?php esc_html_e( 'Soda Machine Information', 'code-sample' ); ?></h3>
		<table class="form-table" aria-label="Soda Machine">
			<tr>
				<th><label for="sodamachine"><?php esc_html_e( 'Soda Machine', 'code-sample' ); ?></label></th>
				<td>
					<select name="sodamachine" id="sodamachine">
					<option value=""><?php esc_html_e( 'Select Soda Machine', 'code-sample' ); ?></option>
						<?php foreach ( $terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $current_soda_machine_value, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
		</table>
		<h3><?php esc_html_e( 'Return Partner Information', 'code-sample' ); ?></h3>
		<table class="form-table" aria-label="Soda Machine">
			<tr>
				<th><label for="sodamachine"><?php esc_html_e( 'Return Partner', 'code-sample' ); ?></label></th>
				<td>
					<select name="return_partner" id="return_partner">
						<option value="usps" 
							<?php
								echo ! empty( $current_return_partner ) ? selected(
									$current_return_partner,
									'usps',
									false
								) : 'selected';
							?>
						>
							<?php esc_html_e( 'USPS', 'code-sample' ); ?>
						</option>
						<!-- <option value="fedex" <?php // selected( $current_return_partner, 'fedex' ); //@codingStandardsIgnoreLine ?>><?php // esc_html_e( 'Fedex', 'code-sample' ); //@codingStandardsIgnoreLine ?></option> --> 
					</select>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Saves the soda machine field value from the edit user profile page.
	 *
	 * This method saves the selected soda machine value in the user meta.
	 * It checks if the current user can edit the user profile before saving the value.
	 *
	 * @param int $user_id The ID of the user.
	 * @return void
	 */
	public function wdm_save_soda_machine_field_from_edit_user_profile( $user_id ) {
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}

		if ( isset( $_POST['sodamachine'] ) ) { //@codingStandardsIgnoreLine
			$soda_machine_data = sanitize_text_field( wp_unslash( $_POST['sodamachine'] ) ); //@codingStandardsIgnoreLine
			update_user_meta( $user_id, 'wdm_default_machine_type', $soda_machine_data );
		}

		if ( isset( $_POST['return_partner'] ) ) { //@codingStandardsIgnoreLine
			$return_partner_data = sanitize_text_field( wp_unslash( $_POST['return_partner'] ) ); //@codingStandardsIgnoreLine
			if ( empty( $return_partner_data ) || ! in_array( $return_partner_data, array( 'usps', 'fedex' ), true ) ) {
				$return_partner_data = 'usps';
			}
			$return_partner_data = 'usps'; // Added intentionally and can be removed once the fedex label size issue is resolved.
			update_user_meta( $user_id, 'wdm_return_partner', $return_partner_data );
		}
	}
}
