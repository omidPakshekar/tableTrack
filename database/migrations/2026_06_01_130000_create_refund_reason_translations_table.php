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
        Schema::create('refund_reason_translations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('refund_reason_id');
            $table->unique(['refund_reason_id', 'locale'], 'refund_reason_locale_unique');
            $table->foreign('refund_reason_id')->references('id')->on('refund_reasons')->onDelete('cascade');
            $table->string('locale')->index();
            $table->text('reason');
        });

        if (! Schema::hasTable('refund_reasons')) {
            return;
        }

        $locale = DB::table('global_settings')->value('locale') ?? 'en';

        DB::table('refund_reasons')
            ->select('id', 'reason')
            ->orderBy('id')
            ->chunkById(100, function ($reasons) use ($locale) {
                $rows = [];

                foreach ($reasons as $reason) {
                    if (empty($reason->reason)) {
                        continue;
                    }

                    $rows[] = [
                        'refund_reason_id' => $reason->id,
                        'locale' => $locale,
                        'reason' => $reason->reason,
                    ];
                }

                if ($rows !== []) {
                    DB::table('refund_reason_translations')->insert($rows);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('refund_reason_translations');
    }
};
