<?php
declare(strict_types=1);

namespace Tap\Payment\Gateway\Http\Client;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Payment\Gateway\Http\ClientException;
use Magento\Payment\Gateway\Http\ClientInterface;
use Magento\Payment\Gateway\Http\TransferInterface;
use Magento\Payment\Model\Method\Logger;

/**
 * JSON over HTTPS to the Tap API. Returns the decoded body (including Tap's
 * {"errors": [...]} payloads) and adds the HTTP status under HTTP_STATUS.
 */
class TapClient implements ClientInterface
{
    public const HTTP_STATUS = '_http_status';
    private const TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Logger $logger
    ) {
    }

    public function placeRequest(TransferInterface $transferObject): array
    {
        $method = $transferObject->getMethod();
        $uri = $transferObject->getUri();
        $log = ['request' => ['method' => $method, 'uri' => $uri, 'body' => $transferObject->getBody()]];

        $curl = $this->curlFactory->create();
        $curl->setTimeout(self::TIMEOUT_SECONDS);
        $curl->setHeaders($transferObject->getHeaders());

        try {
            if ($method === 'GET') {
                $curl->get($uri);
            } else {
                $curl->post($uri, json_encode($transferObject->getBody(), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            }
            $status = $curl->getStatus();
            $raw = (string) $curl->getBody();
        } catch (\Exception $e) {
            $log['error'] = $e->getMessage();
            $this->logger->debug($log);
            throw new ClientException(__('Tap Payments could not be reached. Please try again.'), $e);
        }

        $response = json_decode($raw, true);
        $log['response'] = ['status' => $status, 'body' => is_array($response) ? $response : $raw];
        $this->logger->debug($log);

        if (!is_array($response)) {
            throw new ClientException(__('Tap Payments returned an unexpected response (HTTP %1).', $status));
        }

        $response[self::HTTP_STATUS] = $status;
        return $response;
    }
}
