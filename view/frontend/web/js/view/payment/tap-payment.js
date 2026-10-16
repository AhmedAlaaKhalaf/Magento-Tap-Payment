define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'tap_payment',
        component: 'Tap_Payment/js/view/payment/method-renderer/tap-payment'
    });

    return Component.extend({});
});
