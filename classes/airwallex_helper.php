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

        $this->token = $this->get_token();
    }

    /**
     * Verify a payment intent by ID.
     *
     * @param string $intentid Payment intent ID.
     * @return array|null
     * @throws \moodle_exception
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
        $decodedresult = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('airw_invalidjson', 'paygw_airwallex', '', $result);
        }
        return $decodedresult;
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
            'client_id' => $this->clientid,
            'api_key' => $this->apikey,
        ]);

        $curl = new curl();
        $result = $curl->post($location, $command, $options);
        $result = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('airw_invalidjson', 'paygw_airwallex', '', $result);
        }

        if (isset($result['token'])) {
            return $result['token'];
        } else {
            throw new \moodle_exception('airw_tokenerror', 'paygw_airwallex', '', $result);
        }
    }

    /**
     * Create a payment intent.
     *
     * @param float $amount Amount to charge.
     * @param string $currency ISO currency code.
     * @param string $merchantorderid A unique identifier for the order from Moodle.
     * @param string $returnurl The URL where the customer is redirected after payment.
     * @param string $cancelurl The URL where the customer is redirected if they cancel.
     * @param string $description Optional description.
     * @return array|null The PaymentIntent object, including 'id' and 'client_secret'.
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
                'X-Request-ID: ' . \core_uuid::generate(), // Idempotency key
            ],
        ];

        $command = json_encode([
            'amount' => $amount,
            'currency' => $currency,
            'merchant_order_id' => $merchantorderid,
            'description' => $description,
            'payment_method_options' => [
                'hpp' => [
                    'return_url' => $returnurl,
                    'cancel_url' => $cancelurl,
                ],
            ],
            'confirm' => false, // Important for HPP, SDK will confirm later
        ]);

        $curl = new curl();
        $result = $curl->post($location, $command, $options);
        $decodedresult = json_decode($result, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \moodle_exception('airw_invalidjson', 'paygw_airwallex', '', $result);
        }

        if (isset($decodedresult['id']) && isset($decodedresult['client_secret'])) {
            return $decodedresult;
        } else {
            throw new \moodle_exception('airw_intentcreateerror', 'paygw_airwallex', '', $decodedresult);
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
        // This is a simplified example based on common patterns.
        // ALWAYS consult Airwallex's official documentation for exact signature verification steps.
        // It generally involves splitting the header, hashing the timestamp.payload with your webhook_secret,
        // and comparing it with the provided signature.

        if (strpos($signature, 't=') === false || strpos($signature, 'v1=') === false) {
            return false; // Invalid signature format
        }

        list($tpart, $v1part) = explode(',', $signature);
        $timestamp = (int)str_replace('t=', '', $tpart);
        $sig = str_replace('v1=', '', $v1part);

        $signedpayload = $timestamp . '.' . $payload;
        $expectedsignature = hash_hmac('sha256', $signedpayload, $this->webhooksecret);

        return hash_equals($expectedsignature, $sig);
    }
}