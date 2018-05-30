<?php
/**
 * CyberSource Secure Acceptance WM - Security Functions
 * Secret key signs the transaction data and is required for each transaction.
 * Enter your security key into the SECRET_KEY (getSecretKey() function) field.
 *
 * The security algorithm in each security script is responsible for:
 *
 * Request authentication—the signature is generated on the merchant server by the keyed-hash 
 * message authentication code (HMAC) signing the request parameters using a shared secret key. 
 * This process is also carried out on the Secure Acceptance server, and the two signatures are 
 * compared for authenticity.
 * 
 * Response authentication—the signature is generated on the Secure Acceptance server by HMAC 
 * signing the response parameters using a shared secret key. This process is also carried out 
 * on the merchant server, and the two signatures are compared for authenticity.
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

// Get the Secret Key from the gateway plug-in saved in WooCommerce.
function getSecretKey()
{
	global $woocommerce;
	$gateways = $woocommerce->payment_gateways->payment_gateways();
	return $gateways['cybersource_secure_acceptance_wm']->get_secret_key();
}

define ('HMAC_SHA256', 'sha256');
define ('SECRET_KEY', getSecretKey());

function sign ($params)
{
  return signData(buildDataToSign($params), SECRET_KEY);
}

function signData($data, $secretKey)
{
    return base64_encode(hash_hmac('sha256', $data, $secretKey, true));
}

function buildDataToSign($params)
{
        $signedFieldNames = explode(",",$params["signed_field_names"]);

		foreach ($signedFieldNames as $field)
		{
           $dataToSign[] = $field . "=" . $params[$field];
        }
        return commaSeparate($dataToSign);
}

function commaSeparate ($dataToSign)
{
    return implode(",",$dataToSign);
}
?>
