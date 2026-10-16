<?php
declare(strict_types=1);

namespace Tap\Payment\Test\Unit\Controller;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\Result\Redirect as RedirectResult;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tap\Payment\Controller\Payment\Redirect;
use Tap\Payment\Controller\Payment\Response;
use Tap\Payment\Controller\Payment\Webhook;
use Tap\Payment\Gateway\Config\Config;
use Tap\Payment\Model\Api\TapApi;
use Tap\Payment\Model\OrderLocator;
use Tap\Payment\Model\PaymentResultProcessor;
use Tap\Payment\Model\QuoteRestorer;
use Tap\Payment\Model\Webhook\SignatureValidator;

class ControllersTest extends TestCase
{
    public function testOnlyTapHttpsUrlsAreFollowed(): void
    {
        $this->assertTrue(Redirect::isTapUrl('https://checkout.tap.company/?mode=page&token=abc'));
        $this->assertTrue(Redirect::isTapUrl('https://tap.company/pay'));
        $this->assertFalse(Redirect::isTapUrl('http://checkout.tap.company/'));
        $this->assertFalse(Redirect::isTapUrl('https://checkout.tap.company.evil.test/'));
        $this->assertFalse(Redirect::isTapUrl('https://eviltap.company.test/'));
        $this->assertFalse(Redirect::isTapUrl(''));
    }

    private function order(): Order
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $order->setData(['entity_id' => 7, 'increment_id' => '000000042', 'quote_id' => 3, 'store_id' => 1, 'status' => 'processing']);
        return $order;
    }

    /** @return array{0: Response, 1: RedirectResult&MockObject, 2: ManagerInterface&MockObject, 3: QuoteRestorer&MockObject, 4: CheckoutSession&MockObject} */
    private function response(string $outcome, array $charge): array
    {
        $request = $this->createMock(RequestInterface::class);
        $request->method('getParam')->with('tap_id')->willReturn('chg_1');

        $redirect = $this->createMock(RedirectResult::class);
        $redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $api = $this->createMock(TapApi::class);
        $api->method('retrieve')->with('chg_1', 1)->willReturn($charge);
        $locator = $this->createMock(OrderLocator::class);
        $locator->method('getByTapId')->with('chg_1', '000000042')->willReturn($this->order());
        $processor = $this->createMock(PaymentResultProcessor::class);
        $processor->method('process')->with(7, $charge)->willReturn($outcome);

        $messages = $this->createMock(ManagerInterface::class);
        $restorer = $this->createMock(QuoteRestorer::class);
        $session = $this->getMockBuilder(CheckoutSession::class)->disableOriginalConstructor()
            ->addMethods(['setLastQuoteId', 'setLastSuccessQuoteId', 'setLastOrderId', 'setLastRealOrderId', 'setLastOrderStatus'])
            ->getMock();
        foreach (['setLastQuoteId', 'setLastSuccessQuoteId', 'setLastOrderId', 'setLastRealOrderId', 'setLastOrderStatus'] as $method) {
            $session->method($method)->willReturnSelf();
        }

        $controller = new Response($request, $redirectFactory, $messages, $session, $storeManager, $api, $locator, $processor, $restorer, new NullLogger());
        return [$controller, $redirect, $messages, $restorer, $session];
    }

    public function testSuccessfulReturnGoesToSuccessPage(): void
    {
        [$controller, $redirect, $messages, $restorer, $session] = $this->response(
            PaymentResultProcessor::RESULT_PAID,
            ['id' => 'chg_1', 'status' => 'CAPTURED', 'reference' => ['order' => '000000042']]
        );
        $session->expects($this->once())->method('setLastRealOrderId')->with('000000042');
        $restorer->expects($this->never())->method('restore');
        $messages->expects($this->once())->method('addSuccessMessage');
        $redirect->expects($this->once())->method('setPath')->with('checkout/onepage/success');

        $controller->execute();
    }

    public function testFailedReturnRestoresCartAndExplainsWhy(): void
    {
        [$controller, $redirect, $messages, $restorer] = $this->response(
            PaymentResultProcessor::RESULT_FAILED,
            ['id' => 'chg_1', 'status' => 'DECLINED', 'response' => ['message' => 'Declined'], 'reference' => ['order' => '000000042']]
        );
        $restorer->expects($this->once())->method('restore')->willReturn(true);
        $messages->expects($this->once())->method('addErrorMessage')->with($this->callback(
            fn ($message) => str_contains((string) $message, 'DECLINED: Declined') && str_contains((string) $message, 'cart has been restored')
        ));
        $redirect->expects($this->once())->method('setPath')->with('checkout/cart');

        $controller->execute();
    }

    /** @return array{0: Webhook, 1: PaymentResultProcessor&MockObject, 2: array} */
    private function webhook(array $payload, string $hashstring): array
    {
        $request = $this->createMock(HttpRequest::class);
        $request->method('getContent')->willReturn(json_encode($payload));
        $request->method('getHeader')->with('hashstring')->willReturn($hashstring);

        $codes = new \ArrayObject();
        $raw = $this->createMock(Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function (int $code) use ($raw, $codes) {
            $codes[] = $code;
            return $raw;
        });
        $raw->method('setContents')->willReturnSelf();
        $rawFactory = $this->createMock(RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);

        $config = $this->createMock(Config::class);
        $config->method('getSecretKey')->willReturn('sk_test_secret');
        $locator = $this->createMock(OrderLocator::class);
        $locator->method('getByTapId')->willReturn($this->order());
        $api = $this->createMock(TapApi::class);
        $api->method('retrieve')->willReturn(['id' => 'chg_1', 'status' => 'CAPTURED']);
        $processor = $this->createMock(PaymentResultProcessor::class);

        $controller = new Webhook($request, $rawFactory, $config, $locator, new SignatureValidator(), $api, $processor, new NullLogger());
        return [$controller, $processor, $codes];
    }

    private function webhookPayload(): array
    {
        return [
            'id' => 'chg_1', 'status' => 'CAPTURED', 'amount' => 125.5, 'currency' => 'SAR',
            'transaction' => ['created' => '1792147426059'],
            'reference' => ['order' => '000000042', 'payment' => '123', 'gateway' => 'gw'],
        ];
    }

    public function testSignedWebhookIsProcessedFromTheApiResult(): void
    {
        $payload = $this->webhookPayload();
        [$controller, $processor, $codes] = $this->webhook($payload, (new SignatureValidator())->expected($payload, 'sk_test_secret'));
        $processor->expects($this->once())->method('process')->with(7, ['id' => 'chg_1', 'status' => 'CAPTURED']);

        $controller->execute();
        $this->assertSame([200], $codes->getArrayCopy());
    }

    public function testWebhookWithBadSignatureIsRejected(): void
    {
        [$controller, $processor, $codes] = $this->webhook($this->webhookPayload(), 'deadbeef');
        $processor->expects($this->never())->method('process');

        $controller->execute();
        $this->assertSame([401], $codes->getArrayCopy());
    }

    public function testWebhookWithoutOrderReferenceIsRejected(): void
    {
        $payload = $this->webhookPayload();
        unset($payload['reference']['order']);
        [$controller, $processor, $codes] = $this->webhook($payload, 'x');
        $processor->expects($this->never())->method('process');

        $controller->execute();
        $this->assertSame([400], $codes->getArrayCopy());
    }

    public function testWebhookSkipsCsrfFormKey(): void
    {
        [$controller] = $this->webhook($this->webhookPayload(), 'x');
        $request = $this->createMock(RequestInterface::class);

        $this->assertTrue($controller->validateForCsrf($request));
        $this->assertNull($controller->createCsrfValidationException($request));
    }
}
