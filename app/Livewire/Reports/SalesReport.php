<?php

namespace App\Livewire\Reports;

use Carbon\Carbon;
use App\Models\Tax;
use App\Models\Order;
use Livewire\Component;
use Livewire\Attributes\On;
use App\Models\RestaurantCharge;
use App\Exports\SalesReportExport;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Models\PaymentGatewayCredential;
use App\Models\User;
use App\Scopes\BranchScope;
use App\Models\BranchOperationalShift;

class SalesReport extends Component
{
    public $dateRangeType = 'currentWeek';
    public $startDate;
    public $endDate;
    public $startTime = '00:00'; // Default start time
    public $endTime = '23:59';  // Default end time
    public $currencyId;
    /** @var string User id who created/handled the order (orders.added_by) */
    public $filterByHandler = '';
    /** @var string Assigned waiter user id (orders.waiter_id) */
    public $filterByWaiter = '';
    /** @var string Payment method filter (payments.payment_method) */
    public $filterByPaymentMethod = '';
    /** All branch/restaurant users (handler filter dropdown) */
    public $handlers = [];
    /** Users with Waiter role only (waiter filter dropdown) */
    public $waiters = [];
    public $selectedHandler = '';
    public $selectedWaiter = '';
    public $selectedPaymentMethod = '';
    public $showItemsModal = false;
    public $selectedDate = '';
    public $dateItems = [];
    public $filterShift = '';
    public $shifts = [];

    public function mount()
    {
        abort_unless(in_array('Report', restaurant_modules()), 403);
        abort_unless(user_can('Show Reports'), 403);

        // Centralize currency ID
        $this->currencyId = restaurant()->currency_id;

        // Load date range type from cookie
        $this->dateRangeType = request()->cookie('sales_report_date_range_type', 'currentWeek');
        $this->setDateRange();
        // All users (handler / added_by filter)
        $this->handlers = User::withoutGlobalScope(BranchScope::class)
            ->where(function ($q) {
                return $q->where('branch_id', branch()->id)
                    ->orWhereNull('branch_id');
            })
            ->where('restaurant_id', restaurant()->id)
            ->orderBy('name')
            ->get();

        // Waiter role only (assigned waiter / waiter_id filter)
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

        // Load operational shifts for the current branch
        // Will be filtered by current day of week in render() method when "today" is selected
        if (branch()) {
            $this->shifts = BranchOperationalShift::where('branch_id', branch()->id)
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->orderBy('start_time')
                ->get();
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
        $this->filterByPaymentMethod = '';
        $this->selectedHandler = '';
        $this->selectedWaiter = '';
        $this->selectedPaymentMethod = '';

        // Clear shift filter if not viewing today (shift filter only works for today)
        if ($this->dateRangeType !== 'today') {
            $this->filterShift = null;
        }
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


    private function prepareDateTimeData()
    {
        $timezone = timezone();
        $offset = Carbon::now($timezone)->format('P');
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        // Check if we're viewing "today" (startDate and endDate are the same and equal to today)
        $startDateObj = parseRestaurantDate($this->startDate, $dateFormat, $timezone);
        $endDateObj = parseRestaurantDate($this->endDate, $dateFormat, $timezone);
        $todayDateObj = Carbon::now($timezone);

        $isToday = ($this->startDate === $this->endDate) &&
                   ($startDateObj->toDateString() === $todayDateObj->toDateString());

        if ($isToday && branch()) {
            // Use business day boundaries for "today"
            $boundaries = getBusinessDayBoundaries(branch(), now());
            $startDateTime = $boundaries['start']->setTimezone('UTC')->toDateTimeString();
            $endDateTime = $boundaries['end']->setTimezone('UTC')->toDateTimeString();

            // Extract time from boundaries for shift filtering
            $startTime = $boundaries['start']->format('H:i');
            $endTime = $boundaries['end']->format('H:i');
        } else {
            // Normalize time values to ensure they're in H:i format
            $normalizedStartTime = $this->normalizeTime($this->startTime);
            $normalizedEndTime = $this->normalizeTime($this->endTime);

            // Use calendar day boundaries for other date ranges
            $startDateTime = parseRestaurantDateTime($this->startDate . ' ' . $normalizedStartTime, $dateFormat . ' H:i', $timezone)
                ->setTimezone('UTC')->toDateTimeString();

            $endDateTime = parseRestaurantDateTime($this->endDate . ' ' . $normalizedEndTime, $dateFormat . ' H:i', $timezone)
                ->setTimezone('UTC')->toDateTimeString();

            // Parse time values safely
            $startTime = Carbon::createFromFormat('H:i', $normalizedStartTime, $timezone)->setTimezone('UTC')->format('H:i');
            $endTime = Carbon::createFromFormat('H:i', $normalizedEndTime, $timezone)->setTimezone('UTC')->format('H:i');
        }

        return compact('timezone', 'offset', 'startDateTime', 'endDateTime', 'startTime', 'endTime', 'isToday');
    }

    public function exportReport()
    {
        if (!in_array('Export Report', restaurant_modules())) {
            $this->dispatch('showUpgradeLicense');
            return;
        }

        $dateTimeData = $this->prepareDateTimeData();

        // For display purposes, use business day end time if viewing "today"
        $displayEndDateTime = $dateTimeData['endDateTime'];
        $displayEndTime = $dateTimeData['endTime'];

        if ($dateTimeData['isToday'] && branch()) {
            $boundaries = getBusinessDayBoundaries(branch(), now());
            // Use business_day_end for display (shows full business day end, not "now")
            $displayEnd = isset($boundaries['business_day_end'])
                ? $boundaries['business_day_end']
                : $boundaries['end'];

            $displayEndDateTime = $displayEnd->setTimezone('UTC')->toDateTimeString();
            $displayEndTime = $displayEnd->format('H:i');
        }

        return Excel::download(
            new SalesReportExport(
                $dateTimeData['startDateTime'],
                $dateTimeData['endDateTime'], // Query end time (current time for "today")
                $dateTimeData['startTime'],
                $dateTimeData['endTime'], // Query end time
                $dateTimeData['timezone'],
                $dateTimeData['offset'],
                $this->filterByHandler ?: null,
                $this->filterByWaiter ?: null,
                $this->filterByPaymentMethod ?: null,
                $this->filterShift ?: null,
                $this->startDate,
                $this->endDate,
                $displayEndDateTime, // Display end time (full business day end for "today")
                $displayEndTime // Display end time
            ),
            'sales-report-' . now()->format('Y-m-d_His') . '.xlsx'
        );
    }

    public function updatedDateRangeType($value)
    {
        cookie()->queue(cookie('sales_report_date_range_type', $value, 60 * 24 * 30)); // 30 days
    }

    public function updatedStartTime($value)
    {
        // Normalize time format to H:i (e.g., "14:30")
        $this->startTime = $this->normalizeTime($value);
    }

    public function updatedEndTime($value)
    {
        // Normalize time format to H:i (e.g., "14:30")
        $this->endTime = $this->normalizeTime($value);
    }

    private function normalizeTime($time)
    {
        if (empty($time)) {
            return '00:00';
        }

        $time = trim($time);

        // Match H:i or h:i with optional AM/PM (e.g. "14:30", "2:30 PM", "02:30 am")
        if (preg_match('/^(\d{1,2}):(\d{2})\s*(AM|PM|am|pm)?$/i', $time, $matches)) {
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

        // Fallback to default if parsing fails
        return '00:00';
    }

    private function applyOrderUserFilters($query, $table = null)
    {
        $prefix = $table ? $table . '.' : '';

        if ($this->filterByHandler) {
            $query->where($prefix . 'added_by', $this->filterByHandler);
        }

        if ($this->filterByWaiter) {
            $query->where($prefix . 'waiter_id', $this->filterByWaiter);
        }

        return $query;
    }

    private function applyOrderPaymentMethodFilter($query, $table = null)
    {
        if (!$this->filterByPaymentMethod) {
            return $query;
        }

        // If the query already joins `payments`, we can filter directly on payments.payment_method.
        if ($table) {
            $query->where($table . '.payment_method', $this->filterByPaymentMethod);

            return $query;
        }

        // Otherwise, filter orders by checking existence of a payment with the selected method.
        $paymentMethod = $this->filterByPaymentMethod;
        $query->whereIn('id', function ($sub) use ($paymentMethod) {
            $sub->select('order_id')
                ->from('payments')
                ->where('payment_method', $paymentMethod);
        });

        return $query;
    }

    public function updatedFilterShift()
    {
        // Reset pagination or trigger re-render when shift filter changes
        $this->dispatch('$refresh');
    }

    public function filterHandler()
    {
        $this->filterByHandler = $this->selectedHandler;
    }

    public function filterWaiter()
    {
        $this->filterByWaiter = $this->selectedWaiter;
    }

    public function filterPaymentMethod()
    {
        $this->filterByPaymentMethod = $this->selectedPaymentMethod;
    }

    public function openItemsModal($date)
    {
        $this->selectedDate = $date;
        $this->loadDateItems($date);
        $this->showItemsModal = true;
    }

    public function closeItemsModal()
    {
        $this->showItemsModal = false;
        $this->selectedDate = '';
        $this->dateItems = [];
    }

    private function loadDateItems($date)
    {
        $timezone = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';
        $offset = Carbon::now($timezone)->format('P');

        // Normalize time values
        $normalizedStartTime = $this->normalizeTime($this->startTime);
        $normalizedEndTime = $this->normalizeTime($this->endTime);

        // Convert the date to the correct format for querying
        $dateCarbon = Carbon::createFromFormat('Y-m-d', $date, $timezone);
        $startDateTime = $dateCarbon->copy()->setTimeFromTimeString($normalizedStartTime)->setTimezone('UTC')->toDateTimeString();
        $endBase = $dateCarbon->copy();
        if ($normalizedStartTime > $normalizedEndTime) {
            $endBase->addDay();
        }
        $endDateTime = $endBase->setTimeFromTimeString($normalizedEndTime)->setTimezone('UTC')->toDateTimeString();

        $startTime = Carbon::createFromFormat('H:i', $normalizedStartTime, $timezone)->setTimezone('UTC')->format('H:i');
        $endTime = Carbon::createFromFormat('H:i', $normalizedEndTime, $timezone)->setTimezone('UTC')->format('H:i');

        $baseQuery = DB::table('order_items')
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
            ->whereRaw('DATE(CONVERT_TZ(orders.date_time, "+00:00", ?)) = ?', [$offset, $date])
            ->whereBetween('orders.date_time', [$startDateTime, $endDateTime])
            ->whereIn('orders.status', ['paid', 'payment_due'])
            ->where('orders.branch_id', branch()->id)
            ->where(function ($q) use ($startTime, $endTime) {
                if ($startTime < $endTime) {
                    $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$startTime, $endTime]);
                } else {
                    $q->where(function ($sub) use ($startTime, $endTime) {
                        $sub->whereRaw('TIME(orders.date_time) >= ?', [$startTime])
                            ->orWhereRaw('TIME(orders.date_time) <= ?', [$endTime]);
                    });
                }
            });

        if ($this->filterByHandler) {
            $baseQuery->where('orders.added_by', $this->filterByHandler);
        }
        if ($this->filterByWaiter) {
            $baseQuery->where('orders.waiter_id', $this->filterByWaiter);
        }
        if ($this->filterByPaymentMethod) {
            $paymentMethod = $this->filterByPaymentMethod;
            $baseQuery->whereIn('orders.id', function ($sub) use ($paymentMethod) {
                $sub->select('order_id')
                    ->from('payments')
                    ->where('payment_method', $paymentMethod);
            });
        }

        // Pull order-level taxes via the relation table and compute total tax per order
        $taxByOrder = DB::table('order_taxes')
            ->join('orders', 'order_taxes.order_id', '=', 'orders.id')
            ->join('taxes', 'order_taxes.tax_id', '=', 'taxes.id')
            ->whereBetween('orders.date_time', [$startDateTime, $endDateTime])
            ->whereIn('orders.status', ['paid', 'payment_due'])
            ->where('orders.branch_id', branch()->id)
            ->where(function ($q) use ($startTime, $endTime) {
                if ($startTime < $endTime) {
                    $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$startTime, $endTime]);
                } else {
                    $q->where(function ($sub) use ($startTime, $endTime) {
                        $sub->whereRaw('TIME(orders.date_time) >= ?', [$startTime])
                            ->orWhereRaw('TIME(orders.date_time) <= ?', [$endTime]);
                    });
                }
            })
            ->when($this->filterByHandler, function ($q) {
                $q->where('orders.added_by', $this->filterByHandler);
            })
            ->when($this->filterByWaiter, function ($q) {
                $q->where('orders.waiter_id', $this->filterByWaiter);
            })
            ->when($this->filterByPaymentMethod, function ($q) {
                $paymentMethod = $this->filterByPaymentMethod;
                $q->whereIn('orders.id', function ($sub) use ($paymentMethod) {
                    $sub->select('order_id')
                        ->from('payments')
                        ->where('payment_method', $paymentMethod);
                });
            })
            ->select(
                'orders.id as order_id',
                'orders.tax_base',
                'orders.sub_total',
                'orders.discount_amount',
                DB::raw('SUM((taxes.tax_percent / 100) * COALESCE(orders.tax_base, orders.sub_total - COALESCE(orders.discount_amount, 0))) as tax_total')
            )
            ->groupBy('orders.id', 'orders.tax_base', 'orders.sub_total', 'orders.discount_amount')
            ->pluck('tax_total', 'order_id');

        // Fetch items with order context to distribute tax using the order->taxes relation
        $items = $baseQuery->select(
            'menu_items.id as menu_item_id',
            'menu_items.item_name',
            'order_items.order_id',
            'order_items.quantity',
            'order_items.amount',
            'order_items.price',
            'orders.sub_total',
            'orders.discount_amount'
        )->get();

        $aggregated = [];

        foreach ($items as $item) {
            $key = $item->menu_item_id;

            if (!isset($aggregated[$key])) {
                $aggregated[$key] = [
                    'item_name' => $item->item_name,
                    'quantity' => 0,
                    'total_amount' => 0,
                    'tax_amount' => 0,
                ];
            }

            $aggregated[$key]['quantity'] += $item->quantity;
            $aggregated[$key]['total_amount'] += $item->amount;

            $orderTaxTotal = $taxByOrder[$item->order_id] ?? 0;
            $orderTaxBase = max(($item->sub_total - ($item->discount_amount ?? 0)), 0.0001);
            $itemShare = $orderTaxBase > 0 ? ($item->amount / $orderTaxBase) : 0;
            $aggregated[$key]['tax_amount'] += $orderTaxTotal * $itemShare;
        }

        // Finalize averages
        $this->dateItems = collect($aggregated)
            ->map(function ($item) {
                $avgPrice = $item['quantity'] > 0 ? ($item['total_amount'] / $item['quantity']) : 0;
                $totalWithTax = $item['total_amount'] + $item['tax_amount'];

                return [
                    'item_name' => $item['item_name'],
                    'quantity' => $item['quantity'],
                    'total_amount' => $item['total_amount'],
                    'avg_price' => $avgPrice,
                    'tax_amount' => $item['tax_amount'],
                    'total_with_tax' => $totalWithTax,
                ];
            })
            ->sortByDesc('total_with_tax')
            ->values()
            ->toArray();
    }

    private function applyShiftFilter($query, $dateTimeData)
    {
        // If a specific shift is selected, filter orders by shift times
        if (!empty($this->filterShift) && branch()) {
            $selectedShift = BranchOperationalShift::find($this->filterShift);
            if ($selectedShift) {
                $restaurantTimezone = branch()->restaurant->timezone ?? 'UTC';
                $dateFormat = restaurant()->date_format ?? 'd-m-Y';

                // Build query to match orders within shift times for each day in the date range
                $query->where(function($q) use ($selectedShift, $restaurantTimezone, $dateTimeData, $dateFormat) {
                    // Parse the start and end dates from UTC back to restaurant timezone to iterate
                    $startDate = parseRestaurantDate($this->startDate, $dateFormat, $dateTimeData['timezone']);
                    $endDate = parseRestaurantDate($this->endDate, $dateFormat, $dateTimeData['timezone']);

                    $currentDate = $startDate->copy();
                    $firstCondition = true;

                    while ($currentDate->lte($endDate)) {
                        $dayOfWeek = $currentDate->format('l');
                        $shiftDays = $selectedShift->day_of_week ?? [];

                        // Check if shift applies to this day
                        if (in_array('All', $shiftDays) || in_array($dayOfWeek, $shiftDays)) {
                            // Parse shift times in restaurant timezone for this date
                            $shiftStart = Carbon::parse(
                                $currentDate->toDateString() . ' ' . $selectedShift->start_time,
                                $restaurantTimezone
                            );

                            $shiftEnd = Carbon::parse(
                                $currentDate->toDateString() . ' ' . $selectedShift->end_time,
                                $restaurantTimezone
                            );

                            // Handle overnight shifts
                            if ($selectedShift->end_time < $selectedShift->start_time) {
                                $shiftEnd->addDay();
                            }

                            // Convert to UTC for database query
                            $shiftStartUTC = $shiftStart->setTimezone('UTC')->toDateTimeString();
                            $shiftEndUTC = $shiftEnd->setTimezone('UTC')->toDateTimeString();

                            // Use where for first condition, orWhere for subsequent ones
                            if ($firstCondition) {
                                $q->where(function($subQ) use ($shiftStartUTC, $shiftEndUTC) {
                                    $subQ->where('orders.date_time', '>=', $shiftStartUTC)
                                         ->where('orders.date_time', '<=', $shiftEndUTC);
                                });
                                $firstCondition = false;
                            } else {
                                $q->orWhere(function($subQ) use ($shiftStartUTC, $shiftEndUTC) {
                                    $subQ->where('orders.date_time', '>=', $shiftStartUTC)
                                         ->where('orders.date_time', '<=', $shiftEndUTC);
                                });
                            }
                        }

                        $currentDate->addDay();
                    }
                });
            }
        }

        return $query;
    }

    public function render()
    {
        $dateTimeData = $this->prepareDateTimeData();

        // Retrieve all taxes and charges
        $charges = RestaurantCharge::all();
        $taxes = Tax::all();
        $restaurant = restaurant();
        $taxMode = $restaurant->tax_mode ?? 'order';

        // Get sales report with charges grouped
        $query = Order::join('payments', 'orders.id', '=', 'payments.order_id')
            ->whereBetween('orders.date_time', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
            ->whereIn('orders.status', ['paid', 'payment_due']);

        // Apply time filtering only if not filtering by shift (shift filter handles time internally)
        if (empty($this->filterShift)) {
            $query->where(function ($q) use ($dateTimeData) {
                if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                    $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                }
                else
                 {
                    $q->where(function ($sub) use ($dateTimeData) {
                        $sub->whereRaw('TIME(orders.date_time) >= ?', [$dateTimeData['startTime']])
                            ->orWhereRaw('TIME(orders.date_time) <= ?', [$dateTimeData['endTime']]);
                    });
                }
            });
        }

        // Apply shift filter if selected
        $query = $this->applyShiftFilter($query, $dateTimeData);

        // Get outstanding payments data separately
        $outstandingQuery = Order::whereBetween('date_time', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
            ->where('status', 'payment_due');

        // Apply time filtering only if not filtering by shift
        if (empty($this->filterShift)) {
            $outstandingQuery->where(function ($q) use ($dateTimeData) {
                if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                    $q->whereRaw('TIME(date_time) BETWEEN ? AND ?', [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                }
                else
                 {
                    $q->where(function ($sub) use ($dateTimeData) {
                        $sub->whereRaw('TIME(date_time) >= ?', [$dateTimeData['startTime']])
                            ->orWhereRaw('TIME(date_time) <= ?', [$dateTimeData['endTime']]);
                    });
                }
            });
        }

        // Apply shift filter if selected
        $outstandingQuery = $this->applyShiftFilter($outstandingQuery, $dateTimeData);

        $this->applyOrderUserFilters($query, 'orders');
        $this->applyOrderUserFilters($outstandingQuery);
        $this->applyOrderPaymentMethodFilter($query, 'payments');
        $this->applyOrderPaymentMethodFilter($outstandingQuery, null);

        $query = $query->select(
            DB::raw('DATE(CONVERT_TZ(orders.date_time, "+00:00", "' . $dateTimeData['offset'] . '")) as date'),
            DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
            DB::raw('SUM(payments.amount) as total_amount'),
            DB::raw('SUM(CASE WHEN payments.payment_method = "cash" THEN payments.amount ELSE 0 END) as cash_amount'),
            DB::raw('SUM(CASE WHEN payments.payment_method = "card" THEN payments.amount ELSE 0 END) as card_amount'),
            DB::raw('SUM(CASE WHEN payments.payment_method = "upi" THEN payments.amount ELSE 0 END) as upi_amount'),
            DB::raw('SUM(CASE WHEN payments.payment_method = "bank_transfer" THEN payments.amount ELSE 0 END) as bank_transfer_amount'),
            DB::raw('SUM(CASE WHEN payments.payment_method = "razorpay" THEN payments.amount ELSE 0 END) as razorpay_amount'),
            DB::raw('SUM(CASE WHEN payments.payment_method = "stripe" THEN payments.amount ELSE 0 END) as stripe_amount'),
            DB::raw('SUM(CASE WHEN payments.payment_method = "flutterwave" THEN payments.amount ELSE 0 END) as flutterwave_amount'),
        )
        ->groupBy('date')
        ->orderBy('date')
        ->get();

        // Get outstanding payments data - calculate remaining amount after payments
        $outstandingData = $outstandingQuery->select(
            DB::raw('DATE(CONVERT_TZ(orders.date_time, "+00:00", "' . $dateTimeData['offset'] . '")) as date'),
            DB::raw('COUNT(DISTINCT orders.id) as outstanding_orders'),
            DB::raw('SUM(
                CASE
                    WHEN orders.split_type = "items" THEN
                        orders.total - COALESCE((
                            SELECT SUM(so.amount)
                            FROM split_orders so
                            WHERE so.order_id = orders.id
                            AND so.status = "paid"
                        ), 0)
                    ELSE
                        orders.total - COALESCE((
                            SELECT SUM(p.amount)
                            FROM payments p
                            WHERE p.order_id = orders.id
                            AND p.payment_method != "due"
                        ), 0)
                END
            ) as outstanding_amount')
        )
        ->groupBy('date')
        ->orderBy('date')
        ->get()
        ->keyBy('date');

        // Get due amount received data - sum of due_amount_received from payments
        $dueReceivedQuery = Order::join('payments', 'orders.id', '=', 'payments.order_id')
            ->whereBetween('orders.date_time', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
            ->whereIn('orders.status', ['paid', 'payment_due'])
            ->whereNotNull('payments.due_amount_received')
            ->where('payments.due_amount_received', '>', 0);

        // Apply time filtering only if not filtering by shift
        if (empty($this->filterShift)) {
            $dueReceivedQuery->where(function ($q) use ($dateTimeData) {
                if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                    $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                } else {
                    $q->where(function ($sub) use ($dateTimeData) {
                        $sub->whereRaw('TIME(orders.date_time) >= ?', [$dateTimeData['startTime']])
                            ->orWhereRaw('TIME(orders.date_time) <= ?', [$dateTimeData['endTime']]);
                    });
                }
            });
        }

        // Apply shift filter if selected
        $dueReceivedQuery = $this->applyShiftFilter($dueReceivedQuery, $dateTimeData);

        $this->applyOrderUserFilters($dueReceivedQuery, 'orders');
        $this->applyOrderPaymentMethodFilter($dueReceivedQuery, 'payments');

        $dueReceivedData = $dueReceivedQuery->select(
            DB::raw('DATE(CONVERT_TZ(orders.date_time, "+00:00", "' . $dateTimeData['offset'] . '")) as date'),
            DB::raw('SUM(payments.due_amount_received) as due_received_amount')
        )
        ->groupBy('date')
        ->orderBy('date')
        ->get()
        ->keyBy('date');

        // Get order-level data separately to avoid duplication
        $orderData = Order::whereBetween('date_time', [$dateTimeData['startDateTime'], $dateTimeData['endDateTime']])
            ->whereIn('status', ['paid', 'payment_due']);

        // Apply time filtering only if not filtering by shift
        if (empty($this->filterShift)) {
            $orderData->where(function ($q) use ($dateTimeData) {
                if ($dateTimeData['startTime'] < $dateTimeData['endTime']) {
                    $q->whereRaw('TIME(date_time) BETWEEN ? AND ?', [$dateTimeData['startTime'], $dateTimeData['endTime']]);
                }
                else
                 {
                    $q->where(function ($sub) use ($dateTimeData) {
                        $sub->whereRaw('TIME(date_time) >= ?', [$dateTimeData['startTime']])
                            ->orWhereRaw('TIME(date_time) <= ?', [$dateTimeData['endTime']]);
                    });
                }
            });
        }

        // Apply shift filter if selected
        $orderData = $this->applyShiftFilter($orderData, $dateTimeData);

        $this->applyOrderUserFilters($orderData);
        $this->applyOrderPaymentMethodFilter($orderData, null);

        $orderData = $orderData->select(
            DB::raw('DATE(CONVERT_TZ(date_time, "+00:00", "' . $dateTimeData['offset'] . '")) as date'),
            DB::raw('SUM(discount_amount) as discount_amount'),
            DB::raw('SUM(tip_amount) as tip_amount'),
            DB::raw('SUM(delivery_fee) as delivery_fee'),
        )
        ->groupBy('date')
        ->get()
        ->keyBy('date');


        // Process taxes and charges dynamically using actual tax breakdown data
        $groupedData = $query->map(function ($item) use ($charges, $taxes, $taxMode, $orderData, $outstandingData, $dueReceivedData, $dateTimeData) {
            // Get order-level data for this date
            $orderInfo = $orderData->get($item->date);
            $outstandingInfo = $outstandingData->get($item->date);
            $dueReceivedInfo = $dueReceivedData->get($item->date);

            // Normalize time values
            $normalizedStartTime = $this->normalizeTime($this->startTime);
            $normalizedEndTime = $this->normalizeTime($this->endTime);

            // Build per-day window matching loadDateItems (date + time range, TZ aware)
            $dateCarbon = Carbon::createFromFormat('Y-m-d', $item->date, $dateTimeData['timezone']);
            $startDateTime = $dateCarbon->copy()->setTimeFromTimeString($normalizedStartTime)->setTimezone('UTC')->toDateTimeString();
            $endBase = $dateCarbon->copy();
            if ($normalizedStartTime > $normalizedEndTime) {
                $endBase->addDay();
            }
            $endDateTime = $endBase->setTimeFromTimeString($normalizedEndTime)->setTimezone('UTC')->toDateTimeString();
            $startTime = Carbon::createFromFormat('H:i', $normalizedStartTime, $dateTimeData['timezone'])->setTimezone('UTC')->format('H:i');
            $endTime = Carbon::createFromFormat('H:i', $normalizedEndTime, $dateTimeData['timezone'])->setTimezone('UTC')->format('H:i');

            $chargeAmounts = [];
            foreach ($charges as $charge) {
                $chargeAmounts[$charge->charge_name] = DB::table('order_charges')
                    ->join('orders', 'order_charges.order_id', '=', 'orders.id')
                    ->join('restaurant_charges', 'order_charges.charge_id', '=', 'restaurant_charges.id')
                    ->where('order_charges.charge_id', $charge->id)
                    ->whereIn('orders.status', ['paid', 'payment_due'])
                    ->whereBetween('orders.date_time', [$startDateTime, $endDateTime])
                    ->where(function ($q) use ($startTime, $endTime) {
                        if ($startTime < $endTime) {
                            $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$startTime, $endTime]);
                        } else {
                            $q->where(function ($sub) use ($startTime, $endTime) {
                                $sub->whereRaw('TIME(orders.date_time) >= ?', [$startTime])
                                    ->orWhereRaw('TIME(orders.date_time) <= ?', [$endTime]);
                            });
                        }
                    })
                    ->where('orders.branch_id', branch()->id)
                    ->when($this->filterByHandler, function ($q) {
                        $q->where('orders.added_by', $this->filterByHandler);
                    })
                    ->when($this->filterByWaiter, function ($q) {
                        $q->where('orders.waiter_id', $this->filterByWaiter);
                    })
                    ->when($this->filterByPaymentMethod, function ($q) {
                        $paymentMethod = $this->filterByPaymentMethod;
                        $q->whereIn('orders.id', function ($sub) use ($paymentMethod) {
                            $sub->select('order_id')
                                ->from('payments')
                                ->where('payment_method', $paymentMethod);
                        });
                    })
                    ->sum(DB::raw('CASE WHEN restaurant_charges.charge_type = "percent"
                THEN (restaurant_charges.charge_value / 100) * orders.sub_total
                ELSE restaurant_charges.charge_value END')) ?? 0;
            }

            // Get tax breakdown from both item and order level taxes - flexible approach
            $taxAmounts = [];
            $totalTaxAmount = 0;
            $taxDetails = [];

            // Initialize tax amounts for all taxes
            foreach ($taxes as $tax) {
                $taxAmounts[$tax->tax_name] = 0;
                $taxDetails[$tax->tax_name] = [
                    'name' => $tax->tax_name,
                    'percent' => $tax->tax_percent,
                    'total_amount' => 0,
                    'items_count' => 0
                ];
            }

            // Use only the tax source matching this restaurant's configured tax mode,
            // instead of summing both (item-level and order-level are two different
            // representations of the same tax, not two separate charges).
            $itemTaxData = collect();
            $orderTaxData = collect();

            if ($taxMode === 'item') {
                // Item-level tax data
                $itemTaxData = DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
                    ->join('menu_item_tax', 'menu_items.id', '=', 'menu_item_tax.menu_item_id')
                    ->join('taxes', 'menu_item_tax.tax_id', '=', 'taxes.id')
                    ->where('orders.status', 'paid')
                    ->where('orders.branch_id', branch()->id)
                    ->whereBetween('orders.date_time', [$startDateTime, $endDateTime])
                    ->where(function ($q) use ($startTime, $endTime) {
                        if ($startTime < $endTime) {
                            $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$startTime, $endTime]);
                        } else {
                            $q->where(function ($sub) use ($startTime, $endTime) {
                                $sub->whereRaw('TIME(orders.date_time) >= ?', [$startTime])
                                    ->orWhereRaw('TIME(orders.date_time) <= ?', [$endTime]);
                            });
                        }
                    })
                    ->when($this->filterByHandler, function ($q) {
                        $q->where('orders.added_by', $this->filterByHandler);
                    })
                    ->when($this->filterByWaiter, function ($q) {
                        $q->where('orders.waiter_id', $this->filterByWaiter);
                    })
                    ->when($this->filterByPaymentMethod, function ($q) {
                        $paymentMethod = $this->filterByPaymentMethod;
                        $q->whereIn('orders.id', function ($sub) use ($paymentMethod) {
                            $sub->select('order_id')
                                ->from('payments')
                                ->where('payment_method', $paymentMethod);
                        });
                    })
                    ->select(
                        'taxes.tax_name',
                        'taxes.tax_percent',
                        'order_items.tax_amount',
                        'order_items.quantity',
                        'order_items.order_id',
                        'menu_items.id as menu_item_id'
                    )
                    ->get();

                // Process item-level taxes if found
                if ($itemTaxData->isNotEmpty()) {
                    // Group by order_id and menu_item_id to calculate tax properly per order item
                    $orderItemGroups = $itemTaxData->groupBy(['order_id', 'menu_item_id']);

                    foreach ($orderItemGroups as $orderId => $menuItems) {
                        foreach ($menuItems as $menuItemId => $itemTaxes) {
                            $totalTaxPercent = $itemTaxes->sum('tax_percent');
                            $orderItemTaxAmount = $itemTaxes->first()->tax_amount ?? 0;

                            foreach ($itemTaxes as $taxItem) {
                                $taxName = $taxItem->tax_name;
                                $taxPercent = $taxItem->tax_percent;

                                // Calculate proportional tax amount for this specific order item
                                $proportionalAmount = $totalTaxPercent > 0 ?
                                    ($orderItemTaxAmount * ($taxPercent / $totalTaxPercent)) : 0;

                                $taxAmounts[$taxName] += $proportionalAmount;
                                $taxDetails[$taxName]['total_amount'] += $proportionalAmount;
                                $taxDetails[$taxName]['items_count'] += $taxItem->quantity;
                            }
                        }
                    }
                }
            } else {
                // Order-level tax data
                $orderTaxData = DB::table('order_taxes')
                    ->join('orders', 'order_taxes.order_id', '=', 'orders.id')
                    ->join('taxes', 'order_taxes.tax_id', '=', 'taxes.id')
                    ->where('orders.status', 'paid')
                    ->where('orders.branch_id', branch()->id)
                    ->whereBetween('orders.date_time', [$startDateTime, $endDateTime])
                    ->where(function ($q) use ($startTime, $endTime) {
                        if ($startTime < $endTime) {
                            $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$startTime, $endTime]);
                        } else {
                            $q->where(function ($sub) use ($startTime, $endTime) {
                                $sub->whereRaw('TIME(orders.date_time) >= ?', [$startTime])
                                    ->orWhereRaw('TIME(orders.date_time) <= ?', [$endTime]);
                            });
                        }
                    })
                    ->when($this->filterByHandler, function ($q) {
                        $q->where('orders.added_by', $this->filterByHandler);
                    })
                    ->when($this->filterByWaiter, function ($q) {
                        $q->where('orders.waiter_id', $this->filterByWaiter);
                    })
                    ->when($this->filterByPaymentMethod, function ($q) {
                        $paymentMethod = $this->filterByPaymentMethod;
                        $q->whereIn('orders.id', function ($sub) use ($paymentMethod) {
                            $sub->select('order_id')
                                ->from('payments')
                                ->where('payment_method', $paymentMethod);
                        });
                    })
                    ->select(
                        'taxes.tax_name',
                        'taxes.tax_percent',
                        'orders.sub_total',
                        'orders.discount_amount',
                        'orders.tax_base',
                        'orders.id as order_id'
                    )
                    ->get();

                // Process order-level taxes if found
                if ($orderTaxData->isNotEmpty()) {
                    foreach ($orderTaxData as $orderTax) {
                        $taxName = $orderTax->tax_name;
                        $taxBase = $orderTax->tax_base ?? ($orderTax->sub_total - ($orderTax->discount_amount ?? 0));
                        $taxAmount = ($orderTax->tax_percent / 100) * $taxBase;

                        $taxAmounts[$taxName] += $taxAmount;
                        $taxDetails[$taxName]['total_amount'] += $taxAmount;
                        $taxDetails[$taxName]['items_count'] += 1;
                    }
                }
            }

            // If the tax-mode-appropriate source returned nothing, try fallback calculation
            if ($itemTaxData->isEmpty() && $orderTaxData->isEmpty()) {
                foreach ($taxes as $tax) {
                    // Try item-level calculation using direct tax amount from order_items
                    $itemTaxAmount = DB::table('order_items')
                        ->join('orders', 'order_items.order_id', '=', 'orders.id')
                        ->join('menu_item_tax', 'order_items.menu_item_id', '=', 'menu_item_tax.menu_item_id')
                        ->join('taxes', 'menu_item_tax.tax_id', '=', 'taxes.id')
                        ->where('taxes.id', $tax->id)
                        ->where('orders.status', 'paid')
                        ->where('orders.branch_id', branch()->id)
                        ->whereBetween('orders.date_time', [$startDateTime, $endDateTime])
                        ->where(function ($q) use ($startTime, $endTime) {
                            if ($startTime < $endTime) {
                                $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$startTime, $endTime]);
                            } else {
                                $q->where(function ($sub) use ($startTime, $endTime) {
                                    $sub->whereRaw('TIME(orders.date_time) >= ?', [$startTime])
                                        ->orWhereRaw('TIME(orders.date_time) <= ?', [$endTime]);
                                });
                            }
                        })
                        ->when($this->filterByHandler, function ($q) {
                            $q->where('orders.added_by', $this->filterByHandler);
                        })
                        ->when($this->filterByWaiter, function ($q) {
                            $q->where('orders.waiter_id', $this->filterByWaiter);
                        })
                        ->when($this->filterByPaymentMethod, function ($q) {
                            $paymentMethod = $this->filterByPaymentMethod;
                            $q->whereIn('orders.id', function ($sub) use ($paymentMethod) {
                                $sub->select('order_id')
                                    ->from('payments')
                                    ->where('payment_method', $paymentMethod);
                            });
                        })
                        ->sum(DB::raw('
                            CASE
                                WHEN (SELECT COUNT(*) FROM menu_item_tax WHERE menu_item_id = order_items.menu_item_id) > 1
                                THEN (order_items.tax_amount * (taxes.tax_percent /
                                    (SELECT SUM(t.tax_percent) FROM menu_item_tax mit
                                    JOIN taxes t ON mit.tax_id = t.id
                                    WHERE mit.menu_item_id = order_items.menu_item_id)
                                ))
                                ELSE COALESCE(order_items.tax_amount, 0)
                            END
                        ')) ?? 0;

                    $taxAmounts[$tax->tax_name] += $itemTaxAmount;
                    $taxDetails[$tax->tax_name]['total_amount'] += $itemTaxAmount;
                }
            }

            // Calculate total tax amount
            $totalTaxAmount = array_sum($taxAmounts);

            return [
                'date' => $item->date,
                'total_orders' => $item->total_orders,
                'total_amount' => $item->total_amount ?? 0,
                'total_excluding_tip' => ($item->total_amount ?? 0) - ($orderInfo->tip_amount ?? 0),
                'discount_amount' => $orderInfo->discount_amount ?? 0,
                'tip_amount' => $orderInfo->tip_amount ?? 0,
                'delivery_fee' => $orderInfo->delivery_fee ?? 0,
                'cash_amount' => $item->cash_amount ?? 0,
                'card_amount' => $item->card_amount ?? 0,
                'upi_amount' => $item->upi_amount ?? 0,
                'bank_transfer_amount' => $item->bank_transfer_amount ?? 0,
                'razorpay_amount' => $item->razorpay_amount ?? 0,
                'stripe_amount' => $item->stripe_amount ?? 0,
                'flutterwave_amount' => $item->flutterwave_amount ?? 0,
                'outstanding_orders' => $outstandingInfo->outstanding_orders ?? 0,
                'outstanding_amount' => $outstandingInfo->outstanding_amount ?? 0,
                'due_received_amount' => $dueReceivedInfo->due_received_amount ?? 0,
                'charges' => $chargeAmounts,
                'taxes' => $taxAmounts,
                'tax_details' => $taxDetails,
                'total_tax_amount' => $totalTaxAmount,
            ];
        });

        // Aggregate all taxes across all dates
        $allTaxes = [];
        foreach ($groupedData as $item) {
            if (isset($item['tax_details']) && is_array($item['tax_details'])) {
                foreach ($item['tax_details'] as $taxName => $taxDetail) {
                    if (!isset($allTaxes[$taxName])) {
                        $allTaxes[$taxName] = [
                            'name' => $taxName,
                            'percent' => $taxDetail['percent'] ?? 0,
                            'total_amount' => 0,
                            'items_count' => 0
                        ];
                    }
                    $allTaxes[$taxName]['total_amount'] += $taxDetail['total_amount'] ?? 0;
                    $allTaxes[$taxName]['items_count'] += $taxDetail['items_count'] ?? 0;
                }
            } elseif (isset($item['taxes']) && is_array($item['taxes'])) {
                // Fallback for older tax structure
                foreach ($item['taxes'] as $taxName => $taxAmount) {
                    if (!isset($allTaxes[$taxName])) {
                        // Find tax percentage from the taxes collection
                        $taxPercent = $taxes->where('tax_name', $taxName)->first()->tax_percent ?? 0;
                        $allTaxes[$taxName] = [
                            'name' => $taxName,
                            'percent' => $taxPercent,
                            'total_amount' => 0,
                            'items_count' => 1
                        ];
                    }
                    $allTaxes[$taxName]['total_amount'] += $taxAmount;
                }
            }
        }

        $paymentGateway = PaymentGatewayCredential::select('stripe_status', 'razorpay_status', 'flutterwave_status')
            ->where('restaurant_id', restaurant()->id)
            ->first();

        // Get business day info for "today" - only if today is selected
        $businessDayInfo = null;
        $displayStartTime = $this->startTime;
        $displayEndTime = $this->endTime;
        $filteredShifts = collect();

        if ($dateTimeData['isToday'] && branch()) {
            $boundaries = getBusinessDayBoundaries(branch(), now());
            $restaurantTimezone = branch()->restaurant->timezone ?? 'UTC';
            $timeFormat = restaurant()->time_format ?? 'h:i A';

            // Use business_day_end for display (shows full business day end, not "now")
            $displayEnd = isset($boundaries['business_day_end'])
                ? $boundaries['business_day_end']
                : $boundaries['end'];

            // Set display times for the heading
            $displayStartTime = $boundaries['start']->setTimezone($restaurantTimezone)->format('H:i');
            $displayEndTime = $displayEnd->setTimezone($restaurantTimezone)->format('H:i');

            $businessDayInfo = [
                'start' => $boundaries['start']->setTimezone($restaurantTimezone)->format($timeFormat),
                'end' => $displayEnd->setTimezone($restaurantTimezone)->format($timeFormat),
                'extends_to_next_day' => $displayEnd->toDateString() !== $boundaries['calendar_date'],
                'end_date' => $displayEnd->toDateString(),
            ];

            // Filter shifts to only show those that apply to today's day of week
            $tz = timezone();
            $currentDayOfWeek = Carbon::now($tz)->format('l'); // e.g., "Friday"
            $filteredShifts = collect($this->shifts)->filter(function($shift) use ($currentDayOfWeek) {
                // Ensure day_of_week is an array (handle both array and JSON string)
                $shiftDays = $shift->day_of_week ?? [];
                if (is_string($shiftDays)) {
                    $shiftDays = json_decode($shiftDays, true) ?? [];
                }
                if (!is_array($shiftDays)) {
                    $shiftDays = [];
                }

                // Include shift if it has 'All' days or includes the current day
                $applies = in_array('All', $shiftDays) || in_array($currentDayOfWeek, $shiftDays);
                return $applies;
            })->values(); // Re-index the collection to ensure clean array keys

            // If a shift is currently selected but it's not in the filtered list, clear it
            if (!empty($this->filterShift)) {
                $shiftIds = $filteredShifts->pluck('id')->toArray();
                if (!in_array($this->filterShift, $shiftIds)) {
                    $this->filterShift = null;
                }
            }
        } else {
            // Not viewing today - don't show shifts or business day info
            $filteredShifts = collect();
        }

        // Format display times for the heading
        $timeFormat = restaurant()->time_format ?? 'h:i A';
        $displayStartTimeFormatted = \Carbon\Carbon::createFromFormat('H:i', $displayStartTime)->format($timeFormat);
        $displayEndTimeFormatted = \Carbon\Carbon::createFromFormat('H:i', $displayEndTime)->format($timeFormat);

        // Check if business day extends to next day
        $extendsToNextDay = false;
        if ($businessDayInfo && isset($businessDayInfo['extends_to_next_day'])) {
            $extendsToNextDay = $businessDayInfo['extends_to_next_day'];
        }

        return view('livewire.reports.sales-report', [
            'menuItems' => $groupedData,
            'charges' => $charges,
            'taxes' => $taxes,
            'paymentGateway' => $paymentGateway,
            'taxMode' => $taxMode,
            'allTaxes' => $allTaxes,
            'currencyId' => $this->currencyId,
            'handlers' => $this->handlers,
            'waiters' => $this->waiters,
            'filteredShifts' => $filteredShifts, // Pass filtered shifts (only for today) - use different name to avoid conflict
            'isToday' => $dateTimeData['isToday'], // Pass flag to view
            'businessDayInfo' => $businessDayInfo,
            'startTime' => $displayStartTime,
            'endTime' => $displayEndTime,
            'displayStartTimeFormatted' => $displayStartTimeFormatted,
            'displayEndTimeFormatted' => $displayEndTimeFormatted,
            'extendsToNextDay' => $extendsToNextDay,
        ]);
    }

}

