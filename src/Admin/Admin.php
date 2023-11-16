<?php

namespace Vendidero\Germanized\DHL\Admin;

use Vendidero\Germanized\DHL\Admin\Importer\DHL;
use Vendidero\Germanized\DHL\Package;
use Vendidero\Germanized\DHL\ParcelServices;
use Vendidero\Germanized\Shipments\Shipment;
use Vendidero\Germanized\Shipments\ReturnShipment;

defined( 'ABSPATH' ) || exit;

/**
 * WC_Admin class.
 */
class Admin {

	/**
	 * Constructor.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_styles' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_scripts' ), 30 );

		add_action( 'admin_init', array( __CLASS__, 'download_legacy_label' ) );

		// Legacy meta box
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_legacy_meta_box' ), 20 );

		// Template check
		add_filter( 'woocommerce_gzd_template_check', array( __CLASS__, 'add_template_check' ), 10, 1 );

		// Admin fields
		add_action( 'woocommerce_admin_field_dhl_receiver_ids', array( __CLASS__, 'output_receiver_ids_field' ), 10 );
		add_filter( 'woocommerce_admin_settings_sanitize_option', array( __CLASS__, 'save_custom_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_field_dp_charge', array( __CLASS__, 'output_dp_charge_field' ), 10 );

		add_action( 'woocommerce_admin_field_dhl_participation_numbers', array( __CLASS__, 'output_participation_numbers_field' ), 10 );

		add_action( 'admin_init', array( __CLASS__, 'refresh_data' ) );
		add_action( 'admin_notices', array( __CLASS__, 'add_notices' ) );

		add_action( 'woocommerce_order_shipping_method', array( __CLASS__, 'preferred_delivery_notice' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_shipping_address', array( __CLASS__, 'preferred_delivery_order' ), 5 );

		Status::init();
	}

	/**
	 * @param string $method_str
	 * @param \WC_Order $order
	 *
	 * @return string
	 */
	public static function preferred_delivery_notice( $method_str, $order ) {
		if ( $dhl_order = wc_gzd_dhl_get_order( $order ) ) {
			if ( $dhl_order->has_preferred_day() ) {
				$method_str .= ' - ' . sprintf( _x( 'Preferred day: %1$s', 'dhl', 'woocommerce-germanized-dhl' ), $dhl_order->get_preferred_day()->date_i18n( get_option( 'date_format' ) ) );
			}
		}

		return $method_str;
	}

	/**
	 * @param \WC_Order $order
	 */
	public static function preferred_delivery_order( $order ) {
		if ( $dhl_order = wc_gzd_dhl_get_order( $order ) ) {
			$dhl_data = array();

			if ( $dhl_order->has_preferred_day() ) {
				$dhl_data[] = array(
					'label' => _x( 'Delivery day', 'dhl', 'woocommerce-germanized-dhl' ),
					'value' => $dhl_order->get_preferred_day()->date_i18n( get_option( 'date_format' ) ),
				);
			}

			if ( $dhl_order->has_preferred_delivery_type() ) {
				$type_titles = ParcelServices::get_preferred_delivery_types();
				$type        = $dhl_order->get_preferred_delivery_type();

				$dhl_data[] = array(
					'label' => _x( 'Delivery type', 'dhl', 'woocommerce-germanized-dhl' ),
					'value' => array_key_exists( $type, $type_titles ) ? $type_titles[ $type ] : $type,
				);
			}

			if ( $dhl_order->has_preferred_location() ) {
				$dhl_data[] = array(
					'label' => _x( 'Preferred location', 'dhl', 'woocommerce-germanized-dhl' ),
					'value' => $dhl_order->get_preferred_location(),
				);
			}

			if ( $dhl_order->has_preferred_neighbor() ) {
				$dhl_data[] = array(
					'label' => _x( 'Preferred neighbor', 'dhl', 'woocommerce-germanized-dhl' ),
					'value' => $dhl_order->get_preferred_neighbor_formatted_address(),
				);
			}
			?>
			<?php if ( ! empty( $dhl_data ) ) : ?>
				<p>
					<strong><?php echo esc_html_x( 'DHL Preferred Delivery', 'dhl', 'woocommerce-germanized-dhl' ); ?>:</strong><br/>
					<?php foreach ( $dhl_data as $dhl_dataset ) : ?>
						<span class="label"><?php echo esc_html( $dhl_dataset['label'] ); ?>: </span><span class="value"><?php echo esc_html( $dhl_dataset['value'] ); ?></span><br/>
					<?php endforeach; ?>
				</p>
				<?php
			endif;
		}
	}

	public static function add_notices() {
		if ( current_user_can( 'manage_woocommerce' ) ) {
			if ( isset( $_GET['im-refresh-type'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				?>
				<div class="notice fade <?php echo ( isset( $_GET['success'] ) ? 'updated' : 'error' );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>">
					<p><?php echo ( isset( $_GET['success'] ) ? esc_html_x( 'Refreshed data successfully.', 'dhl', 'woocommerce-germanized-dhl' ) : wp_kses_post( sprintf( _x( 'Error while refreshing data. Please make sure that the Internetmarke API URL can be <a href="%s">accessed</a>.', 'dhl', 'woocommerce-germanized-dhl' ), esc_url( admin_url( 'admin.php?page=wc-status&tab=dhl' ) ) ) ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
				</div>
				<?php
			} elseif ( isset( $_GET['has-imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				?>
				<div class="notice fade updated">
					<p><?php echo wp_kses_post( sprintf( _x( 'New to DHL in Germanized? Learn how to <a href="%s" target="_blank">easily create DHL labels</a> to your shipments.', 'dhl', 'woocommerce-germanized-dhl' ), esc_url( 'https://vendidero.de/dokument/dhl-labels-zu-sendungen-erstellen' ) ) );  // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?></p>
				</div>
				<?php
			}
		}
	}

	public static function refresh_data() {
		if ( current_user_can( 'manage_woocommerce' ) && isset( $_GET['action'], $_GET['_wpnonce'] ) && 'wc-gzd-dhl-im-product-refresh' === $_GET['action'] ) {
			if ( wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'wc-gzd-dhl-refresh-im-products' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$result       = Package::get_internetmarke_api()->update_products();
				$settings_url = add_query_arg( array( 'im-refresh-type' => 'products' ), Package::get_deutsche_post_shipping_provider()->get_edit_link( 'config_set_simple_label' ) );

				if ( is_wp_error( $result ) ) {
					$settings_url = add_query_arg( array( 'error' => 1 ), $settings_url );
				} else {
					$settings_url = add_query_arg( array( 'success' => 1 ), $settings_url );
				}

				wp_safe_redirect( esc_url_raw( $settings_url ) );
				exit();
			}
		} elseif ( current_user_can( 'manage_woocommerce' ) && isset( $_GET['action'], $_GET['_wpnonce'] ) && 'wc-gzd-dhl-im-page-formats-refresh' === $_GET['action'] ) {
			if ( wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'wc-gzd-dhl-refresh-im-page-formats' ) ) {  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$result       = Package::get_internetmarke_api()->get_page_formats( true );
				$settings_url = add_query_arg( array( 'im-refresh-type' => 'formats' ), Package::get_deutsche_post_shipping_provider()->get_edit_link( 'printing' ) );

				if ( is_wp_error( $result ) ) {
					$settings_url = add_query_arg( array( 'error' => 1 ), $settings_url );
				} else {
					$settings_url = add_query_arg( array( 'success' => 1 ), $settings_url );
				}

				wp_safe_redirect( esc_url_raw( $settings_url ) );
				exit();
			}
		} elseif ( current_user_can( 'manage_woocommerce' ) && isset( $_GET['action'], $_GET['_wpnonce'] ) && 'wc-gzd-dhl-refresh-retoure-receiver-ids' === $_GET['action'] ) {
			if ( wp_verify_nonce( wp_unslash( $_GET['_wpnonce'] ), 'wc-gzd-dhl-refresh-retoure-receiver-ids' ) ) {  // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$receiver_ids = Package::get_api()->get_return_api()->get_receiver_ids();
				$settings_url = add_query_arg( array( 'refresh-type' => 'retoure-receiver-ids' ), Package::get_dhl_shipping_provider()->get_edit_link( 'config_set_return_label' ) );

				if ( is_wp_error( $receiver_ids ) ) {
					$settings_url = add_query_arg( array( 'error' => 1 ), $settings_url );
				} elseif ( ! empty( $receiver_ids ) ) {
					if ( $provider = Package::get_dhl_shipping_provider() ) {
						$existing_retoure_ids = Package::get_return_receivers();

						$provider->update_setting( 'retoure_receiver_ids', array_replace_recursive( $existing_retoure_ids, $receiver_ids ) );
						$provider->save();
					}

					$settings_url = add_query_arg( array( 'success' => 1 ), $settings_url );
				}

				wp_safe_redirect( esc_url_raw( $settings_url ) );
				exit();
			}
		}
	}

	public static function save_custom_fields( $value, $option, $raw_value ) {
		if ( ! isset( $option['type'] ) ) {
			return $value;
		}

		$type = $option['type'];

		if ( 'dhl_receiver_ids' === $type ) {
			$receiver  = array();
			$raw_value = is_array( $raw_value ) ? $raw_value : array();

	        // phpcs:disable WordPress.Security.NonceVerification.NoNonceVerification -- Nonce verification already handled in WC_Admin_Settings::save()
			if ( isset( $raw_value['receiver_id'], $raw_value['receiver_country'] ) ) {
				$receiver_ids = wc_clean( wp_unslash( $raw_value['receiver_id'] ) );
				$countries    = wc_clean( wp_unslash( $raw_value['receiver_country'] ) );

				foreach ( $receiver_ids as $i => $name ) {
					$country = isset( $countries[ $i ] ) ? substr( strtoupper( $countries[ $i ] ), 0, 2 ) : '';
					$slug    = sanitize_key( $receiver_ids[ $i ] . '_' . $country );

					$receiver[ $slug ] = array(
						'id'      => $receiver_ids[ $i ],
						'country' => $country,
						'slug'    => $slug,
					);
				}
			}

			return $receiver;
		}

		return $value;
	}

	public static function output_dp_charge_field( $option ) {
		if ( ! Package::get_internetmarke_api()->get_user() ) {
			return;
		}

		$balance      = Package::get_internetmarke_api()->get_balance();
		$user_token   = Package::get_internetmarke_api()->get_user()->getUserToken();
		$settings_url = Package::get_deutsche_post_shipping_provider()->get_edit_link();
		?>

		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html_x( 'Charge (€)', 'dhl', 'woocommerce-germanized-dhl' ); ?></th>
			<td class="forminp forminp-custom" id="woocommerce_gzd_dhl_im_portokasse_charge_wrapper">
				<input type="text" placeholder="10.00" style="max-width: 150px; margin-right: 10px;" class="wc-input-price short" name="woocommerce_gzd_dhl_im_portokasse_charge_amount" id="woocommerce_gzd_dhl_im_portokasse_charge_amount" />

				<a id="woocommerce_gzd_dhl_im_portokasse_charge" class="button button-secondary" data-url="https://portokasse.deutschepost.de/portokasse/marketplace/enter-app-payment" data-success_url="<?php echo esc_url( add_query_arg( array( 'wallet-charge-success' => 'yes' ), $settings_url ) ); ?>" data-cancel_url="<?php echo esc_url( add_query_arg( array( 'wallet-charge-success' => 'no' ), $settings_url ) ); ?>" data-partner_id="<?php echo esc_attr( Package::get_internetmarke_partner_id() ); ?>" data-key_phase="<?php echo esc_attr( Package::get_internetmarke_key_phase() ); ?>" data-user_token="<?php echo esc_attr( $user_token ); ?>" data-schluessel_dpwn_partner="<?php echo esc_attr( Package::get_internetmarke_token() ); ?>" data-wallet="<?php echo esc_attr( $balance ); ?>">
					<?php echo esc_html_x( 'Charge Portokasse', 'dhl', 'woocommerce-germanized-dhl' ); ?>
				</a>
				<p class="description"><?php echo sprintf( esc_html_x( 'The minimum amount is %s', 'dhl', 'woocommerce-germanized-dhl' ), wc_price( 10, array( 'currency' => 'EUR' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			</td>
		</tr>
		<?php
	}

	public static function output_participation_numbers_field( $option ) {
		$products = isset( $option['products'] ) ? $option['products'] : array();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html_x( 'Participation', 'dhl', 'woocommerce-germanized-dhl' ); ?></th>
			<td class="forminp" id="dhl_participation_numbers">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<thead>
						<tr>
							<th><?php echo esc_html_x( 'Product', 'dhl', 'woocommerce-germanized-dhl' ); ?></th>
							<th><?php echo esc_html_x( 'Participation Number', 'dhl', 'woocommerce-germanized-dhl' ); ?> <?php echo wc_help_tip( _x( 'The participation number for the chosen product. Find your participation number in your DHL business customer portal.', 'dhl', 'woocommerce-germanized-dhl' ) ); ?></th>
							<th><?php echo esc_html_x( 'GoGreen', 'dhl', 'woocommerce-germanized-dhl' ); ?> <?php echo wc_help_tip( _x( 'Optionally choose a separate participation number for GoGreen. Leave empty in case your main participation number includes GoGreen already.', 'dhl', 'woocommerce-germanized-dhl' ) ); ?></th>
						</tr>
						</thead>
						<tbody class="dhl_particpation_number_settings">
						<?php foreach ( $products as $product => $title ) : ?>
							<tr>
								<td style="padding: 10px 8px;"><?php echo esc_html( $title ); ?></td>
								<td>
									<input type="text" name="participation_<?php echo esc_attr( $product ); ?>" id="participation_<?php echo esc_attr( $product ); ?>" value="<?php echo esc_attr( Package::get_dhl_shipping_provider()->get_setting( 'participation_' . $product, '' ) ); ?>" maxlength="14" minlength="2" />
								</td>
								<td>
									<input type="text" name="participation_gogreen_<?php echo esc_attr( $product ); ?>" id="participation_gogreen_<?php echo esc_attr( $product ); ?>" value="<?php echo esc_attr( Package::get_dhl_shipping_provider()->get_setting( 'participation_gogreen_' . $product, '' ) ); ?>" maxlength="14" minlength="2" />
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</td>
		</tr>
		<?php
		$html = ob_get_clean();

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function output_receiver_ids_field( $option ) {
		ob_start();

		$option_key   = isset( $option['id'] ) ? $option['id'] : 'dhl_receiver_ids';
		$receiver_ids = isset( $option['value'] ) ? $option['value'] : array();
		$settings_url = isset( $option['settings_url'] ) ? $option['settings_url'] : '';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html_x( 'Receiver Ids', 'dhl', 'woocommerce-germanized-dhl' ); ?></th>
			<td class="forminp" id="dhl_receiver_ids">
				<div class="wc_input_table_wrapper">
					<table class="widefat wc_input_table sortable" cellspacing="0">
						<input type="text" name="dhl_settings_hider" style="display: none" data-show_if_woocommerce_gzd_dhl_label_retoure_enable="" />
						<thead>
						<tr>
							<th><?php echo esc_html_x( 'Receiver Id', 'dhl', 'woocommerce-germanized-dhl' ); ?> <?php echo wc_help_tip( _x( 'Find your Receiver Ids within your DHL contract data.', 'dhl', 'woocommerce-germanized-dhl' ) ); ?></th>
							<th><?php echo esc_html_x( 'Country Code', 'dhl', 'woocommerce-germanized-dhl' ); ?> <?php echo wc_help_tip( _x( 'Leave empty to use the Receiver Id as fallback.', 'dhl', 'woocommerce-germanized-dhl' ) ); ?></th>
						</tr>
						</thead>
						<tbody class="receiver_ids">
						<?php
						$i = -1;
						foreach ( $receiver_ids as $receiver ) {
							$i++;

							echo '<tr class="receiver">
                                    <td><input type="text" value="' . esc_attr( wp_unslash( $receiver['id'] ) ) . '" name="' . esc_attr( $option_key ) . '[receiver_id][' . esc_attr( $i ) . ']" /></td>
                                    <td><input type="text" value="' . esc_attr( wp_unslash( $receiver['country'] ) ) . '" name="' . esc_attr( $option_key ) . '[receiver_country][' . esc_attr( $i ) . ']" /></td>
                                </tr>';
						}
						?>
						</tbody>
						<tfoot>
						<tr>
							<th colspan="2" style="font-weight: normal; padding-right: 10px;">
								<a href="#" class="add button"><?php echo esc_html_x( '+ Add receiver', 'dhl', 'woocommerce-germanized-dhl' ); ?></a>
								<a href="#" class="remove_rows button"><?php echo esc_html_x( 'Remove selected receiver(s)', 'dhl', 'woocommerce-germanized-dhl' ); ?></a>
								<a style="float: right; margin-right: 0; margin-left: 5px;" href="<?php echo esc_url( wp_nonce_url( add_query_arg( array( 'action' => 'wc-gzd-dhl-refresh-retoure-receiver-ids' ), $settings_url ), 'wc-gzd-dhl-refresh-retoure-receiver-ids' ) ); ?>" class="button button-primary"><?php echo esc_html_x( 'Refresh via API', 'dhl', 'woocommerce-germanized-dhl' ); ?></a>
							</th>
						</tr>
						</tfoot>
					</table>
				</div>
				<script type="text/javascript">
					jQuery(function() {
						jQuery('#dhl_receiver_ids').on( 'click', 'a.add', function(){

							var size = jQuery('#dhl_receiver_ids').find('tbody .receiver').length;

							jQuery('<tr class="receiver">\
									<td><input type="text" name="<?php echo esc_attr( $option_key ); ?>[receiver_id][' + size + ']" /></td>\
									<td><input type="text" name="<?php echo esc_attr( $option_key ); ?>[receiver_country][' + size + ']" /></td>\
								</tr>').appendTo('#dhl_receiver_ids table tbody');

							return false;
						});
					});
				</script>
			</td>
		</tr>
		<?php
		$html = ob_get_clean();

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public static function add_template_check( $check ) {
		$check['germanized']['path'][] = Package::get_path() . '/templates';

		return $check;
	}

	public static function add_legacy_meta_box() {
		global $post;

		if ( ! DHL::is_plugin_enabled() && ( $post && 'shop_order' === $post->post_type && get_post_meta( $post->ID, '_pr_shipment_dhl_label_tracking' ) ) ) {
			add_meta_box( 'woocommerce-gzd-shipment-dhl-legacy-label', _x( 'DHL Label', 'dhl', 'woocommerce-germanized-dhl' ), array( __CLASS__, 'legacy_meta_box' ), 'shop_order', 'side', 'high' );
		}
	}

	public static function legacy_meta_box() {
		global $post;

		$order_id = $post->ID;
		$order    = wc_get_order( $order_id );
		$meta     = $order->get_meta( '_pr_shipment_dhl_label_tracking' );

		if ( ! empty( $meta ) ) {
			echo '<p>' . esc_html_x( 'This label has been generated by the DHL for WooCommerce Plugin and is shown for legacy purposes.', 'dhl', 'woocommerce-germanized-dhl' ) . '</p>';
			echo '<a class="button button-primary" target="_blank" href="' . esc_url( self::get_legacy_label_download_url( $order_id ) ) . '">' . esc_html_x( 'Download label', 'dhl', 'woocommerce-germanized-dhl' ) . '</a>';
		}
	}

	public static function get_legacy_label_download_url( $order_id ) {
		$url = add_query_arg(
			array(
				'action'   => 'wc-gzd-dhl-download-legacy-label',
				'order_id' => $order_id,
				'force'    => 'yes',
			),
			wp_nonce_url( admin_url(), 'dhl-download-legacy-label' )
		);

		return esc_url_raw( $url );
	}

	public static function download_legacy_label() {
		if ( isset( $_GET['action'] ) && 'wc-gzd-dhl-download-legacy-label' === $_GET['action'] && isset( $_REQUEST['_wpnonce'] ) ) {
			if ( isset( $_GET['order_id'] ) && wp_verify_nonce( wp_unslash( $_REQUEST['_wpnonce'] ), 'dhl-download-legacy-label' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				$order_id = absint( $_GET['order_id'] );
				$args     = \Vendidero\Germanized\Shipments\Labels\DownloadHandler::parse_args(
					array(
						'force' => wc_string_to_bool( isset( $_GET['force'] ) ? wc_clean( wp_unslash( $_GET['force'] ) ) : false ),
					)
				);

				if ( current_user_can( 'edit_shop_orders' ) ) {
					if ( $order = wc_get_order( $order_id ) ) {
						$meta = (array) $order->get_meta( '_pr_shipment_dhl_label_tracking' );

						if ( ! empty( $meta ) ) {
							$path = $meta['label_path'];

							if ( file_exists( $path ) ) {
								$filename = basename( $path );

								\Vendidero\Germanized\Shipments\Labels\DownloadHandler::download( $path, $filename, $args['force'] );
							}
						}
					}
				}
			}
		}
	}

	public static function admin_scripts() {
		global $post;

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		$suffix    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		wp_register_script( 'wc-gzd-admin-dhl-internetmarke', Package::get_assets_url() . '/js/admin-internetmarke' . $suffix . '.js', array( 'jquery' ), Package::get_version() ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter
		wp_register_script( 'wc-gzd-admin-deutsche-post-label', Package::get_assets_url() . '/js/admin-deutsche-post-label' . $suffix . '.js', array( 'wc-gzd-admin-shipment-modal' ), Package::get_version() ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.NotInFooter

		if ( wp_script_is( 'wc-gzd-admin-shipment-modal', 'enqueued' ) ) {
			wp_enqueue_script( 'wc-gzd-admin-deutsche-post-label' );

			wp_localize_script(
				'wc-gzd-admin-deutsche-post-label',
				'wc_gzd_admin_deutsche_post_label_params',
				array(
					'refresh_label_preview_nonce' => wp_create_nonce( 'wc-gzd-dhl-refresh-deutsche-post-label-preview' ),
				)
			);
		}

		// Shipping zone methods
		if ( 'woocommerce_page_wc-settings' === $screen_id && isset( $_GET['provider'] ) && 'deutsche_post' === $_GET['provider'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			wp_enqueue_script( 'wc-gzd-admin-dhl-internetmarke' );
		}
	}

	public static function admin_styles() {
		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		$suffix    = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// Register admin styles.
		wp_register_style( 'woocommerce_gzd_dhl_admin', Package::get_assets_url() . '/css/admin' . $suffix . '.css', array( 'woocommerce_admin_styles' ), Package::get_version() );

		// Admin styles for WC pages only.
		if ( in_array( $screen_id, self::get_screen_ids(), true ) ) {
			wp_enqueue_style( 'woocommerce_gzd_dhl_admin' );
		}
	}

	public static function get_screen_ids() {
		return \Vendidero\Germanized\Shipments\Admin\Admin::get_screen_ids();
	}
}
