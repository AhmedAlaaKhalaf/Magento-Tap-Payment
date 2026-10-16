<?php
declare(strict_types=1);

namespace Tap\Payment\Model\Webhook;

use Tap\Payment\Model\Amount;

/**
 * Verifies the "hashstring" header Tap sends with webhooks:
 * HMAC-SHA256(secret key, "x_id{id}x_amount{amount}x_currency{currency}
 * x_gateway_reference{ref}x_payment_reference{ref}x_status{status}x_created{created}"),
 * with the amount formatted to the currency's decimals.
 * https://developers.tap.company/docs/webhook
 */
class SignatureValidator
{
    public function expected(array $payload, string $secretKey): string
    {
        $currency = (string) ($payload['currency'] ?? '');

        $message = 'x_id' . ($payload['id'] ?? '')
            . 'x_amount' . Amount::format($payload['amount'] ?? 0, $currency)
            . 'x_currency' . $currency
            . 'x_gateway_reference' . ($payload['reference']['gateway'] ?? '')
            . 'x_payment_reference' . ($payload['reference']['payment'] ?? '')
            . 'x_status' . ($payload['status'] ?? '')
            . 'x_created' . ($payload['transaction']['created'] ?? '');

        return hash_hmac('sha256', $message, $secretKey);
    }

    public function isValid(array $payload, string $hashstring, string $secretKey): bool
    {
        if ($hashstring === '' || $secretKey === '') {
            return false;
        }
        return hash_equals($this->expected($payload, $secretKey), strtolower(trim($hashstring)));
    }
}
