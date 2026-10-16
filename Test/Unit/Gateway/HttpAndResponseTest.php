<?php
declare(strict_types=1);

namespace Tap\Payment\Test\Unit\Gateway;

use Magento\Framework\DataObject;
use Magento\Payment\Gateway\Data\PaymentDataObjectInterface;
use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Validator\Result;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Tap\Payment\Gateway\Config\Config;
use Tap\Payment\Gateway\Http\TransferFactory;
use Tap\Payment\Gateway\Response\InitializeHandler;
use Tap\Payment\Gateway\Validator\InitializeResponseValidator;
use Tap\Payment\Model\PaymentInfo;

class HttpAndResponseTest extends TestCase
{
    private function transferFactory(): TransferFactory
    {
        $config = $this->createMock(Config::class);
        $config->method('getApiUrl')->willReturn('https://api.tap.company/v2/');
        $config->method('getSecretKey')->with(3)->willReturn('sk_test_123');

        return new TransferFactory(new TransferBuilder(), $config);
    }

    public function testPostTransferStripsRoutingDataAndSendsBearerKey(): void
    {
        $transfer = $this->transferFactory()->create([
            TransferFactory::META => ['store_id' => 3, 'method' => 'POST', 'path' => 'charges'],
            'amount' => 10.0,
        ]);

        $this->assertSame('POST', $transfer->getMethod());
        $this->assertSame('https://api.tap.company/v2/charges', $transfer->getUri());
        $this->assertSame(['amount' => 10.0], $transfer->getBody());
        $this->assertSame('Bearer sk_test_123', $transfer->getHeaders()['Authorization']);
    }

    public function testGetTransferHasNoBody(): void
    {
        $transfer = $this->transferFactory()->create([
            TransferFactory::META => ['store_id' => 3, 'method' => 'GET', 'path' => 'authorize/auth_123'],
        ]);

        $this->assertSame('https://api.tap.company/v2/authorize/auth_123', $transfer->getUri());
        $this->assertSame([], $transfer->getBody());
    }

    private function validator(): InitializeResponseValidator
    {
        $factory = $this->createMock(ResultInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            fn (array $args) => new Result($args['isValid'], $args['failsDescription'], $args['errorCodes'])
        );
        return new InitializeResponseValidator($factory);
    }

    public function testValidatorAcceptsHostedPageResponse(): void
    {
        $result = $this->validator()->validate(['response' => [
            'id' => 'chg_1', 'status' => 'INITIATED', 'transaction' => ['url' => 'https://checkout.tap.company/?token=x'],
        ]]);
        $this->assertTrue($result->isValid());
    }

    public function testValidatorRejectsTapErrorsAndMissingUrl(): void
    {
        $errors = $this->validator()->validate(['response' => ['errors' => [['code' => '1117', 'description' => 'Invalid amount']]]]);
        $this->assertFalse($errors->isValid());
        $this->assertSame(['1117'], $errors->getErrorCodes());
        $this->assertStringContainsString('Invalid amount', (string) $errors->getFailsDescription()[0]);

        $noUrl = $this->validator()->validate(['response' => ['id' => 'chg_1', 'transaction' => []]]);
        $this->assertFalse($noUrl->isValid());
    }

    public function testInitializeHandlerStoresTapDataAndKeepsOrderPending(): void
    {
        $order = $this->getMockBuilder(Order::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $payment = $this->getMockBuilder(Payment::class)->disableOriginalConstructor()->onlyMethods(['getOrder'])->getMock();
        $payment->method('getOrder')->willReturn($order);

        $paymentDO = $this->createMock(PaymentDataObjectInterface::class);
        $paymentDO->method('getPayment')->willReturn($payment);
        $stateObject = new DataObject();

        (new InitializeHandler())->handle(['payment' => $paymentDO, 'stateObject' => $stateObject], [
            'id' => 'chg_TS07A46',
            'object' => 'charge',
            'status' => 'INITIATED',
            'transaction' => ['url' => 'https://checkout.tap.company/?token=x'],
        ]);

        $this->assertSame('chg_TS07A46', $payment->getAdditionalInformation(PaymentInfo::TAP_ID));
        $this->assertSame('https://checkout.tap.company/?token=x', $payment->getAdditionalInformation(PaymentInfo::TRANSACTION_URL));
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $stateObject->getData('state'));
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $stateObject->getData('status'));
        $this->assertFalse($order->getCanSendNewEmailFlag());
    }
}
