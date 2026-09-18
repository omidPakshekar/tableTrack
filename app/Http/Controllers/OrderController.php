<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\KotItem;
use App\Models\Payment;
use App\Models\RestaurantTax;
use App\Models\SplitOrder;
use App\Models\Printer;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;
class OrderController extends Controller
{

    public function index()
    {
        abort_if(!in_array('Order', restaurant_modules()), 403);
        abort_if((!user_can('Show Order')), 403);
        return view('order.index');
    }

    public function show($id)
    {
        return view('order.show', compact('id'));
    }

    public function recent(): JsonResponse
    {
        abort_if(!in_array('Order', restaurant_modules()), 403);
        abort_if(!user_can('Show Order') && !user_can('Create Order'), 403);

        $timezone = timezone();

        $orders = Order::query()
            ->with([
                'customer:id,name',
                'table:id,table_code',
                'waiter:id,name',
                'deliveryExecutive:id,name',
                'orderType:id,order_type_name',
                'kot' => fn ($query) => $query->where('status', '!=', 'cancelled'),
                'kot.items',
            ])
            ->withCount('items')
            ->where('status', '!=', 'draft')
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(function (Order $order) use ($timezone) {
                $createdAt = $order->created_at?->timezone($timezone);
                $isToday = $createdAt?->isToday() ?? false;
                $orderTypeLabel = $order->orderType?->order_type_name
                    ?? ucfirst(str_replace('_', ' ', (string) $order->order_type));
                $statusValue = strtolower((string) ($order->status ?? ''));
                $statusLabel = strtoupper(str_replace('_', ' ', $statusValue ?: '--'));
                $statusBadgeClass = match ($statusValue) {
                    'draft' => 'bg-gray-100 text-gray-800 border-gray-400 dark:bg-gray-700 dark:text-gray-400 dark:border-gray-600',
                    'kot' => 'bg-yellow-100 text-yellow-800 border-yellow-400 dark:bg-yellow-900/30 dark:text-yellow-300 dark:border-yellow-600',
                    'billed' => 'bg-blue-100 text-blue-800 border-blue-400 dark:bg-blue-900/30 dark:text-blue-300 dark:border-blue-600',
                    'paid' => 'bg-green-100 text-green-800 border-green-400 dark:bg-green-900/30 dark:text-green-300 dark:border-green-600',
                    'payment_due' => 'bg-amber-100 text-amber-800 border-amber-400 dark:bg-amber-900/30 dark:text-amber-300 dark:border-amber-600',
                    'canceled', 'cancelled' => 'bg-red-100 text-red-800 border-red-400 dark:bg-red-900/30 dark:text-red-300 dark:border-red-600',
                    default => 'bg-gray-100 text-gray-700 border-gray-300 dark:bg-gray-700 dark:text-gray-300 dark:border-gray-600',
                };
                $orderItemsCount = (int) ($order->items_count ?? 0);
                $kotItemsCount = 0;
                if ($orderItemsCount === 0) {
                    $kotItemsCount = $order->kot
                        ->flatMap(function ($kot) {
                            return $kot->items->filter(function (KotItem $item) {
                                $status = $item->status;

                                return $status === null || $status !== 'cancelled';
                            });
                        })
                        ->count();
                }
                // KOT-stage orders often have rows only in kot_items (status may be null).
                $itemsCount = max($orderItemsCount, $kotItemsCount);

                return [
                    'id' => $order->id,
                    'uuid' => $order->uuid,
                    'order_number' => $order->show_formatted_order_number,
                    'customer_name' => $order->customer?->name ?? '--',
                    'status_label' => $statusLabel,
                    'status_badge_class' => $statusBadgeClass,
                    'order_progress_label' => $order->order_status?->translatedLabel() ?? '--',
                    'total' => currency_format($order->total),
                    'date_label' => $isToday ? $createdAt?->format('h:i A') : $createdAt?->format('d M Y h:i A'),
                    'created_at_label' => $createdAt?->format('d M Y h:i A') ?? '--',
                    'order_type' => (string) ($order->order_type ?? ''),
                    'order_type_label' => $orderTypeLabel ?: '--',
                    'payment_status_label' => $statusLabel,
                    'table_label' => $order->table?->table_code ?? '--',
                    'waiter_name' => $order->waiter?->name ?? '--',
                    'delivery_executive_name' => $order->deliveryExecutive?->name ?? '--',
                    'items_count' => $itemsCount,
                    'view_url' => route('orders.show', $order->uuid),
                    'pos_detail_url' => route('pos.kot', ['id' => $order->id]) . '?show-order-detail=true',
                ];
            })
            ->values();

        return response()->json([
            'orders' => $orders,
        ]);
    }

    public function printOrder($id, $width = 80, $thermal = false, $generateImage = false)
    {
        // IMPORTANT:
        // Only treat input as UUID when it's actually a UUID string.
        // Some legacy datasets may have numeric-looking UUIDs, which can cause
        // a wrong order to be fetched (e.g. id=60 matching uuid="60" of another order).
        if (is_string($id) && Str::isUuid($id)) {
            $id = Order::where('uuid', $id)->value('id') ?: $id;
        }
        $id = (int) $id;

        $payment = Payment::where('order_id', $id)->first();
        $restaurant = restaurant();
        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();
        $orderRelations = ['items.menuItem', 'items.menuItemVariation', 'items.modifierOptions'];
        if (function_exists('module_enabled') && module_enabled('Hotel') && in_array('Hotel', restaurant_modules())) {
            $orderRelations[] = 'hotelStay.room';
            $orderRelations[] = 'hotelStay.stayGuests.guest';
            $orderRelations[] = 'hotelRoom';
        }
        $order = Order::with($orderRelations)->find($id);
        if ($order && isHotelModuleEnabled()) {
            $order->append(['context_room_number', 'context_stay_number']);
        }
        $orderBranch = $order->branch ?? branch();
        $receiptSettings = $orderBranch->receiptSetting;
        $taxMode = $order?->tax_mode ?? ($restaurant->tax_mode ?? 'order');
        $totalTaxAmount = 0;

        if ($taxMode === 'item') {
            $totalTaxAmount = $order->total_tax_amount ?? 0;
        }

        $receiptLanguages = $receiptSettings->receipt_languages ?? ['en'];

        // Prefer the bill/order place printer (used by direct print), then default printer.
        $orderPlacePrinter = \App\Models\MultipleOrder::with('printerSetting')->first()?->printerSetting;
        $printerSetting = $orderPlacePrinter ?: Printer::where('is_default', true)->first();
        $printingChoice = $printerSetting?->printing_choice ?? 'browserPopupPrint';

        // Keep one line with all variables from both views (union of both compact calls)
        $content = view('order.print', compact('order', 'orderBranch', 'receiptSettings', 'taxDetails', 'payment', 'taxMode', 'totalTaxAmount', 'width', 'thermal', 'generateImage', 'receiptLanguages', 'printingChoice'));

        return $content;
    }

    /**
     * Generate PDF for order print
     */
    public function generateOrderPdf($id)
    {
        $payment = Payment::where('order_id', $id)->first();
        $restaurant = restaurant();
        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();
        $order = Order::with(['items.menuItem.translations', 'items.menuItemVariation', 'items.modifierOptions'])->find($id);
        $orderBranch = $order->branch ?? branch();
        $receiptSettings = $orderBranch->receiptSetting;
        $taxMode = $restaurant->tax_mode ?? 'order';
        $totalTaxAmount = 0;

        if ($taxMode === 'item') {
            $totalTaxAmount = $order->total_tax_amount ?? 0;
        }

        // Calculate tax_base for PDF view
        if ($order->tax_base) {
            $taxBase = $order->tax_base;
        } else {
            // Fallback for old orders
            $net = $order->sub_total - ($order->discount_amount ?? 0);
            $serviceTotal = 0;
            foreach ($order->charges as $item) {
                $serviceTotal += $item->charge->getAmount($net);
            }
            $includeChargesInTaxBase = $restaurant->include_charges_in_tax_base ?? true;
            $taxBase = $includeChargesInTaxBase ? ($net + $serviceTotal) : $net;
        }

        $receiptLanguages = $receiptSettings->receipt_languages ?? ['en'];

        // Generate PDF
        $pdf = Pdf::loadView('order.print-pdf', compact('order', 'orderBranch', 'receiptSettings', 'taxDetails', 'payment', 'taxMode', 'totalTaxAmount', 'taxBase', 'receiptLanguages'));

        // Set paper size to A4
        $pdf->setPaper('A4', 'portrait');

        return $pdf->download($order->show_formatted_order_number . '.pdf');
    }

    /**
     * Get PDF content as string for email attachment
     */
    public function getOrderPdfContent($id)
    {
        $payment = Payment::where('order_id', $id)->first();
        $restaurant = restaurant();
        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();
        $order = Order::with(['items.menuItem.translations', 'items.menuItemVariation', 'items.modifierOptions'])->find($id);
        $orderBranch = $order->branch ?? branch();
        $receiptSettings = $orderBranch->receiptSetting;
        $taxMode = $restaurant->tax_mode ?? 'order';
        $totalTaxAmount = 0;

        if ($taxMode === 'item') {
            $totalTaxAmount = $order->total_tax_amount ?? 0;
        }

        // Calculate tax_base for PDF view
        if ($order->tax_base) {
            $taxBase = $order->tax_base;
        } else {
            // Fallback for old orders
            $net = $order->sub_total - ($order->discount_amount ?? 0);
            $serviceTotal = 0;
            foreach ($order->charges as $item) {
                $serviceTotal += $item->charge->getAmount($net);
            }
            $includeChargesInTaxBase = $restaurant->include_charges_in_tax_base ?? true;
            $taxBase = $includeChargesInTaxBase ? ($net + $serviceTotal) : $net;
        }

        $receiptLanguages = $receiptSettings->receipt_languages ?? ['en'];

        // Generate PDF
        $pdf = Pdf::loadView('order.print-pdf', compact('order', 'orderBranch', 'receiptSettings', 'taxDetails', 'payment', 'taxMode', 'totalTaxAmount', 'taxBase', 'receiptLanguages'));

        // Set paper size to A4
        $pdf->setPaper('A4', 'portrait');

        return $pdf->output();
    }

    /**
     * Update waiter response status from dropdown
     */
    public function updateWaiterResponse(Request $request, $uuid)
    {
        $validated = $request->validate([
            'waiter_response' => 'required|in:pending,accepted,declined',
        ]);

        $order = Order::where('uuid', $uuid)->firstOrFail();

        if (!$order->waiter_id) {
            if ($request->wantsJson()) {
                return response()->json(['message' => __('messages.invalidRequest')], 422);
            }

            return back()->with('error', __('messages.invalidRequest'));
        }

        $order->waiter_response = $validated['waiter_response'];
        $order->waiter_response_at = now();
        $order->save();

        if ($request->wantsJson()) {
            return response()->json([
                'message' => __('messages.statusUpdated'),
                'waiter_response' => $order->waiter_response,
            ]);
        }

        return back()->with('status', __('messages.statusUpdated'));

    }

    public function printSplitOrder($orderId, $width = 80, $thermal = false)
    {
        // Try to find as SplitOrder first
        $splitOrder = SplitOrder::with([
            'order.items.menuItem',
            'order.items.menuItemVariation',
            'order.items.modifierOptions',
            'items.orderItem.menuItem',
            'items.orderItem.menuItemVariation',
            'items.orderItem.modifierOptions'
        ])->find($orderId);

        // If found as SplitOrder, print single split
        if ($splitOrder) {
            $order = $splitOrder->order;
            $totalSplits = $order->splitOrders()->count();
            $splitNumber = $order->splitOrders()->where('id', '<=', $orderId)->count();
        }
        // Otherwise, treat as Order ID and print all paid splits
        else {
            $order = Order::with([
                'items.menuItem',
                'items.menuItemVariation',
                'items.modifierOptions'
            ])->findOrFail($orderId);
            // Get only paid split orders with their items
            $paidSplitOrders = $order->splitOrders()
                ->where('status', 'paid')
                ->with([
                    'items.orderItem.menuItem',
                    'items.orderItem.menuItemVariation',
                    'items.orderItem.modifierOptions'
                ])
                ->get();

            $totalSplits = $paidSplitOrders->count();
            $printAllSplits = true;

            // Set paid split orders to order for view
            $order->setRelation('splitOrders', $paidSplitOrders);
        }

        $payment = Payment::where('order_id', $order->id)->first();
        $restaurant = restaurant();
        $taxDetails = RestaurantTax::where('restaurant_id', $restaurant->id)->get();
        $receiptSettings = $restaurant->receiptSetting;
        $taxMode = $order?->tax_mode ?? ($restaurant->tax_mode ?? 'order');
        $totalTaxAmount = 0;

        if ($taxMode === 'item') {
            $totalTaxAmount = $order->total_tax_amount ?? 0;
        }

        $viewData = compact(
            'order',
            'receiptSettings',
            'taxDetails',
            'payment',
            'taxMode',
            'totalTaxAmount',
            'width',
            'thermal',
            'totalSplits'
        );

        // Add single split specific data if applicable
        if (isset($splitOrder) && isset($splitNumber)) {
            $viewData['splitOrder'] = $splitOrder;
            $viewData['splitNumber'] = $splitNumber;
        }

        // Add all splits flag if applicable
        if (isset($printAllSplits)) {
            $viewData['printAllSplits'] = $printAllSplits;
        }

        return view('order.print', $viewData);
    }
}
