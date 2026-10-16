<?php
declare(strict_types=1);

namespace Tap\Payment\Controller\Payment;

use Laminas\Uri\Http as HttpUri;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;
use Tap\Payment\Gateway\Config\Config;
use Tap\Payment\Model\PaymentInfo;
use Tap\Payment\Model\PaymentResultProcessor;
use Tap\Payment\Model\QuoteRestorer;

/**
 * GET /tap/payment/redirect, called by the checkout JS right after Place Order:
 * sends the customer to the Tap hosted page (transaction.url) stored on the order.
 */
class Redirect implements HttpGetActionInterface
{
    public function __construct(
        private readonly CheckoutSession $checkoutSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly PaymentResultProcessor $processor,
        private readonly QuoteRestorer $quoteRestorer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): RedirectResult
    {
        $result = $this->redirectFactory->create();
        $order = $this->checkoutSession->getLastRealOrder();
        $payment = $order->getPayment();

        if (!$order->getId() || $payment === null || $payment->getMethod() !== Config::CODE) {
            return $result->setPath('checkout/cart');
        }

        $url = (string) $payment->getAdditionalInformation(PaymentInfo::TRANSACTION_URL);
        if ($order->getState() === Order::STATE_PENDING_PAYMENT && self::isTapUrl($url)) {
            return $result->setUrl($url);
        }

        $this->logger->warning('Tap: no payment page for order', ['order' => $order->getIncrementId(), 'state' => $order->getState()]);
        if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
            try {
                $this->processor->cancelPending((int) $order->getId(), 'Tap payment page unavailable');
            } catch (\Throwable $e) {
                $this->logger->error('Tap: could not cancel order', ['order' => $order->getIncrementId(), 'error' => $e->getMessage()]);
            }
            $this->quoteRestorer->restore($order);
        }

        $this->messageManager->addErrorMessage(__('We could not start the payment with Tap. Please try again.'));
        return $result->setPath('checkout/cart');
    }

    /** Only redirect to Tap's own HTTPS domains. */
    public static function isTapUrl(string $url): bool
    {
        try {
            $uri = new HttpUri($url);
        } catch (\Throwable) {
            return false;
        }
        $host = strtolower((string) $uri->getHost());

        return $uri->getScheme() === 'https'
            && ($host === 'tap.company' || str_ends_with($host, '.tap.company'));
    }
}
