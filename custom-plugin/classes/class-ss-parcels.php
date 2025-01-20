<?php
/**
 * This file contains all the code related to Parcel.
 *
 * @package custom-plugin.
 */

/**
 * This file contains all the code related to Parcel.
 */
trait SS_Parcels {
	/**
	 * The parcel length. It will always be in inches.
	 *
	 * @var string
	 */
	protected string $parcel_length;
	/**
	 * The parcel width. It will always be in inches.
	 *
	 * @var string
	 */
	protected string $parcel_width;
	/**
	 * The parcel height. It will always be in inches.
	 *
	 * @var string
	 */
	protected string $parcel_height;
	/**
	 * The parcel weight. It will always be in ounces.
	 *
	 * @var string
	 */
	protected string $parcel_weight;
	/**
	 * The parcel weight in return. It will always be in ounces.
	 *
	 * @var string
	 */
	protected string $parcel_weight_in_return;

	/**
	 * Set parcel dimensions and weights from ss options.
	 *
	 * Read the parcel dimensions and weights from ss options and
	 * assign them to instance variables.
	 */
	protected function initialize_parcels() {
		$ss_options                    = get_option( 'custom_plugin_options', array() );
		$this->parcel_length           = isset( $ss_options['wdm_shippo_parcel_length'] ) ? $ss_options['wdm_shippo_parcel_length'] : '';
		$this->parcel_width            = isset( $ss_options['wdm_shippo_parcel_width'] ) ? $ss_options['wdm_shippo_parcel_width'] : '';
		$this->parcel_height           = isset( $ss_options['wdm_shippo_parcel_height'] ) ? $ss_options['wdm_shippo_parcel_height'] : '';
		$this->parcel_weight           = isset( $ss_options['wdm_shippo_parcel_weight'] ) ? $ss_options['wdm_shippo_parcel_weight'] : '';
		$this->parcel_weight_in_return = isset( $ss_options['wdm_shippo_parcel_weight_in_return'] ) ? $ss_options['wdm_shippo_parcel_weight_in_return'] : '';
	}
}
