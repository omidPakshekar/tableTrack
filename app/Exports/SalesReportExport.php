<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\Tax;
use App\Models\User;
use App\Models\Order;
use App\Models\RestaurantCharge;
use App\Models\BranchOperationalShift;
use App\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Style\{Fill, Style};
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\{FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles};

class SalesReportExport implements WithMapping, FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected string $startDateTime, $endDateTime;
    protected string $startTime, $endTime, $timezone, $offset;
    protected array $charges, $taxes;
    protected $headingDateTime, $headingEndDateTime, $headingStartTime, $headingEndTime;
    protected $currencyId;
    protected ?string $handlerId = null;
    protected ?string $waiterId = null;
    protected ?string $paymentMethod = null;
    protected ?string $shiftId;
    protected string $startDate;
    protected string $endDate;
    protected ?string $displayEndDateTime;
    protected ?string $displayEndTime;

    public function __construct(string $startDateTime, string $endDateTime, string $startTime, string $endTime, string $timezone, string $offset, ?string $handlerId = null, ?string $waiterId = null, ?string $paymentMethod = null, ?string $shiftId = null, string $startDate = '', string $endDate = '', ?string $displayEndDateTime = null, ?string $displayEndTime = null)
    {
        $this->startDateTime = $startDateTime;
        $this->endDateTime = $endDateTime;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->timezone = $timezone;
        $this->offset = $offset;
        $this->currencyId = restaurant()->currency_id;
        $this->handlerId = $handlerId;
        $this->waiterId = $waiterId;
        $this->paymentMethod = $paymentMethod;
        $this->shiftId = $shiftId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->displayEndDateTime = $displayEndDateTime;
        $this->displayEndTime = $displayEndTime;

        // Use display end date/time for heading if provided, otherwise use query end date/time
        $headingEndDateTime = $displayEndDateTime ?? $endDateTime;
        $headingEndTime = $displayEndTime ?? $endTime;

        $this->headingDateTime = Carbon::parse($startDateTime)->setTimezone($timezone)->displayDate();
        $this->headingEndDateTime = Carbon::parse($headingEndDateTime)->setTimezone($timezone)->displayDate();
        $this->headingStartTime = Carbon::parse($startTime)->setTimezone($timezone)->format(timeFormat());
        $this->headingEndTime = Carbon::parse($headingEndTime)->setTimezone($timezone)->format(timeFormat());

        $this->charges = RestaurantCharge::pluck('charge_name')->toArray();
        $this->taxes = Tax::select('tax_name', 'tax_percent')->get()->toArray();
    }

    /**
     * Build a per-day UTC window and time bounds honoring start/end times (supports overnight ranges).
     */
    private function buildDateWindow(string $date): array
    {
        // Recover the local (restaurant-timezone) start/end time-of-day from the
        // overall report range, then rebuild the UTC datetime boundaries for this
        // specific date only (mirrors the per-day window used by the web report).
        $normalizedStartTime = Carbon::parse($this->startDateTime, 'UTC')->setTimezone($this->timezone)->format('H:i');
        $normalizedEndTime = Carbon::parse($this->endDateTime, 'UTC')->setTimezone($this->timezone)->format('H:i');

        $dateCarbon = Carbon::createFromFormat('Y-m-d', $date, $this->timezone);
        $startDateTime = $dateCarbon->copy()->setTimeFromTimeString($normalizedStartTime)->setTimezone('UTC')->toDateTimeString();

        $endBase = $dateCarbon->copy();
        if ($normalizedStartTime > $normalizedEndTime) {
            $endBase->addDay();
        }
        $endDateTime = $endBase->setTimeFromTimeString($normalizedEndTime)->setTimezone('UTC')->toDateTimeString();

        $startTime = $this->startTime;
        $endTime = $this->endTime;

        return compact('startDateTime', 'endDateTime', 'startTime', 'endTime');
    }

    /** @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query */
    private function applyJoinedOrderUserFilters($query)
    {
        return $query
            ->when($this->handlerId, function ($q) {
                $q->where('orders.added_by', $this->handlerId);
            })
            ->when($this->waiterId, function ($q) {
                $q->where('orders.waiter_id', $this->waiterId);
            });
    }

    /** @param \Illuminate\Database\Eloquent\Builder|\Illuminate\Database\Query\Builder $query */
    private function applyBareOrderUserFilters($query)
    {
        return $query
            ->when($this->handlerId, function ($q) {
                $q->where('added_by', $this->handlerId);
            })
            ->when($this->waiterId, function ($q) {
                $q->where('waiter_id', $this->waiterId);
            });
    }

    public function headings(): array
    {
        $taxHeadings = array_map(function($tax) {
            return "{$tax['tax_name']} ({$tax['tax_percent']}%)";
        }, $this->taxes);

        $headingTitle = $this->headingDateTime === $this->headingEndDateTime
            ? __('modules.report.salesDataFor') . " {$this->headingDateTime}, " . __('modules.report.timePeriod') . " {$this->headingStartTime} - {$this->headingEndTime}"
            : __('modules.report.salesDataFrom') . " {$this->headingDateTime} " . __('app.to') . " {$this->headingEndDateTime}, " . __('modules.report.timePeriodEachDay') . " {$this->headingStartTime} - {$this->headingEndTime}";

        $filterParts = [];
        $branch = branch();
        foreach ([$this->handlerId => __('modules.report.filterByHandler'), $this->waiterId => __('modules.report.waiter')] as $userId => $label) {
            if ($userId === null || $userId === '') {
                continue;
            }
            $q = User::withoutGlobalScope(BranchScope::class)->where('id', $userId)->where('restaurant_id', restaurant()->id);
            $branch && $q->where(fn ($sub) => $sub->where('branch_id', $branch->id)->orWhereNull('branch_id'));
            $filterParts[] = $label . ': ' . ($q->value('name') ?? $userId);
        }
        if ($this->paymentMethod) {
            $paymentLabel = match ($this->paymentMethod) {
                'cash' => __('modules.order.cash'),
                'upi' => __('modules.order.upi'),
                'card' => __('modules.order.card'),
                'razorpay' => __('modules.order.razorpay'),
                'stripe' => __('modules.order.stripe'),
                'flutterwave' => __('modules.order.flutterwave'),
                'bank_transfer' => __('modules.order.bank_transfer'),
                default => $this->paymentMethod,
            };
            $filterParts[] = __('modules.order.paymentMethod') . ': ' . $paymentLabel;
        }
        if ($filterParts !== []) {
            $headingTitle .= ' | ' . implode(' | ', $filterParts);
        }

        return [
            [__('menu.salesReport') . ' ' . $headingTitle],
            array_merge(
            [__('app.date'), __('modules.report.totalOrders')],
            $this->charges,
            $taxHeadings,
            [
                __('modules.report.totalTaxAmount'),
                __('modules.order.cash'),
                __('modules.order.upi'),
                __('modules.order.card'),
                __('modules.order.razorpay'),
                __('modules.order.stripe'),
                __('modules.order.flutterwave'),
                __('modules.order.due'),
                __('modules.report.outstandingReceived'),
                __('modules.order.deliveryFee'),
                __('modules.order.discount'),
                __('modules.order.tip'),
                __('modules.order.total'),
                __('modules.order.total') . ' ' . __('modules.order.totalExcludingTip')
            ]
            )
        ];
    }

    public function map($item): array
    {
        $mappedItem = [
            Carbon::parse($item['date'])->displayDate(),
            $item['total_orders'],
        ];

        foreach ($this->charges as $charge) {
            $mappedItem[] = currency_format($item[$charge] ?? 0, $this->currencyId);
        }

        foreach ($this->taxes as $tax) {
            $mappedItem[] = currency_format($item[$tax['tax_name']] ?? 0, $this->currencyId);
        }

        $mappedItem[] = currency_format($item['total_tax_amount'] ?? 0, $this->currencyId);

        $mappedItem[] = currency_format($item['cash_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['upi_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['card_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['razorpay_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['stripe_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['flutterwave_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['outstanding_amount'] ?? 0, $this->currencyId);
        $mappedItem[] = currency_format($item['due_received_amount'] ?? 0, $this->currencyId);
        $mappedItem[] = currency_format($item['delivery_fee'], $this->currencyId);
        $mappedItem[] = currency_format($item['discount_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['tip_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['total_amount'], $this->currencyId);
        $mappedItem[] = currency_format($item['total_excluding_tip'], $this->currencyId);


        return $mappedItem;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true, 'name' => 'Arial'], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'f5f5f5']]],
        ];
    }

    private function applyShiftFilter($query)
    {
        // If a specific shift is selected, filter orders by shift times
        if (!empty($this->shiftId) && branch()) {
            $selectedShift = BranchOperationalShift::find($this->shiftId);
            if ($selectedShift) {
                $restaurantTimezone = branch()->restaurant->timezone ?? 'UTC';
                $dateFormat = restaurant()->date_format ?? 'd-m-Y';

                // Determine the date_time column name based on whether it's a join query
                $dateTimeColumn = 'orders.date_time';
                if (method_exists($query, 'getQuery')) {
                    $joins = $query->getQuery()->joins ?? [];
                    if (empty($joins)) {
                        $dateTimeColumn = 'date_time';
                    }
                }

                // Build query to match orders within shift times for each day in the date range
                $query->where(function($q) use ($selectedShift, $restaurantTimezone, $dateFormat, $dateTimeColumn) {
                    // Parse the start and end dates from UTC back to restaurant timezone to iterate
                    $startDate = Carbon::createFromFormat($dateFormat, $this->startDate, $this->timezone);
                    $endDate = Carbon::createFromFormat($dateFormat, $this->endDate, $this->timezone);

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
                                $q->where(function($subQ) use ($shiftStartUTC, $shiftEndUTC, $dateTimeColumn) {
                                    $subQ->where($dateTimeColumn, '>=', $shiftStartUTC)
                                         ->where($dateTimeColumn, '<=', $shiftEndUTC);
                                });
                                $firstCondition = false;
                            } else {
                                $q->orWhere(function($subQ) use ($shiftStartUTC, $shiftEndUTC, $dateTimeColumn) {
                                    $subQ->where($dateTimeColumn, '>=', $shiftStartUTC)
                                         ->where($dateTimeColumn, '<=', $shiftEndUTC);
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

    public function collection()
    {
        $charges = RestaurantCharge::all()->keyBy('id');
        $taxes = Tax::all()->keyBy('id');

        $query = Order::join('payments', 'orders.id', '=', 'payments.order_id')
            ->whereBetween('orders.date_time', [$this->startDateTime, $this->endDateTime])
            ->whereIn('orders.status', ['paid', 'payment_due'])
            ->where('orders.branch_id', branch()->id);

        // Apply time filtering only if not filtering by shift (shift filter handles time internally)
        if (empty($this->shiftId)) {
            $query->where(function ($q) {
                if ($this->startTime < $this->endTime) {
                    $q->whereRaw("TIME(orders.date_time) BETWEEN ? AND ?", [$this->startTime, $this->endTime]);
                } else {
                    $q->where(function ($sub) {
                        $sub->whereRaw("TIME(orders.date_time) >= ?", [$this->startTime])
                            ->orWhereRaw("TIME(orders.date_time) <= ?", [$this->endTime]);
                    });
                }
            });
        }

        // Apply shift filter if selected
        $query = $this->applyShiftFilter($query);

        $queryResults = $this->applyJoinedOrderUserFilters($query)
            ->select(
                DB::raw("DATE(CONVERT_TZ(orders.date_time, '+00:00', '{$this->offset}')) as date"),
                DB::raw('COUNT(DISTINCT orders.id) as total_orders'),
                DB::raw('SUM(payments.amount) as total_amount'),
                DB::raw('SUM(CASE WHEN payments.payment_method = "cash" THEN payments.amount ELSE 0 END) as cash_amount'),
                DB::raw('SUM(CASE WHEN payments.payment_method = "card" THEN payments.amount ELSE 0 END) as card_amount'),
                DB::raw('SUM(CASE WHEN payments.payment_method = "upi" THEN payments.amount ELSE 0 END) as upi_amount'),
                DB::raw('SUM(CASE WHEN payments.payment_method = "razorpay" THEN payments.amount ELSE 0 END) as razorpay_amount'),
                DB::raw('SUM(CASE WHEN payments.payment_method = "stripe" THEN payments.amount ELSE 0 END) as stripe_amount'),
                DB::raw('SUM(CASE WHEN payments.payment_method = "flutterwave" THEN payments.amount ELSE 0 END) as flutterwave_amount'),
            )
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->keyBy('date');

        // Get order-level data separately to avoid duplication
        $orderData = Order::whereBetween('date_time', [$this->startDateTime, $this->endDateTime])
            ->whereIn('status', ['paid', 'payment_due'])
            ->where('branch_id', branch()->id);

        // Apply time filtering only if not filtering by shift
        if (empty($this->shiftId)) {
            $orderData->where(function ($q) {
                if ($this->startTime < $this->endTime) {
                    $q->whereRaw("TIME(date_time) BETWEEN ? AND ?", [$this->startTime, $this->endTime]);
                } else {
                    $q->where(function ($sub) {
                        $sub->whereRaw("TIME(date_time) >= ?", [$this->startTime])
                            ->orWhereRaw("TIME(date_time) <= ?", [$this->endTime]);
                    });
                }
            });
        }

        // Apply shift filter if selected
        $orderData = $this->applyShiftFilter($orderData);

        $orderData = $this->applyBareOrderUserFilters($orderData)
            ->select(
                DB::raw("DATE(CONVERT_TZ(date_time, '+00:00', '{$this->offset}')) as date"),
                DB::raw('SUM(discount_amount) as discount_amount'),
                DB::raw('SUM(tip_amount) as tip_amount'),
                DB::raw('SUM(delivery_fee) as delivery_fee'),
            )
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        // Get outstanding payments data - calculate remaining amount after payments
        $outstandingQuery = Order::whereBetween('date_time', [$this->startDateTime, $this->endDateTime])
            ->where('status', 'payment_due')
            ->where('branch_id', branch()->id);

        // Apply time filtering only if not filtering by shift
        if (empty($this->shiftId)) {
            $outstandingQuery->where(function ($q) {
                if ($this->startTime < $this->endTime) {
                    $q->whereRaw("TIME(date_time) BETWEEN ? AND ?", [$this->startTime, $this->endTime]);
                } else {
                    $q->where(function ($sub) {
                        $sub->whereRaw("TIME(date_time) >= ?", [$this->startTime])
                            ->orWhereRaw("TIME(date_time) <= ?", [$this->endTime]);
                    });
                }
            });
        }

        // Apply shift filter if selected
        $outstandingQuery = $this->applyShiftFilter($outstandingQuery);

        $outstandingQuery = $this->applyBareOrderUserFilters($outstandingQuery);

        $outstandingData = $outstandingQuery->select(
            DB::raw("DATE(CONVERT_TZ(date_time, '+00:00', '{$this->offset}')) as date"),
            DB::raw('SUM(
                CASE
                    WHEN split_type = "items" THEN
                        total - COALESCE((
                            SELECT SUM(so.amount)
                            FROM split_orders so
                            WHERE so.order_id = orders.id
                            AND so.status = "paid"
                        ), 0)
                    ELSE
                        total - COALESCE((
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
            ->whereBetween('orders.date_time', [$this->startDateTime, $this->endDateTime])
            ->whereIn('orders.status', ['paid', 'payment_due'])
            ->where('orders.branch_id', branch()->id)
            ->whereNotNull('payments.due_amount_received')
            ->where('payments.due_amount_received', '>', 0);

        // Apply time filtering only if not filtering by shift
        if (empty($this->shiftId)) {
            $dueReceivedQuery->where(function ($q) {
                if ($this->startTime < $this->endTime) {
                    $q->whereRaw("TIME(orders.date_time) BETWEEN ? AND ?", [$this->startTime, $this->endTime]);
                } else {
                    $q->where(function ($sub) {
                        $sub->whereRaw("TIME(orders.date_time) >= ?", [$this->startTime])
                            ->orWhereRaw("TIME(orders.date_time) <= ?", [$this->endTime]);
                    });
                }
            });
        }

        // Apply shift filter if selected
        $dueReceivedQuery = $this->applyShiftFilter($dueReceivedQuery);

        $dueReceivedQuery = $this->applyJoinedOrderUserFilters($dueReceivedQuery);

        $dueReceivedData = $dueReceivedQuery->select(
            DB::raw("DATE(CONVERT_TZ(orders.date_time, '+00:00', '{$this->offset}')) as date"),
            DB::raw('SUM(payments.due_amount_received) as due_received_amount')
        )
        ->groupBy('date')
        ->orderBy('date')
        ->get()
        ->keyBy('date');

        // Use queryResults which is now a collection
        $data = $queryResults->map(function ($item) use ($charges, $taxes, $orderData, $outstandingData, $dueReceivedData) {
            // Build the same per-day window used in the Livewire view
            $window = $this->buildDateWindow($item->date);

            // Get order-level data for this date
            $orderInfo = $orderData->get($item->date);
            $outstandingInfo = $outstandingData->get($item->date);
            $dueReceivedInfo = $dueReceivedData->get($item->date);

            $row = [
                'date' => $item->date,
                'total_orders' => $item->total_orders,
                'total_amount' => $item->total_amount ?? 0,
                'total_excluding_tip' => ($item->total_amount ?? 0) - ($orderInfo->tip_amount ?? 0),
                'delivery_fee' => $orderInfo->delivery_fee ?? 0,
                'tip_amount' => $orderInfo->tip_amount ?? 0,
                'cash_amount' => $item->cash_amount ?? 0,
                'card_amount' => $item->card_amount ?? 0,
                'upi_amount' => $item->upi_amount ?? 0,
                'discount_amount' => $orderInfo->discount_amount ?? 0,
                'razorpay_amount' => $item->razorpay_amount ?? 0,
                'stripe_amount' => $item->stripe_amount ?? 0,
                'flutterwave_amount' => $item->flutterwave_amount ?? 0,
                'outstanding_amount' => $outstandingInfo->outstanding_amount ?? 0,
                'due_received_amount' => $dueReceivedInfo->due_received_amount ?? 0,
            ];

            // Process charges dynamically using actual charge data
            $chargeAmounts = [];
            foreach ($charges as $charge) {
                $chargeAmounts[$charge->charge_name] = DB::table('order_charges')
                    ->join('orders', 'order_charges.order_id', '=', 'orders.id')
                    ->join('restaurant_charges', 'order_charges.charge_id', '=', 'restaurant_charges.id')
                    ->where('order_charges.charge_id', $charge->id)
                    ->whereIn('orders.status', ['paid', 'payment_due'])
                    ->whereBetween('orders.date_time', [$window['startDateTime'], $window['endDateTime']])
                    ->where(function ($q) use ($window) {
                        if ($window['startTime'] < $window['endTime']) {
                            $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$window['startTime'], $window['endTime']]);
                        } else {
                            $q->where(function ($sub) use ($window) {
                                $sub->whereRaw('TIME(orders.date_time) >= ?', [$window['startTime']])
                                    ->orWhereRaw('TIME(orders.date_time) <= ?', [$window['endTime']]);
                            });
                        }
                    })
                    ->where('orders.branch_id', branch()->id)
                    ->when($this->handlerId, function ($q) {
                        $q->where('orders.added_by', $this->handlerId);
                    })
                    ->when($this->waiterId, function ($q) {
                        $q->where('orders.waiter_id', $this->waiterId);
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
            $taxMode = restaurant()->tax_mode ?? 'order';

            if ($taxMode === 'item') {
                // Item-level tax data
                $itemTaxData = DB::table('order_items')
                    ->join('orders', 'order_items.order_id', '=', 'orders.id')
                    ->join('menu_items', 'order_items.menu_item_id', '=', 'menu_items.id')
                    ->join('menu_item_tax', 'menu_items.id', '=', 'menu_item_tax.menu_item_id')
                    ->join('taxes', 'menu_item_tax.tax_id', '=', 'taxes.id')
                    ->where('orders.status', 'paid')
                    ->where('orders.branch_id', branch()->id)
                    ->whereBetween('orders.date_time', [$window['startDateTime'], $window['endDateTime']])
                    ->where(function ($q) use ($window) {
                        if ($window['startTime'] < $window['endTime']) {
                            $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$window['startTime'], $window['endTime']]);
                        } else {
                            $q->where(function ($sub) use ($window) {
                                $sub->whereRaw('TIME(orders.date_time) >= ?', [$window['startTime']])
                                    ->orWhereRaw('TIME(orders.date_time) <= ?', [$window['endTime']]);
                            });
                        }
                    })
                    ->when($this->handlerId, function ($q) {
                        $q->where('orders.added_by', $this->handlerId);
                    })
                    ->when($this->waiterId, function ($q) {
                        $q->where('orders.waiter_id', $this->waiterId);
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
                $orderTaxData = null;
                $orderTaxQuery = DB::table('order_taxes')
                    ->join('orders', 'order_taxes.order_id', '=', 'orders.id')
                    ->join('taxes', 'order_taxes.tax_id', '=', 'taxes.id')
                    ->where('orders.status', 'paid')
                    ->where('orders.branch_id', branch()->id)
                    ->whereBetween('orders.date_time', [$window['startDateTime'], $window['endDateTime']]);

                // Apply time filtering only if not filtering by shift
                if (empty($this->shiftId)) {
                    $orderTaxQuery->where(function ($q) use ($window) {
                        if ($window['startTime'] < $window['endTime']) {
                            $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$window['startTime'], $window['endTime']]);
                        } else {
                            $q->where(function ($sub) use ($window) {
                                $sub->whereRaw('TIME(orders.date_time) >= ?', [$window['startTime']])
                                    ->orWhereRaw('TIME(orders.date_time) <= ?', [$window['endTime']]);
                            });
                        }
                    });
                } else {
                    // Apply shift filter for this specific date
                    if (branch()) {
                        $selectedShift = BranchOperationalShift::find($this->shiftId);
                        if ($selectedShift) {
                            $restaurantTimezone = branch()->restaurant->timezone ?? 'UTC';
                            $dateCarbon = Carbon::createFromFormat('Y-m-d', $item->date, $this->timezone);
                            $dayOfWeek = $dateCarbon->format('l');
                            $shiftDays = $selectedShift->day_of_week ?? [];

                            if (in_array('All', $shiftDays) || in_array($dayOfWeek, $shiftDays)) {
                                $shiftStart = Carbon::parse($dateCarbon->toDateString() . ' ' . $selectedShift->start_time, $restaurantTimezone);
                                $shiftEnd = Carbon::parse($dateCarbon->toDateString() . ' ' . $selectedShift->end_time, $restaurantTimezone);
                                if ($selectedShift->end_time < $selectedShift->start_time) {
                                    $shiftEnd->addDay();
                                }
                                $orderTaxQuery->whereBetween('orders.date_time', [
                                    $shiftStart->setTimezone('UTC')->toDateTimeString(),
                                    $shiftEnd->setTimezone('UTC')->toDateTimeString()
                                ]);
                            } else {
                                // Shift doesn't apply, set empty result
                                $orderTaxData = collect();
                            }
                        }
                    }
                }

                // Only execute query if orderTaxData hasn't been set yet
                if ($orderTaxData === null) {
                    $orderTaxData = $orderTaxQuery
                        ->when($this->handlerId, function ($q) {
                            $q->where('orders.added_by', $this->handlerId);
                        })
                        ->when($this->waiterId, function ($q) {
                            $q->where('orders.waiter_id', $this->waiterId);
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
                }

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
                        ->whereBetween('orders.date_time', [$window['startDateTime'], $window['endDateTime']])
                        ->where(function ($q) use ($window) {
                            if ($window['startTime'] < $window['endTime']) {
                                $q->whereRaw('TIME(orders.date_time) BETWEEN ? AND ?', [$window['startTime'], $window['endTime']]);
                            } else {
                                $q->where(function ($sub) use ($window) {
                                    $sub->whereRaw('TIME(orders.date_time) >= ?', [$window['startTime']])
                                        ->orWhereRaw('TIME(orders.date_time) <= ?', [$window['endTime']]);
                                });
                            }
                        })
                        ->when($this->handlerId, function ($q) {
                            $q->where('orders.added_by', $this->handlerId);
                        })
                        ->when($this->waiterId, function ($q) {
                            $q->where('orders.waiter_id', $this->waiterId);
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

            // Add charge amounts to row
            foreach ($charges as $charge) {
                $row[$charge->charge_name] = $chargeAmounts[$charge->charge_name] ?? 0;
            }

            // Add tax amounts to row
            foreach ($taxes as $tax) {
                $row[$tax->tax_name] = $taxAmounts[$tax->tax_name] ?? 0;
            }

            $row['total_tax_amount'] = $totalTaxAmount;

            return collect($row);
        });

        return $data;
    }
}
