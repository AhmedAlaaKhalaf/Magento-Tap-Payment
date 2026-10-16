<?php
declare(strict_types=1);

namespace Tap\Payment\Gateway\Http;

use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Http\TransferFactoryInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Tap\Payment\Gateway\Config\Config;

/**
 * Turns a built request into an HTTP transfer. Builders pass routing data under
 * the META key (store, method, path); it is removed from the JSON body.
 */
class TransferFactory implements TransferFactoryInterface
{
    public const META = '_tap_meta';
    public const META_STORE_ID = 'store_id';
    public const META_METHOD = 'method';
    public const META_PATH = 'path';

    public function __construct(
        private readonly TransferBuilder $transferBuilder,
        private readonly Config $config
    ) {
    }

    public function create(array $request): TransferInterface
    {
        $meta = $request[self::META] ?? [];
        unset($request[self::META]);

        $storeId = isset($meta[self::META_STORE_ID]) ? (int) $meta[self::META_STORE_ID] : null;
        $method = strtoupper((string) ($meta[self::META_METHOD] ?? 'POST'));
        $path = ltrim((string) ($meta[self::META_PATH] ?? ''), '/');

        return $this->transferBuilder
            ->setMethod($method)
            ->setUri($this->config->getApiUrl($storeId) . $path)
            ->setHeaders([
                'Authorization' => 'Bearer ' . $this->config->getSecretKey($storeId),
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ])
            ->setBody($method === 'GET' ? [] : $request)
            ->build();
    }
}
