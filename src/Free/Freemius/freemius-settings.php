<?php
/**
 * Freemius settings of Llamly (LLP-455). The product is "Llamly" (slug llamly) in the Freemius store of the
 * PowerFolio account (§4.43). Only public values belong here: never put the secret key in this file.
 *
 * Empty id and public_key = development build: the Freemius SDK is not loaded and nothing is sent anywhere.
 * Even when it is loaded, the SDK sends nothing before an administrator clicks "Allow & Continue" on the
 * Llamly screen (readme, External services). Skipping keeps every feature of Llamly.
 */

if (!defined('ABSPATH')) {
	exit;
}

return [
	// Product ID from the Freemius dashboard (Product > Settings > Keys), digits only.
	'id' => '44617',
	// Product public key from the same page (starts with pk_).
	'public_key' => 'pk_65c1c7ff062ce65e6cea018f79d44',
	// Product slug in Freemius. It must be the plugin folder name.
	'slug' => 'llamly',
	// Where "Llamly Pro" in the Llamly admin points (https only). Empty = the Llamly Pro card has no link yet.
	'upgrade_url' => '',
	// Freemius id of the Llamly Pro add-on (digits only). Llamly > Llamly Pro reads its plans and links to its checkout.
	'pro_addon_id' => '44748',
];
