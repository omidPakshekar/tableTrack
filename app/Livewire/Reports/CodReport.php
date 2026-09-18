<?php

namespace App\Livewire\Reports;

use App\Exports\GenericTableExport;
use App\Models\DeliveryCashSettlement;
use App\Models\DeliveryExecutive;
use App\Models\OrderCashCollection;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Livewire\WithoutUrlPagination;
use Maatwebsite\Excel\Facades\Excel;

class CodReport extends Component
{
    use WithPagination;
    use WithoutUrlPagination;

    public $activeTab = 'order-status';
    public $dateRangeType = 'currentMonth';
    public $startDate;
    public $endDate;
    public $search = '';
    public $status = '';
    public $deliveryExecutiveId = '';

    public function mount(): void
    {
        abort_if(!in_array('Report', restaurant_modules()), 403);
        abort_if(!user_can('Show Reports'), 403);

        $this->setDateRange();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingDeliveryExecutiveId(): void
    {
        $this->resetPage();
    }

    public function updatedDateRangeType(): void
    {
        $this->setDateRange();
        $this->resetPage();
    }

    public function switchTab(string $tab): void
    {
        if (!in_array($tab, ['order-status', 'collections', 'pending', 'settlements'], true)) {
            return;
        }

        $this->activeTab = $tab;
        $this->status = '';
        $this->resetPage();
    }

    public function setDateRange(): void
    {
        $tz = timezone();
        $ranges = [
            'today' => [Carbon::now($tz)->startOfDay(), Carbon::now($tz)->endOfDay()],
            'currentWeek' => [Carbon::now($tz)->startOfWeek(), Carbon::now($tz)->endOfWeek()],
            'lastWeek' => [Carbon::now($tz)->subWeek()->startOfWeek(), Carbon::now($tz)->subWeek()->endOfWeek()],
            'last7Days' => [Carbon::now($tz)->subDays(7)->startOfDay(), Carbon::now($tz)->endOfDay()],
            'currentMonth' => [Carbon::now($tz)->startOfMonth(), Carbon::now($tz)->endOfDay()],
            'lastMonth' => [Carbon::now($tz)->subMonth()->startOfMonth(), Carbon::now($tz)->subMonth()->endOfMonth()],
            'currentYear' => [Carbon::now($tz)->startOfYear(), Carbon::now($tz)->endOfDay()],
            'lastYear' => [Carbon::now($tz)->subYear()->startOfYear(), Carbon::now($tz)->subYear()->endOfYear()],
        ];

        [$start, $end] = $ranges[$this->dateRangeType] ?? $ranges['currentMonth'];
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        $this->startDate = $start->displayDate($dateFormat);
        $this->endDate = $end->displayDate($dateFormat);
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
            'cod-report-' . $this->activeTab . '-' . now()->format('Y-m-d-H-i-s') . '.xlsx'
        );
    }

    #[On('setStartDate')]
    public function setStartDate($start): void
    {
        $this->startDate = $start;
        $this->resetPage();
    }

    #[On('setEndDate')]
    public function setEndDate($end): void
    {
        $this->endDate = $end;
        $this->resetPage();
    }

    public function render()
    {
        if (!Schema::hasTable('order_cash_collections')) {
            return view('livewire.reports.cod-report', [
                'executives' => collect(),
                'summaryCards' => [
                    'total_orders' => 0,
                    'expected_amount' => 0,
                    'collected_amount' => 0,
                    'settled_amount' => 0,
                ],
                'tabData' => collect(),
                'tableMissing' => true,
            ]);
        }

        $branchId = branch()?->id;

        $executives = DeliveryExecutive::withoutGlobalScopes()
            ->when($branchId, fn (Builder $builder) => $builder->where('branch_id', $branchId))
            ->orderBy('name')
            ->get(['id', 'name']);
        $summaryCards = $this->buildSummaryCards();
        $tabData = $this->resolveTabData();

        return view('livewire.reports.cod-report', [
            'executives' => $executives,
            'summaryCards' => $summaryCards,
            'tabData' => $tabData,
            'tableMissing' => false,
        ]);
    }

    private function buildSummaryCards(): array
    {
        $query = $this->orderPaymentStatusBaseQuery();

        return [
            'total_orders' => (int) (clone $query)->count(),
            'expected_amount' => (float) (clone $query)->sum('expected_amount'),
            'collected_amount' => (float) (clone $query)->where('status', 'collected')->sum('collected_amount'),
            'settled_amount' => (float) (clone $query)->where('status', 'settled')->sum('collected_amount'),
        ];
    }

    private function resolveTabData(bool $forExport = false)
    {
        return match ($this->activeTab) {
            'collections' => $this->collectionSummaryData(),
            'pending' => $this->pendingCashData(),
            'settlements' => $this->settlementHistoryData($forExport),
            default => $this->orderPaymentStatusData($forExport),
        };
    }

    private function orderPaymentStatusData(bool $forExport = false)
    {
        $query = $this->orderPaymentStatusBaseQuery()
            ->with(['order.customer', 'deliveryExecutive'])
            ->latest('id');

        return $forExport ? $query->get() : $query->paginate(15);
    }

    private function orderPaymentStatusBaseQuery(): Builder
    {
        $branchId = branch()?->id;

        return OrderCashCollection::withoutGlobalScopes()
            ->when($branchId, fn (Builder $builder) => $builder->where('branch_id', $branchId))
            ->whereHas('order', function (Builder $builder) {
                $builder->whereBetween('date_time', $this->dateRangeUtc());
            })
            ->when($this->deliveryExecutiveId !== '', fn (Builder $builder) => $builder->where('delivery_executive_id', $this->deliveryExecutiveId))
            ->when($this->status !== '', fn (Builder $builder) => $builder->where('status', $this->status))
            ->when($this->search !== '', function (Builder $builder) {
                $search = '%' . $this->search . '%';
                $builder->where(function (Builder $nested) use ($search) {
                    $nested->whereHas('deliveryExecutive', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                        ->orWhereHas('order', fn (Builder $q) => $q->where('order_number', 'like', $search)->orWhere('formatted_order_number', 'like', $search))
                        ->orWhereHas('order.customer', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search));
                });
            });
    }

    private function collectionSummaryData()
    {
        return $this->collectionBaseQuery()
            ->selectRaw('delivery_executive_id')
            ->selectRaw('COUNT(*) as total_orders')
            ->selectRaw('SUM(expected_amount) as expected_amount')
            ->selectRaw('SUM(COALESCE(collected_amount, 0)) as collected_amount')
            ->with('deliveryExecutive')
            ->groupBy('delivery_executive_id')
            ->orderByDesc('collected_amount')
            ->get();
    }

    private function pendingCashData()
    {
        return $this->collectionBaseQuery()
            ->selectRaw('delivery_executive_id')
            ->selectRaw('SUM(CASE WHEN status = "pending_collection" THEN expected_amount ELSE 0 END) as due_to_collect')
            ->selectRaw('SUM(CASE WHEN status = "collected" THEN COALESCE(collected_amount, 0) ELSE 0 END) as ready_for_settlement')
            ->selectRaw('SUM(CASE WHEN status = "submitted" THEN COALESCE(collected_amount, 0) ELSE 0 END) as submitted_amount')
            ->selectRaw('COUNT(CASE WHEN status IN ("pending_collection", "collected", "submitted") THEN 1 END) as pending_orders')
            ->with('deliveryExecutive')
            ->whereIn('status', ['pending_collection', 'collected', 'submitted'])
            ->groupBy('delivery_executive_id')
            ->orderByDesc('ready_for_settlement')
            ->get();
    }

    private function collectionBaseQuery(): Builder
    {
        $branchId = branch()?->id;

        return OrderCashCollection::withoutGlobalScopes()
            ->when($branchId, fn (Builder $builder) => $builder->where('branch_id', $branchId))
            ->whereHas('order', function (Builder $builder) {
                $builder->whereBetween('date_time', $this->dateRangeUtc());
            })
            ->when($this->deliveryExecutiveId !== '', fn (Builder $builder) => $builder->where('delivery_executive_id', $this->deliveryExecutiveId))
            ->when($this->search !== '', function (Builder $builder) {
                $search = '%' . $this->search . '%';
                $builder->where(function (Builder $nested) use ($search) {
                    $nested->whereHas('deliveryExecutive', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search))
                        ->orWhereHas('order', fn (Builder $q) => $q->where('order_number', 'like', $search)->orWhere('formatted_order_number', 'like', $search))
                        ->orWhereHas('order.customer', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search));
                });
            });
    }

    private function settlementHistoryData(bool $forExport = false)
    {
        if (!Schema::hasTable('delivery_cash_settlements')) {
            return collect();
        }

        $branchId = branch()?->id;

        $query = DeliveryCashSettlement::withoutGlobalScopes()
            ->with(['deliveryExecutive', 'approvedBy', 'items'])
            ->when($branchId, fn (Builder $builder) => $builder->where('branch_id', $branchId))
            ->whereBetween('submitted_at', $this->dateRangeUtc())
            ->when($this->deliveryExecutiveId !== '', fn (Builder $builder) => $builder->where('delivery_executive_id', $this->deliveryExecutiveId))
            ->when($this->status !== '', function (Builder $builder) {
                if ($this->status === 'settled') {
                    $builder->where('status', 'approved');
                    return;
                }

                $builder->where('status', $this->status);
            })
            ->when($this->search !== '', function (Builder $builder) {
                $search = '%' . $this->search . '%';
                $builder->where(function (Builder $nested) use ($search) {
                    $nested->where('settlement_number', 'like', $search)
                        ->orWhereHas('deliveryExecutive', fn (Builder $q) => $q->where('name', 'like', $search)->orWhere('phone', 'like', $search));
                });
            })
            ->latest('id');

        return $forExport ? $query->get() : $query->paginate(15);
    }

    private function exportPayload(): array
    {
        $data = $this->resolveTabData(true);

        return match ($this->activeTab) {
            'collections' => [
                [
                    __('menu.deliveryExecutive'),
                    __('modules.report.ordersCount'),
                    __('modules.report.expectedCodAmount'),
                    __('modules.report.collectedCodAmount'),
                ],
                $data->map(fn ($item) => [
                    $item->deliveryExecutive?->name ?? '--',
                    (int) $item->total_orders,
                    (float) $item->expected_amount,
                    (float) $item->collected_amount,
                ])->values(),
            ],
            'pending' => [
                [
                    __('menu.deliveryExecutive'),
                    __('modules.delivery.dueToCollect'),
                    __('modules.delivery.readyForSettlement'),
                    __('modules.delivery.submittedForApproval'),
                    __('modules.report.ordersCount'),
                ],
                $data->map(fn ($item) => [
                    $item->deliveryExecutive?->name ?? '--',
                    (float) $item->due_to_collect,
                    (float) $item->ready_for_settlement,
                    (float) $item->submitted_amount,
                    (int) $item->pending_orders,
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
                $data->map(fn ($item) => [
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
                    __('modules.report.orderNumber'),
                    __('modules.report.orderDate'),
                    __('modules.customer.customer'),
                    __('menu.deliveryExecutive'),
                    __('modules.report.expectedCodAmount'),
                    __('modules.report.collectedCodAmount'),
                    __('app.status'),
                    __('modules.report.settlementStatus'),
                ],
                $data->map(fn ($item) => [
                    $item->order?->show_formatted_order_number ?? '--',
                    $item->order?->date_time?->timezone(timezone())->displayDateTime() ?? '--',
                    $item->order?->customer?->name ?? '--',
                    $item->deliveryExecutive?->name ?? '--',
                    (float) $item->expected_amount,
                    (float) ($item->collected_amount ?? 0),
                    __('modules.delivery.' . $item->status),
                    match ($item->status) {
                        'settled' => __('modules.delivery.settled'),
                        'submitted' => __('modules.delivery.submitted'),
                        'collected' => __('modules.delivery.readyForSettlement'),
                        default => __('modules.delivery.pendingCollection'),
                    },
                ])->values(),
            ],
        };
    }

    private function dateRangeUtc(): array
    {
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $timezone = timezone();

        $start = parseRestaurantDate($this->startDate, $dateFormat, $timezone)->startOfDay()->setTimezone('UTC');
        $end = parseRestaurantDate($this->endDate, $dateFormat, $timezone)->endOfDay()->setTimezone('UTC');

        return [$start->toDateTimeString(), $end->toDateTimeString()];
    }
}
