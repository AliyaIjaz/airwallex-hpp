/* eslint-disable no-console */
/* eslint-disable no-unused-vars */
/* eslint-disable jsdoc/check-param-names */
/* eslint-disable promise/no-return-wrap */
/* eslint-disable promise/no-nesting */
/* eslint-disable camelcase */
/* eslint-disable capitalized-comments */
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
// You should have received a copy of the GNU Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This module is responsible for Airwallex content in the gateways modal for SDK-driven HPP.
 *
 * @module     paygw_airwallex/gateways_modal
 * @copyright  2020 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from './repository';
import Templates from 'core/templates';
import Modal from 'core/modal';
import Notification from 'core/notification';
import {getString} from 'core/str';

/**
 * Creates and shows a modal that contains a placeholder/loading message.
 *
 * @returns {Promise<Modal>}
 */
const showModalWithLoading = async() => await Modal.create({
    body: await Templates.render('paygw_airwallex/airwallex_placeholder', {
        message: getString('airw_preparingpayment', 'paygw_airwallex') // Custom message for loading
    }),
    show: true,
    removeOnClose: true,
});

/**
 * Dynamically loads the Airwallex Components SDK.
 *
 * @param {string} env The Airwallex environment ('demo' or 'prod').
 * @param {string} clientId Airwallex client ID.
 * @returns {Promise<object>} Resolves with the Airwallex payment object.
 */
const loadAirwallexComponentsSdk = (env, clientId) => {
    const sdkUrl = 'https://checkout.airwallex.com/assets/components.bundle.min.js';
    // Use a unique static property name to avoid conflicts if multiple SDKs are loaded
    if (loadAirwallexComponentsSdk.currentlyloaded === sdkUrl && window.Airwallex && window.Airwallex.payment) {
        // SDK already loaded and initialized
        return Promise.resolve(window.Airwallex.payment);
    }

    const script = document.createElement('script');
    return new Promise((resolve, reject) => {
        script.onload = async function() {
            if (window.Airwallex && typeof window.Airwallex.init === 'function') {
                try {
                    const { payment } = await window.Airwallex.init({
                        env: env, // 'demo' or 'prod'
                        client_id: clientId,
                        // If you need specific elements (e.g., 'card'), you'd include them here.
                        // For redirectToCheckout, 'payments' is often sufficient.
                        enabledElements: ['payments'],
                    });
                    loadAirwallexComponentsSdk.currentlyloaded = sdkUrl;
                    resolve(payment);
                } catch (e) {
                    console.error('Airwallex SDK init failed:', e);
                    reject(new Error(getString('airw_sdkinitfailed', 'paygw_airwallex')));
                }
            } else {
                reject(new Error(getString('airw_sdknotfound', 'paygw_airwallex')));
            }
        };
        script.onerror = function() {
            reject(new Error(getString('airw_sdkloadfailed', 'paygw_airwallex')));
        };
        script.setAttribute('src', sdkUrl);
        document.head.appendChild(script);
    });
};
loadAirwallexComponentsSdk.currentlyloaded = ''; // Static property to track loaded SDK

/**
 * Process the payment using Airwallex SDK redirectToCheckout for HPP.
 *
 * @param {string} component Name of the component that the itemId belongs to
 * @param {string} paymentArea The area of the component that the itemId belongs to
 * @param {number} itemId An internal identifier that is used by the component
 * @param {string} description Description of the payment (for logging/metadata)
 * @returns {Promise<string>}
 */
export const process = (component, paymentArea, itemId, description) => {
    console.log('Airwallex SDK-driven HPP process initiated', component, paymentArea, itemId, description);
    let paymentModal = null;

    return showModalWithLoading()
    .then(modal => {
        paymentModal = modal;
        // Call the webservice to get payment intent details and HPP config
        return Repository.getHppConfig(component, paymentArea, itemId);
    })
    .then(airwallexConfig => {
        // Load and initialize the Airwallex Components SDK
        return Promise.all([
            airwallexConfig,
            loadAirwallexComponentsSdk(airwallexConfig.env, airwallexConfig.client_id),
        ]);
    })
    .then(([airwallexConfig, paymentSdk]) => {
        // Hide the loading modal as we're about to redirect
        if (paymentModal) {
            paymentModal.hide();
        }

        // Configure Google Pay options if desired. This example uses some from Airwallex docs.
        const googlePayOptions = {
            countryCode: airwallexConfig.currency.substring(0, 2), // Example: 'USD' -> 'US'
            merchantInfo: {
                merchantName: getString('airw_merchantname', 'paygw_airwallex'), // From lang file
            },
            emailRequired: true,
            billingAddressParameters: {
                format: 'FULL',
                phoneNumberRequired: true
            },
            billingAddressRequired: true,
            buttonType: "checkout", // Can be "book", "buy", "donate", etc.
            buttonColor: "black",
            buttonSizeMode: "fill"
        };
        // NOTE: For recurring payments, you'd add displayItems as shown in Airwallex docs.

        // Use the Airwallex SDK to redirect to their hosted payment page
        paymentSdk.redirectToCheckout({
            intent_id: airwallexConfig.payment_intent_id,
            client_secret: airwallexConfig.client_secret,
            currency: airwallexConfig.currency,
            returnUrl: airwallexConfig.return_url,
            cancelUrl: airwallexConfig.cancel_url,
            // Pass Google Pay options if Google Pay is desired within the HPP
            googlePayRequestOptions: googlePayOptions,
            // mode: 'recurring', // If implementing subscriptions
            // customer_id: 'your customer id', // If managing customers in Airwallex
            // ... any other options for redirectToCheckout
        });

        // The browser will now redirect. The promise in this function
        // will not resolve in the usual sense (the page will change).
        // The actual payment result will be handled by callback.php.
        return Promise.resolve(getString('airw_redirecting', 'paygw_airwallex'));
    })
    .catch(err => {
        if (paymentModal) {
            paymentModal.hide();
        }
        console.error('Airwallex HPP initiation failed:', err);
        Notification.exception(err, {
            message: err.message || getString('airw_paymentinitfailed', 'paygw_airwallex')
        });
        return Promise.reject(err.message || getString('airw_paymentinitfailed', 'paygw_airwallex'));
    });
};