<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Handles installs that ran the previous version of 2026_05_12_* which added columns on `restaurants`.
     */
    public function up(): void
    {
        if (!Schema::hasTable('restaurant_eu_allergen_settings')) {
            Schema::create('restaurant_eu_allergen_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
                $table->boolean('enabled')->default(false);
                $table->json('allergen_keys')->nullable();
                $table->timestamps();

                $table->unique('restaurant_id');
            });
        }

        if (Schema::hasColumn('restaurants', 'eu_fic_allergens_enabled')) {
            $rows = DB::table('restaurants')
                ->select('id', 'eu_fic_allergens_enabled', 'eu_fic_allergen_keys')
                ->get();

            foreach ($rows as $row) {
                $keys = $row->eu_fic_allergen_keys;
                if (is_string($keys)) {
                    $decoded = json_decode($keys, true);
                    $keys = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
                }

                DB::table('restaurant_eu_allergen_settings')->updateOrInsert(
                    ['restaurant_id' => $row->id],
                    [
                        'enabled' => (bool) $row->eu_fic_allergens_enabled,
                        'allergen_keys' => $keys !== null ? json_encode($keys) : null,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]
                );
            }

            Schema::table('restaurants', function (Blueprint $table) {
                $toDrop = [];
                if (Schema::hasColumn('restaurants', 'eu_fic_allergen_keys')) {
                    $toDrop[] = 'eu_fic_allergen_keys';
                }
                if (Schema::hasColumn('restaurants', 'eu_fic_allergens_enabled')) {
                    $toDrop[] = 'eu_fic_allergens_enabled';
                }
                if ($toDrop !== []) {
                    $table->dropColumn($toDrop);
                }
            });
        }

        $existingIds = DB::table('restaurant_eu_allergen_settings')->pluck('restaurant_id')->all();
        $restaurantIds = DB::table('restaurants')->pluck('id');
        foreach ($restaurantIds as $restaurantId) {
            if (!in_array((int) $restaurantId, array_map('intval', $existingIds), true)) {
                DB::table('restaurant_eu_allergen_settings')->insert([
                    'restaurant_id' => $restaurantId,
                    'enabled' => false,
                    'allergen_keys' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (!Schema::hasTable('restaurant_eu_allergen_settings')) {
            return;
        }

        if (!Schema::hasColumn('restaurants', 'eu_fic_allergens_enabled')) {
            Schema::table('restaurants', function (Blueprint $table) {
                $table->boolean('eu_fic_allergens_enabled')->default(false);
                $table->json('eu_fic_allergen_keys')->nullable();
            });
        }

        $settings = DB::table('restaurant_eu_allergen_settings')->get();

        foreach ($settings as $s) {
            $keys = $s->allergen_keys;
            if (is_string($keys)) {
                $decoded = json_decode($keys, true);
                $keys = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
            }

            DB::table('restaurants')->where('id', $s->restaurant_id)->update([
                'eu_fic_allergens_enabled' => (bool) $s->enabled,
                'eu_fic_allergen_keys' => $keys !== null ? json_encode($keys) : null,
            ]);
        }
    }
};
