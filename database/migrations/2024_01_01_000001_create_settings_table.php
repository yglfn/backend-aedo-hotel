<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('hotel_name');
            $table->string('hotel_address')->nullable();
            $table->string('hotel_phone')->nullable();
            $table->string('hotel_email')->nullable();
            $table->time('default_check_in')->default('14:00:00');
            $table->time('default_check_out')->default('12:00:00');
            $table->decimal('tax_percentage', 5, 2)->default(0);
            $table->string('payment_gateway_provider')->nullable();
            $table->text('payment_gateway_config')->nullable(); // encrypted JSON
            $table->integer('payment_expiry_minutes')->default(60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
