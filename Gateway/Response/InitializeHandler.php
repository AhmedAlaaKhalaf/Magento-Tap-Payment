<?php
declare(strict_types=1);

namespace Tap\Payment\Gateway\Response;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Response\HandlerInterface;
use Magento\Sales\Model\Order;
use Tap\Payment\Model\ChargeStatus;
use Tap\Payment\Model\PaymentInfo;

/**
 * Runs inside Place Order after Tap created the charge: stores the Tap id and
 * hosted URL on the payment and leaves the order in pending_payment until Tap
 * reports the result.
 */
class InitializeHandler implements HandlerInterface
{
    public function handle(array $handlingSubject, array $response): void
    {
        $payment = SubjectReader::readPayment($handlingSubject)->getPayment();
        $stateObject = SubjectReader::readStateObject($handlingSubject);

        $tapId = (string) $response['id'];
        $payment->setAdditionalInformation(PaymentInfo::TAP_ID, $tapId);
        $payment->setAdditionalInformation(PaymentInfo::TAP_OBJECT, (string) ($response['object'] ?? ChargeStatus::objectFromId($tapId)));
        $payment->setAdditionalInformation(PaymentInfo::TAP_STATUS, (string) ($response['status'] ?? ChargeStatus::INITIATED));
        $payment->setAdditionalInformation(PaymentInfo::TRANSACTION_URL, (string) $response['transaction']['url']);

        $stateObject->setData('state', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('status', Order::STATE_PENDING_PAYMENT);
        $stateObject->setData('is_notified', false);

        if ($payment instanceof Order\Payment) {
            // The confirmation email is sent once Tap confirms the payment.
            $payment->getOrder()->setCanSendNewEmailFlag(false);
        }
    }
}
