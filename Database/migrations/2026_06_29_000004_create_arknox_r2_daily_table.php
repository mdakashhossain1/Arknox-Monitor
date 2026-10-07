<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arknox_r2_daily', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->bigInteger('class_a_ops')->default(0)->unsigned();   // PUT, DELETE, LIST
            $table->bigInteger('class_b_ops')->default(0)->unsigned();   // GET, HEAD
            $table->bigInteger('bytes_uploaded')->default(0)->unsigned();
            $table->bigInteger('bytes_downloaded')->default(0)->unsigned();
            $table->bigInteger('files_added')->default(0)->unsigned();
            $table->bigInteger('files_deleted')->default(0)->unsigned();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('arknox_r2_daily');
    }
};
