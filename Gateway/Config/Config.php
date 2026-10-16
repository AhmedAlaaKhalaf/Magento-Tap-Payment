<?php
declare(strict_types=1);

namespace Tap\Payment\Gateway\Config;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Payment\Gateway\Config\Config as GatewayConfig;
use Tap\Payment\Model\Config\Source\TransactionMode;

/**
 * Typed access to payment/tap_payment/* settings. Keys are stored with the
 * Encrypted backend model and decrypted here.
 */
class Config extends GatewayConfig
{
    public const CODE = 'tap_payment';

    private const KEY_ACTIVE = 'active';
    private const KEY_TRANSACTION_MODE = 'transaction_mode';
    private const KEY_PUBLIC_KEY = 'test_public_key';
    private const KEY_SECRET_KEY = 'test_secret_key';
    private const KEY_API_URL = 'api_url';
    private const KEY_SOURCE_ID = 'source_id';

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor,
        ?string $methodCode = self::CODE,
        string $pathPattern = self::DEFAULT_PATH_PATTERN
    ) {
        parent::__construct($scopeConfig, $methodCode, $pathPattern);
    }

    public function isActive(?int $storeId = null): bool
    {
        return (bool) $this->getValue(self::KEY_ACTIVE, $storeId);
    }

    public function getTransactionMode(?int $storeId = null): string
    {
        $mode = (string) $this->getValue(self::KEY_TRANSACTION_MODE, $storeId);
        return $mode === TransactionMode::AUTHORIZE ? TransactionMode::AUTHORIZE : TransactionMode::CHARGE;
    }

    /** API path for the configured mode: "charges" or "authorize". */
    public function getTransactionEndpoint(?int $storeId = null): string
    {
        return $this->getTransactionMode($storeId) === TransactionMode::AUTHORIZE ? 'authorize' : 'charges';
    }

    public function getPublicKey(?int $storeId = null): string
    {
        return $this->decrypt($this->getValue(self::KEY_PUBLIC_KEY, $storeId));
    }

    public function getSecretKey(?int $storeId = null): string
    {
        return $this->decrypt($this->getValue(self::KEY_SECRET_KEY, $storeId));
    }

    public function getApiUrl(?int $storeId = null): string
    {
        return rtrim((string) ($this->getValue(self::KEY_API_URL, $storeId) ?: 'https://api.tap.company/v2/'), '/') . '/';
    }

    /** "src_all" shows every payment method enabled on the Tap account; "src_card" shows cards only. */
    public function getSourceId(?int $storeId = null): string
    {
        return (string) ($this->getValue(self::KEY_SOURCE_ID, $storeId) ?: 'src_all');
    }

    private function decrypt(mixed $value): string
    {
        $value = trim((string) $value);
        return $value === '' ? '' : trim($this->encryptor->decrypt($value));
    }
}
