/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
/*browser:true*/
/*global define*/
define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/shipping-rates-validator',
        'Magento_Checkout/js/model/shipping-rates-validation-rules',
        'Kingwebmaster_Shipping/js/model/shipping-rates-validator',
        'Kingwebmaster_Shipping/js/model/shipping-rates-validation-rules'
    ],
    function (
        Component,
        defaultShippingRatesValidator,
        defaultShippingRatesValidationRules,
        asmShippingRatesValidator,
        asmShippingRatesValidationRules
    ) {
        'use strict';
        defaultShippingRatesValidator.registerValidator('asmext', asmShippingRatesValidator);
        defaultShippingRatesValidationRules.registerRules('asmext', asmShippingRatesValidationRules);
        return Component;
    }
);