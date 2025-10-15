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
 * Contains helper class to work with Airwallex REST API.
 *
 * @package    paygw_airwallex
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace paygw_airwallex;

use curl;

defined('MOODLE_INTERNAL') || die();

// require_once($CFG->libdir . '/filelib.php'); // Not strictly needed for this class

class airwallex_helper {

    /**
     * @var string The payment intent succeeded.
     */
    public const INTENT_STATUS_SUCCEEDED = 'SUCCEEDED';

    /**
     * @var string The base API URL
     */
    private $baseurl;

    /**
     * @var string Client ID (App Key)
     */
    private $clientid;

    /**
     * @var string API Key (App Secret)
     */
    private $apikey;

    /**
     * @var string webhook secret
     */
    private $webhooksecret;

    /**
     * @var string The bearer token
     */
    private $token;

    /**
     * helper constructor.
     *
     * @param string $clientid The client id (App Key).
     * @param string $apikey Airwallex API key (App Secret).
     * @param string $webhooksecret Airwallex webhook secret.
     * @param bool $sandbox Whether we are working with the sandbox environment or not.
     */
    public function __construct(string $clientid, string $apikey, string $webhooksecret, bool $sandbox) {
        $this->clientid = $clientid;
        $this->apikey = $apikey;
        $this->webhooksecret = $webhooksecret;
        $this->baseurl = $sandbox ? 'https://api-demo.airwallex.com' : 'https://api.airwallex.com';

        // In a real application, you'd cache this token and refresh it only when expired.
        $this->token = $this->get_token();
    }

    /**
     * Verify a payment intent by ID.
     *
     * @param string $intentid Payment intent ID.
     * @return array|null
     */
    public function verify_payment_intent(string $intentid): ?array {
        $location = "$this->baseurl/api/v1/pa/payment_intents/$intentid";

        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->token}",
            ],
        ];

        $curl = new curl();
        $result = $curl->get($location, [], $options);

        return json_decode($result, true);
    }

    /**
     * Request for Airwallex bearer token.
     *
     * @return string
     * @throws \moodle_exception
     */
    private function get_token(): string {
        $location = "$this->baseurl/api/v1/authentication/login";

        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
            ],
        ];

        $command = json_encode([
            'client_id' => $this->clientid, // Corrected key names as per Airwallex docs
            'api_key' => $this->apikey,     // Corrected key names
        ]);

        $curl = new curl();
        $result = $curl->post($location, $command, $options);
        $result = json_decode($result, true);

        // Remove the debugging line: echo '<pre>'; print_r($result); echo '</pre>'; die;
        if (isset($result['token'])) {
            return $result['token'];
        } else {
            // Log the error and throw an exception for clearer debugging.
            throw new \moodle_exception('airw_tokenerror', 'paygw_airwallex', '', $result);
        }
    }

    /**
     * Create a payment intent for HPP.
     *
     * @param float $amount Amount to charge.
     * @param string $currency ISO currency code.
     * @param string $merchantorderid A unique identifier for the order from Moodle.
     * @param string $returnurl The URL where the customer is redirected after payment.
     * @param string $cancelurl The URL where the customer is redirected if they cancel.
     * @param string $description Optional description.
     * @return array|null The PaymentIntent object, including next_action.redirect_url.
     * @throws \moodle_exception
     */
    public function create_payment_intent(
        float $amount,
        string $currency,
        string $merchantorderid,
        string $returnurl,
        string $cancelurl,
        string $description = ''
    ): ?array {
        $location = "$this->baseurl/api/v1/pa/payment_intents/create";

        $options = [
            'CURLOPT_RETURNTRANSFER' => true,
            'CURLOPT_TIMEOUT' => 30,
            'CURLOPT_HTTPHEADER' => [
                'Content-Type: application/json',
                "Authorization: Bearer {$this->token}",
                // Ensure idempotency for retries
                'X-Request-ID: ' . \core_uuid::generate(),
            ],
        ];

        $command = json_encode([
            'amount' => $amount,
            'currency' => $currency,
            'merchant_order_id' => $merchantorderid, // Use the provided unique Moodle order ID
            'description' => $description,
            'payment_method_options' => [
                'hpp' => [
                    'return_url' => $returnurl,
                    'cancel_url' => $cancelurl,
                    // Optionally, you can add more HPP settings here, e.g., 'country_code', 'locale', 'appearance'
                ],
            ],
            // For hosted page, you often specify it's for 'HPP' or a specific payment method upfront.
            // Airwallex's API typically infers this from payment_method_options.hpp
            'confirm' => false, // We will confirm later via the HPP redirect
        ]);

        $curl = new curl();
        $result = $curl->post($location, $command, $options);
        $result = json_decode($result, true);

        if (isset($result['id'])) {
            return $result;
        } else {
            // Log error and throw exception if PaymentIntent creation fails
            throw new \moodle_exception('airw_intentcreateerror', 'paygw_airwallex', '', $result);
        }
    }

    /**
     * Verify the webhook signature.
     *
     * @param string $signature The X-Airwallex-Signature header value.
     * @param string $payload The raw request body.
     * @return bool
     */
    public function verify_webhook_signature(string $signature, string $payload): bool {
        // Airwallex webhook signature verification typically involves a timestamp and signature.
        // The header usually looks like: t=<timestamp>,v1=<signature>
        // Moodle's textlib and openssl functions might be useful here.
        // This is a simplified example; a full implementation would parse the header and
        // use hash_hmac with the webhooksecret.
        list($tpart, $v1part) = explode(',', $signature);
        $timestamp = (int)str_replace('t=', '', $tpart);
        $sig = str_replace('v1=', '', $v1part);

        $signedpayload = $timestamp . '.' . $payload;
        $expectedsignature = hash_hmac('sha256', $signedpayload, $this->webhooksecret);

        return hash_equals($expectedsignature, $sig);
    }
}