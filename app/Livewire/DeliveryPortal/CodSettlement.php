<?php

namespace App\Livewire\DeliveryPortal;

use App\Models\DeliveryCashSettlement;
use App\Models\OrderCashCollection;
use App\Services\DeliveryCashSettlementService;
use Illuminate\Support\Facades\Schema;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;

class CodSettlement extends Component
{
    use LivewireAlert;

    public $selectedSettlementCollections = [];
    public $settlementAmount = '';
    public $settlementNotes = '';

    public function getSelectedSettlementTotalProperty(): float
    {
        $executive = delivery_executive();

        if (!$executive || empty($this->selectedSettlementCollections)) {
            return 0.0;
        }

        return round((float) OrderCashCollection::withoutGlobalScopes()
            ->where('delivery_executive_id', $executive->id)
            ->where('status', 'collected')
            ->whereIn('id', $this->selectedSettlementCollections)
            ->sum('collected_amount'), 2);
    }

    public function render()
    {
        $pendingSettlementCollections = collect();
        $settlementHistory = collect();

        if (Schema::hasTable('order_cash_collections')) {
            $executive = delivery_executive();

            if ($executive) {
                $pendingSettlementCollections = OrderCashCollection::withoutGlobalScopes()
                    ->with('order.customer')
                    ->where('delivery_executive_id', $executive->id)
                    ->where('status', 'collected')
                    ->latest('recorded_at')
                    ->get();

                if (Schema::hasTable('delivery_cash_settlements')) {
                    $settlementHistory = DeliveryCashSettlement::withoutGlobalScopes()
                        ->with('items.order')
                        ->where('delivery_executive_id', $executive->id)
                        ->latest('id')
                        ->limit(10)
                        ->get();
                }
            }
        }

        return view('livewire.delivery-portal.cod-settlement', [
            'pendingSettlementCollections' => $pendingSettlementCollections,
            'settlementHistory' => $settlementHistory,
        ]);
    }

    public function submitSettlement(): void
    {
        if (!Schema::hasTable('delivery_cash_settlements') || !Schema::hasTable('delivery_cash_settlement_items')) {
            $this->alert('error', __('modules.delivery.settlementMigrationMessage'));
            return;
        }

        $this->validate([
            'selectedSettlementCollections' => 'required|array|min:1',
            'selectedSettlementCollections.*' => 'integer',
            'settlementAmount' => 'required|numeric|min:0.01',
            'settlementNotes' => 'nullable|string|max:1000',
        ]);

        $executive = delivery_executive();

        if (!$executive) {
            $this->alert('error', __('messages.invalidRequest'));
            return;
        }

        $selectedTotal = $this->selectedSettlementTotal;
        $enteredAmount = round((float) $this->settlementAmount, 2);
        $validationMessage = $this->settlementAmountValidationMessage($enteredAmount, $selectedTotal);

        if ($validationMessage !== null) {
            $this->addError('settlementAmount', $validationMessage);
            $this->alert('error', $validationMessage);
            return;
        }

        app(DeliveryCashSettlementService::class)->submitForExecutive(
            $executive,
            $this->selectedSettlementCollections,
            $enteredAmount,
            trim((string) $this->settlementNotes) ?: null
        );

        $this->reset(['selectedSettlementCollections', 'settlementAmount', 'settlementNotes']);

        $this->alert('success', __('modules.delivery.settlementSubmittedSuccess'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
        ]);
    }

    private function settlementAmountValidationMessage(float $enteredAmount, float $selectedTotal): ?string
    {
        if ($enteredAmount <= 0) {
            return __('modules.delivery.settlementAmountMustBePositive');
        }

        if ($enteredAmount > $selectedTotal) {
            return __('modules.delivery.settlementAmountCannotExceedSelectedTotal');
        }

        if ($enteredAmount !== $selectedTotal) {
            return __('modules.delivery.settlementAmountMismatch');
        }

        return null;
    }
}
