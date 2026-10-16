define([
    'Magento_Checkout/js/view/payment/default',
    'Magento_Checkout/js/model/full-screen-loader'
], function (Component, fullScreenLoader) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Tap_Payment/payment/tap-payment',
            // Place Order creates the Tap charge server-side; then the customer goes to Tap, not the success page.
            redirectAfterPlaceOrder: false
        },

        afterPlaceOrder: function () {
            fullScreenLoader.startLoader();
            window.location.replace(window.checkoutConfig.payment.tap_payment.redirectUrl);
        }
    });
});
