<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Table;

class RestaurantCloseGuard
{
    /**
     * Whether the branch can close (no running tables or unpaid billed orders).
     */
    public static function canClose(int $branchId): bool
    {
        $blockers = self::blockers($branchId);

        return empty($blockers['tables']) && empty($blockers['orders']);
    }

    /**
     * Open tables and unpaid billed / payment_due orders that block closing.
     *
     * @return array{tables: list<array{id: int, table_code: string|null}>, orders: list<array{id: int, order_number: mixed, show_formatted_order_number: string|null, table_code: string|null, status: string, total: mixed}>}
     */
    public static function blockers(int $branchId): array
    {
        $tables = Table::query()
            ->where('branch_id', $branchId)
            ->where('available_status', 'running')
            ->orderBy('table_code')
            ->get(['id', 'table_code'])
            ->map(static fn (Table $table): array => [
                'id' => $table->id,
                'table_code' => $table->table_code,
            ])
            ->values()
            ->all();

        $orders = Order::query()
            ->with('table:id,table_code')
            ->where('branch_id', $branchId)
            ->whereIn('status', ['billed', 'payment_due'])
            ->orderByDesc('id')
            ->get(['id', 'order_number', 'formatted_order_number', 'table_id', 'status', 'total'])
            ->map(static function (Order $order): array {
                return [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'show_formatted_order_number' => $order->show_formatted_order_number,
                    'table_code' => $order->table?->table_code,
                    'status' => (string) $order->status,
                    'total' => $order->total,
                ];
            })
            ->values()
            ->all();

        return [
            'tables' => $tables,
            'orders' => $orders,
        ];
    }
}
