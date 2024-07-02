<?php

namespace Vendidero\Germanized\DHL\ShippingProvider;

use Vendidero\Germanized\DHL\Package;
use Vendidero\Germanized\DHL\ParcelLocator;

defined( 'ABSPATH' ) || exit;

trait PickupDeliveryTrait {

	public function supports_pickup_location_delivery( $address, $query_args = array() ) {
		$query_args = $this->parse_pickup_location_query_args( $query_args );
		$address    = $this->parse_pickup_location_address_args( $address );
		$types      = $this->get_pickup_location_types();
		$supports   = in_array( $address['country'], ParcelLocator::get_supported_countries(), true ) && ! empty( $types ) && ! in_array( $query_args['payment_gateway'], ParcelLocator::get_excluded_gateways(), true );

		return $supports;
	}

	protected function parse_pickup_location_query_args( $query_args ) {
		$query_args = wp_parse_args(
			$query_args,
			array(
				'limit' => min( absint( Package::get_setting( 'parcel_pickup_max_results', false, 20 ) ), 50 ),
			)
		);

		$query_args = parent::parse_pickup_location_query_args( $query_args );

		return $query_args;
	}

	protected function fetch_pickup_location( $location_code, $address ) {
		$location_code = $this->parse_pickup_location_code( $location_code );
		$address       = $this->parse_pickup_location_address_args( $address );

		if ( empty( $location_code ) ) {
			return false;
		}

		try {
			$result          = Package::get_api()->get_finder_api()->find_by_id( $location_code, $address['country'], $address['postcode'] );
			$pickup_location = $this->get_pickup_location_from_api_response( $result );
		} catch ( \Exception $e ) {
			$pickup_location = null;

			if ( 404 === $e->getCode() ) {
				$pickup_location = false;
			}
		}

		return $pickup_location;
	}

	protected function parse_pickup_location_code( $location_code ) {
		$keyword_id = '';
		preg_match_all( '/([A-Z]{2}-)?[0-9]+/', $location_code, $matches );

		if ( $matches && count( $matches ) > 0 ) {
			if ( isset( $matches[0][0] ) ) {
				$keyword_id = $matches[0][0];
			}
		}

		return $keyword_id;
	}

	protected function get_pickup_location_from_api_response( $location ) {
		$address = array(
			'company'   => $location->name,
			'country'   => $location->place->address->countryCode,
			'postcode'  => $location->place->address->postalCode,
			'address_1' => $location->place->address->streetAddress,
			'city'      => $location->place->address->addressLocality,
		);

		try {
			return new PickupLocation(
				array(
					'code'                         => $location->gzd_id,
					'type'                         => $location->location->type,
					'label'                        => $location->gzd_name,
					'latitude'                     => $location->place->geo->latitude,
					'longitude'                    => $location->place->geo->longitude,
					'supports_customer_number'     => true,
					'customer_number_is_mandatory' => 'locker' === $location->location->type ? true : false,
					'address'                      => $address,
					'address_replacement_map'      => array(
						'address_1' => 'label',
						'country'   => 'country',
						'postcode'  => 'postcode',
						'city'      => 'city',
					),
				)
			);
		} catch ( \Exception $e ) {
			Package::log( $e, 'error' );

			return false;
		}
	}

	protected function get_pickup_location_types() {
		$types = array();

		if ( ParcelLocator::is_packstation_enabled( $this->get_name() ) ) {
			$types[] = 'packstation';
		}

		if ( ParcelLocator::is_parcelshop_enabled( $this->get_name() ) ) {
			$types[] = 'parcelshop';
		}

		if ( ParcelLocator::is_postoffice_enabled( $this->get_name() ) ) {
			$types[] = 'postoffice';
		}

		return $types;
	}

	protected function fetch_pickup_locations( $address, $query_args = array() ) {
		$types     = $this->get_pickup_location_types();
		$locations = array();

		if ( empty( $types ) ) {
			return null;
		}

		try {
			$location_data = Package::get_api()->get_parcel_location(
				array(
					'zip'     => $address['postcode'],
					'country' => $address['country'],
					'city'    => $address['city'],
					'address' => ! empty( $address['postcode'] ) ? $address['address_1'] : '',
				),
				$types,
				$query_args['limit']
			);
		} catch ( \Exception $e ) {
			return null;
		}

		foreach ( $location_data as $location ) {
			if ( $pickup_location = $this->get_pickup_location_from_api_response( $location ) ) {
				$locations[] = $pickup_location;
			}
		}

		return $locations;
	}

	protected function get_pickup_location_cache_key( $location_code, $address ) {
		$address       = $this->parse_pickup_location_address_args( $address );
		$location_code = $this->parse_pickup_location_code( $location_code );
		$cache_key     = 'woocommerce_gzd_shipments_dhl_pickup_location_' . sanitize_key( $location_code ) . '_' . sanitize_key( $address['country'] ) . '_' . $address['postcode'];

		return $cache_key;
	}
}
