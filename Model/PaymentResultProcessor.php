<?php
declare(strict_types=1);

namespace Tap\Payment\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use Psr\Log\LoggerInterface;

/**
 * Applies a verified Tap charge to its order, exactly once.
 *
 * - Success: records the Tap id as the capture (charge) or authorization
 *   transaction, creates the invoice for captures, moves the order to Processing.
 * - Failure: cancels the order (stock is released by Magento's cancel flow).
 * - Anything else: leaves the order in pending_payment.
 *
 * The return URL and the webhook can arrive at the same time, so the work runs
 * under a lock and only an order still in pending_payment is changed.
 */
class PaymentResultProcessor
{
    public const RESULT_PAID = 'paid';
    public const RESULT_FAILED = 'failed';
    public const RESULT_PENDING = 'pending';

    private const LOCK_TIMEOUT_SECONDS = 15;

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly LockManagerInterface $lockManager,
        private readonly OrderSender $orderSender,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @throws LocalizedException
     */
    public function process(int $orderId, array $charge): string
    {
        return $this->withLock($orderId, function (Order $order) use ($charge): string {
            $tapId = (string) ($charge['id'] ?? '');
            $status = strtoupper((string) ($charge['status'] ?? ''));
            $object = (string) ($charge['object'] ?? ChargeStatus::objectFromId($tapId));
            $context = ['order' => $order->getIncrementId(), 'tap_id' => $tapId, 'status' => $status];

            if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
                $this->logger->info('Tap result ignored: order already final', $context + ['state' => $order->getState()]);
                return $this->resultForState((string) $order->getState());
            }

            $this->recordDetails($order->getPayment(), $charge, $status);

            if (ChargeStatus::isSuccessful($status, $object)) {
                $this->markPaid($order, $charge, $tapId, $status, $object);
                $this->logger->info('Tap payment succeeded', $context);
                return self::RESULT_PAID;
            }

            if (ChargeStatus::isFailed($status)) {
                $this->markFailed($order, $tapId, $status, $this->responseMessage($charge, $status));
                $this->logger->info('Tap payment failed', $context);
                return self::RESULT_FAILED;
            }

            $order->addCommentToStatusHistory(__('Tap %1 %2 is %3. Waiting for the final result.', $object, $tapId, $status));
            $this->orderRepository->save($order);
            $this->logger->info('Tap payment still pending', $context);
            return self::RESULT_PENDING;
        });
    }

    /**
     * Cancels an order whose payment never started (e.g. no Tap payment page).
     *
     * @throws LocalizedException
     */
    public function cancelPending(int $orderId, string $reason): void
    {
        $this->withLock($orderId, function (Order $order) use ($reason): string {
            if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
                $this->markFailed($order, (string) $order->getPayment()->getAdditionalInformation(PaymentInfo::TAP_ID), 'NOT_STARTED', $reason);
            }
            return self::RESULT_FAILED;
        });
    }

    private function withLock(int $orderId, callable $work): string
    {
        $lockName = 'tap_payment_order_' . $orderId;
        if (!$this->lockManager->lock($lockName, self::LOCK_TIMEOUT_SECONDS)) {
            throw new LocalizedException(__('The order is being updated. Please try again in a moment.'));
        }

        try {
            /** @var Order $order */
            $order = $this->orderRepository->get($orderId);
            return $work($order);
        } finally {
            $this->lockManager->unlock($lockName);
        }
    }

    private function markPaid(Order $order, array $charge, string $tapId, string $status, string $object): void
    {
        /** @var Payment $payment */
        $payment = $order->getPayment();
        $amount = (float) ($charge['amount'] ?? 0);
        $currency = (string) ($charge['currency'] ?? '');

        $payment->setTransactionId($tapId);
        $payment->setCurrencyCode($currency);
        $payment->setTransactionAdditionalInfo(Transaction::RAW_DETAILS, $this->rawDetails($charge));

        if ($currency !== $order->getBaseCurrencyCode()
            || !Amount::equals($amount, (float) $order->getBaseGrandTotal(), $currency)
        ) {
            // Paid, but not what was requested: keep the money trail, flag for review.
            $payment->setIsFraudDetected(true);
            $order->addCommentToStatusHistory(__(
                'Tap reported %1 %2 but the order total is %3 %4. Please review before shipping.',
                Amount::format($amount, $currency),
                $currency,
                Amount::format((float) $order->getBaseGrandTotal(), (string) $order->getBaseCurrencyCode()),
                $order->getBaseCurrencyCode()
            ));
        }

        if ($object === ChargeStatus::OBJECT_AUTHORIZE && $status === ChargeStatus::AUTHORIZED) {
            $payment->setIsTransactionClosed(false);
            $payment->registerAuthorizationNotification($amount);
        } else {
            $payment->setIsTransactionClosed(true);
            $payment->registerCaptureNotification($amount);
        }

        if ($order->getState() === Order::STATE_PENDING_PAYMENT) {
            $order->setState(Order::STATE_PROCESSING)
                ->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));
        }
        $order->addCommentToStatusHistory(__('Tap %1 %2: %3 (%4).', $object, $tapId, $status, $this->responseMessage($charge, $status)));
        $order->setCanSendNewEmailFlag(true);
        $this->orderRepository->save($order);

        try {
            $this->orderSender->send($order);
        } catch (\Throwable $e) {
            $this->logger->warning('Tap: order confirmation email failed', ['order' => $order->getIncrementId(), 'error' => $e->getMessage()]);
        }
    }

    private function markFailed(Order $order, string $tapId, string $status, string $reason): void
    {
        if ($order->canCancel()) {
            $order->cancel();
        }
        $order->addCommentToStatusHistory(__('Tap payment %1 was %2 (%3). Order canceled.', $tapId, $status, $reason));
        $this->orderRepository->save($order);
    }

    private function recordDetails(Payment $payment, array $charge, string $status): void
    {
        $payment->setAdditionalInformation(PaymentInfo::TAP_STATUS, $status);
        $payment->setAdditionalInformation(PaymentInfo::TAP_RESPONSE, trim(
            ($charge['response']['code'] ?? '') . ' ' . ($charge['response']['message'] ?? '')
        ));
        if (!empty($charge['card']['last_four'])) {
            $payment->setAdditionalInformation(PaymentInfo::TAP_CARD, trim(
                ($charge['card']['brand'] ?? '') . ' **** ' . $charge['card']['last_four']
            ));
        }
    }

    /** Scalar summary shown in Sales > Transactions. */
    private function rawDetails(array $charge): array
    {
        return array_filter([
            'id' => $charge['id'] ?? null,
            'object' => $charge['object'] ?? null,
            'status' => $charge['status'] ?? null,
            'amount' => isset($charge['amount']) ? (string) $charge['amount'] : null,
            'currency' => $charge['currency'] ?? null,
            'response_code' => $charge['response']['code'] ?? null,
            'response_message' => $charge['response']['message'] ?? null,
            'reference_payment' => $charge['reference']['payment'] ?? null,
            'reference_gateway' => $charge['reference']['gateway'] ?? null,
            'reference_track' => $charge['reference']['track'] ?? null,
            'authorization_id' => $charge['transaction']['authorization_id'] ?? null,
            'card' => isset($charge['card']['last_four']) ? ($charge['card']['brand'] ?? '') . ' ' . $charge['card']['last_four'] : null,
            '3ds_status' => $charge['security']['threeDSecure']['status'] ?? null,
            'live_mode' => isset($charge['live_mode']) ? ($charge['live_mode'] ? 'yes' : 'no') : null,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function responseMessage(array $charge, string $status): string
    {
        return (string) ($charge['response']['message'] ?? $status);
    }

    private function resultForState(string $state): string
    {
        return match ($state) {
            Order::STATE_CANCELED, Order::STATE_CLOSED => self::RESULT_FAILED,
            Order::STATE_NEW, Order::STATE_PAYMENT_REVIEW => self::RESULT_PENDING,
            default => self::RESULT_PAID,
        };
    }
}
