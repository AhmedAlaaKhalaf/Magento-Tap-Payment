<?php
declare(strict_types=1);

namespace Tap\Payment\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class TransactionMode implements OptionSourceInterface
{
    public const CHARGE = 'charge';
    public const AUTHORIZE = 'authorize';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::CHARGE, 'label' => __('Charge (capture immediately)')],
            ['value' => self::AUTHORIZE, 'label' => __('Authorize (hold funds)')],
        ];
    }
}
