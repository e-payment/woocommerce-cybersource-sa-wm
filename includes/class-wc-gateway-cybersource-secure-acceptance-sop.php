<?php

/**
 * WooCommerce CyberSource Secure Acceptance SOP
 *
 * @Class	WC_Gateway_Cybersource_Secure_Acceptance_SOP
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce CyberSource Secure Acceptance SOP to newer
 * versions in the future.
 *
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * CyberSource Gateway class
 */
class WC_Gateway_Cybersource_Secure_Acceptance_SOP extends WC_Payment_Gateway {

	// CyberSource Standard Transaction Endpoints
	private $test_url = "https://testsecureacceptance.cybersource.com/silent/pay";
	//private $test_url = "http://localhost/php_sop/receipt.php";
	private $live_url = "https://secureacceptance.cybersource.com/silent/pay";


	// CyberSource Decision Manager Device Fingerprinting Organisation IDs
	private $test_org_id = "1snn5n9w";
	private $live_org_id = "k8vif92e";


	/**
	 * Associative array of card types to card name
	 * @var array
	 */
	private $card_type_options;


	/**
	 * Construct and initialize the gateway class
	 */
	public function __construct()
	{

		global $woocommerce;

		$this->plugin_path = untrailingslashit( plugin_dir_path( __FILE__ ) );

		$this->id                 = WC_Cybersource_Secure_Acceptance_SOP::GATEWAY_ID;
		$this->method_title       = __( 'CyberSource Secure Acceptance SOP', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN );
		$this->method_description = __( 'CyberSource Secure Acceptance SOP (Silent Order Post) handles all the steps in the secure transaction while remaining virtually transparent. ' .
		                                'Payment data is passed from the checkout to CyberSource for processing without ever passing through your server, simplifying PCI compliance.', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN );

		// to set up the images icon for your shop, use the included images/cards.png
		// for the card images you accept, and hook into this filter with a return
		// value like: plugins_url( '/images/cards.png', __FILE__ );

		$this->icon               = apply_filters( 'woocommerce_cybersource_secure_acceptance_sop_icon', '' );

		// actually this gateway does not have any payment fields, but unfortunately
		// it seems necessary to set this to true so that the payment_fields()
		// method will guaranteed to be called and the styling for the gateway
		// credit card icons can be rendered.

		$this->has_fields = true;

		// define the default card type options, and allow plugins to add in additional ones.
		// Additional display names can be associated with a single card type by using the
		// following convention: 001: Visa, 002: MasterCard, 003: American Express, etc
		$default_card_type_options = array(
			'001' => 'Visa',
			'002' => 'MasterCard',
			'003' => 'American Express',
			'042' => 'Maestro Int\'l'
		);
		$this->card_type_options = apply_filters( 'woocommerce_cybersource_secure_acceptance_sop_card_types', $default_card_type_options );

		// Load the form fields.
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Define user set variables
		$this->enabled          		= $this->settings['enabled'];
		$this->title            		= $this->settings['title'];
		$this->description      		= $this->settings['description'];
		$this->checkout_processing		= $this->settings['checkout_processing'];
		$this->testmode         		= $this->settings['testmode'];
		$this->debug            		= $this->settings['debug'];
		$this->log              		= $this->settings['log'];
		$this->device_finger_print		= $this->settings['device_finger_print'];
		$this->transaction_type			= $this->settings['transaction_type'];
		$this->locale					= $this->settings['locale'];
		$this->currency					= $this->settings['currency'];
		$this->card_type        		= $this->settings['card_type'];
		$this->merchant_id				= $this->settings['merchant_id'];
		$this->profile_keys				= $this->settings['profile_keys'];
		$this->profile_id       		= $this->settings['profile_id'];
		$this->profile_id_test  		= $this->settings['profile_id_test'];
		$this->secret_key	    		= $this->settings['secret_key'];
		$this->secret_key_test  		= $this->settings['secret_key_test'];
		$this->access_key	    		= $this->settings['access_key'];
		$this->access_key_test  		= $this->settings['access_key_test'];
		$this->autocomplete_orders  	= $this->settings['autocomplete_orders'];
		$this->autocomplete_orders_mode = $this->settings['autocomplete_orders_mode'];
		$this->sslseal          		= isset( $this->settings['sslseal'] ) ? $this->settings['sslseal'] : 'no';
		$this->banklogo         		= isset( $this->settings['banklogo'] ) ? $this->settings['banklogo'] : 'no';

		if ( $this->is_test_mode() ) $this->description . ' ' . __( 'TEST MODE ENABLED', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN );

		// Payment form hook
		add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'payment_page' ) );
		
		// Autocomplete Orders hook
		add_action( 'init', array( $this,'autocompleteOrders' ), 0 );

		if ( is_admin() )
		{
			add_action( 'woocommerce_update_options_payment_gateways',              array( $this, 'process_admin_options' ) );  // WC < 2.0
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );  // WC >= 2.0
		}

		// Override Checkout Button Description
		$this->order_button_text = __( 'Credit OR Debit Card', 'woocommerce' );

	} // End Construct

	/**
	 * Initialise Gateway Settings Form Fields
	 */
	public function init_form_fields()
	{
		$this->form_fields = include( 'settings-cybersource.php' );
	}

	/**
	 * get_icon function.
	 *
	 * @access public
	 * @return string
	 */
	function get_icon()
	{
		global $woocommerce, $wc_cybersource_secure_acceptance_sop;

		$icon = '';
		if ( $this->icon )
		{
			// default behavior
				$icon = '<img src="' . WC_HTTPS::force_https_url( $this->icon ) . '" alt="' . $this->title . '" />';

		} elseif ( $this->card_type )
		{
			// display icons for the selected card types
			$icon = '';
			foreach ( $this->card_type as $card_type )
			{
				if ( file_exists( $wc_cybersource_secure_acceptance_sop->plugin_path() . '/images/card-' . strtolower( $card_type ) . '.png' ) )
				{
						$icon .= '<img src="' . WC_HTTPS::force_https_url( $wc_cybersource_secure_acceptance_sop->plugin_url() . '/images/card-' . strtolower( $card_type ) . '.png' ) . '" alt="' . strtolower( $card_type ) . '" />';
				}
			}
		}
		return apply_filters( 'woocommerce_gateway_icon', $icon, $this->id );
	}


	/**
	 * Being a direct-post method, there are no payment fields on the
	 * main checkout page, rather the payment type is selected, and
	 * the customer is taken to a special payment page to complete
	 * the transaction.
	 */
	function payment_fields()
	{
		parent::payment_fields();
		?>
		<style type="text/css">#payment ul.payment_methods li label[for='payment_method_cybersource_secure_acceptance_sop'] img:nth-child(n+2) { margin-left:1px; }</style>
<?php
	}


	/**
	 * Payments for direct-post methods are automatically accepted, and the
	 * client is redirected to a 'payment' page which contains the form that
	 * collects the actual payment information and posts to the processor
	 * server.
	 */
	public function process_payment( $order_id )
	{
		parent::process_payment( $order_id );

		$order = wc_get_order( $order_id );

		do_action( 'woocommerce_' . $this->id . '_process_payment', $this->id, $order_id );

		return array(
			'result'   => 'success',
			'redirect' => $this->get_payment_page_url( $order )
		);
	}


	/**
	 * Payment page for showing the payment form which sends data to cybersource.
	 * Secure Acceptance Silent Order Post has the unfortunate consequence of very visibly
	 * sending the browser to an empty page on the cybersource server while
	 * the credit card is processed, before redirecting back to this server.
	 *
	 * TODO: on decline, select the expiration/card type that was tried
	 */
	public function payment_page( $order_id )
	{

		global $woocommerce, $wc_cybersource_secure_acceptance_sop;

		// Include the Security file that is used to sign the API fields
		require_once( 'cybersource_security/security.php' );

		$order = wc_get_order( $order_id );

		/**
		 * Generate a unique reference or tracking number for each transaction.
		 */
		function getmicrotime()
		{ 
			list( $usec, $sec ) = explode( " ",microtime() );
			$usec = ( int )( ( float )$usec * 1000 );
			
			while (strlen($usec) < 3)
			
			{
				$usec = "0" . $usec;
			}
			
			return $sec . $usec;
		}

		// Array for Order and to be used for building Request Fields.
		$data_array = array(
			'access_key' => $this->get_access_key(),
			'profile_id' => $this->get_profile_id(),
			'transaction_uuid' => $order->order_key,
			'unsigned_field_names' => 'card_number,card_expiry_date,card_type,card_cvn',
			'signed_date_time' => gmdate("Y-m-d\TH:i:s\Z"),
			'locale' => $this->locale,
			'transaction_type' => $this->transaction_type,
			'reference_number' => $order->id,
			'amount' => $order->get_total(),
			'currency' => $this->currency,
			'payment_method' => 'card', // Hard coded because this is a card payment gateway.
			'bill_to_forename' => $order->billing_first_name,
			'bill_to_surname' => $order->billing_last_name,
			'bill_to_email' => $order->billing_email,
			'bill_to_phone' => $order->billing_phone,
			'bill_to_address_line1' => $order->billing_address_1,
			'bill_to_address_line2' => $order->billing_address_2,
			'bill_to_address_city' => $order->billing_city,
			'bill_to_address_state' => $order->billing_state,
			'bill_to_address_country' => $order->billing_country,
			'bill_to_address_postal_code' => $order->billing_postcode,
			'bill_to_company_name' => $order->billing_company,
			'customer_ip_address' => $this->get_ip_address(),
			'device_fingerprint_id' => $this->device_finger_print( $order_id ),
		);

		$new = array();

		// Loop through array to check if the Key pair value is not empty, if empty exclude before siging the fields
		foreach ( $data_array as $name => $value)
		{ 
			if( !$value == '' )
			{
				$new[] = $name; 
			}
		}

		// Create comma separated list from array. The list will be created based on the Device Finger Print Status
		$signed_field_names = "signed_field_names,";
		$signed_field_names .= implode( ',', $new );


		// Add the signed_field_names field to the Array to aid generate a HASH_MAC(Signature) value
		$data_to_merge = array_merge( $data_array, array( "signed_field_names" => $signed_field_names ) );

		// Pass the Data to Security.php to generate Signature 
		foreach( $data_to_merge as $name => $value )
		{
			$params[ $name ] = $value;
		}

		// Add Signature field to the array before POSTing to CyberSource
		$data_to_post = array_merge( $data_to_merge, array( "signature" => sign( $params ) ) );

		// Get the order
		$order = wc_get_order( $order_id );

		echo wpautop( __( 'Enter your Card details below.', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ) );
		
		if ( $this->is_test_mode() )
		{
			echo "<p style=\"color: #FF0000;\"><strong>" . __( 'TEST MODE ENABLED', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ) . "</strong></p>\n";
		}
		
		/**
		 * Build form name value pairs from Checkout page.
		 * 
		 * This is were we submit the form fileds to CyberSource as per CyberSource API
		 */
?>
	<form action="<?php echo $this->get_action_url(); ?>" method="POST" class="checkout_cybersource_sop" >

<?php
			// Iterate and list the fields to post to CyberSource Endpoint url
			foreach( $data_to_post as $name => $value )
			{
				echo "<input type=\"hidden\" id=\"" . $name . "\" name=\"" . $name . "\" value=\"" . $value . "\"/>\n";
			}

			// Unsigned field names not part of the $data_to_post array
			echo "<input type=\"hidden\" id=\"card_expiry_date\" name=\"card_expiry_date\"/>\n";
			echo "<input type=\"hidden\" id=\"card_number\" name=\"card_number\"/>\n";
			echo "<input type=\"hidden\" id=\"card_cvn\" name=\"card_cvn\"/>\n";
			echo "<input type=\"hidden\" id=\"card_type\" name=\"card_type\"/>\n";
?>
		
		<div id="payment">
		<ul class="payment_methods methods">
		<li>
		<div class="payment_box payment_method_cybersource">
		<fieldset>
			<?php if ( $this->description ) echo "<p>{$this->description}</p>"; ?>
			<p class="form-row form-row-first">
				<label for="card_number"><?php _e( 'Credit Card Number', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?> <span class="required">*</span></label>
				<input id="card_number1" name="card_number" value="" size="30" type="text" maxlength="19" class="input-text" autocomplete="off" />
			</p>
			<p class="form-row form-row-last">
				<label for="card_type"><?php _e( 'Card Type', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?> <span class="required">*</span></label>
				<select name="card_type" id="card_type1" style="width:auto;"><br>
					<option value="">
					<?php
						foreach ( $this->card_type as $type ) :
							if ( isset( $this->card_type_options[ $type ] ) ) :
								?>
								<option value="<?php echo esc_attr( preg_replace( '/-.*$/', '', $type ) ); ?>" rel="<?php echo esc_attr( $type ); ?>"><?php _e( $this->card_type_options[ $type ], WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?></option>
								<?php
							endif;
						endforeach;
					?>
				</select>
			</p>
			<div class="clear"></div>
			<p class="form-row form-row-first">
				<label for="card_expiry_month"><?php _e( 'Expiration Date', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?> <span class="required">*</span></label>
				<select id="card_expiry_month" style="width:auto;">
					<option value=""><?php _e( 'Month', 'wc_elavon' ) ?></option>
					<?php foreach ( range( 1, 12 ) as $month ) : ?>
						<option value="<?php echo sprintf( '%02d', $month ) ?>"><?php echo sprintf( '%02d', $month ) ?></option>
					<?php endforeach; ?>
				</select>
				<select id="card_expiry_year" style="width:auto;">
					<option value=""><?php _e( 'Year', 'wc_elavon' ) ?></option>
					<?php foreach ( range( date( 'Y' ), date( 'Y' ) + 10 ) as $year ) : ?>
						<option value="<?php echo $year ?>"><?php echo $year ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="form-row form-row-last">
				<?php if ( $this->display_ssl_seal() ) : ?><img id="cybersource_sop_ssl_seal" style="float:right;box-shadow:none;" src="<?php echo $wc_cybersource_secure_acceptance_sop->plugin_url() . '/images/seal-ssl.png' ?>" /><?php endif; ?>
				<?php if ( $this->display_bank_logo() ) : ?><img id="cybersource_sop_bank_logo" style="float:right;box-shadow:none;" src="<?php echo $wc_cybersource_secure_acceptance_sop->plugin_url() . '/images/logo.png' ?>" /><?php endif; ?>
				<label for="card_cvn" style="float:left;"><?php _e( 'Card Security Code', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?> <span class="required">*</span></label>
				<input id="card_cvn1" style="float:left;clear:left;display:block;width:auto;" name="card_cvn" size="4" type="text" maxlength="4" class="input-text" autocomplete="off" style="width:auto;" />
			</p>
			<div class="clear"></div>
		</fieldset>
		</div>
		</li>
		</ul>
		</div>

		<p><?php _e( '<strong>Important:</strong> All fields with <span class="required">*</span> are required.', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?></p>
		<input type="submit" name="confirm_pay" value="<?php _e( 'Confirm and Pay', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ) ?>" class="button alt" /> <a href="<?php echo $this->get_payment_page_url( $order, true ) ?>"><?php _e( 'Back', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ) ?></a>
	</form>

	<script type="text/javascript">
		jQuery(document).ready(function($)
		{

		<?php
			/**
			* To successfully implement Device Fingerprinting, an invisible 1-pixel image file and two
			* scripts need to be placed in the <body> tag of the checkout page (the page prior to directing
			* the customer to Secure Acceptance) at the top of the main body. This ensures a 3-5 second
			* window in which the code segments can complete the data collection necessary to create a
			* fingerprint for the device making the order.
			*/
			if ( $this->is_device_finger_print_enabled() )
			{
				echo $this->html_device_finger_print( $order_id );
			}
		?>

			$('form.checkout_cybersource_sop').submit(function()
			{
				var form = $(this);

				if (form.is('.processing')) return false;

				var errors = new Array();

				var cardType        = $('#card_type1').val();
				var accountNumber   = $('#card_number1').val();
				var cvNumber        = $('#card_cvn1').val();
				var expirationMonth = $('#card_expiry_month').val();
				var expirationYear  = $('#card_expiry_year').val();

				if (!cardType)
				{
					errors.push("<?php _e( 'Please Select A Card Type', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>");
				}

				if (!cvNumber)
				{
					errors.push("<?php _e( 'Card Security Code (CVV or CVN) Is Missing', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>");
				} else if (/\D/.test(cvNumber))
				
				{
					errors.push("<?php _e( 'Card Security Code Is Invalid (ONLY Digits Are Allowed)', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>");
				} else if ( (3 != cvNumber.length && ['001', '002', '042'].indexOf(cardType) > -1) || (4 != cvNumber.length && '003' == cardType) )
				
				{
					errors.push("<?php _e( 'Card Security Code Is Invalid (Wrong Length)', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>");
				}

				var currentYear = new Date().getFullYear();
				if (/\D/.test(expirationMonth) || /\D/.test(expirationYear) ||
					expirationMonth > 12 ||
					expirationMonth < 1 ||
					expirationYear < currentYear ||
					expirationYear > currentYear + 20 )
				{
					errors.push("<?php _e( 'Card Expiration Date Is Invalid', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>");
				}

				accountNumber = accountNumber.replace(/-|\s/g, '');  // replace any dashes or spaces in the card number
				if (!accountNumber)
				{
					errors.push("<?php _e( 'Missing Credit Card Number', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>");
				} else if (accountNumber.length < 12 || accountNumber.length > 19 || /\D/.test(accountNumber) || !luhnCheck(accountNumber))
				
				{
					errors.push("<?php _e( 'Card Number Is Invalid', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>");
				}

				if (errors.length > 0)
				{

					// hide and remove any previous errors
					$('.woocommerce-error').remove();

					$('.order_details').parent().prepend('<ul class="woocommerce_error woocommerce-error" style="display:none;"><li>'+errors.join('</li><li>')+'</li></ul>');

					// scroll the errors into the viewport if needed
					if ($(document).scrollTop() > $('.order_details').parent().offset().top)
					{
						$('.woocommerce-error').show();
						$('html, body').animate({
							scrollTop: ($('.order_details').parent().offset().top - 50)
						}, 700);
					} else
					
					{
						// otherwise animate the error display
						$('.woocommerce-error').slideDown();
					}
					return false;
				} else
				
				{

					// no errors: animate and hide the previous errors
					$('.woocommerce-error').slideUp();
				}

				form.addClass('processing').block(
				{
					message: '<img src="<?php echo esc_url( $wc_cybersource_secure_acceptance_sop->plugin_url() ); ?>/images/ajax-loader.gif" alt="Redirecting&hellip;" style="float:left; margin-right: 10px; box-shadow:none;" /><?php echo esc_js( 'Thank you for your order.  Please do not refresh your browser or click "back" while we are processing your payment otherwise you may be charged twice.', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>',
					overlayCSS:
					{
						background: "#fff",
						opacity: 0.6
					},
					css:
					{
						padding:         20,
						textAlign:       "center",
						color:           "#555",
						border:          "3px solid #aaa",
						backgroundColor: "#fff",
						cursor:          "wait",
						width:           "50%"
					}
				});
				
				// Concatenate the month and year (mm-yyyy) as it is the format that CyberSource expects
				$('#card_expiry_date').val( expirationMonth + '-' + expirationYear );
				
				// get rid of any space/dash characters
				$('#card_number').val( accountNumber );
				$('#card_cvn').val( cvNumber );
				$('#card_type').val( cardType );

			}); // End Submit Function

			function luhnCheck( accountNumber )
			{
				var sum = 0;
				for ( var i = 0, ix = accountNumber.length; i < ix - 1; i++ )
				{
					var weight = parseInt( accountNumber.substr( ix - ( i + 2 ), 1 ) * ( 2 - ( i % 2 ) ) );
					sum += weight < 10 ? weight : weight - 9;
				}

				return accountNumber.substr( ix - 1 ) == ( ( 10 - sum % 10 ) % 10 );
			}

		});
	</script>
	<?php
	}

	/** Begin - Response Gateway Handler ***********************************************************/

	/**
	 * Relay response - handles return data from CyberSource and does redirects
	 */
	public function cybersource_response()
	{
		global $woocommerce, $wc_cybersource_secure_acceptance_sop;
		
		// Array for CyberSource Reason Codes sent with every transaction request.
		$reasonCodes = array(
			'100' => 'Card transaction was processed successfully.',
			'102' => 'One or more fields in the request contain invalid data. <p style=\"color: #FF0000;\"><strong>Possible Action: see the reply fields invalid_fields for which fields are invalid. Resend the request with the correct information.</strong></p>',
			'104' => 'The access_key and transaction_uuid fields for this authorization request matches the access_key and transaction_uuid of another authorization request that you sent within the past 15 minutes. <p style=\"color: #FF0000;\"><strong>Possible Action: resend the request with a unique access_key and transaction_uuid fields.</strong></p>',
			'110' => 'Only a partial amount was approved.',
			'150' => 'Error - General system failure. <p style=\"color: #FF0000;\"><strong>Possible Action: See the documentation for your CyberSource client (SDK) for information about how to handle retries in the case of system errors.</strong></p>',
			'200' => 'The authorization request was approved by the issuing bank but declined by CyberSource because it did not pass Address Verification System (AVS) check. <p style=\"color: #FF0000;\"><strong>Possible Action: you can capture the authorization, but consider reviewing the order for the possibility of fraud.</strong></p>',
			'201' => 'The issuing bank has questions about the request. You do not receive an authorization code programmatically, but you might receive one verbally by calling the processor. <p style=\"color: #FF0000;\"><strong>Possible Action: call your processor to possibly receive a verbal authorization. For contact phone numbers, refer to Barclays Bank, Card Centre.</strong></p>',
			'202' => 'Expired card. You might also receive this value if the expiration date you provided does not match the date the issuing bank has on file. <p style=\"color: #FF0000;\"><strong>Possible Action: request a different card or other form of payment.</strong></p>',
			'203' => 'General decline of the card. No other information was provided by the issuing bank. <p style=\"color: #FF0000;\"><strong>Possible Action: request a different card or other form of payment.</strong></p>',
			'204' => 'Insufficient funds in the account. <p style=\"color: #FF0000;\"><strong>Possible Action: request a different card or other form of payment.</strong></p>',
			'205' => 'Stolen or lost card. <p style=\"color: #FF0000;\"><strong>Possible Action: review this transaction manually to ensure that you submitted the correct information.</strong></p>',
			'207' => 'Issuing bank unavailable. <p style=\"color: #FF0000;\"><strong>Possible Action: wait a few minutes and resend the request.</strong></p>',
			'208' => 'Inactive card or card not authorized for card-not-present transactions. <p style=\"color: #FF0000;\"><strong>Possible Action: request a different card or other form of payment.</strong></p>',
			'210' => 'The card has reached the credit limit. <p style=\"color: #FF0000;\"><strong>Possible Action: request a different card or other form of payment.</strong></p>',
			'211' => 'Invalid CVN. <p style=\"color: #FF0000;\"><strong>Possible Action: request a different card or other form of payment.</strong></p>',
			'221' => 'The customer matched an entry on the processor\'s negative file.',
			'222' => 'Account frozen or closed.',
			'230' => 'The authorization request was approved by the issuing bank but declined by CyberSource because it did not pass the Card Verification Number (CVN) check. <p style=\"color: #FF0000;\"><strong>Possible Action: you can capture the authorization, but consider reviewing the order for the possibility of fraud.</strong></p>',
			'231' => 'Invalid account number. <p style=\"color: #FF0000;\"><strong>Possible Action: request a different card or other form of payment.</strong></p>',
			'232' => 'The card type is not accepted by the payment processor. <p style=\"color: #FF0000;\"><strong>Possible Action: contact Barclays Bank, Card Centre to confirm that your account is set up to receive the card in question.</strong></p>',
			'233' => 'General decline by processor. <p style=\"color: #FF0000;\"><strong>Possible Action: request a different card or other form of payment.</strong></p>',
			'234' => 'There is a problem with the information in your CyberSource account. <p style=\"color: #FF0000;\"><strong>Possible Action: do not resend the request. Contact Barclays Bank, Card Centre to correct the information in your account.</strong></p>',
			'236' => 'Processor failure. <p style=\"color: #FF0000;\"><strong>Possible Action: wait a few minutes and resend the request.</strong></p>',
			'240' => 'The card type is invalid or does not correlate with the credit card number. <p style=\"color: #FF0000;\"><strong>Possible Action: Possible Action: confirm that the card type correlates with the credit card number specified in the request, then resend the request.</strong></p>',
			'475' => 'The cardholder is enrolled for payer authentication. <p style=\"color: #FF0000;\"><strong>Possible Action: authenticate cardholder before proceeding.</strong></p>',
			'476' => 'Payer authentication could not be authenticated.',
			'481' => 'The order has been rejected by Decision Manager.',
			'520' => 'The authorization request was approved by the issuing bank but declined by CyberSource based on your legacy Smart authorization settings. <p style=\"color: #FF0000;\"><strong>Possible Action: review the authorization request.</strong></p>',
		);

		// the api url is shared with the SOP echeck plugin, so make sure this is a card transaction before going any further
		if ( ! isset( $_POST['req_payment_method'] ) || 'card' != $_POST['req_payment_method'] ) return;

		// Include the Security file that is used to sign the API fields
		require_once( 'cybersource_security/security.php' );

		// Log the Response received from CyberSource - Used for Troubleshooting purposes
		if ( $this->log_enabled() ) $this->log_request( "CyberSource Response Fields: " );

		// Loop through the $_POST Array
		foreach( $_POST as $name => $value )
		{
			$params[$name] = $value;
		}

		// Verify the Signature before processing any further. This will ensure that no fields have been tampered with.
		if (strcmp($params["signature"], sign($params))==0)
		{

			/**
			 * The following ORDER statuses are used:
			 * Pending - Order received(Unpaid)
			 * Failed - Payment failed or was declined(Unpaid)
			 * Processing - Payment received and stock has been reduced: the order is awaiting fulfilment
			 * Completed - Order fulfilled and complete: requires no further action
			 * On-Hold - Awaiting payment: stock is reduced but you need to confirm payment
			 * Cancelled - Cancelled by an admin or the customer: no further action required
			 * Refunded - Refunded by an admin: No further action required
			 */
			$order_id = explode('_', $_POST['req_reference_number']);
			$order_id = (int)$order_id[0];

			$order = wc_get_order( $order_id );

			// If the payment got sent twice somehow and completed successfully add a note and, redirect to the 'thank you' page
			if ( 'completed' == $order->status || 'processing' == $order->status )
			{
				// Log the transaction details to the LOG file.
				if ( $this->log_enabled() ) $wc_cybersource_secure_acceptance_sop->log( sprintf( "Possible Duplicate, Order %s has already been processed", $order->id ) );

				$order_note = __( 'Duplicate transaction received:', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN );
				$order->add_order_note( $order_note );

				wp_redirect( $this->get_return_url( $order ) );
				exit;
			}

			/**
			 * Handle the transaction response according to
			 * the Decision that has been sent.
			 */
			$decision = $_POST['decision'];

			SWITCH ($decision)
			{

				CASE "ACCEPT":

					if ( $this->log_enabled() ) $wc_cybersource_secure_acceptance_sop->log( sprintf( "Order %s has being processed successfully.", $order->id ) );
					// Add a note that goes with the transaction from CyberSource
					$order_note = sprintf(__( $reasonCodes[$this->get_post( 'reason_code' )], WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ) );
					$order->add_order_note( $order_note );

					// Payment complete
					 $order->payment_complete();

					// Remove cart
					$woocommerce->cart->empty_cart();

					// Redirect to the Thank You page
					wp_redirect( $this->get_return_url( $order ), 302 );

					exit;

					break;

				CASE "DECLINE":

					if ( $this->log_enabled() ) $wc_cybersource_secure_acceptance_sop->log( sprintf( "Order %s has being DECLINED by Decision Manager.", $order->id ) );
					// Place on-hold for the Admin to Review
					$order_note = sprintf( __( $reasonCodes[$this->get_post( 'reason_code' )], WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ) );

					if ( 'failed' != $order->status )
					{

						$order->update_status( 'failed', $order_note );

					} else
					
					{

						// Otherwise, make sure we add the order note so we can detect when someone fails to check out multiple times
						$order->add_order_note( $order_note );
					}

					// Redirect to the Thank You page
					wp_redirect( $this->get_return_url( $order ), 302 );
					exit;

					break;

				CASE "REVIEW":

					if ( $this->log_enabled() ) $wc_cybersource_secure_acceptance_sop->log( sprintf( "Order %s has being placed on-hold because Decision Manager has marked it for REVIEW.", $order->id ) );
					// Place on-hold for the Admin to Review
					$order_note = sprintf( __( $reasonCodes[$this->get_post( 'reason_code' )], WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ) );

					if ( 'on-hold' != $order->status )
					{

						$order->update_status( 'on-hold', $order_note );

					} else
					
					{

						// Otherwise, make sure we add the order note so we can detect when someone fails to check out multiple times
						$order->add_order_note( $order_note );
					}

					// Redirect to the Thank You page
					wp_redirect( $this->get_return_url( $order ), 302 );
					exit;

					break;

				CASE "ERROR":

					if ( $this->log_enabled() ) $wc_cybersource_secure_acceptance_sop->log( sprintf( "Possible Error in processing, Order %s . Please check the Cybersource settings.", $order->id ) );
					// Place on Failed for the Admin to Review
					$order_note = sprintf( __( 'Access denied, page not found, or internal server error: code %s%s', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ), $this->get_post( 'reason_code' ), $error_message );

					if ( 'failed' != $order->status )
					{

						$order->update_status( 'failed', $order_note . $order_message );

					} else
					
					{

						// Otherwise, make sure we add the order note so we can detect when someone fails to check out multiple times
						$order->add_order_note( $order_note . $order_message );
					}

					// Redirect to the Thank You page
					wp_redirect( $this->get_return_url( $order ), 302 );
					exit;

					break;

				CASE "CANCEL":

					if ( $this->log_enabled() ) $wc_cybersource_secure_acceptance_sop->log( sprintf( "The Order has been cancelled by the customer, Order %s ", $order->id ) );
					// Place on Cancelled for the Admin to Review
					$order_note = sprintf( __( 'The Order has been cancelled by the customer: code %s%s', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ), $this->get_post( 'reason_code' ), $error_message );

					if ( 'Cancelled' != $order->status )
					{

						$order->update_status( 'Cancelled', $order_note . $order_message );

					} else
					
					{

						// Otherwise, make sure we add the order note so we can detect when someone fails to check out multiple times
						$order->add_order_note( $order_note . $order_message );
					}

					// Redirect to the Thank You page
					wp_redirect( $this->get_return_url( $order ), 302 );
					exit;

					break;

				DEFAULT:

					// Log this when a UNKNOWN DECISION is sent through
					if ( $this->log_enabled() ) $wc_cybersource_secure_acceptance_sop->log( sprintf( "Unknown Decision sent for Order %s, it has been placed on-hold for investigation", $order->id ) );

					// Place order on-hold for the Admin
					$order_note = sprintf( __( 'Unknown Decision Received from Gateway: ' . $_POST['decision']. ' with reason code %s%s, Please contact the Barclays Bank, Card Centre for Investigations.', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ), $this->get_post( 'reason_code' ), $error_message );

					if ( 'on-hold' != $order->status )
					{

						$order->update_status( 'on-hold', $order_note );

					} else
					
					{

						// Otherwise, make sure we add the order note to enable investigations for a unknown decision
						$order->add_order_note( $order_note );
					}

					// Redirect to the Thank You page
					wp_redirect( $this->get_return_url( $order ), 302 );
					exit;

			} // End of SWITCH Statement

		} else
		
		{

			// Signature Verification failed, response was not properly signed by CyberSource
			if ( $this->log_enabled() ) $wc_cybersource_secure_acceptance_sop->log( sprintf( "Signature Verification failed for this Order " . $params['req_reference_number'] . ", please check CyberSource Settings. Generated signature by this Gateway is: " . sign($params), $order->id ) );
			echo __( "Error - invalid transaction signature (check CyberSource settings).  Please contact the merchant and provide them with this message.", WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN );

			// Redirect to the Thank You page
			wp_redirect( $this->get_return_url( $order ), 302 );
			exit;
		}
	}


	/** End - Response Gateway Handler ***********************************************************/

	/**
	 *
	 * Get Available Profile ID, Access Key and Secret Key to be used for the request
	 *
	 */
	function is_available()
	{

		// proper configuration
		if ( ! $this->get_profile_id() || ! $this->get_access_key() || ! $this->get_secret_key() || ! $this->get_merchant_id() ) return false;

		return parent::is_available();
	}


	/** Admin methods ******************************************************/


	/**
	 * Add a button to the order actions meta box to view the order in CyberSource
	 *
	 * @param WC_Order $order the order object
	 */
	public function order_meta_box_transaction_link( $order )
	{
		if ( $url = $this->get_transaction_url( $order ) )
		{
			?>
			<li class="wide" style="text-align: center;">
				<a class="button tips" href="<?php echo esc_url( $url ); ?>" target="_blank" data-tip="<?php _e( 'View this transaction in the CyberSource Business Center', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?>" style="cursor: pointer !important;"><?php _e( 'View in CyberSource', WC_Cybersource_Secure_Acceptance_SOP::TEXT_DOMAIN ); ?></a>
			</li>
			<?php
		}
	}


	/** Helper methods ******************************************************/


	/**
	 * Log the CyberSource request to woocommerce/logs/cybersource_secure_acceptance_sop.txt
	 *
	 * @param string $title the title to display
	 */
	private function log_request( $title )
	{
		global $wc_cybersource_secure_acceptance_sop;

		$response = $_POST;
		unset( $response['wc-api'] );
		$wc_cybersource_secure_acceptance_sop->log( $title . "\n" . print_r( $response, true ) );
	}


	/** Getter methods ******************************************************/


	// Safely get post data if set
	private function get_post( $name )
	{
		if ( isset( $_POST[ $name ] ) )
		{
			return trim( $_POST[ $name ] );
		}
		return null;
	}


	/**
	 * Returns the URL to the payment page
	 *
	 * @param WC_Order $order the order object
	 * @param boolean $choose_payment_page if true the url will be for the
	 *        payment page where the payment method can be chosen
	 *
	 * @return string url to the payment page
	 */
	private function get_payment_page_url( $order, $choose_payment_page = false )
	{

		$payment_page = get_permalink( woocommerce_get_page_id( 'checkout' ) );

		// make ssl if needed
		if ( is_ssl() || 'yes' == get_option( 'woocommerce_force_ssl_checkout' ) ) $payment_page = str_replace( 'http:', 'https:', $payment_page );

		// URL for the choose payment method page
		if ( $choose_payment_page ) return add_query_arg( array( 'order_id' => $order->id, 'order-pay' => $order->order_key, 'pay_for_order' => true ), $payment_page );

		// URL for the payment page
		return add_query_arg( array( 'order-pay' => $order->id, 'key' => $order->order_key ), $payment_page );
	}


	/**
	 * Return the profile id for the current mode
	 *
	 * @return string profile id
	 */
	public function get_profile_id()
	{
		return $this->is_test_mode() ? $this->profile_id_test : $this->profile_id;
	}


	/**
	 * Returns the action URL for cybersource for the current mode (test/live)
	 *
	 * @return string action post URL
	 */
	private function get_action_url()
	{
		return $this->is_test_mode() ? $this->test_url : $this->live_url;
	}


	/**
	 * Return the secret key for the current mode
	 *
	 * @return string secret key
	 */
	public function get_secret_key()
	{
		return $this->is_test_mode() ? $this->secret_key_test : $this->secret_key;
	}


	/**
	 * Return the access key for the current mode
	 *
	 * @return string access key
	 */
	public function get_access_key()
	{
		return $this->is_test_mode() ? $this->access_key_test : $this->access_key;
	}


	/**
	 * Return the Org ID for the current mode
	 *
	 * @return string org_id
	 */
	public function get_org_id()
	{
		return $this->is_test_mode() ? $this->test_org_id : $this->live_org_id;
	}


	/**
	 * Return the Merchant ID
	 *
	 * @return string merchant_id
	 */
	public function get_merchant_id()
	{
		return $this->merchant_id;
	}


	// Get Client GEO location
	private function get_ip_address()
	{
		$url = "http://ipinfo.io/";
		$json = file_get_contents($url);
		$data = json_decode($json);
		
		return $data->ip;
	}


	/**
	 * Is test mode enabled?
	 *
	 * @return boolean true if test mode is enabled
	 */
	private function is_test_mode()
	{
		return "yes" == $this->testmode;
	}


	/**
	 * Is debug mode enabled?
	 *
	 * @return boolean true if debug mode is enabled
	 */
	private function is_debug_mode()
	{
		return "yes" == $this->debug;
	}


	/**
	 * Should CyberSource communication be logged?
	 *
	 * @return boolean true if log mode is enabled
	 */
	private function log_enabled()
	{
		return "yes" == $this->log;
	}


	/**
	 * Is Device Finger Print on?
	 *
	 * @return boolean true if Device Finger Print mode is enabled
	 */
	private function is_device_finger_print_enabled()
	{
		return "yes" == $this->device_finger_print;
	}


	/**
	 * Should the SSL Seal be displayed on the Pay page?
	 *
	 * @return boolean true if the seal should be displayed; false otherwise
	 */
	private function display_ssl_seal()
	{
		return "yes" == $this->sslseal;
	}


	/**
	 * Should the Bank Logo be displayed on the Pay page?
	 *
	 * @return boolean true if the logo should be displayed; false otherwise
	 */
	private function display_bank_logo()
	{
		return "yes" == $this->banklogo;
	}


	/**
	 * The fields will only be part of the form if Device Finger Print
	 * has been enabled in the Admin Settings for this Plugin in WooCommerce.
	 */
	private function device_finger_print( $order_id )
	{	
		if( $this->is_device_finger_print_enabled() )
		{
			global $woocommerce, $wc_cybersource_secure_acceptance_sop;
			$order = wc_get_order( $order_id );
			return $order->id . substr( $order->order_key,9 );
		}
	}


	// Return Device Fingerprinting code segments
	private function html_device_finger_print( $order_id )
	{
		$js_html  =			'var org_ID = \'' . $this->get_org_id() . '\';';
		$js_html .=			'var session_ID = \'' . $this->device_finger_print( $order_id ) . '\';';
		$js_html .=			'var merchant_ID = \'' . $this->get_merchant_id() . '\';';

		$js_html .=			'function device_finger_print()';
		$js_html .=			'{';
		
		// The code segments for implementing Device Fingerprinting i.e. PNG Image, Flash Code and JavaScript Code respectively
		$js_html .=				'var str_img = \'<p style="background:url(https://h.online-metrix.net/fp/clear.png?org_id=\' + org_ID + \'&amp;session_id=\' + merchant_ID + session_ID + \'&amp;m=1)"></p><img src="https://h.online-metrix.net/fp/clear.png?org_id=\' + org_ID + \'&amp;session_id=\' + merchant_ID + session_ID + \'&amp;m=2" alt="">\';';
		$js_html .=				'var str_obj = \'<object type="application/x-shockwave-flash" data="https://h.online-metrix.net/fp/fp.swf?org_id=\' + org_ID + \'&amp;session_id=\' + merchant_ID + session_ID + \'" width="1" height="1" id="thm_fp"><param name="movie" value="https://h.online-metrix.net/fp/fp.swf?org_id=\' + org_ID + \'&amp;session_id=\' + merchant_ID + session_ID + \'" /><div></div></object>\';';
		$js_html .=				'var str_script = \'<script src="https://h.online-metrix.net/fp/check.js?org_id=\' + org_ID + \'&amp;session_id=\' + merchant_ID + session_ID + \'" type="text/javascript">\';';

		$js_html .=				'return str_img + str_obj + str_script ;';

		$js_html .=			'}'; //End of device_finger_print function

		$js_html .=			'$("body").prepend( device_finger_print() )'; // Prepend Device Fingerprinting code segments to body tag

		return $js_html;
	}


	/**
	 * Build the URL for the current page that will be used to post to self.
	 * I could have used the PHP_SELF or $_SERVER['PHP_SELF'] function but 
	 * unfortunately the later gives a blank action url and the former redirects to 
	 * the WordPress Index.php page as it is used as the entry point to the site.
	 */
	private function self_URL()
	{
		$ret = substr( strtolower($_SERVER['SERVER_PROTOCOL']),0,strpos( strtolower($_SERVER['SERVER_PROTOCOL']),"/")); // Add protocol (like HTTP)
		$ret .= ( empty($_SERVER['HTTPS']) ? NULL : (($_SERVER['HTTPS'] == "on") ? "s" : NULL )); // Add 's' if protocol is secure HTTPS
		$ret .= "://" . $_SERVER['SERVER_NAME']; // Add domain name/IP address
		$ret .= ( $_SERVER['SERVER_PORT'] == 80 ? "" : ":".$_SERVER['SERVER_PORT']); // Add port directive if port is not 80 (default WWW port)
		$ret .= $_SERVER['REQUEST_URI']; // Add the rest of the URL
		
		return $ret; // Return the value
	}


	/**
	 * autocompleteOrders 
	 * Autocomplete Orders
	 * @return void
	 */
	function autocompleteOrders()
	{
		$mode = get_option('wc_'.$this->id.'_mode');
		if ($mode == 'all')
		{
			add_action('woocommerce_thankyou', 'autocompleteAllOrders');
			/**
			 * autocompleteAllOrders 
			 * Register custom tabs Post Type
			 * @return void
			 */
			function autocompleteAllOrders($order_id)
			{
				global $woocommerce;

				if (!$order_id)
					return;
				$order = new WC_Order($order_id);
				$order->update_status('completed');
			}
		} elseif ($mode == 'paid') {
			add_filter('woocommerce_payment_complete_order_status', 'autocompletePaidOrders', 10, 2);
			/**
			 * autocompletePaidOrders 
			 * Register custom tabs Post Type
			 * @return void
			 */
			function autocompletePaidOrders($order_status, $order_id)
			{
				$order = new WC_Order($order_id);
				if ($order_status == 'processing' && ($order->status == 'on-hold' || $order->status == 'pending' || $order->status == 'failed')) 
				{
					return 'completed';
				}
				return $order_status;
			}
		} elseif ($mode == 'virtual') {
			add_filter('woocommerce_payment_complete_order_status', 'autocompleteVirtualOrders', 10, 2);
			/**
			 * autocompleteVirtualOrders 
			 * Register custom tabs Post Type
			 * @return void
			 */
			function autocompleteVirtualOrders($order_status, $order_id)
			{
				$order = new WC_Order($order_id);
				if ('processing' == $order_status && ('on-hold' == $order->status || 'pending' == $order->status || 'failed' == $order->status)) 
				{
					$virtual_order = null;
					if (count($order->get_items()) > 0 ) 
					{
						foreach ($order->get_items() as $item) 
						{
							if ('line_item' == $item['type']) 
							{
								$_product = $order->get_product_from_item($item);
								if (!$_product->is_virtual()) 
								{
									$virtual_order = false;
									break;
								} else {
									$virtual_order = true;
								}
							}
						}
					}
					if ($virtual_order) 
					{
						return 'completed';
					}
				}
				return $order_status;
			}
		}
	}


	/**
	 * Returns the CyberSource business center transaction URL for the given order
	 *
	 * @param WC_Order $order the order object
	 * @param string $request_id optional request identifier
	 * @param string $environment optional environment identifier
	 *
	 * @return string cybersource transaction url or empty string
	 */
/*	private function get_transaction_url( $order, $request_id = null, $environment = null )
	{

		if ( ! $request_id && isset( $order->order_custom_fields['_cybersource_request_id'][0] ) && $order->order_custom_fields['_cybersource_request_id'][0] )
			$request_id = $order->order_custom_fields['_cybersource_request_id'][0];

		if ( ! $environment && isset( $order->order_custom_fields['_cybersource_orderpage_environment'][0] ) && $order->order_custom_fields['_cybersource_orderpage_environment'][0] )
			$environment = $order->order_custom_fields['_cybersource_orderpage_environment'][0];

		if ( $request_id && $environment )
		{

			// build the URL to the test/production environment
			if ( 'TEST' == $environment )
				$url = "https://ebctest.cybersource.com/ebctest/transactionsearch/TransactionSearchLoad.do?requestId=%s";
			else
				$url = "https://ebc.cybersource.com/ebc/transactionsearch/TransactionSearchLoad.do?requestId=%s";

			return sprintf( $url, $request_id );
		}

		return '';
	}
*/

} // End of WC_Cybersource_Secure_Acceptance_SOP Class
