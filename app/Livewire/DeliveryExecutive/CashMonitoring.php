<?php

namespace App\Livewire\DeliveryExecutive;

use App\Exports\GenericTableExport;
use App\Models\DeliveryCashSettlement;
use App\Models\OrderCashCollection;
use App\Services\DeliveryCashSettlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Jantinnerezo\LivewireAlert\LivewireAlert;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithoutUrlPagination;
use Maatwebsite\Excel\Facades\Excel;

class CashMonitoring extends Component
{
    use LivewireAlert;
    use WithPagination;
    use WithoutUrlPagination;

    public $search = '';
    public $status = '';
    public $activeTab = 'settlements';

    private array $pendingStatuses = ['pending_collection', 'collected'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function switchTab(string $tab): void
    {
        if (!in_array($tab, ['summary', 'orders', 'settlements'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->resetPage();
    }

    public function exportReport()
    {
        if (!in_array('Export Report', restaurant_modules())) {
            $this->dispatch('showUpgradeLicense');
            return null;
        }

        [$headings, $rows] = $this->exportPayload();

        return Excel::download(
            new GenericTableExport($rows, $headings),
            'cod-monitoring-' . $this->activeTab . '-' . now()->format('Y-m-d-H-i-s') . '.xlsx'
        );
    }

    public function approveSettlement(int $settlementId): void
    {
        if (!Schema::hasTable('delivery_cash_settlements')) {
            return;
        }

        $settlement = DeliveryCashSettlement::withoutGlobalScopes()->findOrFail($settlementId);
        app(DeliveryCashSettlementService::class)->approve($settlement, auth()->id());

        $this->alert('success', __('modules.delivery.settlementApprovedSuccess'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
        ]);
    }

    public function rejectSettlement(int $settlementId): void
    {
        if (!Schema::hasTable('delivery_cash_settlements')) {
            return;
        }

        $settlement = DeliveryCashSettlement::withoutGlobalScopes()->findOrFail($settlementId);
        app(DeliveryCashSettlementService::class)->reject($settlement, auth()->id());

        $this->alert('success', __('modules.delivery.settlementRejectedSuccess'), [
            'toast' => true,
            'position' => 'top-end',
            'showCancelButton' => false,
        ]);
    }

    public function render()
    {
        if (!Schema::hasTable('order_cash_collections')) {
            return view('livewire.delivery-executive.cash-monitoring', [
                'summary' => collect(),
                'collections' => collect(),
                'totals' => [
                    'due_collection_total' => 0,
                    'due_collection_orders' => 0,
                    'ready_settlement_total' => 0,
                    'ready_settlement_orders' => 0,
                    'submitted_settlement_total' => 0,
                    'submitted_settlement_orders' => 0,
                    'settled_total' => 0,
                    'settled_orders' => 0,
                    'collected_today_total' => 0,
                    'collected_today_orders' => 0,
                    'total_cod_amount' => 0,
                    'total_cod_orders' => 0,
                ],
                'tableMissing' => true,
            ]);
        }

        $branchId = branch()?->id;

        $summary = $this->summaryQuery($branchId)->get();

        $collections = $this->collectionsQuery($branchId)
            ->latest('id')
            ->paginate(10);

        $settlements = Schema::hasTable('delivery_cash_settlements')
            ? $this->settlementsQuery($branchId)->latest('id')->get()
            : collect();

        $allCollections = OrderCashCollection::withoutGlobalScopes()
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId));

        $totals = [
            'due_collection_total' => (float) (clone $allCollections)
                ->where('status', 'pending_collection')
                ->sum('expected_amount'),
            'due_collection_orders' => (int) (clone $allCollections)
                ->where('status', 'pending_collection')
                ->count(),
            'ready_settlement_total' => (float) (clone $allCollections)
                ->where('status', 'collected')
                ->sum('collected_amount'),
            'ready_settlement_orders' => (int) (clone $allCollections)
                ->where('status', 'collected')
                ->count(),
            'submitted_settlement_total' => (float) (clone $allCollections)
                ->where('status', 'submitted')
                ->sum('collected_amount'),
            'submitted_settlement_orders' => (int) (clone $allCollections)
                ->where('status', 'submitted')
                ->count(),
            'settled_total' => (float) (clone $allCollections)
                ->where('status', 'settled')
                ->sum('collected_amount'),
            'settled_orders' => (int) (clone $allCollections)
                ->where('status', 'settled')
                ->count(),
            'collected_today_total' => (float) (clone $allCollections)
                ->whereDate('recorded_at', now()->toDateString())
                ->sum('collected_amount'),
            'collected_today_orders' => (int) (clone $allCollections)
                ->whereDate('recorded_at', now()->toDateString())
                ->count(),
            'total_cod_amount' => (float) (clone $allCollections)
                ->sum('expected_amount'),
            'total_cod_orders' => (int) (clone $allCollections)
                ->count(),
        ];

        return view('livewire.delivery-executive.cash-monitoring', [
            'summary' => $summary,
            'collections' => $collections,
            'settlements' => $settlements,
            'totals' => $totals,
            'tableMissing' => false,
        ]);
    }

    private function collectionsQuery(?int $branchId): Builder
    {
        return OrderCashCollection::withoutGlobalScopes()
            ->with(['deliveryExecutive', 'order.customer'])
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($this->status !== '', fn (Builder $query) => $query->where('status', $this->status))
            ->when($this->search !== '', function (Builder $query) {
                $search = '%' . $this->search . '%';

                $query->where(function (Builder $nested) use ($search) {
                    $nested->whereHas('deliveryExecutive', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                        ->orWhereHas('order', fn (Builder $q) => $q->where('order_number', 'like', $search)->orWhere('formatted_order_number', 'like', $search))
                        ->orWhereHas('order.customer', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search));
                });
            });
    }

    private function summaryQuery(?int $branchId): Builder
    {
        return OrderCashCollection::withoutGlobalScopes()
            ->selectRaw('delivery_executive_id, COUNT(*) as cod_pending_orders')
            ->selectRaw('SUM(CASE WHEN status = "collected" AND collected_amount IS NOT NULL THEN collected_amount ELSE expected_amount END) as cod_pending_amount')
            ->with('deliveryExecutive')
            ->whereIn('status', $this->pendingStatuses)
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($this->search !== '', function (Builder $query) {
                $search = '%' . $this->search . '%';

                $query->where(function (Builder $nested) use ($search) {
                    $nested->whereHas('deliveryExecutive', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                        ->orWhereHas('order', fn (Builder $q) => $q->where('order_number', 'like', $search)->orWhere('formatted_order_number', 'like', $search))
                        ->orWhereHas('order.customer', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search));
                });
            })
            ->groupBy('delivery_executive_id')
            ->orderByDesc('cod_pending_amount');
    }

    private function settlementsQuery(?int $branchId): Builder
    {
        return DeliveryCashSettlement::withoutGlobalScopes()
            ->with(['deliveryExecutive', 'items.order', 'approvedBy'])
            ->when($branchId, fn (Builder $query) => $query->where('branch_id', $branchId))
            ->when($this->status !== '', function (Builder $query) {
                if ($this->status === 'settled') {
                    $query->where('status', 'approved');
                    return;
                }

                $query->where('status', $this->status);
            })
            ->when($this->search !== '', function (Builder $query) {
                $search = '%' . $this->search . '%';
                $query->where(function (Builder $nested) use ($search) {
                    $nested->where('settlement_number', 'like', $search)
                        ->orWhereHas('deliveryExecutive', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search));
                });
            });
    }

    private function exportPayload(): array
    {
        $branchId = branch()?->id;

        return match ($this->activeTab) {
            'orders' => [
                [
                    __('modules.order.orderNumber'),
                    __('modules.customer.customer'),
                    __('menu.deliveryExecutive'),
                    __('modules.delivery.dueAmount'),
                    __('modules.delivery.collectedAmount'),
                    __('app.status'),
                    __('app.dateTime'),
                ],
                $this->collectionsQuery($branchId)
                    ->latest('id')
                    ->get()
                    ->map(fn ($item) => [
                        $item->order?->show_formatted_order_number ?? '--',
                        $item->order?->customer?->name ?? '--',
                        $item->deliveryExecutive?->name ?? '--',
                        (float) $item->expected_amount,
                        (float) ($item->collected_amount ?? 0),
                        match ($item->status) {
                            'pending_collection' => __('modules.delivery.pendingCollection'),
                            'collected' => __('modules.delivery.collected'),
                            'submitted' => __('modules.delivery.submitted'),
                            'settled' => __('modules.delivery.settled'),
                            default => ucwords(str_replace('_', ' ', $item->status)),
                        },
                        optional($item->recorded_at ?? $item->updated_at)?->timezone(timezone())->displayDateTime() ?? '--',
                    ])->values(),
            ],
            'settlements' => [
                [
                    __('modules.delivery.settlementNumber'),
                    __('menu.deliveryExecutive'),
                    __('modules.delivery.orderCount'),
                    __('modules.delivery.submittedAmount'),
                    __('modules.delivery.submittedAt'),
                    __('app.note'),
                    __('app.status'),
                ],
                $this->settlementsQuery($branchId)
                    ->latest('id')
                    ->get()
                    ->map(fn ($item) => [
                        $item->settlement_number ?? '--',
                        $item->deliveryExecutive?->name ?? '--',
                        $item->items->count(),
                        (float) $item->submitted_amount,
                        $item->submitted_at?->timezone(timezone())->displayDateTime() ?? '--',
                        $item->notes ?: '--',
                        match ($item->status) {
                            'submitted' => __('modules.delivery.submitted'),
                            'approved' => __('modules.delivery.settled'),
                            'rejected' => __('modules.delivery.rejected'),
                            default => ucwords(str_replace('_', ' ', $item->status)),
                        },
                    ])->values(),
            ],
            default => [
                [
                    __('menu.deliveryExecutive'),
                    __('modules.delivery.totalPendingCod'),
                    __('modules.delivery.pendingCodOrders'),
                ],
                $this->summaryQuery($branchId)
                    ->get()
                    ->map(fn ($item) => [
                        $item->deliveryExecutive?->name ?? '--',
                        (float) ($item->cod_pending_amount ?? 0),
                        (int) ($item->cod_pending_orders ?? 0),
                    ])->values(),
            ],
        };
    }
}
