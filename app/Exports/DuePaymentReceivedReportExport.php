<?php

namespace App\Exports;

use App\Models\Payment;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DuePaymentReceivedReportExport implements WithMapping, FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected $startDate;
    protected $endDate;
    protected $filterCustomer;

    public function __construct($startDate, $endDate, $filterCustomer = null)
    {
        $tz = timezone();
        $dateFormat = restaurant()->date_format ?? 'd-m-Y';

        $this->startDate = Carbon::createFromFormat($dateFormat, $startDate, $tz)->startOfDay();
        $this->endDate = Carbon::createFromFormat($dateFormat, $endDate, $tz)->endOfDay();
        $this->filterCustomer = $filterCustomer;
    }

    public function headings(): array
    {
        return [
            __('modules.customer.name'),
            __('modules.order.amount'),
            __('modules.order.paymentMethod'),
            __('modules.order.orderNumber'),
            __('app.dateTime'),
        ];
    }

    public function map($payment): array
    {
        return [
            $payment->order?->customer?->name ?? '--',
            currency_format($payment->due_amount_received, restaurant()->currency_id),
            __('modules.order.' . $payment->payment_method),
            $payment->order?->show_formatted_order_number ?? $payment->order?->order_number ?? '--',
            $payment->created_at->timezone(timezone())->displayDateTime(),
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
            1 => [
                'font' => ['bold' => true, 'name' => 'Arial'],
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'f5f5f5'],
                ],
            ],
        ];
    }

    public function collection()
    {
        $query = Payment::with(['order.customer'])
            ->whereNotNull('due_amount_received')
            ->where('due_amount_received', '>', 0)
            ->whereBetween('created_at', [$this->startDate, $this->endDate]);

        // Apply customer filter if selected
        if ($this->filterCustomer) {
            $query->whereHas('order', function($q) {
                $q->where('customer_id', $this->filterCustomer);
            });
        }

        return $query->orderBy('created_at', 'desc')->get();
    }
}

