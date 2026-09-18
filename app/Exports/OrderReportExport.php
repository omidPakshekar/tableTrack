<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\Order;
use App\Helper\Common;
use Illuminate\Support\Facades\Lang;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class OrderReportExport implements WithMapping, FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected string $startDateTime;
    protected string $endDateTime;
    protected string $startTime;
    protected string $endTime;
    protected string $timezone;
    protected string $searchTerm;
    protected string $orderTypeFilter;
    protected string $headingDateTime;
    protected string $headingEndDateTime;
    protected string $headingStartTime;
    protected string $headingEndTime;

    public function __construct(
        string $startDateTime,
        string $endDateTime,
        string $startTime,
        string $endTime,
        string $timezone,
        ?string $searchTerm = '',
        ?string $orderTypeFilter = ''
    ) {
        $this->startDateTime = $startDateTime;
        $this->endDateTime = $endDateTime;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->timezone = $timezone;
        $this->searchTerm = $searchTerm ?? '';
        $this->orderTypeFilter = $orderTypeFilter ?? '';

        $this->headingDateTime = Carbon::parse($startDateTime)->setTimezone($timezone)->format('Y-m-d');
        $this->headingEndDateTime = Carbon::parse($endDateTime)->setTimezone($timezone)->format('Y-m-d');
        $this->headingStartTime = Carbon::parse($startTime)->setTimezone($timezone)->format('h:i A');
        $this->headingEndTime = Carbon::parse($endTime)->setTimezone($timezone)->format('h:i A');
    }

    public function headings(): array
    {
        $headingTitle = $this->headingDateTime === $this->headingEndDateTime
            ? __('modules.report.salesDataFor') . " {$this->headingDateTime}, " . __('modules.report.timePeriod') . " {$this->headingStartTime} - {$this->headingEndTime}"
            : __('modules.report.salesDataFrom') . " {$this->headingDateTime} " . __('app.to') . " {$this->headingEndDateTime}, " . __('modules.report.timePeriodEachDay') . " {$this->headingStartTime} - {$this->headingEndTime}";

        return [
            [__('modules.order.orderReport') . ' ' . $headingTitle],
            [
                __('app.id'),
                __('modules.settings.branchName'),
                __('modules.report.orderNumber'),
                __('modules.report.orderDate'),
                __('app.status'),
                __('modules.report.placedBy'),
                __('modules.order.amountPaid'),
                __('modules.order.paymentMethod'),
                __('modules.order.totalTax'),
                __('modules.report.orderType'),
            ],
        ];
    }

    public function map($order): array
    {
        $paymentMethods = $order->payments->pluck('payment_method')->unique()->filter();
        $paymentMethodLabel = $paymentMethods->isNotEmpty()
            ? $paymentMethods->map(fn ($method) => Lang::has('modules.order.' . $method) ? __('modules.order.' . $method) : ucwords(str_replace('_', ' ', $method)))->join(', ')
            : '-';

        $statusLabel = Lang::has('modules.order.' . $order->status)
            ? __('modules.order.' . $order->status)
            : ucwords(str_replace('_', ' ', $order->status ?? ''));

        $orderTypeLabel = $order->order_type && Lang::has('modules.order.' . $order->order_type)
            ? __('modules.order.' . $order->order_type)
            : ($order->order_type ? ucwords(str_replace('_', ' ', $order->order_type)) : '-');

        return [
            $order->id,
            $order->branch?->name ?? '-',
            $order->show_formatted_order_number ?? $order->order_number,
            $order->date_time
                ? Carbon::parse($order->date_time)->setTimezone($this->timezone)->displayDateTime()
                : '-',
            $statusLabel,
            $order->addedBy?->name ?? '-',
            currency_format($order->amount_paid ?? 0, restaurant()->currency_id),
            $paymentMethodLabel,
            currency_format($order->total_tax_amount ?? 0, restaurant()->currency_id),
            $orderTypeLabel,
        ];
    }

    public function defaultStyles(Style $defaultStyle)
    {
        return $defaultStyle
            ->getFont()
            ->setName('Arial');
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'name' => 'Arial'], 'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'f5f5f5'],
            ]],
            2 => ['font' => ['bold' => true, 'name' => 'Arial'], 'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'e5e7eb'],
            ]],
        ];
    }

    public function collection()
    {
        $query = Order::with(['branch', 'addedBy', 'customer', 'waiter', 'payments'])
            ->where('branch_id', branch()->id)
            ->whereBetween('date_time', [$this->startDateTime, $this->endDateTime])
            ->where(function ($q) {
                if ($this->startTime < $this->endTime) {
                    $q->whereRaw('TIME(date_time) BETWEEN ? AND ?', [$this->startTime, $this->endTime]);
                } else {
                    $q->where(function ($sub) {
                        $sub->whereRaw('TIME(date_time) >= ?', [$this->startTime])
                            ->orWhereRaw('TIME(date_time) <= ?', [$this->endTime]);
                    });
                }
            });

        if ($this->orderTypeFilter) {
            $query->where('order_type', $this->orderTypeFilter);
        }

        if ($this->searchTerm) {
            $safeTerm = Common::safeString($this->searchTerm);
            $search = '%' . $safeTerm . '%';
            $normalizedSearch = '%' . str_replace(' ', '_', $safeTerm) . '%';

            $query->where(function ($q) use ($search, $normalizedSearch) {
                $q->where('order_number', 'like', $search)
                    ->orWhere('status', 'like', $normalizedSearch)
                    ->orWhere('order_type', 'like', $normalizedSearch)
                    ->orWhereHas('branch', function ($q) use ($search) {
                        $q->where('name', 'like', $search);
                    })
                    ->orWhereHas('payments', function ($q) use ($normalizedSearch) {
                        $q->where('payment_method', 'like', $normalizedSearch);
                    })
                    ->orWhereHas('customer', function ($q) use ($search) {
                        $q->where('name', 'like', $search)
                            ->orWhere('phone', 'like', $search);
                    })
                    ->orWhereHas('waiter', function ($q) use ($search) {
                        $q->where('name', 'like', $search);
                    })
                    ->orWhereHas('addedBy', function ($q) use ($search) {
                        $q->where('name', 'like', $search);
                    });
            });
        }

        return $query->orderBy('date_time', 'desc')->get();
    }
}
