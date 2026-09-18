<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\Reservation;
use App\Models\ReservationSetting;
use App\Models\Table;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ReservationSeeder extends Seeder
{
    public const RESERVATION_COUNT = 12;

    /**
     * Seed sample table reservations for demo / non-codecanyon installs.
     */
    public function run($branch): void
    {
        $branch = $branch instanceof Branch
            ? $branch->loadMissing('restaurant')
            : Branch::query()->with('restaurant')->find($branch);

        if (! $branch) {
            return;
        }

        $customers = Customer::query()
            ->where('restaurant_id', $branch->restaurant_id)
            ->orderBy('id')
            ->get(['id']);

        $tables = Table::query()
            ->where('branch_id', $branch->id)
            ->orderBy('id')
            ->get(['id', 'area_id', 'seating_capacity']);

        if ($customers->isEmpty()) {
            return;
        }

        $timezone = $branch->restaurant?->timezone ?: config('app.timezone', 'UTC');
        $now = Carbon::now($timezone);

        $slotDefinitions = [
            ['type' => 'Breakfast', 'hour' => 9, 'minute' => 0, 'difference' => 30],
            ['type' => 'Lunch', 'hour' => 13, 'minute' => 0, 'difference' => 60],
            ['type' => 'Dinner', 'hour' => 19, 'minute' => 30, 'difference' => 60],
        ];

        for ($i = 0; $i < self::RESERVATION_COUNT; $i++) {
            $slot = $slotDefinitions[$i % count($slotDefinitions)];

            $dayOffset = match (true) {
                $i < 4 => 0,
                $i < 8 => 1,
                $i < 10 => -1,
                default => -2,
            };

            $reservationAt = $now->copy()
                ->addDays($dayOffset)
                ->setTime($slot['hour'], $slot['minute'], 0);

            if ($dayOffset === 0 && $reservationAt->lte($now)) {
                $reservationAt->addDay();
                $dayOffset = 1;
            }

            $isUpcoming = $reservationAt->gte($now->copy()->startOfDay());

            $status = $isUpcoming
                ? (['Confirmed', 'Confirmed', 'Pending'][$i % 3])
                : (['Checked_In', 'Cancelled', 'No_Show'][$i % 3]);

            $customer = $customers[$i % $customers->count()];
            $table = $tables->isNotEmpty() ? $tables[$i % $tables->count()] : null;
            $assignTable = $table
                && in_array($status, ['Confirmed', 'Checked_In'], true)
                && $i % 2 === 0;

            $slotDifference = ReservationSetting::query()
                ->where('branch_id', $branch->id)
                ->where('slot_type', $slot['type'])
                ->value('time_slot_difference');

            Reservation::create([
                'branch_id' => $branch->id,
                'customer_id' => $customer->id,
                'table_id' => $assignTable ? $table->id : null,
                'area_id' => $assignTable ? $table->area_id : null,
                'reservation_date_time' => $reservationAt->format('Y-m-d H:i:s'),
                'party_size' => min(max(rand(2, 6), 1), $table?->seating_capacity ?: 8),
                'special_requests' => $i % 3 === 0 ? fake()->optional(0.6)->sentence() : null,
                'reservation_status' => $status,
                'reservation_slot_type' => $slot['type'],
                'slot_time_difference' => $slotDifference ?? $slot['difference'],
            ]);
        }
    }
}
