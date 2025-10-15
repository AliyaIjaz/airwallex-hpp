<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This class contains a webservice function to get configuration for Airwallex SDK-driven HPP.
 *
 * @package    paygw_airwallex
 * @copyright  2020 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace paygw_airwallex\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;
use core_external\external_single_structure;
use core_payment\helper;
use paygw_airwallex\airwallex_helper;

class get_hpp_config extends external_api {

    /**
     * Returns description of method parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'component' => new external_value(PARAM_COMPONENT, 'Component'),
            'paymentarea' => new external_value(PARAM_AREA, 'Payment area in the component'),
            'itemid' => new external_value(PARAM_INT, 'An identifier for payment area in the component'),
        ]);
    }

    /**
     * Returns the config values required by the Airwallex JavaScript SDK for HPP.
     *
     * @param string $component
     * @param string $paymentarea
     * @param int $itemid
     * @return array
     * @throws \moodle_exception
     */
    public static function execute(string $component, string $paymentarea, int $itemid): array {
        global $CFG;

        self::validate_parameters(self::execute_parameters(), [
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
        ]);

        $config = (object)helper::get_gateway_configuration($component, $paymentarea, $itemid, 'airwallex');
        if (empty($config->clientid) || empty($config->apikey)) {
            throw new \moodle_exception('airw_notconfigured', 'paygw_airwallex');
        }

        $payable = helper::get_payable($component, $paymentarea, $itemid);
        $surcharge = helper::get_gateway_surcharge('airwallex');

        $cost = helper::get_rounded_cost($payable->get_amount(), $payable->get_currency(), $surcharge);
        $currency = $payable->get_currency();

        // Generate a unique Moodle order ID.
        $moodleorderid = 'mdl_' . $component . '_' . $paymentarea . '_' . $itemid . '_' . time() . '_' . rand(1000, 9999);

        // Construct the return and cancel URLs for Airwallex to redirect back to.
        $baseurl = new \moodle_url('/paygw_airwallex/callback.php', [
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
            // We'll pass the Airwallex payment_intent_id in the URL from their side.
            // But we include original context for Moodle's callback logic.
        ]);
        $returnurl = $baseurl->out(false); // Get absolute URL.
        $cancelurl = $baseurl->out(false) . '&status=cancelled'; // Custom parameter for cancellation.

        $sandbox = ($config->environment ?? 'sandbox') === 'sandbox';
        $airwallexhelper = new airwallex_helper($config->clientid, $config->apikey, $config->webhooksecret ?? '', $sandbox);

        $intent = $airwallexhelper->create_payment_intent(
            (float)$cost,
            (string)$currency,
            $moodleorderid,
            $returnurl,
            $cancelurl,
            'Moodle payment for ' . $component . ' ' . $itemid
        );

        // You might want to temporarily store the payment intent ID here
        // (e.g., in mdl_paygw_airwallex table) linked to component/itemid
        // to help with reconciliation if the callback is delayed or lost.

        return [
            'client_id' => $config->clientid, // For SDK init
            'payment_intent_id' => $intent['id'],
            'client_secret' => $intent['client_secret'], // Crucial for SDK-driven HPP
            'cost' => $cost, // For display if needed
            'currency' => $currency,
            'return_url' => $returnurl, // For SDK's redirectToCheckout
            'cancel_url' => $cancelurl, // For SDK's redirectToCheckout
            'env' => $config->environment ?? 'sandbox', // For SDK init
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'client_id' => new external_value(PARAM_TEXT, 'Airwallex client ID for SDK init'),
            'payment_intent_id' => new external_value(PARAM_TEXT, 'Airwallex payment intent ID'),
            'client_secret' => new external_value(PARAM_TEXT, 'Airwallex client secret for SDK confirm'),
            'cost' => new external_value(PARAM_FLOAT, 'Cost with gateway surcharge'),
            'currency' => new external_value(PARAM_TEXT, 'Currency'),
            'return_url' => new external_value(PARAM_URL, 'URL for successful return from HPP'),
            'cancel_url' => new external_value(PARAM_URL, 'URL for cancellation return from HPP'),
            'env' => new external_value(PARAM_ALPHANUMEXT, 'Airwallex environment (demo or prod)'),
        ]);
    }
}