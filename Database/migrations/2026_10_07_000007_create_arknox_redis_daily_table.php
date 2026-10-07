<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arknox_redis_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->bigInteger('commands')->default(0)->unsigned();
            $table->bigInteger('peak_bytes')->default(0)->unsigned();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arknox_redis_daily');
    }
};
