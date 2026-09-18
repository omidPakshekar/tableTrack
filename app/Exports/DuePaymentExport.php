<?php

namespace App\Exports;

use App\Models\Payment;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Style;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class DuePaymentExport implements WithMapping, FromCollection, WithHeadings, WithStyles, ShouldAutoSize
{
    protected ?string $search;
    protected ?int $customerId;

    public function __construct(?string $search = null, $customerId = null)
    {
        $this->search = $search ?: null;
        $this->customerId = $customerId ? (int) $customerId : null;
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
            $payment->amount,
            __('modules.order.due'),
            $payment->order?->show_formatted_order_number ?? ($payment->order_number ?? $payment->order_id),
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
        return Payment::query()
            ->with(['order.customer'])
            ->join('orders', 'orders.id', 'payments.order_id')
            ->where('payment_method', 'due')
            ->when($this->search, function ($q) {
                $search = $this->search;

                return $q->where(function ($inner) use ($search) {
                    $inner->where('amount', 'like', '%' . $search . '%')
                        ->orWhere('order_id', 'like', '%' . $search . '%')
                        ->orWhere('orders.order_number', 'like', '%' . $search . '%');
                });
            })
            ->when($this->customerId, function ($q) {
                $q->where('orders.customer_id', $this->customerId);
            })
            ->select('payments.*', 'orders.order_number')
            ->orderBy('payments.id', 'desc')
            ->get();
    }
}


