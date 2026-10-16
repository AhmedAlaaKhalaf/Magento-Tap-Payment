<?php
declare(strict_types=1);

namespace Tap\Payment\Block;

use Magento\Payment\Block\ConfigurableInfo;
use Tap\Payment\Model\PaymentInfo;

/**
 * Shows the Tap reference on the order view (admin, customer account, emails).
 */
class Info extends ConfigurableInfo
{
    protected function getLabel($field)
    {
        return match ($field) {
            PaymentInfo::TAP_ID => __('Tap Transaction ID'),
            PaymentInfo::TAP_OBJECT => __('Tap Mode'),
            PaymentInfo::TAP_STATUS => __('Tap Status'),
            PaymentInfo::TAP_RESPONSE => __('Tap Response'),
            PaymentInfo::TAP_CARD => __('Card'),
            default => parent::getLabel($field),
        };
    }
}
