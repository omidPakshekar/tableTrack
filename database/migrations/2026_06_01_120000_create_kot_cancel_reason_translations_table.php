<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('kot_cancel_reason_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('kot_cancel_reason_id');
            $table->unique(['kot_cancel_reason_id', 'locale'], 'kot_cancel_reason_locale_unique');
            $table->foreign('kot_cancel_reason_id')->references('id')->on('kot_cancel_reasons')->onDelete('cascade');
            $table->string('locale')->index();
            $table->string('reason');
        });

        if (! Schema::hasTable('kot_cancel_reasons')) {
            return;
        }

        $locale = DB::table('global_settings')->value('locale') ?? 'en';

        DB::table('kot_cancel_reasons')
            ->select('id', 'reason')
            ->orderBy('id')
            ->chunkById(100, function ($reasons) use ($locale) {
                $rows = [];

                foreach ($reasons as $reason) {
                    if (empty($reason->reason)) {
                        continue;
                    }

                    $rows[] = [
                        'kot_cancel_reason_id' => $reason->id,
                        'locale' => $locale,
                        'reason' => $reason->reason,
                    ];
                }

                if ($rows !== []) {
                    DB::table('kot_cancel_reason_translations')->insert($rows);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kot_cancel_reason_translations');
    }
};
