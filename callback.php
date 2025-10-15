<?php
// ... (Moodle GPL boilerplate and headers) ...

/**
 * Airwallex HPP callback endpoint.
 * This script handles the redirect back from Airwallex after payment.
 *
 * @package    paygw_airwallex
 * @copyright  2020 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir.'/payment/externallib.php');
require_once(__DIR__ . '/classes/airwallex_helper.php');

use paygw_airwallex\airwallex_helper;
use core_payment\helper as payment_helper;

require_login(0, false); // Required for Moodle context, adjust as needed for guests

$component = required_param('component', PARAM_COMPONENT);
$paymentarea = required_param('paymentarea', PARAM_AREA);
$itemid = required_param('itemid', PARAM_INT);
$paymentintentid = optional_param('payment_intent_id', '', PARAM_TEXT); // Airwallex intent ID
$statusparam = optional_param('status', '', PARAM_TEXT); // Custom status for cancel, or Airwallex provided

// URLs for redirecting the user back to Moodle's payment confirmation pages
$successurl = new moodle_url('/payment/status.php', [
    'contextid' => \context_system::instance()->id, // Use appropriate context
    'component' => $component,
    'paymentarea' => $paymentarea,
    'itemid' => $itemid,
    'success' => 1,
]);
$failureurl = new moodle_url('/payment/status.php', [
    'contextid' => \context_system::instance()->id, // Use appropriate context
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
        $config = (object)payment_helper::get_gateway_configuration($component, $paymentarea, $itemid, 'airwallex');
        if (empty($config->clientid) || empty($config->apikey)) {
            throw new \moodle_exception('airw_notconfigured', 'paygw_airwallex');
        }

        $sandbox = ($config->environment ?? 'sandbox') === 'sandbox';
        $airwallexhelper = new airwallex_helper($config->clientid, $config->apikey, $config->webhooksecret ?? '', $sandbox);

        $intent = $airwallexhelper->verify_payment_intent($paymentintentid);

        if ($intent && ($intent['status'] ?? '') === airwallex_helper::INTENT_STATUS_SUCCEEDED) {
            // Payment SUCCEEDED. Now, update Moodle's records.
            $payable = payment_helper::get_payable($component, $paymentarea, $itemid);
            $currency = $payable->get_currency();
            $surcharge = payment_helper::get_gateway_surcharge('airwallex');
            $amount = payment_helper::get_rounded_cost($payable->get_amount(), $currency, $surcharge);

            global $DB;
            $existingrecord = $DB->get_record('paygw_airwallex', ['intentid' => $paymentintentid]);

            // Idempotency check: If already processed, redirect to success.
            if ($existingrecord) {
                $moodlepayment = $DB->get_record('payment', ['id' => $existingrecord->paymentid]);
                if ($moodlepayment && $moodlepayment->status === PAYMENT_STATUS_COMPLETE) {
                    redirect($successurl, get_string('airw_paymentalreadyprocessed', 'paygw_airwallex'), 1);
                }
            }

            $paymentid = payment_helper::save_payment($payable->get_account_id(), $component, $paymentarea,
                $itemid, (int) $USER->id, $amount, $currency, 'airwallex');

            // Store or update the Airwallex intent ID in your custom table
            $record = new \stdClass();
            if ($existingrecord) {
                $record->id = $existingrecord->id;
                $record->paymentid = $paymentid;
                $record->intentid = $paymentintentid;
                $record->timemodified = time();
                $DB->update_record('paygw_airwallex', $record);
            } else {
                $record->paymentid = $paymentid;
                $record->intentid = $paymentintentid;
                $record->timecreated = time();
                $record->timemodified = time();
                $DB->insert_record('paygw_airwallex', $record);
            }

            payment_helper::deliver_order($component, $paymentarea, $itemid, $paymentid, (int) $USER->id);

            redirect($successurl, get_string('paymentsuccess', 'paygw_airwallex'), 1);

        } else {
            debugging('Airwallex payment intent ' . $paymentintentid . ' status was not SUCCEEDED. Intent details: ' . print_r($intent, true), DEBUG_DEVELOPER);
            redirect($failureurl, get_string('paymentnotcleared', 'paygw_airwallex'), 3);
        }

    } catch (\Exception $e) {
        debugging('Exception during Airwallex HPP callback: ' . $e->getMessage() . "\n" . $e->getTraceAsString(), DEBUG_DEVELOPER);
        redirect($failureurl, get_string('airw_internalerror', 'paygw_airwallex'), 3);
    }
} else {
    redirect($failureurl, get_string('airw_invalidcallback', 'paygw_airwallex'), 3);
}