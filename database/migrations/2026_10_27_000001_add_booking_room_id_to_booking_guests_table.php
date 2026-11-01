<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menautkan penghuni ke kamar spesifik pada reservasi multi-kamar.
 *
 * Additive: kolom nullable, jadi reservasi lama tetap valid dan data lama tidak diubah.
 * BookingRoom dihapus hanya lewat cascade reservasi, sehingga nullOnDelete cukup untuk
 * kasus kamar dilepas dari reservasi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('booking_guests', function (Blueprint $table) {
            $table->unsignedBigInteger('booking_room_id')->nullable()->after('booking_id');
            $table->foreign('booking_room_id')
                ->references('id')
                ->on('booking_rooms')
                ->nullOnDelete();
            $table->index(['booking_id', 'booking_room_id']);
        });
    }

    public function down(): void
    {
        Schema::table('booking_guests', function (Blueprint $table) {
            $table->dropIndex(['booking_id', 'booking_room_id']);
            $table->dropForeign(['booking_room_id']);
            $table->dropColumn('booking_room_id');
        });
    }
};
