<?php
declare(strict_types=1);

namespace Tap\Payment\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use Tap\Payment\Model\Amount;
use Tap\Payment\Model\ChargeStatus;

class AmountAndStatusTest extends TestCase
{
    public function testCurrencyPrecision(): void
    {
        $this->assertSame(2, Amount::decimals('SAR'));
        $this->assertSame(3, Amount::decimals('kwd'));
        $this->assertSame('1.000', Amount::format(1, 'KWD'));
        $this->assertSame('12.50', Amount::format('12.5', 'SAR'));
        $this->assertSame(10.13, Amount::round(10.125001, 'USD'));
        $this->assertTrue(Amount::equals(25, '25.00', 'SAR'));
        $this->assertFalse(Amount::equals(25, 25.01, 'SAR'));
    }

    public function testSuccessDependsOnTransactionObject(): void
    {
        $this->assertTrue(ChargeStatus::isSuccessful('CAPTURED', 'charge'));
        $this->assertFalse(ChargeStatus::isSuccessful('AUTHORIZED', 'charge'));
        $this->assertTrue(ChargeStatus::isSuccessful('AUTHORIZED', 'authorize'));
        $this->assertTrue(ChargeStatus::isSuccessful('captured', 'authorize'));
    }

    public function testFailedAndPendingStatuses(): void
    {
        foreach (['DECLINED', 'CANCELLED', 'ABANDONED', 'FAILED', 'RESTRICTED', 'VOID', 'TIMEDOUT'] as $status) {
            $this->assertTrue(ChargeStatus::isFailed($status), $status);
        }
        foreach (['INITIATED', 'IN_PROGRESS', 'UNKNOWN'] as $status) {
            $this->assertTrue(ChargeStatus::isPending($status, 'charge'), $status);
            $this->assertFalse(ChargeStatus::isFailed($status), $status);
        }
    }

    public function testObjectFromId(): void
    {
        $this->assertSame('authorize', ChargeStatus::objectFromId('auth_TS03A47'));
        $this->assertSame('charge', ChargeStatus::objectFromId('chg_TS07A46'));
    }
}
