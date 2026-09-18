<?php

namespace App\Http\Controllers;

use App\ApiResource\OrderResource;
use App\Models\Area;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryExecutive;
use App\Models\DeliveryPlatform;
use App\Models\Kot;
use App\Models\KotItem;
use App\Models\KotPlace;
use App\Models\MenuItem;
use App\Models\MenuItemVariation;
use App\Models\MultipleOrder;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderCharge;
use App\Models\OrderItem;
use App\Models\OrderTax;
use App\Models\OrderType;
use App\Models\Printer;
use App\Models\Reservation;
use App\Models\Restaurant;
use App\Models\RestaurantCharge;
use App\Models\Table;
use App\Models\TableSession;
use App\Models\Tax;
use App\Models\User;
use App\Enums\OrderStatus;
use App\Events\OrderTableAssigned;
use App\Events\OrderWaiterAssigned;
use App\Events\NewOrderCreated;
use App\Events\SendNewOrderReceived;
use App\Services\OrderWaiterResponseService;
use App\Events\SendOrderBillEvent;
use App\Services\Pos\MenuItemsCatalogCache;
use App\Services\Pos\PosBranchCacheInvalidation;
use App\Services\Pos\PosIngredientStockCache;
use App\Support\PosQuantity;
use App\Services\Pos\PosTaxonomyCache;
use App\Services\Pos\PosWaitersCache;
use App\Services\RestaurantAvailabilityService;
use App\Services\Pos\IngredientStockGuard;
use App\Services\Tables\TablesIndexCache;
use App\Support\DietaryLabels;
use App\Support\EuAnnexIiAllergens;
use App\Traits\PrinterSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PosAjaxController extends Controller
{
    use PrinterSetting;

    /** @var string Request attribute key for browser events (Livewire v3 has no Livewire::dispatch()) */
    private const BROWSER_DISPATCH_QUEUE_KEY = 'pos_ajax_browser_dispatches';

    private $branch;
    private $restaurant;

    public function __construct()
    {
        $this->branch = Branch::find(branch()->id);
        $this->restaurant = Restaurant::find(restaurant()->id);
    }

    /**
     * PrinterSetting trait calls $this->dispatch like a Livewire component.
     * Livewire v3 does not expose Livewire::dispatch(); queue events for the AJAX JSON response
     * and run them in JS via window.Livewire.dispatch (see pos.blade.php).
     */
    protected function dispatch($event, ...$params): void
    {
        $queue = request()->attributes->get(self::BROWSER_DISPATCH_QUEUE_KEY, []);
        $queue[] = ['name' => $event, 'params' => $params];
        request()->attributes->set(self::BROWSER_DISPATCH_QUEUE_KEY, $queue);
    }

    /**
     * @return array<int, array{name: string, params: array}>
     */
    protected function pullBrowserDispatches(): array
    {
        $queue = request()->attributes->get(self::BROWSER_DISPATCH_QUEUE_KEY, []);
        request()->attributes->set(self::BROWSER_DISPATCH_QUEUE_KEY, []);

        return is_array($queue) ? $queue : [];
    }

    protected function alert(string $type, string $message, array $options = []): void
    {
        Log::info('[PosAjaxController] print alert', [
            'type' => $type,
            'message' => $message,
        ]);
    }

    /**
     * Loyalty is an optional module. These helpers keep POS AJAX safe even when
     * the Loyalty module isn't installed/enabled in the current deployment.
     */
    private function isLoyaltyEnabledForPos(): bool
    {
        if (!function_exists('module_enabled') || !module_enabled('Loyalty')) {
            return false;
        }

        if (function_exists('restaurant_modules') && !in_array('Loyalty', restaurant_modules())) {
            return false;
        }

        // Prefer the dedicated POS loyalty handler so that per-platform
        // settings (points enabled/disabled specifically for POS) are respected.
        if (class_exists(\Modules\Loyalty\Services\PosLoyaltyHandler::class)) {
            $handler = new \Modules\Loyalty\Services\PosLoyaltyHandler(new \stdClass());

            // If points are not enabled for POS in Loyalty settings, treat
            // loyalty as disabled for this POS section.
            if (method_exists($handler, 'isPointsEnabledForPOS') && !$handler->isPointsEnabledForPOS()) {
                return false;
            }
        }

        // Fallback: basic module + settings existence check
        return class_exists(\Modules\Loyalty\Services\LoyaltyService::class)
            && class_exists(\Modules\Loyalty\Entities\LoyaltySetting::class);
    }

    /**
     * Get customer's loyalty tier redemption multiplier (1.0 if no tier or module).
     * Used so points redemption discount matches LoyaltyService and tt POS.
     */
    private function getTierRedemptionMultiplier(?int $restaurantId, ?int $customerId): float
    {
        if (!$restaurantId || !$customerId || !class_exists(\Modules\Loyalty\Entities\LoyaltyTier::class)) {
            return 1.0;
        }
        try {
            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $account = $loyaltyService->getOrCreateAccount($restaurantId, $customerId);
            if (!$account || !$account->tier_id) {
                return 1.0;
            }
            $tier = \Modules\Loyalty\Entities\LoyaltyTier::find($account->tier_id);
            return ($tier && $tier->redemption_multiplier > 0) ? (float) $tier->redemption_multiplier : 1.0;
        } catch (\Throwable $e) {
            return 1.0;
        }
    }

    /**
     * Shared loyalty points context (tt parity: same settings, tier, limits).
     * Returns null if loyalty disabled or settings missing; otherwise array with keys:
     * settings, availablePoints, valuePerPoint, minRedeemPoints, maxDiscountPercent,
     * tierMultiplier, effectiveValuePerPoint, maxLoyaltyDiscount, maxRedeemablePoints.
     */
    private function getLoyaltyPointsContext(int $customerId, float $subTotal): ?array
    {
        if (!$this->isLoyaltyEnabledForPos()) {
            return null;
        }
        $restaurantId = $this->restaurant?->id ?? (restaurant()->id ?? null);
        if (!$restaurantId) {
            return null;
        }
        $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
        if (!$settings || !$settings->isEnabled()) {
            return null;
        }
        $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
        $availablePoints = (int) $loyaltyService->getAvailablePoints($restaurantId, $customerId);
        $valuePerPoint = (float) ($settings->value_per_point ?? 1);
        $minRedeemPoints = (int) ($settings->min_redeem_points ?? 0);
        $maxDiscountPercent = (float) ($settings->max_discount_percent ?? 0);
        $tierMultiplier = $this->getTierRedemptionMultiplier($restaurantId, $customerId);
        // Keep points valuation aligned with LoyaltyService redemption behavior used at
        // order-finalization time, so AJAX preview/max points and final saved points match.
        $effectiveValuePerPoint = $valuePerPoint;
        $maxLoyaltyDiscount = ($subTotal > 0 && $maxDiscountPercent > 0)
            ? (($subTotal * $maxDiscountPercent) / 100)
            : 0;
        $maxByDiscount = ($effectiveValuePerPoint > 0 && $maxLoyaltyDiscount > 0)
            ? (int) floor($maxLoyaltyDiscount / $effectiveValuePerPoint)
            : 0;
        $maxRedeemablePoints = min($availablePoints, $maxByDiscount);
        if ($minRedeemPoints > 0 && $maxRedeemablePoints > 0) {
            $maxRedeemablePoints = (int) (floor($maxRedeemablePoints / $minRedeemPoints) * $minRedeemPoints);
        }
        return [
            'settings' => $settings,
            'restaurantId' => $restaurantId,
            'availablePoints' => $availablePoints,
            'valuePerPoint' => $valuePerPoint,
            'minRedeemPoints' => $minRedeemPoints,
            'maxDiscountPercent' => $maxDiscountPercent,
            'tierMultiplier' => $tierMultiplier,
            'effectiveValuePerPoint' => $effectiveValuePerPoint,
            'maxLoyaltyDiscount' => $maxLoyaltyDiscount,
            'maxRedeemablePoints' => $maxRedeemablePoints,
        ];
    }

    /**
     * Check if stamps are enabled for POS (mirrors PosLoyaltyHandler::isStampsEnabledForPOS).
     */
    private function isStampsEnabledForPos(?int $restaurantId): bool
    {
        if (!$restaurantId || !class_exists(\Modules\Loyalty\Entities\LoyaltySetting::class)) {
            return false;
        }
        $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
        if (!$settings || !($settings->enabled ?? false)) {
            return false;
        }
        $loyaltyType = (string) ($settings->loyalty_type ?? 'points');
        if (!in_array($loyaltyType, ['stamps', 'both'], true) || !(bool) ($settings->enable_stamps ?? true)) {
            return false;
        }
        if (isset($settings->enable_stamps_for_pos) && $settings->enable_stamps_for_pos !== null) {
            return (bool) $settings->enable_stamps_for_pos;
        }
        return (bool) ($settings->enable_for_pos ?? true);
    }

    /**
     * Common loyalty JSON error responses (DRY).
     */
    private function loyaltyErrorCustomerNotFound(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'message' => __('messages.customerNotFound')], 422);
    }

    private function loyaltyErrorModuleDisabled(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['success' => false, 'message' => __('messages.moduleNotEnabled')], 422);
    }

    /**
     * Return loyalty summary for POS customer selection.
     * Mostly used client-side; server validates settings/limits.
     */
    public function getLoyaltySummary(Request $request)
    {
        $customerId = (int) $request->input('customer_id');
        $subTotal = (float) $request->input('sub_total', 0);

        if (!$customerId) {
            return response()->json([
                'success' => false,
                'message' => __('messages.customerNotFound'),
            ], 422);
        }

        $emptySummary = [
            'success' => true,
            'enabled' => false,
            'available_points' => 0,
            'value_per_point' => 0,
            'min_redeem_points' => 0,
            'max_discount_percent' => 0,
            'max_loyalty_discount' => 0,
            'max_redeemable_points' => 0,
        ];

        try {
            $ctx = $this->getLoyaltyPointsContext($customerId, $subTotal);
            if ($ctx === null) {
                return response()->json($emptySummary);
            }

            return response()->json([
                'success' => true,
                'enabled' => true,
                'available_points' => $ctx['availablePoints'],
                'value_per_point' => $ctx['valuePerPoint'],
                'redemption_multiplier' => $ctx['tierMultiplier'],
                'min_redeem_points' => $ctx['minRedeemPoints'],
                'max_discount_percent' => $ctx['maxDiscountPercent'],
                'max_loyalty_discount' => round($ctx['maxLoyaltyDiscount'], 2),
                'max_redeemable_points' => $ctx['maxRedeemablePoints'],
            ]);
        } catch (\Exception $e) {
            Log::error('POS loyalty summary error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => __('messages.somethingWentWrong'),
            ], 500);
        }
    }

    /**
     * Validate and compute loyalty redemption result.
     * This does NOT deduct points; deduction happens during order processing in module logic.
     */
    public function redeemLoyaltyPoints(Request $request)
    {
        $customerId = (int) $request->input('customer_id');
        $subTotal = (float) $request->input('sub_total', 0);
        $requestedPoints = (int) $request->input('points', 0);

        if (!$customerId) {
            return $this->loyaltyErrorCustomerNotFound();
        }

        if (!$this->isLoyaltyEnabledForPos()) {
            return $this->loyaltyErrorModuleDisabled();
        }

        try {
            $ctx = $this->getLoyaltyPointsContext($customerId, $subTotal);
            if ($ctx === null) {
                return $this->loyaltyErrorModuleDisabled();
            }

            $minRedeemPoints = $ctx['minRedeemPoints'];
            $maxRedeemablePoints = $ctx['maxRedeemablePoints'];

            if ($requestedPoints <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => __('loyalty::app.enterPoints'),
                ], 422);
            }

            if ($minRedeemPoints > 0 && $requestedPoints < $minRedeemPoints) {
                return response()->json([
                    'success' => false,
                    'message' => __('loyalty::app.minPointsRequired', ['min_points' => $minRedeemPoints]),
                ], 422);
            }

            if ($minRedeemPoints > 0 && ($requestedPoints % $minRedeemPoints) !== 0) {
                return response()->json([
                    'success' => false,
                    'message' => __('loyalty::app.insufficientLoyaltyPointsAvailable', ['min' => $minRedeemPoints]),
                ], 422);
            }

            if ($requestedPoints > $maxRedeemablePoints) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.maxLimitReached'),
                ], 422);
            }

            $discountAmount = $requestedPoints * $ctx['effectiveValuePerPoint'];
            $discountAmount = min($discountAmount, $ctx['maxLoyaltyDiscount']);
            $discountAmount = round($discountAmount, 2);

            $actualPoints = $requestedPoints;
            if (
                $ctx['effectiveValuePerPoint'] > 0
                && $discountAmount < ($requestedPoints * $ctx['effectiveValuePerPoint']) - 0.001
            ) {
                $actualPoints = (int) floor($discountAmount / $ctx['effectiveValuePerPoint']);
                if ($minRedeemPoints > 0 && $actualPoints > 0) {
                    $actualPoints = (int) (floor($actualPoints / $minRedeemPoints) * $minRedeemPoints);
                    $discountAmount = round(
                        min($actualPoints * $ctx['effectiveValuePerPoint'], $ctx['maxLoyaltyDiscount']),
                        2
                    );
                }
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'points_redeemed' => $actualPoints,
                    'discount_amount' => $discountAmount,
                    'available_points' => $ctx['availablePoints'],
                    'value_per_point' => $ctx['valuePerPoint'],
                    'redemption_multiplier' => $ctx['tierMultiplier'],
                    'min_redeem_points' => $minRedeemPoints,
                    'max_redeemable_points' => $maxRedeemablePoints,
                    'max_loyalty_discount' => round($ctx['maxLoyaltyDiscount'], 2),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('POS loyalty redeem error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => __('messages.somethingWentWrong'),
            ], 500);
        }
    }

    public function resetLoyaltyRedemption()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'points_redeemed' => 0,
                'discount_amount' => 0,
            ],
        ]);
    }

    /**
     * Preview automatic stamp redemption for a cart item (tt parity for instant POS UI feedback).
     * Does not create ledger transactions; only returns what should be shown client-side.
     */
    public function getAutoStampPreview(Request $request)
    {
        $customerId = (int)$request->input('customer_id');
        $menuItemId = (int)$request->input('menu_item_id');
        $itemQty = max(1, (int)$request->input('quantity', 1));
        $unitPrice = (float)$request->input('unit_price', 0);

        if (!$customerId || !$menuItemId) {
            return response()->json([
                'success' => false,
                'message' => __('messages.invalidRequest'),
            ], 422);
        }

        if (
            !class_exists(\Modules\Loyalty\Entities\LoyaltyStampRule::class)
            || !method_exists(\Modules\Loyalty\Entities\LoyaltyStampRule::class, 'getRuleForMenuItem')
            || !class_exists(\Modules\Loyalty\Services\LoyaltyService::class)
        ) {
            return response()->json([
                'success' => true,
                'applied' => false,
            ]);
        }

        try {
            $restaurantRef = function_exists('restaurant') ? restaurant() : null;
            $restaurantId = $this->restaurant?->id
                ?? ((is_object($restaurantRef) && isset($restaurantRef->id)) ? $restaurantRef->id : null);
            if (!$restaurantId) {
                return response()->json([
                    'success' => true,
                    'applied' => false,
                ]);
            }

            if (!$this->isStampsEnabledForPos($restaurantId)) {
                return response()->json([
                    'success' => true,
                    'applied' => false,
                ]);
            }

            $stampRule = \Modules\Loyalty\Entities\LoyaltyStampRule::getRuleForMenuItem($restaurantId, $menuItemId);
            if (!$stampRule || !($stampRule->is_active ?? false)) {
                return response()->json([
                    'success' => true,
                    'applied' => false,
                ]);
            }

            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
            $availableStamps = (int)$loyaltyService->getAvailableStamps($restaurantId, $customerId, (int)$stampRule->id);
            $stampsRequired = max(1, (int)($stampRule->stamps_required ?? 1));

            if ($availableStamps < $stampsRequired) {
                return response()->json([
                    'success' => true,
                    'applied' => false,
                    'rule_id' => (int)$stampRule->id,
                ]);
            }

            $eligibleQty = (int)floor($availableStamps / $stampsRequired);
            $appliedQty = min($itemQty, $eligibleQty);

            if ($appliedQty <= 0) {
                return response()->json([
                    'success' => true,
                    'applied' => false,
                    'rule_id' => (int)$stampRule->id,
                ]);
            }

            $response = [
                'success' => true,
                'applied' => true,
                'rule_id' => (int)$stampRule->id,
                'reward_type' => (string)($stampRule->reward_type ?? ''),
                'stamps_required' => $stampsRequired,
                'available_stamps' => $availableStamps,
                'eligible_qty' => $eligibleQty,
                'applied_qty' => $appliedQty,
                'free_item_note' => (string)__('loyalty::app.freeItemFromStamp'),
            ];

            if (($stampRule->reward_type ?? '') === 'free_item' && $stampRule->rewardMenuItem) {
                $rewardItem = $stampRule->rewardMenuItem;
                $rewardVariation = $stampRule->rewardMenuItemVariation;
                $rewardPrice = (float)($rewardItem->price ?? 0);
                $rewardVariationId = null;

                if ($stampRule->reward_menu_item_variation_id && $rewardVariation) {
                    $rewardVariationId = (int)$rewardVariation->id;
                    $rewardPrice = (float)($rewardVariation->price ?? $rewardPrice);
                }

                $response['reward_item'] = [
                    'id' => (int)$rewardItem->id,
                    'item_name' => (string)($rewardItem->item_name ?? ''),
                    'name' => (string)($rewardItem->item_name ?? ''),
                    'price' => $rewardPrice,
                    'is_free_item_from_stamp' => true,
                    'stamp_rule_id' => (int)$stampRule->id,
                ];
                $response['reward_variation'] = $rewardVariationId ? [
                    'id' => $rewardVariationId,
                    'price' => $rewardPrice,
                ] : null;
            } elseif (in_array($stampRule->reward_type, ['discount_percent', 'discount_amount'], true)) {
                $tierMultiplier = $this->getTierRedemptionMultiplier($restaurantId, $customerId);
                $discountPerUnit = 0.0;
                if ($stampRule->reward_type === 'discount_percent') {
                    $discountPerUnit = (($unitPrice * (float)$stampRule->reward_value) / 100) * $tierMultiplier;
                } else {
                    $discountPerUnit = min((float)$stampRule->reward_value * $tierMultiplier, $unitPrice);
                }
                $response['preview_discount_amount'] = round(max(0, $discountPerUnit * $appliedQty), 2);
            }

            return response()->json($response);
        } catch (\Throwable $e) {
            Log::error('POS auto stamp preview failed: ' . $e->getMessage(), [
                'customer_id' => $customerId,
                'menu_item_id' => $menuItemId,
            ]);

            return response()->json([
                'success' => true,
                'applied' => false,
            ]);
        }
    }

    /**
     * Save default order type preference (JS/AJAX equivalent of SaaS OrderTypeSelection Livewire flow).
     */
    public function saveDefaultOrderTypePreference(Request $request)
    {
        $request->validate([
            'order_type_id' => 'required|integer',
        ]);

        $orderTypeId = (int)$request->input('order_type_id');

        $orderType = OrderType::where('id', $orderTypeId)
            ->where('is_active', true)
            ->when($this->branch, function ($q) {
                $q->where('branch_id', $this->branch->id);
            })
            ->first();

        if (!$orderType) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.noOrderTypesAvailable'),
            ], 404);
        }

        $restaurant = $this->restaurant;

        if (!$restaurant) {
            return response()->json([
                'success' => false,
                'message' => __('messages.unauthorized'),
            ], 401);
        }

        $restaurant->default_order_type_id = $orderType->id;
        $restaurant->disable_order_type_popup = true;
        $restaurant->save();

        // Clear cached restaurant session data so next load respects the preference
        session()->forget('restaurant');

        return response()->json([
            'success' => true,
            'message' => __('modules.order.orderTypeSetTo', ['type' => $orderType->order_type_name]),
        ]);
    }

    public function getMenus()
    {
        $branchId = (int) $this->branch->id;
        $menus = PosTaxonomyCache::rememberMenus(
            $branchId,
            fn () => PosTaxonomyCache::buildMenusPayload($branchId)
        );

        return response()->json($menus);
    }

    public function getCategories(Request $request)
    {
        $menuId = $request->input('menu_id');
        $search = (string) $request->input('search', '');
        $branchId = (int) $this->branch->id;

        $categories = PosTaxonomyCache::rememberCategories(
            $branchId,
            $menuId,
            $search,
            fn () => PosTaxonomyCache::buildCategoriesPayload($branchId, $menuId, $search)
        );

        return response()->json($categories);
    }

    public function getMenuItems(Request $request)
    {
        $loadAll = $request->boolean('load_all');
        $menuId = $loadAll ? null : $request->input('menu_id');
        $categoryId = $loadAll ? null : $request->input('category_id');
        $search = $loadAll ? '' : $request->input('search', '');
        $limit = $loadAll ? MenuItemsCatalogCache::CATALOG_LIMIT : (int) $request->input('limit', 48);
        $limit = min(max($limit, 1), MenuItemsCatalogCache::CATALOG_LIMIT);
        $orderTypeId = $request->input('order_type_id');
        $deliveryAppId = $request->input('delivery_app_id');
        $normalizedDeliveryAppId = ($deliveryAppId === 'default' || !$deliveryAppId) ? null : (int) $deliveryAppId;

        if ($loadAll) {
            $catalog = MenuItemsCatalogCache::getCatalogPayload($this->branch->id);
            $items = $catalog['items'];
            $totalCount = $catalog['total_count'];

            if ($orderTypeId) {
                $items = MenuItemsCatalogCache::applyOrderContextToRows(
                    $items,
                    $this->branch->id,
                    (int) $orderTypeId,
                    $normalizedDeliveryAppId
                );
            }

            return response()->json([
                'success' => true,
                'items' => $items,
                'total_count' => $totalCount,
                'loaded_count' => count($items),
                'ingredient_stock' => PosIngredientStockCache::getPayload($this->branch->id),
            ]);
        }

        // Build query
        $query = MenuItem::where('branch_id', $this->branch->id);

        if ($menuId) {
            $query->where('menu_id', $menuId);
        }

        if ($categoryId) {
            $query->where('item_category_id', $categoryId);
        }

        $query->matchesSearch($search);

        $totalCount = $query->count();

        $menuItems = $query->with(['taxes:id,tax_name,tax_percent'])
            ->withCount(['variations', 'modifierGroups'])
            ->limit($limit)
            ->get();

        // Apply price context based on order type
        if ($orderTypeId) {
            $menuItems->load([
                'prices' => function ($query) {
                    $query->select(['id', 'menu_item_id', 'order_type_id', 'delivery_app_id', 'menu_item_variation_id', 'final_price', 'status'])
                        ->where('status', true);
                }
            ]);
            foreach ($menuItems as $menuItem) {
                $menuItem->setPriceContext($orderTypeId, $normalizedDeliveryAppId);
            }
        }

        $restaurantSelectable = $this->restaurant?->selectableEuAllergenKeys() ?? [];
        $appendEuAllergens = $restaurantSelectable !== [];

        $items = $menuItems->map(function ($menuItem) use ($appendEuAllergens, $restaurantSelectable) {
            $euAllergenKeys = [];
            if ($appendEuAllergens) {
                $euAllergenKeys = array_values(array_unique(array_intersect(
                    EuAnnexIiAllergens::keys(),
                    $restaurantSelectable,
                    array_filter((array) ($menuItem->eu_allergen_keys ?? []), 'is_string')
                )));
            }

            $dietaryLabels = DietaryLabels::normalize(
                is_array($menuItem->dietary_labels ?? null) ? $menuItem->dietary_labels : []
            );

            return [
                'id' => $menuItem->id,
                'menu_id' => $menuItem->menu_id,
                'item_category_id' => $menuItem->item_category_id,
                'item_name' => $menuItem->item_name,
                'price' => (float) $menuItem->price,
                'item_photo_url' => $menuItem->item_photo_url,
                'type' => $menuItem->type,
                'in_stock' => IngredientStockGuard::resolveInStockForCatalog(
                    (int) $this->branch->id,
                    (int) $menuItem->id,
                    (bool) $menuItem->in_stock,
                ),
                'sell_by_weight' => (bool) ($menuItem->sell_by_weight ?? false),
                'variations_count' => (int) ($menuItem->variations_count ?? 0),
                'modifier_groups_count' => (int) ($menuItem->modifier_groups_count ?? 0),
                'eu_allergen_keys' => $euAllergenKeys,
                'dietary_labels' => $dietaryLabels,
                'taxes' => collect($menuItem->taxes ?? [])->map(function ($tax) {
                    return [
                        'id' => $tax->id,
                        'tax_name' => $tax->tax_name,
                        'tax_percent' => (float) $tax->tax_percent,
                    ];
                })->values()->all(),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'items' => $items,
            'total_count' => $totalCount,
            'loaded_count' => $items->count()
        ]);
    }

    /**
     * Hotel module: fetch active stays for room-service POS (AJAX POS only).
     */
    public function getHotelStays(Request $request)
    {
        if (!function_exists('module_enabled') || !module_enabled('Hotel')) {
            return response()->json(['success' => false, 'stays' => []]);
        }

        try {
            if (!class_exists(\Modules\Hotel\Entities\Stay::class)) {
                return response()->json(['success' => false, 'stays' => []]);
            }

            $branchId = $this->branch?->id ?? null;
            $search = trim((string)$request->input('search', ''));

            $statuses = $this->resolveHotelStaySelectableStatuses();
            $query = \Modules\Hotel\Entities\Stay::withoutGlobalScopes()
                ->with(['room.roomType', 'stayGuests.guest'])
                ->whereIn('status', $statuses);

            if ($search !== '') {
                $query->where(function ($q) use ($search) {
                    $q->whereHas('room', function ($qr) use ($search) {
                        $qr->where('room_number', 'like', '%' . $search . '%');
                    })->orWhere('stay_number', 'like', '%' . $search . '%');
                });
            }

            $stays = $query->limit(50)->get()->map(function ($stay) {
                $primaryGuest = null;
                if ($stay->relationLoaded('stayGuests') && $stay->stayGuests->isNotEmpty()) {
                    $primaryGuest = $stay->stayGuests->where('is_primary', true)->first() ?? $stay->stayGuests->first();
                }

                return [
                    'id' => $stay->id,
                    'room_number' => optional($stay->room)->room_number,
                    'stay_number' => $stay->stay_number,
                    'guest_name' => $primaryGuest && $primaryGuest->guest ? $primaryGuest->guest->full_name : null,
                ];
            });

            return response()->json([
                'success' => true,
                'stays' => $stays,
            ]);
        } catch (\Throwable $e) {
            \Log::error('POS AJAX getHotelStays failed: ' . $e->getMessage());
            return response()->json(['success' => false, 'stays' => []], 500);
        }
    }

    /**
     * Hotel module: full room list for POS picker (all rooms + active stay when present).
     * Lets staff see every room at once; rows without a matching stay are shown disabled.
     */
    public function getHotelRoomPickerList(Request $request)
    {
        if (!function_exists('module_enabled') || !module_enabled('Hotel')) {
            return response()->json(['success' => false, 'items' => []]);
        }

        if (!class_exists(\Modules\Hotel\Entities\Stay::class)) {
            return response()->json(['success' => false, 'items' => []]);
        }

        $branchId = (int) ($this->branch?->id ?? 0);
        if ($branchId < 1) {
            return response()->json(['success' => false, 'items' => []]);
        }

        try {
            $statuses = $this->resolveHotelStaySelectableStatuses();

            if (class_exists(\Modules\Hotel\Entities\Room::class)) {
                $items = $this->buildHotelRoomPickerItemsFromEloquentRooms($branchId, $statuses);
            } elseif (Schema::hasTable('hotel_rooms')) {
                $items = $this->buildHotelRoomPickerItemsFromDatabaseRooms($branchId, $statuses);
            } else {
                $items = $this->buildHotelRoomPickerItemsStaysOnly($branchId, $statuses);
            }

            return response()->json([
                'success' => true,
                'items' => $items,
                'fetched_at' => now()->toIso8601String(),
            ]);
        } catch (\Throwable $e) {
            Log::error('POS AJAX getHotelRoomPickerList failed: ' . $e->getMessage());

            return response()->json(['success' => false, 'items' => []], 500);
        }
    }

    /**
     * @return array<int, string>
     */
    private function resolveHotelStaySelectableStatuses(): array
    {
        $out = [];
        if (class_exists(\Modules\Hotel\Enums\StayStatus::class) && enum_exists(\Modules\Hotel\Enums\StayStatus::class)) {
            $allowedNames = ['CHECKED_IN', 'CHECK_IN', 'IN_HOUSE', 'OCCUPIED'];
            foreach (\Modules\Hotel\Enums\StayStatus::cases() as $case) {
                if (in_array($case->name, $allowedNames, true)) {
                    $out[] = $case instanceof \BackedEnum ? (string) $case->value : $case->name;
                }
            }
        }

        $out = array_values(array_unique(array_filter($out)));
        if ($out === []) {
            $out = ['checked_in', 'CHECKED_IN'];
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function buildHotelRoomPickerItemsFromEloquentRooms(int $branchId, array $statuses): array
    {
        $roomClass = \Modules\Hotel\Entities\Room::class;

        $rooms = $roomClass::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->with(['roomType'])
            ->orderBy('room_number')
            ->get();

        if ($rooms->isEmpty() && $this->restaurant && Schema::hasColumn('hotel_rooms', 'restaurant_id')) {
            $rooms = $roomClass::withoutGlobalScopes()
                ->where('restaurant_id', (int) $this->restaurant->id)
                ->with(['roomType'])
                ->orderBy('room_number')
                ->get();
        }

        $roomIds = $rooms->pluck('id')->all();
        $staysByRoomId = collect();
        if ($roomIds !== []) {
            $staysByRoomId = \Modules\Hotel\Entities\Stay::withoutGlobalScopes()
                ->whereIn('room_id', $roomIds)
                ->whereIn('status', $statuses)
                ->with(['stayGuests.guest'])
                ->orderByDesc('id')
                ->get()
                ->unique('room_id')
                ->keyBy('room_id');
        }

        $rows = [];
        foreach ($rooms as $room) {
            $stay = $staysByRoomId->get($room->id);
            $rows[] = $this->formatHotelRoomPickerRowFromRoomAndStay($room, $stay);
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function buildHotelRoomPickerItemsFromDatabaseRooms(int $branchId, array $statuses): array
    {
        $q = DB::table('hotel_rooms');
        if (Schema::hasColumn('hotel_rooms', 'branch_id')) {
            $q->where('branch_id', $branchId);
        } elseif (Schema::hasColumn('hotel_rooms', 'restaurant_id') && $this->restaurant) {
            $q->where('restaurant_id', (int) $this->restaurant->id);
        }

        $orderCol = Schema::hasColumn('hotel_rooms', 'room_number') ? 'room_number' : (Schema::hasColumn('hotel_rooms', 'number') ? 'number' : 'id');
        $rooms = $q->orderBy($orderCol)->get();

        if ($rooms->isEmpty() && Schema::hasColumn('hotel_rooms', 'restaurant_id') && $this->restaurant) {
            $rooms = DB::table('hotel_rooms')
                ->where('restaurant_id', (int) $this->restaurant->id)
                ->orderBy($orderCol)
                ->get();
        }

        $roomIds = $rooms->pluck('id')->map(fn ($id) => (int) $id)->all();
        $staysByRoomId = collect();
        if ($roomIds !== [] && Schema::hasTable('hotel_stays')) {
            $staysByRoomId = DB::table('hotel_stays')
                ->whereIn('room_id', $roomIds)
                ->whereIn('status', $statuses)
                ->get()
                ->keyBy('room_id');
        }

        $rows = [];
        foreach ($rooms as $room) {
            $rid = (int) $room->id;
            $stayRow = $staysByRoomId->get($rid);
            $stay = null;
            if ($stayRow) {
                $stay = \Modules\Hotel\Entities\Stay::query()
                    ->with(['stayGuests.guest'])
                    ->find((int) $stayRow->id);
            }

            $roomObj = new \stdClass;
            $roomObj->id = $rid;
            $roomObj->room_number = $room->room_number ?? $room->number ?? '';
            $roomObj->roomType = null;
            if (isset($room->room_type_id) && Schema::hasTable('hotel_room_types')) {
                $rt = DB::table('hotel_room_types')->where('id', $room->room_type_id)->first();
                if ($rt) {
                    $typeObj = new \stdClass;
                    $typeObj->name = $rt->name ?? $rt->type_name ?? null;
                    $roomObj->roomType = $typeObj;
                }
            }

            $rows[] = $this->formatHotelRoomPickerRowFromRoomAndStay($roomObj, $stay);
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $statuses
     * @return array<int, array<string, mixed>>
     */
    private function buildHotelRoomPickerItemsStaysOnly(int $branchId, array $statuses): array
    {
        $stays = \Modules\Hotel\Entities\Stay::withoutGlobalScopes()
            ->whereIn('status', $statuses)
            ->whereHas('room', function ($q) use ($branchId) {
                $q->where('branch_id', $branchId);
            })
            ->with(['room.roomType', 'stayGuests.guest'])
            ->get();

        if ($stays->isEmpty() && $this->restaurant && Schema::hasColumn('hotel_rooms', 'restaurant_id')) {
            $restaurantId = (int) $this->restaurant->id;
            $stays = \Modules\Hotel\Entities\Stay::withoutGlobalScopes()
                ->whereIn('status', $statuses)
                ->whereHas('room', function ($q) use ($restaurantId) {
                    $q->where('restaurant_id', $restaurantId);
                })
                ->with(['room.roomType', 'stayGuests.guest'])
                ->get();
        }

        $rows = [];
        foreach ($stays as $stay) {
            $room = $stay->room;
            if (!$room) {
                continue;
            }
            $rows[] = $this->formatHotelRoomPickerRowFromRoomAndStay($room, $stay);
        }

        usort($rows, function ($a, $b) {
            return strcmp((string) ($a['room_number'] ?? ''), (string) ($b['room_number'] ?? ''));
        });

        return $rows;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model|object  $room
     * @param  \Illuminate\Database\Eloquent\Model|null  $stay
     * @return array<string, mixed>
     */
    private function formatHotelRoomPickerRowFromRoomAndStay(object $room, $stay): array
    {
        $roomNumber = '';
        if (isset($room->room_number)) {
            $roomNumber = (string) $room->room_number;
        } elseif (isset($room->number)) {
            $roomNumber = (string) $room->number;
        }

        $typeName = null;
        if (isset($room->roomType) && $room->roomType) {
            $rt = $room->roomType;
            $typeName = $rt->name ?? $rt->type_name ?? null;
            if ($typeName !== null) {
                $typeName = (string) $typeName;
            }
        }

        $row = [
            'room_id' => (int) $room->id,
            'room_number' => $roomNumber,
            'room_type_name' => $typeName,
            'stay_id' => null,
            'stay_number' => null,
            'guest_name' => null,
            'selectable' => false,
        ];

        if ($stay) {
            $row['stay_id'] = (int) $stay->id;
            $row['stay_number'] = (string) ($stay->stay_number ?? '');
            $row['guest_name'] = $this->primaryGuestNameFromStayModel($stay);
            $row['selectable'] = true;
        }

        return $row;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Model  $stay
     */
    private function primaryGuestNameFromStayModel($stay): ?string
    {
        try {
            if (!$stay->relationLoaded('stayGuests')) {
                $stay->load(['stayGuests.guest']);
            }
            if ($stay->stayGuests && $stay->stayGuests->isNotEmpty()) {
                $primaryGuest = $stay->stayGuests->where('is_primary', true)->first() ?? $stay->stayGuests->first();
                if ($primaryGuest && $primaryGuest->guest) {
                    return $primaryGuest->guest->full_name ?? null;
                }
            }
        } catch (\Throwable $e) {
        }

        return null;
    }

    public function getWaiters()
    {
        $waiters = PosWaitersCache::remember((int) $this->restaurant->id, (int) $this->branch->id);
        $waiters = PosWaitersCache::forPosActor($waiters, auth()->user(), (int) $this->restaurant->id);

        return response()->json($waiters);
    }

    public function getCustomers(Request $request)
    {
        $searchQuery = $request->query('search', '');

        $query = Customer::where('restaurant_id', $this->restaurant->id);

        if (!empty($searchQuery) && strlen($searchQuery) >= 2) {
            $query->where(function ($q) use ($searchQuery) {
                $q->where('name', 'like', '%' . $searchQuery . '%')
                    ->orWhere('phone', 'like', '%' . $searchQuery . '%')
                    ->orWhere('email', 'like', '%' . $searchQuery . '%');
            });
        }

        $customers = $query->orderBy('name')->limit(10)->get();

        return response()->json($customers);
    }

    public function getCustomer(int $id)
    {
        $customer = Customer::where('restaurant_id', $this->restaurant->id)->find($id);

        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => __('messages.customerNotFound'),
            ], 404);
        }

        return response()->json([
            'success' => true,
            'customer' => $customer,
        ]);
    }

    public function getPhoneCodes(Request $request)
    {
        $search = $request->query('search', '');

        $phoneCodes = \App\Models\Country::pluck('phonecode')
            ->unique()
            ->filter()
            ->values();

        if (!empty($search)) {
            $phoneCodes = $phoneCodes->filter(function ($code) use ($search) {
                return str_contains($code, $search);
            })->values();
        }

        return response()->json($phoneCodes);
    }

    public function saveCustomer(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone_code' => 'required',
            'phone' => 'required',
            'email' => 'nullable|email',
            'address' => 'nullable|string|max:500',
        ]);

        // Check for existing customer by email or phone
        $existingCustomer = null;

        if (!empty($validated['email'])) {
            $existingCustomer = Customer::where('restaurant_id', $this->restaurant->id)
                ->where('email', $validated['email'])
                ->first();
        }

        if (!$existingCustomer && !empty($validated['phone'])) {
            $existingCustomer = Customer::where('restaurant_id', $this->restaurant->id)
                ->where('phone', $validated['phone'])
                ->first();
        }

        $customerData = [
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'phone_code' => $validated['phone_code'],
            'email' => $validated['email'] ?? null,
            'delivery_address' => $validated['address'] ?? null,
        ];

        // Update existing customer or create new one
        if ($existingCustomer) {
            $customer = tap($existingCustomer)->update($customerData);
        } else {
            $customerData['restaurant_id'] = $this->restaurant->id;
            $customer = Customer::create($customerData);
        }

        // Clear cache
        cache()->forget('customers_' . $this->branch->id);

        return response()->json([
            'success' => true,
            'message' => $existingCustomer ? __('messages.customerUpdated') : __('messages.customerAdded'),
            'customer' => $customer,
        ]);
    }

    public function assignCustomerToOrder(Request $request, int $orderId)
    {
        $validated = $request->validate([
            'customer_id' => 'required|integer',
            'delivery_address' => 'nullable|string|max:500',
        ]);

        $customer = Customer::where('restaurant_id', $this->restaurant->id)->find($validated['customer_id']);
        if (!$customer) {
            return response()->json([
                'success' => false,
                'message' => __('messages.customerNotFound'),
            ], 404);
        }

        // Orders belong to a branch (BranchScope), not restaurant_id on orders table.
        $order = Order::where('id', $orderId)->first();

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('messages.orderNotFound'),
            ], 404);
        }

        $order->customer_id = $customer->id;
        $order->delivery_address = $validated['delivery_address'] ?? $order->delivery_address;
        $order->save();

        return response()->json([
            'success' => true,
            'message' => __('messages.customerAdded'),
            'customer' => $customer,
            'order_id' => $order->id,
        ]);
    }

    public function getExtraCharges($orderType)
    {
        $extraCharges = RestaurantCharge::whereJsonContains('order_types', $orderType)
            ->where('is_enabled', true)
            ->where('restaurant_id', $this->restaurant->id)
            ->get();

        return response()->json($extraCharges);
    }

    public function getTables()
    {
        // First cleanup expired locks
        Table::cleanupExpiredLocks();

        $user = auth()->user();
        $userId = $user ? $user->id : null;
        $isAdmin = $user ? $user->hasRole('Admin_' . $user->restaurant_id) : false;

        $occupiedPaxByTable = Order::where('branch_id', $this->branch->id)
            ->whereNotNull('table_id')
            ->occupyingTableSeats()
            ->selectRaw('table_id, SUM(number_of_pax) as occupied_pax')
            ->groupBy('table_id')
            ->pluck('occupied_pax', 'table_id');

        $activeOrderIdsByTable = Order::activeOrderIdsByTableForBranch((int) $this->branch->id);

        $tables = Table::where('branch_id', $this->branch->id)
            ->where('status', 'active')
            ->with(['area', 'tableSession.lockedByUser'])
            ->get()
            ->map(function ($table) use ($userId, $occupiedPaxByTable, $activeOrderIdsByTable) {
                $session = $table->tableSession;
                $isLocked = $session ? $session->isLocked() : false;
                $isLockedByCurrentUser = $isLocked && $session && $session->locked_by_user_id === $userId;
                $isLockedByOtherUser = $isLocked && $session && $session->locked_by_user_id !== $userId;
                $seatCap = (int) ($table->seating_capacity ?? 0);
                $occupiedPax = (int) ($occupiedPaxByTable[$table->id] ?? 0);
                $isSeatBlocked = $seatCap > 0 && $occupiedPax >= $seatCap;
                $activeOrderId = isset($activeOrderIdsByTable[$table->id])
                    ? (int) $activeOrderIdsByTable[$table->id]
                    : null;
                $hasActiveOrder = $activeOrderId !== null;

                return [
                    'id' => $table->id,
                    'branch_id' => $table->branch_id,
                    'table_code' => $table->table_code,
                    'hash' => $table->hash,
                    'status' => $table->status,
                    'available_status' => $table->available_status,
                    'area_id' => $table->area_id,
                    'area_name' => $table->area ? $table->area->area_name : 'Unknown Area',
                    'seating_capacity' => $table->seating_capacity,
                    'occupied_pax' => $occupiedPax,
                    'seats_left' => $seatCap > 0 ? max($seatCap - $occupiedPax, 0) : null,
                    'is_seat_blocked' => $isSeatBlocked,
                    'has_active_order' => $hasActiveOrder,
                    'active_order_id' => $activeOrderId,
                    'is_locked' => $isLocked,
                    'is_locked_by_current_user' => $isLockedByCurrentUser,
                    'is_locked_by_other_user' => $isLockedByOtherUser,
                    'locked_by_user_id' => $session ? $session->locked_by_user_id : null,
                    'locked_by_user_name' => $session && $session->lockedByUser ? $session->lockedByUser->name : null,
                    'locked_at' => $session && $session->locked_at ? $session->locked_at->format('H:i') : null,
                    'created_at' => $table->created_at,
                    'updated_at' => $table->updated_at,
                ];
            });

        return response()->json([
            'tables' => $tables,
            'is_admin' => $isAdmin,
        ]);
    }

    public function getTodayReservations()
    {
        $restaurant = $this->branch->restaurant ?? null;
        $dateFormat = $restaurant->date_format ?? dateFormat();
        $timeFormat = $restaurant->time_format ?? timeFormat();

        $reservations = Reservation::where('branch_id', $this->branch->id)
            ->whereDate('reservation_date_time', today())
            ->whereNotNull('table_id')
            ->with('table')
            ->get()
            ->map(function ($reservation) use ($dateFormat, $timeFormat) {
                return [
                    'id' => $reservation->id,
                    'table_code' => $reservation->table ? $reservation->table->table_code : 'N/A',
                    'time' => $reservation->reservation_date_time->translatedFormat($timeFormat),
                    'datetime' => $reservation->reservation_date_time->displayDateTime($dateFormat, $timeFormat),
                    'date' => $reservation->reservation_date_time->displayDate($dateFormat),
                    'party_size' => $reservation->party_size,
                    'status' => $reservation->reservation_status,
                ];
            });
        return response()->json($reservations);
    }

    public function forceUnlockTable($tableId)
    {
        $table = Table::find($tableId);

        if (!$table) {
            return response()->json([
                'success' => false,
                'message' => __('messages.tableNotFound'),
            ], 404);
        }

        $user = auth()->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => __('messages.unauthorized'),
            ], 401);
        }

        $isAdmin = $user->hasRole('Admin_' . $user->restaurant_id);
        $isLockedByCurrentUser = $table->tableSession && $table->tableSession->locked_by_user_id === $user->id;

        if (!($isAdmin || $isLockedByCurrentUser)) {
            return response()->json([
                'success' => false,
                'message' => __('messages.tableUnlockFailed'),
            ], 403);
        }

        $result = $table->unlock(null, true);

        if ($result['success']) {
            return response()->json([
                'success' => true,
                'message' => __('messages.tableUnlockedSuccess', ['table' => $table->table_code]),
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => __('messages.tableUnlockFailed'),
            ], 500);
        }
    }

    public function getOrderTypes()
    {
        $orderTypes = OrderType::availableForRestaurant()
            ->where('branch_id', $this->branch->id)
            ->where('is_active', true)
            ->orderBy('order_type_name')
            ->get()
            ->map(function ($orderType) {
                return [
                    'id' => $orderType->id,
                    'slug' => $orderType->slug,
                    'order_type_name' => $orderType->translated_name,
                    'type' => $orderType->type,
                ];
            });

        return response()->json($orderTypes);
    }

    public function getDeliveryPlatforms()
    {
        $deliveryPlatforms = DeliveryPlatform::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(function ($platform) {
                return [
                    'id' => $platform->id,
                    'name' => $platform->name,
                    'logo' => $platform->logo,
                    'logo_url' => $platform->logo_url ?? null,
                ];
            });

        return response()->json($deliveryPlatforms);
    }

    public function getOrderNumber()
    {
        $orderNumberData = Order::generateOrderNumber($this->branch);

        $formattedOrderNumber = isOrderPrefixEnabled($this->branch)
            ? $orderNumberData['formatted_order_number']
            : __('modules.order.orderNumber') . ' #' . $orderNumberData['order_number'];

        // Return as array format: [order_number, formatted_order_number]
        return response()->json([
            $orderNumberData['order_number'],
            $formattedOrderNumber,
        ]);
    }

    public function submitOrder(Request $request)
    {
        try {
            DB::beginTransaction();

            // Get request data
            $data = $request->all();
            $orderId = $data['order_id'] ?? null;
            $customerData = $data['customer'] ?? [];
            $items = $data['items'] ?? [];
            $taxes = $data['taxes'] ?? [];
            $actions = $data['actions'] ?? [];
            if (in_array('bill', $actions, true) && !user_can('Bill and Payment')) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.permissionDenied'),
                ], 403);
            }
            $note = $data['note'] ?? ($data['order_note'] ?? '');
            $orderTypeDisplay = $data['order_type'] ?? 'Dine In';
            $orderNumber = $data['order_number'] ?? '';
            $pax = $data['pax'] ?? 1;
            $waiterRaw = $data['waiter_id'] ?? null;
            $waiterId = is_numeric($waiterRaw) ? (int) $waiterRaw : null;
            $posWaitersForActor = PosWaitersCache::forPosActor(
                PosWaitersCache::remember((int) $this->restaurant->id, (int) $this->branch->id),
                auth()->user(),
                (int) $this->restaurant->id
            );
            $waiterId = PosWaitersCache::normalizeWaiterSelection(
                $waiterId,
                auth()->user(),
                (int) $this->restaurant->id,
                $posWaitersForActor
            );
            $rawTableId = $data['table_id'] ?? null;
            $tableId = (is_numeric($rawTableId) && (int) $rawTableId > 0)
                ? (int) $rawTableId
                : null;
            $discountType = $data['discount_type'] ?? null;
            $discountValue = $data['discount_value'] ?? 0;
            $discountAmount = $data['discount_amount'] ?? 0;
            $rawDiscountApplyOn = $data['discount_apply_on'] ?? 'sub_total';
            $discountApplyOn = in_array($rawDiscountApplyOn, ['sub_total', 'total'], true)
                ? $rawDiscountApplyOn
                : 'sub_total';
            $loyaltyPointsRedeemed = (int)($data['loyalty_points_redeemed'] ?? 0);
            $loyaltyDiscountAmount = (float)($data['loyalty_discount_amount'] ?? 0);
            $extraChargesData = $data['extra_charges'] ?? [];
            $deliveryExecutiveId = $data['delivery_executive_id'] ?? null;
            $deliveryFee = $data['delivery_fee'] ?? 0;
            $tipAmount = $data['tip_amount'] ?? 0;
            $deliveryAppId = $data['delivery_app_id'] ?? null;
            $pickupDate = $data['pickup_date'] ?? null;
            $pickupDateOnly = $data['pickup_date_only'] ?? null;
            $pickupTimeOnly = $data['pickup_time_only'] ?? null;
            $ordersToDeleteAfterMerge = $data['orders_to_delete_after_merge'] ?? [];
            $taxMode = $data['tax_mode'] ?? 'order';
            // Use calculated values from frontend (already calculated by calculateTotal())
            $subTotal = $data['sub_total'] ?? 0;
            $total = $data['total'] ?? 0;
            $discountedTotal = $data['discounted_total'] ?? 0;
            $totalTaxAmount = $data['total_tax_amount'] ?? 0;
            $taxBaseFromClient = array_key_exists('tax_base', $data) ? (float)$data['tax_base'] : null;

            // Hotel room-service context (AJAX POS parity with Livewire Pos.php)
            $contextType = $data['context_type'] ?? null;
            $contextId = $data['context_id'] ?? null;
            $billTo = $data['bill_to'] ?? null;
            $hotelRoomId = isset($data['hotel_room_id']) && is_numeric($data['hotel_room_id'])
                ? (int) $data['hotel_room_id']
                : null;

            // Stamp/free-item detection helper (AJAX POS parity with Livewire POS).
            // Some clients only send a note marker for free-stamp items; normalize here.
            $freeStampNoteToken = __('loyalty::app.freeItemFromStamp');
            $normalizeBoolean = function ($value): bool {
                if (is_bool($value)) {
                    return $value;
                }
                if (is_int($value) || is_float($value)) {
                    return ((int)$value) === 1;
                }
                if (is_string($value)) {
                    $normalized = strtolower(trim($value));
                    if ($normalized === '') {
                        return false;
                    }
                    // Accept only explicit true-like values; "false"/"0" must remain false.
                    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
                }
                return false;
            };

            $normalizeStampFields = function (array $item, ?string $itemNote, ?int $menuItemId) use ($freeStampNoteToken, $normalizeBoolean) {
                $itemKey = (string)($item['key'] ?? '');

                $isFreeItemFromStamp = $normalizeBoolean($item['is_free_item_from_stamp'] ?? false);
                $stampRuleId = $item['stamp_rule_id'] ?? null;

                if ($itemKey && str_starts_with($itemKey, 'free_stamp_')) {
                    $isFreeItemFromStamp = true;
                    $parts = explode('_', $itemKey);
                    $stampRuleId = $stampRuleId ?: ($parts[2] ?? null);
                }

                // Fallback: if note contains the free-stamp token, treat as free-stamp item.
                if (!$isFreeItemFromStamp && $itemNote && $freeStampNoteToken && str_contains($itemNote, $freeStampNoteToken)) {
                    $isFreeItemFromStamp = true;
                }

                // Best-effort: if free-stamp item has no rule id, infer rule by menu item.
                if (
                    $isFreeItemFromStamp
                    && !$stampRuleId
                    && $menuItemId
                    && function_exists('module_enabled')
                    && module_enabled('Loyalty')
                    && class_exists(\Modules\Loyalty\Entities\LoyaltyStampRule::class)
                    && method_exists(\Modules\Loyalty\Entities\LoyaltyStampRule::class, 'getRuleForMenuItem')
                ) {
                    try {
                        $restaurantId = $this->restaurant?->id ?? (restaurant()->id ?? null);
                        if ($restaurantId) {
                            $rule = \Modules\Loyalty\Entities\LoyaltyStampRule::getRuleForMenuItem($restaurantId, (int)$menuItemId);
                            $stampRuleId = $rule?->id ?: $stampRuleId;
                        }
                    } catch (\Throwable $e) {
                        // fail-safe
                    }
                }

                return [(bool)$isFreeItemFromStamp, $stampRuleId];
            };

            // Best-effort order-level stamp discount from incoming item payload.
            // Free stamp items are tracked separately via is_free_item_from_stamp and should not be double-counted here.
            $stampDiscountAmount = 0.0;
            foreach ($items as $item) {
                $menuItemId = $item['id'] ?? null;
                $itemNote = $item['note'] ?? null;
                [$isFreeItemFromStamp] = $normalizeStampFields($item, $itemNote, $menuItemId ? (int)$menuItemId : null);

                if ($isFreeItemFromStamp) {
                    continue;
                }

                $qty = \App\Support\PosQuantity::normalize($item['quantity'] ?? 1);
                $unitPrice = (float)($item['price'] ?? 0);
                $actualAmount = array_key_exists('amount', $item)
                    ? (float)$item['amount']
                    : ($unitPrice * $qty);
                $expectedAmount = $unitPrice * $qty;

                if ($expectedAmount > $actualAmount) {
                    $stampDiscountAmount += ($expectedAmount - $actualAmount);
                }
            }
            $stampDiscountAmount = round($stampDiscountAmount, 2);

            // If loyalty redemption is applied, regular discount must not be applied.
            if ($loyaltyPointsRedeemed > 0 && $loyaltyDiscountAmount > 0) {
                $discountType = null;
                $discountValue = null;
                $discountAmount = 0;
            }

            // Validate required fields (similar to Pos.php)
            if (empty($items)) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.orderItemRequired'),
                ], 422);
            }

            $stockValidation = IngredientStockGuard::validateCartFromPosItems(
                (int) $this->branch->id,
                $items,
            );

            if (! $stockValidation['ok']) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => $stockValidation['error'] ?? IngredientStockGuard::insufficientStockMessage(),
                ], 422);
            }

            // Batch-load cart-related rows once (removes N× MenuItem::find / RestaurantCharge::find / Tax::all in loops).
            $menuIdsFromCart = collect($items)->pluck('id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
            $menuItemsById = $menuIdsFromCart === []
                ? collect()
                : MenuItem::with('translations')->whereIn('id', $menuIdsFromCart)->get()->keyBy('id');

            $extraChargeIdList = collect($extraChargesData ?? [])
                ->map(fn ($c) => is_array($c) ? ($c['id'] ?? null) : $c)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();
            $restaurantChargesById = $extraChargeIdList === []
                ? collect()
                : RestaurantCharge::whereIn('id', $extraChargeIdList)->get()->keyBy('id');

            $cachedTaxesAll = Tax::all();

            // Restaurant availability guard (match Livewire Pos::saveOrder)
            // Do not block explicit cancel actions from availability check
            $primaryAction = !empty($actions) ? $actions[0] : null;
            if ($primaryAction !== 'cancel') {
                $availability = RestaurantAvailabilityService::getAvailability($this->restaurant, $this->branch);
                if (!($availability['is_open'] ?? true)) {
                    $message = RestaurantAvailabilityService::getMessage($availability, $this->restaurant);
                    DB::rollBack();
                    return response()->json([
                        'success' => false,
                        'message' => $message,
                    ], 422);
                }
            }

            // Normalize order type for validation
            $normalizedOrderType = strtolower(str_replace(' ', '_', $orderTypeDisplay));
            if ($normalizedOrderType === 'dine in') {
                $normalizedOrderType = 'dine_in';
            }




            // Check if table is locked by another user (similar to Pos.php)
            $table = null;
            if ($tableId && $normalizedOrderType === 'dine_in') {
                $table = Table::with(['tableSession.lockedByUser'])->find($tableId);
                if ($table && $table->tableSession && $table->tableSession->isLocked()) {
                    $lockedByUser = $table->tableSession->lockedByUser;
                    $lockedUserName = $lockedByUser ? $lockedByUser->name : 'Another user';

                    // Check if current user can access the table
                    $user = auth()->user();
                    if ($user && method_exists($table, 'canBeAccessedByUser') && !$table->canBeAccessedByUser($user->id)) {
                        return response()->json([
                            'success' => false,
                            'message' => __('messages.tableHandledByUser', [
                                'user' => $lockedUserName,
                                'table' => $table->table_code
                            ]),
                        ], 403);
                    }
                }

                if ($table) {
                    $excludeOrderId = is_numeric($orderId) && (int) $orderId > 0 ? (int) $orderId : null;
                    $paxToFit = max((int) $pax, 1);
                    $remaining = $table->remainingSeats($excludeOrderId);

                    if ($remaining !== null && $paxToFit > $remaining) {
                        DB::rollBack();

                        return response()->json([
                            'success' => false,
                            'message' => $remaining <= 0
                                ? __('messages.tableSeatsFull', ['table' => $table->table_code])
                                : __('messages.paxExceedsRemainingSeats', [
                                    'pax' => $paxToFit,
                                    'remaining' => $remaining,
                                    'table' => $table->table_code,
                                ]),
                        ], 422);
                    }
                }
            }

            // Find or create customer (similar to Pos.php)
            $customerId = null;
            if (!empty($customerData['name']) || !empty($customerData['phone']) || !empty($customerData['email'])) {
                $customer = Customer::firstOrCreate(
                    [
                        'restaurant_id' => $this->restaurant->id,
                        'phone' => $customerData['phone'] ?? null,
                    ],
                    [
                        'name' => $customerData['name'] ?? '',
                        'email' => $customerData['email'] ?? null,
                    ]
                );

                // Update customer data if provided
                if (!empty($customerData['name'])) {
                    $customer->name = $customerData['name'];
                }
                if (!empty($customerData['email'])) {
                    $customer->email = $customerData['email'];
                }
                if (!empty($customerData['phone'])) {
                    $customer->phone = $customerData['phone'];
                }
                $customer->save();
                $customerId = $customer->id;
            }

            // Preload stamp rules + tier multiplier once for applyStampDiscountToAmount (avoids N× rule/tier queries per line).
            $preloadedStampRulesById = collect();
            $stampDiscountTierMultiplierOverride = null;
            if (
                function_exists('module_enabled')
                && module_enabled('Loyalty')
                && class_exists(\Modules\Loyalty\Entities\LoyaltyStampRule::class)
            ) {
                $stampRuleIdsForPreload = [];
                foreach ($items as $si) {
                    if (!empty($si['stamp_rule_id'])) {
                        $stampRuleIdsForPreload[] = (int) $si['stamp_rule_id'];
                    }
                    $sk = (string) ($si['key'] ?? '');
                    if ($sk !== '' && str_starts_with($sk, 'free_stamp_')) {
                        $p = explode('_', $sk);
                        if (!empty($p[2])) {
                            $stampRuleIdsForPreload[] = (int) $p[2];
                        }
                    }
                }
                $stampRuleIdsForPreload = array_values(array_unique(array_filter($stampRuleIdsForPreload)));
                if ($stampRuleIdsForPreload !== []) {
                    $preloadedStampRulesById = \Modules\Loyalty\Entities\LoyaltyStampRule::whereIn('id', $stampRuleIdsForPreload)
                        ->get()
                        ->keyBy('id');
                }

                if ($customerId && $this->restaurant && class_exists(\Modules\Loyalty\Entities\LoyaltyAccount::class)) {
                    $stampDiscountTierMultiplierOverride = 1.0;
                    try {
                        $restaurantId = (int) ($this->restaurant->id ?? 0);
                        if ($restaurantId > 0) {
                            $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                            $account = $loyaltyService->getOrCreateAccount($restaurantId, (int) $customerId);
                            if ($account && $account->tier_id && class_exists(\Modules\Loyalty\Entities\LoyaltyTier::class)) {
                                $tier = \Modules\Loyalty\Entities\LoyaltyTier::find($account->tier_id);
                                if ($tier && (float) $tier->redemption_multiplier > 0) {
                                    $stampDiscountTierMultiplierOverride = (float) $tier->redemption_multiplier;
                                }
                            }
                        }
                    } catch (\Throwable $e) {
                        $stampDiscountTierMultiplierOverride = null;
                    }
                }
            }

            // Find order type (similar to Pos.php)
            $orderTypeModel = null;
            $orderTypeId = null;
            $orderTypeSlug = null;
            $orderTypeName = null;

            $orderTypeModel = OrderType::where('branch_id', $this->branch->id)
                ->where('is_active', true)
                ->where(function ($q) use ($normalizedOrderType, $orderTypeDisplay) {
                    $q->where('slug', $normalizedOrderType)
                        ->orWhere('type', $normalizedOrderType)
                        ->orWhere('order_type_name', $orderTypeDisplay);
                })
                ->first();

            if ($orderTypeModel) {
                $orderTypeId = $orderTypeModel->id;
                $orderTypeSlug = $orderTypeModel->slug;
                $orderTypeName = $orderTypeModel->order_type_name;
            } else {
                // Fallback to default order type
                $orderTypeModel = OrderType::where('branch_id', $this->branch->id)
                    ->where('is_default', true)
                    ->where('is_active', true)
                    ->first();

                if ($orderTypeModel) {
                    $orderTypeId = $orderTypeModel->id;
                    $orderTypeSlug = $orderTypeModel->slug;
                    $orderTypeName = $orderTypeModel->order_type_name;
            } else {
                $orderTypeSlug = $normalizedOrderType;
                $orderTypeName = $orderTypeDisplay;
            }
        }

        if ($orderTypeSlug !== 'dine_in' && $normalizedOrderType !== 'dine_in') {
            $tableId = null;
        }

        // Build extra charges array for creating OrderCharge records
            $extraCharges = [];
            if (!empty($extraChargesData) && is_array($extraChargesData)) {
                foreach ($extraChargesData as $charge) {
                    $chargeId = is_array($charge) ? ($charge['id'] ?? null) : $charge;
                    if ($chargeId) {
                        $chargeModel = $restaurantChargesById->get((int) $chargeId);
                        if ($chargeModel) {
                            $extraCharges[] = $chargeModel;
                        }
                    }
                }
            }

            // Generate order number (similar to Pos.php)
            $orderNumberData = Order::generateOrderNumber($this->branch);

            // Determine status based on actions (similar to Pos.php saveOrder)
            $status = 'draft';
            $orderStatus = 'placed';
            $tableStatus = 'available';

            $action = !empty($actions) ? $actions[0] : null;

            // ------------------------------------------------------------------
            // Delivery & Pickup validations (AJAX POS parity with Livewire POS)
            // ------------------------------------------------------------------

            $isHotelEnabled = isHotelModuleEnabled();

            // Require delivery executive for internal delivery orders
            if (
                $action !== 'cancel'
                && ($orderTypeSlug === 'delivery' || $normalizedOrderType === 'delivery')
            ) {
                // Treat "default" or null as internal delivery (no 3rd‑party app)
                $isDefaultDeliveryApp = ($deliveryAppId === 'default' || $deliveryAppId === null || $deliveryAppId === '' || $deliveryAppId === 0);

                if ($isDefaultDeliveryApp && empty($deliveryExecutiveId)) {
                    return response()->json([
                        'success' => false,
                        'message' => __('validation.required', ['attribute' => __('modules.delivery.deliveryExecutive')]),
                    ], 422);
                }

                if ($deliveryExecutiveId && !DeliveryExecutive::findAssignableForBranch((int) $deliveryExecutiveId, (int) $this->branch->id)) {
                    return response()->json([
                        'success' => false,
                        'message' => __('messages.invalidRequest'),
                    ], 422);
                }
            }

            $isRoomServiceOrder = $isHotelEnabled && ($orderTypeSlug === 'room_service'
                || $normalizedOrderType === 'room_service'
                || (($orderTypeModel?->type ?? null) === 'room_service'));

            if ($isRoomServiceOrder && $action !== 'cancel') {
                $existingHotelRoomId = null;
                if ($orderId) {
                    $existingHotelRoomId = Order::where('id', $orderId)->value('hotel_room_id');
                }

                $resolvedHotelRoomId = $hotelRoomId ?: ($existingHotelRoomId ? (int) $existingHotelRoomId : null);
                $resolvedStayId = $contextId;

                if (
                    $contextType === 'HOTEL_ROOM'
                    && $resolvedStayId
                    && ! DB::table('hotel_stays')->where('id', $resolvedStayId)->exists()
                ) {
                    return response()->json([
                        'success' => false,
                        'message' => __('hotel::modules.roomService.invalidStay'),
                    ], 422);
                }

                if (! $resolvedStayId && ! $resolvedHotelRoomId) {
                    return response()->json([
                        'success' => false,
                        'message' => __('hotel::modules.roomService.selectStayRequired'),
                    ], 422);
                }
            }

            // Normalize and validate pickup date/time for pickup orders – ensure future time
            if (
                $pickupDate
                && $action !== 'cancel'
                && ($orderTypeSlug === 'pickup' || $normalizedOrderType === 'pickup')
            ) {
                $timezone   = restaurant()->timezone ?? config('app.timezone');
                $dateFormat = restaurant()->date_format ?? (global_setting()->date_format ?? 'd-m-Y');

                try {
                    $parsedDateTime = null;

                    // Prefer explicit date/time fields from POS state when available.
                    $datePart = trim((string) ($pickupDateOnly ?? ''));
                    $timePart = trim((string) ($pickupTimeOnly ?? ''));

                    if ($datePart !== '' && $timePart !== '') {
                        $dateTimeCandidates = [
                            $dateFormat . ' H:i',
                            $dateFormat . ' H:i:s',
                            $dateFormat . ' h:i A',
                            $dateFormat . ' h:i a',
                            $dateFormat . ' g:i A',
                            $dateFormat . ' g:i a',
                        ];

                        foreach ($dateTimeCandidates as $candidateFormat) {
                            try {
                                $parsedDateTime = \Carbon\Carbon::createFromFormat($candidateFormat, $datePart . ' ' . $timePart, $timezone);
                                break;
                            } catch (\Exception $ignored) {
                                // Try next candidate format
                            }
                        }
                    }

                    if (!$parsedDateTime) {
                        // Fallback: combined pickup_date value.
                        $dateTimeCandidates = [
                            $dateFormat . ' H:i',
                            $dateFormat . ' H:i:s',
                            $dateFormat . ' h:i A',
                            $dateFormat . ' h:i a',
                            'Y-m-d H:i:s',
                            'Y-m-d H:i',
                        ];

                        foreach ($dateTimeCandidates as $candidateFormat) {
                            try {
                                $parsedDateTime = \Carbon\Carbon::createFromFormat($candidateFormat, (string) $pickupDate, $timezone);
                                break;
                            } catch (\Exception $ignored) {
                                // Try next candidate format
                            }
                        }
                    }

                    if ($parsedDateTime) {
                        // Same-day validation: deny times equal to or before current time
                        $today       = now($timezone)->startOfDay();
                        $selectedDay = $parsedDateTime->copy()->startOfDay();

                        if ($selectedDay->equalTo($today)) {
                            $currentDateTime = now($timezone)->startOfMinute();
                        }

                        // Persist normalized local datetime string (matches Livewire behaviour)
                        $pickupDate = $parsedDateTime->format('Y-m-d H:i:s');
                    }
                    // If parsing fails entirely we silently keep original
                    // $pickupDate value instead of breaking the request.
                } catch (\Throwable $e) {
                    // Silent fail for legacy clients: do not reject the request.
                }
            }

            switch ($action) {
                case 'bill':
                case 'billed':
                    $status = 'billed';
                    $orderStatus = 'confirmed';
                    $tableStatus = 'running';
                    break;
                case 'kot':
                    $status = 'kot';
                    $orderStatus = 'confirmed';
                    $tableStatus = 'running';
                    break;
                case 'cancel':
                    $status = 'canceled';
                    $orderStatus = OrderStatus::CANCELLED->value;
                    $tableStatus = 'available';
                    break;
                default:
                    $status = 'draft';
                    $orderStatus = 'placed';
                    $tableStatus = 'available';
            }

            // Preserve / honor the current order_status from POS only when it is a valid OrderStatus enum value.
            // The Blade POS may send lifecycle `status` strings (e.g. "kot", "draft", "billed") in this field;
            // those belong on orders.status, not order_status, and would break the OrderStatus cast.
            $hintCoerced = null;
            if (!empty($data['order_status'])) {
                $hintCoerced = $this->coerceOrderStatusEnumValue((string) $data['order_status']);
            } elseif (!empty($data['order_status_display'])) {
                $hintCoerced = $this->coerceOrderStatusEnumValue((string) $data['order_status_display']);
            }
            if ($hintCoerced !== null) {
                $orderStatus = $hintCoerced;
            } elseif ($orderId) {
                // If not sent (older clients) or hint was invalid, keep existing order status when possible.
                try {
                    $existingOrder = $order ?? Order::find($orderId);
                    if ($existingOrder && !empty($existingOrder->order_status)) {
                        $existingRaw = $existingOrder->order_status instanceof \BackedEnum
                            ? $existingOrder->order_status->value
                            : (string) $existingOrder->order_status;
                        $existingCoerced = $this->coerceOrderStatusEnumValue($existingRaw);
                        if ($existingCoerced !== null) {
                            $orderStatus = $existingCoerced;
                        }
                    }
                } catch (\Throwable $e) {
                    // fail-safe
                }
            }

            // Get order type name (similar to Pos.php)
            $orderTypeNameFinal = $orderTypeName ?? $orderTypeDisplay;


            $normalizedDeliveryAppId = ($deliveryAppId === 'default' || $deliveryAppId === null) ? null : (int)$deliveryAppId;

            $posMachineId = null;
            if (module_enabled('MultiPOS') && function_exists('pos_machine_id')) {
                $posMachineId = pos_machine_id();
            }

            // Check if updating existing order or creating new one
            $order = null;
            $wasDraft = false;
            $existingRedeemedPoints = 0;
            $preserveDisplayedTotalsForExistingKot = false;
            $createdNewOrderInSubmit = false;

            if ($orderId) {
                $order = Order::find($orderId);

                if (!$order) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Order not found',
                    ], 404);
                }

                // Store original status before update
                $wasDraft = $order->status === 'draft';
                $existingRedeemedPoints = (int) ($order->loyalty_points_redeemed ?? 0);
                // Performance: evaluate existing KOT-items existence once for this request flow.
                $hasExistingKotItems = $order->kot()->whereHas('items')->exists();
                $isExistingKotUpdate = ($status === 'kot' && $hasExistingKotItems);
                $preserveDisplayedTotalsForExistingKot = $isExistingKotUpdate;

                // When adding a new KOT to an existing KOT order, any new loyalty redemption coming
                // from the POS should be **added** on top of the already‑redeemed points/amount
                // instead of replacing them. This keeps the order‑level loyalty fields cumulative.
                if ($isExistingKotUpdate && $status === 'kot') {
                    $loyaltyPointsRedeemed = (int) $loyaltyPointsRedeemed + $existingRedeemedPoints;
                    $loyaltyDiscountAmount = (float) $loyaltyDiscountAmount + (float) ($order->loyalty_discount_amount ?? 0);
                }

                // If converting from draft to KOT/Bill, generate order number
                $orderNumberData = null;
                if ($wasDraft && $action !== 'draft' && !$order->order_number) {
                    $orderNumberData = Order::generateOrderNumber($this->branch);
                }

                // Prepare update data
                $updateData = [
                    'date_time' => now(),
                    'order_type' => $orderTypeSlug ?? $normalizedOrderType,
                    'order_type_id' => $orderTypeId,
                    'custom_order_type_name' => $orderTypeNameFinal,
                    'delivery_executive_id' => ($orderTypeSlug === 'delivery') ? $deliveryExecutiveId : null,
                    'number_of_pax' => $pax,
                    'order_note' => $note ?: null,
                    'waiter_id' => $waiterId,
                    'pickup_date' => ($orderTypeSlug === 'pickup') ? $pickupDate : null,
                    'table_id' => $tableId ?? $order->table_id,
                    'sub_total' => $subTotal,
                    'total' => $total,
                    'total_tax_amount' => $totalTaxAmount,
                    'delivery_fee' => ($orderTypeSlug === 'delivery') ? $deliveryFee : 0,
                    'delivery_app_id' => ($orderTypeSlug === 'delivery') ? $normalizedDeliveryAppId : null,
                    'tip_amount' => $tipAmount,
                    'status' => $status,
                    'order_status' => $orderStatus,
                    'customer_id' => $customerId,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'discount_apply_on' => $discountApplyOn,
                    'stamp_discount_amount' => $stampDiscountAmount,
                    'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
                    'loyalty_discount_amount' => round($loyaltyDiscountAmount, 2),
                ];

                // Add room service context when applicable (HOTEL_ROOM parity with Livewire Pos.php)
                if (! empty($contextType) && ! empty($contextId) && $contextType === 'HOTEL_ROOM') {
                    $updateData['context_type'] = 'HOTEL_ROOM';
                    $updateData['context_id'] = $contextId;
                    $updateData['bill_to'] = $billTo;
                }

                if ($isHotelEnabled && $hotelRoomId) {
                    $updateData['hotel_room_id'] = $hotelRoomId;
                } elseif ($isHotelEnabled && $order->hotel_room_id) {
                    $updateData['hotel_room_id'] = $order->hotel_room_id;
                }

                // Add order number if converting from draft
                if ($orderNumberData) {
                    $updateData['order_number'] = $orderNumberData['order_number'];
                    $updateData['formatted_order_number'] = $orderNumberData['formatted_order_number'];
                }

                $user = auth()->user();
                if ($user && in_array($status, ['kot', 'billed'], true)) {
                    $updateData['added_by'] = $user->id;
                }

                // Update order
                $order->update($updateData);

                // Delete existing items and taxes to recreate them (match Livewire Pos.php behavior)
                // CRITICAL: Do NOT delete when:
                // - billing a KOT order (billing flow should preserve linked KOT items),
                // - OR order has free stamp items (customer-site stamp redemption),
                // - OR adding a new KOT to an existing KOT order.
                $isBillingKotOrder = ($status === 'billed' && $hasExistingKotItems);
                $hasFreeStampItems = ($status === 'billed' && $order->items()->where('is_free_item_from_stamp', true)->exists());
                $isKotUpdate = ($status === 'kot' && $hasExistingKotItems);
                $preserveOrderItemsOnBill = ($isBillingKotOrder || $hasFreeStampItems || $isKotUpdate);

                if ($wasDraft && $status !== 'draft') {
                    // Converting from draft to real order - delete draft items
                    $order->items()->delete();
                } elseif ($status !== 'draft' && !$preserveOrderItemsOnBill) {
                    // Updating a non-draft order - delete items to recreate (skip when preserving)
                    $order->items()->delete();
                }

                // When preserving order items, keep existing taxes so totals/tax_base remain consistent.
                if (!$preserveOrderItemsOnBill) {
                    $order->taxes()->delete();
                }

                // Charges should reflect current request state
                $order->charges()->delete();

            } else {
                // Create order (similar to Pos.php orderData structure)
                $orderData = [
                    'order_number' => $action === 'draft' ? null : ($orderNumberData['order_number'] ?? null),
                    'formatted_order_number' => $action === 'draft' ? null : ($orderNumberData['formatted_order_number'] ?? null),
                    'branch_id' => $this->branch->id,
                    'table_id' => $tableId,
                    'date_time' => now(),
                    'number_of_pax' => $pax,
                    'order_note' => $note ?: null,
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => $discountAmount,
                    'discount_apply_on' => $discountApplyOn,
                    'stamp_discount_amount' => $stampDiscountAmount,
                    'loyalty_points_redeemed' => $loyaltyPointsRedeemed,
                    'loyalty_discount_amount' => round($loyaltyDiscountAmount, 2),
                    'waiter_id' => $waiterId,
                    'sub_total' => $subTotal,
                    'total' => $total,
                    'total_tax_amount' => $totalTaxAmount,
                    'order_type' => $orderTypeSlug ?? $normalizedOrderType,
                    'order_type_id' => $orderTypeId,
                    'custom_order_type_name' => $orderTypeNameFinal,
                    'pickup_date' => ($orderTypeSlug === 'pickup') ? $pickupDate : null,
                    'delivery_fee' => ($orderTypeSlug === 'delivery') ? $deliveryFee : 0,
                    'delivery_executive_id' => ($orderTypeSlug === 'delivery') ? $deliveryExecutiveId : null,
                    'delivery_app_id' => ($orderTypeSlug === 'delivery') ? $normalizedDeliveryAppId : null,
                    'tip_amount' => $tipAmount,
                    'status' => $status,
                    'order_status' => $orderStatus,
                    'placed_via' => 'pos',
                    'tax_mode' => $taxMode,
                    'customer_id' => $customerId,
                    'pos_machine_id' => $posMachineId,
                ];

                // Add room service context if provided (HOTEL_ROOM parity with Livewire Pos.php)
                if (! empty($contextType) && ! empty($contextId) && $contextType === 'HOTEL_ROOM') {
                    $orderData['context_type'] = 'HOTEL_ROOM';
                    $orderData['context_id'] = $contextId;
                    $orderData['bill_to'] = $billTo;
                }

                if ($isHotelEnabled && $hotelRoomId) {
                    $orderData['hotel_room_id'] = $hotelRoomId;
                }

                $order = Order::create($orderData);
                $createdNewOrderInSubmit = true;

                $user = auth()->user();
                if ($user && in_array($status, ['kot', 'billed'], true)) {
                    $order->added_by = $user->id;
                    $order->save();
                }
            }

            // Create extra charges (similar to Pos.php)
            if (!empty($extraCharges)) {
                $chargesData = collect($extraCharges)
                    ->map(fn($charge) => [
                        'charge_id' => $charge->id,
                    ])->toArray();

                $order->charges()->createMany($chargesData);
            }

            // Handle canceled status (similar to Pos.php)
            if ($status == 'canceled') {
                if ($table) {
                    $table->available_status = $tableStatus;
                    $table->saveQuietly();
                }

                DB::commit();
                TablesIndexCache::forgetForBranch((int) $this->branch->id);

                return response()->json([
                    'success' => true,
                    'message' => __('messages.orderCanceled'),
                    'order' => $order,
                ], 200);
            }

            // Handle KOT creation (similar to Pos.php)
            $kot = null;
            $kotIds = [];
            $orderItemsAlreadyCreated = false; // Flag to prevent duplicate item creation

            // Check if we need to create KOT (action is 'kot' or second action is 'bill')
            $secondAction = !empty($actions) && count($actions) > 1 ? $actions[1] : null;
            $thirdAction = !empty($actions) && count($actions) > 2 ? $actions[2] : null;
            $shouldCreateKot = ($status == 'kot');

            if ($shouldCreateKot) {
                // Kitchen module: one KOT per kot_place (matches Livewire Pos::executeSaveOrder).
                $useKitchenKotSplit = function_exists('restaurant_modules')
                    && function_exists('custom_module_plugins')
                    && in_array('Kitchen', restaurant_modules(), true)
                    && in_array('kitchen', custom_module_plugins(), true);

                $groupedItemsByPlace = [];
                $itemsWithoutKotPlace = [];
                if ($useKitchenKotSplit) {
                    $kotPlaceByMenuItemId = $menuItemsById->pluck('kot_place_id', 'id');

                    foreach ($items as $item) {
                        $mid = isset($item['id']) ? (int) $item['id'] : 0;
                        if ($mid <= 0) {
                            continue;
                        }
                        $placeId = $kotPlaceByMenuItemId[$mid] ?? null;
                        if ($placeId) {
                            $groupedItemsByPlace[$placeId][] = $item;
                        } else {
                            // Livewire Pos skips these; we still send one KOT so the line is not lost.
                            $itemsWithoutKotPlace[] = $item;
                        }
                    }

                    // Nothing assigned to a kitchen: same as non-kitchen (single KOT for whole cart).
                    if ($groupedItemsByPlace === []) {
                        $useKitchenKotSplit = false;
                        $itemsWithoutKotPlace = [];
                    }
                }

                $createKotItemsOnKot = function (Kot $kotRow, array $cartItems) use (
                    $normalizeBoolean,
                    $orderTypeId,
                    $orderTypeSlug
                ) {
                    foreach ($cartItems as $item) {
                        $menuItemId = $item['id'] ?? null;
                        $variantId = $item['variant_id'] ?? 0;
                        $quantity = \App\Support\PosQuantity::normalize($item['quantity'] ?? 1);
                        $itemNote = $item['note'] ?? null;
                        $modifierIds = $item['modifier_ids'] ?? [];

                        // Prefer incoming unit price/amount; do NOT override discounts (stamp/customer-site) by recomputing.
                        $unitPrice = (float)($item['price'] ?? 0);
                        $itemAmount = array_key_exists('amount', $item)
                            ? (float)$item['amount']
                            : ($unitPrice * (float)$quantity);

                        // Stamp fields (best-effort, compatible with Livewire key format)
                        $itemKey = (string)($item['key'] ?? '');
                        $isFreeItemFromStamp = false;
                        $stampRuleId = null;
                        if ($itemKey && str_starts_with($itemKey, 'free_stamp_')) {
                            $isFreeItemFromStamp = true;
                            $parts = explode('_', $itemKey);
                            $stampRuleId = $parts[2] ?? null;
                        }
                        if (array_key_exists('is_free_item_from_stamp', $item)) {
                            $isFreeItemFromStamp = $normalizeBoolean($item['is_free_item_from_stamp']);
                        }
                        if (array_key_exists('stamp_rule_id', $item) && $item['stamp_rule_id']) {
                            $stampRuleId = $item['stamp_rule_id'];
                        }

                        // If a discount is already reflected in amount, resolve stamp rule id + discount for KOT row display.
                        $itemLevelDiscountAmount = 0.0;
                        $isDiscounted = false;
                        if (module_enabled('Loyalty') && !$isFreeItemFromStamp) {
                            try {
                                $expected = round($unitPrice * (float)$quantity, 2);
                                $actual = round($itemAmount, 2);
                                if ($expected > $actual + 0.01) {
                                    $loyaltyHandler = new \Modules\Loyalty\Services\PosLoyaltyHandler(new \stdClass());
                                    [$resolvedRuleId, $resolvedDiscount, $resolvedIsDiscounted] = $loyaltyHandler
                                        ->resolveStampDiscountForItem((int)$menuItemId, (float)$expected, (float)$actual);
                                    if ($resolvedRuleId) {
                                        $stampRuleId = $stampRuleId ?: $resolvedRuleId;
                                        $itemLevelDiscountAmount = (float)$resolvedDiscount;
                                        $isDiscounted = (bool)$resolvedIsDiscounted;
                                    }
                                }
                            } catch (\Throwable $e) {
                                // Fail-safe: do not block POS on stamp resolution errors
                            }
                        }

                        if ($isFreeItemFromStamp) {
                            $itemAmount = 0.0;
                        }

                        $kotItem = KotItem::create([
                            'kot_id' => $kotRow->id,
                            'menu_item_id' => $menuItemId,
                            'menu_item_variation_id' => $variantId > 0 ? $variantId : null,
                            'quantity' => $quantity,
                            'price' => $unitPrice,
                            'amount' => $itemAmount,
                            'is_free_item_from_stamp' => $isFreeItemFromStamp,
                            'stamp_rule_id' => $stampRuleId,
                            'discount_amount' => $itemLevelDiscountAmount,
                            'is_discounted' => $isDiscounted,
                            'note' => $itemNote,
                            'order_type_id' => $orderTypeId ?? null,
                            'order_type' => $orderTypeSlug ?? null,
                        ]);

                        if (!empty($modifierIds) && is_array($modifierIds)) {
                            $kotItem->modifierOptions()->sync($modifierIds);
                        }
                    }
                };

                if ($useKitchenKotSplit) {
                    foreach ($groupedItemsByPlace as $kotPlaceId => $placeItems) {
                        $kot = Kot::create([
                            'branch_id' => $this->branch->id,
                            'kot_number' => Kot::generateKotNumber($this->branch),
                            'order_id' => $order->id,
                            'order_type_id' => $orderTypeId,
                            'token_number' => Kot::generateTokenNumber($this->branch->id, $orderTypeId),
                            'note' => $note,
                            'kitchen_place_id' => $kotPlaceId,
                        ]);
                        $kotIds[] = $kot->id;
                        $createKotItemsOnKot($kot, $placeItems);
                    }
                    if ($itemsWithoutKotPlace !== []) {
                        $kot = Kot::create([
                            'branch_id' => $this->branch->id,
                            'kot_number' => Kot::generateKotNumber($this->branch),
                            'order_id' => $order->id,
                            'order_type_id' => $orderTypeId,
                            'token_number' => Kot::generateTokenNumber($this->branch->id, $orderTypeId),
                            'note' => $note,
                        ]);
                        $kotIds[] = $kot->id;
                        $createKotItemsOnKot($kot, $itemsWithoutKotPlace);
                    }
                } else {
                    $kot = Kot::create([
                        'branch_id' => $this->branch->id,
                        'kot_number' => Kot::generateKotNumber($this->branch),
                        'order_id' => $order->id,
                        'order_type_id' => $orderTypeId,
                        'token_number' => Kot::generateTokenNumber($this->branch->id, $orderTypeId),
                        'note' => $note,
                    ]);

                    $kotIds[] = $kot->id;
                    $createKotItemsOnKot($kot, $items);
                }

                // Recalculate totals after KOT creation ONLY if editing an existing order
                // This matches the Livewire component logic: if ($this->orderID) { ... }
                if ($orderId && !$preserveDisplayedTotalsForExistingKot) {
                    // Recalculate totals for existing KOT order.
                    // If Loyalty module is enabled, mirror Livewire's recalculateOrderTotalAfterStampRedemption logic:
                    //   - Start from item amounts (stamp discounts already applied there)
                    //   - Subtract regular + loyalty discounts BEFORE charges and taxes
                    // Otherwise, mirror recalculateTotalsForKotOrderWithoutModule.
                    $order->refresh();
                    $order->load(['items', 'taxes.tax', 'charges.charge', 'kot.items']);

                    if (function_exists('module_enabled') && module_enabled('Loyalty')) {
                        // Stamp-aware recomputation (similar to Shop\OrderDetail::recalculateOrderTotalAfterStampRedemption)
                        $correctSubTotal = (float)($order->items->sum('amount') ?? 0);
                        $correctTotal = $correctSubTotal;

                        // Apply regular and loyalty discounts BEFORE tax/charges
                        $discountAmount = (float)($order->discount_amount ?? 0);
                        $loyaltyDiscount = (float)($order->loyalty_discount_amount ?? 0);
                        $correctTotal -= $discountAmount;
                        $correctTotal -= $loyaltyDiscount;

                        // Service charges on discounted base (stamp discount already in item amounts)
                        $serviceTotal = 0.0;
                        $chargeBase = max(0.0, $correctSubTotal - $discountAmount - $loyaltyDiscount);
                        foreach ($order->charges ?? [] as $chargeRelation) {
                            $charge = $chargeRelation->charge ?? $chargeRelation;
                            if ($charge) {
                                $serviceTotal += (float)$charge->getAmount($chargeBase);
                            }
                        }

                        // Tax base equals taxable amount (subtotal - discount), excluding charges.
                        $taxBase = $chargeBase;
                        $taxBase = max(0.0, (float)$taxBase);

                        // Taxes
                        $taxAmount = 0.0;
                        if (($order->tax_mode ?? $taxMode) === 'order') {
                            // For order-level tax mode, recompute tax for the current tax base.
                            // If order has attached taxes, use those mappings; otherwise use incoming/all taxes.
                            $existingOrderTaxes = $order->taxes && $order->taxes->count() > 0;

                            if (!$existingOrderTaxes) {
                                $taxesToUse = (function () use ($taxes) {
                                    if (!empty($taxes)) {
                                        return collect($taxes);
                                    }
                                    return $cachedTaxesAll;
                                })();

                                foreach ($taxesToUse as $taxModel) {
                                    if ($taxModel && isset($taxModel->tax_percent)) {
                                        $percent = (float)$taxModel->tax_percent;
                                        $taxAmount += ($percent / 100.0) * $taxBase;
                                    }
                                }
                            } else {
                                $taxesToUse = $order->taxes
                                    ->map(fn($orderTax) => $orderTax->tax)
                                    ->filter()
                                    ->unique('id')
                                    ->values();

                                foreach ($taxesToUse as $taxModel) {
                                    $percent = (float)($taxModel->tax_percent ?? 0);
                                    $taxAmount += ($percent / 100.0) * $taxBase;
                                }
                            }

                            $taxAmount = round($taxAmount, 2);
                            $correctTotal += $taxAmount;
                        } else {
                            // Item-level taxes: rely on summed item tax_amount
                            $taxAmount = (float)($order->items->sum('tax_amount') ?? 0);
                            $isInclusive = $this->restaurant->tax_inclusive ?? false;
                            if (!$isInclusive && $taxAmount > 0) {
                                $correctTotal += $taxAmount;
                            }
                        }

                        // Add charges, tip, and delivery to total
                        $correctTotal += $serviceTotal;
                        $correctTotal += (float)($order->tip_amount ?? $tipAmount ?? 0);
                        $correctTotal += (float)($order->delivery_fee ?? $deliveryFee ?? 0);

                        $order->updateQuietly([
                            'sub_total' => round($correctSubTotal, 2),
                            'total' => round($correctTotal, 2),
                            'discount_amount' => round($discountAmount, 2),
                            'total_tax_amount' => round($taxAmount, 2),
                            'tax_base' => round($taxBase, 2),
                            'tax_mode' => $order->tax_mode ?? $taxMode,
                            'loyalty_points_redeemed' => (int)($order->loyalty_points_redeemed ?? 0),
                            'loyalty_discount_amount' => round($loyaltyDiscount, 2),
                        ]);
                    } else {
                        // Non-loyalty recomputation (mirror Pos::recalculateTotalsForKotOrderWithoutModule)
                        $subTotal = 0.0;
                        foreach ($order->kot as $kot) {
                            foreach ($kot->items->where('status', '!=', 'cancelled') as $kotItem) {
                                if ($kotItem->amount !== null) {
                                    $subTotal += (float)$kotItem->amount;
                                    continue;
                                }
                                $itemPrice = $kotItem->menuItemVariation->price
                                    ?? $kotItem->menuItem->price
                                    ?? 0;
                                $modifierPrice = $kotItem->modifierOptions?->sum('price') ?? 0;
                                $subTotal += ($itemPrice + $modifierPrice) * $kotItem->quantity;
                            }
                        }

                        $discountAmount = (float)($order->discount_amount ?? 0);
                        $loyaltyDiscount = (float)($order->loyalty_discount_amount ?? 0);
                        $discountedBase = $subTotal - $discountAmount - $loyaltyDiscount;

                        $serviceTotal = 0.0;
                        foreach ($order->charges ?? [] as $chargeRelation) {
                            $charge = $chargeRelation->charge ?? $chargeRelation;
                            if ($charge) {
                                $serviceTotal += (float)$charge->getAmount($discountedBase);
                            }
                        }

                        $taxBase = $discountedBase;
                        $taxBase = max(0.0, (float)$taxBase);

                        $taxAmount = 0.0;
                        if (($order->tax_mode ?? $taxMode) === 'order') {
                            // Mirror Livewire Pos::recalculateTotalsForKotOrderWithoutModule:
                            // prefer taxes already attached to this order; otherwise, use all taxes.
                            $taxesToUse = $order->taxes && $order->taxes->count() > 0
                                ? $order->taxes->map(fn($orderTax) => $orderTax->tax)->filter()
                                : $cachedTaxesAll;
                            foreach ($taxesToUse as $taxModel) {
                                if ($taxModel && isset($taxModel->tax_percent)) {
                                    $percent = (float)$taxModel->tax_percent;
                                    $taxAmount += ($percent / 100.0) * $taxBase;
                                }
                            }
                        } else {
                            $taxAmount = (float)($order->items->sum('tax_amount') ?? 0);
                        }

                        $total = $discountedBase + $serviceTotal;
                        if (($order->tax_mode ?? $taxMode) === 'order') {
                            $total += $taxAmount;
                        } else {
                            $isInclusive = $this->restaurant->tax_inclusive ?? false;
                            if (!$isInclusive) {
                                $total += $taxAmount;
                            }
                        }

                        $total += (float)($order->tip_amount ?? $tipAmount ?? 0);
                        $total += (float)($order->delivery_fee ?? $deliveryFee ?? 0);

                        $order->updateQuietly([
                            'sub_total' => round($subTotal, 2),
                            'total' => round($total, 2),
                            'discount_amount' => round($discountAmount, 2),
                            'total_tax_amount' => round($taxAmount, 2),
                            'tax_base' => round($taxBase, 2),
                            'tax_mode' => $order->tax_mode ?? $taxMode,
                            'loyalty_points_redeemed' => (int)($order->loyalty_points_redeemed ?? 0),
                            'loyalty_discount_amount' => round($loyaltyDiscount, 2),
                        ]);
                    }
                }
                // For new orders, totals are already correct from frontend - no recalculation needed

                // If second action is 'bill', update order status to 'billed' and create order items
                if ($secondAction === 'bill' && $thirdAction === 'payment') {
                    // Update order status to billed
                    $order->update([
                        'status' => 'billed',
                        'order_status' => $orderStatus,
                    ]);

                    // When billing an existing KOT order, the frontend can send cart lines keyed as `kot_{kotId}_{kotItemId}`.
                    // If we re-run billing for the same order, we must not re-add those same KOT lines again.
                    $kotOrderItemIdByKotItemId = [];
                    try {
                        $kotItemIdsInPayload = [];
                        foreach ($items as $payloadItem) {
                            $key = (string)($payloadItem['key'] ?? '');
                            if ($key && preg_match('/^kot_(\d+)_(\d+)$/', $key, $m)) {
                                $kotItemIdsInPayload[] = (int)$m[2];
                            }
                        }
                        $kotItemIdsInPayload = array_values(array_unique(array_filter($kotItemIdsInPayload)));
                        if (!empty($kotItemIdsInPayload)) {
                            $kotOrderItemIdByKotItemId = KotItem::whereIn('id', $kotItemIdsInPayload)
                                ->pluck('order_item_id', 'id')
                                ->toArray();
                        }
                    } catch (\Throwable $e) {
                        // Fail-safe: do not block billing if lookup fails
                        $kotOrderItemIdByKotItemId = [];
                    }

                    // Now create order items for billing
                    $touchedOrderItemIds = [];
                    foreach ($items as $item) {
                        $menuItemId = $item['id'] ?? null;
                        $variantId = $item['variant_id'] ?? 0;
                        $quantity = \App\Support\PosQuantity::normalize($item['quantity'] ?? 1);
                        $price = $item['price'] ?? 0;
                        $itemNote = $item['note'] ?? null;
                        // Do NOT override discounted amounts (stamp/customer-site) by recomputing.
                        $amount = array_key_exists('amount', $item)
                            ? (float)$item['amount']
                            : ((float)$price * (float)$quantity);
                        $modifierIds = $item['modifier_ids'] ?? [];
                        // Treat different modifier combinations as different line items.
                        $normalizedModifierIds = [];
                        if (is_array($modifierIds)) {
                            $normalizedModifierIds = array_values(array_unique(array_filter(array_map('intval', $modifierIds), fn ($v) => $v > 0)));
                            sort($normalizedModifierIds);
                        }
                        $taxAmount = $item['tax_amount'] ?? 0;
                        $taxPercentage = $item['tax_percentage'] ?? 0;
                        $taxBreakup = $item['tax_breakup'] ?? null;

                        [$isFreeItemFromStamp, $stampRuleId] = $normalizeStampFields($item, $itemNote, $menuItemId ? (int)$menuItemId : null);
                        if ($isFreeItemFromStamp) {
                            $amount = 0.0;
                        }

                        $kotItemIdFromKey = null;
                        $itemKey = (string)($item['key'] ?? '');
                        if ($itemKey && preg_match('/^kot_(\d+)_(\d+)$/', $itemKey, $m)) {
                            $kotItemIdFromKey = (int)$m[2];
                        }
                        // If this KOT line is already linked to an order_item, don't create/accumulate again.
                        if ($kotItemIdFromKey && !empty($kotOrderItemIdByKotItemId[$kotItemIdFromKey])) {
                            continue;
                        }

                        $orderItemData = [
                            'branch_id' => $this->branch->id,
                            'order_id' => $order->id,
                            'menu_item_id' => $menuItemId,
                            'menu_item_variation_id' => $variantId > 0 ? $variantId : null,
                            'quantity' => $quantity,
                            'price' => $price,
                            'amount' => $amount,
                            'note' => $itemNote,
                            'order_type' => $orderTypeSlug ?? null,
                            'order_type_id' => $orderTypeId ?? null,
                            'tax_amount' => $taxAmount,
                            'tax_percentage' => $taxPercentage,
                            'tax_breakup' => is_array($taxBreakup) ? json_encode($taxBreakup) : $taxBreakup,
                            // Stamp fields (best-effort)
                            'is_free_item_from_stamp' => $isFreeItemFromStamp,
                            'stamp_rule_id' => $stampRuleId,
                        ];

                        $this->upsertAjaxCartOrderItem(
                            $order,
                            $orderItemData,
                            $normalizedModifierIds,
                            $kotItemIdFromKey,
                            $kotOrderItemIdByKotItemId,
                            $touchedOrderItemIds
                        );
                    }

                    // Create order taxes (order level) without duplicating existing rows
                    if (!empty($taxes) && is_array($taxes)) {
                        foreach ($taxes as $tax) {
                            if (isset($tax['id'])) {
                                OrderTax::firstOrCreate([
                                    'order_id' => $order->id,
                                    'tax_id' => $tax['id'],
                                ]);
                            }
                        }
                    }

                    // Bill+payment flow: persist frontend-calculated totals directly.
                    $order->updateQuietly([
                        'sub_total' => (float)$subTotal,
                        'total' => max(0, (float)$total),
                        'discount_amount' => (float)$discountAmount,
                        'total_tax_amount' => (float)$totalTaxAmount,
                        'tax_base' => $taxBaseFromClient !== null ? (float)$taxBaseFromClient : (float)$discountedTotal,
                        'tax_mode' => $taxMode,
                    ]);

                    // Update status variable for correct response message
                    $status = 'billed';

                    // Mark that order items have been created to prevent duplicate creation
                    $orderItemsAlreadyCreated = true;
                }
            }

            // Create order items (for 'draft' status only, similar to Pos.php)
            if ($status == 'draft') {
                // Persist draft items as OrderItems so draft orders can be reopened/edited
                // Always recreate items for draft saves to match the current cart
                $order->items()->delete();

                foreach ($items as $item) {
                    $menuItemId = $item['id'] ?? null;
                    $variantId = $item['variant_id'] ?? 0;
                    $quantity = \App\Support\PosQuantity::normalize($item['quantity'] ?? 1);
                    $price = $item['price'] ?? 0;
                    $itemNote = $item['note'] ?? null;
                    // Do NOT override discounted amounts (stamp/customer-site) by recomputing.
                    $hasIncomingAmount = array_key_exists('amount', $item);
                    $amount = $hasIncomingAmount
                        ? (float)$item['amount']
                        : ((float)$price * (float)$quantity);
                    $modifierIds = $item['modifier_ids'] ?? [];
                    $taxAmount = (float)($item['tax_amount'] ?? 0);
                    $taxPercentage = (float)($item['tax_percentage'] ?? 0);
                    $taxBreakup = $item['tax_breakup'] ?? null;

                    [$isFreeItemFromStamp, $stampRuleId] = $normalizeStampFields($item, $itemNote, $menuItemId ? (int)$menuItemId : null);
                    if ($isFreeItemFromStamp) {
                        $amount = 0.0;
                    } elseif ($stampRuleId) {
                        $amount = $this->applyStampDiscountToAmount(
                            (int)$menuItemId,
                            (int)$stampRuleId,
                            (float)$price,
                            (float)$quantity,
                            (float)$amount,
                            $customerId ? (int)$customerId : null,
                            $preloadedStampRulesById->get((int)$stampRuleId),
                            $stampDiscountTierMultiplierOverride
                        );
                    }

                    // Set price context if possible (same as billed)
                    $menuItem = $menuItemId ? $menuItemsById->get((int)$menuItemId) : null;
                    if ($menuItem && $orderTypeId) {
                        if (method_exists($menuItem, 'setPriceContext')) {
                            $menuItem->setPriceContext($orderTypeId, null);
                            $price = $menuItem->price ?? $price;
                            // Recalculate amount only when client did not send amount explicitly.
                            if (!$hasIncomingAmount && !$isFreeItemFromStamp) {
                                $amount = ((float)$price) * ((float)$quantity);
                            }
                        }
                    }

                    $orderItem = OrderItem::create([
                        'branch_id' => $this->branch->id,
                        'order_id' => $order->id,
                        'menu_item_id' => $menuItemId,
                        'menu_item_variation_id' => $variantId > 0 ? $variantId : null,
                        'quantity' => $quantity,
                        'price' => $price,
                        'amount' => $amount,
                        'note' => $itemNote,
                        'order_type' => $orderTypeSlug ?? null,
                        'order_type_id' => $orderTypeId ?? null,
                        'tax_amount' => $taxAmount,
                        'tax_percentage' => $taxPercentage,
                        'tax_breakup' => is_array($taxBreakup) ? json_encode($taxBreakup) : $taxBreakup,
                        // Stamp fields (best-effort)
                        'is_free_item_from_stamp' => $isFreeItemFromStamp,
                        'stamp_rule_id' => $stampRuleId,
                    ]);

                    if (!empty($modifierIds) && is_array($modifierIds)) {
                        $orderItem->modifierOptions()->sync($modifierIds);
                    }
                }
            }

            // Create order items (for 'billed' status only, similar to Pos.php)
            // Skip if items were already created in KOT+Bill+Payment flow
            if ($status == 'billed' && !$orderItemsAlreadyCreated) {
                // Guard against double-billing KOT lines: reuse `kot_items.order_item_id` if already linked.
                $kotOrderItemIdByKotItemId = [];
                try {
                    $kotItemIdsInPayload = [];
                    foreach ($items as $payloadItem) {
                        $key = (string)($payloadItem['key'] ?? '');
                        if ($key && preg_match('/^kot_(\d+)_(\d+)$/', $key, $m)) {
                            $kotItemIdsInPayload[] = (int)$m[2];
                        }
                    }
                    $kotItemIdsInPayload = array_values(array_unique(array_filter($kotItemIdsInPayload)));
                    if (!empty($kotItemIdsInPayload)) {
                        $kotOrderItemIdByKotItemId = KotItem::whereIn('id', $kotItemIdsInPayload)
                            ->pluck('order_item_id', 'id')
                            ->toArray();
                    }
                } catch (\Throwable $e) {
                    $kotOrderItemIdByKotItemId = [];
                }

                $touchedOrderItemIds = [];
                foreach ($items as $item) {
                    $menuItemId = $item['id'] ?? null;
                    $variantId = $item['variant_id'] ?? 0;
                    $quantity = \App\Support\PosQuantity::normalize($item['quantity'] ?? 1);
                    $price = $item['price'] ?? 0;
                    $itemNote = $item['note'] ?? null;
                    // Do NOT override discounted amounts (stamp/customer-site) by recomputing.
                    $amount = array_key_exists('amount', $item)
                        ? (float)$item['amount']
                        : ((float)$price * (float)$quantity);
                    $modifierIds = $item['modifier_ids'] ?? [];
                    // Treat different modifier combinations as different line items.
                    $normalizedModifierIds = [];
                    if (is_array($modifierIds)) {
                        $normalizedModifierIds = array_values(array_unique(array_filter(array_map('intval', $modifierIds), fn ($v) => $v > 0)));
                        sort($normalizedModifierIds);
                    }
                    $taxAmount = (float)($item['tax_amount'] ?? 0);
                    $taxPercentage = (float)($item['tax_percentage'] ?? 0);
                    $taxBreakup = $item['tax_breakup'] ?? null;

                    [$isFreeItemFromStamp, $stampRuleId] = $normalizeStampFields($item, $itemNote, $menuItemId ? (int)$menuItemId : null);
                    if ($isFreeItemFromStamp) {
                        $amount = 0.0;
                    }

                    $kotItemIdFromKey = null;
                    $itemKey = (string)($item['key'] ?? '');
                    if ($itemKey && preg_match('/^kot_(\d+)_(\d+)$/', $itemKey, $m)) {
                        $kotItemIdFromKey = (int)$m[2];
                    }
                    if ($kotItemIdFromKey && !empty($kotOrderItemIdByKotItemId[$kotItemIdFromKey])) {
                        continue;
                    }

                    $orderItemData = [
                        'branch_id' => $this->branch->id,
                        'order_id' => $order->id,
                        'menu_item_id' => $menuItemId,
                        'menu_item_variation_id' => $variantId > 0 ? $variantId : null,
                        'quantity' => $quantity,
                        'price' => $price,
                        'amount' => $amount,
                        'note' => $itemNote,
                        'order_type' => $orderTypeSlug ?? null,
                        'order_type_id' => $orderTypeId ?? null,
                        'tax_amount' => $taxAmount,
                        'tax_percentage' => $taxPercentage,
                        'tax_breakup' => is_array($taxBreakup) ? json_encode($taxBreakup) : $taxBreakup,
                        // Stamp fields (best-effort)
                        'is_free_item_from_stamp' => $isFreeItemFromStamp,
                        'stamp_rule_id' => $stampRuleId,
                    ];

                    $this->upsertAjaxCartOrderItem(
                        $order,
                        $orderItemData,
                        $normalizedModifierIds,
                        $kotItemIdFromKey,
                        $kotOrderItemIdByKotItemId,
                        $touchedOrderItemIds
                    );
                }

                // Create order taxes (order level, similar to Pos.php) without duplicates
                if (!empty($taxes) && is_array($taxes)) {
                    foreach ($taxes as $tax) {
                        if (isset($tax['id'])) {
                            OrderTax::firstOrCreate([
                                'order_id' => $order->id,
                                'tax_id' => $tax['id'],
                            ]);
                        }
                    }
                }

                // Billed flow: keep totals exactly as sent by frontend.
                $order->updateQuietly([
                    'sub_total' => (float)$subTotal,
                    'total' => max(0, (float)$total),
                    'discount_amount' => (float)$discountAmount,
                    'total_tax_amount' => (float)$totalTaxAmount,
                    'tax_base' => $taxBaseFromClient !== null ? (float)$taxBaseFromClient : (float)$discountedTotal,
                    'tax_mode' => $taxMode,
                ]);
            }

            // TT parity: after non-draft order items exist, redeem stamps for all eligible items
            // (service-driven eligibility, not only key/note heuristics). Avoid running this for
            // draft orders to prevent double-applying stamp discounts that are already reflected
            // in item amounts from the POS cart.
            //
            // IMPORTANT: Prevent double redemption when moving from an already stamped KOT order
            // to billed. If the order was already in a final-ish state ('kot' or 'billed'),
            // we skip a second automatic redemption pass to avoid granting extra rewards.
            $previousStatus = $order->getOriginal('status');
            $shouldRunStampRedemption = in_array($status, ['kot', 'billed'], true)
                && $order->customer_id
                && $this->isStampsEnabledForPosAjax()
                && !in_array($previousStatus, ['kot', 'billed'], true);

            if ($shouldRunStampRedemption) {
                $stampRedemptionHappened = $this->redeemStampsForEligibleBilledItems($order);
                if ($stampRedemptionHappened) {
                    // Recalculate persisted totals after redemption and use those values downstream.
                    $this->recalculateOrderTotals($order, $cachedTaxesAll, true);
                    $order->refresh();
                    $stampDiscountAmount = (float)($order->stamp_discount_amount ?? $stampDiscountAmount);
                    $subTotal = (float)($order->sub_total ?? $subTotal);
                    $total = (float)($order->total ?? $total);
                    $totalTaxAmount = (float)($order->total_tax_amount ?? $totalTaxAmount);
                    $taxBaseFromClient = (float)($order->tax_base ?? ($taxBaseFromClient ?? 0));
                }
            }

            // For existing KOT orders, recompute totals from all KOT rows after new KOT creation
            // so order-level total reflects cumulative order amount (not only fresh cart payload).
            if ($orderId && $status === 'kot') {
                $this->recalculateOrderTotals($order, $cachedTaxesAll, true);
                $order->refresh();
            }

            // Final persistence step.
            // For existing orders saved as KOT, always persist recalculated DB totals
            // (old + new KOT items) instead of request payload snapshot values.
            $shouldPersistKotRecalculatedTotals = ($orderId && $status === 'kot');
            if ($shouldPersistKotRecalculatedTotals || $preserveDisplayedTotalsForExistingKot) {
                $order->refresh();
                $finalOrderUpdate = [
                    'sub_total' => (float)($order->sub_total ?? 0),
                    'total' => max(0, (float)($order->total ?? 0)),
                    'discount_type' => $order->discount_type,
                    'discount_value' => $order->discount_value,
                    'discount_amount' => (float)($order->discount_amount ?? 0),
                    'stamp_discount_amount' => (float)($order->stamp_discount_amount ?? 0),
                    'loyalty_points_redeemed' => (int)($order->loyalty_points_redeemed ?? 0),
                    'loyalty_discount_amount' => round((float)($order->loyalty_discount_amount ?? 0), 2),
                    'total_tax_amount' => (float)($order->total_tax_amount ?? 0),
                    'tax_base' => (float)($order->tax_base ?? 0),
                    'tax_mode' => $order->tax_mode ?? $taxMode,
                    'tip_amount' => (float)($order->tip_amount ?? $tipAmount),
                    'delivery_fee' => (float)($order->delivery_fee ?? (($orderTypeSlug === 'delivery') ? $deliveryFee : 0)),
                ];
            } elseif ($status === 'billed') {
                // Billed flow must persist frontend snapshot values as-is.
                $finalOrderUpdate = [
                    'sub_total' => (float)$subTotal,
                    'total' => max(0, (float)$total),
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => (float)$discountAmount,
                    'discount_apply_on' => $discountApplyOn,
                    'stamp_discount_amount' => (float)$stampDiscountAmount,
                    'loyalty_points_redeemed' => (int)$loyaltyPointsRedeemed,
                    'loyalty_discount_amount' => round((float)$loyaltyDiscountAmount, 2),
                    'total_tax_amount' => (float)$totalTaxAmount,
                    'tax_base' => $taxBaseFromClient !== null ? (float)$taxBaseFromClient : (float)$discountedTotal,
                    'tax_mode' => $taxMode,
                    'tip_amount' => (float)$tipAmount,
                    'delivery_fee' => (float)(($orderTypeSlug === 'delivery') ? $deliveryFee : 0),
                ];
            } else {
                // Always recompute totals server-side before final save to guarantee backend order:
                // discount -> charges -> tax (on discounted tax_base) -> tip/delivery.
                $this->recalculateOrderTotals($order, $cachedTaxesAll, true);
                $order->refresh();

                $finalOrderUpdate = [
                    'sub_total' => (float)($order->sub_total ?? 0),
                    'total' => max(0, (float)($order->total ?? 0)),
                    'discount_type' => $discountType,
                    'discount_value' => $discountValue,
                    'discount_amount' => (float)($order->discount_amount ?? 0),
                    'discount_apply_on' => $order->discount_apply_on ?? $discountApplyOn,
                    'stamp_discount_amount' => (float)$stampDiscountAmount,
                    'loyalty_points_redeemed' => (int)$loyaltyPointsRedeemed,
                    'loyalty_discount_amount' => round((float)$loyaltyDiscountAmount, 2),
                    'total_tax_amount' => (float)($order->total_tax_amount ?? 0),
                    'tax_base' => (float)($order->tax_base ?? 0),
                    'tax_mode' => $taxMode,
                    'tip_amount' => (float)($order->tip_amount ?? $tipAmount),
                    'delivery_fee' => (float)($order->delivery_fee ?? (($orderTypeSlug === 'delivery') ? $deliveryFee : 0)),
                ];
            }

            if ($status !== 'billed') {
                // Final normalization: tax_base must match taxable amount shown in POS.
                // Rule agreed: taxable amount = sub_total - discount_amount (charges excluded).
                $normalizedSubTotal = (float)($finalOrderUpdate['sub_total'] ?? 0);
                $normalizedDiscount = (float)($finalOrderUpdate['discount_amount'] ?? 0);
                $normalizedTaxable = max(0, round($normalizedSubTotal - $normalizedDiscount, 2));
                $finalOrderUpdate['tax_base'] = $normalizedTaxable;
                // Keep subtotal aligned with frontend rule: subtotal = taxable + discount.
                $finalOrderUpdate['sub_total'] = round($normalizedTaxable + $normalizedDiscount, 2);

                // Normalize final total from persisted parts to avoid branch drift:
                // total = taxable + applicable charges + tax + tip + delivery (delivery only for delivery orders)
                $normalizedServiceTotal = 0.0;
                try {
                    $orderCharges = $order->charges()->with('charge')->get();
                    foreach ($orderCharges as $orderCharge) {
                        if (!$orderCharge->charge) {
                            continue;
                        }
                        $charge = $orderCharge->charge;
                        $allowedTypes = $charge->order_types ?? [];
                        if (!empty($allowedTypes) && $orderTypeSlug && !in_array($orderTypeSlug, $allowedTypes)) {
                            continue;
                        }
                        $normalizedServiceTotal += (float) $charge->getAmount($normalizedTaxable);
                    }
                } catch (\Throwable $e) {
                    // Fail-safe: if charge normalization fails, keep service total at 0.
                }

                $normalizedTax = (float)($finalOrderUpdate['total_tax_amount'] ?? 0);
                $normalizedTip = (float)($finalOrderUpdate['tip_amount'] ?? 0);
                $normalizedDelivery = ($orderTypeSlug === 'delivery')
                    ? (float)($finalOrderUpdate['delivery_fee'] ?? 0)
                    : 0.0;

                $isInclusive = (bool) ($this->restaurant->tax_inclusive ?? false);
                if ($isInclusive) {
                    $order->loadMissing(['items', 'kot.items']);
                    $grossItemTotal = (float) ($order->items->sum('amount') ?? 0);
                    if ($grossItemTotal <= 0 && $order->kot) {
                        foreach ($order->kot as $kot) {
                            $grossItemTotal += (float) $kot->items
                                ->where('status', '!=', 'cancelled')
                                ->sum('amount');
                        }
                    }
                    $loyaltyDiscount = (float)($finalOrderUpdate['loyalty_discount_amount'] ?? 0);
                    $finalOrderUpdate['total'] = max(0, round(
                        $grossItemTotal - $normalizedDiscount - $loyaltyDiscount + $normalizedTip + $normalizedDelivery,
                        2
                    ));
                } else {
                    $finalOrderUpdate['total'] = max(0, round(
                        $normalizedTaxable + $normalizedServiceTotal + $normalizedTax + $normalizedTip + $normalizedDelivery,
                        2
                    ));
                }
            }

            $order->update($finalOrderUpdate);

            // Update table status (similar to Pos.php)
            if ($table) {
                $table->available_status = $tableStatus;
                $table->saveQuietly();
            }

            // Optional: Deduct loyalty points via module service (if installed).
            // Guarded to prevent duplicate deduction on updates and to avoid double-applying
            // loyalty discounts for draft orders (points should be finalized on non-draft statuses only).
            if (
                $this->isLoyaltyEnabledForPos()
                && $status !== 'draft'
                && $loyaltyPointsRedeemed > 0
                && $loyaltyDiscountAmount > 0
                && $order->customer_id
                && $existingRedeemedPoints === 0
            ) {
                try {
                    $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                    if (method_exists($loyaltyService, 'redeemPoints')) {
                        $loyaltyService->redeemPoints($order, $loyaltyPointsRedeemed);
                    }
                } catch (\Exception $e) {
                    Log::error('POS loyalty redeemPoints failed: ' . $e->getMessage());
                }
            }

            // New non-draft create: OrderObserver::created already queued NewOrderCreated (after-commit).
            // Do not rely on wasRecentlyCreated (cleared by follow-up save() on new orders).
            $observerAlreadyQueuedNewOrderNotification = $createdNewOrderInSubmit && $status !== 'draft';

            DB::commit();
            TablesIndexCache::forgetForBranch((int) $this->branch->id);

            $order->refresh();
            OrderWaiterResponseService::autoAcceptWhenPlacedByWaiterOnPos($order);

            if (!$observerAlreadyQueuedNewOrderNotification) {
                NewOrderCreated::dispatch($order->fresh());
            }

            // Delete merged table orders if order is KOT or billed (not draft)
            // This handles the case when merging tables and saving the order
            if ($status !== 'draft' && !empty($ordersToDeleteAfterMerge) && is_array($ordersToDeleteAfterMerge)) {
                try {
                    // Get all orders to delete with their relationships
                    $ordersToDelete = Order::whereIn('id', $ordersToDeleteAfterMerge)
                        ->where('branch_id', $this->branch->id) // Ensure we only delete orders from this branch
                        ->with(['kot.items', 'items', 'taxes', 'charges'])
                        ->get();

                    if ($ordersToDelete->isNotEmpty()) {
                        $orderIds = $ordersToDelete->pluck('id')->toArray();

                        // Collect KOT IDs from loaded relationships
                        $kotIds = $ordersToDelete->flatMap(function ($order) {
                            return $order->kot->pluck('id');
                        })->filter()->unique()->toArray();

                        // Bulk delete KOT items
                        if (!empty($kotIds)) {
                            KotItem::whereIn('kot_id', $kotIds)->delete();
                            Kot::whereIn('id', $kotIds)->delete();
                        }

                        // Bulk delete order items, taxes, and charges
                        OrderItem::whereIn('order_id', $orderIds)->delete();
                        OrderTax::whereIn('order_id', $orderIds)->delete();
                        OrderCharge::whereIn('order_id', $orderIds)->delete();

                        // Get table IDs from orders before deleting
                        $tableIds = $ordersToDelete->pluck('table_id')->filter()->unique()->toArray();

                        // Bulk delete orders
                        Order::whereIn('id', $orderIds)->delete();

                        // Update table statuses and unlock tables
                        if (!empty($tableIds)) {
                            Table::whereIn('id', $tableIds)->update(['available_status' => 'available']);
                            foreach (Table::whereIn('id', $tableIds)->get() as $tableToUnlock) {
                                $tableToUnlock->unlock(null, true);
                            }
                        }

                        $deletedCount = count($orderIds);
                        Log::info("Deleted {$deletedCount} order(s) from merged tables via AJAX");
                    }

                    // Clear session data after successful deletion
                    session()->forget('pos_merged_orders_to_delete');
                } catch (\Exception $e) {
                    Log::error('Error deleting merged table orders via AJAX: ' . $e->getMessage(), [
                        'trace' => $e->getTraceAsString(),
                        'order_ids' => $ordersToDeleteAfterMerge,
                    ]);
                    // Clear session even on error to prevent retry issues
                    session()->forget('pos_merged_orders_to_delete');
                }
            }

            // Get payment gateway QR code if applicable
            $qrCodeImageUrl = restaurant()->customerDisplayQrCodeImageUrl();

            if ($status === 'billed') {
                $customerDisplayData = [
                    'order_number' => $order->order_number,
                    'formatted_order_number' => $order->formatted_order_number,
                    'items' => [],
                    'sub_total' => 0,
                    'discount' => 0,
                    'total' => $order->total,
                    'taxes' => [],
                    'extra_charges' => [],
                    'tip' => $order->tip_amount ?? 0,
                    'delivery_fee' => $order->delivery_fee ?? 0,
                    'order_type' => $orderTypeDisplay,
                    'status' => 'billed',
                    'cash_due' => $order->total,
                    'qr_code_image_url' => $qrCodeImageUrl,
                ];
                $this->updateCustomerDisplayCache($customerDisplayData);
            } else {
                // For other statuses (kot, draft), reset to idle (matches Livewire pattern)
                $customerDisplayData = [
                    'order_number' => null,
                    'formatted_order_number' => null,
                    'items' => [],
                    'sub_total' => 0,
                    'discount' => 0,
                    'total' => 0,
                    'taxes' => [],
                    'extra_charges' => [],
                    'tip' => 0,
                    'delivery_fee' => 0,
                    'order_type' => null,
                    'status' => 'idle',
                    'cash_due' => null,
                    'qr_code_image_url' => null,
                ];
                $this->updateCustomerDisplayCache($customerDisplayData);
            }

            // Load relationships for response
            $order->load(['items', 'customer', 'table', 'waiter', 'kot']);

            // Return success message based on status (similar to Pos.php)
            $successMessage = 'Order created successfully';
            if ($status == 'kot') {
                $successMessage = __('messages.kotGenerated');
            } elseif ($status == 'billed') {
                $successMessage = __('messages.billedSuccess');
            } elseif ($status == 'draft') {
                $successMessage = __('messages.orderSavedAsDraft');
            }

            return response()->json([
                'success' => true,
                'message' => $successMessage,
                'order' => $order,
                'order_id' => $order->id,  // Also include order_id for easier access
                'kot' => $kot,
                // All KOT rows created this save (kitchen module = one per kot_place); used for multi-print.
                'kot_ids' => $kotIds,
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('POS Order Creation Error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create order: ' . $e->getMessage(),
                'error' => $e->getMessage(),
            ], 500);
        }
    }


    public function printOrder($order)
    {
        // Ensure $order is an Order model instance
        if (is_numeric($order)) {
            $order = Order::find($order);
        }

        if (!$order) {
            Log::warning('[PosAjaxController] printOrder: order not found');

            return;
        }

        // Check if order has split payments - if yes, show modal
        if ($order->split_type && $order->splitOrders()->where('status', 'paid')->count() > 0) {
            $this->showPrintOptionsModal = true;
            $this->printMode = null;
            $this->selectedSplitId = null;
            return;
        }

        // No splits - execute normal print
        $this->executePrint($order->id);
    }

    private function executePrint($orderId)
    {
        $order = Order::find($orderId);
        if (!$order) {
            return;
        }

        $orderPlace = \App\Models\MultipleOrder::with('printerSetting')->first();
        $printerSetting = $orderPlace?->printerSetting;

        try {
            switch ($printerSetting?->printing_choice) {
                case 'directPrint':
                    $this->handleOrderPrint($orderId);
                    break;
                default:
                    $url = route('orders.print', $orderId);
                    $this->dispatch('print_location', $url);
                    break;
            }
        } catch (\Throwable $e) {
            Log::error('[PosAjaxController] executePrint failed: ' . $e->getMessage());
        }
    }

    /**
     * Shared KOT print routing (matches Livewire Pos::printKot). Callbacks replace $this->dispatch / alerts.
     *
     * @param  callable(string): void  $emitUrl
     * @param  callable(int, int): void  $doDirectPrint
     * @param  callable(string): void  $emitError
     */
    private function applyKotPrinting(
        Order $order,
        ?Kot $kotContext,
        array $kotIds,
        callable $emitUrl,
        callable $doDirectPrint,
        callable $emitError
    ): void {
        if (in_array('Kitchen', restaurant_modules()) && in_array('kitchen', custom_module_plugins())) {
            if ($kotIds) {
                $kots = $order->kot()->whereIn('id', $kotIds)->with('items')->get();
            } else {
                $kots = $order->kot()->with('items')->get();
            }

            foreach ($kots as $kotRow) {
                $kotPlaceItems = [];

                foreach ($kotRow->items as $kotItem) {
                    if ($kotItem->menuItem && $kotItem->menuItem->kot_place_id) {
                        $kotPlaceId = $kotItem->menuItem->kot_place_id;

                        if (!isset($kotPlaceItems[$kotPlaceId])) {
                            $kotPlaceItems[$kotPlaceId] = [];
                        }

                        $kotPlaceItems[$kotPlaceId][] = $kotItem;
                    }
                }

                $kotPlaceIds = array_keys($kotPlaceItems);
                $kotPlaces = KotPlace::with('printerSetting')->whereIn('id', $kotPlaceIds)->get();

                foreach ($kotPlaces as $kotPlace) {
                    $printerSetting = $kotPlace->printerSetting;

                    if ($printerSetting && $printerSetting->is_active == 0) {
                        $printerSetting = Printer::where('is_default', true)->first();
                    }

                    if (!$printerSetting) {
                        $emitUrl(route('kot.print', [$kotRow->id, $kotPlace?->id]));
                        continue;
                    }

                    try {
                        switch ($printerSetting->printing_choice) {
                            case 'directPrint':
                                $doDirectPrint($kotRow->id, $kotPlace->id);
                                break;
                            default:
                                $emitUrl(route('kot.print', [$kotRow->id, $kotPlace?->id]));
                                break;
                        }
                    } catch (\Throwable $e) {
                        $emitError(__('messages.printerNotConnected') . ' ' . $e->getMessage());
                    }
                }

                // No kot_place groups (e.g. items missing kot_place_id): still print once via default kitchen
                if ($kotPlaces->isEmpty()) {
                    $defaultKotPlace = KotPlace::where('is_default', 1)->with('printerSetting')->first();
                    $printerSetting = $defaultKotPlace?->printerSetting;

                    if ($printerSetting && $printerSetting->is_active == 0) {
                        $printerSetting = Printer::where('is_default', true)->first();
                    }

                    if (!$printerSetting) {
                        $emitUrl(route('kot.print', [$kotRow->id, $defaultKotPlace?->id]));

                        continue;
                    }

                    $placeIdForPrint = (int) ($defaultKotPlace?->id ?? 0);
                    if ($placeIdForPrint < 1) {
                        $emitUrl(route('kot.print', [$kotRow->id]));

                        continue;
                    }

                    try {
                        switch ($printerSetting->printing_choice) {
                            case 'directPrint':
                                $doDirectPrint($kotRow->id, $placeIdForPrint);
                                break;
                            default:
                                $emitUrl(route('kot.print', [$kotRow->id, $placeIdForPrint]));
                                break;
                        }
                    } catch (\Throwable $e) {
                        $emitError(__('messages.printerNotConnected') . ' ' . $e->getMessage());
                    }
                }
            }
        } else {
            $kotPlace = KotPlace::where('is_default', 1)->first();
            $printerSetting = $kotPlace?->printerSetting;

            $kotRow = $kotContext ?? $order->kot()->first();

            if (!$kotRow) {
                $emitError(__('messages.orderNotFound'));

                return;
            }

            if (!$printerSetting) {
                $emitUrl(route('kot.print', [$kotRow->id, $kotPlace?->id]));

                return;
            }

            try {
                switch ($printerSetting->printing_choice) {
                    case 'directPrint':
                        $doDirectPrint($kotRow->id, $kotPlace->id);
                        break;
                    default:
                        $emitUrl(route('kot.print', [$kotRow->id]));
                        break;
                }
            } catch (\Throwable $e) {
                $emitError(__('messages.printerNotConnected') . ' ' . $e->getMessage());
            }
        }
    }

    public function printKot($order, $kot = null, $kotIds = [])
    {
        $this->applyKotPrinting(
            $order,
            $kot,
            $kotIds,
            fn (string $url) => $this->dispatch('print_location', $url),
            fn (int $kotId, int $kotPlaceId) => $this->handleKotPrint($kotId, $kotPlaceId),
            fn (string $message) => Log::warning('[PosAjaxController] printKot: ' . $message)
        );
    }

    /**
     * Build JSON payload for AJAX KOT print using the same routing as Livewire Pos::printKot($order, $kot, $kotIds).
     *
     * Manual test matrix (kitchen = Kitchen module + kitchen plugin):
     * - Kitchen ON, kot_ids [a,b]: one applyKotPrinting pass — each KOT split by item kot_place → multiple URLs/direct jobs.
     * - Kitchen ON, kot_ids []: all KOTs on order (same as Livewire empty $kotIds).
     * - Kitchen OFF, kot_ids []: uses $kotContext ?? first KOT on order (default station).
     * - Kitchen OFF, kot_ids [a,b]: one print pass per KOT id (multiple tickets if user created several KOTs).
     */
    private function buildAjaxKotPrintPayload(Order $order, ?Kot $kotContext = null, array $kotIds = []): array
    {
        $urls = [];
        $errors = [];
        $direct = false;

        $emitUrl = function (string $url) use (&$urls): void {
            $urls[] = $url;
        };
        $doDirectPrint = function (int $kotId, int $kotPlaceId) use (&$direct): void {
            $this->handleKotPrint($kotId, $kotPlaceId);
            $direct = true;
        };
        $emitError = function (string $message) use (&$errors): void {
            $errors[] = $message;
        };

        $kitchenOn = in_array('Kitchen', restaurant_modules()) && in_array('kitchen', custom_module_plugins());

        try {
            if ($kitchenOn) {
                $this->applyKotPrinting(
                    $order,
                    null,
                    $kotIds,
                    $emitUrl,
                    $doDirectPrint,
                    $emitError
                );
            } else {
                $ids = array_values(array_unique(array_filter(array_map('intval', $kotIds))));
                if ($ids === []) {
                    $this->applyKotPrinting(
                        $order,
                        $kotContext,
                        [],
                        $emitUrl,
                        $doDirectPrint,
                        $emitError
                    );
                } else {
                    foreach ($ids as $kid) {
                        $row = Kot::where('order_id', $order->id)->find($kid);
                        if ($row) {
                            $this->applyKotPrinting(
                                $order,
                                $row,
                                [],
                                $emitUrl,
                                $doDirectPrint,
                                $emitError
                            );
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => __('messages.printerNotConnected') . ' ' . $e->getMessage(),
                'http_status' => 500,
            ];
        }

        $urls = array_values(array_unique($urls));
        $success = $direct || count($urls) > 0;

        if (!$success) {
            return [
                'success' => false,
                'message' => count($errors) ? implode(' ', $errors) : __('messages.printerNotConnected'),
                'errors' => $errors,
                'http_status' => 200,
            ];
        }

        $payload = [
            'success' => true,
            'direct' => $direct,
            'urls' => $urls,
            'message' => __('modules.kot.print_success'),
            'http_status' => 200,
        ];

        if (count($urls) === 1 && !$direct) {
            $payload['mode'] = 'url';
            $payload['url'] = $urls[0];
        } elseif ($direct && count($urls) === 0) {
            $payload['mode'] = 'direct';
        } else {
            $payload['mode'] = 'mixed';
        }

        if (count($errors) > 0) {
            $payload['warnings'] = $errors;
        }

        $dispatches = $this->pullBrowserDispatches();
        if (count($dispatches) > 0) {
            $payload['dispatches'] = $dispatches;
        }

        return $payload;
    }

    /**
     * AJAX POS: print KOT(s) for an order — same as Livewire printKot($order, $kotContext, $kotIds).
     *
     * Body: kot_ids[] (optional, empty = all KOTs when kitchen on; when kitchen off and empty, uses kot_id or first KOT).
     * Body: kot_id (optional) context when kitchen off and kot_ids empty.
     */
    public function ajaxPrintKotForOrder(Request $request, $orderId): \Illuminate\Http\JsonResponse
    {
        $orderId = (int) $orderId;
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('messages.orderNotFound'),
            ], 404);
        }

        $rawIds = $request->input('kot_ids', []);
        if (!is_array($rawIds)) {
            $rawIds = [];
        }
        $kotIds = array_values(array_unique(array_filter(array_map('intval', $rawIds))));

        $kotContext = null;
        if ($request->filled('kot_id')) {
            $kotContext = Kot::where('order_id', $order->id)->find((int) $request->input('kot_id'));
        }

        $payload = $this->buildAjaxKotPrintPayload($order, $kotContext, $kotIds);
        $http = (int) ($payload['http_status'] ?? 200);
        unset($payload['http_status']);

        return response()->json($payload, $http);
    }

    /**
     * AJAX POS: resolve order print (direct vs browser URL) same as executePrint / Pos::printOrder.
     */
    public function ajaxPrintOrder(Request $request, $orderId)
    {
        $orderId = (int) $orderId;
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('messages.orderNotFound'),
            ], 404);
        }

        if ($order->split_type && $order->splitOrders()->where('status', 'paid')->count() > 0) {
            return response()->json([
                'success' => true,
                'mode' => 'print_options',
                'message' => __('modules.order.selectPrintOption'),
            ]);
        }

        $orderPlace = MultipleOrder::with('printerSetting')->first();
        $printerSetting = $orderPlace?->printerSetting;

        try {
            switch ($printerSetting?->printing_choice) {
                case 'directPrint':
                    $this->handleOrderPrint($orderId);
                    $payload = [
                        'success' => true,
                        'mode' => 'direct',
                        'message' => __('modules.kot.print_success'),
                    ];
                    $dispatches = $this->pullBrowserDispatches();
                    if (count($dispatches) > 0) {
                        $payload['dispatches'] = $dispatches;
                    }

                    return response()->json($payload);
                default:
                    $url = route('orders.print', $orderId);

                    return response()->json([
                        'success' => true,
                        'mode' => 'url',
                        'url' => $url,
                        'message' => __('modules.kot.print_success'),
                    ]);
            }
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => __('messages.printerNotConnected') . ' : ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * AJAX POS: print one KOT by id — delegates to buildAjaxKotPrintPayload (same as Livewire printKot for that row).
     */
    public function ajaxPrintKot(Request $request, $kotId)
    {
        $kotId = (int) $kotId;
        $kot = Kot::with(['order'])->find($kotId);

        if (!$kot || !$kot->order) {
            return response()->json([
                'success' => false,
                'message' => __('messages.orderNotFound'),
            ], 404);
        }

        $payload = $this->buildAjaxKotPrintPayload($kot->order, $kot, [$kot->id]);
        $http = (int) ($payload['http_status'] ?? 200);
        unset($payload['http_status']);

        return response()->json($payload, $http);
    }

    public function getOrder($id)
    {
        $relations = [
            'items',
            'customer',
            'table',
            'waiter',
            'kot' => function ($query) {
                $query->orderBy('created_at', 'asc');
            },
            'kot.items',
            'kot.items.menuItem',
            'kot.items.menuItemVariation',
            'kot.items.modifierOptions',
        ];

        if (isHotelModuleEnabled()) {
            $relations[] = 'hotelStay.room';
            $relations[] = 'hotelRoom';
        }

        $order = Order::with($relations)->find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => 'Order not found',
            ], 404);
        }

        if (isHotelModuleEnabled() && $order->order_type === 'room_service') {
            $order->append(['context_room_number', 'context_stay_number']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Order fetched successfully',
            'order' => $order,
        ], 200);
    }

    public function getOrders($status = null)
    {
        $orders = Order::where('branch_id', $this->branch->id)
            ->with('items', 'customer', 'table', 'waiter', 'kot', 'kot.items', 'kot.items.menuItem');

        if ($status) {
            $orders->where('order_status', OrderStatus::from($status));
        }

        $orders = OrderResource::collection($orders->get());
        return response()->json($orders);
    }

    public function getTaxes()
    {
        $taxes = Tax::get();
        return response()->json($taxes);
    }

    public function getRestaurants()
    {
        $restaurant = Restaurant::with('currency')->where('id', $this->restaurant->id)->first();
        return response()->json($restaurant);
    }

    public function checkMenuItemStock(Request $request)
    {
        $menuItemId = (int) $request->input('menu_item_id');
        $variantId = (int) $request->input('variant_id', 0);
        $modifierIds = array_values(array_filter(array_map(
            'intval',
            is_array($request->input('modifier_ids')) ? $request->input('modifier_ids') : [],
        )));
        $quantity = PosQuantity::normalize($request->input('quantity', 1));

        $menuItem = MenuItem::find($menuItemId);

        if (! $menuItem) {
            return response()->json([
                'success' => false,
                'in_stock' => false,
                'message' => __('messages.notFound'),
            ], 404);
        }

        if (! IngredientStockGuard::resolveInStockForCatalog(
            (int) $this->branch->id,
            $menuItemId,
            (bool) $menuItem->in_stock,
        )) {
            return response()->json([
                'success' => true,
                'in_stock' => false,
                'message' => IngredientStockGuard::insufficientStockMessage(),
            ]);
        }

        $validation = IngredientStockGuard::validateOrderLine(
            (int) $this->branch->id,
            $menuItemId,
            $variantId > 0 ? $variantId : null,
            $modifierIds,
            $quantity,
        );

        return response()->json([
            'success' => true,
            'in_stock' => (bool) ($validation['ok'] ?? false),
            'message' => $validation['error'] ?? null,
        ]);
    }

    public function addCartItem(Request $request)
    {
        $menuItemId = $request->input('menu_item_id');
        $variationId = $request->input('variation_id');
        $orderTypeId = $request->input('order_type_id');
        $deliveryAppId = $request->input('delivery_app_id');

        $menuItem = MenuItem::with(['prices', 'variations.prices', 'modifierGroups.options.prices'])->find($menuItemId);

        if (!$menuItem) {
            return response()->json(['success' => false, 'message' => 'Menu item not found'], 404);
        }

        if (! IngredientStockGuard::resolveInStockForCatalog(
            (int) $this->branch->id,
            (int) $menuItem->id,
            (bool) $menuItem->in_stock,
        )) {
            return response()->json([
                'success' => false,
                'message' => IngredientStockGuard::insufficientStockMessage(),
            ], 422);
        }

        // Set price context
        if ($orderTypeId) {
            $normalizedDeliveryAppId = ($deliveryAppId === 'default' || !$deliveryAppId) ? null : (int)$deliveryAppId;
            $menuItem->setPriceContext($orderTypeId, $normalizedDeliveryAppId);
        }

        return response()->json([
            'success' => true,
            'menu_item' => $menuItem,
            'has_variations' => $menuItem->variations->count() > 0,
            'has_modifiers' => $menuItem->modifierGroups->count() > 0
        ]);
    }

    public function updateCartItem(Request $request)
    {
        // Handle cart item updates
        return response()->json(['success' => true]);
    }

    public function deleteCartItem(Request $request)
    {
        $itemKey = $request->input('item_key');
        $orderId = $request->input('order_id');

        if (!$itemKey) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.itemNotFound')
            ], 400);
        }

        // Parse the item key to determine if it's a draft order item or KOT item
        $parts = explode('_', str_replace('"', '', $itemKey));

        // Get order ID from posState or orderDetail
        $orderId = $orderId ?? null;

        // Check if it's a draft order item (format: order_item_123)
        if (count($parts) >= 3 && $parts[0] === 'order' && $parts[1] === 'item') {
            $orderItemId = $parts[2];

            if ($orderId) {
                return $this->deleteOrderItem($orderId, $orderItemId);
            }

            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound')
            ], 404);
        }

        // Check if it's a KOT item (format: kot_123_456)
        if (count($parts) >= 3 && $parts[0] === 'kot') {
            $kotId = $parts[1];
            $kotItemId = $parts[2];

            if (!$orderId) {
                return response()->json([
                    'success' => false,
                    'message' => __('modules.order.orderNotFound')
                ], 404);
            }

            if (!user_can('Delete Item After KOT')) {
                return response()->json([
                    'success' => false,
                    'message' => __('messages.permissionDenied')
                ], 403);
            }

            // Delete the KOT item
            $kotItem = KotItem::where('id', $kotItemId)
                ->where('kot_id', $kotId)
                ->first();

            if (!$kotItem) {
                return response()->json([
                    'success' => false,
                    'message' => __('modules.order.itemNotFound')
                ], 404);
            }

            $kotItem->delete();

            // Check if KOT is now empty
            $kot = Kot::find($kotId);
            if ($kot && $kot->items()->count() === 0) {
                $kot->delete();

                // Check if order has any remaining KOTs
                $order = Order::find($orderId);
                if ($order && $order->kot()->count() === 0) {
                    // No KOTs left, delete the entire order
                    if ($order->table_id) {
                        Table::where('id', $order->table_id)->update(['available_status' => 'available']);
                    }

                    OrderItem::where('order_id', $order->id)->delete();
                    OrderTax::where('order_id', $order->id)->delete();
                    OrderCharge::where('order_id', $order->id)->delete();
                    $order->delete();

                    return response()->json([
                        'success' => true,
                        'message' => __('messages.orderDeleted'),
                        'order_deleted' => true,
                        'redirect' => route('pos.index')
                    ]);
                }
            }

            // Recalculate order totals
            $order = Order::find($orderId);
            if ($order) {
                $this->recalculateOrderTotals($order);
                $order->refresh();
            }

            $currencyId = $this->restaurant->currency_id ?? null;

            return response()->json([
                'success' => true,
                'message' => __('modules.order.itemDeleted'),
                'order' => $order ? [
                    'items_count' => $order->items()->count(),
                    'sub_total' => number_format($order->sub_total ?? 0, 2, '.', ''),
                    'discount_amount' => number_format($order->discount_amount ?? 0, 2, '.', ''),
                    'total_tax_amount' => number_format($order->total_tax_amount ?? 0, 2, '.', ''),
                    'total' => number_format($order->total ?? 0, 2, '.', ''),
                    'sub_total_formatted' => currency_format($order->sub_total ?? 0, $currencyId),
                    'discount_amount_formatted' => currency_format($order->discount_amount ?? 0, $currencyId),
                    'total_tax_amount_formatted' => currency_format($order->total_tax_amount ?? 0, $currencyId),
                    'total_formatted' => currency_format($order->total ?? 0, $currencyId),
                ] : null
            ]);
        }

        // For new items not yet saved (no prefix), just return success
        // as they only exist in client-side state
        return response()->json([
            'success' => true,
            'message' => __('modules.order.itemDeleted')
        ]);
    }

    public function setTable(Request $request)
    {
        $rawTableId = $request->input('table_id');
        $tableId = (is_numeric($rawTableId) && (int) $rawTableId > 0)
            ? (int) $rawTableId
            : null;
        $orderId = $request->input('order_id');

        if (! $tableId) {
            return response()->json([
                'success' => false,
                'message' => __('messages.setTableNo'),
            ], 422);
        }

        $table = Table::find($tableId);

        if (!$table) {
            return response()->json(['success' => false, 'message' => 'Table not found'], 404);
        }

        $excludeOrderId = is_numeric($orderId) && (int) $orderId > 0 ? (int) $orderId : null;
        if ($table->isSeatFull($excludeOrderId)) {
            return response()->json([
                'success' => false,
                'message' => __('messages.tableSeatsFull', ['table' => $table->table_code]),
            ], 422);
        }

        if ($excludeOrderId) {
            $orderPax = (int) (Order::whereKey($excludeOrderId)->value('number_of_pax') ?? 1);
            $paxToFit = max($orderPax, 1);
            if (!$table->canAcceptPax($paxToFit, $excludeOrderId)) {
                $remaining = (int) ($table->remainingSeats($excludeOrderId) ?? 0);

                return response()->json([
                    'success' => false,
                    'message' => __('messages.paxExceedsRemainingSeats', [
                        'pax' => $paxToFit,
                        'remaining' => $remaining,
                        'table' => $table->table_code,
                    ]),
                ], 422);
            }
        }

        // Check table lock
        if (!$table->canBeAccessedByUser(auth()->id())) {
            $session = $table->tableSession;
            $lockedByUser = $session?->lockedByUser;
            $lockedUserName = $lockedByUser?->name ?? 'Another user';

            return response()->json([
                'success' => false,
                'message' => __('messages.tableLockedByUser', ['user' => $lockedUserName])
            ], 403);
        }

        // Lock table
        $lockResult = $table->lockForUser(auth()->id());

        if (!$lockResult['success']) {
            return response()->json([
                'success' => false,
                'message' => __('messages.tableLockFailed')
            ], 500);
        }

        // If order ID is provided, update the order's table
        if ($orderId) {
            $order = Order::find($orderId);
            if ($order) {
                $previousTable = $order->table_id ? Table::find($order->table_id) : null;
                $isTableChange = (int) $order->table_id !== (int) $tableId;

                $order->update(['table_id' => $tableId]);

                // Update table status if order is from today
                if ($order->date_time && $order->date_time->format('d-m-Y') == now()->format('d-m-Y')) {
                    Table::where('id', $tableId)->update(['available_status' => 'running']);
                }

                // Match Livewire Pos::dispatchOrderTableAssignedEvent (notifications / listeners)
                if ($isTableChange) {
                    $order->refresh();
                    $order->loadMissing(['waiter', 'table', 'branch.restaurant', 'customer']);
                    OrderTableAssigned::dispatch($order, $table, $previousTable);
                }
            }
        }

        TablesIndexCache::forgetForBranch((int) $this->branch->id);

        return response()->json([
            'success' => true,
            'message' => __('messages.tableLocked', ['table' => $table->table_code]),
            'table' => $table
        ]);
    }

    public function setCustomer(Request $request)
    {
        $customerId = $request->input('customer_id');
        $customer = Customer::find($customerId);

        if (!$customer) {
            return response()->json(['success' => false, 'message' => 'Customer not found'], 404);
        }

        return response()->json([
            'success' => true,
            'customer' => $customer
        ]);
    }

    public function saveOrder(Request $request)
    {
        // This is similar to submitOrder but for updating existing orders
        return $this->submitOrder($request);
    }

    /**
     * Apply a single full / partial payment for Blade POS offline sync.
     * Mirrors the non–split path in {@see \App\Livewire\Order\AddPayment::submitForm()}.
     * Split bills must be completed online.
     */
    public function syncOfflinePayment(Request $request)
    {
        $orderId = (int) $request->input('order_id', 0);
        if ($orderId <= 0) {
            return response()->json([
                'success' => false,
                'message' => __('messages.orderNotFound'),
            ], 422);
        }

        $order = Order::with(['branch.restaurant'])->find($orderId);
        if (! $order) {
            return response()->json([
                'success' => false,
                'message' => __('messages.orderNotFound'),
            ], 404);
        }

        if ($order->status === 'paid') {
            return response()->json([
                'success' => true,
                'message' => __('messages.paymentDoneSuccessfully'),
                'order' => $order,
            ]);
        }

        if (! empty($order->split_type)) {
            return response()->json([
                'success' => false,
                'message' => __('messages.posOfflineSplitPaymentUnsupported'),
            ], 422);
        }

        $paymentMethod = (string) $request->input('payment_method', 'cash');
        $allowedMethods = ['cash', 'card', 'upi', 'bank_transfer', 'due'];
        if (! in_array($paymentMethod, $allowedMethods, true)) {
            return response()->json([
                'success' => false,
                'message' => __('messages.invalidPaymentMethod'),
            ], 422);
        }

        $returnAmount = round((float) $request->input('return_amount', 0), 2);
        $tendered = round((float) $request->input('payment_amount', 0), 2);
        if ($tendered < 0) {
            $tendered = 0;
        }
        if ($returnAmount < 0) {
            $returnAmount = 0;
        }

        try {
            DB::transaction(function () use ($orderId, $paymentMethod, $tendered, $returnAmount) {
                $order = Order::lockForUpdate()->with([
                    'items',
                    'items.menuItem',
                    'taxes',
                    'payments',
                    'splitOrders.items',
                    'branch.restaurant',
                ])->find($orderId);

                if (! $order || $order->status === 'paid') {
                    return;
                }

                if (! empty($order->split_type)) {
                    throw new \RuntimeException(__('messages.posOfflineSplitPaymentUnsupported'));
                }

                $restaurant = $order->branch?->restaurant ?? restaurant();
                $availability = RestaurantAvailabilityService::getAvailability($restaurant, $order->branch);
                if (! ($availability['is_open'] ?? true)) {
                    throw new \RuntimeException(RestaurantAvailabilityService::getMessage($availability, $restaurant));
                }

                $hasDuePayments = Payment::where('order_id', $order->id)
                    ->where('is_due', true)
                    ->exists();

                if ($tendered >= 0) {
                    $receivedAmount = max(0, round($tendered - $returnAmount, 2));
                    $recordedMethod = $paymentMethod;

                    if ($recordedMethod === 'due' && $receivedAmount > 0) {
                        $recordedMethod = 'cash';
                    }

                    if ($recordedMethod !== 'due' && $receivedAmount > 0) {
                        Payment::create([
                            'order_id' => $order->id,
                            'payment_method' => $recordedMethod,
                            'amount' => $receivedAmount,
                            'balance' => $returnAmount,
                            'is_due' => false,
                            'due_amount_received' => $hasDuePayments ? $receivedAmount : null,
                        ]);
                    }
                }

                $order->refresh();
                $order->load(['items', 'items.menuItem', 'taxes', 'payments', 'splitOrders.items']);

                $orderPaidAmount = Payment::where('order_id', $order->id)
                    ->where('payment_method', '!=', 'due')
                    ->sum('amount');

                $order->amount_paid = $orderPaidAmount;
                $wasPaid = $order->status === 'paid';
                $order->status = $orderPaidAmount >= $order->total ? 'paid' : 'payment_due';
                $order->save();

                if ($order->status === 'paid' && ! $wasPaid) {
                    SendNewOrderReceived::dispatch($order);
                }

                Payment::where('order_id', $order->id)->where('payment_method', 'due')->delete();

                $remainingDue = max(0, round($order->total - $orderPaidAmount, 2));

                if ($remainingDue > 0) {
                    Payment::create([
                        'order_id' => $order->id,
                        'payment_method' => 'due',
                        'amount' => $remainingDue,
                        'is_due' => true,
                    ]);
                }

                // Dine-in table + lock: released when order_status is completed (see OrderObserver).

                if ($order->customer_id) {
                    try {
                        SendOrderBillEvent::dispatch($order);
                    } catch (\Exception $e) {
                        Log::error('syncOfflinePayment notification: '.$e->getMessage());
                    }
                }
            });
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (\Throwable $e) {
            Log::error('syncOfflinePayment: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => __('messages.somethingWentWrong'),
            ], 500);
        }

        $order = Order::find($orderId);
        TablesIndexCache::forgetForBranch((int) $this->branch->id);

        return response()->json([
            'success' => true,
            'message' => __('messages.paymentDoneSuccessfully'),
            'order' => $order,
        ]);
    }

    public function getMenuItem($id)
    {
        $orderTypeId = request()->input('order_type_id');
        $deliveryAppId = request()->input('delivery_app_id');

        $menuItem = MenuItem::with(['prices', 'variations.prices', 'modifierGroups.options.prices'])->find($id);

        if (!$menuItem) {
            return response()->json(['success' => false, 'message' => 'Menu item not found'], 404);
        }

        // Set price context
        if ($orderTypeId) {
            $normalizedDeliveryAppId = ($deliveryAppId === 'default' || !$deliveryAppId) ? null : (int)$deliveryAppId;
            $menuItem->setPriceContext($orderTypeId, $normalizedDeliveryAppId);
        }

        return response()->json([
            'success' => true,
            'menu_item' => $menuItem
        ]);
    }

    public function getMenuItemVariations($id)
    {
        $orderTypeId = request()->input('order_type_id');
        $deliveryAppId = request()->input('delivery_app_id');

        $menuItem = MenuItem::with(['variations.prices'])->find($id);

        if (!$menuItem) {
            return response()->json(['success' => false, 'message' => 'Menu item not found'], 404);
        }

        $variations = $menuItem->variations;

        // Set price context
        if ($orderTypeId) {
            $normalizedDeliveryAppId = ($deliveryAppId === 'default' || !$deliveryAppId) ? null : (int)$deliveryAppId;
            foreach ($variations as $variation) {
                $variation->setPriceContext($orderTypeId, $normalizedDeliveryAppId);
            }
        }

        // Generate HTML for variations modal
        $html = view('pos.variations-modal', [
            'menuItem' => $menuItem,
            'variations' => $variations,
            'orderTypeId' => $orderTypeId,
            'deliveryAppId' => $deliveryAppId
        ])->render();

        return response()->json([
            'success' => true,
            'html' => $html,
            'variations' => $variations
        ]);
    }

    public function getMenuItemModifiers($id)
    {
        $orderTypeId = request()->input('order_type_id');
        $deliveryAppId = request()->input('delivery_app_id');
        $variationId = request()->input('variation_id');

        $menuItem = MenuItem::with(['modifierGroups.options.prices', 'modifierGroups.itemModifiers'])->find($id);

        if (!$menuItem) {
            return response()->json(['success' => false, 'message' => 'Menu item not found'], 404);
        }

        // Get base modifiers (where variation_id is null)
        $baseModifiers = \App\Models\ModifierGroup::whereHas('itemModifiers', function($query) use ($id) {
            $query->where('menu_item_id', $id)
                ->whereNull('menu_item_variation_id');
        })->with(['options', 'itemModifiers' => function($query) use ($id) {
            $query->where('menu_item_id', $id)
                ->whereNull('menu_item_variation_id');
        }])->get();

        $modifierGroups = $baseModifiers;

        // If we have a variation, add variation-specific modifiers
        if ($variationId) {
            $variationSpecificModifiers = \App\Models\ModifierGroup::whereHas('itemModifiers', function($query) use ($id, $variationId) {
                $query->where('menu_item_id', $id)
                    ->where('menu_item_variation_id', $variationId);
            })->with(['options', 'itemModifiers' => function($query) use ($id, $variationId) {
                $query->where('menu_item_id', $id)
                    ->where('menu_item_variation_id', $variationId);
            }])->get();

            $modifierGroups = $baseModifiers->concat($variationSpecificModifiers);
        }

        // Set price context
        if ($orderTypeId) {
            $normalizedDeliveryAppId = ($deliveryAppId === 'default' || !$deliveryAppId) ? null : (int)$deliveryAppId;
            foreach ($modifierGroups as $group) {
                foreach ($group->options as $option) {
                    $option->setPriceContext($orderTypeId, $normalizedDeliveryAppId);
                }
            }
        }

        // Generate HTML for modifiers modal
        $html = view('pos.modifiers-modal', [
            'menuItem' => $menuItem,
            'modifierGroups' => $modifierGroups,
            'orderTypeId' => $orderTypeId,
            'deliveryAppId' => $deliveryAppId,
            'variationId' => $variationId
        ])->render();

        // Prepare modifier options data for JavaScript
        $modifierOptionsData = [];
        foreach ($modifierGroups as $group) {
            foreach ($group->options as $option) {
                $modifierOptionsData[$option->id] = [
                    'id' => $option->id,
                    'name' => $option->name,
                    'price' => $option->price,
                    'groupId' => $group->id
                ];
            }
        }

        return response()->json([
            'success' => true,
            'html' => $html,
            'modifier_groups' => $modifierGroups,
            'modifier_options' => $modifierOptionsData
        ]);
    }

    public function calculateTotal(Request $request)
    {
        $items = $request->input('items', []);
        $discountType = $request->input('discount_type');
        $discountValue = $request->input('discount_value', 0);
        $extraCharges = $request->input('extra_charges', []);
        $deliveryFee = $request->input('delivery_fee', 0);
        $tipAmount = $request->input('tip_amount', 0);
        $taxMode = $request->input('tax_mode', 'order');
        $includeChargesInTaxBase = $request->input('include_charges_in_tax_base', true);

        $subTotal = 0;
        $totalTaxAmount = 0;
        $orderItemTaxDetails = [];

        // Get restaurant settings
        $restaurant = $this->restaurant;
        $taxes = $restaurant->taxes ?? [];
        $isInclusive = $restaurant->tax_inclusive ?? false;

        // Calculate subtotal and item taxes
        foreach ($items as $key => $item) {
            $price = $item['price'] ?? 0;
            $quantity = \App\Support\PosQuantity::normalize($item['quantity'] ?? 1);
            $itemTotal = $price * $quantity;

            if ($taxMode === 'item') {
                // Calculate item-level taxes
                // In item mode, taxes are item-specific; do not fallback to all taxes.
                $itemTaxes = $item['taxes'] ?? [];
                $itemTaxAmount = 0;
                $itemTaxPercent = 0;
                $taxBreakup = [];

                if ($itemTaxes && count($itemTaxes) > 0) {
                    $totalTaxPercent = 0;
                    foreach ($itemTaxes as $tax) {
                        $totalTaxPercent += (float)($tax['tax_percent'] ?? 0);
                    }

                    foreach ($itemTaxes as $tax) {
                        $taxName = $tax['tax_name'] ?? '';
                        $taxPercent = (float)($tax['tax_percent'] ?? 0);
                        $taxAmount = 0;

                        if ($isInclusive) {
                            $taxAmount = ($price * $taxPercent) / (100 + $totalTaxPercent);
                        } else {
                            $taxAmount = ($price * $taxPercent) / 100;
                        }

                        $itemTaxAmount += $taxAmount;
                        $itemTaxPercent += $taxPercent;
                        if ($taxName !== '') {
                            $taxBreakup[$taxName] = [
                                'percent' => $taxPercent,
                                'amount' => $taxAmount,
                            ];
                        }
                    }
                }

                $orderItemTaxDetails[$key] = [
                    'tax_amount' => $itemTaxAmount * $quantity,
                    'tax_percent' => $itemTaxPercent,
                    'tax_breakup' => $taxBreakup,
                    'base_price' => $price,
                    'qty' => $quantity
                ];

                if ($isInclusive) {
                    $subTotal += ($itemTotal - ($itemTaxAmount * $quantity));
                } else {
                    $subTotal += $itemTotal;
                }
            } else {
                $subTotal += $itemTotal;
            }
        }

        // Calculate discount
        $discountAmount = 0;
        $discountBase = $subTotal;
        if ($discountType === 'percent') {
            $discountAmount = ($discountBase * $discountValue) / 100;
        } elseif ($discountType === 'fixed') {
            $discountAmount = min($discountValue, $discountBase);
        }

        $discountedTotal = $discountBase - $discountAmount;

        // Calculate service charges
        $serviceTotal = 0;
        $total = $discountedTotal;

        foreach ($extraCharges as $charge) {
            if (is_array($charge) && isset($charge['amount'])) {
                $total += $charge['amount'];
                $serviceTotal += $charge['amount'];
            }
        }

        // tax_base is taxable amount (subtotal - discount), excluding charges.
        $taxBase = $discountedTotal;

        // Calculate taxes
        if ($taxMode === 'order') {
            $totalTaxPercent = collect($taxes)->sum(function ($tax) {
                return (float) ($tax['tax_percent'] ?? 0);
            });

            foreach ($taxes as $tax) {
                $taxPercent = (float) ($tax['tax_percent'] ?? 0);
                $taxAmount = 0;

                if ($isInclusive) {
                    // Inclusive order-level tax: extract per-tax share from tax-included taxable amount.
                    $taxAmount = $totalTaxPercent > 0
                        ? (($taxBase * $taxPercent) / (100 + $totalTaxPercent))
                        : 0;
                } else {
                    $taxAmount = ($taxPercent / 100) * $taxBase;
                }

                $totalTaxAmount += $taxAmount;
            }
            if (!$isInclusive) {
                $total += $totalTaxAmount;
            }
        } elseif ($taxMode === 'item') {
            $totalInclusiveTax = 0;
            $totalExclusiveTax = 0;

            foreach ($orderItemTaxDetails as $taxDetail) {
                $taxAmount = $taxDetail['tax_amount'] ?? 0;

                if ($isInclusive) {
                    $totalInclusiveTax += $taxAmount;
                } else {
                    $totalExclusiveTax += $taxAmount;
                }
            }

            $totalTaxAmount = $totalInclusiveTax + $totalExclusiveTax;

            if ($totalExclusiveTax > 0) {
                $total += $totalExclusiveTax;
            }
        }

        // Add delivery fee and tip
        $total += $deliveryFee + $tipAmount;

        // Ensure total is not negative
        $total = max(0, $total);

        return response()->json([
            'success' => true,
            'sub_total' => $subTotal,
            'discount_amount' => $discountAmount,
            'discounted_total' => $discountedTotal,
            'service_total' => $serviceTotal,
            'tax_base' => $taxBase,
            'total_tax_amount' => $totalTaxAmount,
            'total' => $total,
            'order_item_tax_details' => $orderItemTaxDetails
        ]);
    }

    /**
     * Server-side enforcement for stamp discount rules on a single line item.
     * Mirrors Loyalty module logic (incl. tier redemption_multiplier when $customerId is set).
     *
     * @param int|null $customerId Optional; when set, tier redemption_multiplier is applied (tt parity).
     * @param mixed $preloadedRule Optional LoyaltyStampRule from batch load (skips find).
     * @param float|null $tierMultiplierOverride When set, skips per-call loyalty tier lookups (POS batch path).
     */
    protected function applyStampDiscountToAmount(
        int $menuItemId,
        int $stampRuleId,
        float $unitPrice,
        int $quantity,
        float $currentAmount,
        ?int $customerId = null,
        $preloadedRule = null,
        ?float $tierMultiplierOverride = null
    ): float {
        // If Loyalty module is not available, keep existing amount.
        if (!function_exists('module_enabled') || !module_enabled('Loyalty')) {
            return $currentAmount;
        }

        try {
            /** @var \Modules\Loyalty\Entities\LoyaltyStampRule|null $rule */
            $rule = $preloadedRule;
            if (!$rule) {
                $rule = \Modules\Loyalty\Entities\LoyaltyStampRule::find($stampRuleId);
            }
            if (!$rule) {
                return $currentAmount;
            }

            // Only enforce for discount rules; free-item rules are handled elsewhere.
            if (!in_array($rule->reward_type, ['discount_percent', 'discount_amount'], true)) {
                return $currentAmount;
            }

            $qty = max(1, $quantity);
            $expected = max(0.0, $unitPrice * $qty);
            if ($expected <= 0) {
                return max(0.0, $currentAmount);
            }

            // Tier power (tt parity): apply customer's tier redemption_multiplier to stamp discount
            $tierMultiplier = 1.00;
            if ($tierMultiplierOverride !== null) {
                $tierMultiplier = $tierMultiplierOverride;
            } elseif ($customerId && $this->restaurant && class_exists(\Modules\Loyalty\Entities\LoyaltyAccount::class)) {
                try {
                    $restaurantId = (int)($this->restaurant->id ?? 0);
                    if ($restaurantId > 0) {
                        $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
                        $account = $loyaltyService->getOrCreateAccount($restaurantId, $customerId);
                        if ($account && $account->tier_id && class_exists(\Modules\Loyalty\Entities\LoyaltyTier::class)) {
                            $tier = \Modules\Loyalty\Entities\LoyaltyTier::find($account->tier_id);
                            if ($tier && (float)$tier->redemption_multiplier > 0) {
                                $tierMultiplier = (float)$tier->redemption_multiplier;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    // keep 1.00
                }
            }

            $discountPerUnit = 0.0;
            if ($rule->reward_type === 'discount_percent') {
                $percent = (float)($rule->reward_value ?? 0);
                if ($percent <= 0) {
                    return max(0.0, $currentAmount);
                }
                $discountPerUnit = (($percent / 100.0) * $unitPrice) * $tierMultiplier;
            } elseif ($rule->reward_type === 'discount_amount') {
                $value = (float)($rule->reward_value ?? 0);
                if ($value <= 0) {
                    return max(0.0, $currentAmount);
                }
                $discountPerUnit = min($value * $tierMultiplier, $unitPrice);
            }

            $discountTotal = max(0.0, $discountPerUnit * $qty);
            $discountedAmount = max(0.0, $expected - $discountTotal);

            return $discountedAmount;
        } catch (\Throwable $e) {
            // Fail-safe: if anything goes wrong, do not block POS
            return $currentAmount;
        }
    }

    public function getTablesWithUnpaidOrders()
    {
        // Fetch tables that have orders which are not paid
        $unpaidOrders = Order::where('branch_id', $this->branch->id)
            ->whereNotNull('table_id')
            ->where('status', '!=', 'paid')
            ->where('status', '!=', 'canceled')
            ->with([
                'table',
                'items.menuItem',
                'items.menuItemVariation',
                'items.modifierOptions',
                'kot' => function($query) {
                    $query->orderBy('created_at', 'asc');
                },
                'kot.items.menuItem',
                'kot.items.menuItemVariation',
                'kot.items.modifierOptions'
            ])
            ->orderBy('created_at', 'desc')
            ->get();

        // Group by table_id and get unique tables
        $tableIds = $unpaidOrders->pluck('table_id')->unique()->filter();

        $tables = Table::whereIn('id', $tableIds)
            ->where('branch_id', $this->branch->id)
            ->orderBy('table_code')
            ->get()
            ->map(function ($table) use ($unpaidOrders) {
                // Get unpaid orders for this table
                $tableOrders = $unpaidOrders->where('table_id', $table->id)->values();

                // Convert to array and add unpaid_orders
                $tableData = $table->toArray();
                $tableData['unpaid_orders'] = $tableOrders->toArray();

                return $tableData;
            });

        return response()->json([
            'success' => true,
            'tables' => $tables
        ]);
    }

    public function mergeTables(Request $request)
    {
        $tableIds = $request->input('table_ids', []);
        $currentTableId = $request->input('current_table_id');
        $orderTypeId = $request->input('order_type_id');

        if (empty($tableIds) || !is_array($tableIds)) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.selectAtLeastOneTable')
            ], 422);
        }

        try {
            // Store order IDs only (not Eloquent models) to avoid session serialize errors
            $orderIdsToMerge = [];

            foreach ($tableIds as $tableId) {
                // Skip current table if it's in the list
                if ($currentTableId && $tableId == $currentTableId) {
                    continue;
                }

                $unpaidOrderIds = Order::where('table_id', $tableId)
                    ->where('branch_id', $this->branch->id)
                    ->where('status', '!=', 'paid')
                    ->where('status', '!=', 'canceled')
                    ->pluck('id')
                    ->all();

                foreach ($unpaidOrderIds as $orderId) {
                    $orderIdsToMerge[] = (int) $orderId;
                }
            }

            $orderIdsToMerge = array_values(array_unique($orderIdsToMerge));

            if (empty($orderIdsToMerge)) {
                return response()->json([
                    'success' => false,
                    'message' => __('modules.order.noOrdersToMerge')
                ], 422);
            }

            // Store merge data in session to be used by POS after reload
            session()->put('pos_merge_data', [
                'order_item_ids' => [],
                'kot_item_ids' => [],
                'order_ids_to_delete' => $orderIdsToMerge,
                'orders_to_merge' => $orderIdsToMerge,
                'merged_at' => now()->toDateTimeString()
            ]);

            return response()->json([
                'success' => true,
                'message' => __('messages.tablesReadyToMerge'),
                'data' => [
                    'orders_count' => count($orderIdsToMerge),
                    'reload_required' => true
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error merging tables: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => __('messages.somethingWentWrong')
            ], 500);
        }
    }

    /**
     * Update order status
     */
    public function updateOrderStatus(Request $request, $id)
    {
        $request->validate([
            'status' => 'required|string'
        ]);

        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound')
            ], 404);
        }

        // Check permission
        if (!user_can('Update Order')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permissionDenied')
            ], 403);
        }

        // Update order status
        $order->update([
            'order_status' => $request->status
        ]);

        return response()->json([
            'success' => true,
            'message' => __('modules.order.statusUpdated'),
            'data' => [
                'order_id' => $order->id,
                'status' => $order->order_status
            ]
        ]);
    }

    /**
     * Cancel an order
     */
    public function cancelOrder(Request $request, $id)
    {
        $request->validate([
            'cancel_reason_id' => 'nullable|exists:kot_cancel_reasons,id',
            'cancel_reason_text' => 'nullable|string|max:500'
        ]);

        // Check if at least one reason is provided
        if (!$request->cancel_reason_id && !$request->cancel_reason_text) {
            return response()->json([
                'success' => false,
                'message' => __('modules.settings.cancelReasonRequired')
            ], 422);
        }

        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound')
            ], 404);
        }

        // Check permission
        if (!user_can('Update Order')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permissionDenied')
            ], 403);
        }

        // Update order to cancelled status
        $order->update([
            'status' => 'canceled',
            'order_status' => 'cancelled',
            'cancel_reason_id' => $request->cancel_reason_id,
            'cancel_reason_text' => $request->cancel_reason_text,
            'cancelled_by' => auth()->id(),
            'cancel_time' => \Carbon\Carbon::now()->setTimezone(restaurant()->timezone),
        ]);

        // Make table available if it was a dine-in order
        if ($order->table_id) {
            Table::where('id', $order->table_id)->update([
                'available_status' => 'available',
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.orderCanceled'),
            'data' => [
                'order_id' => $order->id,
                'status' => $order->status
            ]
        ]);
    }

    /**
     * Delete an order completely
     */
    public function deleteOrder($id)
    {
        $order = Order::find($id);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound')
            ], 404);
        }

        // Check permission
        if (!user_can('Delete Order')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permissionDenied')
            ], 403);
        }

        // Make table available if it was a dine-in order
        if ($order->table_id) {
            Table::where('id', $order->table_id)->update([
                'available_status' => 'available',
            ]);
        }

        // Delete associated KOT records
        $order->kot()->delete();

        // Delete the order
        $order->delete();

        return response()->json([
            'success' => true,
            'message' => __('messages.orderDeleted')
        ]);
    }

    /**
     * Delete an individual order item
     */
    public function deleteOrderItem($orderId, $itemId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound')
            ], 404);
        }

        // Check permission
        if (!user_can('Delete Order')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permissionDenied')
            ], 403);
        }

        // Cannot delete items from paid or payment_due orders
        if ($order->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.cannotDeletePaidOrderItem')
            ], 400);
        }
        if ($order->status === 'payment_due') {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.cannotModifyPaymentDueOrder')
            ], 400);
        }

        // Try to find as OrderItem first (for draft orders)
        $orderItem = OrderItem::where('id', $itemId)
            ->where('order_id', $orderId)
            ->first();

        if ($orderItem) {
            // Delete related KOT items first
            $kotItems = KotItem::where('menu_item_id', $orderItem->menu_item_id)
                ->where('menu_item_variation_id', $orderItem->menu_item_variation_id)
                ->whereHas('kot', function ($query) use ($orderItem) {
                    $query->where('order_id', $orderItem->order_id);
                })
                ->get();

            foreach ($kotItems as $kotItem) {
                $kotId = $kotItem->kot_id;
                $kotItem->delete();

                // Check if KOT is now empty and delete it
                $kot = Kot::find($kotId);
                if ($kot && $kot->items()->count() === 0) {
                    $kot->delete();
                }
            }

            $orderItem->delete();
        } else {
            // Try to find as KotItem (for regular orders with KOTs)
            $kotItem = KotItem::where('id', $itemId)
                ->whereHas('kot', function ($query) use ($orderId) {
                    $query->where('order_id', $orderId);
                })
                ->first();

            if (!$kotItem) {
                return response()->json([
                    'success' => false,
                    'message' => __('modules.order.itemNotFound')
                ], 404);
            }

            $kotId = $kotItem->kot_id;
            $kotItem->delete();

            // Check if KOT is now empty and delete it
            $kot = Kot::find($kotId);
            if ($kot && $kot->items()->count() === 0) {
                $kot->delete();
            }
        }

        // Refresh order to check remaining items
        $order->refresh();

        // If no items left and no KOTs, delete the entire order
        if ($order->items()->count() === 0 && $order->kot()->count() === 0) {
            // Delete associated KOT items
            $kots = Kot::where('order_id', $order->id)->get();
            foreach ($kots as $kot) {
                KotItem::where('kot_id', $kot->id)->delete();
                $kot->delete();
            }

            // Delete order taxes and charges
            OrderTax::where('order_id', $order->id)->delete();
            OrderCharge::where('order_id', $order->id)->delete();

            // Unlock table if assigned
            if ($order->table_id) {
                $table = Table::find($order->table_id);

                if ($table) {
                    $table->update(['available_status' => 'available']);
                    // Release table session lock if exists
                    if ($table->tableSession) {
                        $table->tableSession->releaseLock();
                    }
                }
            }

            // Delete the order
            $order->delete();

            return response()->json([
                'success' => true,
                'message' => __('messages.orderDeleted'),
                'redirect' => route('pos.index')
            ]);
        }

        // Recalculate order totals (sub_total, discount, charges, taxes, tip, delivery, total)
        $this->recalculateOrderTotals($order);

        // Refresh order to get updated data
        $order->refresh();

        $currencyId = $this->restaurant->currency_id ?? null;

        return response()->json([
            'success' => true,
            'message' => __('modules.order.itemDeleted'),
            'order' => [
                'items_count' => $order->items()->count(),
                'sub_total' => number_format($order->sub_total, 2, '.', ''),
                'discount_amount' => number_format($order->discount_amount ?? 0, 2, '.', ''),
                'total_tax_amount' => number_format($order->total_tax_amount ?? 0, 2, '.', ''),
                'total' => number_format($order->total, 2, '.', ''),
                'sub_total_formatted' => currency_format($order->sub_total, $currencyId),
                'discount_amount_formatted' => currency_format($order->discount_amount ?? 0, $currencyId),
                'total_tax_amount_formatted' => currency_format($order->total_tax_amount ?? 0, $currencyId),
                'total_formatted' => currency_format($order->total, $currencyId),
            ]
        ]);
    }

    /**
     * Update order item tax details for cart items (matching Livewire)
     */
    private function updateOrderItemTaxDetailsForCart($items, &$orderItemTaxDetails, &$totalTaxAmount)
    {
        // Mirror Livewire POS behavior: use global taxes collection and restaurant tax settings
        $taxes = Tax::all();
        $isInclusive = $this->restaurant->tax_inclusive ?? false;

        foreach ($items as $key => $item) {
            $price = is_array($item) ? ($item['price'] ?? 0) : ($item->price ?? 0);
            $quantity = \App\Support\PosQuantity::normalize(is_array($item) ? ($item['quantity'] ?? 1) : ($item->quantity ?? 1));

            $itemTaxAmount = 0;
            $totalTaxPercent = 0;

            // Calculate total tax percent
            foreach ($taxes as $tax) {
                $totalTaxPercent += $tax->tax_percent ?? 0;
            }

            // Calculate tax amount
            foreach ($taxes as $tax) {
                $taxPercent = $tax->tax_percent ?? 0;
                $taxAmount = 0;

                if ($isInclusive) {
                    $taxAmount = ($price * $taxPercent) / (100 + $totalTaxPercent);
                } else {
                    $taxAmount = ($price * $taxPercent) / 100;
                }

                $itemTaxAmount += $taxAmount;
            }

            $orderItemTaxDetails[$key] = [
                'tax_amount' => $itemTaxAmount * $quantity,
                'base_price' => $price,
                'qty' => $quantity
            ];

            $totalTaxAmount += $itemTaxAmount * $quantity;
        }
    }

    /**
     * Recalculate order totals - Direct replication of Livewire Pos::calculateTotal()
     * This matches the exact calculation flow from Pos.php
     *
     * @param  \Illuminate\Support\Collection|\Illuminate\Database\Eloquent\Collection|array|null  $taxesCollection  When set, used instead of Tax::all() (submitOrder hot path).
     * @param  bool  $quiet  When true, persist totals without firing Order observers (submitOrder intermediate recalcs).
     */
    /**
     * Create or merge a POS cart line into order_items.
     * Multiple cart lines for the same menu/variation/modifiers in one request
     * accumulate qty/amount (POS uses a unique key per tap via Date.now()).
     *
     * @param  array<int, int|null>  $kotOrderItemIdByKotItemId
     * @param  array<int, true>  $touchedOrderItemIds
     */
    private function upsertAjaxCartOrderItem(
        Order $order,
        array $orderItemData,
        array $normalizedModifierIds,
        ?int $kotItemIdFromKey,
        array &$kotOrderItemIdByKotItemId,
        array &$touchedOrderItemIds
    ): void {
        $menuItemId = $orderItemData['menu_item_id'] ?? null;
        $variantId = $orderItemData['menu_item_variation_id'] ?? null;
        $isFreeItemFromStamp = (bool) ($orderItemData['is_free_item_from_stamp'] ?? false);
        $stampRuleId = $orderItemData['stamp_rule_id'] ?? null;
        $quantity = (float) ($orderItemData['quantity'] ?? 1);
        $quantity = PosQuantity::normalize($quantity);
        $amount = (float) ($orderItemData['amount'] ?? 0);
        $taxAmount = (float) ($orderItemData['tax_amount'] ?? 0);
        $itemNote = $orderItemData['note'] ?? null;

        $existingOrderItemQuery = $order->items()->where('menu_item_id', $menuItemId);

        if ($variantId) {
            $existingOrderItemQuery->where('menu_item_variation_id', $variantId);
        } else {
            $existingOrderItemQuery->whereNull('menu_item_variation_id');
        }

        if ($isFreeItemFromStamp) {
            $existingOrderItemQuery->where('is_free_item_from_stamp', true)
                ->where('stamp_rule_id', $stampRuleId);
        } else {
            $existingOrderItemQuery->where(function ($q) {
                $q->where('is_free_item_from_stamp', false)
                    ->orWhereNull('is_free_item_from_stamp');
            });
        }

        $existingOrderItem = null;
        foreach ($existingOrderItemQuery->with('modifierOptions:id')->get() as $candidate) {
            $candidateModifierIds = $candidate->modifierOptions
                ? $candidate->modifierOptions->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->toArray()
                : [];
            if ($candidateModifierIds === $normalizedModifierIds) {
                $existingOrderItem = $candidate;
                break;
            }
        }

        if ($existingOrderItem) {
            if (isset($touchedOrderItemIds[$existingOrderItem->id])) {
                $existingOrderItem->update([
                    'quantity' => PosQuantity::normalize((float) ($existingOrderItem->quantity ?? 0) + $quantity),
                    'amount' => (float) ($existingOrderItem->amount ?? 0) + $amount,
                    'tax_amount' => (float) ($existingOrderItem->tax_amount ?? 0) + $taxAmount,
                    'note' => $existingOrderItem->note ?: ($itemNote ?: null),
                ]);
            } else {
                $existingOrderItem->update($orderItemData);
                $touchedOrderItemIds[$existingOrderItem->id] = true;
            }

            $existingOrderItem->modifierOptions()->sync($normalizedModifierIds);

            if ($kotItemIdFromKey) {
                KotItem::where('id', $kotItemIdFromKey)
                    ->whereNull('order_item_id')
                    ->update(['order_item_id' => $existingOrderItem->id]);
                $kotOrderItemIdByKotItemId[$kotItemIdFromKey] = $existingOrderItem->id;
            }

            return;
        }

        $orderItem = OrderItem::create($orderItemData);
        $orderItem->modifierOptions()->sync($normalizedModifierIds);
        $touchedOrderItemIds[$orderItem->id] = true;

        if ($kotItemIdFromKey) {
            KotItem::where('id', $kotItemIdFromKey)
                ->whereNull('order_item_id')
                ->update(['order_item_id' => $orderItem->id]);
            $kotOrderItemIdByKotItemId[$kotItemIdFromKey] = $orderItem->id;
        }
    }

    private function recalculateOrderTotals($order, $taxesCollection = null, bool $quiet = false)
    {
        // Step 1: Calculate subtotal and total from order items
        $total = 0;
        $subTotal = 0;
        $totalTaxAmount = 0;
        $orderItemTaxDetails = [];

        // Load necessary relationships for tax-aware recalculation.
        $order->load([
            'items.menuItem.taxes',
            'charges.charge',
            'kot.items.menuItem.taxes',
            'taxes.tax',
        ]);

        // Use current restaurant instance for tax settings.
        $restaurant = $this->restaurant;

        $taxMode = $order->tax_mode ?? $restaurant->tax_mode ?? 'order';
        $isInclusive = $restaurant->tax_inclusive ?? false;
        $taxes = $taxesCollection === null ? Tax::all() : collect($taxesCollection);

        // Get order items based on status
        $orderItems = collect();
        if ($order->status === 'draft') {
            $orderItems = $order->items;
        } elseif ($order->status === 'kot') {
            // For KOT status, include all non-cancelled KOT items.
            // Some KOT flows may store status as null/other values, so a narrow whereIn()
            // can incorrectly exclude valid rows and collapse subtotal to service-only.
            foreach ($order->kot as $kot) {
                $orderItems = $orderItems->concat($kot->items->where('status', '!=', 'cancelled'));
            }
        } else {
            // For other statuses, use order items
            $orderItems = $order->items;
        }

        // Calculate item taxes first if needed (item-level, per-item tax assignment).
        if ($taxMode === 'item') {
            foreach ($orderItems as $key => $item) {
                $quantity = \App\Support\PosQuantity::normalize($item->quantity ?? 1);
                $itemAmount = isset($item->amount) ? (float)$item->amount : ((float)($item->price ?? 0) * $quantity);
                $storedTaxAmount = (float)($item->tax_amount ?? 0);
                $itemTaxAmount = $storedTaxAmount;

                // Fallback for old rows where tax_amount wasn't saved:
                // recompute from this item's own assigned taxes only.
                if ($itemTaxAmount <= 0 && isset($item->menuItem) && $item->menuItem && $item->menuItem->relationLoaded('taxes')) {
                    $itemTaxes = $item->menuItem->taxes ?? collect();
                    if ($itemTaxes->isNotEmpty()) {
                        $unitAmount = $quantity > 0 ? ($itemAmount / $quantity) : (float)($item->price ?? 0);
                        $taxResult = MenuItem::calculateItemTaxes($unitAmount, $itemTaxes, $isInclusive);
                        $itemTaxAmount = round((float)($taxResult['tax_amount'] ?? 0) * $quantity, 2);
                    }
                }

                $orderItemTaxDetails[$key] = [
                    'tax_amount' => $itemTaxAmount,
                    'base_price' => (float)($item->price ?? 0),
                    'qty' => $quantity
                ];
            }
        }

        // Calculate subtotal and total (use stored amount when available for modifiers/discounts)
        foreach ($orderItems as $key => $item) {
            $itemAmount = isset($item->amount) ? (float) $item->amount : (($item->price ?? 0) * ($item->quantity ?? 1));
            $total += $itemAmount;

            $subTotal += $itemAmount;
        }

        $discountedTotal = $total;

        // Step 2: Apply discounts (matching Livewire)
        $discountAmount = 0;
        $discountBase = $subTotal;
        if ($order->discount_value > 0 && $order->discount_type) {
            if ($order->discount_type === 'percent') {
                $discountAmount = round(($discountBase * $order->discount_value) / 100, 2);
            } elseif ($order->discount_type === 'fixed') {
                $discountAmount = min($order->discount_value, $discountBase);
            }

            $total -= $discountAmount;
        }
        $discountedTotal = $total;

        // Step 3: Calculate service charges on discountedTotal (matching Livewire / tt OrderDetail)
        $serviceTotal = 0;
        // Sum of charges flagged taxable (is_taxable) so GST can be applied on them.
        $taxableServiceTotal = 0;
        $includeChargesInTaxBase = (bool) ($restaurant->include_charges_in_tax_base ?? false);
        $orderTypeSlug = $order->orderType ? $order->orderType->slug : ($order->order_type ?? null);

        $chargeBase = $discountedTotal;
        if ($isInclusive && $taxMode === 'item') {
            $itemTaxSum = (float) $orderItems->sum(fn ($item) => (float) ($item->tax_amount ?? 0));
            $chargeBase = max(0, $chargeBase - $itemTaxSum);
        }

        foreach ($order->charges as $orderCharge) {
            if (!$orderCharge->charge) {
                continue;
            }
            $charge = $orderCharge->charge;
            $allowedTypes = $charge->order_types ?? [];
            if (!empty($allowedTypes) && $orderTypeSlug && !in_array($orderTypeSlug, $allowedTypes)) {
                continue;
            }
            $chargeAmount = $charge->getAmount($chargeBase);
            if (!$isInclusive) {
                $total += $chargeAmount;
            }
            $serviceTotal += $chargeAmount;
            if ($includeChargesInTaxBase && ($charge->is_taxable ?? true)) {
                $taxableServiceTotal += $chargeAmount;
            }
        }

        // Step 4: tax_base = (subtotal - discount) + taxable service charges (per tax settings).
        $taxBase = $includeChargesInTaxBase
            ? ($discountedTotal + $taxableServiceTotal)
            : $discountedTotal;

        // Step 5: Calculate taxes on tax_base (use order's OrderTax when present, else all taxes - match tt Livewire)
        $totalTaxAmount = 0;
        $orderTaxes = $order->taxes
            ->filter(fn ($ot) => $ot->tax)
            ->unique('tax_id')
            ->values();

        if ($taxMode === 'order') {
            $taxesToApply = $orderTaxes->isNotEmpty()
                ? $orderTaxes->pluck('tax')->filter()->unique('id')->values()
                : collect($taxes)->filter()->unique('id')->values();

            $totalTaxPercent = $taxesToApply->sum(function ($tax) {
                return (float) ($tax->tax_percent ?? $tax->percent ?? 0);
            });

            foreach ($taxesToApply as $tax) {
                if (!$tax) {
                    continue;
                }
                $taxPercent = (float) ($tax->tax_percent ?? $tax->percent ?? 0);
                $taxAmount = 0;

                if ($isInclusive) {
                    // Inclusive order-level tax extraction from tax-included taxBase.
                    $taxAmount = $totalTaxPercent > 0
                        ? (($taxBase * $taxPercent) / (100 + $totalTaxPercent))
                        : 0;
                } else {
                    $taxAmount = ($taxPercent / 100) * $taxBase;
                }

                $totalTaxAmount += $taxAmount;
            }
            // Do not mutate order_taxes here; preserve original attached tax mapping.
        } elseif ($taxMode === 'item') {
            // Item-level tax
            $totalInclusiveTax = 0;
            $totalExclusiveTax = 0;

            foreach ($orderItemTaxDetails as $taxDetail) {
                $taxAmount = $taxDetail['tax_amount'] ?? 0;

                if ($isInclusive) {
                    $totalInclusiveTax += $taxAmount;
                } else {
                    $totalExclusiveTax += $taxAmount;
                }
            }

            $totalTaxAmount = $totalInclusiveTax + $totalExclusiveTax;
        }

        // Step 6: For inclusive item taxes, adjust subtotal (matching Livewire)
        if ($taxMode === 'item' && $isInclusive) {
            $subTotal -= $totalTaxAmount;
        }

        // Item-tax mode only — GST on taxable service charges.
        if ($taxableServiceTotal > 0 && $taxMode === 'item') {
            $effectiveTaxRate = 0.0;
            if ($taxMode === 'item') {
                $rateBase = $subTotal;
                if ($isInclusive) {
                    $rateBase += $totalTaxAmount;
                }
                $effectiveTaxRate = $rateBase > 0 ? ($totalTaxAmount / $rateBase) : 0.0;
            }

            $chargeTax = $taxableServiceTotal * $effectiveTaxRate;
            if ($chargeTax > 0) {
                $totalTaxAmount += $chargeTax;
            }
        }

        // Step 7: Final total — inclusive reverse-solves x + service + GST = gross.
        $tipAmount = (float) ($order->tip_amount ?? 0);
        $deliveryFee = (float) ($order->delivery_fee ?? 0);

        if ($isInclusive) {
            $grossItemTotal = (float) $orderItems->sum(function ($item) {
                if (isset($item->amount)) {
                    return (float) $item->amount;
                }

                return (float) ($item->price ?? 0) * (float) ($item->quantity ?? 1);
            });
            $inclusiveGross = $grossItemTotal;
            if ($subTotal > 0) {
                $netAfterDiscounts = max(0, $subTotal - $discountAmount - (float) ($order->loyalty_discount_amount ?? 0));
                $inclusiveGross = $grossItemTotal * ($netAfterDiscounts / $subTotal);
            } else {
                $inclusiveGross -= $discountAmount;
            }

            $chargesForReverse = $order->charges
                ->map(fn ($orderCharge) => $orderCharge->charge)
                ->filter();

            $taxPercentTotal = $taxMode === 'order'
                ? (float) $taxesToApply->sum(fn ($tax) => (float) ($tax->tax_percent ?? $tax->percent ?? 0))
                : (float) $orderItems->pluck('tax_percentage')->filter()->unique()->sum();

            $reversed = RestaurantCharge::reverseInclusiveBreakdown(
                $inclusiveGross,
                $chargesForReverse,
                $taxPercentTotal,
                (bool) ($restaurant->include_charges_in_tax_base ?? false)
            );

            $subTotal = round($reversed['sub_total'], 3);
            $taxBase = round($reversed['tax_base'], 3);
            $totalTaxAmount = round($reversed['total_tax_amount'], 3);
            $finalTotal = $inclusiveGross;
        } else {
            $finalTotal = $discountedTotal + $serviceTotal + $totalTaxAmount;
        }

        // Add tip and delivery (cast to float to avoid int + string errors)
        $finalTotal += $tipAmount + $deliveryFee;
        $total = round($finalTotal, 2);

        // Step 8: Update order with calculated values (matching Livewire) and persist tax_mode
        $payload = [
            'sub_total' => $subTotal,
            'discount_amount' => $discountAmount,
            'total' => max(0, $total),
            'total_tax_amount' => $totalTaxAmount,
            'tax_base' => $taxBase,
            'tax_mode' => $taxMode,
        ];
        if ($quiet) {
            $order->updateQuietly($payload);
        } else {
            $order->update($payload);
        }
    }

    /**
     * Check if stamps are enabled for POS (mirrors Livewire logic).
     */
    private function isStampsEnabledForPosAjax(): bool
    {
        if (!$this->isLoyaltyEnabledForPos()) {
            return false;
        }

        try {
            $restaurantId = $this->restaurant?->id ?? (restaurant()->id ?? null);
            if (!$restaurantId) {
                return false;
            }

            $settings = \Modules\Loyalty\Entities\LoyaltySetting::getForRestaurant($restaurantId);
            if (!$settings || !($settings->enabled ?? false)) {
                return false;
            }

            $loyaltyType = $settings->loyalty_type ?? 'points';
            $stampsEnabled = in_array($loyaltyType, ['stamps', 'both'], true) && ($settings->enable_stamps ?? true);
            if (!$stampsEnabled) {
                return false;
            }

            if (!is_null($settings->enable_stamps_for_pos)) {
                return (bool)$settings->enable_stamps_for_pos;
            }

            return (bool)($settings->enable_for_pos ?? true);
        } catch (\Throwable $e) {
            Log::warning('POS stamp settings check failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Redeem stamps repeatedly for each rule until no more eligible items remain.
     * Returns true when redemption progressed.
     */
    private function redeemStampsForEligibleBilledItems(Order $order): bool
    {
        if (!$this->isStampsEnabledForPosAjax() || !$order->customer_id) {
            return false;
        }

        if (!class_exists(\Modules\Loyalty\Services\LoyaltyService::class)) {
            return false;
        }

        $order->load(['items', 'kot.items']);

        // Collect explicit stamp_rule_ids from KOT items and order items.
        $stampRuleIds = [];
        foreach ($order->kot as $kot) {
            foreach ($kot->items as $kotItem) {
                if (!empty($kotItem->stamp_rule_id)) {
                    $stampRuleIds[] = (int)$kotItem->stamp_rule_id;
                }
            }
        }
        foreach ($order->items as $orderItem) {
            if (!empty($orderItem->stamp_rule_id)) {
                $stampRuleIds[] = (int)$orderItem->stamp_rule_id;
            }
        }

        $stampRuleIds = array_values(array_unique(array_filter($stampRuleIds)));

        if (empty($stampRuleIds)) {
            return false;
        }

        $loyaltyService = app(\Modules\Loyalty\Services\LoyaltyService::class);
        $didRedeem = false;

        foreach ($stampRuleIds as $stampRuleId) {
            $maxIterations = 100;
            for ($i = 0; $i < $maxIterations; $i++) {
                $beforeTxCount = 0;
                if (class_exists(\Modules\Loyalty\Entities\LoyaltyStampTransaction::class)) {
                    $beforeTxCount = \Modules\Loyalty\Entities\LoyaltyStampTransaction::where('order_id', $order->id)
                        ->where('stamp_rule_id', $stampRuleId)
                        ->where('type', 'REDEEM')
                        ->count();
                }

                $order->refresh();
                $order->load('items');

                $eligibleItemsCount = $order->items()
                    ->where(function ($q) use ($stampRuleId) {
                        $q->where('stamp_rule_id', $stampRuleId)
                            ->orWhereNull('stamp_rule_id');
                    })
                    ->where(function ($q) {
                        $q->whereNull('is_free_item_from_stamp')
                            ->orWhere('is_free_item_from_stamp', false);
                    })
                    ->count();

                if ($eligibleItemsCount <= 0) {
                    break;
                }

                try {
                    $result = $loyaltyService->redeemStamps($order, $stampRuleId);
                    if (!is_array($result) || !($result['success'] ?? false)) {
                        break;
                    }
                } catch (\Throwable $e) {
                    Log::warning('POS redeemStamps failed', [
                        'order_id' => $order->id,
                        'stamp_rule_id' => $stampRuleId,
                        'error' => $e->getMessage(),
                    ]);
                    break;
                }

                $order->refresh();
                $afterTxCount = $beforeTxCount;
                if (class_exists(\Modules\Loyalty\Entities\LoyaltyStampTransaction::class)) {
                    $afterTxCount = \Modules\Loyalty\Entities\LoyaltyStampTransaction::where('order_id', $order->id)
                        ->where('stamp_rule_id', $stampRuleId)
                        ->where('type', 'REDEEM')
                        ->count();
                }

                if ($afterTxCount <= $beforeTxCount) {
                    break;
                }

                $didRedeem = true;
            }
        }

        return $didRedeem;
    }

    /**
     * Remove an extra charge from an order (matching Livewire Pos::removeExtraCharge)
     */
    public function removeExtraCharge($orderId, $chargeId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound')
            ], 404);
        }

        // Check permission
        if (!user_can('Update Order')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permissionDenied')
            ], 403);
        }

        // Cannot modify paid orders
        if ($order->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.cannotModifyPaidOrder')
            ], 400);
        }

        // Detach the charge (matching Livewire: $order->extraCharges()->detach($chargeId))
        $detached = $order->extraCharges()->detach($chargeId);

        if ($detached === 0) {
            return response()->json([
                'success' => false,
                'message' => __('messages.chargeNotFound')
            ], 404);
        }

        // Recalculate totals (matching Livewire: $this->calculateTotal())
        $this->recalculateOrderTotals($order);

        // Refresh order to get updated values
        $order->refresh();

        $currencyId = $this->restaurant->currency_id ?? null;

        return response()->json([
            'success' => true,
            'message' => __('messages.extraChargeRemoved'),
            'order' => [
                'id' => $order->id,
                'status' => $order->status,
                'sub_total' => number_format($order->sub_total, 2, '.', ''),
                'discount_amount' => number_format($order->discount_amount ?? 0, 2, '.', ''),
                'discount_type' => $order->discount_type,
                'discount_value' => $order->discount_value,
                'total_tax_amount' => number_format($order->total_tax_amount ?? 0, 2, '.', ''),
                'total' => number_format($order->total, 2, '.', ''),
                'tax_base' => number_format($order->tax_base ?? 0, 2, '.', ''),
                'sub_total_formatted' => currency_format($order->sub_total, $currencyId),
                'discount_amount_formatted' => currency_format($order->discount_amount ?? 0, $currencyId),
                'total_tax_amount_formatted' => currency_format($order->total_tax_amount ?? 0, $currencyId),
                'total_formatted' => currency_format($order->total, $currencyId),
            ]
        ]);
    }

    /**
     * Update discount for an existing order (order detail view) and recalculate totals
     */
    public function updateOrderDiscount(Request $request, $orderId)
    {
        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound')
            ], 404);
        }

        if (!user_can('Update Order') || !user_can('Add Discount on POS')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permissionDenied')
            ], 403);
        }

        if ($order->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.cannotModifyPaidOrder')
            ], 400);
        }

        $discountType = $request->input('discount_type');
        $discountValue = $request->input('discount_value', 0);

        $order->update([
            'discount_type' => $discountType ?: null,
            'discount_value' => $discountValue ? (float) $discountValue : 0,
        ]);

        $this->recalculateOrderTotals($order);
        $order->refresh();

        $currencyId = $this->restaurant->currency_id ?? null;

        return response()->json([
            'success' => true,
            'message' => $discountValue > 0 ? __('messages.discountApplied') : __('messages.discountRemoved'),
            'order' => [
                'id' => $order->id,
                'sub_total' => number_format($order->sub_total, 2, '.', ''),
                'discount_amount' => number_format($order->discount_amount ?? 0, 2, '.', ''),
                'total_tax_amount' => number_format($order->total_tax_amount ?? 0, 2, '.', ''),
                'total' => number_format($order->total, 2, '.', ''),
                'tax_base' => number_format($order->tax_base ?? 0, 2, '.', ''),
                'discount_type' => $order->discount_type,
                'discount_value' => $order->discount_value,
                'sub_total_formatted' => currency_format($order->sub_total, $currencyId),
                'discount_amount_formatted' => currency_format($order->discount_amount ?? 0, $currencyId),
                'total_tax_amount_formatted' => currency_format($order->total_tax_amount ?? 0, $currencyId),
                'total_formatted' => currency_format($order->total, $currencyId),
            ]
        ]);
    }

    /**
     * Update order note for an existing order (order detail / KOT view).
     */
    public function updateOrderNote(Request $request, $orderId)
    {
        $request->validate([
            'order_note' => 'nullable|string|max:2000',
        ]);

        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound'),
            ], 404);
        }

        if (!user_can('Update Order')) {
            return response()->json([
                'success' => false,
                'message' => __('messages.permissionDenied'),
            ], 403);
        }

        if ($order->status === 'paid') {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.cannotModifyPaidOrder'),
            ], 400);
        }

        $note = $request->input('order_note');
        $note = is_string($note) ? trim($note) : null;
        if ($note === '') {
            $note = null;
        }

        $order->order_note = $note;
        $order->save();

        return response()->json([
            'success' => true,
            'message' => $note ? __('messages.updatedSuccessfully') : __('messages.removedSuccessfully'),
            'order' => [
                'id' => $order->id,
                'order_note' => $order->order_note,
            ],
        ]);
    }

    /**
     * Update waiter for an order
     */
    public function updateWaiter(Request $request, $orderId)
    {
        $request->validate([
            'waiter_id' => 'nullable|integer|exists:users,id',
        ]);

        $order = Order::with(['waiter', 'table', 'branch.restaurant', 'customer'])->find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound')
            ], 404);
        }

        // Allow null to clear waiter assignment (match Livewire Pos::updatedSelectWaiter)
        $waiterId = $request->waiter_id ? intval($request->waiter_id) : null;
        $posWaitersForActor = PosWaitersCache::forPosActor(
            PosWaitersCache::remember((int) $this->restaurant->id, (int) $this->branch->id),
            auth()->user(),
            (int) $this->restaurant->id
        );
        $waiterId = PosWaitersCache::normalizeWaiterSelection(
            $waiterId,
            auth()->user(),
            (int) $this->restaurant->id,
            $posWaitersForActor
        );
        $previousWaiter = $order->waiter;
        $order->update(['waiter_id' => $waiterId]);
        $order->refresh();
        $order->loadMissing(['waiter', 'table', 'branch.restaurant', 'customer']);

        if ($order->waiter_id) {
            OrderWaiterAssigned::dispatch($order, $previousWaiter);
        }

        return response()->json([
            'success' => true,
            'message' => $waiterId ? __('messages.waiterUpdated') : __('messages.waiterRemoved'),
            'waiter_id' => $order->waiter_id
        ]);
    }

    /**
     * Persist delivery executive on the order when editing an existing order (parity with Livewire Pos::saveDeliveryExecutive).
     * When there is no order id yet, the SPA only updates posState; submitOrder/saveOrder sends delivery_executive_id.
     */
    public function updateDeliveryExecutive(Request $request, $orderId)
    {
        $request->validate([
            'delivery_executive_id' => 'nullable|integer|exists:delivery_executives,id',
        ]);

        $order = Order::find($orderId);

        if (!$order) {
            return response()->json([
                'success' => false,
                'message' => __('modules.order.orderNotFound'),
            ], 404);
        }

        $deliveryExecutiveId = $request->delivery_executive_id ? (int) $request->delivery_executive_id : null;

        if ($deliveryExecutiveId && !DeliveryExecutive::findAssignableForBranch($deliveryExecutiveId, (int) $order->branch_id)) {
            return response()->json([
                'success' => false,
                'message' => __('messages.invalidRequest'),
            ], 422);
        }

        $order->update(['delivery_executive_id' => $deliveryExecutiveId]);
        $order->refresh();

        return response()->json([
            'success' => true,
            'message' => $deliveryExecutiveId
                ? __('messages.deliveryExecutiveAssigned')
                : __('messages.deliveryExecutiveRemoved'),
            'delivery_executive_id' => $order->delivery_executive_id,
        ]);
    }

    /**
     * Clear merge session data
     */
    public function clearMergeSession()
    {
        session()->forget('pos_merge_data');
        session()->forget('pos_merged_orders_to_delete');

        return response()->json([
            'success' => true
        ]);
    }

    /**
     * Clear POS caches for current branch on server.
     */
    public function clearPosCache()
    {
        $branchId = (int) ($this->branch->id ?? 0);

        if ($branchId > 0) {
            PosBranchCacheInvalidation::invalidateForBranch($branchId);
        }

        if ($this->restaurant && $this->branch) {
            Cache::forget(PosWaitersCache::cacheKey((int) $this->restaurant->id, $branchId));
            Cache::forget('waiters_' . (int) $this->restaurant->id . '_' . $branchId);
            Cache::forget('waiters_' . $branchId);
        }

        return response()->json([
            'success' => true,
            'message' => __('messages.cacheCleared')
        ]);
    }

    /**
     * Update customer display cache with current cart data
     * Follows the pattern from Livewire Pos.php calculateTotal() method
     *
     * @param array $displayData Complete display data with items, totals, etc.
     * @return void
     */
    private function updateCustomerDisplayCache($displayData)
    {
        $userId = auth()->id();
        if (!$userId) {
            return;
        }

        $displayData = \App\Support\CustomerDisplayPayload::normalize($displayData);

        // Store in cache (matches Livewire Pos.php pattern)
        $cacheKey = 'customer_display_cart_user_' . $userId;
        Cache::put($cacheKey, $displayData, now()->addMinutes(30));

        // Broadcast customer display update if Pusher is enabled (matches Livewire pattern)
        if (pusherSettings()->is_enabled_pusher_broadcast) {
            broadcast(new \App\Events\CustomerDisplayUpdated($displayData, $userId));
        }
    }

    /**
     * Update customer display - called from JavaScript calculateTotal()
     * Mirrors Livewire Pos.php calculateTotal() customer display update logic
     */
    public function updateCustomerDisplay(Request $request)
    {
        $items = $request->input('items', []);
        $orderNumber = $request->input('order_number');
        $formattedOrderNumber = $request->input('formatted_order_number');
        $subTotal = $request->input('sub_total', 0);
        $discount = $request->input('discount', 0);
        $total = $request->input('total', 0);
        $taxes = $request->input('taxes', []);
        $extraCharges = $request->input('extra_charges', []);
        $tip = $request->input('tip', 0);
        $deliveryFee = $request->input('delivery_fee', 0);
        $orderType = $request->input('order_type');
        $status = $request->input('status', 'idle');

        // Get payment gateway QR code (matching Livewire pattern)
        $qrCodeImageUrl = restaurant()->customerDisplayQrCodeImageUrl();

        // Prepare customer display data (matching Livewire pattern exactly)
        $customerDisplayData = \App\Support\CustomerDisplayPayload::normalize([
            'order_number' => $orderNumber,
            'formatted_order_number' => $formattedOrderNumber,
            'items' => $items,
            'sub_total' => $subTotal,
            'discount' => $discount,
            'total' => $total,
            'taxes' => $taxes,
            'extra_charges' => $extraCharges,
            'tip' => $tip,
            'delivery_fee' => $deliveryFee,
            'order_type' => $orderType,
            'status' => $status,
            'cash_due' => $status === 'billed' ? $total : null,
            'qr_code_image_url' => $qrCodeImageUrl,
        ]);

        // Update cache and broadcast (matching Livewire pattern)
        $this->updateCustomerDisplayCache($customerDisplayData);

        return response()->json([
            'success' => true,
            'message' => 'Customer display updated'
        ]);
    }

    /**
     * Normalize a raw order_status string to a valid OrderStatus enum backing value, or null if not valid.
     * Accepts US spelling "canceled" as an alias for "cancelled".
     */
    private function coerceOrderStatusEnumValue(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $v = strtolower(trim($raw));
        if ($v === '') {
            return null;
        }
        if ($v === 'canceled') {
            $v = 'cancelled';
        }
        $case = OrderStatus::tryFrom($v);

        return $case ? $case->value : null;
    }
}
