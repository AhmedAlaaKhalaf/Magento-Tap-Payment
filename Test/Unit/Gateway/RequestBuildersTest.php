<?php
declare(strict_types=1);

namespace Tap\Payment\Test\Unit\Gateway;

use Magento\Framework\UrlInterface;
use Magento\Payment\Gateway\Data\AddressAdapterInterface;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use PHPUnit\Framework\TestCase;
use Tap\Payment\Gateway\Config\Config;
use Tap\Payment\Gateway\Http\TransferFactory;
use Tap\Payment\Gateway\Request\CustomerDataBuilder;
use Tap\Payment\Gateway\Request\PaymentDataBuilder;
use Tap\Payment\Gateway\Request\UrlDataBuilder;

class RequestBuildersTest extends TestCase
{
    private function subject(array $order = [], ?array $address = []): array
    {
        $order += ['increment' => '000000042', 'currency' => 'SAR', 'total' => 125.5, 'store' => 1];

        $orderAdapter = $this->createMock(OrderAdapterInterface::class);
        $orderAdapter->method('getOrderIncrementId')->willReturn($order['increment']);
        $orderAdapter->method('getCurrencyCode')->willReturn($order['currency']);
        $orderAdapter->method('getGrandTotalAmount')->willReturn($order['total']);
        $orderAdapter->method('getStoreId')->willReturn($order['store']);

        if ($address !== null) {
            $address += ['first' => 'Alice', 'last' => 'Smith', 'email' => 'alice@example.com', 'country' => 'SA', 'phone' => '0512345678'];
            $billing = $this->createMock(AddressAdapterInterface::class);
            $billing->method('getFirstname')->willReturn($address['first']);
            $billing->method('getLastname')->willReturn($address['last']);
            $billing->method('getEmail')->willReturn($address['email']);
            $billing->method('getCountryId')->willReturn($address['country']);
            $billing->method('getTelephone')->willReturn($address['phone']);
            $orderAdapter->method('getBillingAddress')->willReturn($billing);
        }

        $paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDO->method('getOrder')->willReturn($orderAdapter);

        return ['payment' => $paymentDO];
    }

    private function config(string $endpoint = 'charges'): Config
    {
        $config = $this->createMock(Config::class);
        $config->method('getTransactionEndpoint')->willReturn($endpoint);
        $config->method('getSourceId')->willReturn('src_all');
        return $config;
    }

    public function testChargeRequestCarriesAmountReferencesAndRouting(): void
    {
        $request = (new PaymentDataBuilder($this->config('charges')))->build($this->subject());

        $this->assertSame(['store_id' => 1, 'method' => 'POST', 'path' => 'charges'], $request[TransferFactory::META]);
        $this->assertSame(125.5, $request['amount']);
        $this->assertSame('SAR', $request['currency']);
        $this->assertTrue($request['threeDSecure']);
        $this->assertSame(['id' => 'src_all'], $request['source']);
        $this->assertSame('000000042', $request['reference']['order']);
        $this->assertSame('magento-1-000000042-charges-125.50SAR', $request['reference']['idempotent']);
    }

    public function testAuthorizeModeUsesAuthorizeEndpointAndCurrencyPrecision(): void
    {
        $request = (new PaymentDataBuilder($this->config('authorize')))->build(
            $this->subject(['currency' => 'KWD', 'total' => 10.12345])
        );

        $this->assertSame('authorize', $request[TransferFactory::META]['path']);
        $this->assertSame(10.123, $request['amount']);
        $this->assertStringEndsWith('-authorize-10.123KWD', $request['reference']['idempotent']);
    }

    public function testCustomerPhoneIsSplitIntoCountryCodeAndNumber(): void
    {
        $customer = (new CustomerDataBuilder())->build($this->subject())['customer'];

        $this->assertSame('Alice', $customer['first_name']);
        $this->assertSame('alice@example.com', $customer['email']);
        $this->assertSame(['country_code' => '966', 'number' => '512345678'], $customer['phone']);
    }

    /**
     * @dataProvider phoneProvider
     */
    public function testPhoneNormalisation(string $country, string $input, ?array $expected): void
    {
        $customer = (new CustomerDataBuilder())->build($this->subject([], ['country' => $country, 'phone' => $input]))['customer'];
        $this->assertSame($expected, $customer['phone'] ?? null);
    }

    public static function phoneProvider(): array
    {
        return [
            'international with plus' => ['KW', '+965 5123 4567', ['country_code' => '965', 'number' => '51234567']],
            'international with 00' => ['AE', '00971501234567', ['country_code' => '971', 'number' => '501234567']],
            'local with leading zero' => ['SA', '0551234567', ['country_code' => '966', 'number' => '551234567']],
            'unknown country omitted' => ['ZZ', '5551234567', null],
            'too short omitted' => ['SA', '123', null],
        ];
    }

    public function testCustomerWithoutBillingAddressStillHasRequiredFields(): void
    {
        $customer = (new CustomerDataBuilder())->build($this->subject([], null))['customer'];
        $this->assertSame('Customer', $customer['first_name']);
    }

    public function testRedirectAndWebhookUrls(): void
    {
        $urlBuilder = $this->createMock(UrlInterface::class);
        $urlBuilder->method('getUrl')->willReturnCallback(
            fn (string $route, array $params) => 'https://shop.test/' . $route . '/?store=' . $params['_scope']
        );

        $request = (new UrlDataBuilder($urlBuilder))->build($this->subject(['store' => 2]));

        $this->assertSame('https://shop.test/tap/payment/response/?store=2', $request['redirect']['url']);
        $this->assertSame('https://shop.test/tap/payment/webhook/?store=2', $request['post']['url']);
    }
}
