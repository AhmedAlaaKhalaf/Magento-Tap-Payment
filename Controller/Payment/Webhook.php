<?php
declare(strict_types=1);

namespace Tap\Payment\Controller\Payment;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Raw;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;
use Tap\Payment\Gateway\Config\Config;
use Tap\Payment\Model\Api\TapApi;
use Tap\Payment\Model\OrderLocator;
use Tap\Payment\Model\PaymentResultProcessor;
use Tap\Payment\Model\Webhook\SignatureValidator;

/**
 * POST /tap/payment/webhook, the post.url Tap calls server to server.
 *
 * The payload only identifies the order: it must match the order's stored Tap
 * id and carry a valid hashstring, and the status applied is re-read from the
 * Tap API. Non-2xx responses make Tap retry.
 */
class Webhook implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly HttpRequest $request,
        private readonly RawFactory $rawFactory,
        private readonly Config $config,
        private readonly OrderLocator $orderLocator,
        private readonly SignatureValidator $signatureValidator,
        private readonly TapApi $tapApi,
        private readonly PaymentResultProcessor $processor,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Raw
    {
        $payload = json_decode((string) $this->request->getContent(), true);
        $tapId = is_array($payload) ? (string) ($payload['id'] ?? '') : '';
        $incrementId = is_array($payload) ? (string) ($payload['reference']['order'] ?? '') : '';
        $context = ['tap_id' => $tapId, 'order' => $incrementId];

        if (!TapApi::isValidId($tapId) || $incrementId === '') {
            $this->logger->warning('Tap webhook: invalid payload');
            return $this->respond(400, 'Invalid payload');
        }

        try {
            $order = $this->orderLocator->getByTapId($tapId, $incrementId);
        } catch (NoSuchEntityException $e) {
            $this->logger->warning('Tap webhook: unknown order', $context + ['error' => $e->getMessage()]);
            return $this->respond(404, 'Unknown order');
        }

        $storeId = (int) $order->getStoreId();
        $hashstring = (string) $this->request->getHeader('hashstring');
        if (!$this->signatureValidator->isValid($payload, $hashstring, $this->config->getSecretKey($storeId))) {
            $this->logger->warning('Tap webhook: invalid hashstring', $context);
            return $this->respond(401, 'Invalid signature');
        }

        try {
            $charge = $this->tapApi->retrieve($tapId, $storeId);
            $outcome = $this->processor->process((int) $order->getEntityId(), $charge);
        } catch (\Throwable $e) {
            $this->logger->error('Tap webhook: processing failed', $context + ['error' => $e->getMessage()]);
            return $this->respond(500, 'Processing failed');
        }

        $this->logger->info('Tap webhook processed', $context + ['outcome' => $outcome, 'status' => $charge['status'] ?? '']);
        return $this->respond(200, 'OK');
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /** Server-to-server call without a form key; authenticity comes from the hashstring check. */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }

    private function respond(int $code, string $body): Raw
    {
        return $this->rawFactory->create()->setHttpResponseCode($code)->setContents($body);
    }
}
