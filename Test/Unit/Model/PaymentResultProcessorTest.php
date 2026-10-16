<?php
declare(strict_types=1);

namespace Tap\Payment\Test\Unit\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Config as OrderConfig;
use Magento\Sales\Model\Order\Email\Sender\OrderSender;
use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tap\Payment\Model\PaymentInfo;
use Tap\Payment\Model\PaymentResultProcessor;

class PaymentResultProcessorTest extends TestCase
{
    private Order&MockObject $order;
    private Payment&MockObject $payment;
    private OrderRepositoryInterface&MockObject $repository;
    private LockManagerInterface&MockObject $locks;
    private OrderSender&MockObject $sender;
    private array $comments = [];

    protected function setUp(): void
    {
        $this->payment = $this->getMockBuilder(Payment::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['registerCaptureNotification', 'registerAuthorizationNotification'])
            ->getMock();

        $orderConfig = $this->createMock(OrderConfig::class);
        $orderConfig->method('getStateDefaultStatus')->willReturn('processing');

        $this->order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getPayment', 'canCancel', 'cancel', 'addCommentToStatusHistory', 'getConfig'])
            ->getMock();
        $this->order->setData([
            'entity_id' => 7,
            'increment_id' => '000000042',
            'state' => Order::STATE_PENDING_PAYMENT,
            'status' => 'pending_payment',
            'base_grand_total' => 125.5,
            'base_currency_code' => 'SAR',
        ]);
        $this->order->method('getPayment')->willReturn($this->payment);
        $this->order->method('getConfig')->willReturn($orderConfig);
        $this->order->method('addCommentToStatusHistory')->willReturnCallback(function ($comment) {
            $this->comments[] = (string) $comment;
            return $this->order;
        });

        $this->repository = $this->createMock(OrderRepositoryInterface::class);
        $this->repository->method('get')->with(7)->willReturn($this->order);
        $this->locks = $this->createMock(LockManagerInterface::class);
        $this->locks->method('lock')->willReturn(true);
        $this->sender = $this->createMock(OrderSender::class);
    }

    private function processor(): PaymentResultProcessor
    {
        return new PaymentResultProcessor($this->repository, $this->locks, $this->sender, new NullLogger());
    }

    private function charge(array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 'chg_TS07A46',
            'object' => 'charge',
            'status' => 'CAPTURED',
            'amount' => 125.5,
            'currency' => 'SAR',
            'response' => ['code' => '000', 'message' => 'Captured'],
            'reference' => ['payment' => '4327230736', 'order' => '000000042'],
            'card' => ['brand' => 'VISA', 'last_four' => '1019'],
        ], $overrides);
    }

    public function testCapturedChargeRecordsCaptureTransactionAndMovesToProcessing(): void
    {
        $this->payment->expects($this->once())->method('registerCaptureNotification')->with(125.5);
        $this->payment->expects($this->never())->method('registerAuthorizationNotification');
        $this->repository->expects($this->once())->method('save')->with($this->order);
        $this->sender->expects($this->once())->method('send')->with($this->order);
        $this->locks->expects($this->once())->method('unlock')->with('tap_payment_order_7');

        $result = $this->processor()->process(7, $this->charge());

        $this->assertSame(PaymentResultProcessor::RESULT_PAID, $result);
        $this->assertSame('chg_TS07A46', $this->payment->getTransactionId());
        $this->assertTrue($this->payment->getIsTransactionClosed());
        $this->assertSame(Order::STATE_PROCESSING, $this->order->getState());
        $this->assertSame('processing', $this->order->getStatus());
        $this->assertSame('CAPTURED', $this->payment->getAdditionalInformation(PaymentInfo::TAP_STATUS));
        $this->assertSame('VISA **** 1019', $this->payment->getAdditionalInformation(PaymentInfo::TAP_CARD));
        $raw = $this->payment->getTransactionAdditionalInfo()[Transaction::RAW_DETAILS];
        $this->assertSame('chg_TS07A46', $raw['id']);
        $this->assertSame('4327230736', $raw['reference_payment']);
    }

    public function testAuthorizedAuthorizationRecordsOpenAuthTransaction(): void
    {
        $this->payment->expects($this->once())->method('registerAuthorizationNotification')->with(125.5);
        $this->payment->expects($this->never())->method('registerCaptureNotification');

        $result = $this->processor()->process(7, $this->charge(['id' => 'auth_TS03A47', 'object' => 'authorize', 'status' => 'AUTHORIZED']));

        $this->assertSame(PaymentResultProcessor::RESULT_PAID, $result);
        $this->assertSame('auth_TS03A47', $this->payment->getTransactionId());
        $this->assertFalse($this->payment->getIsTransactionClosed());
        $this->assertSame(Order::STATE_PROCESSING, $this->order->getState());
    }

    public function testDeclinedPaymentCancelsTheOrder(): void
    {
        $this->order->method('canCancel')->willReturn(true);
        $this->order->expects($this->once())->method('cancel');
        $this->payment->expects($this->never())->method('registerCaptureNotification');
        $this->repository->expects($this->once())->method('save');
        $this->sender->expects($this->never())->method('send');

        $result = $this->processor()->process(7, $this->charge(['status' => 'DECLINED', 'response' => ['code' => '505', 'message' => 'Declined']]));

        $this->assertSame(PaymentResultProcessor::RESULT_FAILED, $result);
        $this->assertStringContainsString('chg_TS07A46 was DECLINED (Declined)', end($this->comments));
    }

    public function testNonFinalStatusLeavesOrderPending(): void
    {
        $this->order->expects($this->never())->method('cancel');
        $this->payment->expects($this->never())->method('registerCaptureNotification');

        $result = $this->processor()->process(7, $this->charge(['status' => 'INITIATED']));

        $this->assertSame(PaymentResultProcessor::RESULT_PENDING, $result);
        $this->assertSame(Order::STATE_PENDING_PAYMENT, $this->order->getState());
    }

    public function testAlreadyFinalOrderIsNotChangedAgain(): void
    {
        $this->order->setState(Order::STATE_PROCESSING);
        $this->payment->expects($this->never())->method('registerCaptureNotification');
        $this->repository->expects($this->never())->method('save');
        $this->sender->expects($this->never())->method('send');

        $this->assertSame(PaymentResultProcessor::RESULT_PAID, $this->processor()->process(7, $this->charge()));

        $this->order->setState(Order::STATE_CANCELED);
        $this->assertSame(PaymentResultProcessor::RESULT_FAILED, $this->processor()->process(7, $this->charge()));
    }

    public function testAmountMismatchIsFlaggedForReview(): void
    {
        $this->payment->expects($this->once())->method('registerCaptureNotification')->with(100.0);

        $this->processor()->process(7, $this->charge(['amount' => 100.0]));

        $this->assertTrue($this->payment->getIsFraudDetected());
        $this->assertStringContainsString('Tap reported 100.00 SAR but the order total is 125.50 SAR', $this->comments[0]);
    }

    public function testBusyLockStopsProcessing(): void
    {
        $locks = $this->createMock(LockManagerInterface::class);
        $locks->method('lock')->willReturn(false);
        $this->repository->expects($this->never())->method('get');

        $this->expectException(LocalizedException::class);
        (new PaymentResultProcessor($this->repository, $locks, $this->sender, new NullLogger()))->process(7, $this->charge());
    }

    public function testLockIsReleasedWhenProcessingFails(): void
    {
        $this->payment->method('registerCaptureNotification')->willThrowException(new \RuntimeException('db down'));
        $this->locks->expects($this->once())->method('unlock')->with('tap_payment_order_7');

        $this->expectException(\RuntimeException::class);
        $this->processor()->process(7, $this->charge());
    }

    public function testCancelPendingCancelsOnlyPendingOrders(): void
    {
        $this->order->method('canCancel')->willReturn(true);
        $this->order->expects($this->once())->method('cancel');

        $this->processor()->cancelPending(7, 'Tap payment page unavailable');
        $this->assertStringContainsString('NOT_STARTED (Tap payment page unavailable)', end($this->comments));
    }
}
