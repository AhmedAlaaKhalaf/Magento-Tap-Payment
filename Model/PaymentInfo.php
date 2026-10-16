<?php
declare(strict_types=1);

namespace Tap\Payment\Model;

/**
 * Keys used in sales_order_payment.additional_information.
 */
class PaymentInfo
{
    public const TAP_ID = 'tap_id';
    public const TAP_OBJECT = 'tap_object';
    public const TAP_STATUS = 'tap_status';
    public const TAP_RESPONSE = 'tap_response';
    public const TAP_CARD = 'tap_card';
    public const TRANSACTION_URL = 'tap_transaction_url';
}
