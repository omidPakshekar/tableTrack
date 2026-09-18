<?php

namespace App\Services;

use App\Models\Order;
use App\Models\OrderCashCollection;
use App\Enums\OrderStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class OrderCashCollectionService
{
    private const RECORDABLE_STATUSES = ['collected', 'partial', 'not_collected'];

    public function calculateExpectedAmount(Order $order): float
    {
        return round(max((float) $order->total - (float) ($order->amount_paid ?? 0), 0), 2);
    }

    public function requiresCashCollection(Order $order): bool
    {
        return $order->order_type === 'delivery'
            && !is_null($order->delivery_executive_id)
            && $this->calculateExpectedAmount($order) > 0;
    }

    public function syncForOrder(Order $order): ?OrderCashCollection
    {
        if (!Schema::hasTable('order_cash_collections')) {
            return null;
        }

        $expectedAmount = $this->calculateExpectedAmount($order);
        $collection = OrderCashCollection::withoutGlobalScopes()
            ->firstWhere('order_id', $order->id);

        if ($collection && $order->order_status?->value === OrderStatus::CANCELLED->value) {
            if (in_array($collection->status, ['pending_collection', 'not_collected'], true) && is_null($collection->submitted_at) && is_null($collection->settled_at)) {
                $collection->update([
                    'delivery_executive_id' => $order->delivery_executive_id ?? $collection->delivery_executive_id,
                    'expected_amount' => $expectedAmount,
                    'collected_amount' => 0,
                    'status' => 'not_collected',
                    'notes' => $collection->notes ?: __('modules.delivery.orderCancelledBeforeCollection'),
                    'recorded_at' => $collection->recorded_at ?? now(),
                ]);
            }

            return $collection->fresh();
        }

        if (!$this->requiresCashCollection($order)) {
            if ($collection && is_null($collection->recorded_at) && is_null($collection->collected_amount)) {
                $collection->delete();
                return null;
            }

            if ($collection) {
                $payload = [];

                if (!$this->hasAppliedCashToOrder($collection)) {
                    $payload['delivery_executive_id'] = $order->delivery_executive_id;
                    $payload['expected_amount'] = $expectedAmount;
                }

                if (!empty($payload)) {
                    $collection->update($payload);
                }
            }

            return $collection;
        }

        $payload = [
            'branch_id' => $order->branch_id,
            'delivery_executive_id' => $order->delivery_executive_id,
            'expected_amount' => $expectedAmount,
        ];

        if ($collection) {
            if ($collection->status === 'pending_collection' && is_null($collection->recorded_at)) {
                $payload['status'] = 'pending_collection';
                $payload['delivery_executive_id'] = $order->delivery_executive_id;
            } else {
                unset($payload['delivery_executive_id']);
            }

            $collection->update($payload);

            return $collection->fresh();
        }

        return OrderCashCollection::create($payload + [
            'order_id' => $order->id,
            'status' => 'pending_collection',
        ]);
    }

    public function hasRecordedCollection(Order $order): bool
    {
        $collection = $order->orderCashCollection;

        return $collection
            && in_array($collection->status, ['collected', 'partial', 'not_collected', 'submitted', 'settled'], true)
            && !is_null($collection->recorded_at);
    }

    public function recordCollection(Order $order, string $status, float $collectedAmount, ?string $notes = null): OrderCashCollection
    {
        if (!in_array($status, self::RECORDABLE_STATUSES, true)) {
            throw new InvalidArgumentException(__('messages.invalidRequest'));
        }

        if ($order->order_status?->value === OrderStatus::CANCELLED->value) {
            throw new InvalidArgumentException(__('modules.delivery.orderCancelledCashCollectionBlocked'));
        }

        if (!$this->requiresCashCollection($order)) {
            throw new InvalidArgumentException(__('modules.delivery.cashCollectionNotRequired'));
        }

        $expectedAmount = $this->calculateExpectedAmount($order);
        $collectedAmount = round($collectedAmount, 2);

        if ($status === 'collected' && $collectedAmount !== $expectedAmount) {
            throw new InvalidArgumentException(__('modules.delivery.collectedAmountMustMatchExpected'));
        }

        if ($status === 'partial' && ($collectedAmount <= 0 || $collectedAmount >= $expectedAmount || blank($notes))) {
            throw new InvalidArgumentException(__('modules.delivery.partialCollectionValidationMessage'));
        }

        if ($status === 'not_collected' && ($collectedAmount !== 0.0 || blank($notes))) {
            throw new InvalidArgumentException(__('modules.delivery.notCollectedValidationMessage'));
        }

        return DB::transaction(function () use ($order, $status, $collectedAmount, $notes, $expectedAmount) {
            $collection = $this->syncForOrder($order)
                ?? OrderCashCollection::create([
                    'branch_id' => $order->branch_id,
                    'order_id' => $order->id,
                    'delivery_executive_id' => $order->delivery_executive_id,
                    'expected_amount' => $expectedAmount,
                ]);

            $previousAppliedAmount = $this->appliedAmountForCollection($collection);
            $newAppliedAmount = in_array($status, ['collected', 'partial'], true) ? $collectedAmount : 0.0;

            $collection->update([
                'delivery_executive_id' => $collection->delivery_executive_id ?? $order->delivery_executive_id,
                'expected_amount' => $expectedAmount,
                'collected_amount' => $collectedAmount,
                'status' => $status,
                'notes' => $notes,
                'recorded_at' => now(),
            ]);

            $delta = round($newAppliedAmount - $previousAppliedAmount, 2);

            if ($delta !== 0.0) {
                $currentPaid = round((float) ($order->amount_paid ?? 0), 2);
                $newAmountPaid = min(round((float) $order->total, 2), max(0.0, round($currentPaid + $delta, 2)));

                $order->update([
                    'amount_paid' => $newAmountPaid,
                ]);
            }

            return $collection->fresh();
        });
    }

    private function hasAppliedCashToOrder(OrderCashCollection $collection): bool
    {
        return $this->appliedAmountForCollection($collection) > 0;
    }

    private function appliedAmountForCollection(OrderCashCollection $collection): float
    {
        return in_array($collection->status, ['collected', 'partial', 'submitted', 'settled'], true)
            ? round((float) ($collection->collected_amount ?? 0), 2)
            : 0.0;
    }
}
