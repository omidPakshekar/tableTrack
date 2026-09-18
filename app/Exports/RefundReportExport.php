<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\Refund;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use App\Helper\Common;

class RefundReportExport implements WithMapping, FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected string $startDateTime, $endDateTime;
    protected string $startTime, $endTime, $timezone, $searchTerm, $refundTypeFilter;
    protected $headingDateTime, $headingEndDateTime, $headingStartTime, $headingEndTime;

    public function __construct(string $startDateTime, string $endDateTime, string $startTime, string $endTime, string $timezone, ?string $searchTerm = '', ?string $refundTypeFilter = '')
    {
        $this->startDateTime = $startDateTime;
        $this->endDateTime = $endDateTime;
        $this->startTime = $startTime;
        $this->endTime = $endTime;
        $this->timezone = $timezone;
        $this->searchTerm = $searchTerm ?? '';
        $this->refundTypeFilter = $refundTypeFilter ?? '';

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
            [__('modules.refund.refundReport') . ' ' . $headingTitle],
            [
                __('app.date'),
                __('modules.order.orderNumber'),
                __('modules.order.deliveryApp'),
                __('modules.refund.refundType'),
                __('modules.settings.refundReason'),
                __('modules.refund.processedBy'),
                __('modules.refund.originalPrice'),
                __('modules.refund.refundedAmount'),
                __('modules.refund.resalePrice'),
                __('modules.refund.commissionAdjustment'),
                __('modules.refund.inventoryChange'),
                __('modules.refund.notes'),
            ]
        ];
    }

    public function map($refund): array
    {
        // Use stored commission_adjustment from database
        $deliveryCommissionAdjustment = $refund->commission_adjustment ?? 0;

        $refundTypeLabel = match($refund->refund_type) {
            'full' => __('modules.refund.fullRefund'),
            'partial' => __('modules.refund.partialRefund'),
            'waste' => __('modules.refund.wasteRefund'),
            default => $refund->refund_type
        };

        if ($refund->refund_type === 'partial' && $refund->partial_refund_type) {
            $partialTypeLabels = [
                'half' => __('modules.refund.halfPrice'),
                'fixed' => __('modules.refund.fixedAmount'),
                'custom' => __('modules.refund.customAmount')
            ];
            $refundTypeLabel .= ' (' . ($partialTypeLabels[$refund->partial_refund_type] ?? $refund->partial_refund_type) . ')';
        }

        $inventoryChange = '-';
        if ($refund->refund_type === 'waste') {
            $inventoryChange = __('modules.refund.writeOff');
        }

        $deliveryAppName = '-';
        if ($refund->deliveryPlatform) {
            $deliveryAppName = $refund->deliveryPlatform->name;
        } elseif ($refund->payment && $refund->payment->order && $refund->payment->order->deliveryPlatform) {
            $deliveryAppName = $refund->payment->order->deliveryPlatform->name;
        }

        return [
            $refund->processed_at ? Carbon::parse($refund->processed_at)->setTimezone($this->timezone)->format('Y-m-d') : '-',
            $refund->payment && $refund->payment->order ? ($refund->payment->order->show_formatted_order_number ?? $refund->payment->order->order_number) : '-',
            $deliveryAppName,
            $refundTypeLabel,
            $refund->refundReason->reason ?? '-',
            $refund->processedBy->name ?? '-',
            currency_format($refund->payment->amount ?? 0, restaurant()->currency_id),
            currency_format($refund->amount, restaurant()->currency_id),
            __('app.na'),
            $deliveryCommissionAdjustment > 0 ? currency_format($deliveryCommissionAdjustment, restaurant()->currency_id) : '-',
            $inventoryChange,
            $refund->notes ?? '-',
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
            // Style the first row as bold text.
            1    => ['font' => ['bold' => true, 'name' => 'Arial'], 'fill'  => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => array('rgb' => 'f5f5f5'),
            ]],
            2    => ['font' => ['bold' => true, 'name' => 'Arial'], 'fill'  => [
                'fillType'   => Fill::FILL_SOLID,
                'startColor' => array('rgb' => 'e5e7eb'),
            ]],
        ];
    }

    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $query = Refund::with(['payment.order.deliveryPlatform', 'deliveryPlatform', 'refundReason', 'processedBy'])
            ->where('branch_id', branch()->id)
            ->where('status', 'processed')
            ->whereBetween('processed_at', [$this->startDateTime, $this->endDateTime])
            ->where(function ($q) {
                if ($this->startTime < $this->endTime) {
                    $q->whereRaw("TIME(processed_at) BETWEEN ? AND ?", [$this->startTime, $this->endTime]);
                } else {
                    $q->where(function ($sub) {
                        $sub->whereRaw("TIME(processed_at) >= ?", [$this->startTime])
                            ->orWhereRaw("TIME(processed_at) <= ?", [$this->endTime]);
                    });
                }
            });

        if ($this->refundTypeFilter) {
            $query->where('refund_type', $this->refundTypeFilter);
        }

        if ($this->searchTerm) {
            $query->where(function ($q) {
                $safeTerm = Common::safeString($this->searchTerm);
                $q->whereHas('order', function ($q) use ($safeTerm) {
                    $q->where('order_number', 'like', '%' . $safeTerm . '%');
                })
                ->orWhereHas('refundReason', function ($q) use ($safeTerm) {
                    $q->where('reason', 'like', '%' . $safeTerm . '%');
                })
                ->orWhereHas('processedBy', function ($q) use ($safeTerm) {
                    $q->where('name', 'like', '%' . $safeTerm . '%');
                });
            });
        }

        return $query->get();
    }
}

