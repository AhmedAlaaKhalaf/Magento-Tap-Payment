<?php
declare(strict_types=1);

namespace Tap\Payment\Model;

use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Tap\Payment\Gateway\Config\Config;

/**
 * Finds the order a Tap charge belongs to. The charge carries the increment id
 * in reference.order; the order must also have stored that exact Tap id at
 * Place Order, so a charge can never be applied to someone else's order.
 */
class OrderLocator
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SearchCriteriaBuilder $searchCriteriaBuilder
    ) {
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getByTapId(string $tapId, string $incrementId): OrderInterface
    {
        if ($tapId === '' || $incrementId === '') {
            throw new NoSuchEntityException(__('Missing Tap transaction or order reference.'));
        }

        $criteria = $this->searchCriteriaBuilder
            ->addFilter(OrderInterface::INCREMENT_ID, $incrementId)
            ->setPageSize(1)
            ->create();
        $orders = $this->orderRepository->getList($criteria)->getItems();
        $order = reset($orders);

        if (!$order) {
            throw new NoSuchEntityException(__('Order %1 was not found.', $incrementId));
        }

        $payment = $order->getPayment();
        if ($payment === null
            || $payment->getMethod() !== Config::CODE
            || $payment->getAdditionalInformation(PaymentInfo::TAP_ID) !== $tapId
        ) {
            throw new NoSuchEntityException(__('Tap transaction %1 does not belong to order %2.', $tapId, $incrementId));
        }

        return $order;
    }
}
