<?php

namespace App\Services\Pos;

use App\Models\DeliveryPlatform;
use App\Models\RestaurantCharge;

/**
 * Builds POS order-type modal payloads (price maps, extra charges, etc.) for Blade and Livewire UIs.
 */
class PosOrderTypeClientData
{
    public static function orderTypePriceMapKey(int $orderTypeId, ?int $deliveryAppId): string
    {
        return $orderTypeId . '__' . ($deliveryAppId === null ? 'none' : (string) $deliveryAppId);
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, float>
     */
    public static function extractMenuItemPricesFromCatalogRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row['id']] = (float) $row['price'];
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderType>  $orderTypes
     * @param  \Illuminate\Support\Collection<int, DeliveryPlatform>  $deliveryPlatforms
     * @return array<string, array<string, float|int>>
     */
    public static function buildOrderTypePriceMaps(int $branchId, $orderTypes, $deliveryPlatforms): array
    {
        if ($orderTypes->isEmpty()) {
            return [];
        }

        $catalog = MenuItemsCatalogCache::getCatalogPayload($branchId);
        $itemsBase = $catalog['items'];
        $priceMaps = [];

        foreach ($orderTypes as $orderType) {
            if ($orderType->slug === 'delivery') {
                $appIds = [null];
                foreach ($deliveryPlatforms as $dp) {
                    $appIds[] = (int) $dp->id;
                }
                foreach ($appIds as $appId) {
                    $key = self::orderTypePriceMapKey((int) $orderType->id, $appId);
                    $priceMaps[$key] = self::extractMenuItemPricesFromCatalogRows(
                        MenuItemsCatalogCache::applyOrderContextToRows($itemsBase, $branchId, (int) $orderType->id, $appId)
                    );
                }
            } else {
                $key = self::orderTypePriceMapKey((int) $orderType->id, null);
                $priceMaps[$key] = self::extractMenuItemPricesFromCatalogRows(
                    MenuItemsCatalogCache::applyOrderContextToRows($itemsBase, $branchId, (int) $orderType->id, null)
                );
            }
        }

        return $priceMaps;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderType>  $orderTypes
     * @return array<string, mixed>
     */
    public static function buildExtraChargesBySlugForTypes($orderTypes): array
    {
        $bySlug = [];
        foreach ($orderTypes as $ot) {
            $bySlug[$ot->slug] = RestaurantCharge::whereJsonContains('order_types', $ot->slug)
                ->where('is_enabled', true)
                ->get()
                ->values();
        }

        return $bySlug;
    }

    public static function resolveDeliveryDefaultFee($branch): float
    {
        $deliverySettings = $branch->deliverySetting;
        if ($deliverySettings && $deliverySettings->is_enabled && $deliverySettings->fee_type->value === 'fixed') {
            return (float) ($deliverySettings->fixed_fee ?? 0);
        }

        return 0.0;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderType>  $orderTypes
     * @param  \Illuminate\Support\Collection<int, DeliveryPlatform>  $deliveryPlatforms
     * @return array{
     *     posOrderTypePriceMaps: array,
     *     posExtraChargesBySlug: array,
     *     posDeliveryDefaultFee: float,
     *     posOrderTypesForModal: array<int, array<string, mixed>>,
     *     posDeliveryPlatformsForModal: array<int, array<string, mixed>>,
     * }
     */
    public static function buildModalScriptPayload(int $branchId, $branch, $orderTypes, $deliveryPlatforms): array
    {
        $posOrderTypePriceMaps = self::buildOrderTypePriceMaps($branchId, $orderTypes, $deliveryPlatforms);
        $posExtraChargesBySlug = self::buildExtraChargesBySlugForTypes($orderTypes);
        $posDeliveryDefaultFee = self::resolveDeliveryDefaultFee($branch);
        $posOrderTypesForModal = $orderTypes->map(function ($ot) {
            return [
                'id' => (int) $ot->id,
                'slug' => $ot->slug,
                'type' => $ot->type,
                'order_type_name' => $ot->order_type_name,
            ];
        })->values()->all();
        $posDeliveryPlatformsForModal = $deliveryPlatforms->map(function (DeliveryPlatform $p) {
            return [
                'id' => (int) $p->id,
                'name' => $p->name,
                'logo_url' => $p->logo_url ?? null,
            ];
        })->values()->all();

        return compact(
            'posOrderTypePriceMaps',
            'posExtraChargesBySlug',
            'posDeliveryDefaultFee',
            'posOrderTypesForModal',
            'posDeliveryPlatformsForModal'
        );
    }

    /**
     * POS order-type UX: one non-delivery type → auto-apply and lock; delivery-only → platform picker; else modal.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\OrderType>  $orderTypes
     * @param  \Illuminate\Support\Collection<int, DeliveryPlatform>  $deliveryPlatforms
     * @return array{
     *     mode: string,
     *     shouldPromptModalOnLoad: bool,
     *     allowOrderTypeChange: bool,
     *     autoOrderTypeId: ?int,
     *     autoSlug: ?string,
     *     autoType: ?string,
     *     requiresDeliveryPlatform: bool,
     * }
     */
    public static function resolveSelectionPolicy($orderTypes, $deliveryPlatforms): array
    {
        $types = $orderTypes->values();
        $deliveryType = $types->firstWhere('slug', 'delivery');
        $nonDelivery = $types->filter(fn ($ot) => $ot->slug !== 'delivery')->values();

        if ($types->count() === 1 && $nonDelivery->count() === 1) {
            $only = $nonDelivery->first();

            return [
                'mode' => 'locked_single',
                'shouldPromptModalOnLoad' => false,
                'allowOrderTypeChange' => false,
                'autoOrderTypeId' => (int) $only->id,
                'autoSlug' => $only->slug,
                'autoType' => $only->type,
                'requiresDeliveryPlatform' => false,
            ];
        }

        if ($types->count() === 1 && $deliveryType) {
            return [
                'mode' => 'delivery_only',
                'shouldPromptModalOnLoad' => true,
                'allowOrderTypeChange' => true,
                'autoOrderTypeId' => (int) $deliveryType->id,
                'autoSlug' => 'delivery',
                'autoType' => $deliveryType->type,
                'requiresDeliveryPlatform' => true,
            ];
        }

        return [
            'mode' => 'choose',
            'shouldPromptModalOnLoad' => true,
            'allowOrderTypeChange' => true,
            'autoOrderTypeId' => null,
            'autoSlug' => null,
            'autoType' => null,
            'requiresDeliveryPlatform' => false,
        ];
    }
}
