<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('tlync_payments')) {
            return;
        }

        Schema::table('tlync_payments', function (Blueprint $table) {
            if (! Schema::hasColumn('tlync_payments', 'phone')) {
                $table->string('phone')->nullable()->after('amount');
            }

            if (! Schema::hasColumn('tlync_payments', 'custom_ref')) {
                $table->string('custom_ref')->nullable()->after('phone');
            }
        });

        if (
            Schema::hasColumn('tlync_payments', 'tlync_transaction_ref')
            && ! Schema::hasColumn('tlync_payments', 'tlync_payment_id')
        ) {
            Schema::table('tlync_payments', function (Blueprint $table) {
                $table->renameColumn('tlync_transaction_ref', 'tlync_payment_id');
            });
        } elseif (! Schema::hasColumn('tlync_payments', 'tlync_payment_id')) {
            Schema::table('tlync_payments', function (Blueprint $table) {
                $table->string('tlync_payment_id')->nullable()->after('id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('tlync_payments')) {
            return;
        }

        Schema::table('tlync_payments', function (Blueprint $table) {
            if (Schema::hasColumn('tlync_payments', 'phone')) {
                $table->dropColumn('phone');
            }

            if (Schema::hasColumn('tlync_payments', 'custom_ref')) {
                $table->dropColumn('custom_ref');
            }
        });

        if (
            Schema::hasColumn('tlync_payments', 'tlync_payment_id')
            && ! Schema::hasColumn('tlync_payments', 'tlync_transaction_ref')
        ) {
            Schema::table('tlync_payments', function (Blueprint $table) {
                $table->renameColumn('tlync_payment_id', 'tlync_transaction_ref');
            });
        }
    }
};
