<?php
/*
Plugin Name: WooCommerce Product Gift Wrap
Plugin URI: https://github.com/mikejolley/woocommerce-product-gift-wrap
Description: Add an option to your products to enable gift wrapping. Optionally charge a fee. For WooCommerce 2.0+ @todo Design selection.
Version: 1.0.1
Author: Mike Jolley
Author URI: http://mikejolley.com
Requires at least: 3.5
Tested up to: 3.5
Text Domain: product_gift_wrap
Domain Path: /languages/

	Copyright: © 2013 Mike Jolley.
	License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

/**
 * Localisation
 */
load_plugin_textdomain( 'product_gift_wrap', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

/**
 * WC_Product_Gift_wrap class.
 */
class WC_Product_Gift_Wrap {

	/**
	 * Hook us in :)
	 *
	 * @access public
	 * @return void
	 */
	public function __construct() {

		$default_message = '<p class="gift-wrapping" style="clear:both; padding-top: .5em;"><label>{checkbox} Gift wrap this item for {price}?</label></p>';

		$this->gift_wrap_enabled = get_option( 'product_gift_wrap_enabled' ) == 'yes' ? true : false;
		$this->gift_wrap_cost    = get_option( 'product_gift_wrap_cost', 0 );
		// $this->product_gift_wrap_message = get_option( 'product_gift_wrap_message' );

		if ( ! $this->product_gift_wrap_message )
			$this->product_gift_wrap_message = $default_message;

		add_option( 'product_gift_wrap_enabled', 'no' );
		add_option( 'product_gift_wrap_cost', '0' );
		add_option( 'product_gift_wrap_message', '' );

		// Init settings
		$this->settings = array(
			array(
				'name' 		=> __( 'Default Gift Wrap Status', 'product_gift_wrap' ),
				'desc' 		=> __( 'Enable this to allow gift wrapping by default.', 'product_gift_wrap' ),
				'id' 		=> 'product_gift_wrap_enabled',
				'type' 		=> 'checkbox',
			),
			array(
				'name' 		=> __( 'Default Gift Wrap Cost', 'product_gift_wrap' ),
				'desc' 		=> __( 'The cost of gift wrap unless overridden per-product.', 'product_gift_wrap' ),
				'id' 		=> 'product_gift_wrap_cost',
				'type' 		=> 'text',
				'desc_tip'  => true
			),
			array(
				'name' 		=> __( 'Gift Wrap Message', 'product_gift_wrap' ),
				'desc' 		=> __( 'Default', 'product_gift_wrap' ) . ': ' . htmlspecialchars( $default_message ),
				'id' 		=> 'product_gift_wrap_message',
				'type' 		=> 'text',
				'desc_tip'  => __( 'The checkbox and label shown to the user on the frontend.', 'product_gift_wrap' )
			),
		);

		// Display on the front end
		// add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'gift_option_html' ), 10 );
		add_action( 'petite_gift_wrap_column', array( $this, 'gift_wrap_column' ), 10, 2 );

		// Filters for cart actions
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'add_cart_item_data' ), 10, 2 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'handle_actions' ), 9, 3 );
		add_filter( 'woocommerce_get_cart_item_from_session', array( $this, 'get_cart_item_from_session' ), 10, 2 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'get_item_data' ), 10, 2 );
		add_filter( 'woocommerce_add_cart_item', array( $this, 'add_cart_item' ), 10, 1 );
		add_action( 'woocommerce_add_order_item_meta', array( $this, 'add_order_item_meta' ), 10, 2 );

		// Write Panels
		add_action( 'woocommerce_product_options_pricing', array( $this, 'write_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'write_panel_save' ) );

		// Admin
		add_action( 'woocommerce_settings_general_options_end', array( $this, 'admin_settings' ) );
		add_action( 'woocommerce_update_options_general', array( $this, 'save_admin_settings' ) );
	}

	/**
	 * Check if the post is wrappable
	 *
	 * @param $post_id integer
	 * @return string
	 **/
	public function is_wrappable( $post_id )
	{
		$is_wrappable = get_post_meta( $post_id, '_is_gift_wrappable', true );

		if ( $is_wrappable == '' && $this->gift_wrap_enabled )
			$is_wrappable = 'yes';	

		return $is_wrappable;
	}

	/**
	 * Get the wrap cost
	 *
	 * @return string
	 **/
	public function get_wrap_cost()
	{
		global $post;

		$cost = get_post_meta( $post->ID, '_gift_wrap_cost', true );

		if ( $cost == '' )
			$cost = $this->gift_wrap_cost;

		$price_text = $cost > 0 ? woocommerce_price( $cost ) : 'Embalagem Gratuita';

		return $price_text;
	}

	/**
	 * Show the Gift Checkbox on the frontend, single product page
	 *
	 * @access public
	 * @return void
	 */
	public function gift_option_html() {
		global $post;

		if ( $this->is_wrappable($post->ID) == 'yes' ) {

			$current_value = ! empty( $_REQUEST['gift_wrap'] ) ? 1 : 0;

			$cost = get_post_meta( $post->ID, '_gift_wrap_cost', true );

			if ( $cost == '' )
				$cost = $this->gift_wrap_cost;

			$price_text    = $cost > 0 ? 'por '.woocommerce_price( $cost ) : 'gratuitamente';
			$checkbox      = '<input type="checkbox" name="gift_wrap" value="yes" ' . checked( $current_value, 1, false ).' />';

			echo '<p>'.$checkbox.' Embalar para presente '. $price_text .'?</p>';
		}
	}

	/**
	 * Display the gift wrap column on the cart page table
	 *	
	 * @param $product_values mixed
	 * @return string
	 **/
	public function gift_wrap_column( $product_values, $cart_item_key )
	{
		global $post;
		global $woocommerce;


		if ( $this->is_wrappable($post->ID) == 'yes' ) {
			if ($product_values['gift_wrap'] == 1) {
				echo "<i class='icon-gift'></i> <p>".$this->get_wrap_cost()."</p>
					 <p>Com embalagem<a href='".add_query_arg( array('product_key' => $cart_item_key, 'gift_wrap_action' => 'remove' ), $woocommerce->cart->get_cart_url() )."'> (Remover)</a></p>";
			} else {
				echo "<p>Sem embalagem para presente</p>
					  <a class='gift-wrap-add' href='".add_query_arg( array('product_key' => $cart_item_key, 'gift_wrap_action' => 'add' ), $woocommerce->cart->get_cart_url() )."'> (Adicionar)</a>";
			}
		} else {
			echo "Esse produto não pode ser embalado para presente";
		}
	}

	/**
	 * When added to cart, save any gift data
	 *
	 * @access public
	 * @param mixed $cart_item_meta
	 * @param mixed $product_id
	 * @return void
	 */
	public function add_cart_item_data( $cart_item_meta, $product_id ) {
		global $woocommerce;

		$is_wrappable = get_post_meta( $product_id, '_is_gift_wrappable', true );

		if ( $is_wrappable == '' && $this->gift_wrap_enabled )
			$is_wrappable = 'yes';

		if ( ! empty( $_POST['gift_wrap'] ) && $is_wrappable == 'yes' && $_POST['gift_wrap'] !== false )
			$cart_item_meta['gift_wrap'] = true;

		return $cart_item_meta;
	}

	/**
	 * Handle gift wrap cart actions
	 *
	 * @access public
	 * @return void
	 */
	public function handle_actions( $cart_item, $values, $key ) {

		if ( isset($_GET['gift_wrap_action']) ) {

			if ( $key == $_GET['product_key'] ) {

				if ( $_GET['gift_wrap_action'] == "remove" ) {

					$cart_item['gift_wrap'] = 0;
					$cart_item['gift_message'] = '';

				} elseif ( $_GET['gift_wrap_action'] == "add" ) {

					$cart_item['gift_wrap'] = 1;
					$cart_item['gift_message'] = htmlspecialchars($_POST['gift_message']);

				}

			} else {

				$cart_item['gift_wrap'] = $values['gift_wrap'];

			}	
				
		}

		return $cart_item;
	}

	/**
	 * Get the gift data from the session on page load
	 *
	 * @access public
	 * @param mixed $cart_item
	 * @param mixed $values
	 * @return void
	 */
	public function get_cart_item_from_session( $cart_item, $values ) {

		// First time add to cart (functionality not in use now)
		if ( ! isset($cart_item['gift_wrap']) ) {

			if ( isset( $values['gift_wrap'] ) ) {

				$cart_item['gift_wrap'] = true;

				$cart_item['gift_message'] = $values['gift_message'];

				$cost = get_post_meta( $cart_item['data']->id, '_gift_wrap_cost', true );

				if ( $cost == '' )
					$cost = $this->gift_wrap_cost;

				$cart_item['data']->adjust_price( $cost );
			}

		} else {
			// Using the functionality in the cart page

			if ( $cart_item['gift_wrap'] !== 0 ) {

				$cart_item['gift_wrap'] = true;

				$cart_item['gift_message'] = $cart_item['gift_message'];

				$cost = get_post_meta( $cart_item['data']->id, '_gift_wrap_cost', true );

				if ( $cost == '' )
					$cost = $this->gift_wrap_cost;

				$cart_item['data']->adjust_price( $cost );
			}

		}

		return $cart_item;
	}

	/**
	 * Display gift data if present in the cart
	 *
	 * @access public
	 * @param mixed $other_data
	 * @param mixed $cart_item
	 * @return void
	 */
	public function get_item_data( $item_data, $cart_item ) {

		if ( ! empty( $cart_item['gift_wrap'] ) )
			$item_data[] = array(
				'name'    => 'Para presente',
				'value'   => __( 'Yes', 'product_gift_wrap' ),
				'display' => 'Sim'
			);

		return $item_data;
	}

	/**
	 * Adjust price after adding to cart
	 *
	 * @access public
	 * @param mixed $cart_item
	 * @return void
	 */
	public function add_cart_item( $cart_item ) {
		if ( ! empty( $cart_item['gift_wrap'] ) ) {

			$cost = get_post_meta( $cart_item['data']->id, '_gift_wrap_cost', true );

			if ( $cost == '' )
				$cost = $this->gift_wrap_cost;

			$cart_item['data']->adjust_price( $cost );
		}

		return $cart_item;
	}

	/**
	 * After ordering, add the data to the order line items.
	 *
	 * @access public
	 * @param mixed $item_id
	 * @param mixed $values
	 * @return void
	 */
	public function add_order_item_meta( $item_id, $cart_item ) {
		if ( ! empty( $cart_item['gift_wrap'] ) )
			woocommerce_add_order_item_meta( $item_id, 'Para presente', 'Sim' );
			woocommerce_add_order_item_meta( $item_id, 'Mensagem', $cart_item['gift_message'] );
	}

	/**
	 * write_panel function.
	 *
	 * @access public
	 * @return void
	 */
	public function write_panel() {
		global $post, $woocommerce;

		echo '</div><div class="options_group show_if_simple show_if_variable">';

		$is_wrappable = get_post_meta( $post->ID, '_is_gift_wrappable', true );

		if ( $is_wrappable == '' && $this->gift_wrap_enabled )
			$is_wrappable = 'yes';

		woocommerce_wp_checkbox( array(
				'id'            => '_is_gift_wrappable',
				'wrapper_class' => '',
				'value'         => $is_wrappable,
				'label'         => __( 'Gift Wrappable', 'product_gift_wrap' ),
				'description'   => __( 'Enable this option if the customer can choose gift wrapping.', 'product_gift_wrap' ),
			) );

		woocommerce_wp_text_input( array(
				'id'          => '_gift_wrap_cost',
				'label'       => __( 'Gift Wrap Cost', 'product_gift_wrap' ),
				'placeholder' => $this->gift_wrap_cost,
				'desc_tip'    => true,
				'description' => __( 'Override the default cost by inputting a cost here.', 'product_gift_wrap' ),
			) );

		$woocommerce->add_inline_js( "
			jQuery('input#_is_gift_wrappable').change(function(){

				jQuery('._gift_wrap_cost_field').hide();

				if ( jQuery('#_is_gift_wrappable').is(':checked') ) {
					jQuery('._gift_wrap_cost_field').show();
				}

			}).change();
		" );
	}

	/**
	 * write_panel_save function.
	 *
	 * @access public
	 * @param mixed $post_id
	 * @return void
	 */
	public function write_panel_save( $post_id ) {
		$_is_gift_wrappable = ! empty( $_POST['_is_gift_wrappable'] ) ? 'yes' : 'no';
		$_gift_wrap_cost   = ! empty( $_POST['_gift_wrap_cost'] ) ? woocommerce_clean( $_POST['_gift_wrap_cost'] ) : '';

		update_post_meta( $post_id, '_is_gift_wrappable', $_is_gift_wrappable );
		update_post_meta( $post_id, '_gift_wrap_cost', $_gift_wrap_cost );
	}

	/**
	 * admin_settings function.
	 *
	 * @access public
	 * @return void
	 */
	public function admin_settings() {
		woocommerce_admin_fields( $this->settings );
	}

	/**
	 * save_admin_settings function.
	 *
	 * @access public
	 * @return void
	 */
	public function save_admin_settings() {
		woocommerce_update_options( $this->settings );
	}
}

new WC_Product_Gift_Wrap();
