<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\DeletedOrder;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DeletedOrderReportExport implements WithMapping, FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected $startDateTime;
    protected $endDateTime;
    protected $startTime;
    protected $endTime;
    protected $timezone;
    protected $offset;
    protected $selectedDeletedBy;
    protected $currencyId;
    protected $dateFormat;

    public function __construct(
        $startDateTime,
        $endDateTime,
        $timezone,
        $offset,
        $startTime = '00:00',
        $endTime = '23:59',
        $selectedDeletedBy = '',
        $currencyId = null
    ) {
        $this->startDateTime = $startDateTime;
        $this->endDateTime = $endDateTime;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->timezone = $timezone;
        $this->offset = $offset;
        $this->selectedDeletedBy = $selectedDeletedBy;
        $this->currencyId = $currencyId;
        $this->dateFormat = restaurant()->date_format ?? 'd-m-Y';
    }

    public function headings(): array
    {
        $startDate = Carbon::parse($this->startDateTime)->setTimezone($this->timezone)->format($this->dateFormat);
        $endDate = Carbon::parse($this->endDateTime)->setTimezone($this->timezone)->format($this->dateFormat);
        $formattedStartTime = Carbon::parse($this->startTime)->setTimezone($this->timezone)->format(timeFormat());
        $formattedEndTime = Carbon::parse($this->endTime)->setTimezone($this->timezone)->format(timeFormat());

        return [
            ['Delete Order Report - ' . $startDate . ' to ' . $endDate . ' (' . $formattedStartTime . ' - ' . $formattedEndTime . ')'],
            [
                'Order Number',
                'Order Date',
                'Deleted Date',
                'Customer',
                'Customer Phone',
                'Table',
                'Waiter',
                'Placed By',
                'Order Type',
                'Status',
                'Deleted By',
                'Items',
                'Amount Paid',
                'Order Total',
            ],
        ];
    }

    public function map($deletedOrder): array
    {
        $orderDate = $deletedOrder->order_date_time
            ? Carbon::parse($deletedOrder->order_date_time)->setTimezone($this->timezone)->displayDateTime()
            : 'N/A';

        $deletedDate = $deletedOrder->order_deleted_at
            ? Carbon::parse($deletedOrder->order_deleted_at)->setTimezone($this->timezone)->displayDateTime()
            : 'N/A';

        $itemsSummary = collect($deletedOrder->items ?? [])
            ->map(function ($item) {
                $line = ($item['quantity'] ?? 0) . 'x ' . ($item['name'] ?? 'N/A');
                if (!empty($item['variation'])) {
                    $line .= ' (' . $item['variation'] . ')';
                }

                return $line;
            })
            ->implode('; ');

        return [
            $deletedOrder->show_formatted_order_number,
            $orderDate,
            $deletedDate,
            $deletedOrder->customer_name ?? 'Walk-in',
            $deletedOrder->customer_phone ?? 'N/A',
            $deletedOrder->table_code ?? 'N/A',
            $deletedOrder->waiter_name ?? 'N/A',
            $deletedOrder->placed_by_name ?? 'N/A',
            $deletedOrder->order_type ?? 'N/A',
            $deletedOrder->status ?? 'N/A',
            $deletedOrder->deleted_by_name ?? ($deletedOrder->deletedBy->name ?? 'N/A'),
            $itemsSummary ?: 'N/A',
            currency_format($deletedOrder->amount_paid ?? 0, $this->currencyId),
            currency_format($deletedOrder->total ?? 0, $this->currencyId),
        ];
    }

    public function defaultStyles(Style $defaultStyle)
    {
        return $defaultStyle->getFont()->setName('Arial');
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'name' => 'Arial', 'size' => 14], 'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'f5f5f5'],
            ]],
            2 => ['font' => ['bold' => true, 'name' => 'Arial'], 'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'e5e5e5'],
            ]],
        ];
    }

    public function collection()
    {
        $query = DeletedOrder::with('deletedBy')
            ->whereBetween('order_deleted_at', [$this->startDateTime, $this->endDateTime])
            ->where(function ($q) {
                if ($this->startTime < $this->endTime) {
                    $q->whereRaw('TIME(order_deleted_at) BETWEEN ? AND ?', [$this->startTime, $this->endTime]);
                } else {
                    $q->where(function ($sub) {
                        $sub->whereRaw('TIME(order_deleted_at) >= ?', [$this->startTime])
                            ->orWhereRaw('TIME(order_deleted_at) <= ?', [$this->endTime]);
                    });
                }
            });

        if ($this->selectedDeletedBy) {
            $query->where('deleted_by', $this->selectedDeletedBy);
        }

        return $query->orderBy('order_deleted_at', 'desc')->get();
    }
}
