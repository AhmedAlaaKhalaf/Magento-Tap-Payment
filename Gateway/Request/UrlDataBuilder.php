<?php
declare(strict_types=1);

namespace Tap\Payment\Gateway\Request;

use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

/**
 * redirect.url: where Tap sends the customer after paying (Tap appends ?tap_id=...).
 * post.url: the server-to-server webhook (IPN).
 */
class UrlDataBuilder implements BuilderInterface
{
    public const RESPONSE_ROUTE = 'tap/payment/response';
    public const WEBHOOK_ROUTE = 'tap/payment/webhook';

    public function __construct(private readonly UrlInterface $urlBuilder)
    {
    }

    public function build(array $buildSubject): array
    {
        $storeId = (int) SubjectReader::readPayment($buildSubject)->getOrder()->getStoreId();
        $params = ['_secure' => true, '_nosid' => true, '_scope' => $storeId];

        return [
            'redirect' => ['url' => $this->urlBuilder->getUrl(self::RESPONSE_ROUTE, $params)],
            'post' => ['url' => $this->urlBuilder->getUrl(self::WEBHOOK_ROUTE, $params)],
        ];
    }
}
