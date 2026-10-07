<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arknox_invoices', function (Blueprint $table) {
            $table->decimal('redis_command_cost', 10, 4)->default(0)->after('r2_overage_amount');
            $table->decimal('redis_storage_cost', 10, 4)->default(0)->after('redis_command_cost');
            $table->decimal('redis_overage_amount', 10, 4)->default(0)->after('redis_storage_cost');
        });
    }

    public function down(): void
    {
        Schema::table('arknox_invoices', function (Blueprint $table) {
            $table->dropColumn(['redis_command_cost', 'redis_storage_cost', 'redis_overage_amount']);
        });
    }
};
