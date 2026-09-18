<?php

namespace Database\Seeders;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryExecutive;
use App\Models\Kot;
use App\Models\KotItem;
use App\Models\MenuItem;
use App\Models\OnboardingStep;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderTax;
use App\Models\OrderType;
use App\Models\Payment;
use App\Models\Table;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run($branch): void
    {
        $menuItems = MenuItem::where('branch_id', $branch->id)->get(['id', 'price']);
        $taxes = Tax::withoutGlobalScopes()->where('branch_id', $branch->id)->get(['id', 'tax_percent']);
        $deliveryExecutives = DeliveryExecutive::where('branch_id', $branch->id)->orderBy('id')->get();
        $deliveryOrderType = OrderType::where('branch_id', $branch->id)->where('slug', 'delivery')->first();

        $orderNumberCounter = ((int) Order::where('branch_id', $branch->id)->max('id')) + 1;
        $kotNumberCounter = ((int) Kot::max('id')) + 1;

        if ($menuItems->isEmpty()) {
            OnboardingStep::where('branch_id', $branch->id)->update([
                'add_area_completed' => 1,
                'add_table_completed' => 1,
                'add_menu_completed' => 1,
                'add_menu_items_completed' => 1,
            ]);

            return;
        }

        $tableIds = Table::where('branch_id', $branch->id)->pluck('id')->all();
        $waiterIds = User::where('branch_id', $branch->id)->pluck('id')->all();
        $dineInOrderType = OrderType::where('branch_id', $branch->id)->where('slug', 'dine_in')->first();
        $pickupOrderType = OrderType::where('branch_id', $branch->id)->where('slug', 'pickup')->first();

        if (! empty($tableIds) && ! empty($waiterIds)) {
            $this->seedDineInOrders(
                $branch,
                $tableIds,
                $waiterIds,
                $menuItems,
                $taxes,
                $dineInOrderType,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }

        if ($pickupOrderType) {
            $this->seedPickupOrders(
                $branch,
                $menuItems,
                $taxes,
                $pickupOrderType,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }

        if ($deliveryExecutives->isNotEmpty() && $deliveryOrderType) {
            $this->seedDeliveryOrders(
                $branch,
                $menuItems,
                $taxes,
                $deliveryExecutives,
                $deliveryOrderType,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }

        OnboardingStep::where('branch_id', $branch->id)->update([
            'add_area_completed' => 1,
            'add_table_completed' => 1,
            'add_menu_completed' => 1,
            'add_menu_items_completed' => 1,
        ]);
    }

    /**
     * Delivery orders assigned to executives; first executive gets 2–3 active (in-progress) orders.
     */
    private function seedDeliveryOrders(
        $branch,
        $menuItems,
        $taxes,
        $deliveryExecutives,
        OrderType $deliveryOrderType,
        int &$orderNumberCounter,
        int &$kotNumberCounter
    ): void {
        $deliveryCustomers = Customer::query()
            ->where('restaurant_id', $branch->restaurant_id)
            ->whereHas('addresses')
            ->with('latestDeliveryAddress')
            ->orderBy('id')
            ->get();

        if ($deliveryCustomers->isEmpty()) {
            return;
        }

        $firstExecutive = $deliveryExecutives->first();
        $otherExecutives = $deliveryExecutives->slice(1);
        $activeOrderIds = [];

        // First delivery agent: 3 present / in-progress orders (assigned list)
        for ($i = 0; $i < 3; $i++) {
            $customer = $deliveryCustomers->shift();
            if (! $customer) {
                break;
            }
            $orderId = $this->placeDeliveryOrder(
                $customer,
                $branch,
                $menuItems,
                $taxes,
                $deliveryOrderType,
                $firstExecutive,
                OrderStatus::FOOD_READY,
                true,
                $orderNumberCounter,
                $kotNumberCounter
            );
            if ($orderId) {
                $activeOrderIds[] = $orderId;
            }
        }

        // Other agents: one active delivery each (today)
        foreach ($otherExecutives->take(3) as $executive) {
            $customer = $deliveryCustomers->shift();
            if (! $customer) {
                break;
            }
            $this->placeDeliveryOrder(
                $customer,
                $branch,
                $menuItems,
                $taxes,
                $deliveryOrderType,
                $executive,
                OrderStatus::FOOD_READY,
                true,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }

        // Completed deliveries (history)
        for ($i = 0; $i < 4; $i++) {
            $customer = $deliveryCustomers->shift();
            if (! $customer) {
                break;
            }
            $executive = $deliveryExecutives->random();
            $this->placeDeliveryOrder(
                $customer,
                $branch,
                $menuItems,
                $taxes,
                $deliveryOrderType,
                $executive,
                OrderStatus::DELIVERED,
                false,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }

        $firstExecutive->update([
            'status' => DeliveryExecutive::STATUS_ACTIVE,
            'is_online' => true,
        ]);

        $this->seedExecutiveLocationsFromBranch($branch, $firstExecutive, $activeOrderIds);
    }


    /**
     * @return int|null Order id
     */
    private function placeDeliveryOrder(
        Customer $customer,
        $branch,
        $menuItems,
        $taxes,
        OrderType $deliveryOrderType,
        DeliveryExecutive $executive,
        OrderStatus $orderStatus,
        bool $isToday,
        int &$orderNumberCounter,
        int &$kotNumberCounter
    ): ?int {
        if ($menuItems->isEmpty()) {
            return null;
        }

        $waiterId = User::where('branch_id', $branch->id)->value('id');

        $orderNumber = $orderNumberCounter++;
        $kotNumber = $kotNumberCounter++;
        $now = now();
        $dateTime = $isToday
            ? $now->toDateTimeString()
            : $now->copy()->subDays(rand(1, 5))->toDateTimeString();

        $order = Order::create([
            'order_number' => (string) $orderNumber,
            'table_id' => null,
            'customer_id' => $customer->id,
            'waiter_id' => $waiterId,
            'date_time' => $dateTime,
            'sub_total' => 0,
            'total' => 0,
            'status' => 'paid',
            'order_status' => $orderStatus,
            'order_type' => 'delivery',
            'order_type_id' => $deliveryOrderType->id,
            'delivery_address' => $this->deliveryAddressForOrder($customer),
            'customer_lat' => $this->deliveryLatitudeForOrder($customer),
            'customer_lng' => $this->deliveryLongitudeForOrder($customer),
            'delivery_executive_id' => $executive->id,
            'delivery_fee' => round(fake()->randomFloat(2, 20, 80), 2),
            'branch_id' => $branch->id,
            'placed_via' => 'pos',
        ]);

        $kot = Kot::create([
            'kot_number' => (string) $kotNumber,
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'status' => in_array($orderStatus, [OrderStatus::DELIVERED, OrderStatus::OUT_FOR_DELIVERY, OrderStatus::PICKED_UP], true)
                ? 'food_ready'
                : 'in_kitchen',
        ]);

        $itemsCount = min(rand(1, 4), $menuItems->count());
        $selectedItems = $itemsCount === 1
            ? collect([$menuItems->random()])
            : $menuItems->random($itemsCount);

        $subTotal = $this->insertOrderLines($order, $kot, $branch, $selectedItems, $now);
        $total = $this->applyTaxesAndPayment($order, $taxes, $subTotal);

        return $order->id;
    }

    private function insertOrderLines(Order $order, Kot $kot, $branch, $selectedItems, $now): float
    {
        $orderItemRows = [];
        $kotItemRows = [];
        $subTotal = 0.0;

        foreach ($selectedItems as $value) {
            $quantity = rand(1, 3);
            $amount = round(((float) $quantity) * (float) $value->price, 2);
            $subTotal += $amount;

            $kotItemRows[] = [
                'kot_id' => $kot->id,
                'menu_item_id' => $value->id,
                'menu_item_variation_id' => null,
                'quantity' => $quantity,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $orderItemRows[] = [
                'order_id' => $order->id,
                'menu_item_id' => $value->id,
                'menu_item_variation_id' => null,
                'quantity' => $quantity,
                'price' => $value->price,
                'amount' => $amount,
                'branch_id' => $branch->id,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($orderItemRows !== []) {
            OrderItem::insert($orderItemRows);
        }

        if ($kotItemRows !== []) {
            KotItem::insert($kotItemRows);
        }

        return $subTotal;
    }

    private function applyTaxesAndPayment(Order $order, $taxes, float $subTotal): float
    {
        $total = $subTotal + (float) ($order->delivery_fee ?? 0);

        if ($taxes && !$taxes->isEmpty()) {
            $orderTaxRows = [];
            foreach ($taxes as $value) {
                $orderTaxRows[] = [
                    'order_id' => $order->id,
                    'tax_id' => $value->id,
                ];
                $total += ((float) $value->tax_percent / 100) * $subTotal;
            }

            if ($orderTaxRows !== []) {
                OrderTax::insert($orderTaxRows);
            }
        }

        $total = round($total);

        Order::where('id', $order->id)->update([
            'sub_total' => $subTotal,
            'total' => $total,
            'amount_paid' => $total,
        ]);

        $paymentMethod = ['card', 'cash', 'upi'];

        Payment::create([
            'order_id' => $order->id,
            'payment_method' => $paymentMethod[array_rand($paymentMethod)],
            'amount' => $total,
            'branch_id' => $order->branch_id,
        ]);

        return $total;
    }

    /**
     * Seed GPS rows for the first executive at the branch coordinates (RestApi tracking table).
     *
     * @param  array<int>  $orderIds
     */
    private function seedExecutiveLocationsFromBranch(Branch $branch, DeliveryExecutive $executive, array $orderIds): void
    {
        if (! Schema::hasTable('delivery_executive_locations')) {
            return;
        }

        $lat = $branch->lat;
        $lng = $branch->lng;

        if ($lat === null || $lng === null) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($orderIds as $orderId) {
            $rows[] = [
                'delivery_executive_id' => $executive->id,
                'order_id' => $orderId,
                'restaurant_id' => $branch->restaurant_id,
                'branch_id' => $branch->id,
                'latitude' => $lat,
                'longitude' => $lng,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // Slight offset so map path is visible when multiple points exist
        if (count($orderIds) > 1) {
            $rows[] = [
                'delivery_executive_id' => $executive->id,
                'order_id' => $orderIds[0],
                'restaurant_id' => $branch->restaurant_id,
                'branch_id' => $branch->id,
                'latitude' => $lat + 0.002,
                'longitude' => $lng + 0.002,
                'created_at' => $now->copy()->subMinutes(5),
                'updated_at' => $now->copy()->subMinutes(5),
            ];
        }

        if ($rows !== []) {
            DB::table('delivery_executive_locations')->insert($rows);
        }
    }

    private function deliveryAddressForOrder(Customer $customer): ?string
    {
        return $customer->latestDeliveryAddress?->address
            ?? $customer->delivery_address
            ?? null;
    }

    private function deliveryLatitudeForOrder(Customer $customer): ?float
    {
        $lat = $customer->latestDeliveryAddress?->lat;

        return $lat !== null ? (float) $lat : null;
    }

    private function deliveryLongitudeForOrder(Customer $customer): ?float
    {
        $lng = $customer->latestDeliveryAddress?->lng;

        return $lng !== null ? (float) $lng : null;
    }

    private function createCustomer($branch): Customer
    {
        $branchModel = $branch instanceof Branch ? $branch : Branch::query()->find($branch->id ?? $branch);

        $coordinateIndex = Customer::query()
            ->where('restaurant_id', $branchModel->restaurant_id)
            ->count();

        return CustomerSeeder::createWithBranchAddress(
            $branchModel->restaurant_id,
            $branchModel,
            $coordinateIndex
        );
    }

    private function seedDineInOrders(
        $branch,
        array $tableIds,
        array $waiterIds,
        $menuItems,
        $taxes,
        ?OrderType $dineInOrderType,
        int &$orderNumberCounter,
        int &$kotNumberCounter
    ): void {
        for ($i = 0; $i < 5; $i++) {
            $this->placeDineInOrder(
                $this->createCustomer($branch),
                $branch,
                true,
                $tableIds,
                $waiterIds,
                $menuItems,
                $taxes,
                $dineInOrderType,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }

        for ($i = 0; $i < 3; $i++) {
            $this->placeDineInOrder(
                $this->createCustomer($branch),
                $branch,
                false,
                $tableIds,
                $waiterIds,
                $menuItems,
                $taxes,
                $dineInOrderType,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }
    }

    private function seedPickupOrders(
        $branch,
        $menuItems,
        $taxes,
        OrderType $pickupOrderType,
        int &$orderNumberCounter,
        int &$kotNumberCounter
    ): void {
        for ($i = 0; $i < 3; $i++) {
            $this->placePickupOrder(
                $this->createCustomer($branch),
                $branch,
                $menuItems,
                $taxes,
                $pickupOrderType,
                OrderStatus::READY_FOR_PICKUP,
                true,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }

        for ($i = 0; $i < 2; $i++) {
            $this->placePickupOrder(
                $this->createCustomer($branch),
                $branch,
                $menuItems,
                $taxes,
                $pickupOrderType,
                OrderStatus::COMPLETED,
                false,
                $orderNumberCounter,
                $kotNumberCounter
            );
        }
    }

    private function placePickupOrder(
        Customer $customer,
        $branch,
        $menuItems,
        $taxes,
        OrderType $pickupOrderType,
        OrderStatus $orderStatus,
        bool $isToday,
        int &$orderNumberCounter,
        int &$kotNumberCounter
    ): void {
        $waiterId = User::where('branch_id', $branch->id)->value('id');
        $orderNumber = $orderNumberCounter++;
        $kotNumber = $kotNumberCounter++;
        $now = now();

        $order = Order::create([
            'order_number' => (string) $orderNumber,
            'table_id' => null,
            'customer_id' => $customer->id,
            'waiter_id' => $waiterId,
            'date_time' => $isToday ? $now->toDateTimeString() : $now->copy()->subDays(rand(1, 4))->toDateTimeString(),
            'sub_total' => 0,
            'total' => 0,
            'status' => 'paid',
            'order_status' => $orderStatus,
            'order_type' => 'pickup',
            'order_type_id' => $pickupOrderType->id,
            'branch_id' => $branch->id,
            'placed_via' => 'pos',
        ]);

        $kot = Kot::create([
            'kot_number' => (string) $kotNumber,
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'status' => 'food_ready',
        ]);

        $itemsCount = min(rand(1, 3), $menuItems->count());
        $selectedItems = $itemsCount === 1
            ? collect([$menuItems->random()])
            : $menuItems->random($itemsCount);

        $subTotal = $this->insertOrderLines($order, $kot, $branch, $selectedItems, $now);
        $this->applyTaxesAndPayment($order, $taxes, $subTotal);
    }

    private function placeDineInOrder(
        Customer $customer,
        $branch,
        bool $isToday,
        array $tableIds,
        array $waiterIds,
        $menuItems,
        $taxes,
        ?OrderType $dineInOrderType,
        int &$orderNumberCounter,
        int &$kotNumberCounter
    ): void {
        $tableId = $tableIds[array_rand($tableIds)];
        $waiterId = $waiterIds[array_rand($waiterIds)];

        $orderNumber = $orderNumberCounter++;
        $kotNumber = $kotNumberCounter++;
        $now = now();

        $order = Order::create([
            'order_number' => (string) $orderNumber,
            'table_id' => $tableId,
            'customer_id' => $customer->id,
            'waiter_id' => $waiterId,
            'date_time' => $isToday ? $now->toDateTimeString() : $now->copy()->subDays(rand(1, 3))->toDateTimeString(),
            'sub_total' => 0,
            'total' => 0,
            'status' => 'paid',
            'order_status' => $isToday ? OrderStatus::PREPARING : OrderStatus::COMPLETED,
            'order_type' => 'dine_in',
            'order_type_id' => $dineInOrderType?->id,
            'branch_id' => $branch->id,
            'placed_via' => 'pos',
        ]);

        $kot = Kot::create([
            'kot_number' => (string) $kotNumber,
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'status' => 'in_kitchen',
        ]);

        $itemsCount = min(rand(1, 5), $menuItems->count());
        $selectedItems = $itemsCount === 1
            ? collect([$menuItems->random()])
            : $menuItems->random($itemsCount);

        $subTotal = $this->insertOrderLines($order, $kot, $branch, $selectedItems, $now);
        $this->applyTaxesAndPayment($order, $taxes, $subTotal);
    }
}
