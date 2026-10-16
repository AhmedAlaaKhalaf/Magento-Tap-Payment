<?php
declare(strict_types=1);

namespace Tap\Payment\Gateway\Request;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;
use Tap\Payment\Gateway\Config\Config;
use Tap\Payment\Gateway\Http\TransferFactory;
use Tap\Payment\Model\Amount;

/**
 * Amount, references and the hosted-page source for POST /v2/charges or /v2/authorize.
 */
class PaymentDataBuilder implements BuilderInterface
{
    public function __construct(private readonly Config $config)
    {
    }

    public function build(array $buildSubject): array
    {
        $order = SubjectReader::readPayment($buildSubject)->getOrder();
        $storeId = (int) $order->getStoreId();
        $incrementId = (string) $order->getOrderIncrementId();
        $currency = (string) $order->getCurrencyCode();
        $amount = Amount::round((float) $order->getGrandTotalAmount(), $currency);
        $endpoint = $this->config->getTransactionEndpoint($storeId);

        return [
            TransferFactory::META => [
                TransferFactory::META_STORE_ID => $storeId,
                TransferFactory::META_METHOD => 'POST',
                TransferFactory::META_PATH => $endpoint,
            ],
            'amount' => $amount,
            'currency' => $currency,
            'customer_initiated' => true,
            'threeDSecure' => true,
            'save_card' => false,
            'description' => sprintf('Order #%s', $incrementId),
            'metadata' => [
                'order_increment_id' => $incrementId,
                'platform' => 'magento2',
            ],
            'reference' => [
                'transaction' => $incrementId,
                'order' => $incrementId,
                // Tap returns the original charge for a repeated key. The amount is part of
                // the key so a retried checkout with a changed cart creates a new charge.
                'idempotent' => sprintf(
                    'magento-%d-%s-%s-%s%s',
                    $storeId,
                    $incrementId,
                    $endpoint,
                    Amount::format($amount, $currency),
                    $currency
                ),
            ],
            'receipt' => ['email' => false, 'sms' => false],
            'source' => ['id' => $this->config->getSourceId($storeId)],
        ];
    }
}
