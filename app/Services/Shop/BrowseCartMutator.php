<?php

namespace App\Services\Shop;

use App\Models\Branch;
use App\Models\MenuItem;
use App\Models\Restaurant;
use App\Scopes\AvailableMenuItemScope;
use Illuminate\Http\Request;
use App\Services\Pos\IngredientStockGuard;

class BrowseCartMutator
{
    public static function sessionKey(string $restaurantHash): string
    {
        return 'shop_browse_simple_cart_' . $restaurantHash;
    }

    /**
     * @return array{ok: bool, cart_item_qty?: array<string, int>, error?: string, error_code?: string}
     */
    public static function mutate(
        Restaurant $restaurant,
        Branch $branch,
        string $action,
        int $menuItemId,
        bool $canCreateOrder,
        bool $allowCustomerOrders,
        bool $cameFromQr,
        ?float $requestLat,
        ?float $requestLng,
    ): array {
        $action = strtolower($action);
        if (! in_array($action, ['add', 'inc', 'dec'], true)) {
            return ['ok' => false, 'error' => __('messages.notFound'), 'error_code' => 'invalid_action'];
        }

        if (! $canCreateOrder) {
            return ['ok' => false, 'error' => __('messages.CartAddPermissionDenied'), 'error_code' => 'no_order_module'];
        }

        if (! $allowCustomerOrders && in_array($action, ['add', 'inc'], true)) {
            return ['ok' => false, 'error' => __('messages.CartAddPermissionDenied'), 'error_code' => 'customer_orders_disabled'];
        }

        if (session('shop_table_qr_ordering_blocked') && in_array($action, ['add', 'inc'], true)) {
            return ['ok' => false, 'error' => __('messages.tableQrDineInDisabled'), 'error_code' => 'table_qr_dine_in_disabled'];
        }

        if (session('shop_room_qr_ordering_blocked') && in_array($action, ['add', 'inc'], true)) {
            return ['ok' => false, 'error' => __('messages.roomQrRoomServiceDisabled'), 'error_code' => 'room_qr_room_service_disabled'];
        }

        $radiusError = self::checkQrRadiusOrError($restaurant, $branch, $cameFromQr, $requestLat, $requestLng);
        if ($radiusError !== null) {
            return $radiusError;
        }

        if (in_array($action, ['add', 'inc'], true)) {
            $orderStats = getRestaurantOrderStats($branch->id);
            if (! branchOrderStatsAllowNewOrder($orderStats)) {
                return ['ok' => false, 'error' => __('messages.orderLimitReached'), 'error_code' => 'order_limit'];
            }
        }

        $menuItem = MenuItem::withoutGlobalScope(AvailableMenuItemScope::class)
            ->where('branch_id', $branch->id)
            ->where('show_on_customer_site', true)
            ->whereKey($menuItemId)
            ->withCount(['variations', 'modifierGroups'])
            ->first();

        if (! $menuItem) {
            return ['ok' => false, 'error' => __('messages.notFound'), 'error_code' => 'item_not_found'];
        }

        if (((int) ($menuItem->variations_count ?? 0)) > 0 || ((int) ($menuItem->modifier_groups_count ?? 0)) > 0) {
            return ['ok' => false, 'error' => __('messages.notFound'), 'error_code' => 'item_requires_livewire'];
        }

        if (! $menuItem->in_stock || ! ($menuItem->is_available ?? true)) {
            return ['ok' => false, 'error' => __('messages.notFound'), 'error_code' => 'out_of_stock'];
        }

        if (! IngredientStockGuard::resolveInStockForCatalog(
            $branch->id,
            (int) $menuItem->id,
            (bool) $menuItem->in_stock,
        ) || ! ($menuItem->is_available ?? true)) {
            return [
                'ok' => false,
                'error' => IngredientStockGuard::insufficientStockMessage(),
                'error_code' => 'out_of_stock',
            ];
        }

        $sessionKey = self::sessionKey($restaurant->hash);
        $map = session($sessionKey, []);
        if (! is_array($map)) {
            $map = [];
        }

        if (in_array($action, ['add', 'inc'], true)) {
            $current = (int) ($map[(string) $menuItemId] ?? 0);
            $nextQty = $action === 'add'
                ? ($current > 0 ? $current + 1 : 1)
                : ($current < 1 ? 1 : $current + 1);

            $stockResult = IngredientStockGuard::validateOrderLine(
                $branch->id,
                $menuItem->id,
                null,
                [],
                $nextQty,
            );

            if (! $stockResult['ok']) {
                return [
                    'ok' => false,
                    'error' => $stockResult['error'] ?? IngredientStockGuard::insufficientStockMessage(),
                    'error_code' => 'out_of_stock',
                ];
            }
        }

        $idKey = (string) $menuItemId;
        $current = (int) ($map[$idKey] ?? 0);

        if ($action === 'add') {
            $map[$idKey] = $current > 0 ? $current + 1 : 1;
        } elseif ($action === 'inc') {
            if ($current < 1) {
                $map[$idKey] = 1;
            } else {
                $map[$idKey] = $current + 1;
            }
        } else {
            if ($current <= 1) {
                unset($map[$idKey]);
            } else {
                $map[$idKey] = $current - 1;
            }
        }

        // Keep the key so Livewire can distinguish "browse cart cleared" from "never used browse API".
        session([$sessionKey => $map]);

        $out = [];
        foreach ($map as $k => $v) {
            $out[(string) $k] = (int) $v;
        }

        return ['ok' => true, 'cart_item_qty' => $out];
    }

    public static function validateBrowseCartRequest(Request $request): array
    {
        return $request->validate([
            'action' => ['required', 'string', 'in:add,inc,dec'],
            'menu_item_id' => ['required', 'integer', 'min:1'],
            'branch_id' => ['required', 'integer', 'min:1'],
            'came_from_qr' => ['sometimes', 'boolean'],
            'address_lat' => ['nullable', 'numeric'],
            'address_lng' => ['nullable', 'numeric'],
        ]);
    }

    /**
     * @return array{ok: false, error: string, error_code: string}|null
     */
    private static function checkQrRadiusOrError(
        Restaurant $restaurant,
        Branch $branch,
        bool $cameFromQr,
        ?float $requestLat,
        ?float $requestLng,
    ): ?array {
        if (! $cameFromQr || ! $restaurant->restrict_qr_order_by_location || empty($restaurant->qr_order_radius_meters)) {
            return null;
        }

        $lat = $requestLat;
        $lng = $requestLng;
        $sessionLocation = session('customer_location');
        if (($lat === null || $lng === null) && is_array($sessionLocation)) {
            $lat = isset($sessionLocation['lat']) ? (float) $sessionLocation['lat'] : null;
            $lng = isset($sessionLocation['lng']) ? (float) $sessionLocation['lng'] : null;
        }

        if ($lat === null || $lng === null) {
            return [
                'ok' => false,
                'error' => __('app.locationAccessRequired'),
                'error_code' => 'location_required',
            ];
        }

        if ($requestLat !== null && $requestLng !== null) {
            session([
                'customer_location' => [
                    'lat' => $requestLat,
                    'lng' => $requestLng,
                    'address' => is_array($sessionLocation) ? ($sessionLocation['address'] ?? null) : null,
                    'stored_at' => now()->toDateTimeString(),
                ],
            ]);
        }

        $branchLat = $branch->lat ?? $branch->latitude ?? null;
        $branchLng = $branch->lng ?? $branch->longitude ?? null;

        if (empty($branchLat) || empty($branchLng)) {
            return null;
        }

        $distance = self::haversineMeters($lat, $lng, (float) $branchLat, (float) $branchLng);
        if ($distance > (float) $restaurant->qr_order_radius_meters) {
            return [
                'ok' => false,
                'error' => __('app.outsideAllowedAreaMeters', ['meters' => $restaurant->qr_order_radius_meters]),
                'error_code' => 'outside_radius',
            ];
        }

        return null;
    }

    private static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0;
        $lat1 = deg2rad($lat1);
        $lng1 = deg2rad($lng1);
        $lat2 = deg2rad($lat2);
        $lng2 = deg2rad($lng2);
        $latDiff = $lat2 - $lat1;
        $lngDiff = $lng2 - $lng1;
        $a = sin($latDiff / 2) * sin($latDiff / 2)
            + cos($lat1) * cos($lat2) * sin($lngDiff / 2) * sin($lngDiff / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}
