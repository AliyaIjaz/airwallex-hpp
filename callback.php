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
 * Airwallex HPP callback endpoint.
 * This script handles the redirect back from Airwallex after payment.
 *
 * @package    paygw_airwallex
 * @copyright  2020 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php'); // Moodle's configuration file
require_once($CFG->libdir.'/payment/externallib.php'); // Moodle payment helper functions
require_once(__DIR__ . '/classes/airwallex_helper.php'); // Your Airwallex helper class

use paygw_airwallex\airwallex_helper;
use core_payment\helper as payment_helper;

// Ensure Moodle is fully initialized
require_login(0, false); // No login required for this page, but we need Moodle context

// Get parameters from the URL. Airwallex will typically add 'payment_intent_id'
// and potentially 'status' or other query parameters.
$component = required_param('component', PARAM_COMPONENT);
$paymentarea = required_param('paymentarea', PARAM_AREA);
$itemid = required_param('itemid', PARAM_INT);
$paymentintentid = optional_param('payment_intent_id', '', PARAM_TEXT); // Airwallex intent ID
$statusparam = optional_param('status', '', PARAM_TEXT); // Custom status for cancel, or Airwallex provided

// URL for redirecting the user back to Moodle's payment confirmation pages
$successurl = new moodle_url('/payment/status.php', [
    'contextid' => \context_system::instance()->id, // Adjust context if needed
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
    'success' => 1,
]);
$failureurl = new moodle_url('/payment/status.php', [
    'contextid' => \context_system::instance()->id, // Adjust context if needed
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
    'success' => 0,
]);

// Handle cancellation first, if applicable (e.g., from our custom cancel_url)
if ($statusparam === 'cancelled') {
    // Optionally, update Moodle's payment record to 'cancelled' if you tracked it
    // then redirect to a failure page.
    redirect($failureurl, get_string('paymentcancelled', 'paygw_airwallex'), 3);
}

// Proceed with payment verification if an intent ID is present
if (!empty($paymentintentid)) {
    try {
        // Retrieve gateway configuration from Moodle
        $config = (object)payment_helper::get_gateway_configuration($component, $paymentarea, $itemid, 'airwallex');
        if (empty($config->clientid) || empty($config->apikey)) {
            throw new \moodle_exception('airw_notconfigured', 'paygw_airwallex');
        }

        $sandbox = ($config->environment ?? 'sandbox') === 'sandbox';
        $airwallexhelper = new airwallex_helper($config->clientid, $config->apikey, $config->webhooksecret ?? '', $sandbox);

        // Verify the payment intent with Airwallex
        $intent = $airwallexhelper->verify_payment_intent($paymentintentid);

        if ($intent && ($intent['status'] ?? '') === airwallex_helper::INTENT_STATUS_SUCCEEDED) {
            // Payment SUCCEEDED. Now, update Moodle's records.
            // Recalculate cost and currency to avoid client-side manipulation.
            $payable = payment_helper::get_payable($component, $paymentarea, $itemid);
            $currency = $payable->get_currency();
            $surcharge = payment_helper::get_gateway_surcharge('airwallex');
            $amount = payment_helper::get_rounded_cost($payable->get_amount(), $currency, $surcharge);

            // IMPORTANT: Check for idempotency to avoid double processing if this callback is hit multiple times.
            // You should have a custom table that stores paymentintentid and a status.
            // If the record exists and is already marked as success, simply redirect to success page.
            global $DB;
            $existingrecord = $DB->get_record('paygw_airwallex', ['intentid' => $paymentintentid]);

            if ($existingrecord && $DB->get_record('payment', ['id' => $existingrecord->paymentid])->status === PAYMENT_STATUS_COMPLETE) {
                // Payment already processed, redirect to success.
                redirect($successurl, get_string('airw_paymentalreadyprocessed', 'paygw_airwallex'), 1);
            }

            // Save payment in Moodle's core payment system
            $paymentid = payment_helper::save_payment($payable->get_account_id(), $component, $paymentarea,
                $itemid, (int) $USER->id, $amount, $currency, 'airwallex');

            // Store the Airwallex intent ID in your custom table (or update if it was a pending record)
            $record = new \stdClass();
            if ($existingrecord) {
                $record->id = $existingrecord->id;
                $record->paymentid = $paymentid; // Link to the newly saved Moodle payment if it was temporary
                $record->intentid = $paymentintentid;
                $DB->update_record('paygw_airwallex', $record);
            } else {
                $record->paymentid = $paymentid;
                $record->intentid = $paymentintentid;
                $DB->insert_record('paygw_airwallex', $record);
            }

            // Deliver the order (e.g., enroll user in course)
            payment_helper::deliver_order($component, $paymentarea, $itemid, $paymentid, (int) $USER->id);

            // Redirect user to Moodle's success page
            redirect($successurl, get_string('paymentsuccess', 'paygw_airwallex'), 1);

        } else {
            // Payment failed or not in succeeded state on Airwallex's side
            debugging('Airwallex payment intent ' . $paymentintentid . ' status was not SUCCEEDED. Intent details: ' . print_r($intent, true), DEBUG_DEVELOPER);
            redirect($failureurl, get_string('paymentnotcleared', 'paygw_airwallex'), 3);
        }

    } catch (\Exception $e) {
        debugging('Exception during Airwallex HPP callback: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
        redirect($failureurl, get_string('airw_internalerror', 'paygw_airwallex'), 3);
    }
} else {
    // No payment intent ID, possibly a direct access or an unexpected return
    redirect($failureurl, get_string('airw_invalidcallback', 'paygw_airwallex'), 3);
}