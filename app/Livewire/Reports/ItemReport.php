<?php

namespace App\Livewire\Reports;

use Carbon\Carbon;
use Livewire\Component;
use App\Models\MenuItem;
use App\Models\User;
use Livewire\Attributes\On;
use App\Exports\ItemReportExport;
use Livewire\Attributes\Computed;
use Maatwebsite\Excel\Facades\Excel;
use App\Scopes\AvailableMenuItemScope;
use App\Scopes\BranchScope;

class ItemReport extends Component
{

    public $dateRangeType;
    public $startDate;
    public $endDate;
    public $startTime = '00:00'; // Default start time
    public $endTime = '23:59';  // Default end time
    public $searchTerm;
    public $sortBy = 'quantity_sold';
    public $sortDirection = 'desc';
    /** @var string User id who created/handled the order (orders.added_by) */
    public $filterByHandler = '';
    /** @var string Assigned waiter user id (orders.waiter_id) */
    public $filterByWaiter = '';
    /** All branch/restaurant users (handler filter dropdown) */
    public $handlers = [];
    /** Users with Waiter role only (waiter filter dropdown) */
    public $waiters = [];
    public $selectedHandler = '';
    public $selectedWaiter = '';

    public function mount()
    {
        abort_if(!in_array('Report', restaurant_modules()), 403);
        abort_if((!user_can('Show Reports')), 403);

        $tz = timezone();

        // Load date range type from cookie
        $this->dateRangeType = request()->cookie('item_report_date_range_type', 'currentWeek');
        $this->setDateRange();

        $this->handlers = User::withoutGlobalScope(BranchScope::class)
            ->where(function ($q) {
                return $q->where('branch_id', branch()->id)
                    ->orWhereNull('branch_id');
            })
            ->where('restaurant_id', restaurant()->id)
            ->orderBy('name')
            ->get();

        $this->waiters = User::withoutGlobalScope(BranchScope::class)
            ->role('Waiter_' . restaurant()->id)
            ->where(function ($q) {
                return $q->where('branch_id', branch()->id)
                    ->orWhereNull('branch_id');
            })
            ->where('restaurant_id', restaurant()->id)
            ->orderBy('name')
            ->get();

        $this->selectedHandler = '';
        $this->selectedWaiter = '';
    }

    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('item_report_date_range_type', $value, 60 * 24 * 30)); // 30 days
    }

    public function sortByToggle($field)
    {
        if ($this->sortBy === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortBy = $field;
            $this->sortDirection = 'desc';
        }
    }

    public function setDateRange()
    {
        $tz = timezone();

        $ranges = [
            'today' => [Carbon::now($tz)->startOfDay(), Carbon::now($tz)->startOfDay()],
            'lastWeek' => [Carbon::now($tz)->subWeek()->startOfWeek(), Carbon::now($tz)->subWeek()->endOfWeek()],
            'last7Days' => [Carbon::now($tz)->subDays(7), Carbon::now($tz)->startOfDay()],
            'currentMonth' => [Carbon::now($tz)->startOfMonth(), Carbon::now($tz)->startOfDay()],
            'lastMonth' => [Carbon::now($tz)->subMonth()->startOfMonth(), Carbon::now($tz)->subMonth()->endOfMonth()],
            'currentYear' => [Carbon::now($tz)->startOfYear(), Carbon::now($tz)->startOfDay()],
            'lastYear' => [Carbon::now($tz)->subYear()->startOfYear(), Carbon::now($tz)->subYear()->endOfYear()],
            'currentWeek' => [Carbon::now($tz)->startOfWeek(), Carbon::now($tz)->endOfWeek()],
        ];

        [$start, $end] = $ranges[$this->dateRangeType] ?? $ranges['currentWeek'];
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $this->startDate = $start->displayDate($dateFormat);
        $this->endDate = $end->displayDate($dateFormat);
        $this->filterByHandler = '';
        $this->filterByWaiter = '';
        $this->selectedHandler = '';
        $this->selectedWaiter = '';
    }

    #[On('setStartDate')]

    public function setStartDate($start)
    {
        $this->startDate = $start;
    }

    #[On('setEndDate')]
    public function setEndDate($end)
    {
        $this->endDate = $end;
    }

    public function filterHandler()
    {
        $this->filterByHandler = $this->selectedHandler;
    }

    public function filterWaiter()
    {
        $this->filterByWaiter = $this->selectedWaiter;
    }

    public function updatedStartTime($value)
    {
        $this->startTime = $this->normalizeTime($value);
    }

    public function updatedEndTime($value)
    {
        $this->endTime = $this->normalizeTime($value);
    }

    private function normalizeTime($time)
    {
        if (empty($time)) {
            return '00:00';
        }

        // Match H:i or h:i with optional AM/PM (e.g. "14:30", "2:30 PM", "02:30 am")
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM|am|pm)?$/i', trim($time), $matches)) {
            $hours = (int) $matches[1];
            $minutes = (int) $matches[2];
            $ampm = $matches[3] ?? null;

            if ($ampm !== null && $ampm !== '') {
                $ampmUpper = strtoupper($ampm);
                if ($ampmUpper === 'PM' && $hours !== 12) {
                    $hours += 12;
                }
                if ($ampmUpper === 'AM' && $hours === 12) {
                    $hours = 0;
                }
            }

            $hours = min(23, max(0, $hours));
            $minutes = min(59, max(0, $minutes));

            return sprintf('%02d:%02d', $hours, $minutes);
        }

        return '00:00';
    }

    public function exportReport()
    {
        if (!in_array('Export Report', restaurant_modules())) {
            $this->dispatch('showUpgradeLicense');
        } else {
            $data = $this->prepareDateTimeData();

            return Excel::download(
                new ItemReportExport($data['startDateTime'], $data['endDateTime'], $data['startTime'], $data['endTime'], $data['timezone'], $this->searchTerm, $this->filterByHandler, $this->filterByWaiter),
                'item-report-' . now()->toDateTimeString() . '.xlsx'
            );
        }
    }

    private function prepareDateTimeData()
    {
        $timezone = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $normalizedStartTime = $this->normalizeTime($this->startTime);
        $normalizedEndTime = $this->normalizeTime($this->endTime);

        $startDateTime = parseRestaurantDateTime($this->startDate . ' ' . $normalizedStartTime, $dateFormat . ' H:i', $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $endDateTime = parseRestaurantDateTime($this->endDate . ' ' . $normalizedEndTime, $dateFormat . ' H:i', $timezone)
            ->setTimezone('UTC')->toDateTimeString();

        $startTime = Carbon::createFromFormat('H:i', $normalizedStartTime, $timezone)->setTimezone('UTC')->format('H:i');
        $endTime = Carbon::createFromFormat('H:i', $normalizedEndTime, $timezone)->setTimezone('UTC')->format('H:i');

        return compact('timezone', 'startDateTime', 'endDateTime', 'startTime', 'endTime');
    }

    private function prepareTodayDateTimeData(): array
    {
        $boundaries = getBusinessDayBoundaries(branch(), now());

        return [
            'timezone' => $boundaries['timezone'],
            'startDateTime' => $boundaries['start']->copy()->setTimezone('UTC')->toDateTimeString(),
            'endDateTime' => $boundaries['end']->copy()->setTimezone('UTC')->toDateTimeString(),
            'skipTimeFilter' => true,
        ];
    }

    private function prepareLast30DaysDateTimeData(): array
    {
        $timezone = timezone();

        return [
            'timezone' => $timezone,
            'startDateTime' => Carbon::now($timezone)->subDays(30)->startOfDay()->setTimezone('UTC')->toDateTimeString(),
            'endDateTime' => Carbon::now($timezone)->endOfDay()->setTimezone('UTC')->toDateTimeString(),
            'skipTimeFilter' => true,
        ];
    }

    private function buildMenuItemsCollection(array $dateTimeData, bool $applyFilters = true)
    {
        $ordersFilter = function ($q) use ($dateTimeData, $applyFilters) {
            return $q->join('orders', 'orders.id', '=', 'order_items.order_id')
                ->whereBetween('orders.date_time', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
                ->where('orders.status', 'paid')
                ->when(empty($dateTimeData['skipTimeFilter']), function ($q) use ($dateTimeData) {
                    $q->where(function ($q) use ($dateTimeData) {
                        if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                            $q->whereRaw("TIME(orders.date_time) BETWEEN ? AND ?", [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                        } else {
                            $q->where(function ($sub) use ($dateTimeData) {
                                $sub->whereRaw("TIME(orders.date_time) >= ?", [$dateTimeData['startTime']])
                                    ->orWhereRaw("TIME(orders.date_time) <= ?", [$dateTimeData['endTime']]);
                            });
                        }
                    });
                })
                ->when($applyFilters && $this->filterByHandler, function ($q) {
                    $q->where('orders.added_by', $this->filterByHandler);
                })
                ->when($applyFilters && $this->filterByWaiter, function ($q) {
                    $q->where('orders.waiter_id', $this->filterByWaiter);
                });
        };

        $query = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)
            ->whereHas('orders', $ordersFilter)
            ->with(['orders' => $ordersFilter, 'category', 'variations'])
            ->withCount('variations');

        if ($applyFilters && $this->searchTerm) {
            $query->where(function ($q) {
                $q->where('item_name', 'like', '%' . $this->searchTerm . '%')
                    ->orWhereHas('category', function ($q) {
                        $q->where('category_name', 'like', '%' . $this->searchTerm . '%');
                    });
            });
        }

        return $query->get()
            ->map(function ($item) {
                if ($item->variations_count > 0) {
                    $item->variations->each(function ($variation) use ($item) {
                        $variation->quantity_sold = $item->orders
                            ->where('menu_item_variation_id', $variation->id)
                            ->sum('quantity') ?? 0;
                        $variation->total_revenue = $variation->price * $variation->quantity_sold;
                    });

                    $item->setRelation('variations', $item->variations->filter(function ($v) {
                        return (int) ($v->quantity_sold ?? 0) > 0;
                    })->values());

                    $item->quantity_sold = $item->variations->sum('quantity_sold');
                    $item->total_revenue = $item->variations->sum('total_revenue');
                } else {
                    $quantitySold = (int) ($item->orders->sum('quantity') ?? 0);
                    $totalRevenue = $item->price * $quantitySold;

                    $item->quantity_sold = $quantitySold;
                    $item->total_revenue = $totalRevenue;
                }

                return $item;
            })
            ->filter(function ($item) {
                return (int) ($item->quantity_sold ?? 0) > 0;
            })
            ->values();
    }

    private function resolveTopSellingItem($menuItems): ?array
    {
        $top = null;
        $maxQuantity = 0;

        foreach ($menuItems as $item) {
            if ($item->variations_count > 0 && $item->variations->isNotEmpty()) {
                foreach ($item->variations as $variation) {
                    $quantity = (int) ($variation->quantity_sold ?? 0);
                    if ($quantity > $maxQuantity) {
                        $maxQuantity = $quantity;
                        $top = [
                            'name' => $item->item_name . ' (' . $variation->variation . ')',
                            'quantity_sold' => $quantity,
                        ];
                    }
                }
            } else {
                $quantity = (int) ($item->quantity_sold ?? 0);
                if ($quantity > $maxQuantity) {
                    $maxQuantity = $quantity;
                    $top = [
                        'name' => $item->item_name,
                        'quantity_sold' => $quantity,
                    ];
                }
            }
        }

        return $top;
    }


    #[Computed]
    public function menuItems()
    {
        $menuItems = $this->buildMenuItemsCollection($this->prepareDateTimeData());

        // Sort by the selected field
        switch ($this->sortBy) {
            case 'item_name':
            case 'price':
                return $this->sortDirection === 'asc'
                    ? $menuItems->sortBy($this->sortBy)
                    : $menuItems->sortByDesc($this->sortBy);

            case 'category_name':
                return $this->sortDirection === 'asc'
                    ? $menuItems->sortBy('category.category_name')
                    : $menuItems->sortByDesc('category.category_name');

            case 'quantity_sold':
                return $this->sortDirection === 'asc'
                    ? $menuItems->sortBy('quantity_sold')
                    : $menuItems->sortByDesc('quantity_sold');

            case 'total_revenue':
            default:
                return $this->sortDirection === 'asc'
                    ? $menuItems->sortBy('total_revenue')
                    : $menuItems->sortByDesc('total_revenue');
        }
    }

    #[Computed]
    public function totalQuantitySold()
    {
        return $this->menuItems->sum('quantity_sold');
    }

    #[Computed]
    public function totalRevenue()
    {
        return $this->menuItems->sum('total_revenue');
    }

    #[Computed]
    public function topSellingItemToday()
    {
        $menuItems = $this->buildMenuItemsCollection($this->prepareTodayDateTimeData(), false);

        return $this->resolveTopSellingItem($menuItems);
    }

    #[Computed]
    public function topSellingItemLast30Days()
    {
        $menuItems = $this->buildMenuItemsCollection($this->prepareLast30DaysDateTimeData(), false);

        return $this->resolveTopSellingItem($menuItems);
    }

    public function render()
    {
        return view('livewire.reports.item-report');
    }

}
