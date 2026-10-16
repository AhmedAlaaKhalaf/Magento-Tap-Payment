<?php
declare(strict_types=1);

/**
 * Calls the Tap sandbox through the module's own classes (builders, transfer
 * factory, HTTP client, validator, handler, TapApi) without a Magento store.
 *
 *   MAGENTO_ROOT=/path/to/magento TAP_SECRET_KEY=sk_test_... php dev/tap-sandbox-smoke.php [charge|authorize]
 *   php dev/tap-sandbox-smoke.php status <chg_...|auth_...>   (after paying on the hosted page)
 */

use Magento\Framework\DataObject;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Payment\Gateway\Data\AddressAdapterInterface;
use Magento\Payment\Gateway\Data\OrderAdapterInterface;
use Magento\Payment\Gateway\Data\PaymentDataObject;
use Magento\Payment\Gateway\Http\TransferBuilder;
use Magento\Payment\Gateway\Validator\Result;
use Magento\Payment\Gateway\Validator\ResultInterfaceFactory;
use Magento\Payment\Model\Method\Logger;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use Psr\Log\NullLogger;
use Tap\Payment\Gateway\Config\Config;
use Tap\Payment\Gateway\Http\Client\TapClient;
use Tap\Payment\Gateway\Http\TransferFactory;
use Tap\Payment\Gateway\Request\CustomerDataBuilder;
use Tap\Payment\Gateway\Request\PaymentDataBuilder;
use Tap\Payment\Gateway\Request\UrlDataBuilder;
use Tap\Payment\Gateway\Response\InitializeHandler;
use Tap\Payment\Gateway\Validator\InitializeResponseValidator;
use Tap\Payment\Model\Api\TapApi;
use Tap\Payment\Model\PaymentInfo;

$magentoRoot = getenv('MAGENTO_ROOT') ?: dirname(__DIR__) . '/.dev/magento';
$loader = require $magentoRoot . '/vendor/autoload.php';
$loader->addPsr4('Tap\\Payment\\', dirname(__DIR__) . '/');
require_once $magentoRoot . '/vendor/magento/framework/Phrase/__.php';

$secretKey = getenv('TAP_SECRET_KEY');
if (!$secretKey) {
    fwrite(STDERR, "Set TAP_SECRET_KEY to your Tap sandbox secret key (sk_test_...).\n");
    exit(1);
}
$mode = ($argv[1] ?? 'charge') === 'authorize' ? 'authorize' : 'charge';

$config = new class ($secretKey, $mode) extends Config {
    public function __construct(private string $key, private string $mode)
    {
    }
    public function getSecretKey(?int $storeId = null): string { return $this->key; }
    public function getApiUrl(?int $storeId = null): string { return 'https://api.tap.company/v2/'; }
    public function getSourceId(?int $storeId = null): string { return 'src_all'; }
    public function getTransactionMode(?int $storeId = null): string { return $this->mode; }
};

$address = new class implements AddressAdapterInterface {
    public function getRegionCode() { return null; }
    public function getCountryId() { return 'SA'; }
    public function getStreetLine1() { return 'King Fahd Rd'; }
    public function getStreetLine2() { return ''; }
    public function getTelephone() { return '0512345678'; }
    public function getPostcode() { return '12271'; }
    public function getCity() { return 'Riyadh'; }
    public function getFirstname() { return 'Alice'; }
    public function getLastname() { return 'Smith'; }
    public function getMiddlename() { return null; }
    public function getCustomerId() { return null; }
    public function getEmail() { return 'alice@example.com'; }
    public function getPrefix() { return null; }
    public function getSuffix() { return null; }
    public function getCompany() { return null; }
};

$incrementId = 'SMOKE' . time();
$orderAdapter = new class ($address, $incrementId) implements OrderAdapterInterface {
    public function __construct(private AddressAdapterInterface $address, private string $increment)
    {
    }
    public function getCurrencyCode() { return 'SAR'; }
    public function getOrderIncrementId() { return $this->increment; }
    public function getCustomerId() { return null; }
    public function getBillingAddress() { return $this->address; }
    public function getShippingAddress() { return $this->address; }
    public function getStoreId() { return 1; }
    public function getId() { return 1; }
    public function getGrandTotalAmount() { return 49.0; }
    public function getItems() { return []; }
    public function getRemoteIp() { return '127.0.0.1'; }
};

$order = (new ReflectionClass(Order::class))->newInstanceWithoutConstructor();
$payment = (new ReflectionClass(Payment::class))->newInstanceWithoutConstructor();
$payment->setOrder($order);
$paymentDO = new PaymentDataObject($orderAdapter, $payment);

$urlBuilder = new class implements \Magento\Framework\UrlInterface {
    public function getUseSession() { return false; }
    public function getBaseUrl($params = []) { return 'https://shop.example.com/'; }
    public function getCurrentUrl() { return 'https://shop.example.com/'; }
    public function getRouteUrl($routePath = null, $routeParams = null) { return $this->getUrl($routePath); }
    public function addSessionParam() { return $this; }
    public function addQueryParams(array $data) { return $this; }
    public function setQueryParam($key, $data) { return $this; }
    public function getUrl($routePath = null, $routeParams = null) { return 'https://shop.example.com/' . trim((string) $routePath, '/') . '/'; }
    public function escape($value) { return $value; }
    public function getDirectUrl($url, $params = []) { return $url; }
    public function sessionUrlVar($html) { return $html; }
    public function isOwnOriginUrl() { return true; }
    public function getRedirectUrl($url) { return $url; }
    public function setScope($params) { return $this; }
};

$curlFactory = new class extends CurlFactory {
    public function __construct()
    {
    }
    public function create(array $data = []) { return new Curl(); }
};
$resultFactory = new class extends ResultInterfaceFactory {
    public function __construct()
    {
    }
    public function create(array $data = []) { return new Result($data['isValid'], $data['failsDescription'], $data['errorCodes']); }
};

$transferFactory = new TransferFactory(new TransferBuilder(), $config);
$client = new TapClient($curlFactory, new Logger(new NullLogger()));

if (($argv[1] ?? '') === 'status') {
    $charge = (new TapApi($client, $transferFactory))->retrieve((string) ($argv[2] ?? ''), 1);
    printf("%s  status=%s  %s %s  response=%s %s  card=%s\n",
        $charge['id'], $charge['status'], $charge['amount'], $charge['currency'],
        $charge['response']['code'] ?? '', $charge['response']['message'] ?? '',
        isset($charge['card']['last_four']) ? $charge['card']['brand'] . ' ' . $charge['card']['last_four'] : '-');
    exit(0);
}

$subject = ['payment' => $paymentDO];
$request = array_merge(
    (new PaymentDataBuilder($config))->build($subject),
    (new CustomerDataBuilder())->build($subject),
    (new UrlDataBuilder($urlBuilder))->build($subject)
);

$response = $client->placeRequest($transferFactory->create($request));

$result = (new InitializeResponseValidator($resultFactory))->validate(['response' => $response]);
if (!$result->isValid()) {
    fwrite(STDERR, "FAIL: " . implode('; ', array_map('strval', $result->getFailsDescription())) . "\n");
    exit(1);
}

$stateObject = new DataObject();
(new InitializeHandler())->handle(['payment' => $paymentDO, 'stateObject' => $stateObject], $response);
$tapId = $payment->getAdditionalInformation(PaymentInfo::TAP_ID);

$retrieved = (new TapApi($client, $transferFactory))->retrieve($tapId, 1);

printf("Mode:            %s (POST /v2/%s)\n", $mode, $mode === 'authorize' ? 'authorize' : 'charges');
printf("Order:           %s, 49.00 SAR\n", $incrementId);
printf("Tap id:          %s\n", $tapId);
printf("Order state:     %s\n", $stateObject->getData('state'));
printf("Hosted page:     %s\n", $payment->getAdditionalInformation(PaymentInfo::TRANSACTION_URL));
printf("Retrieved:       %s %s %s, reference.order=%s\n", $retrieved['status'], $retrieved['amount'], $retrieved['currency'], $retrieved['reference']['order'] ?? '-');
echo "OK\n";
