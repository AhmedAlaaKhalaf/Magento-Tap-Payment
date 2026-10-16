<?php
declare(strict_types=1);

namespace Tap\Payment\Model;

/**
 * Classifies Tap charge/authorize statuses.
 */
class ChargeStatus
{
    public const INITIATED = 'INITIATED';
    public const IN_PROGRESS = 'IN_PROGRESS';
    public const CAPTURED = 'CAPTURED';
    public const AUTHORIZED = 'AUTHORIZED';
    public const UNKNOWN = 'UNKNOWN';

    private const FAILED = ['ABANDONED', 'CANCELLED', 'FAILED', 'DECLINED', 'RESTRICTED', 'VOID', 'TIMEDOUT', 'REVERSED'];

    public const OBJECT_CHARGE = 'charge';
    public const OBJECT_AUTHORIZE = 'authorize';

    /** A charge succeeds when CAPTURED; an authorization when AUTHORIZED (or later CAPTURED). */
    public static function isSuccessful(string $status, string $object): bool
    {
        $status = strtoupper($status);
        if ($object === self::OBJECT_AUTHORIZE) {
            return $status === self::AUTHORIZED || $status === self::CAPTURED;
        }
        return $status === self::CAPTURED;
    }

    public static function isFailed(string $status): bool
    {
        return in_array(strtoupper($status), self::FAILED, true);
    }

    /**
     * INITIATED, IN_PROGRESS and UNKNOWN are not final: the customer may still
     * be paying, or the issuer may answer later. The order stays pending.
     */
    public static function isPending(string $status, string $object): bool
    {
        return !self::isSuccessful($status, $object) && !self::isFailed($status);
    }

    public static function objectFromId(string $tapId): string
    {
        return str_starts_with($tapId, 'auth_') ? self::OBJECT_AUTHORIZE : self::OBJECT_CHARGE;
    }
}
