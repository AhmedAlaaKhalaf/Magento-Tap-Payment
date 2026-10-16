<?php
declare(strict_types=1);

namespace Tap\Payment\Model\Ui;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\UrlInterface;
use Tap\Payment\Gateway\Config\Config;

/**
 * Exposes the redirect URL to the checkout JS renderer (window.checkoutConfig).
 */
class ConfigProvider implements ConfigProviderInterface
{
    public const REDIRECT_ROUTE = 'tap/payment/redirect';

    public function __construct(private readonly UrlInterface $urlBuilder)
    {
    }

    public function getConfig(): array
    {
        return [
            'payment' => [
                Config::CODE => [
                    'redirectUrl' => $this->urlBuilder->getUrl(self::REDIRECT_ROUTE, ['_secure' => true]),
                ],
            ],
        ];
    }
}
