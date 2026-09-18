<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryExecutive;
use App\Models\DeliveryPlatform;
use App\Models\ItemCategory;
use App\Models\KotCancelReason;
use App\Models\KotPlace;
use App\Models\Menu;
use App\Models\MenuItem;
use App\Models\OfflinePaymentMethod;
use App\Models\OrderCharge;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTax;
use App\Models\OrderType;
use App\Models\Printer;
use App\Models\RestaurantCharge;
use App\Models\Restaurant;
use App\Models\Table;
use App\Models\Tax;
use App\Models\Kot;
use App\Models\KotItem;
use App\Enums\OrderStatus;
use App\Services\Pos\MenuItemsCatalogCache;
use App\Services\Pos\PosOrderTypeClientData;
use App\Services\Pos\PosWaitersCache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OfflineController extends Controller
{


    /**
     * Whether loyalty (points redemption) is enabled for POS.
     * Keep this aligned with PosController / PosAjaxController checks.
     */
    private function isLoyaltyEnabledForPos(): bool
    {
        if (!function_exists('module_enabled') || !module_enabled('Loyalty')) {
            return false;
        }

        if (function_exists('restaurant_modules') && !in_array('Loyalty', restaurant_modules())) {
            return false;
        }

        if (!class_exists(\Modules\Loyalty\Services\PosLoyaltyHandler::class)) {
            return false;
        }

        try {
            $handler = new \Modules\Loyalty\Services\PosLoyaltyHandler(new \stdClass());
            if (method_exists($handler, 'isPointsEnabledForPOS')) {
                return (bool) $handler->isPointsEnabledForPOS();
            }
        } catch (\Throwable $e) {
            return false;
        }

        return class_exists(\Modules\Loyalty\Services\LoyaltyService::class)
            && class_exists(\Modules\Loyalty\Entities\LoyaltySetting::class);
    }

    private function resolveCustomerForOfflineOrder(int $restaurantId, array $customerData): ?Customer
    {
        if (empty($customerData)) {
            return null;
        }

        $customerId = isset($customerData['id']) ? (int) $customerData['id'] : null;
        if ($customerId) {
            return Customer::withoutGlobalScopes()
                ->where('restaurant_id', $restaurantId)
                ->find($customerId);
        }

        $phone = trim((string) ($customerData['phone'] ?? ''));
        $email = trim((string) ($customerData['email'] ?? ''));
        $phoneCode = trim((string) ($customerData['phone_code'] ?? ''));

        if ($phone === '' && $email === '') {
            return null;
        }

        $query = Customer::withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->where(function ($q) use ($phone, $email) {
                if ($phone !== '') {
                    $q->orWhere('phone', $phone);
                }
                if ($email !== '') {
                    $q->orWhere('email', $email);
                }
            });

        $customer = $query->first();

        $payload = [
            'restaurant_id' => $restaurantId,
            'name' => $customerData['name'] ?? '',
            'email' => $email !== '' ? $email : null,
            'phone' => $phone !== '' ? $phone : null,
            'phone_code' => $phoneCode !== '' ? $phoneCode : null,
            'delivery_address' => $customerData['delivery_address'] ?? ($customerData['address'] ?? null),
        ];

        if ($customer) {
            $customer->fill(array_filter($payload, fn($value) => $value !== null && $value !== ''));
            $customer->save();

            return $customer;
        }

        return Customer::withoutGlobalScopes()->create($payload);
    }

    public function bootstrap()
    {
        $currentBranch = function_exists('branch') ? branch() : null;
        $currentRestaurant = function_exists('restaurant') ? restaurant() : null;
        $branchId = (int) ($currentBranch?->id ?? 1);
        $restaurantId = (int) ($currentRestaurant?->id ?? 1);

        $menuItems = MenuItem::withoutGlobalScopes()
            ->with([
                'variations:id,menu_item_id,variation,price',
                'modifiers:id,menu_item_id,modifier_group_id,is_required,allow_multiple_selection',
                'modifiers.modifierGroup:id,name',
                'taxes:id,tax_name,tax_percent',
            ])
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(function (MenuItem $item) {
                $taxes = $item->taxes->map(fn($tax) => [
                    'id' => $tax->id,
                    'name' => $tax->tax_name,
                    'rate' => (float) $tax->tax_percent,
                ])->values();

                return [
                    'id' => $item->id,
                    'name' => $item->item_name,
                    'description' => $item->description,
                    'price' => (float) ($item->getRawOriginal('price') ?? 0),
                    'tax_id' => $taxes->first()['id'] ?? null,
                    'taxes' => $taxes,
                    'category_id' => $item->item_category_id,
                    'variants' => $item->variations->map(fn($variation) => [
                        'id' => $variation->id,
                        'name' => $variation->variation,
                        'price' => (float) $variation->price,
                    ])->values(),
                    'modifiers' => $item->modifiers
                        ->filter(fn($modifier) => $modifier->modifierGroup !== null)
                        ->map(fn($modifier) => [
                            'id' => $modifier->modifierGroup->id,
                            'name' => $modifier->modifierGroup->name,
                            'is_required' => (bool) $modifier->is_required,
                            'max_allowed' => $modifier->allow_multiple_selection ? null : 1,
                        ])->values(),
                    'image_url' => $item->item_photo_url,
                    'is_available' => (bool) $item->is_available,
                    'sort_order' => (int) ($item->sort_order ?? 0),
                ];
            })
            ->values();

        $categories = ItemCategory::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn(ItemCategory $category) => [
                'id' => $category->id,
                'name' => $category->category_name,
                'color' => null,
                'icon' => null,
                'sort_order' => (int) ($category->sort_order ?? 0),
                'is_active' => true,
            ])->values();

        $tables = Table::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->orderBy('id')
            ->get()
            ->map(fn(Table $table) => [
                'id' => $table->id,
                'name' => $table->table_code,
                'section_id' => $table->area_id,
                'seats' => (int) ($table->seating_capacity ?? 0),
                'available_status' => $table->available_status,
                'shape' => null,
                'x' => null,
                'y' => null,
                'is_active' => $table->status === 'active',
            ])->values();

        $sections = DB::table('areas')
            ->where('branch_id', $branchId)
            ->orderBy('id')
            ->get()
            ->map(fn($area) => [
                'id' => $area->id,
                'name' => $area->area_name,
            ])->values();

        $taxes = Tax::withoutGlobalScopes()
            ->where(function ($query) use ($branchId, $restaurantId) {
                $query->where('branch_id', $branchId)->orWhere('restaurant_id', $restaurantId);
            })
            ->orderBy('id')
            ->get()
            ->map(fn(Tax $tax) => [
                'id' => $tax->id,
                'name' => $tax->tax_name,
                'rate' => (float) $tax->tax_percent,
                'type' => null,
                'applicable_to' => 'menu_item',
            ])->values();

        $paymentMethods = OfflinePaymentMethod::withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->orderBy('id')
            ->get()
            ->map(fn(OfflinePaymentMethod $method) => [
                'id' => $method->id,
                'name' => $method->name,
                'type' => 'offline',
                'is_active' => $method->status === 'active',
            ])->values();

        $restaurant = Restaurant::withoutGlobalScopes()
            ->with(['currency'])
            ->find($restaurantId);

        $branch = Branch::withoutGlobalScopes()->find($branchId);

        $printers = Printer::withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->where('branch_id', $branchId)
            ->orderBy('id')
            ->get()
            ->map(fn(Printer $printer) => [
                'id' => $printer->id,
                'name' => $printer->name,
                'ip' => $printer->ipv4_address ?? $printer->ip_address,
                'type' => $printer->printing_choice ?? $printer->type,
                'assigned_sections' => $printer->kots ?? [],
                'is_active' => (bool) ($printer->is_active ?? true),
            ])->values();

        $menus = Menu::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'menu_name', 'sort_order'])
            ->map(fn($menu) => [
                'id' => $menu->id,
                'name' => $menu->menu_name,
                'sort_order' => (int) ($menu->sort_order ?? 0),
            ])->values();

        $orderTypes = OrderType::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->availableForRestaurant()
            ->orderBy('order_type_name')
            ->get()
            ->map(fn($type) => [
                'id' => (int) $type->id,
                'slug' => $type->slug,
                'type' => $type->type,
                'order_type_name' => $type->order_type_name,
                'translated_name' => $type->translated_name,
                'enable_token_number' => (bool) ($type->enable_token_number ?? false),
                'show_order_number_on_board' => (bool) ($type->show_order_number_on_board ?? false),
                'enable_from_customer_site' => (bool) ($type->enable_from_customer_site ?? false),
            ])->values();

        $cancelReasonsQuery = KotCancelReason::withoutGlobalScopes()
            ->where('cancel_order', true);
        if (Schema::hasColumn('kot_cancel_reasons', 'branch_id')) {
            $cancelReasonsQuery->where('branch_id', $branchId);
        }
        $cancelReasons = $cancelReasonsQuery
            ->orderBy('id')
            ->get()
            ->map(fn($reason) => [
                'id' => $reason->id,
                'reason' => $reason->reason ?? $reason->name ?? null,
            ])->values();

        $orderPlacesQuery = KotPlace::withoutGlobalScopes();
        if (Schema::hasColumn('kot_places', 'branch_id')) {
            $orderPlacesQuery->where('branch_id', $branchId);
        }
        $orderPlaces = $orderPlacesQuery
            ->orderBy('id')
            ->get()
            ->map(fn($place) => [
                'id' => $place->id,
                'name' => $place->name ?? null,
                'is_active' => (bool) ($place->is_active ?? true),
            ])->values();

        $deliveryPlatforms = DeliveryPlatform::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn(DeliveryPlatform $platform) => [
                'id' => (int) $platform->id,
                'name' => $platform->name,
                'logo_url' => $platform->logo_url,
                'commission_type' => $platform->commission_type,
                'commission_value' => (float) ($platform->commission_value ?? 0),
            ])->values();

        $deliveryExecutives = DeliveryExecutive::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->assignable()
            ->orderBy('id')
            ->get()
            ->map(function ($executive) {
                $tz = timezone();
                $dayStartUtc = now($tz)->startOfDay()->setTimezone('UTC')->toDateTimeString();
                $dayEndUtc = now($tz)->endOfDay()->setTimezone('UTC')->toDateTimeString();
                $busy = Order::withoutGlobalScopes()
                    ->where('branch_id', $executive->branch_id)
                    ->where('delivery_executive_id', $executive->id)
                    ->whereNotIn('order_status', OrderStatus::terminalProgressValues())
                    ->where('date_time', '>=', $dayStartUtc)
                    ->where('date_time', '<=', $dayEndUtc)
                    ->exists();

                return [
                    'id' => $executive->id,
                    'name' => $executive->name,
                    'phone' => $executive->phone ?? null,
                    'is_online' => (bool) ($executive->is_online ?? false),
                    'is_busy' => $busy,
                ];
            })->values();

        $waiters = PosWaitersCache::remember($restaurantId, $branchId);
        $waiters = PosWaitersCache::forPosActor($waiters, auth()->user(), $restaurantId)
            ->map(fn($waiter) => [
                'id' => $waiter->id,
                'name' => $waiter->name,
                'email' => $waiter->email,
            ])->values();

        $categoryList = ItemCategory::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->withCount(['items' => function ($query) use ($branchId) {
                $query->withoutGlobalScopes()->where('branch_id', $branchId);
            }])
            ->having('items_count', '>', 0)
            ->get()
            ->map(fn($category) => [
                'id' => $category->id,
                'name' => $category->category_name,
                'items_count' => (int) ($category->items_count ?? 0),
            ])->values();

        $menuCatalog = MenuItemsCatalogCache::getCatalogPayload($branchId);

        $orderTypeModels = OrderType::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->availableForRestaurant()
            ->orderBy('order_type_name')
            ->get();
        $deliveryPlatformModels = DeliveryPlatform::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
        $modalScript = PosOrderTypeClientData::buildModalScriptPayload(
            $branchId,
            $branch,
            $orderTypeModels,
            $deliveryPlatformModels
        );

        $extraChargesBySlug = [];
        foreach ($orderTypeModels as $orderType) {
            $chargesQuery = RestaurantCharge::withoutGlobalScopes()
                ->whereJsonContains('order_types', $orderType->slug)
                ->where('is_enabled', true);

            if (Schema::hasColumn('restaurant_charges', 'branch_id')) {
                $chargesQuery->where('branch_id', $branchId);
            }

            $extraChargesBySlug[$orderType->slug] = $chargesQuery->get()->values();
        }

        $customers = Customer::withoutGlobalScopes()
            ->where('restaurant_id', $restaurantId)
            ->orderBy('id')
            ->get()
            ->map(fn(Customer $customer) => [
                'id' => $customer->id,
                'name' => $customer->name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'phone_code' => $customer->phone_code,
                'loyalty_points' => (int) ($customer->loyalty_points ?? 0),
                'delivery_address' => $customer->delivery_address ?? null,
                'created_at' => optional($customer->created_at)->toIso8601String(),
                'updated_at' => optional($customer->updated_at)->toIso8601String(),
            ])->values();

        $orders = Order::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->with([
                'customer:id,name,phone,email',
                'table:id,table_code,area_id,seating_capacity,status,available_status',
                'waiter:id,name',
                'orderType:id,order_type_name,slug,type',
                'deliveryApp:id,name',
                'items:id,order_id,menu_item_id,menu_item_variation_id,quantity,price,amount,note,tax_amount,tax_breakup,created_at,updated_at',
                'items.menuItem:id,item_name',
                'items.menuItemVariation:id,variation,price',
                'items.modifierOptions:id,name,price',
                'taxes:id,order_id,tax_id',
                'taxes.tax:id,tax_name,tax_percent',
                'extraCharges',
                'payments',
                'kot:id,order_id,kot_number,status,created_at,updated_at',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn(Order $order) => [
                'id' => $order->id,
                'uuid' => $order->uuid,
                'order_number' => $order->order_number,
                'formatted_order_number' => $order->show_formatted_order_number,
                'date_time' => optional($order->date_time)->toIso8601String(),
                'status' => $order->status,
                'order_status' => is_object($order->order_status) ? $order->order_status->value : $order->order_status,
                'customer' => $order->customer,
                'table' => $order->table,
                'waiter' => $order->waiter,
                'order_type' => $order->orderType,
                'delivery_platform' => $order->deliveryApp,
                'number_of_pax' => $order->number_of_pax,
                'sub_total' => (float) ($order->sub_total ?? 0),
                'discount_type' => $order->discount_type,
                'discount_value' => $order->discount_value,
                'discount_amount' => (float) ($order->discount_amount ?? 0),
                'total_tax_amount' => (float) ($order->total_tax_amount ?? 0),
                'delivery_fee' => (float) ($order->delivery_fee ?? 0),
                'tip_amount' => (float) ($order->tip_amount ?? 0),
                'total' => (float) ($order->total ?? 0),
                'amount_paid' => (float) ($order->amount_paid ?? 0),
                'remaining_amount' => (float) $order->remainingAmount(),
                'tax_mode' => $order->tax_mode,
                'items' => $order->items,
                'taxes' => $order->taxes,
                'extra_charges' => $order->extraCharges,
                'payments' => $order->payments,
                'kots' => $order->kot,
                'created_at' => optional($order->created_at)->toIso8601String(),
                'updated_at' => optional($order->updated_at)->toIso8601String(),
            ])->values();

        $kots = \App\Models\Kot::withoutGlobalScopes()
            ->where('branch_id', $branchId)
            ->with([
                'order:id,uuid,order_number,status,order_status,total,amount_paid',
                'table:id,table_code,area_id',
                'kotPlace:id,name',
                'cancelReason:id,reason,name',
                'orderType:id,order_type_name,slug,type',
                'items',
                'items.menuItem:id,item_name',
                'items.menuItemVariation:id,variation,price',
                'items.modifierOptions:id,name,price',
                'items.cancelReason:id,reason,name',
            ])
            ->orderBy('id')
            ->get()
            ->map(fn($kot) => [
                'id' => $kot->id,
                'order_id' => $kot->order_id,
                'kot_number' => $kot->kot_number,
                'status' => $kot->status,
                'token_number' => $kot->token_number ?? null,
                'table' => $kot->table,
                'order' => $kot->order,
                'kot_place' => $kot->kotPlace,
                'cancel_reason' => $kot->cancelReason,
                'order_type' => $kot->orderType,
                'items' => $kot->items,
                'created_at' => optional($kot->created_at)->toIso8601String(),
                'updated_at' => optional($kot->updated_at)->toIso8601String(),
            ])->values();

        $orderNumberData = $branch ? Order::generateOrderNumber($branch) : ['order_number' => null, 'formatted_order_number' => null];
        $posLoyaltyEnabled = $this->isLoyaltyEnabledForPos();

        $responseData = [
            'branch_id' => $branchId,
            'restaurant_id' => $restaurantId,
            'menu_items' => $menuItems,
            'categories' => $categories,
            'tables' => $tables,
            'sections' => $sections,
            'taxes' => $taxes,
            'discounts' => [],
            'payment_methods' => $paymentMethods,
            'customers' => $customers,
            'orders' => $orders,
            'kots' => $kots,
            'restaurant_config' => [
                'name' => $restaurant?->name,
                'logo_url' => $restaurant?->logo_url,
                'currency' => $restaurant?->currency?->currency_code ?? null,
                'currency_symbol' => $restaurant?->currency?->currency_symbol ?? null,
                'timezone' => $restaurant?->timezone,
                'receipt_header' => $branch?->cr_vat ?? null,
                'receipt_footer' => $branch?->receiptSetting?->displayFooterText(),
                'kot_printer_ip' => $printers->firstWhere('type', 'kot')['ip'] ?? null,
            ],
            'menus' => $menus,
            'menu_catalog' => $menuCatalog,
            'printers' => $printers,
            'pos_context' => [
                'order_number' => $orderNumberData['order_number'] ?? null,
                'formatted_order_number' => $orderNumberData['formatted_order_number'] ?? null,
                'waiters' => $waiters,
                'delivery_executives' => $deliveryExecutives,
                'delivery_platforms' => $deliveryPlatforms,
                'order_types' => $orderTypes,
                'cancel_reasons' => $cancelReasons,
                'order_places' => $orderPlaces,
                'category_list' => $categoryList,
                'extra_charges_by_slug' => $extraChargesBySlug,
                'pos_order_type_price_maps' => $modalScript['posOrderTypePriceMaps'] ?? [],
                'pos_extra_charges_by_slug' => $modalScript['posExtraChargesBySlug'] ?? [],
                'pos_delivery_default_fee' => $modalScript['posDeliveryDefaultFee'] ?? 0,
                'pos_order_types_for_modal' => $modalScript['posOrderTypesForModal'] ?? [],
                'pos_delivery_platforms_for_modal' => $modalScript['posDeliveryPlatformsForModal'] ?? [],
                'tax_mode' => $restaurant?->tax_mode ?? 'order',
                'include_charges_in_tax_base' => (bool) ($restaurant?->include_charges_in_tax_base ?? false),
                'disable_order_type_popup' => (bool) ($restaurant?->disable_order_type_popup ?? false),
                'default_order_type_id' => $restaurant?->default_order_type_id,
                'pickup_days_range' => (int) ($restaurant?->pickup_days_range ?? 1),
                'date_format' => $restaurant?->date_format,
                'time_format' => $restaurant?->time_format ?? 'h:i A',
                'pos_loyalty_enabled' => $posLoyaltyEnabled,
            ],
            'meta' => [
                'bootstrapped_at' => now()->toIso8601String(),
                'payload_size_kb' => 0,
                'notes' => [
                    'Discount master data is not configured yet, returning empty list.',
                    'Table shape/position and category color/icon are unavailable in schema, returning null/default values.',
                ],
            ],
        ];

        $responseData['meta']['payload_size_kb'] = round(strlen(json_encode($responseData)) / 1024, 2);

        return response()->json($responseData);
    }

    public function createOrder(Request $request)
    {
        $data = $request->validate([
            'table_id' => ['nullable', 'integer'],
            'waiter_id' => ['nullable', 'integer'],
            'table_available_status' => ['nullable', 'string', 'max:50'],
            'delivery_executive_id' => ['nullable', 'integer'],
            'delivery_app_id' => ['nullable'],
            'order_type_id' => ['nullable', 'integer'],
            'order_type' => ['nullable', 'string', 'max:100'],
            'order_type_slug' => ['nullable', 'string', 'max:100'],
            'number_of_pax' => ['nullable', 'integer', 'min:1'],
            'order_note' => ['nullable', 'string'],
            'pickup_date' => ['nullable', 'string'],
            'sub_total' => ['nullable', 'numeric'],
            'discount_type' => ['nullable', 'string', 'max:50'],
            'discount_value' => ['nullable', 'numeric'],
            'discount_amount' => ['nullable', 'numeric'],
            'total_tax_amount' => ['nullable', 'numeric'],
            'tax_base' => ['nullable', 'numeric'],
            'delivery_fee' => ['nullable', 'numeric'],
            'tip_amount' => ['nullable', 'numeric'],
            'total' => ['required', 'numeric'],
            'tax_mode' => ['nullable', 'string', 'max:20'],
            'include_charges_in_tax_base' => ['nullable', 'boolean'],
            'status' => ['nullable', 'string', 'max:50'],
            'order_status' => ['nullable', 'string', 'max:50'],
            'date_time' => ['nullable', 'string'],
            'actions' => ['nullable', 'array'],
            'actions.*' => ['string'],
            'customer_id' => ['nullable', 'integer'],
            'customer' => ['nullable', 'array'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer'],
            'items.*.variant_id' => ['nullable', 'integer'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'numeric'],
            'items.*.amount' => ['nullable', 'numeric'],
            'items.*.note' => ['nullable', 'string'],
            'items.*.tax_amount' => ['nullable', 'numeric'],
            'items.*.tax_breakup' => ['nullable'],
            'items.*.modifier_ids' => ['nullable', 'array'],
            'items.*.modifier_ids.*' => ['integer'],
            'items.*.is_free_item_from_stamp' => ['nullable', 'boolean'],
            'items.*.stamp_rule_id' => ['nullable', 'integer'],
            'extra_charges' => ['nullable', 'array'],
            'extra_charges.*.id' => ['nullable', 'integer'],
            'extra_charges.*.charge_id' => ['nullable', 'integer'],
            'taxes' => ['nullable', 'array'],
            'taxes.*.id' => ['nullable', 'integer'],
            'taxes.*.tax_id' => ['nullable', 'integer'],
            'create_kot' => ['nullable', 'boolean'],
            'kitchen_place_id' => ['nullable', 'integer'],
        ]);

        $currentBranch = function_exists('branch') ? branch() : null;
        $currentRestaurant = function_exists('restaurant') ? restaurant() : null;
        $branch = Branch::withoutGlobalScopes()->find((int) ($currentBranch?->id ?? 1));
        $restaurant = Restaurant::withoutGlobalScopes()->find((int) ($currentRestaurant?->id ?? 1));

        if (!$branch || !$restaurant) {
            return response()->json([
                'success' => false,
                'message' => 'Branch/Restaurant context not found.',
            ], 422);
        }

        $customerIdFromPayload = isset($data['customer_id']) ? (int) $data['customer_id'] : null;
        $customer = null;

        if ($customerIdFromPayload) {
            $customer = Customer::withoutGlobalScopes()
                ->where('restaurant_id', (int) $restaurant->id)
                ->find($customerIdFromPayload);
        }

        if (!$customer) {
            $customer = $this->resolveCustomerForOfflineOrder((int) $restaurant->id, (array) ($data['customer'] ?? []));
        }

        $posWaitersForActor = PosWaitersCache::forPosActor(
            PosWaitersCache::remember((int) $restaurant->id, (int) $branch->id),
            auth()->user(),
            (int) $restaurant->id
        );
        $waiterRaw = $data['waiter_id'] ?? null;
        $waiterInt = is_numeric($waiterRaw) ? (int) $waiterRaw : null;
        $data['waiter_id'] = PosWaitersCache::normalizeWaiterSelection(
            $waiterInt,
            auth()->user(),
            (int) $restaurant->id,
            $posWaitersForActor
        );

        $orderTypeId = isset($data['order_type_id']) ? (int) $data['order_type_id'] : null;
        $orderTypeModel = $orderTypeId
            ? OrderType::withoutGlobalScopes()->find($orderTypeId)
            : null;
        $orderTypeSlug = $data['order_type_slug'] ?? $orderTypeModel?->slug ?? $data['order_type'] ?? null;
        $orderTypeName = $data['order_type'] ?? $orderTypeModel?->order_type_name ?? $orderTypeSlug;
        $createKot = array_key_exists('create_kot', $data) ? (bool) $data['create_kot'] : true;
        $actions = collect($data['actions'] ?? [])->map(fn($v) => strtolower((string) $v))->values();
        $requestedStatus = strtolower((string) ($data['status'] ?? ''));
        $isBillAction = $actions->contains('bill') || $actions->contains('billed');
        $isKotAction = $actions->contains('kot') || $actions->contains('send_to_kitchen');
        $status = match (true) {
            in_array($requestedStatus, ['draft', 'kot', 'billed', 'canceled'], true) => $requestedStatus,
            $isBillAction => 'billed',
            $isKotAction => 'kot',
            default => 'draft',
        };

        $allowedOrderStatuses = collect(OrderStatus::cases())->map(fn($case) => $case->value)->all();
        $requestedOrderStatus = strtolower((string) ($data['order_status'] ?? ''));
        $orderStatus = in_array($requestedOrderStatus, $allowedOrderStatuses, true)
            ? $requestedOrderStatus
            : ($status === 'draft' ? OrderStatus::PLACED->value : OrderStatus::CONFIRMED->value);

        if ($isKotAction) {
            $createKot = true;
        }
        $orderNumberData = $status === 'draft' ? ['order_number' => null, 'formatted_order_number' => null] : Order::generateOrderNumber($branch);
        $subTotal = (float) ($data['sub_total'] ?? 0);
        $discountAmount = (float) ($data['discount_amount'] ?? 0);
        $baseTaxBase = max(0, $subTotal - $discountAmount);
        $includeChargesInTaxBase = (bool) ($data['include_charges_in_tax_base'] ?? false);
        $extraChargesAmount = collect($data['extra_charges'] ?? [])->sum(fn($charge) => (float) ($charge['amount'] ?? 0));
        $taxBase = array_key_exists('tax_base', $data)
            ? (float) $data['tax_base']
            : ($includeChargesInTaxBase ? $baseTaxBase + $extraChargesAmount : $baseTaxBase);

        DB::beginTransaction();
        try {
            $order = Order::withoutGlobalScopes()->create([
                'branch_id' => $branch->id,
                'table_id' => $orderTypeSlug === 'dine_in' ? ($data['table_id'] ?? null) : null,
                'date_time' => !empty($data['date_time']) ? $data['date_time'] : now(),
                'number_of_pax' => (int) ($data['number_of_pax'] ?? 1),
                'waiter_id' => $data['waiter_id'] ?? null,
                'customer_id' => $customer?->id,
                'order_number' => $orderNumberData['order_number'] ?? null,
                'formatted_order_number' => $orderNumberData['formatted_order_number'] ?? null,
                'order_type' => $orderTypeSlug,
                'order_type_id' => $orderTypeModel?->id,
                'custom_order_type_name' => $orderTypeName,
                'pickup_date' => $data['pickup_date'] ?? null,
                'delivery_fee' => (float) ($data['delivery_fee'] ?? 0),
                'delivery_executive_id' => $data['delivery_executive_id'] ?? null,
                'delivery_app_id' => ($data['delivery_app_id'] ?? null) === 'default' ? null : ($data['delivery_app_id'] ?? null),
                'tip_amount' => (float) ($data['tip_amount'] ?? 0),
                'discount_type' => $data['discount_type'] ?? null,
                'discount_value' => $data['discount_value'] ?? null,
                'discount_amount' => $discountAmount,
                'sub_total' => $subTotal,
                'total_tax_amount' => (float) ($data['total_tax_amount'] ?? 0),
                'tax_base' => $taxBase,
                'total' => (float) $data['total'],
                'tax_mode' => $data['tax_mode'] ?? 'order',
                'status' => $status,
                'order_status' => $orderStatus,
                'placed_via' => 'pos',
                'order_note' => $data['order_note'] ?? null,
            ]);

            foreach ((array) $data['items'] as $item) {
                $orderItem = OrderItem::withoutGlobalScopes()->create([
                    'branch_id' => $branch->id,
                    'order_id' => $order->id,
                    'menu_item_id' => (int) $item['id'],
                    'menu_item_variation_id' => !empty($item['variant_id']) ? (int) $item['variant_id'] : null,
                    'quantity' => (int) $item['quantity'],
                    'price' => (float) $item['price'],
                    'amount' => array_key_exists('amount', $item) ? (float) $item['amount'] : ((float) $item['price'] * (int) $item['quantity']),
                    'note' => $item['note'] ?? null,
                    'tax_amount' => (float) ($item['tax_amount'] ?? 0),
                    'tax_breakup' => is_array($item['tax_breakup'] ?? null) ? json_encode($item['tax_breakup']) : ($item['tax_breakup'] ?? null),
                    'is_free_item_from_stamp' => (bool) ($item['is_free_item_from_stamp'] ?? false),
                    'stamp_rule_id' => $item['stamp_rule_id'] ?? null,
                ]);

                $modifierIds = collect($item['modifier_ids'] ?? [])->filter()->map(fn($id) => (int) $id)->values()->all();
                if ($modifierIds !== []) {
                    $orderItem->modifierOptions()->sync($modifierIds);
                }
            }

            foreach ((array) ($data['taxes'] ?? []) as $taxRow) {
                $taxId = (int) ($taxRow['tax_id'] ?? $taxRow['id'] ?? 0);
                if ($taxId > 0) {
                    OrderTax::withoutGlobalScopes()->create([
                        'order_id' => $order->id,
                        'tax_id' => $taxId,
                    ]);
                }
            }

            foreach ((array) ($data['extra_charges'] ?? []) as $chargeRow) {
                $chargeId = (int) ($chargeRow['charge_id'] ?? $chargeRow['id'] ?? 0);
                if ($chargeId > 0) {
                    OrderCharge::withoutGlobalScopes()->create([
                        'order_id' => $order->id,
                        'charge_id' => $chargeId,
                    ]);
                }
            }

            $createdKotIds = [];
            if ($createKot) {
                $kot = Kot::withoutGlobalScopes()->create([
                    'branch_id' => $branch->id,
                    'kot_number' => Kot::generateKotNumber($branch),
                    'order_id' => $order->id,
                    'order_type_id' => $orderTypeModel?->id,
                    'token_number' => Kot::generateTokenNumber($branch->id, $orderTypeModel?->id),
                    'note' => $data['order_note'] ?? null,
                    'kitchen_place_id' => $data['kitchen_place_id'] ?? null,
                    'status' => 'placed',
                ]);
                $createdKotIds[] = $kot->id;

                foreach ((array) $data['items'] as $item) {
                    $kotItem = KotItem::withoutGlobalScopes()->create([
                        'kot_id' => $kot->id,
                        'menu_item_id' => (int) $item['id'],
                        'menu_item_variation_id' => !empty($item['variant_id']) ? (int) $item['variant_id'] : null,
                        'quantity' => (int) $item['quantity'],
                        'price' => (float) $item['price'],
                        'amount' => array_key_exists('amount', $item) ? (float) $item['amount'] : ((float) $item['price'] * (int) $item['quantity']),
                        'note' => $item['note'] ?? null,
                        'is_free_item_from_stamp' => (bool) ($item['is_free_item_from_stamp'] ?? false),
                        'stamp_rule_id' => $item['stamp_rule_id'] ?? null,
                        'order_type_id' => $orderTypeModel?->id,
                        'order_type' => $orderTypeSlug,
                    ]);

                    $modifierIds = collect($item['modifier_ids'] ?? [])->filter()->map(fn($id) => (int) $id)->values()->all();
                    if ($modifierIds !== []) {
                        $kotItem->modifierOptions()->sync($modifierIds);
                    }
                }
            }

            if (!empty($data['table_id'])) {
                $targetTableStatus = $data['table_available_status'] ?? null;
                if (!$targetTableStatus) {
                    $targetTableStatus = in_array($status, ['kot', 'billed'], true) ? 'running' : 'available';
                }
                Table::withoutGlobalScopes()
                    ->where('id', (int) $data['table_id'])
                    ->update(['available_status' => $targetTableStatus]);
            }

            DB::commit();

            $customerColumns = ['id', 'name', 'email', 'phone', 'phone_code'];
            if (Schema::hasColumn('customers', 'delivery_address')) {
                $customerColumns[] = 'delivery_address';
            }

            $order->load([
                'customer:' . implode(',', $customerColumns),
                'items.modifierOptions:id,name,price',
                'taxes.tax:id,tax_name,tax_percent',
                'extraCharges',
                'kot.items.modifierOptions:id,name,price',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Order and KOT created successfully.',
                'order_id' => $order->id,
                'order_uuid' => $order->uuid,
                'kot_ids' => $createdKotIds,
                'order' => $order,
                'expected_payload' => [
                    'customer' => ['id?', 'name?', 'phone?', 'phone_code?', 'email?', 'delivery_address?'],
                    'items' => ['id', 'variant_id?', 'quantity', 'price', 'amount?', 'modifier_ids?', 'note?', 'tax_amount?', 'tax_breakup?'],
                    'taxes' => ['tax_id|id'],
                    'extra_charges' => ['charge_id|id'],
                    'order_meta' => ['table_id?', 'waiter_id?', 'order_type_id?|order_type_slug?', 'number_of_pax?', 'order_note?'],
                    'totals' => ['sub_total?', 'discount_type?', 'discount_value?', 'discount_amount?', 'total_tax_amount?', 'delivery_fee?', 'tip_amount?', 'total'],
                ],
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Unable to create offline order.',
                'error' => $e->getMessage(),
            ], 422);
        }
    }
}
