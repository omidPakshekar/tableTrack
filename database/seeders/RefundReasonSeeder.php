<?php

namespace Database\Seeders;

use App\Models\RefundReason;
use Illuminate\Database\Seeder;

class RefundReasonSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run($branch): void
    {
        $refundReasons = [
            'The item was prepared but returned by the customer.',
            'The item was delivered but rejected.',
            'A mistake in the order.',
            'Product quality issue.',
        ];

        $locale = function_exists('global_setting') && global_setting()
            ? global_setting()->locale
            : 'en';

        foreach ($refundReasons as $reason) {
            $refundReason = RefundReason::create([
                'reason' => $reason,
                'branch_id' => $branch->id,
            ]);

            $refundReason->translations()->create([
                'locale' => $locale,
                'reason' => $refundReason->getRawOriginal('reason'),
            ]);
        }
    }
}
