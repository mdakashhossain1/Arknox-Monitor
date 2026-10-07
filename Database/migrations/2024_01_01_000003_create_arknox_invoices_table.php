<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arknox_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->unsignedBigInteger('query_count')->default(0);
            $table->decimal('base_rent',      10, 4);
            $table->decimal('overage_amount', 10, 4)->default(0);
            $table->decimal('total_amount',   10, 4);
            $table->enum('status', ['pending', 'paid', 'unpaid'])->default('pending');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arknox_invoices');
    }
};
