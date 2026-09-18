<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\RestaurantCharge;
use App\Models\RestaurantTax;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Session;

class OrderBillPdfService
{
    public function getPdfContent(int|Order $order): string
    {
        $orderModel = is_int($order)
            ? Order::with(['items.menuItem.translations', 'items.menuItemVariation', 'items.modifierOptions', 'charges.charge', 'taxes.tax', 'branch.receiptSetting'])
                ->findOrFail($order)
            : $order->loadMissing(['items.menuItem.translations', 'items.menuItemVariation', 'items.modifierOptions', 'charges.charge', 'taxes.tax', 'branch.receiptSetting']);

        $payment = Payment::where('order_id', $orderModel->id)->first();
        $orderModel->loadMissing(['branch.restaurant.currency']);

        $orderBranch = $orderModel->branch ?? branch();
        $restaurant = $orderBranch?->restaurant ?? restaurant();
        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();

        $receiptSettings = $orderBranch->receiptSetting;

        $taxMode = $orderModel?->tax_mode ?? ($restaurant->tax_mode ?? 'order');
        $totalTaxAmount = 0;
        if ($taxMode === 'item') {
            $totalTaxAmount = $orderModel->total_tax_amount ?? 0;
        }

        if ($orderModel->tax_base) {
            $taxBase = $orderModel->tax_base;
        } else {
            $net = $orderModel->sub_total - ($orderModel->discount_amount ?? 0);
            $charges = collect($orderModel->charges ?? [])
                ->map(fn ($item) => $item->charge)
                ->filter();
            $chargeTotals = RestaurantCharge::calculateTotals($charges, $net);
            $includeChargesInTaxBase = $restaurant->include_charges_in_tax_base ?? true;
            $taxBase = RestaurantCharge::resolveTaxBase($net, $charges, $includeChargesInTaxBase);
        }

        $receiptLanguages = $receiptSettings?->receipt_languages ?? ['en'];

        // `order.print-pdf` view uses `restaurant()` / `branch()` helpers which depend on session.
        // In queued notifications / CLI context, those helpers return false unless we set them.
        $previousRestaurant = Session::get('restaurant');
        $previousBranch = Session::get('branch');

        Session::put('restaurant', $restaurant);
        Session::put('branch', $orderBranch);

        try {
            $pdf = Pdf::loadView('order.print-pdf', [
                'order' => $orderModel,
                'orderBranch' => $orderBranch,
                'receiptSettings' => $receiptSettings,
                'taxDetails' => $taxDetails,
                'payment' => $payment,
                'taxMode' => $taxMode,
                'totalTaxAmount' => $totalTaxAmount,
                'taxBase' => $taxBase,
                'receiptLanguages' => $receiptLanguages,
            ]);
        } finally {
            Session::put('restaurant', $previousRestaurant);
            Session::put('branch', $previousBranch);
        }

        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }
}

