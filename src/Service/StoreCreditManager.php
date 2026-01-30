<?php

namespace StoreCredit\Service;

use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;

class StoreCreditManager
{
    private EntityRepository $storeCreditRepository;
    private EntityRepository $storeCreditHistoryRepository;
    private EntityRepository $customerRepository;
    private SystemConfigService $systemConfigService;

    public function __construct(
        EntityRepository $storeCreditRepository,
        EntityRepository $storeCreditHistoryRepository,
        EntityRepository $customerRepository,
        SystemConfigService $systemConfigService,
    ) {
        $this->storeCreditRepository        = $storeCreditRepository;
        $this->storeCreditHistoryRepository = $storeCreditHistoryRepository;
        $this->customerRepository           = $customerRepository;
        $this->systemConfigService          = $systemConfigService;
    }

    public function addCredit(string $customerId, ?string $orderId, ?string $currencyId, float $amount, Context $context, ?string $reason = null): string
    {
        $amountInCredits = $this->convertMoneyToCredits($customerId, $amount, $context);

        $storeCreditId = $this->getStoreCreditId($customerId, $context);

        if ($storeCreditId) {
            $currentCredits = $this->getCreditBalance($customerId, $context)['balanceCredits'];
            $newCredits     = $currentCredits + $amountInCredits;

            $this->storeCreditRepository->update([
                [
                    'id'         => $storeCreditId,
                    'balance'    => $newCredits,
                    'currencyId' => $currencyId,
                    'updatedAt'  => (new \DateTime())->format('Y-m-d H:i:s'),
                ]
            ], $context);
        } else {
            $storeCreditId = Uuid::randomHex();
            $this->storeCreditRepository->create([
                [
                    'id'         => $storeCreditId,
                    'customerId' => $customerId,
                    'balance'    => $amountInCredits,
                    'currencyId' => $currencyId,
                ]
            ], $context);
        }

        $historyId = Uuid::randomHex();
        $this->storeCreditHistoryRepository->create([
            [
                'id'            => $historyId,
                'storeCreditId' => $storeCreditId,
                'orderId'       => $orderId,
                'amount'        => $amount,
                'currencyId'    => $currencyId,
                'reason'        => $reason ?: 'Not specified',
                'actionType'    => 'add',
                'createdAt'     => (new \DateTime())->format('Y-m-d H:i:s.u'),
            ]
        ], $context);

        return $historyId;
    }

    public function deductCredit(string $customerId, float $amount, Context $context, ?string $orderId, ?string $currencyId, ?string $reason = null): ?string
    {
        $storeCreditId = $this->getStoreCreditId($customerId, $context);
        $amountInCredits = $this->convertMoneyToCredits($customerId, $amount, $context);

        if ($storeCreditId) {
            $currentCredits = $this->getCreditBalance($customerId, $context)['balanceCredits'];

            if (!($currentCredits < $amountInCredits)) {
                $newCredits = $currentCredits - $amountInCredits;

                $this->storeCreditRepository->update([
                    [
                        'id'         => $storeCreditId,
                        'balance'    => $newCredits,
                        'currencyId' => $currencyId,
                        'updatedAt'  => (new \DateTime())->format('Y-m-d H:i:s'),
                    ]
                ], $context);

                $historyId = Uuid::randomHex();
                $this->storeCreditHistoryRepository->create([
                    [
                        'id'            => $historyId,
                        'storeCreditId' => $storeCreditId,
                        'orderId'       => $orderId,

                        'amount'        => $amount,
                        'currencyId'    => $currencyId,
                        'reason'        => $reason ?: 'Not specified',
                        'actionType'    => 'deduct',
                        'createdAt'     => (new \DateTime())->format('Y-m-d H:i:s.u'),
                    ]
                ], $context);
                return $historyId;
            }
        }
        return('No store credit found for this customer or insufficient store credit balance.');
    }
    public function getCreditBalance(string $customerId, Context $context): array
    {
        $valuePerCredit = $this->getValuePerCredit($customerId, $context);
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $result = $this->storeCreditRepository->search($criteria, $context);

        $storeCreditEntity = $result->first();
        $credits = $storeCreditEntity ? (float) $storeCreditEntity->get('balance') : 0.0;

        return [
            'balanceCredits'    => $credits,
            'balanceAmount'     => $credits * $valuePerCredit,
            'balanceCurrencyId' => $storeCreditEntity ? $storeCreditEntity->get('currencyId') : null,
        ];
    }

    public function getStoreCreditId(string $customerId, Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customerId));
        $result = $this->storeCreditRepository->search($criteria, $context);

        $storeCreditEntity = $result->first();

        return $storeCreditEntity ? $storeCreditEntity->get('id') : null;
    }

    private function convertMoneyToCredits(string $customerId, float $moneyAmount, Context $context): float
    {
        if ($moneyAmount <= 0) {
            return 0.0;
        }

        $valuePerCredit = $this->getValuePerCredit($customerId, $context);
        if ($valuePerCredit <= 0) {
            $valuePerCredit = 1.0;
        }

        return $moneyAmount / $valuePerCredit;
    }

    private function getValuePerCredit(string $customerId, Context $context): float
    {
        $criteria = new Criteria([$customerId]);
        $customer = $this->customerRepository->search($criteria, $context)->first();

        $customFields = $customer ? ($customer->get('customFields') ?? []) : [];
        $value = is_array($customFields) ? ($customFields['store_credit_value_per_unit'] ?? null) : null;

        $value = is_numeric($value) ? (float) $value : 0.0;
        if ($value > 0) {
            return $value;
        }

        $default = $this->systemConfigService->get('StoreCredit.config.defaultValuePerCredit');
        $default = is_numeric($default) ? (float) $default : 1.0;

        return $default > 0 ? $default : 1.0;
    }
}
