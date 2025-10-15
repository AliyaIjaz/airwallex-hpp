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
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This module is responsible for Airwallex content in the gateways modal for HPP.
 *
 * @module     paygw_airwallex/gateways_modal
 * @copyright  2020 Shamim Rezaie <shamim@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import * as Repository from './repository'; // Your repository.js will call the new webservice
import Templates from 'core/templates';
import Modal from 'core/modal';
// import ModalEvents from 'core/modal_events'; // Not strictly needed for HPP redirect
import {getString} from 'core/str';
import Notification from 'core/notification'; // For displaying error messages

/**
 * Creates and shows a modal that contains a placeholder.
 *
 * @returns {Promise<Modal>}
 */
const showModalWithPlaceholder = async() => await Modal.create({
    body: await Templates.render('paygw_airwallex/airwallex_placeholder', {}),
    show: true,
    removeOnClose: true,
});

/**
 * Process the payment by redirecting to Airwallex HPP.
 *
 * @param {string} component Name of the component that the itemId belongs to
 * @param {string} paymentArea The area of the component that the itemId belongs to
 * @param {number} itemId An internal identifier that is used by the component
 * @param {string} description Description of the payment (not directly used in HPP redirect, but good for context)
 * @returns {Promise<string>}
 */
export const process = (component, paymentArea, itemId, description) => {
    console.log('Airwallex HPP process initiated', component, paymentArea, itemId, description);
    let paymentModal = null; // To hold the modal instance

    return showModalWithPlaceholder()
    .then(modal => {
        paymentModal = modal;
        // Call the webservice to get the HPP redirect URL
        return Repository.getHppConfig(component, paymentArea, itemId);
    })
    .then(airwallexConfig => {
        if (!airwallexConfig.hpp_redirect_url) {
            throw new Error(getString('airw_noredirecturl_js', 'paygw_airwallex'));
        }

        // Hide the modal before redirecting
        if (paymentModal) {
            paymentModal.hide();
        }

        // Redirect the user to the Airwallex Hosted Payment Page
        window.location.href = airwallexConfig.hpp_redirect_url;

        // The Promise will not resolve until the user returns to Moodle via callback.php
        // For the purpose of this function, we can resolve immediately if the redirect is successful.
        // The actual payment confirmation will happen in callback.php and potentially webhook.php.
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

// No need for loadAirwallexSdk as we are redirecting to their page.
// No need for currentlyloaded static property.