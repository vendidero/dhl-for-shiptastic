<?php

namespace Vendidero\Shiptastic\DHL\ShippingProvider\Services;

use Vendidero\Shiptastic\ShipmentError;
use Vendidero\Shiptastic\ShippingProvider\Service;

defined( 'ABSPATH' ) || exit;

class PreferredLocation extends Service {

	public function __construct( $shipping_provider, $args = array() ) {
		$args = array(
			'id'                 => 'PreferredLocation',
			'label'              => _x( 'Drop-off location', 'dhl', 'dhl-for-shiptastic' ),
			'description'        => _x( 'Enable drop-off location delivery.', 'dhl', 'dhl-for-shiptastic' ),
			'long_description'   => '<div class="wc-shiptastic-additional-desc ">' . _x( 'Enabling this option will display options for the user to select their preferred delivery location during the checkout.', 'dhl', 'dhl-for-shiptastic' ) . '</div>',
			'setting_id'         => 'PreferredLocation_enable',
			'products'           => array( 'V01PAK', 'V62WP', 'V62KP' ),
			'countries'          => array( 'DE' ),
			'zones'              => array( 'dom' ),
			'excluded_locations' => array( 'settings' ),
		);

		parent::__construct( $shipping_provider, $args );
	}

	protected function get_additional_label_fields( $shipment ) {
		$label_fields = parent::get_additional_label_fields( $shipment );
		$dhl_order    = wc_stc_dhl_get_order( $shipment->get_order() );
		$value        = '';

		if ( $dhl_order && $dhl_order->has_preferred_location() ) {
			$value = $dhl_order->get_preferred_location();
		}

		$label_fields = array_merge(
			$label_fields,
			array(
				array(
					'id'                => $this->get_label_field_id( 'location' ),
					'label'             => _x( 'Drop-off location', 'dhl', 'dhl-for-shiptastic' ),
					'placeholder'       => '',
					'description'       => '',
					'value'             => $value,
					'custom_attributes' => array(
						'maxlength' => '80',
						'data-show-if-service_PreferredLocation' => '',
					),
					'type'              => 'text',
					'is_required'       => true,
				),
			)
		);

		return $label_fields;
	}

	public function book_as_default( $shipment ) {
		$book_as_default = parent::book_as_default( $shipment );

		if ( false === $book_as_default ) {
			$dhl_order = wc_stc_dhl_get_order( $shipment->get_order() );

			if ( $dhl_order && $dhl_order->has_preferred_location() ) {
				$book_as_default = true;
			}
		}

		return $book_as_default;
	}
}
