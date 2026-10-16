<?php
declare(strict_types=1);

namespace Tap\Payment\Controller\Payment;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Tap\Payment\Model\Api\TapApi;
use Tap\Payment\Model\OrderLocator;
use Tap\Payment\Model\PaymentResultProcessor;
use Tap\Payment\Model\QuoteRestorer;

/**
 * GET /tap/payment/response?tap_id=chg_..., the redirect.url Tap returns the
 * customer to. The status is read from the Tap API, never from the query string.
 */
class Response implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly CheckoutSession $checkoutSession,
        private readonly StoreManagerInterface $storeManager,
        private readonly TapApi $tapApi,
        private readonly OrderLocator $orderLocator,
        private readonly PaymentResultProcessor $processor,
        private readonly QuoteRestorer $quoteRestorer,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): RedirectResult
    {
        $result = $this->redirectFactory->create();
        $tapId = (string) $this->request->getParam('tap_id');

        try {
            $charge = $this->tapApi->retrieve($tapId, (int) $this->storeManager->getStore()->getId());
            $order = $this->orderLocator->getByTapId($tapId, (string) ($charge['reference']['order'] ?? ''));
            $outcome = $this->processor->process((int) $order->getEntityId(), $charge);
        } catch (\Throwable $e) {
            $this->logger->error('Tap: could not confirm payment on return', ['tap_id' => $tapId, 'error' => $e->getMessage()]);
            $this->messageManager->addErrorMessage(
                __('We could not confirm your payment with Tap. If you were charged, your order will be updated automatically.')
            );
            return $result->setPath('checkout/cart');
        }

        $status = (string) ($charge['status'] ?? '');
        $reason = (string) ($charge['response']['message'] ?? $status);

        switch ($outcome) {
            case PaymentResultProcessor::RESULT_PAID:
                $this->rememberOrder($order);
                $this->messageManager->addSuccessMessage(__('Your payment was successful. Thank you for your order.'));
                return $result->setPath('checkout/onepage/success');

            case PaymentResultProcessor::RESULT_FAILED:
                $restored = $this->quoteRestorer->restore($order);
                $this->messageManager->addErrorMessage($restored
                    ? __('Your payment was not completed (%1: %2). Your cart has been restored so you can try again.', $status, $reason)
                    : __('Your payment was not completed (%1: %2). Order #%3 has been canceled.', $status, $reason, $order->getIncrementId()));
                return $result->setPath('checkout/cart');

            default:
                $this->rememberOrder($order);
                $this->messageManager->addNoticeMessage(
                    __('Tap is still confirming your payment. Your order will be updated as soon as the result arrives.')
                );
                return $result->setPath('checkout/onepage/success');
        }
    }

    /** The success page reads the order from the checkout session. */
    private function rememberOrder(OrderInterface $order): void
    {
        $this->checkoutSession
            ->setLastQuoteId($order->getQuoteId())
            ->setLastSuccessQuoteId($order->getQuoteId())
            ->setLastOrderId($order->getEntityId())
            ->setLastRealOrderId($order->getIncrementId())
            ->setLastOrderStatus($order->getStatus());
    }
}
