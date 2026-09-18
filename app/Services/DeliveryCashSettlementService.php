<?php

namespace App\Services;

use App\Models\DeliveryCashSettlement;
use App\Models\DeliveryExecutive;
use App\Models\OrderCashCollection;
use App\Models\User;
use App\Notifications\DeliveryCashSettlementNotification;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

class DeliveryCashSettlementService
{
    public function submitForExecutive(DeliveryExecutive $executive, array $collectionIds, float $submittedAmount, ?string $notes = null): DeliveryCashSettlement
    {
        $settlement = DB::transaction(function () use ($executive, $collectionIds, $submittedAmount, $notes) {
            $collectionIds = array_values(array_unique(array_map('intval', $collectionIds)));

            $collections = OrderCashCollection::withoutGlobalScopes()
                ->with('order')
                ->where('delivery_executive_id', $executive->id)
                ->where('status', 'collected')
                ->whereIn('id', $collectionIds)
                ->get();

            abort_if($collections->isEmpty(), 422, __('modules.delivery.noEligibleCodCollections'));
            abort_if($collections->count() !== count($collectionIds), 422, __('modules.delivery.invalidSettlementSelection'));

            $alreadyLinkedCount = DB::table('delivery_cash_settlement_items')
                ->join('delivery_cash_settlements', 'delivery_cash_settlements.id', '=', 'delivery_cash_settlement_items.settlement_id')
                ->whereIn('order_cash_collection_id', $collectionIds)
                ->whereIn('delivery_cash_settlements.status', ['submitted', 'approved'])
                ->exists();

            abort_if($alreadyLinkedCount, 422, __('modules.delivery.duplicateSettlementSelection'));

            $selectedTotal = round((float) $collections->sum(fn (OrderCashCollection $collection) => (float) ($collection->collected_amount ?? 0)), 2);
            abort_if(round($submittedAmount, 2) !== $selectedTotal, 422, __('modules.delivery.settlementAmountMismatch'));

            $settlement = DeliveryCashSettlement::create([
                'branch_id' => $executive->branch_id,
                'delivery_executive_id' => $executive->id,
                'submitted_amount' => $selectedTotal,
                'status' => 'submitted',
                'notes' => $notes,
                'submitted_at' => now(),
            ]);

            $settlement->update([
                'settlement_number' => 'SET-' . now()->format('Ymd') . '-' . str_pad((string) $settlement->id, 4, '0', STR_PAD_LEFT),
            ]);

            foreach ($collections as $collection) {
                $settlement->items()->create([
                    'order_cash_collection_id' => $collection->id,
                    'order_id' => $collection->order_id,
                    'amount' => (float) ($collection->collected_amount ?? 0),
                ]);

                $collection->update([
                    'status' => 'submitted',
                    'submitted_at' => now(),
                ]);
            }

            return $settlement->fresh(['items.orderCashCollection', 'deliveryExecutive', 'branch.restaurant']);
        });

        $this->notifyAdmins($settlement, 'submitted');
        $this->notifyExecutive($settlement, 'submitted');

        return $settlement;
    }

    public function approve(DeliveryCashSettlement $settlement, ?int $approvedBy = null): DeliveryCashSettlement
    {
        $settlement = DB::transaction(function () use ($settlement, $approvedBy) {
            abort_if($settlement->status !== 'submitted', 422, __('messages.invalidRequest'));

            $settlement->loadMissing('items.orderCashCollection');

            foreach ($settlement->items as $item) {
                $item->orderCashCollection?->update([
                    'status' => 'settled',
                    'settled_at' => now(),
                    'settled_by' => $approvedBy,
                ]);
            }

            $settlement->update([
                'status' => 'approved',
                'verified_amount' => $settlement->submitted_amount,
                'approved_at' => now(),
                'approved_by' => $approvedBy,
            ]);

            return $settlement->fresh(['items.orderCashCollection', 'deliveryExecutive', 'approvedBy', 'branch.restaurant']);
        });

        $this->notifyAdmins($settlement, 'approved');
        $this->notifyExecutive($settlement, 'approved');

        return $settlement;
    }

    public function reject(DeliveryCashSettlement $settlement, ?int $approvedBy = null): DeliveryCashSettlement
    {
        $settlement = DB::transaction(function () use ($settlement, $approvedBy) {
            abort_if($settlement->status !== 'submitted', 422, __('messages.invalidRequest'));

            $settlement->loadMissing('items.orderCashCollection');

            foreach ($settlement->items as $item) {
                $item->orderCashCollection?->update([
                    'status' => 'collected',
                    'submitted_at' => null,
                ]);
            }

            $settlement->update([
                'status' => 'rejected',
                'approved_at' => now(),
                'approved_by' => $approvedBy,
            ]);

            return $settlement->fresh(['items.orderCashCollection', 'deliveryExecutive', 'approvedBy', 'branch.restaurant']);
        });

        $this->notifyAdmins($settlement, 'rejected');
        $this->notifyExecutive($settlement, 'rejected');

        return $settlement;
    }

    protected function notifyAdmins(DeliveryCashSettlement $settlement, string $eventType): void
    {
        $restaurantId = $settlement->branch?->restaurant_id;

        if (!$restaurantId) {
            return;
        }

        $admins = User::withoutGlobalScope(BranchScope::class)
            ->role('Admin_' . $restaurantId)
            ->where('restaurant_id', $restaurantId)
            ->where(function ($query) use ($settlement) {
                $query->whereNull('branch_id')
                    ->orWhere('branch_id', $settlement->branch_id);
            })
            ->get();

        if ($admins->isEmpty()) {
            return;
        }

        Notification::send($admins, new DeliveryCashSettlementNotification($settlement, $eventType));
    }

    protected function notifyExecutive(DeliveryCashSettlement $settlement, string $eventType): void
    {
        $executive = $settlement->deliveryExecutive;

        if (!$executive || empty($executive->email)) {
            return;
        }

        $executive->notify(new DeliveryCashSettlementNotification($settlement, $eventType));
    }
}
