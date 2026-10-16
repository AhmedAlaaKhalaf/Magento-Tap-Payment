<?php
declare(strict_types=1);

namespace Tap\Payment\Gateway\Validator;

use Magento\Payment\Gateway\Helper\SubjectReader;
use Magento\Payment\Gateway\Validator\AbstractValidator;
use Magento\Payment\Gateway\Validator\ResultInterface;

/**
 * The charge/authorize call must return an id and a hosted transaction.url.
 * Failing here aborts Place Order, so no order is left without a Tap charge.
 */
class InitializeResponseValidator extends AbstractValidator
{
    public function validate(array $validationSubject): ResultInterface
    {
        $response = SubjectReader::readResponse($validationSubject);
        $errors = [];
        $codes = [];

        foreach ($response['errors'] ?? [] as $error) {
            $errors[] = __('Tap error %1: %2', $error['code'] ?? '?', $error['description'] ?? 'Unknown error');
            $codes[] = (string) ($error['code'] ?? '');
        }

        if (!$errors && (empty($response['id']) || empty($response['transaction']['url']))) {
            $errors[] = __('Tap did not return a payment page URL.');
        }

        return $this->createResult($errors === [], $errors, $codes);
    }
}
