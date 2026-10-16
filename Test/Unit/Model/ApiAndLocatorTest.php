<?php
declare(strict_types=1);

namespace Tap\Payment\Test\Unit\Model;

use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Tap\Payment\Gateway\Http\TransferFactory;
use Tap\Payment\Model\Api\TapApi;
use Tap\Payment\Model\OrderLocator;
use Tap\Payment\Model\PaymentInfo;

class ApiAndLocatorTest extends TestCase
{
    private function api(array $response, ?string &$path = null): TapApi
    {
        $transferFactory = $this->createMock(TransferFactory::class);
        $transferFactory->method('create')->willReturnCallback(function (array $request) use (&$path) {
            $path = $request[TransferFactory::META][TransferFactory::META_PATH];
            return $this->createMock(TransferInterface::class);
        });
        $client = $this->createMock(ClientInterface::class);
        $client->method('placeRequest')->willReturn($response);

        return new TapApi($client, $transferFactory);
    }

    public function testRetrievesChargesAndAuthorizationsFromTheRightEndpoint(): void
    {
        $this->assertSame('CAPTURED', $this->api(['id' => 'chg_1', 'status' => 'CAPTURED'], $path)->retrieve('chg_1', 1)['status']);
        $this->assertSame('charges/chg_1', $path);

        $this->api(['id' => 'auth_1', 'status' => 'AUTHORIZED'], $path)->retrieve('auth_1', 1);
        $this->assertSame('authorize/auth_1', $path);
    }

    public function testRejectsMalformedIdsBeforeCallingTap(): void
    {
        $this->expectException(LocalizedException::class);
        $this->api(['id' => 'chg_1'])->retrieve('chg_1/../../customers', 1);
    }

    public function testTapErrorsAndMismatchedIdsAreErrors(): void
    {
        try {
            $this->api(['errors' => [['code' => '9997', 'description' => 'Provided id is invalid']]])->retrieve('chg_1', 1);
            $this->fail('Expected exception');
        } catch (LocalizedException $e) {
            $this->assertStringContainsString('Provided id is invalid', $e->getMessage());
        }

        $this->expectException(LocalizedException::class);
        $this->api(['id' => 'chg_other'])->retrieve('chg_1', 1);
    }

    private function locator(?Order $order): OrderLocator
    {
        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn(new SearchCriteria());

        $results = $this->createMock(OrderSearchResultInterface::class);
        $results->method('getItems')->willReturn($order ? [7 => $order] : []);
        $repository = $this->createMock(OrderRepositoryInterface::class);
        $repository->method('getList')->willReturn($results);

        return new OrderLocator($repository, $builder);
    }

    private function order(string $method, string $tapId): Order
    {
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment->setMethod($method);
        $payment->setAdditionalInformation(PaymentInfo::TAP_ID, $tapId);

        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods(['getPayment'])->getMock();
        $order->method('getPayment')->willReturn($payment);
        return $order;
    }

    public function testFindsOrderWhoseStoredTapIdMatches(): void
    {
        $order = $this->order('tap_payment', 'chg_1');
        $this->assertSame($order, $this->locator($order)->getByTapId('chg_1', '000000042'));
    }

    public function testRefusesChargesThatBelongToAnotherOrder(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->locator($this->order('tap_payment', 'chg_other'))->getByTapId('chg_1', '000000042');
    }

    public function testRefusesOrdersPaidWithAnotherMethod(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->locator($this->order('checkmo', 'chg_1'))->getByTapId('chg_1', '000000042');
    }

    public function testUnknownOrder(): void
    {
        $this->expectException(NoSuchEntityException::class);
        $this->locator(null)->getByTapId('chg_1', '000000999');
    }
}
