<?php

/**
 * Plugin Name: WooCommerce CyberSource Secure Acceptance WM Gateway - Developement
 * Plugin URI: 
 * Description: Adds the CyberSource Secure Acceptance Web/Mobile (WM) payment gateway to your WooCommerce website. Requires an SSL certificate.
 * Author: Mikochi Mabingo
 * Author URI: 
 * Version: 1.2.0-beta
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * The WC_Cybersource_Secure_Acceptance_WM global object
 * @name $wc_cybersource_secure_acceptance_wm
 * @global WC_Cybersource_Secure_Acceptance_WM $GLOBALS['wc_cybersource_secure_acceptance_wm']
 */
$GLOBALS['wc_cybersource_secure_acceptance_wm'] = new WC_Cybersource_Secure_Acceptance_WM();


/**
 * Main Plugin Class
 *
 * For test transactions:
 *
 * Card Type/Number:
 * Visa				/ 4111111111111111
 * MasterCard		/ 5555555555554444
 * Maestro Int'l	/ 6000340000009859
 * American Express	/ 378282246310005
 *
 * Expiration Date: any date in the future
 * Card Security Code: any 3 digits
 */
class WC_Cybersource_Secure_Acceptance_WM
{

	/** class name to load as gateway */
	const GATEWAY_CLASS_NAME = 'WC_Gateway_Cybersource_Secure_Acceptance_WM';

	/** gateway id */
	const GATEWAY_ID = 'cybersource_secure_acceptance_wm';
	
	/** plugin text domain */
	const TEXT_DOMAIN = 'wc-cybersource';	

	/** @var string the plugin path */
	private $plugin_path;

	/** @var string the plugin url */
	private $plugin_url;

	/** @var object WC_Logger instance */
	private $logger;

	/**
	 * The custom receipt page to redirect to after a transaction.
	 * This is not guaranteed to work, so it's important to configure the setting
	 * in the CyberSource Admin-> Tools & Settings -> Profiles -> Profile Name -> Customer Response Pages
	 * Receipt Page with https://www.example.com/?wc-api=wc_gateway_cybersource_secure_acceptance_wm_response
	 * @var string
	 */
	private $response_url;

	/**
	 * Construct and initialize the main plugin class
	 */
	function __construct()
	{

		// Load the gateway
		add_action( 'plugins_loaded', array( $this, 'load_classes' ) );
		
		// add a 'Configure' link to the plugin action links
		add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'plugin_manage_link' ), 10, 4 );    // remember, __FILE__ derefs symlinks :(

		add_action( 'init', array( $this, 'load_translation' ) );

		if ( is_admin() && ! defined( 'DOING_AJAX' ) )
		{

			add_action( 'admin_notices', array( $this, 'check_ssl' ) );

			// order admin link to cybersource transaction
			add_action( 'woocommerce_order_actions',       array( $this, 'order_meta_box_transaction_link' ) );
			add_action( 'woocommerce_order_actions_start', array( $this, 'order_meta_box_transaction_link' ) );

		}

		
		// Unhook Woocommerce email notifications
		add_action( 'woocommerce_email', array( $this, 'unhook_email_notifications' ) );

		/**
		* Technically this is cheating, we're going to set up our own API
		* listeners and instantiate and hand off to the payment gateway
		* object when really we should be listening from within the gateway
		* on a single response URL based on its classname.  However, recoding
		* these handlers would be more effort than this gateway can justify
		* with its current sales figures
		*/
		$this->response_url = add_query_arg( 'wc-api', 'wc_gateway_cybersource_secure_acceptance_wm_response', home_url( '/' ) );

		if ( is_ssl() || 'yes' == get_option( 'woocommerce_force_ssl_checkout' ) )
		{
			$this->response_url = str_replace( 'http:', 'https:', $this->response_url );
		}
	
		// Payment listener/API hook
		add_action( 'woocommerce_api_wc_gateway_cybersource_secure_acceptance_wm_response', array( $this, 'cybersource_relay_response' ) );
	}


	/**
	 * Loads Gateway class once parent class is available
	 */
	public function load_classes()
	{

		// CyberSource gateway
		require_once( 'includes/class-wc-gateway-cybersource-secure-acceptance-wm.php' );

		// Add class to WC Payment Methods
		add_filter( 'woocommerce_payment_gateways', array( $this, 'load_gateway' ) );

	}


	/**
	 * Adds gateway to the list of available payment gateways
	 *
	 * @param array $gateways array of gateway names or objects
	 * @return array $gateways array of gateway names or objects
	 */
	public function load_gateway( $gateways )
	{

		$gateways[] = self::GATEWAY_CLASS_NAME;

		return $gateways;
	}


	/**
	 * Load the translation so that WPML is supported
	 */
	public function load_translation()
	{
		load_plugin_textdomain( self::TEXT_DOMAIN, false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
	}


	/**
	 * Check if SSL is enabled and notify the admin user.  The gateway can technically still
	 * function without SSL, so this isn't a fatal dependency, not to mention users might
	 * not bother to configure SSL for their test server.
	 */
	public function check_ssl()
	{
		if ( 'yes' != get_option( 'woocommerce_force_ssl_checkout' ) )
		{
			echo '<div class="error"><p>WooCommerce IS NOT BEING FORCED OVER SSL; YOUR CUSTOMER\'S CREDIT CARD DATA IS AT RISK.</p></div>';
		}
	}


	/** API methods ******************************************************/


	/**
	 * Handle the API response call by instantiating the gateway object
	 * and handing off to it
	 */
	public function cybersource_relay_response()
	{
		$wc_gateway_cybersource_secure_acceptance_wm = new WC_Gateway_Cybersource_Secure_Acceptance_WM();
		$wc_gateway_cybersource_secure_acceptance_wm->cybersource_response();
	}

	
	/** Admin methods ******************************************************/


	/**
	 * Add a button to the order actions meta box to view the order in the
	 * CyberSource ebc
	 *
	 * @param int $post_id the order identifier
	 */
	public function order_meta_box_transaction_link( $post_id )
	{
		global $woocommerce, $wc_cybersource_secure_acceptance_wm;
		// this action is overloaded
		if ( is_array( $post_id ) ) return $post_id;

		$order = wc_get_order( isset( $order_id ) );

		if ( self::GATEWAY_ID == $order->payment_method )
		{
			$wc_gateway_cybersource_secure_acceptance_wm = new WC_Gateway_Cybersource_Secure_Acceptance_WM();
			$wc_gateway_cybersource_secure_acceptance_wm->order_meta_box_transaction_link( $order );
		}
	}


	/** Helper methods ******************************************************/


	/**
	 * Logs $message using the woocommerce logging facility
	 *
	 * @param string $message the string to log
	 */
	public function log( $message )
	{
		global $woocommerce;
		
		if ( ! is_object( $this->logger ) )
		{

			$this->logger = new WC_Logger();

			$this->logger->add( self::GATEWAY_ID, $message );
		}
	}


	/**
	 * Get the plugin path
	 */
	public function plugin_path()
	{
		if ( is_null( $this->plugin_path ) ) $this->plugin_path = plugin_dir_path( __FILE__ );

		return $this->plugin_path;
	}


	/**
	 * Get the plugin url, ie http://example.com/wp-content/plugins/plugin-name
	 *
	 * @return string the plugin url
	 */
	public function plugin_url()
	{
		if ( is_null( $this->plugin_url ) ) $this->plugin_url = untrailingslashit( plugins_url( '/', __FILE__ ) );

		return $this->plugin_url;
	}


	/**
	 * Return the plugin action links.  This will only be called if the plugin
	 * is active.
	 *
	 * @param array $actions associative array of action names to anchor tags
	 * @param string $plugin_file plugin file name, ie my-plugin/my-plugin.php
	 * @param array $plugin_data associative array of plugin data from the plugin file headers
	 * @param string $context plugin status context, ie 'all', 'active', 'inactive', 'recently_active'
	 *
	 * @return array associative array of plugin action links
	 */
	public function plugin_manage_link( $actions, $plugin_file, $plugin_data, $context )
	{
		// add a 'Configure' link to the front of the actions list for this plugin
		if ( version_compare( WOOCOMMERCE_VERSION, "2.1" ) <= 0 )
		{
			return array_merge( array( 'configure' => '<a href="' . admin_url( 'admin.php?page=woocommerce_settings&tab=payment_gateways&section=' . self::GATEWAY_CLASS_NAME ) . '">' . __( 'Configure', self::TEXT_DOMAIN ) . '</a>' ),
								$actions );
		}else{
			return array_merge( array( 'configure' => '<a href="' . admin_url( 'admin.php?page=wc-settings&tab=checkout&section=' . self::GATEWAY_CLASS_NAME ) . '">' . __( 'Configure', self::TEXT_DOMAIN ) . '</a>' ),
								$actions );
		}
	}

	/** Getter methods ******************************************************/


	/**
	 * Gets the receipt response URL
	 *
	 * @return string receipt response URL
	 */
	public function get_response_url()
	{
		return $this->response_url;
	}
	
	
	/** Default Email Notification Override **********************************/
	
	public function unhook_email_notifications( $email_class )
	{
 
		/**
		 * Hooks for sending emails during store events
		 **/
	//	remove_action( 'woocommerce_low_stock_notification', array( $email_class, 'low_stock' ) );
	//	remove_action( 'woocommerce_no_stock_notification', array( $email_class, 'no_stock' ) );
	//	remove_action( 'woocommerce_product_on_backorder_notification', array( $email_class, 'backorder' ) );
		
		// New order emails
		remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_pending_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_failed_to_processing_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_failed_to_completed_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_failed_to_on-hold_notification', array( $email_class->emails['WC_Email_New_Order'], 'trigger' ) );
		
		// Processing order emails
		remove_action( 'woocommerce_order_status_pending_to_processing_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );
		remove_action( 'woocommerce_order_status_pending_to_on-hold_notification', array( $email_class->emails['WC_Email_Customer_Processing_Order'], 'trigger' ) );
		
		// Completed order emails
		remove_action( 'woocommerce_order_status_completed_notification', array( $email_class->emails['WC_Email_Customer_Completed_Order'], 'trigger' ) );
			
		// Note emails
		remove_action( 'woocommerce_new_customer_note_notification', array( $email_class->emails['WC_Email_Customer_Note'], 'trigger' ) );
	}
}
