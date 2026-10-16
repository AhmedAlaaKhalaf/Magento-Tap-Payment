<?php
declare(strict_types=1);

namespace Tap\Payment\Test\Unit\Model\Webhook;

use PHPUnit\Framework\TestCase;
use Tap\Payment\Model\Webhook\SignatureValidator;

class SignatureValidatorTest extends TestCase
{
    private const SECRET = 'sk_test_webhook_secret';

    private function payload(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'chg_TS05A4120230736x9K22710693',
            'status' => 'CAPTURED',
            'amount' => 1.0,
            'currency' => 'SAR',
            'transaction' => ['created' => '1698392202943'],
            'reference' => ['gateway' => 'mada_pg70983e7a', 'payment' => '4327230736106619650', 'order' => '000000042'],
        ], $overrides);
    }

    public function testBuildsTheDocumentedMessage(): void
    {
        $message = 'x_idchg_TS05A4120230736x9K22710693x_amount1.00x_currencySAR'
            . 'x_gateway_referencemada_pg70983e7ax_payment_reference4327230736106619650'
            . 'x_statusCAPTUREDx_created1698392202943';

        $this->assertSame(hash_hmac('sha256', $message, self::SECRET), (new SignatureValidator())->expected($this->payload(), self::SECRET));
    }

    public function testFormatsThreeDecimalCurrencies(): void
    {
        $payload = $this->payload(['amount' => 1, 'currency' => 'KWD']);
        $message = 'x_idchg_TS05A4120230736x9K22710693x_amount1.000x_currencyKWD'
            . 'x_gateway_referencemada_pg70983e7ax_payment_reference4327230736106619650'
            . 'x_statusCAPTUREDx_created1698392202943';

        $this->assertSame(hash_hmac('sha256', $message, self::SECRET), (new SignatureValidator())->expected($payload, self::SECRET));
    }

    public function testAcceptsValidSignatureCaseInsensitively(): void
    {
        $validator = new SignatureValidator();
        $hash = strtoupper($validator->expected($this->payload(), self::SECRET));

        $this->assertTrue($validator->isValid($this->payload(), $hash, self::SECRET));
    }

    public function testRejectsTamperedPayloadsAndMissingInputs(): void
    {
        $validator = new SignatureValidator();
        $hash = $validator->expected($this->payload(), self::SECRET);

        $this->assertFalse($validator->isValid($this->payload(['status' => 'DECLINED']), $hash, self::SECRET));
        $this->assertFalse($validator->isValid($this->payload(['amount' => 100]), $hash, self::SECRET));
        $this->assertFalse($validator->isValid($this->payload(), $hash, 'sk_test_other'));
        $this->assertFalse($validator->isValid($this->payload(), '', self::SECRET));
        $this->assertFalse($validator->isValid($this->payload(), $hash, ''));
    }
}
