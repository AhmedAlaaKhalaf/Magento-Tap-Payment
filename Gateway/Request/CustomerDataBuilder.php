<?php
declare(strict_types=1);

namespace Tap\Payment\Gateway\Request;

use Magento\Payment\Gateway\Data\AddressAdapterInterface;
use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Request\BuilderInterface;

/**
 * Customer block from the billing address. Tap requires first_name and email;
 * the phone is only sent when it can be split into country code and number.
 */
class CustomerDataBuilder implements BuilderInterface
{
    /** ISO country => international dialing code, for the markets Tap serves plus common ones. */
    private const DIALING_CODES = [
        'AE' => '971', 'BH' => '973', 'EG' => '20', 'JO' => '962', 'KW' => '965', 'LB' => '961',
        'OM' => '968', 'QA' => '974', 'SA' => '966', 'GB' => '44', 'US' => '1', 'CA' => '1',
        'FR' => '33', 'DE' => '49', 'IN' => '91', 'PK' => '92', 'TR' => '90',
    ];

    public function build(array $buildSubject): array
    {
        $order = SubjectReader::readPayment($buildSubject)->getOrder();
        $address = $order->getBillingAddress();

        if ($address === null) {
            return ['customer' => ['first_name' => 'Customer', 'email' => '']];
        }

        $customer = [
            'first_name' => (string) $address->getFirstname() ?: 'Customer',
            'last_name' => (string) $address->getLastname(),
            'email' => (string) $address->getEmail(),
        ];

        $phone = $this->phone($address);
        if ($phone !== null) {
            $customer['phone'] = $phone;
        }

        return ['customer' => $customer];
    }

    private function phone(AddressAdapterInterface $address): ?array
    {
        $code = self::DIALING_CODES[strtoupper((string) $address->getCountryId())] ?? null;
        $digits = preg_replace('/\D+/', '', (string) $address->getTelephone());

        if ($code === null || $digits === '') {
            return null;
        }

        if (str_starts_with($digits, '00' . $code)) {
            $digits = substr($digits, 2 + strlen($code));
        } elseif (str_starts_with($digits, $code) && strlen($digits) > strlen($code) + 6) {
            $digits = substr($digits, strlen($code));
        }
        $digits = ltrim($digits, '0');

        return strlen($digits) >= 6 ? ['country_code' => $code, 'number' => $digits] : null;
    }
}
