<?php
declare(strict_types=1);

namespace Tap\Payment\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Tap\Payment\Gateway\Http\TransferFactory;
use Tap\Payment\Model\ChargeStatus;

/**
 * Reads a charge or authorization back from Tap. The return URL and the
 * webhook both act only on what this returns, never on client-supplied data.
 */
class TapApi
{
    private const ID_PATTERN = '/^(chg|auth)_[A-Za-z0-9]+$/';

    public function __construct(
        private readonly ClientInterface $client,
        private readonly TransferFactory $transferFactory
    ) {
    }

    public static function isValidId(string $tapId): bool
    {
        return (bool) preg_match(self::ID_PATTERN, $tapId);
    }

    /**
     * @throws LocalizedException
     */
    public function retrieve(string $tapId, ?int $storeId): array
    {
        if (!self::isValidId($tapId)) {
            throw new LocalizedException(__('Invalid Tap transaction id.'));
        }

        $path = (ChargeStatus::objectFromId($tapId) === ChargeStatus::OBJECT_AUTHORIZE ? 'authorize/' : 'charges/') . $tapId;
        $response = $this->client->placeRequest($this->transferFactory->create([
            TransferFactory::META => [
                TransferFactory::META_STORE_ID => $storeId,
                TransferFactory::META_METHOD => 'GET',
                TransferFactory::META_PATH => $path,
            ],
        ]));

        if (!empty($response['errors']) || ($response['id'] ?? null) !== $tapId) {
            $error = $response['errors'][0]['description'] ?? 'unexpected response';
            throw new LocalizedException(__('Could not retrieve Tap transaction %1: %2', $tapId, $error));
        }

        return $response;
    }
}
