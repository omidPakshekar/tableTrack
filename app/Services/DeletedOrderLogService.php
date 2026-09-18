<?php

namespace App\Services;

use App\Models\DeletedOrder;
use App\Models\Order;
use App\Scopes\AvailableMenuItemScope;

class DeletedOrderLogService
{
    public function log(Order $order): void
    {
        $order->loadMissing([
            'customer',
            'table',
            'waiter',
            'addedBy',
            'items.menuItem' => fn ($query) => $query->withoutGlobalScope(AvailableMenuItemScope::class),
            'items.menuItemVariation',
            'items.modifierOptions',
            'payments',
        ]);

        $deletedByUser = user();

        $items = $order->items->map(function ($item) {
            return [
                'menu_item_id' => $item->menu_item_id,
                'name' => $item->menuItem?->item_name,
                'variation' => $item->menuItemVariation?->variation,
                'quantity' => $item->quantity,
                'price' => $item->price,
                'amount' => $item->amount,
                'note' => $item->note,
                'modifiers' => $item->modifierOptions
                    ->map(fn ($option) => $option->pivot->modifier_option_name ?? $option->name)
                    ->filter()
                    ->values()
                    ->all(),
            ];
        })->values()->all();

        DeletedOrder::create([
            'original_order_id' => $order->id,
            'branch_id' => $order->branch_id,
            'order_number' => $order->order_number,
            'formatted_order_number' => $order->formatted_order_number,
            'uuid' => $order->uuid,
            'order_date_time' => $order->date_time,
            'order_deleted_at' => now(),
            'deleted_by' => $deletedByUser?->id,
            'deleted_by_name' => $deletedByUser?->name,
            'customer_id' => $order->customer_id,
            'customer_name' => $order->customer?->name,
            'customer_phone' => $order->customer?->phone,
            'table_id' => $order->table_id,
            'table_code' => $order->table?->table_code,
            'waiter_id' => $order->waiter_id,
            'waiter_name' => $order->waiter?->name,
            'added_by' => $order->added_by,
            'placed_by_name' => $order->addedBy?->name,
            'order_type' => $order->order_type,
            'status' => $order->status,
            'order_status' => $order->order_status?->value ?? $order->order_status,
            'sub_total' => $order->sub_total ?? 0,
            'total' => $order->total ?? 0,
            'amount_paid' => $order->amount_paid ?? 0,
            'total_tax_amount' => $order->total_tax_amount ?? 0,
            'number_of_pax' => $order->number_of_pax,
            'payment_methods' => $order->payments->pluck('payment_method')->unique()->filter()->values()->all(),
            'items' => $items,
        ]);
    }
}
