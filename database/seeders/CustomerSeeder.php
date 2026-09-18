<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Restaurant;
use Illuminate\Database\Seeder;

class CustomerSeeder extends Seeder
{
    /** Delivery demo orders (one customer per order). */
    public const DELIVERY_CUSTOMER_COUNT = 12;

    /**
     * Seed restaurant-scoped customers; every customer gets one address with branch-based lat/lng.
     */
    public function run($restaurant, $branch = null): void
    {
        $restaurant = $restaurant instanceof Restaurant
            ? $restaurant
            : Restaurant::query()->find($restaurant);

        if (! $restaurant) {
            return;
        }

        $branch = $this->resolveBranch($restaurant, $branch);

        if (! $branch || $branch->lat === null || $branch->lng === null) {
            return;
        }

        $startIndex = Customer::query()
            ->where('restaurant_id', $restaurant->id)
            ->count();

        for ($i = 0; $i < self::DELIVERY_CUSTOMER_COUNT; $i++) {
            self::createWithBranchAddress($restaurant->id, $branch, $startIndex + $i);
        }
    }

    /**
     * Create a customer with delivery_address and one customer_addresses row (branch-based coords).
     */
    public static function createWithBranchAddress(int $restaurantId, Branch $branch, int $coordinateIndex): Customer
    {
        $branch = $branch->fresh() ?? $branch;
        $branchLat = (float) $branch->lat;
        $branchLng = (float) $branch->lng;
        $city = $branch->name ?: 'City';

        [$addressLat, $addressLng] = self::coordinatesNearBranch($branchLat, $branchLng, $coordinateIndex);
        $addressLine = fake()->buildingNumber() . ' ' . fake()->streetName() . ', ' . $city . ', India';

        $customer = new Customer();
        $customer->setRawAttributes([
            'restaurant_id' => $restaurantId,
            'name' => fake()->firstName() . ' ' . fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => preg_replace('/[^0-9]/', '', fake()->unique()->numerify('##########')),
            'phone_code' => '91',
            'delivery_address' => $addressLine,
        ]);
        $customer->saveQuietly(['*']); // this skips observer by setting all attributes as dirty 
   
        CustomerAddress::create([
            'customer_id' => $customer->id,
            'label' => 'Home',
            'address' => $addressLine,
            'lat' => $addressLat,
            'lng' => $addressLng,
        ]);

        return $customer->load('latestDeliveryAddress');
    }

    private function resolveBranch(Restaurant $restaurant, $branch): ?Branch
    {
        if ($branch instanceof Branch) {
            return $branch->fresh();
        }

        if ($branch) {
            return Branch::query()->find($branch);
        }

        return $restaurant->branches()
            ->whereNotNull('lat')
            ->whereNotNull('lng')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array{0: float, 1: float}
     */
    public static function coordinatesNearBranch(float $branchLat, float $branchLng, int $index): array
    {
        $ring = intdiv($index, 4);
        $slot = $index % 4;
        $delta = 0.002 * (1 + $ring);

        $offsets = [
            [0.0, $delta],
            [$delta, 0.0],
            [0.0, -$delta],
            [-$delta, 0.0],
        ];

        [$dLat, $dLng] = $offsets[$slot];

        return [
            round($branchLat + $dLat, 7),
            round($branchLng + $dLng, 7),
        ];
    }
}
