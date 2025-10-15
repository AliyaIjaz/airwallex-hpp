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
 * This class contains a webservice function to get configuration for Airwallex HPP.
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
            // Optionally pass a redirect base for constructing return/cancel URLs if not constant
            // 'redirectbaseurl' => new external_value(PARAM_URL, 'Base URL for redirects to Moodle', external_value::OPTIONS_OPTIONAL),
        ]);
    }

    /**
     * Returns the HPP redirect URL and payment intent ID.
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

        // Generate a unique Moodle order ID. Ensure this is unique across ALL Moodle payments.
        // It's good practice to also save this ID in a temporary Moodle payment record before calling Airwallex.
        $moodleorderid = 'mdl_' . $component . '_' . $paymentarea . '_' . $itemid . '_' . time() . '_' . rand(1000, 9999);

        // Construct the return and cancel URLs for Airwallex to redirect back to.
        // Ensure these URLs are publicly accessible on your Moodle site.
        // They will typically point to a custom callback script in your plugin.
        $baseurl = new \moodle_url('/paygw_airwallex/callback.php', [
            'component' => $component,
            'paymentarea' => $paymentarea,
            'itemid' => $itemid,
            // You might pass the $moodleorderid here as well, but Airwallex also returns its intent_id.
            // A safer approach for the callback is to fetch details from the payment_intent_id itself.
        ]);
        $returnurl = $baseurl->out(false); // Get absolute URL.
        $cancelurl = $baseurl->out(false) . '&status=cancelled'; // A simple way to signify cancellation.

        $sandbox = ($config->environment ?? 'sandbox') === 'sandbox';
        $airwallexhelper = new airwallex_helper($config->clientid, $config->apikey, $config->webhooksecret ?? '', $sandbox); // Pass webhooksecret, though not used for intent creation

        $intent = $airwallexhelper->create_payment_intent(
            (float)$cost,
            (string)$currency,
            $moodleorderid,
            $returnurl,
            $cancelurl,
            'Moodle payment for ' . $component . ' ' . $itemid
        );

        if (empty($intent['next_action']['redirect_url'])) {
            throw new \moodle_exception('airw_noredirecturl', 'paygw_airwallex', '', $intent);
        }

        // Before returning, it's a good idea to temporarily store the payment intent ID
        // and the Moodle order ID in Moodle's database, linked to the user and item.
        // This is crucial for reconciliation when the user returns via callback.php.
        // For example, into a custom table like paygw_airwallex_intents
        // You'd need to create a table for this (e.g., db/install.xml).
        // For simplicity here, we assume the callback script will directly verify the intent ID.

        return [
            'payment_intent_id' => $intent['id'] ?? '',
            'hpp_redirect_url' => $intent['next_action']['redirect_url'],
            'cost' => $cost,
            'currency' => $currency,
        ];
    }

    /**
     * Returns description of method result value.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'payment_intent_id' => new external_value(PARAM_TEXT, 'Airwallex payment intent ID'),
            'hpp_redirect_url' => new external_value(PARAM_URL, 'URL to redirect to Airwallex HPP'),
            'cost' => new external_value(PARAM_FLOAT, 'Cost with gateway surcharge'),
            'currency' => new external_value(PARAM_TEXT, 'Currency'),
        ]);
    }
}