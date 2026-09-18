<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('restaurant_impersonation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('superadmin_user_id');
            $table->string('superadmin_name')->nullable();
            $table->string('superadmin_email')->nullable();
            $table->unsignedBigInteger('impersonated_user_id');
            $table->string('impersonated_user_name')->nullable();
            $table->string('impersonated_user_email')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('end_ip_address', 45)->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'started_at']);
            $table->index('superadmin_user_id');
            $table->index('ended_at');
        });

        Schema::create('restaurant_impersonation_log_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('restaurant_impersonation_log_id');
            $table->string('method', 10);
            $table->string('path');
            $table->string('route_name')->nullable();
            $table->string('description')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('restaurant_impersonation_log_id', 'imp_log_actions_log_fk')
                ->references('id')
                ->on('restaurant_impersonation_logs')
                ->cascadeOnDelete();

            $table->index(['restaurant_impersonation_log_id', 'created_at'], 'imp_log_actions_log_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('restaurant_impersonation_log_actions');
        Schema::dropIfExists('restaurant_impersonation_logs');
    }
};
