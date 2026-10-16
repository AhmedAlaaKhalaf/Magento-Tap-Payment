<?php
declare(strict_types=1);

namespace Tap\Payment\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Puts the canceled order's items back in the customer's cart.
 *
 * Only done when this browser session placed the order: checkout session's
 * last real order must be this order, so a returning link can never load
 * another customer's cart.
 */
class QuoteRestorer
{
    public function __construct(private readonly CheckoutSession $checkoutSession)
    {
    }

    public function restore(OrderInterface $order): bool
    {
        if ((string) $this->checkoutSession->getLastRealOrderId() !== (string) $order->getIncrementId()) {
            return false;
        }
        return $this->checkoutSession->restoreQuote();
    }
}
