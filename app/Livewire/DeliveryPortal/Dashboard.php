<?php

namespace App\Livewire\DeliveryPortal;

use App\Models\DeliveryCashSettlement;
use App\Models\OrderCashCollection;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;

class Dashboard extends Component
{
    public $dueCollectionTotal = 0;
    public $dueCollectionOrders = 0;
    public $readySettlementTotal = 0;
    public $readySettlementOrders = 0;
    public $submittedSettlementTotal = 0;
    public $submittedSettlementOrders = 0;
    public $settledTotal = 0;
    public $settledOrders = 0;
    public $collectedTodayTotal = 0;
    public $collectedTodayOrders = 0;
    public $totalCodAmount = 0;
    public $totalCodOrders = 0;

    public function render()
    {
        $pendingCollections = collect();
        $recentSettlements = collect();

        $this->loadDashboardSummary();

        if (Schema::hasTable('order_cash_collections')) {
            $executive = delivery_executive();

            if ($executive) {
                $pendingCollections = OrderCashCollection::withoutGlobalScopes()
                    ->with('order.customer')
                    ->where('delivery_executive_id', $executive->id)
                    ->where('status', 'pending_collection')
                    ->latest('id')
                    ->limit(5)
                    ->get();

                if (Schema::hasTable('delivery_cash_settlements')) {
                    $recentSettlements = DeliveryCashSettlement::withoutGlobalScopes()
                        ->where('delivery_executive_id', $executive->id)
                        ->latest('id')
                        ->limit(5)
                        ->get();
                }
            }
        }

        return view('livewire.delivery-portal.dashboard', [
            'pendingCollections' => $pendingCollections,
            'recentSettlements' => $recentSettlements,
        ]);
    }

    private function loadDashboardSummary(): void
    {
        if (!Schema::hasTable('order_cash_collections')) {
            return;
        }

        $executive = delivery_executive();

        if (!$executive) {
            return;
        }

        $baseQuery = OrderCashCollection::withoutGlobalScopes()
            ->where('delivery_executive_id', $executive->id);

        $this->dueCollectionTotal = (float) (clone $baseQuery)
            ->where('status', 'pending_collection')
            ->sum('expected_amount');
        $this->dueCollectionOrders = (int) (clone $baseQuery)
            ->where('status', 'pending_collection')
            ->count();

        $this->readySettlementTotal = (float) (clone $baseQuery)
            ->where('status', 'collected')
            ->sum('collected_amount');
        $this->readySettlementOrders = (int) (clone $baseQuery)
            ->where('status', 'collected')
            ->count();

        $this->submittedSettlementTotal = (float) (clone $baseQuery)
            ->where('status', 'submitted')
            ->sum('collected_amount');
        $this->submittedSettlementOrders = (int) (clone $baseQuery)
            ->where('status', 'submitted')
            ->count();

        $this->settledTotal = (float) (clone $baseQuery)
            ->where('status', 'settled')
            ->sum('collected_amount');
        $this->settledOrders = (int) (clone $baseQuery)
            ->where('status', 'settled')
            ->count();

        $this->collectedTodayTotal = (float) (clone $baseQuery)
            ->whereDate('recorded_at', now()->toDateString())
            ->sum('collected_amount');
        $this->collectedTodayOrders = (int) (clone $baseQuery)
            ->whereDate('recorded_at', now()->toDateString())
            ->count();

        $this->totalCodAmount = (float) (clone $baseQuery)
            ->sum('expected_amount');
        $this->totalCodOrders = (int) (clone $baseQuery)
            ->count();
    }
}
