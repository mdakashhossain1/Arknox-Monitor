<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('arknox_invoices', function (Blueprint $table) {
            $table->decimal('r2_storage_cost',  10, 4)->default(0)->after('overage_amount');
            $table->decimal('r2_class_a_cost',  10, 4)->default(0)->after('r2_storage_cost');
            $table->decimal('r2_class_b_cost',  10, 4)->default(0)->after('r2_class_a_cost');
            $table->decimal('r2_overage_amount', 10, 4)->default(0)->after('r2_class_b_cost');
        });
    }

    public function down(): void
    {
        Schema::table('arknox_invoices', function (Blueprint $table) {
            $table->dropColumn(['r2_storage_cost', 'r2_class_a_cost', 'r2_class_b_cost', 'r2_overage_amount']);
        });
    }
};
